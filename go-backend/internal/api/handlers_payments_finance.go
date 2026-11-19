package api

import (
	"database/sql"
	"errors"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// handlePaymentInstallment is POST /orders/{order}/payment/installment.
func (s *Server) handlePaymentInstallment(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	order, ok := s.bindOrder(w, r, actor)
	if !ok {
		return
	}
	form, err := readPaymentForm(r)
	if err != nil {
		writeError(w, r, err)
		return
	}

	errs := fieldErrors{}
	var amount float64
	if !form.has("amount") {
		errs.add("amount", vmsg(ctx, "required", "amount"))
	} else {
		amount, _ = validateMoney(ctx, errs, "amount", form.fields["amount"])
	}
	channel := "transfer"
	if form.has("channel") {
		text, _ := stringValue(form.fields["channel"])
		if text != "transfer" && text != "cash" {
			errs.add("channel", vmsg(ctx, "in", "channel"))
		} else {
			channel = text
		}
	}
	var receivedBy *int64
	if form.filled("received_by_user_id") {
		value, valid := intValue(form.fields["received_by_user_id"])
		if !valid {
			errs.add("received_by_user_id", vmsg(ctx, "integer", "received_by_user_id"))
		} else {
			v := int64(value)
			receivedBy = &v
		}
	}
	var receivedAt *string
	if form.filled("received_at") {
		text, _ := stringValue(form.fields["received_at"])
		parsed, parseErr := parseDateTime(text)
		switch {
		case parseErr != nil:
			errs.add("received_at", vmsg(ctx, "date", "received_at"))
		case parsed.After(time.Now()):
			errs.add("received_at", vmsg(ctx, "before_or_equal", "received_at", "date", "now"))
		default:
			receivedAt = &text
		}
	}
	reference, _ := validateOptionalString(ctx, errs, form, "receipt_reference", 120)
	validateImageFile(ctx, errs, "receipt_photo", form.files["receipt_photo"], false)
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}

	if err := s.authorizeProofSubmission(r, actor, order); err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	if channel == "cash" && order.ownedBy(actor.ID) {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	if channel == "cash" {
		if reference == "" && form.files["receipt_photo"] == nil {
			writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"),
				FieldStrings: map[string]string{"receipt_reference": msg(ctx, "system.field_required")}})
			return
		}
		if receivedBy != nil && *receivedBy != 0 && *receivedBy != actor.ID {
			valid, err := s.validCashReceiver(r, *receivedBy, order.agentID)
			if err != nil {
				writeError(w, r, err)
				return
			}
			if !valid {
				writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"),
					FieldStrings: map[string]string{"received_by_user_id": msg(ctx, "system.field_invalid")}})
				return
			}
		}
	}

	var referencePtr *string
	if reference != "" {
		referencePtr = &reference
	}
	input := orders.InstallmentInput{
		OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role, Amount: amount, Channel: channel,
		ReceivedByUserID: receivedBy, ReceivedAt: receivedAt, ReceiptReference: referencePtr,
		ReceiptPhoto: form.files["receipt_photo"],
	}
	if channel != "cash" {
		input.ReceivedByUserID, input.ReceivedAt, input.ReceiptReference, input.ReceiptPhoto = nil, nil, nil, nil
	}
	service := s.paymentService(r)
	transaction, replay, err := service.RequestInstallment(ctx, input)
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	fresh, err := service.LoadTransactionResource(ctx, transaction.ID, false, true)
	if err != nil {
		writeError(w, r, err)
		return
	}
	message := msg(ctx, "payment.settlement_requested")
	if replay {
		message = msg(ctx, "payment.settlement_replayed")
	}
	httpx.OK(w, map[string]any{"transaction": orders.RenderTransaction(fresh), "replay": replay}, message, nil)
}

