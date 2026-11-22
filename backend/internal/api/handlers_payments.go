package api

// Post-order payment endpoints (PaymentController).
//
//	POST  /orders/{order}/payment/proof        bank-transfer proof (konsumen / branch staff / payOnBehalf)
//	POST  /orders/{order}/payment/cod-proof    COD proof
//	POST  /orders/{order}/payment/installment  installment / payoff / cash receipt
//	PATCH /orders/{order}/payment/cod          mark COD paid/unpaid         (keuangan, super_admin)
//	POST  /orders/{order}/payment/verify       approve/reject a proof       (keuangan, super_admin)
//	POST  /orders/{order}/payment/settle       DP settlement request        (keuangan, super_admin)
//	PATCH /admin/cod-payment-proofs/{id}/confirm                            (keuangan, super_admin)
//	GET   /payment-proofs/{id}                 authorised proof download
//	PUT   /orders/{order}/due-date             per-order due date           (admin, keuangan, super_admin)
//
// The ORDER of effects is the reference's: route role gate → route-model binding (Order carries the branch scope,
// so a cross-branch order is a 404) → FormRequest validation (422) → controller authorisation (403) → service.

import (
	"context"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

type boundOrder struct {
	id         int64
	agentID    int64
	konsumenID *int64
}

// bindOrder is route-model binding for {order}: BelongsToAgentScope hides another branch's orders (404).
func (s *Server) bindOrder(w http.ResponseWriter, r *http.Request, actor *auth.User) (*boundOrder, bool) {
	id, ok := pathID(w, r, "order")
	if !ok {
		return nil, false
	}
	ctx := r.Context()
	var agentID int64
	var konsumen *int64
	row := s.database.QueryRowContext(ctx, `SELECT agent_id, konsumen_id FROM orders WHERE id = ?`, id)
	var k *int64
	if err := row.Scan(&agentID, &k); err != nil {
		writeError(w, r, notFoundError(ctx))
		return nil, false
	}
	konsumen = k
	if !actor.IsRole(auth.RoleSuperAdmin) && (actor.AgentID == nil || *actor.AgentID != agentID) {
		writeError(w, r, notFoundError(ctx))
		return nil, false
	}
	return &boundOrder{id: id, agentID: agentID, konsumenID: konsumen}, true
}

func (b *boundOrder) ownedBy(actorID int64) bool {
	return b.konsumenID != nil && *b.konsumenID == actorID
}

// authorizeProofSubmission is PaymentController::authorizeProofSubmission.
func (s *Server) authorizeProofSubmission(r *http.Request, actor *auth.User, order *boundOrder) error {
	ctx := r.Context()
	svc := s.orderService()
	scopes := roleScopesOf(actor)
	if actor.HasAnyRole("korsal", "sales", "sales-kurir-sub") && !order.ownedBy(actor.ID) {
		allowed, err := svc.PayOnBehalf(ctx, scopes, order.id)
		if err != nil {
			return err
		}
		if !allowed {
			return &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "payment.on_behalf_forbidden")}
		}
		return nil
	}
	// $this->authorize('view', $order) then assertOwnedByActor.
	viewable, err := svc.CanView(ctx, scopes, order.id)
	if err != nil {
		return err
	}
	if !viewable {
		return forbiddenError(ctx)
	}
	owned := order.ownedBy(actor.ID) ||
		(actor.HasAnyRole("agen", "admin", "keuangan") && actor.AgentID != nil && *actor.AgentID == order.agentID) ||
		actor.IsRole(auth.RoleSuperAdmin)
	if !owned {
		return forbiddenError(ctx)
	}
	return nil
}

// assertMayVerifyPayment is PaymentController::assertMayVerifyPayment.
func assertMayVerifyPayment(r *http.Request, actor *auth.User, order *boundOrder) error {
	if actor.IsRole(auth.RoleSuperAdmin) {
		return nil
	}
	if actor.IsRole("keuangan") && actor.AgentID != nil && *actor.AgentID == order.agentID {
		return nil
	}
	return forbiddenError(r.Context())
}

// resolvePaidBy is PaymentController::resolvePaidBy.
func resolvePaidBy(r *http.Request, actor *auth.User, form *paymentForm) (string, error) {
	paid := "konsumen"
	if form.filled("paid_by") {
		text, ok := stringValue(form.fields["paid_by"])
		if !ok {
			return "", paidByInvalid(r)
		}
		paid = text
	}
	allowed := []string{"konsumen"}
	switch {
	case actor.HasAnyRole("sales", "sales-kurir-sub"):
		allowed = append(allowed, "sales")
	case actor.IsRole("korsal"):
		allowed = append(allowed, "korsal")
	}
	for _, candidate := range allowed {
		if candidate == paid {
			return paid, nil
		}
	}
	return "", paidByInvalid(r)
}

func paidByInvalid(r *http.Request) error {
	ctx := r.Context()
	return &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"),
		FieldStrings: map[string]string{"paid_by": msg(ctx, "system.field_invalid")}}
}

// handlePaymentProof is POST /orders/{order}/payment/proof.
func (s *Server) handlePaymentProof(w http.ResponseWriter, r *http.Request) {
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
	validateImageFile(ctx, errs, "proof", form.files["proof"], true)
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}
	if err := s.authorizeProofSubmission(r, actor, order); err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	paidBy, err := resolvePaidBy(r, actor, form)
	if err != nil {
		writeError(w, r, err)
		return
	}

	service := s.paymentService(r)
	transaction, err := service.SubmitBankTransferProof(ctx, orders.SubmitBankTransferProofInput{
		OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role, PaidBy: paidBy, Proof: *form.files["proof"],
	})
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	fresh, err := service.LoadTransactionResource(ctx, transaction.ID, false, true)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"transaction": orders.RenderTransaction(fresh)}, msg(ctx, "payment.proof_submitted"), nil)
}

// handlePaymentCodProof is POST /orders/{order}/payment/cod-proof.
func (s *Server) handlePaymentCodProof(w http.ResponseWriter, r *http.Request) {
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
	validateImageFile(ctx, errs, "proof", form.files["proof"], true)
	var amount *float64
	if form.filled("amount") {
		if value, valid := validateMoney(ctx, errs, "amount", form.fields["amount"]); valid {
			amount = &value
		}
	}
	if err := errs.fail(ctx); err != nil {
		writeError(w, r, err)
		return
	}
	if err := s.authorizeProofSubmission(r, actor, order); err != nil {
		writeError(w, r, orderError(ctx, err))
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
	paidBy, err := resolvePaidBy(r, actor, form)
	if err != nil {
		writeError(w, r, err)
		return
	}
	transaction, err := service.SubmitCodPaymentProof(ctx, orders.SubmitCodProofInput{
		OrderID: order.id, ActorID: actor.ID, ActorRole: actor.Role, PaidBy: paidBy, Amount: amount,
		Proof: *form.files["proof"],
	})
	if err != nil {
		writeError(w, r, paymentError(ctx, err))
		return
	}
	fresh, err := service.LoadTransactionResource(ctx, transaction.ID, true, false)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"transaction": orders.RenderTransaction(fresh)}, msg(ctx, "payment.cod_proof_submitted"), nil)
}

// paymentService builds the order service with the request's audit context.
func (s *Server) paymentService(r *http.Request) *orders.Service {
	service := s.orderService()
	ctx := r.Context()
	service.Messages = func(key string, params ...string) string { return message(ctx, key, params...) }
	service.IP = clientIP(r)
	service.UserAgent = r.UserAgent()
	return service
}

// paymentError maps the order domain problems onto the envelope.
func paymentError(ctx context.Context, err error) error { return orderError(ctx, err) }