func (s *Server) validCashReceiver(r *http.Request, receiverID, agentID int64) (bool, error) {
	var role string
	var receiverAgent sql.NullInt64
	err := s.database.QueryRowContext(r.Context(), `SELECT r.slug, u.agent_id FROM users u
		JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, receiverID).Scan(&role, &receiverAgent)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if !receiverAgent.Valid || receiverAgent.Int64 != agentID {
		return false, nil
	}
	switch auth.CanonicalSlug(role) {
	case "korsal", "sales", "sales-kurir-sub", "agen", "admin", "keuangan":
		return true, nil
	}
	return false, nil
}

func parseDateTime(value string) (time.Time, error) {
	value = strings.TrimSpace(value)
	for _, layout := range []string{time.RFC3339, "2006-01-02 15:04:05", "2006-01-02T15:04:05", "2006-01-02"} {
		if parsed, err := time.ParseInLocation(layout, value, time.UTC); err == nil {
			return parsed, nil
		}
	}
	return time.Time{}, errors.New("unparseable date")
}

// handlePaymentVerify is POST /orders/{order}/payment/verify.
func (s *Server) handlePaymentVerify(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	order, ok := s.bindOrder(w, r, actor)
	if !ok {
		return
	}
	form, err := readPaymentForm(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	errs := fieldErrors{}
	approved, approvedValid := validateBoolRequired(ctx, errs, form, "approved")
	rejection, _ := validateRequiredIfFalse(ctx, errs, form, "rejection_reason", "approved", approved, approvedValid)
	var verified *float64
	if form.filled("verified_amount") {
		if value, valid := validateMoney(ctx, errs, "verified_amount", form.fields["verified_amount"]); valid {
			verified = &value
		}
	}
	adjustment, _ := validateOptionalString(ctx, errs, form, "amount_adjustment_reason", 255)
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}
	if err := assertMayVerifyPayment(r, actor, order); err != nil {
		writeError(w, r, err)
		return
	}

	service := s.paymentService(r)
	_, verification, err := service.LatestManualVerification(ctx, order.id)
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	if verification == nil || verification.Status != "pending" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "payment.already_verified")})
		return
	}
	var rejectionPtr, adjustmentPtr *string
	if rejection != "" {
		rejectionPtr = &rejection
	}
	if adjustment != "" {
		adjustmentPtr = &adjustment
	}
	transaction, _, err := service.VerifyBankTransfer(ctx, orders.VerifyBankTransferInput{
		OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role, Approved: approved,
		RejectionReason: rejectionPtr, VerifiedAmount: verified, AmountAdjustmentReason: adjustmentPtr,
	})
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	message := msg(ctx, "payment.rejected")
	if approved {
		message = msg(ctx, "payment.verified")
	}
	httpx.OK(w, map[string]any{"transaction": orders.RenderTransaction(transaction)}, message, nil)
}

// handlePaymentMarkCod is PATCH /orders/{order}/payment/cod.
func (s *Server) handlePaymentMarkCod(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	order, ok := s.bindOrder(w, r, actor)
	if !ok {
		return
	}
	form, err := readPaymentForm(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	errs := fieldErrors{}
	paid, _ := validateBoolRequired(ctx, errs, form, "paid")
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}
	if !actor.IsRole(auth.RoleSuperAdmin) && !(actor.IsRole("keuangan") && actor.AgentID != nil && *actor.AgentID == order.agentID) {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	service := s.paymentService(r)
	isCod, err := service.OrderIsCod(ctx, order.id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !isCod {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "payment.order_not_cod")})
		return
	}
	if _, err := service.MarkCod(ctx, orders.MarkCodInput{OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role, Paid: paid}); err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	loaded, err := service.LoadOrderForPaymentResponse(ctx, order.id, orders.ModeVerification)
	if err != nil {
		writeError(w, r, err)
		return
	}
	loaded.LoadPaymentTransactions = false // markCod's controller load set has no paymentTransactions relation
	rendered, err := orders.Render(ctx, service, loaded, viewerOf(actor))
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, rendered, msg(ctx, "payment.cod_status_updated"), nil)
}

// handlePaymentSettle is POST /orders/{order}/payment/settle.
func (s *Server) handlePaymentSettle(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	order, ok := s.bindOrder(w, r, actor)
	if !ok {
		return
	}
	if err := assertMayVerifyPayment(r, actor, order); err != nil {
		writeError(w, r, err)
		return
	}
	service := s.paymentService(r)
	code, err := service.OrderPaymentCode(ctx, order.id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if code != "down_payment" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "payment.not_dp_order")})
		return
	}
	transaction, replay, err := service.RequestSettlement(ctx, orders.SettleInput{OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role})
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	fresh, err := service.LoadTransactionResource(ctx, transaction.ID, false, false)
	if err != nil {
		writeError(w, r, err)
		return
	}
	loaded, err := service.LoadOrderForPaymentResponse(ctx, order.id, orders.ModeVerification)
	if err != nil {
		writeError(w, r, err)
		return
	}
	rendered, err := orders.Render(ctx, service, loaded, viewerOf(actor))
	if err != nil {
		writeError(w, r, err)
		return
	}
	message := msg(ctx, "payment.settlement_requested")
	if replay {
		message = msg(ctx, "payment.settlement_replayed")
	}
	httpx.OK(w, map[string]any{"transaction": orders.RenderTransaction(fresh), "replay": replay, "order": rendered}, message, nil)
}

// handleCodProofConfirm is PATCH /admin/cod-payment-proofs/{codPaymentProof}/confirm.
func (s *Server) handleCodProofConfirm(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	proofID, ok := pathID(w, r, "codPaymentProof")
	if !ok {
		return
	}
	service := s.paymentService(r)
	orderID, agentID, konsumen, status, err := service.CodProofOrder(ctx, proofID)
	if err != nil {
		writeError(w, r, notFoundError(ctx))
		return
	}
	form, err := readPaymentForm(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	errs := fieldErrors{}
	confirmed, confirmedValid := validateBoolRequired(ctx, errs, form, "confirmed")
	rejection, _ := validateRequiredIfFalse(ctx, errs, form, "rejection_reason", "confirmed", confirmed, confirmedValid)
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}
	bound := &boundOrder{id: orderID, agentID: agentID, konsumenID: konsumen}
	if err := assertMayVerifyPayment(r, actor, bound); err != nil {
		writeError(w, r, err)
		return
	}
	if status != "pending" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "payment.cod_proof_already_processed")})
		return
	}
	var rejectionPtr *string
	if rejection != "" {
		rejectionPtr = &rejection
	}
	if _, err := service.ConfirmCodPayment(ctx, orders.ConfirmCodProofInput{
		ProofID: proofID, ActorID: actor.ID, ActorRole: actor.Role, Confirmed: confirmed, RejectionReason: rejectionPtr,
	}); err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	loaded, err := service.LoadOrderForPaymentResponse(ctx, orderID, orders.ModeCodProof)
	if err != nil {
		writeError(w, r, err)
		return
	}
	rendered, err := orders.Render(ctx, service, loaded, viewerOf(actor))
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, rendered, msg(ctx, "payment.cod_proof_confirmed"), nil)
}

// handleOrderDueDate is PUT /orders/{order}/due-date.
func (s *Server) handleOrderDueDate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	order, ok := s.bindOrder(w, r, actor)
	if !ok {
		return
	}
	if !actor.IsRole(auth.RoleSuperAdmin) && (actor.AgentID == nil || *actor.AgentID != order.agentID) {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	form, err := readPaymentForm(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	errs := fieldErrors{}
	due := ""
	if !form.filled("due_date") {
		errs.add("due_date", vmsg(ctx, "required", "due_date"))
	} else {
		text, _ := stringValue(form.fields["due_date"])
		if _, perr := parseDateTime(text); perr != nil {
			errs.add("due_date", vmsg(ctx, "date", "due_date"))
		} else {
			due = text
		}
	}
	reason := ""
	if !form.filled("reason") {
		errs.add("reason", vmsg(ctx, "required", "reason"))
	} else if text, valid := validateOptionalString(ctx, errs, form, "reason", 255); valid {
		reason = text
	}
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}
	service := s.paymentService(r)
	newDue, err := service.SetDueDate(ctx, orders.SetDueDateInput{OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role, DueDate: due, Reason: reason})
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	httpx.OK(w, map[string]any{"order_id": order.id, "due_date": newDue}, "OK", nil)
}

// handlePaymentProofFile is GET /payment-proofs/{verification}.
func (s *Server) handlePaymentProofFile(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "verification")
	if !ok {
		return
	}
	service := s.paymentService(r)
	file, err := service.LoadProofFile(ctx, id)
	if err != nil {
		writeError(w, r, notFoundError(ctx))
		return
	}
	allowed := actor.IsRole(auth.RoleSuperAdmin) ||
		(file.Konsumen != nil && *file.Konsumen == actor.ID) ||
		(actor.HasAnyRole("agen", "admin", "keuangan") && actor.AgentID != nil && *actor.AgentID == file.AgentID)
	if !allowed && actor.HasAnyRole("korsal", "sales", "sales-kurir-sub") {
		onBehalf, err := service.PayOnBehalf(ctx, roleScopesOf(actor), file.OrderID)
		if err != nil {
			writeError(w, r, err)
			return
		}
		allowed = onBehalf
	}
	if !allowed || file.Path == "" {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	content, err := os.ReadFile(file.ProofAbsolutePath())
	if err != nil {
		// The reference answers a missing file with the unauthorized message at status 404.
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	w.Header().Set("Content-Type", http.DetectContentType(content))
	w.Header().Set("Content-Length", strconv.Itoa(len(content)))
	w.Header().Set("Cache-Control", "private, no-store")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(content)
}
