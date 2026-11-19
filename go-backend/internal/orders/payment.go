package orders

// Post-order payment surface — proof upload, verification, COD collection/confirmation, DP settlement and
// post-order installments.
//
// Ported from Services/Payment/PaymentService + PaymentSummaryService + the controller's authorization shape.
// One rule dominates this file: PaymentService is the ONLY writer of orders.paid_amount / remaining_amount /
// payment_status. Every path below locks the canonical Order row FIRST (the same row OrderService::cancel and
// OrderFulfillmentService serialize on), then the transaction/verification/proof row it owns — never the other
// way round — so a concurrent cancellation or a second verifier can never apply money twice.
//
// REM-004 admission (expedition = full payment only; Kurir Toko = max 3 successful regular payments, the third
// must settle, never above the regular balance) runs under that Order lock BEFORE any write.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// querier is the shared subset of *sql.Tx and *db.DB.
type querier interface {
	QueryContext(ctx context.Context, query string, args ...any) (*sql.Rows, error)
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
	ExecContext(ctx context.Context, query string, args ...any) (sql.Result, error)
}

// BankTransferVerification is one bank_transfer_verifications row.
type BankTransferVerification struct {
	ID                     int64
	PaymentTransactionID   int64
	Status                 string
	ProofImagePath         string
	ProofDisk              string
	Channel                string
	SubmittedAmount        *float64
	VerifiedAmount         *float64
	AmountAdjustmentReason *string
	CashReceivedByUserID   *int64
	CashReceivedAt         *time.Time
	ReceiptReference       *string
	RejectionReason        *string
	SubmittedOnBehalf      bool
	SubmittedByUserID      *int64
	PaidByRole             *string
	SubmittedAt            *time.Time
	VerifiedBy             *int64
	// Resolved {id,name,role} payer summaries (PaymentTransactionResource::payerSummary).
	CashReceivedBy, SubmittedBy, VerifiedByUser any
	VerifiedAt                                  *time.Time
}

// CodPaymentProof is one cod_payment_proofs row (plus its media path).
type CodPaymentProof struct {
	ID                           int64
	PaymentTransactionID         int64
	ProofMediaID                 int64
	CollectedAmount              *float64
	Status                       string
	ConfirmedBy                  *int64
	SubmittedBy, ConfirmedByUser any
	ConfirmedAt                  *time.Time
	RejectionReason              *string
	SubmittedByUserID            *int64
	SubmittedOnBehalf            bool
	PaidByRole                   *string
	SubmittedAt                  *time.Time
	ProofPath                    string
}

// paidOrder is the locked financial boundary of an order.
type paidOrder struct {
	ID            int64
	Status        string
	PaymentStatus string
	Total         float64
	Paid          float64
	// StoredRemaining is the persisted remaining_amount column (the audit rows report THAT, not a recomputation:
	// it is 0 for an order that never had a money event).
	StoredRemaining float64
	GrossReceived   float64
	KonsumenID      *int64
	AgentID         int64
	PaymentMethodID *int64
	PaymentCode     *string
	PaymentType     *string
}

// ---- read helpers ---------------------------------------------------------------------------------

func (s *Service) lockOrderForPayment(ctx context.Context, tx *sql.Tx, orderID int64) (*paidOrder, error) {
	var (
		o                                   paidOrder
		total, paid, gross, storedRemaining string
		konsumen                            sql.NullInt64
		methodID                            sql.NullInt64
		code, ptype                         sql.NullString
	)
	err := tx.QueryRowContext(ctx, `SELECT o.id, o.status, o.payment_status,
			CAST(o.total_amount AS CHAR), CAST(o.paid_amount AS CHAR), CAST(o.gross_received_amount AS CHAR),
			CAST(o.remaining_amount AS CHAR), o.konsumen_id, o.agent_id, o.payment_method_id, pm.code, pm.type
		FROM orders o LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
		WHERE o.id = ? FOR UPDATE`, orderID).
		Scan(&o.ID, &o.Status, &o.PaymentStatus, &total, &paid, &gross, &storedRemaining, &konsumen, &o.AgentID,
			&methodID, &code, &ptype)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrOrderNotFound
	}
	if err != nil {
		return nil, err
	}
	o.Total = parseString(total)
	o.Paid = parseString(paid)
	o.GrossReceived = parseString(gross)
	o.StoredRemaining = parseString(storedRemaining)
	o.KonsumenID = nullIntPtr(konsumen)
	o.PaymentMethodID = nullIntPtr(methodID)
	o.PaymentCode = nullStringPtr(code)
	o.PaymentType = nullStringPtr(ptype)
	return &o, nil
}

// loadPaymentTransaction reads one transaction (raw_payload decoded into Instructions).
func (s *Service) loadPaymentTransaction(ctx context.Context, q querier, id int64, lock bool) (*PaymentTransaction, error) {
	query := `SELECT id, order_id, payment_method_id, type, CAST(amount AS CHAR), status,
			gateway_reference, raw_payload, paid_at
		FROM payment_transactions WHERE id = ?`
	if lock {
		query += " FOR UPDATE"
	}
	var (
		p          PaymentTransaction
		amount     string
		reference  sql.NullString
		rawPayload sql.NullString
		paidAt     sql.NullTime
	)
	err := q.QueryRowContext(ctx, query, id).
		Scan(&p.ID, &p.OrderID, &p.PaymentMethodID, &p.Type, &amount, &p.Status, &reference, &rawPayload, &paidAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	if err != nil {
		return nil, err
	}
	p.Amount = parseString(amount)
	p.GatewayReference = nullStringPtr(reference)
	p.Instructions = decodeRawPayload(rawPayload)
	if paidAt.Valid {
		t := paidAt.Time
		p.PaidAt = &t
	}
	return &p, nil
}

func decodeRawPayload(raw sql.NullString) map[string]any {
	if !raw.Valid || strings.TrimSpace(raw.String) == "" {
		return nil
	}
	var decoded map[string]any
	if json.Unmarshal([]byte(raw.String), &decoded) != nil {
		return nil
	}
	return decoded
}

func rawString(raw map[string]any, key string) (string, bool) {
	value, present := raw[key]
	if !present || value == nil {
		return "", false
	}
	text, ok := value.(string)
	return text, ok
}

func rawFloat(raw map[string]any, key string) (float64, bool) {
	value, present := raw[key]
	if !present || value == nil {
		return 0, false
	}
	switch v := value.(type) {
	case float64:
		return v, true
	case json.Number:
		f, err := v.Float64()
		return f, err == nil
	case string:
		f, err := strconv.ParseFloat(strings.TrimSpace(v), 64)
		return f, err == nil
	}
	return 0, false
}

func rawInt(raw map[string]any, key string) (int64, bool) {
	value, present := raw[key]
	if !present || value == nil {
		return 0, false
	}
	switch v := value.(type) {
	case float64:
		return int64(v), true
	case json.Number:
		n, err := v.Int64()
		return n, err == nil
	case string:
		n, err := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
		return n, err == nil
	}
	return 0, false
}

// paymentChannelKind is PaymentService::paymentChannelKind over PERSISTED shipment data.
func (s *Service) paymentChannelKind(ctx context.Context, q querier, orderID int64) (string, error) {
	rows, err := q.QueryContext(ctx, `SELECT delivery_mode, IFNULL(shipping_provider_code, '')
		FROM shipments WHERE order_id = ?`, orderID)
	if err != nil {
		return "", err
	}
	defer rows.Close()
	type shipmentRow struct{ mode, provider string }
	var shipments []shipmentRow
	for rows.Next() {
		var row shipmentRow
		if err := rows.Scan(&row.mode, &row.provider); err != nil {
			return "", err
		}
		shipments = append(shipments, row)
	}
	if err := rows.Err(); err != nil {
		return "", err
	}
	for _, row := range shipments {
		if row.provider == "rajaongkir" {
			return "expedition", nil
		}
	}
	if len(shipments) == 0 {
		return "other", nil
	}
	for _, row := range shipments {
		if row.mode != "standard" || row.provider == "pickup" {
			return "other", nil
		}
	}
	return "kurir_toko", nil
}

// successfulPaymentCount counts paid/refunded regular payments (additional obligations are excluded).
func (s *Service) successfulPaymentCount(ctx context.Context, q querier, orderID int64) (int, error) {
	var count int
	err := q.QueryRowContext(ctx, `SELECT COUNT(*) FROM payment_transactions
		WHERE order_id = ? AND status IN ('paid','refunded') AND type <> 'additional_payment'
		FOR UPDATE`, orderID).Scan(&count)
	return count, err
}

func (s *Service) successfulPaymentCountNoLock(ctx context.Context, q querier, orderID int64) (int, error) {
	var count int
	err := q.QueryRowContext(ctx, `SELECT COUNT(*) FROM payment_transactions
		WHERE order_id = ? AND status IN ('paid','refunded') AND type <> 'additional_payment'`, orderID).Scan(&count)
	return count, err
}

// pendingAdditionalAmount is PaymentService::pendingAdditionalAmount.
func (s *Service) pendingAdditionalAmount(ctx context.Context, q querier, orderID int64) (float64, error) {
	var amount string
	err := q.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount), 0) FROM order_additional_payments
		WHERE order_id = ? AND status = 'pending' FOR UPDATE`, orderID).Scan(&amount)
	if err != nil {
		return 0, err
	}
	return money.Round2(parseString(amount)), nil
}

// regularRemaining is the balance collectible through a REGULAR payment.
func (s *Service) regularRemaining(ctx context.Context, q querier, order *paidOrder) (float64, error) {
	pending, err := s.pendingAdditionalAmount(ctx, q, order.ID)
	if err != nil {
		return 0, err
	}
	remaining := money.Round2(order.Total - order.Paid - pending)
	if remaining < 0 {
		remaining = 0
	}
	return remaining, nil
}

// assertPaymentAllowed is PaymentService::assertPaymentAllowed (REM-004).
func (s *Service) assertPaymentAllowed(ctx context.Context, q querier, order *paidOrder, amount float64, additional bool) error {
	kind, err := s.paymentChannelKind(ctx, q, order.ID)
	if err != nil {
		return err
	}
	if kind == "other" {
		return nil
	}
	amount = money.Round2(amount)
	remaining := money.Round2(order.Total - order.Paid)
	if remaining < 0 {
		remaining = 0
	}
	if kind == "expedition" {
		if amount+0.004 < remaining {
			return &Problem{Status: 422, Message: s.Messages("payment.expedition_full_payment_only")}
		}
		return nil
	}
	if additional {
		if amount > remaining+0.004 {
			return &Problem{Status: 422, Message: s.Messages("payment.overpayment_refused")}
		}
		return nil
	}
	pending, err := s.pendingAdditionalAmount(ctx, q, order.ID)
	if err != nil {
		return err
	}
	regularRemaining := money.Round2(remaining - pending)
	if regularRemaining < 0 {
		regularRemaining = 0
	}
	if amount > regularRemaining+0.004 {
		return &Problem{Status: 422, Message: s.Messages("payment.overpayment_refused")}
	}
	successful, err := s.successfulPaymentCount(ctx, q, order.ID)
	if err != nil {
		return err
	}
	if successful >= 3 {
		return &Problem{Status: 422, Message: s.Messages("payment.payment_limit_reached")}
	}
	if successful == 2 && amount+0.004 < regularRemaining {
		return &Problem{Status: 422, Message: s.Messages("payment.third_payment_must_settle")}
	}
	return nil
}

// recalculatePaymentStatus is PaymentService::recalculatePaymentStatus. paid_amount is deliberately never
// clamped to the total (an overpayment is real history); only remaining_amount is floored at zero.
func (s *Service) recalculatePaymentStatus(ctx context.Context, tx *sql.Tx, orderID int64, paidOverride *float64) error {
	var total, current, gross string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR), CAST(paid_amount AS CHAR),
			CAST(gross_received_amount AS CHAR) FROM orders WHERE id = ? FOR UPDATE`, orderID).
		Scan(&total, &current, &gross); err != nil {
		return err
	}
	totalValue := money.Round2(parseString(total))
	currentValue := money.Round2(parseString(current))
	paid := currentValue
	if paidOverride != nil {
		paid = money.Round2(*paidOverride)
	}
	if paid < 0 {
		paid = 0
	}
	remaining := money.Round2(totalValue - paid)
	if remaining < 0 {
		remaining = 0
	}
	grossValue := money.Round2(parseString(gross))
	if paid > currentValue {
		grossValue = money.Round2(grossValue + (paid - currentValue))
	}
	status := "unpaid"
	if remaining <= 0 {
		status = "paid"
	} else if paid > 0 {
		status = "partially_paid"
	}
	_, err := tx.ExecContext(ctx, `UPDATE orders SET paid_amount = ?, gross_received_amount = ?,
		remaining_amount = ?, payment_status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		money.String(paid), money.String(grossValue), money.String(remaining), status, orderID)
	return err
}

func (s *Service) applyPaymentToOrder(ctx context.Context, tx *sql.Tx, order *paidOrder, amount float64) error {
	paid := money.Round2(order.Paid + amount)
	return s.recalculatePaymentStatus(ctx, tx, order.ID, &paid)
}

// ---- proof submission / verification --------------------------------------------------------------

// SubmitBankTransferProofInput is POST /orders/{order}/payment/proof.
type SubmitBankTransferProofInput struct {
	OrderID   int64
	ActorID   int64
	ActorRole string
	PaidBy    string
	Proof     media.Upload
}

// SubmitBankTransferProof is PaymentService::submitBankTransferProof.
func (s *Service) SubmitBankTransferProof(ctx context.Context, in SubmitBankTransferProofInput) (*PaymentTransaction, error) {
	transaction, err := s.latestManualTransaction(ctx, in.OrderID)
	if err != nil {
		return nil, err
	}

	var verificationID int64
	err = s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var existingID int64
		var existingStatus string
		err := tx.QueryRowContext(ctx, `SELECT id, status FROM bank_transfer_verifications
			WHERE payment_transaction_id = ? FOR UPDATE`, transaction.ID).Scan(&existingID, &existingStatus)
		switch {
		case errors.Is(err, sql.ErrNoRows):
			existingID = 0
		case err != nil:
			return err
		}

		// Lock order mirrors the reference exactly: verification row (above) → transaction READ → ORDER lock.
		current, err := s.loadPaymentTransaction(ctx, tx, transaction.ID, false)
		if err != nil {
			return err
		}
		order, err := s.lockOrderForPayment(ctx, tx, current.OrderID)
		if err != nil {
			return err
		}
		if order.Status == "dibatalkan" || current.Status == "paid" || (existingID > 0 && existingStatus == "verified") {
			return &Problem{Status: 422, Message: s.Messages("payment.proof_locked")}
		}
		methodCode, err := paymentMethodCode(ctx, tx, current.PaymentMethodID)
		if err != nil {
			return err
		}

		onBehalf := order.KonsumenID == nil || *order.KonsumenID != in.ActorID
		// Laravel stores via `$proof->store()` → `hashName()` names the file with the extension GUESSED FROM
		// THE MIME TYPE (never the client filename), so the same content always lands with the same extension.
		path, err := storePrivateUpload("payments/bank-transfer-proofs", in.Proof, mimeExtension(in.Proof.Content))
		if err != nil {
			return err
		}

		var submittedBy any
		if in.ActorID > 0 {
			submittedBy = in.ActorID
		}
		var bankName, accountName, accountNumber any = "-", "-", "-"
		if value, ok := rawString(current.Instructions, "bank_name"); ok {
			bankName = value
		}
		if value, ok := rawString(current.Instructions, "account_name"); ok {
			accountName = value
		}
		if value, ok := rawString(current.Instructions, "account_number"); ok {
			accountNumber = value
		}

		// updateOrCreate on the unique payment_transaction_id.
		_, err = tx.ExecContext(ctx, `INSERT INTO bank_transfer_verifications
			(payment_transaction_id, bank_name, account_name, account_number, proof_image_path, proof_disk,
			 submitted_amount, status, verified_by, verified_at, rejection_reason, submitted_by_user_id,
			 submitted_on_behalf, paid_by_role, submitted_at, channel, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, 'local', ?, 'pending', NULL, NULL, NULL, ?, ?, ?, UTC_TIMESTAMP(), 'transfer',
			 UTC_TIMESTAMP(), UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE bank_name = VALUES(bank_name), account_name = VALUES(account_name),
			 account_number = VALUES(account_number), proof_image_path = VALUES(proof_image_path),
			 proof_disk = VALUES(proof_disk), submitted_amount = VALUES(submitted_amount), status = 'pending',
			 verified_by = NULL, verified_at = NULL, rejection_reason = NULL,
			 submitted_by_user_id = VALUES(submitted_by_user_id),
			 submitted_on_behalf = VALUES(submitted_on_behalf), paid_by_role = VALUES(paid_by_role),
			 submitted_at = UTC_TIMESTAMP()`,
			current.ID, bankName, accountName, accountNumber, path, money.String(current.Amount),
			submittedBy, boolInt(onBehalf), in.PaidBy)
		if err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT id FROM bank_transfer_verifications
			WHERE payment_transaction_id = ?`, current.ID).Scan(&verificationID); err != nil {
			return err
		}

		if _, err := tx.ExecContext(ctx, `UPDATE orders SET payment_status = 'pending_verification',
			updated_at = UTC_TIMESTAMP() WHERE id = ?`, order.ID); err != nil {
			return err
		}

		return logActivity(ctx, tx, in.ActorID, order.ID, "payment.proof_submitted", map[string]any{
			"actor_role":               in.ActorRole,
			"on_behalf_of_konsumen_id": nullableOrderID(onBehalf, order.KonsumenID),
			"submitted_on_behalf":      onBehalf,
			"paid_by":                  in.PaidBy,
			"payment_transaction_id":   current.ID,
			"amount":                   current.Amount,
			"payment_method":           methodCode,
			"order_payment_status":     "pending_verification",
		}, s.IP, s.UserAgent)
	})
	if err != nil {
		return nil, err
	}
	return s.loadPaymentTransactionWithVerification(ctx, transaction.ID, verificationID)
}

func (s *Service) loadPaymentTransactionWithVerification(ctx context.Context, transactionID, verificationID int64) (*PaymentTransaction, error) {
	p, err := s.loadPaymentTransaction(ctx, s.Database, transactionID, false)
	if err != nil {
		return nil, err
	}
	p.LoadVerification = true
	v, err := s.loadVerification(ctx, s.Database, verificationID)
	if err != nil {
		return nil, err
	}
	p.Verification = v
	return p, nil
}

func (s *Service) loadVerification(ctx context.Context, q querier, id int64) (*BankTransferVerification, error) {
	var (
		v                         BankTransferVerification
		proofPath                 string
		proofDisk                 string
		channel                   string
		submitted, verifiedAmount sql.NullString
		adjustReason              sql.NullString
		cashBy                    sql.NullInt64
		cashAt                    sql.NullTime
		receipt                   sql.NullString
		rejection                 sql.NullString
		onBehalf                  bool
		submittedBy, verifiedBy   sql.NullInt64
		paidBy                    sql.NullString
		submittedAt, verifiedAt   sql.NullTime
	)
	err := q.QueryRowContext(ctx, `SELECT id, payment_transaction_id, status, proof_image_path, proof_disk,
			channel, CAST(submitted_amount AS CHAR), CAST(verified_amount AS CHAR), amount_adjustment_reason,
			cash_received_by_user_id, cash_received_at, receipt_reference, rejection_reason,
			submitted_on_behalf, submitted_by_user_id, paid_by_role, submitted_at, verified_by, verified_at
		FROM bank_transfer_verifications WHERE id = ?`, id).
		Scan(&v.ID, &v.PaymentTransactionID, &v.Status, &proofPath, &proofDisk, &channel, &submitted,
			&verifiedAmount, &adjustReason, &cashBy, &cashAt, &receipt, &rejection, &onBehalf, &submittedBy,
			&paidBy, &submittedAt, &verifiedBy, &verifiedAt)
	if err != nil {
		return nil, err
	}
	v.ProofImagePath = proofPath
	v.ProofDisk = proofDisk
	v.Channel = channel
	v.SubmittedAmount = nullMoneyPtr(submitted)
	v.VerifiedAmount = nullMoneyPtr(verifiedAmount)
	v.AmountAdjustmentReason = nullStringPtr(adjustReason)
	v.CashReceivedByUserID = nullIntPtr(cashBy)
	if cashAt.Valid {
		t := cashAt.Time
		v.CashReceivedAt = &t
	}
	v.ReceiptReference = nullStringPtr(receipt)
	v.RejectionReason = nullStringPtr(rejection)
	v.SubmittedOnBehalf = onBehalf
	v.SubmittedByUserID = nullIntPtr(submittedBy)
	v.PaidByRole = nullStringPtr(paidBy)
	if submittedAt.Valid {
		t := submittedAt.Time
		v.SubmittedAt = &t
	}
	v.VerifiedBy = nullIntPtr(verifiedBy)
	v.CashReceivedBy = s.payerSummary(ctx, q, v.CashReceivedByUserID)
	v.SubmittedBy = s.payerSummary(ctx, q, v.SubmittedByUserID)
	v.VerifiedByUser = s.payerSummary(ctx, q, v.VerifiedBy)
	if verifiedAt.Valid {
		t := verifiedAt.Time
		v.VerifiedAt = &t
	}
	return &v, nil
}

func (s *Service) loadCodProof(ctx context.Context, q querier, id int64) (*CodPaymentProof, error) {
	var (
		p           CodPaymentProof
		collected   sql.NullString
		confirmedBy sql.NullInt64
		confirmedAt sql.NullTime
		rejection   sql.NullString
		submittedBy sql.NullInt64
		onBehalf    bool
		paidBy      sql.NullString
		submittedAt sql.NullTime
		proofPath   sql.NullString
	)
	err := q.QueryRowContext(ctx, `SELECT cp.id, cp.payment_transaction_id, cp.proof_media_id,
			CAST(cp.collected_amount AS CHAR), cp.status, cp.confirmed_by, cp.confirmed_at,
			cp.rejection_reason, cp.submitted_by_user_id, cp.submitted_on_behalf, cp.paid_by_role,
			cp.submitted_at, m.path
		FROM cod_payment_proofs cp LEFT JOIN media m ON m.id = cp.proof_media_id
		WHERE cp.id = ?`, id).
		Scan(&p.ID, &p.PaymentTransactionID, &p.ProofMediaID, &collected, &p.Status, &confirmedBy,
			&confirmedAt, &rejection, &submittedBy, &onBehalf, &paidBy, &submittedAt, &proofPath)
	if err != nil {
		return nil, err
	}
	p.CollectedAmount = nullMoneyPtr(collected)
	p.ConfirmedBy = nullIntPtr(confirmedBy)
	p.SubmittedBy = s.payerSummary(ctx, q, nullIntPtr(submittedBy))
	p.ConfirmedByUser = s.payerSummary(ctx, q, p.ConfirmedBy)
	if confirmedAt.Valid {
		t := confirmedAt.Time
		p.ConfirmedAt = &t
	}
	p.RejectionReason = nullStringPtr(rejection)
	p.SubmittedByUserID = nullIntPtr(submittedBy)
	p.SubmittedOnBehalf = onBehalf
	p.PaidByRole = nullStringPtr(paidBy)
	if submittedAt.Valid {
		t := submittedAt.Time
		p.SubmittedAt = &t
	}
	p.ProofPath = proofPath.String
	return &p, nil
}

// VerifyBankTransferInput is POST /orders/{order}/payment/verify.
type VerifyBankTransferInput struct {
	OrderID                int64
	ActorID                int64
	ActorRole              string
	Approved               bool
	RejectionReason        *string
	VerifiedAmount         *float64
	AmountAdjustmentReason *string
}

// VerifyBankTransfer is PaymentService::verifyBankTransfer.
func (s *Service) VerifyBankTransfer(ctx context.Context, in VerifyBankTransferInput) (*PaymentTransaction, int64, error) {
	transaction, err := s.latestManualTransaction(ctx, in.OrderID)
	if err != nil {
		return nil, 0, err
	}

	var verificationID int64
	err = s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		// Lock the verification row first (the row the proof upload and a concurrent verify already lock).
		var status string
		err := tx.QueryRowContext(ctx, `SELECT id, status FROM bank_transfer_verifications
			WHERE payment_transaction_id = ? FOR UPDATE`, transaction.ID).Scan(&verificationID, &status)
		if errors.Is(err, sql.ErrNoRows) {
			return &Problem{Status: 422, Message: s.Messages("payment.already_verified")}
		}
		if err != nil {
			return err
		}
		if status != "pending" {
			return &Problem{Status: 422, Message: s.Messages("payment.already_verified")}
		}
		verification, err := s.loadVerification(ctx, tx, verificationID)
		if err != nil {
			return err
		}
		if in.Approved && ((verification.SubmittedByUserID != nil && *verification.SubmittedByUserID == in.ActorID) ||
			(verification.CashReceivedByUserID != nil && *verification.CashReceivedByUserID == in.ActorID)) {
			return &Problem{Status: 403, Message: s.Messages("payment.self_verification_forbidden")}
		}

		current, err := s.loadPaymentTransaction(ctx, tx, transaction.ID, false)
		if err != nil {
			return err
		}
		order, err := s.lockOrderForPayment(ctx, tx, current.OrderID)
		if err != nil {
			return err
		}
		current, err = s.loadPaymentTransaction(ctx, tx, transaction.ID, true)
		if err != nil {
			return err
		}
		if current.Status == "paid" || current.Status == "refunded" {
			return &Problem{Status: 422, Message: s.Messages("payment.already_verified")}
		}

		submitted := money.Round2(current.Amount)
		applied := submitted
		if in.Approved && in.VerifiedAmount != nil {
			applied = money.Round2(*in.VerifiedAmount)
			if applied <= 0 {
				return &Problem{Status: 422, Message: s.Messages("system.validation_failed"),
					Fields: map[string]string{"verified_amount": s.Messages("system.field_invalid")}}
			}
			outstanding := order.Collectible()
			if applied > outstanding+0.004 {
				return &Problem{Status: 422, Message: s.Messages("payment.verified_amount_exceeds_remaining"),
					Fields: map[string]string{"verified_amount": s.Messages("payment.verified_amount_exceeds_remaining")}}
			}
			if applied > submitted+0.004 {
				return &Problem{Status: 422, Message: s.Messages("payment.verified_amount_exceeds_submitted"),
					Fields: map[string]string{"verified_amount": s.Messages("payment.verified_amount_exceeds_submitted")}}
			}
			if applied != submitted && strings.TrimSpace(derefString(in.AmountAdjustmentReason)) == "" {
				return &Problem{Status: 422, Message: s.Messages("payment.adjustment_reason_required"),
					Fields: map[string]string{"amount_adjustment_reason": s.Messages("system.field_required")}}
			}
		}

		if in.Approved {
			if err := s.assertPaymentAllowed(ctx, tx, order, applied, current.Type == "additional_payment"); err != nil {
				return err
			}
		}

		verifiedBy := any(in.ActorID)
		verifiedAmountValue := any(nil)
		adjustReasonValue := any(nil)
		rejectionValue := any(nil)
		statusValue := "rejected"
		if in.Approved {
			statusValue = "verified"
			verifiedAmountValue = money.String(applied)
			if applied != submitted {
				adjustReasonValue = derefString(in.AmountAdjustmentReason)
			}
		} else {
			rejectionValue = derefString(in.RejectionReason)
		}
		if _, err := tx.ExecContext(ctx, `UPDATE bank_transfer_verifications SET status = ?,
				verified_by = ?, verified_at = UTC_TIMESTAMP(), rejection_reason = ?,
				submitted_amount = COALESCE(submitted_amount, ?), verified_amount = ?,
				amount_adjustment_reason = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`,
			statusValue, verifiedBy, rejectionValue, money.String(submitted), verifiedAmountValue,
			adjustReasonValue, verificationID); err != nil {
			return err
		}

		transactionStatus := "failed"
		paidAt := "NULL"
		if in.Approved {
			transactionStatus = "paid"
			paidAt = "UTC_TIMESTAMP()"
		}
		if _, err := tx.ExecContext(ctx, `UPDATE payment_transactions SET status = ?, paid_at = `+paidAt+`,
			updated_at = UTC_TIMESTAMP() WHERE id = ?`, transactionStatus, current.ID); err != nil {
			return err
		}

		if in.Approved && current.Type == "additional_payment" {
			if err := s.settleAdditionalObligation(ctx, tx, order, current, applied, in.AmountAdjustmentReason, in.ActorID); err != nil {
				return err
			}
		}

		if in.Approved {
			if err := s.applyPaymentToOrder(ctx, tx, order, applied); err != nil {
				return err
			}
		} else if order.Paid > 0 {
			if err := s.recalculatePaymentStatus(ctx, tx, order.ID, nil); err != nil {
				return err
			}
		} else {
			if _, err := tx.ExecContext(ctx, `UPDATE orders SET payment_status = 'failed',
				updated_at = UTC_TIMESTAMP() WHERE id = ?`, order.ID); err != nil {
				return err
			}
		}

		// Re-read the post-write figures the audit row reports.
		after, err := s.lockOrderForPayment(ctx, tx, order.ID)
		if err != nil {
			return err
		}
		var adjustment any
		if in.Approved && applied != submitted {
			adjustment = derefString(in.AmountAdjustmentReason)
		}
		var verifiedForLog any
		if in.Approved {
			verifiedForLog = applied
		}
		if err := logActivityDescribed(ctx, tx, in.ActorID, "App\\Models\\Order", order.ID,
			"payment.bank_transfer_verified", rejectionPayload(in.RejectionReason), map[string]any{
				"approved":                 in.Approved,
				"payment_transaction_id":   current.ID,
				"amount":                   submitted,
				"submitted_amount":         submitted,
				"verified_amount":          verifiedForLog,
				"amount_adjustment_reason": adjustment,
				"channel":                  verification.Channel,
				"paid_amount":              after.Paid,
				"remaining_amount":         after.StoredRemaining,
			}, s.IP, s.UserAgent); err != nil {
			return err
		}

		if in.Approved {
			return s.syncInvoiceAfterChange(ctx, tx, order.ID, in.ActorID, "payment_verified")
		}
		return nil
	})
	if err != nil {
		return nil, 0, err
	}
	p, err := s.loadPaymentTransaction(ctx, s.Database, transaction.ID, false)
	if err != nil {
		return nil, 0, err
	}
	p.LoadVerification = true
	v, err := s.loadVerification(ctx, s.Database, verificationID)
	if err != nil {
		return nil, 0, err
	}
	p.Verification = v
	return p, verificationID, nil
}

// Collectible is Support\CollectibleReceivable::forOrder over the locked financial boundary: zero for a
// cancelled order, otherwise GREATEST(total - paid, 0). It deliberately ignores pending additional obligations.
func (o *paidOrder) Collectible() float64 {
	if o.Status == "dibatalkan" {
		return 0
	}
	value := money.Round2(o.Total - o.Paid)
	if value < 0 {
		return 0
	}
	return value
}

// Remaining is the stored remaining_amount recomputed from the locked figures.
func (o *paidOrder) Remaining() float64 {
	remaining := money.Round2(o.Total - o.Paid)
	if remaining < 0 {
		remaining = 0
	}
	return remaining
}

func rejectionPayload(reason *string) *string {
	if reason == nil {
		return nil
	}
	return reason
}

// settleAdditionalObligation is verifyBankTransfer's additional_payment branch (F03 + the partial-settlement
// Owner decision): verified cash reduces the SAME outstanding obligation; the residual stays additional.
func (s *Service) settleAdditionalObligation(ctx context.Context, tx *sql.Tx, order *paidOrder,
	transaction *PaymentTransaction, applied float64, adjustmentReason *string, actorID int64) error {

	var (
		obligationID   int64
		status, method string
		amount         string
		requestedBy    sql.NullInt64
		reason         sql.NullString
	)
	err := tx.QueryRowContext(ctx, `SELECT id, status, method, CAST(amount AS CHAR), requested_by, reason
		FROM order_additional_payments WHERE payment_transaction_id = ? FOR UPDATE`, transaction.ID).
		Scan(&obligationID, &status, &method, &amount, &requestedBy, &reason)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if status != "pending" {
		return &Problem{Status: 422, Message: s.Messages("payment.already_processed")}
	}
	obligationAmount := money.Round2(parseString(amount))
	remainingAdditional := money.Round2(obligationAmount - applied)
	if remainingAdditional < -0.004 {
		return &Problem{Status: 422, Message: s.Messages("payment.overpayment_refused")}
	}
	if remainingAdditional > 0 {
		reference, err := randomAlphaNumeric(10)
		if err != nil {
			return err
		}
		residualPayload := map[string]any{
			"residual_of":           transaction.ID,
			"additional_payment_id": obligationID,
		}
		for key, value := range transaction.Instructions {
			if _, reserved := residualPayload[key]; !reserved {
				residualPayload[key] = value
			}
		}
		payloadJSON, err := json.Marshal(residualPayload)
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
			(order_id, payment_method_id, type, amount, status, gateway_reference, raw_payload,
			 created_at, updated_at)
			VALUES (?, ?, 'additional_payment', ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			order.ID, transaction.PaymentMethodID, money.String(remainingAdditional),
			"AP-"+upperASCII(reference), string(payloadJSON))
		if err != nil {
			return err
		}
		residualID, err := res.LastInsertId()
		if err != nil {
			return err
		}
		res, err = tx.ExecContext(ctx, `INSERT INTO order_additional_payments
			(order_id, requested_by, method, payment_transaction_id, amount, reason, status,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 'paid', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			order.ID, nullableIntValue(requestedBy), method, transaction.ID, money.String(applied),
			nullableStringValue(reason))
		if err != nil {
			return err
		}
		settledPortionID, err := res.LastInsertId()
		if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_additional_payments
			SET amount = ?, payment_transaction_id = ?, status = 'pending', updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, money.String(remainingAdditional), residualID, obligationID); err != nil {
			return err
		}
		return logActivityDescribed(ctx, tx, actorID, "App\\Models\\OrderAdditionalPayment", obligationID,
			"order_additional_payment.partially_settled", adjustmentReason, map[string]any{
				"previous_amount":         obligationAmount,
				"verified_amount":         applied,
				"remaining_amount":        remainingAdditional,
				"payment_transaction_id":  transaction.ID,
				"residual_transaction_id": residualID,
				"settled_portion_id":      settledPortionID,
			}, s.IP, s.UserAgent)
	}
	_, err = tx.ExecContext(ctx, `UPDATE order_additional_payments SET status = 'paid',
		updated_at = UTC_TIMESTAMP() WHERE id = ?`, obligationID)
	return err
}

// ---- COD -------------------------------------------------------------------------------------------

// MarkCodInput is PATCH /orders/{order}/payment/cod.
type MarkCodInput struct {
	OrderID   int64
	ActorID   int64
	ActorRole string
	Paid      bool
}

// MarkCod is PaymentService::markCod. It returns the order id; the handler loads the response shape through
// LoadOrderForPaymentResponse (the reference returns `$order->fresh([...])` from the service and the controller
// then loads the remaining display relations).
func (s *Service) MarkCod(ctx context.Context, in MarkCodInput) (int64, error) {
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		order, err := s.lockOrderForPayment(ctx, tx, in.OrderID)
		if err != nil {
			return err
		}
		previous := order.PaymentStatus

		if in.Paid {
			amount, err := s.regularRemaining(ctx, tx, order)
			if err != nil {
				return err
			}
			if amount > 0 {
				transaction, err := s.latestPendingOrAnyTransaction(ctx, tx, order.ID)
				if err != nil {
					return err
				}
				if err := s.settleCodCollection(ctx, tx, order, transaction, amount, in.ActorID); err != nil {
					return err
				}
			}
		} else {
			paidTxs, err := s.paidTransactions(ctx, tx, order.ID)
			if err != nil {
				return err
			}
			var hasResidual bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM payment_transactions
				WHERE order_id = ? AND JSON_EXTRACT(raw_payload, '$.residual_of') IS NOT NULL)`,
				order.ID).Scan(&hasResidual); err != nil {
				return err
			}
			isSingleFullCollection := len(paidTxs) == 1 && !hasResidual &&
				money.Round2(paidTxs[0].Amount) >= money.Round2(order.Total)-0.004
			if isSingleFullCollection {
				successful, err := s.successfulPaymentCount(ctx, tx, order.ID)
				if err != nil {
					return err
				}
				if successful > 1 {
					isSingleFullCollection = false
				}
			}
			if len(paidTxs) > 0 && !isSingleFullCollection {
				return &Problem{Status: 422, Message: s.Messages("payment.cod_undo_not_allowed")}
			}
			zero := 0.0
			if err := s.recalculatePaymentStatus(ctx, tx, order.ID, &zero); err != nil {
				return err
			}
			if len(paidTxs) > 0 {
				if _, err := tx.ExecContext(ctx, `UPDATE payment_transactions SET status = 'pending',
					paid_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?`, paidTxs[0].ID); err != nil {
					return err
				}
			}
			if err := s.syncInvoiceAfterChange(ctx, tx, order.ID, in.ActorID, "cod_unmarked"); err != nil {
				return err
			}
		}

		after, err := s.lockOrderForPayment(ctx, tx, order.ID)
		if err != nil {
			return err
		}
		return logActivity(ctx, tx, in.ActorID, order.ID, "payment.cod_status_changed", map[string]any{
			"from": previous, "to": after.PaymentStatus,
		}, s.IP, s.UserAgent)
	})
	if err != nil {
		return 0, err
	}
	return in.OrderID, nil
}

// settleCodCollection is PaymentService::settleCodCollection (REM-004 partial collections).
func (s *Service) settleCodCollection(ctx context.Context, tx *sql.Tx, order *paidOrder,
	transaction *PaymentTransaction, collected float64, actorID int64) error {

	collected = money.Round2(collected)
	if collected <= 0 {
		return &Problem{Status: 422, Message: s.Messages("payment.overpayment_refused")}
	}
	if err := s.assertPaymentAllowed(ctx, tx, order, collected, false); err != nil {
		return err
	}
	if transaction == nil {
		if order.PaymentMethodID == nil {
			return &Problem{Status: 422, Message: s.Messages("payment.order_not_cod")}
		}
		payload, _ := json.Marshal(map[string]any{"note": "cash_on_delivery"})
		res, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
			(order_id, payment_method_id, type, amount, status, raw_payload, created_at, updated_at)
			VALUES (?, ?, 'payment', ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			order.ID, *order.PaymentMethodID, money.String(collected), string(payload))
		if err != nil {
			return err
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		transaction, err = s.loadPaymentTransaction(ctx, tx, id, true)
		if err != nil {
			return err
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE payment_transactions SET amount = ?, status = 'paid',
		paid_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		money.String(collected), transaction.ID); err != nil {
		return err
	}
	if err := s.applyPaymentToOrder(ctx, tx, order, collected); err != nil {
		return err
	}
	if err := s.syncInvoiceAfterChange(ctx, tx, order.ID, actorID, "cod_collected"); err != nil {
		return err
	}
	after, err := s.lockOrderForPayment(ctx, tx, order.ID)
	if err != nil {
		return err
	}
	remaining, err := s.regularRemaining(ctx, tx, after)
	if err != nil {
		return err
	}
	if remaining > 0 {
		payload, _ := json.Marshal(map[string]any{
			"note": "cash_on_delivery", "residual_of": transaction.ID,
		})
		if _, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
			(order_id, payment_method_id, type, amount, status, raw_payload, created_at, updated_at)
			VALUES (?, ?, 'payment', ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			order.ID, transaction.PaymentMethodID, money.String(remaining), string(payload)); err != nil {
			return err
		}
	}
	return nil
}

func (s *Service) latestPendingOrAnyTransaction(ctx context.Context, tx *sql.Tx, orderID int64) (*PaymentTransaction, error) {
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM payment_transactions WHERE order_id = ? AND status = 'pending'
		ORDER BY id DESC LIMIT 1 FOR UPDATE`, orderID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		err = tx.QueryRowContext(ctx, `SELECT id FROM payment_transactions WHERE order_id = ?
			ORDER BY id DESC LIMIT 1 FOR UPDATE`, orderID).Scan(&id)
	}
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.loadPaymentTransaction(ctx, tx, id, true)
}

func (s *Service) paidTransactions(ctx context.Context, tx *sql.Tx, orderID int64) ([]*PaymentTransaction, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id FROM payment_transactions
		WHERE order_id = ? AND status = 'paid' ORDER BY id FOR UPDATE`, orderID)
	if err != nil {
		return nil, err
	}
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return nil, err
		}
		ids = append(ids, id)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	out := make([]*PaymentTransaction, 0, len(ids))
	for _, id := range ids {
		p, err := s.loadPaymentTransaction(ctx, tx, id, true)
		if err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, nil
}

// SubmitCodProofInput is POST /orders/{order}/payment/cod-proof.
type SubmitCodProofInput struct {
	OrderID   int64
	ActorID   int64
	ActorRole string
	PaidBy    string
	Amount    *float64
	Proof     media.Upload
}

// SubmitCodPaymentProof is PaymentService::submitCodPaymentProof.
func (s *Service) SubmitCodPaymentProof(ctx context.Context, in SubmitCodProofInput) (*PaymentTransaction, error) {
	transaction, err := s.latestCodTransaction(ctx, in.OrderID)
	if err != nil {
		return nil, err
	}

	var proofID int64
	err = s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var existingID int64
		var existingStatus string
		err := tx.QueryRowContext(ctx, `SELECT id, status FROM cod_payment_proofs
			WHERE payment_transaction_id = ? FOR UPDATE`, transaction.ID).Scan(&existingID, &existingStatus)
		switch {
		case errors.Is(err, sql.ErrNoRows):
			existingID = 0
		case err != nil:
			return err
		}
		order, err := s.lockOrderForPayment(ctx, tx, transaction.OrderID)
		if err != nil {
			return err
		}
		if order.Status == "dibatalkan" || order.PaymentStatus == "paid" ||
			(existingID > 0 && existingStatus == "confirmed") {
			return &Problem{Status: 422, Message: s.Messages("payment.proof_locked")}
		}
		open, err := s.regularRemaining(ctx, tx, order)
		if err != nil {
			return err
		}
		amount := open
		if in.Amount != nil {
			amount = *in.Amount
		}
		if err := s.assertPaymentAllowed(ctx, tx, order, amount, false); err != nil {
			return err
		}
		onBehalf := order.KonsumenID == nil || *order.KonsumenID != in.ActorID

		mediableType := "App\\Models\\PaymentTransaction"
		stored, err := media.Store(ctx, tx, "cod_payment_proof", in.Proof, &mediableType,
			&transaction.ID, &in.ActorID)
		if err != nil {
			return err
		}

		var collected any
		if in.Amount != nil {
			collected = money.String(*in.Amount)
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO cod_payment_proofs
			(payment_transaction_id, proof_media_id, collected_amount, status, confirmed_by, confirmed_at,
			 rejection_reason, submitted_by_user_id, submitted_on_behalf, paid_by_role, submitted_at,
			 created_at, updated_at)
			VALUES (?, ?, ?, 'pending', NULL, NULL, NULL, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE proof_media_id = VALUES(proof_media_id),
			 collected_amount = VALUES(collected_amount), status = 'pending', confirmed_by = NULL,
			 confirmed_at = NULL, rejection_reason = NULL, submitted_by_user_id = VALUES(submitted_by_user_id),
			 submitted_on_behalf = VALUES(submitted_on_behalf), paid_by_role = VALUES(paid_by_role),
			 submitted_at = UTC_TIMESTAMP()`,
			transaction.ID, stored.ID, collected, in.ActorID, boolInt(onBehalf), in.PaidBy)
		if err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT id FROM cod_payment_proofs
			WHERE payment_transaction_id = ?`, transaction.ID).Scan(&proofID); err != nil {
			return err
		}

		return logActivity(ctx, tx, in.ActorID, order.ID, "payment.cod_proof_submitted", map[string]any{
			"actor_role":               in.ActorRole,
			"payment_transaction_id":   transaction.ID,
			"on_behalf_of_konsumen_id": nullableOrderID(onBehalf, order.KonsumenID),
			"submitted_on_behalf":      onBehalf,
			"paid_by":                  in.PaidBy,
			"amount":                   transaction.Amount,
			"payment_method":           nullableColumn(order.PaymentCode),
			"order_payment_status":     order.PaymentStatus,
		}, s.IP, s.UserAgent)
	})
	if err != nil {
		return nil, err
	}

	p, err := s.loadPaymentTransaction(ctx, s.Database, transaction.ID, false)
	if err != nil {
		return nil, err
	}
	p.LoadCodProof = true
	proof, err := s.loadCodProof(ctx, s.Database, proofID)
	if err != nil {
		return nil, err
	}
	p.CodProof = proof
	return p, nil
}

// ConfirmCodProofInput is PATCH /admin/cod-payment-proofs/{proof}/confirm.
type ConfirmCodProofInput struct {
	ProofID         int64
	ActorID         int64
	ActorRole       string
	Confirmed       bool
	RejectionReason *string
}

// ConfirmCodPayment is PaymentService::confirmCodPayment and returns the owning order id.
func (s *Service) ConfirmCodPayment(ctx context.Context, in ConfirmCodProofInput) (int64, error) {
	var orderID int64
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var status string
		var transactionID int64
		err := tx.QueryRowContext(ctx, `SELECT status, payment_transaction_id FROM cod_payment_proofs
			WHERE id = ? FOR UPDATE`, in.ProofID).Scan(&status, &transactionID)
		if errors.Is(err, sql.ErrNoRows) {
			return ErrOrderNotFound
		}
		if err != nil {
			return err
		}
		transaction, err := s.loadPaymentTransaction(ctx, tx, transactionID, false)
		if err != nil {
			return err
		}
		order, err := s.lockOrderForPayment(ctx, tx, transaction.OrderID)
		if err != nil {
			return err
		}
		orderID = order.ID
		if status != "pending" {
			return &Problem{Status: 422, Message: s.Messages("payment.already_verified")}
		}

		proof, err := s.loadCodProof(ctx, tx, in.ProofID)
		if err != nil {
			return err
		}
		proofStatus := "rejected"
		var rejection any
		if in.Confirmed {
			proofStatus = "confirmed"
		} else {
			rejection = derefString(in.RejectionReason)
		}
		if _, err := tx.ExecContext(ctx, `UPDATE cod_payment_proofs SET status = ?, confirmed_by = ?,
			confirmed_at = UTC_TIMESTAMP(), rejection_reason = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, proofStatus, in.ActorID, rejection, in.ProofID); err != nil {
			return err
		}

		if in.Confirmed {
			open, err := s.regularRemaining(ctx, tx, order)
			if err != nil {
				return err
			}
			collected := 0.0
			if proof.CollectedAmount != nil {
				collected = *proof.CollectedAmount
			} else {
				collected = transaction.Amount
				if open < collected {
					collected = open
				}
			}
			transaction, err = s.loadPaymentTransaction(ctx, tx, transactionID, true)
			if err != nil {
				return err
			}
			if err := s.settleCodCollection(ctx, tx, order, transaction, collected, in.ActorID); err != nil {
				return err
			}
		}

		return logActivityDescribed(ctx, tx, in.ActorID, "App\\Models\\Order", order.ID,
			"payment.cod_proof_confirmed", in.RejectionReason, map[string]any{
				"confirmed": in.Confirmed, "actor_role": in.ActorRole,
			}, s.IP, s.UserAgent)
	})
	if err != nil {
		return 0, err
	}
	return orderID, nil
}

// ---- settlement / installment / due date ----------------------------------------------------------

// SettleInput is POST /orders/{order}/payment/settle.
type SettleInput struct {
	OrderID   int64
	ActorID   int64
	ActorRole string
}

// RequestSettlement is PaymentService::requestSettlement. `replay` reports an equivalent pending request.
func (s *Service) RequestSettlement(ctx context.Context, in SettleInput) (*PaymentTransaction, bool, error) {
	var (
		result   *PaymentTransaction
		replay   bool
		conflict *settlementConflict
	)
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		order, err := s.lockOrderForPayment(ctx, tx, in.OrderID)
		if err != nil {
			return err
		}
		if order.PaymentCode == nil || *order.PaymentCode != "down_payment" {
			return &Problem{Status: 422, Message: s.Messages("payment.not_dp_order")}
		}
		remaining, err := s.regularRemaining(ctx, tx, order)
		if err != nil {
			return err
		}
		if remaining <= 0 {
			return &Problem{Status: 422, Message: s.Messages("payment.nothing_to_settle")}
		}
		if err := s.assertPaymentAllowed(ctx, tx, order, remaining, false); err != nil {
			return err
		}
		pending, err := s.pendingSettlementFor(ctx, tx, order.ID)
		if err != nil {
			return err
		}
		if pending != nil && money.Round2(pending.Amount) == remaining {
			replay = true
			result = pending
			return logActivity(ctx, tx, in.ActorID, order.ID, "payment.settlement_request_replayed",
				map[string]any{
					"payment_transaction_id": pending.ID,
					"amount":                 remaining,
					"remaining_amount":       remaining,
					"idempotent_replay":      true,
					"actor_role":             in.ActorRole,
				}, s.IP, s.UserAgent)
		}
		if pending != nil {
			conflict = &settlementConflict{pending: pending, requested: remaining}
			return nil
		}

		methodID, config, err := s.bankTransferConfig(ctx, tx, order.AgentID)
		if err != nil {
			return err
		}
		reference, err := randomAlphaNumeric(10)
		if err != nil {
			return err
		}
		payload, err := json.Marshal(map[string]any{
			"bank_name":      config["bank_name"],
			"account_name":   config["account_name"],
			"account_number": config["account_number"],
			"note":           "dp_settlement",
		})
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
			(order_id, payment_method_id, type, amount, status, gateway_reference, raw_payload,
			 created_at, updated_at)
			VALUES (?, ?, 'payment', ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			order.ID, methodID, money.String(remaining), "ST-"+upperASCII(reference), string(payload))
		if err != nil {
			return err
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		result, err = s.loadPaymentTransaction(ctx, tx, id, false)
		if err != nil {
			return err
		}
		return logActivity(ctx, tx, in.ActorID, order.ID, "payment.settlement_requested",
			map[string]any{
				"payment_transaction_id": result.ID,
				"amount":                 result.Amount,
				"remaining_amount":       remaining,
				"actor_role":             in.ActorRole,
			}, s.IP, s.UserAgent)
	})
	if err != nil {
		return nil, false, err
	}
	if conflict != nil {
		if err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
			reason := "A settlement for a different outstanding amount is already pending"
			return logActivityDescribed(ctx, tx, in.ActorID, "App\\Models\\Order", in.OrderID,
				"payment.settlement_request_rejected", &reason, map[string]any{
					"pending_payment_transaction_id": conflict.pending.ID,
					"pending_amount":                 conflict.pending.Amount,
					"requested_amount":               conflict.requested,
					"actor_role":                     in.ActorRole,
				}, s.IP, s.UserAgent)
		}); err != nil {
			return nil, false, err
		}
		return nil, false, &Problem{Status: 422, Message: s.Messages("payment.already_processed")}
	}
	return result, replay, nil
}

type settlementConflict struct {
	pending   *PaymentTransaction
	requested float64
}

func (s *Service) pendingSettlementFor(ctx context.Context, tx *sql.Tx, orderID int64) (*PaymentTransaction, error) {
	var methodID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM payment_methods WHERE code = 'bank_transfer'`).
		Scan(&methodID); errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	} else if err != nil {
		return nil, err
	}
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM payment_transactions
		WHERE order_id = ? AND payment_method_id = ? AND type = 'payment' AND status = 'pending'
		  AND JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.note')) = 'dp_settlement'
		ORDER BY id DESC LIMIT 1 FOR UPDATE`, orderID, methodID.Int64).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.loadPaymentTransaction(ctx, tx, id, true)
}

// InstallmentInput is POST /orders/{order}/payment/installment.
type InstallmentInput struct {
	OrderID          int64
	ActorID          int64
	ActorRole        string
	Amount           float64
	Channel          string
	ReceivedByUserID *int64
	ReceivedAt       *string
	ReceiptReference *string
	ReceiptPhoto     *media.Upload
}

// RequestInstallment is PaymentService::requestInstallment.
func (s *Service) RequestInstallment(ctx context.Context, in InstallmentInput) (*PaymentTransaction, bool, error) {
	amount := money.Round2(in.Amount)
	channel := in.Channel
	if channel == "" {
		channel = "transfer"
	}
	var (
		result *PaymentTransaction
		replay bool
	)
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		order, err := s.lockOrderForPayment(ctx, tx, in.OrderID)
		if err != nil {
			return err
		}
		receivedByID := in.ActorID
		if in.ReceivedByUserID != nil && *in.ReceivedByUserID > 0 {
			receivedByID = *in.ReceivedByUserID
		}
		if order.PaymentType != nil && *order.PaymentType == "cod" {
			return &Problem{Status: 422, Message: s.Messages("payment.installment_not_for_cod")}
		}
		if order.Status != "diproses" && order.Status != "dikirim" && order.Status != "terkirim" {
			return &Problem{Status: 422, Message: s.Messages("payment.installment_not_allowed_yet")}
		}
		remaining, err := s.regularRemaining(ctx, tx, order)
		if err != nil {
			return err
		}
		if remaining <= 0 {
			return &Problem{Status: 422, Message: s.Messages("payment.nothing_to_settle")}
		}
		if amount <= 0 || amount > remaining {
			return &Problem{Status: 422, Message: s.Messages("payment.installment_amount_invalid"),
				Fields: map[string]string{"amount": s.Messages("payment.installment_amount_invalid")}}
		}
		if err := s.assertPaymentAllowed(ctx, tx, order, amount, false); err != nil {
			return err
		}

		var methodID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM payment_methods WHERE code = 'bank_transfer'`).
			Scan(&methodID); err != nil {
			return err
		}
		var pendingID int64
		var pendingAmount string
		var pendingRaw sql.NullString
		err = tx.QueryRowContext(ctx, `SELECT id, CAST(amount AS CHAR), raw_payload FROM payment_transactions
			WHERE order_id = ? AND payment_method_id = ? AND type = 'payment' AND status = 'pending'
			ORDER BY id DESC LIMIT 1 FOR UPDATE`, order.ID, methodID.Int64).
			Scan(&pendingID, &pendingAmount, &pendingRaw)
		switch {
		case err == nil:
			raw := decodeRawPayload(pendingRaw)
			note, _ := rawString(raw, "note")
			rawChannel, hasChannel := rawString(raw, "channel")
			if !hasChannel {
				rawChannel = "transfer"
			}
			requestedBy, _ := rawInt(raw, "requested_by")
			if money.Round2(parseString(pendingAmount)) == amount && note == "installment" &&
				rawChannel == channel && requestedBy == in.ActorID {
				result, err = s.loadPaymentTransaction(ctx, tx, pendingID, false)
				if err != nil {
					return err
				}
				replay = true
				return nil
			}
			return &Problem{Status: 422, Message: s.Messages("payment.already_processed")}
		case !errors.Is(err, sql.ErrNoRows):
			return err
		}

		methodConfigID, config, err := s.bankTransferConfig(ctx, tx, order.AgentID)
		if err != nil {
			return err
		}
		reference, err := randomAlphaNumeric(10)
		if err != nil {
			return err
		}
		prefix := "IN-"
		if channel == "cash" {
			prefix = "CS-"
		}
		var cashPayload any
		if channel == "cash" {
			// Laravel's global ConvertEmptyStringsToNull turns an empty input into NULL before it reaches
			// the service, so the raw_payload stores nulls — never empty strings.
			cashPayload = map[string]any{
				"received_by_user_id": receivedByID,
				"received_at":         nullIfEmpty(derefString(in.ReceivedAt)),
				"receipt_reference":   nullIfEmpty(derefString(in.ReceiptReference)),
			}
		}
		payload, err := json.Marshal(map[string]any{
			"bank_name":      config["bank_name"],
			"account_name":   config["account_name"],
			"account_number": config["account_number"],
			"note":           "installment",
			"channel":        channel,
			"requested_by":   in.ActorID,
			"cash":           cashPayload,
		})
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
			(order_id, payment_method_id, type, amount, status, gateway_reference, raw_payload,
			 created_at, updated_at)
			VALUES (?, ?, 'payment', ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			order.ID, methodConfigID, money.String(amount), prefix+upperASCII(reference), string(payload))
		if err != nil {
			return err
		}
		transactionID, err := res.LastInsertId()
		if err != nil {
			return err
		}

		if channel == "cash" {
			// Laravel stores the receipt photo through `$cashPhoto->store(self::PROOF_DIRECTORY, PRIVATE_DISK)`
			// → the MIME-guessed extension, same as the transfer proofs.
			proofPath := ""
			if in.ReceiptPhoto != nil {
				var storeErr error
				proofPath, storeErr = storePrivateUpload("payments/bank-transfer-proofs", *in.ReceiptPhoto, mimeExtension(in.ReceiptPhoto.Content))
				if storeErr != nil {
					return storeErr
				}
			}
			receivedBy := receivedByID
			receivedAt := time.Now().UTC()
			var receivedAtValue any = receivedAt
			if in.ReceivedAt != nil && strings.TrimSpace(*in.ReceivedAt) != "" {
				if parsed, err := parseFlexibleDate(*in.ReceivedAt); err == nil {
					receivedAtValue = parsed
				}
			}
			paidBy := "konsumen"
			if in.ActorRole == "korsal" {
				paidBy = "korsal"
			} else if in.ActorRole == "sales" || in.ActorRole == "sales-kurir-sub" {
				paidBy = "sales"
			}
			onBehalf := order.KonsumenID == nil || *order.KonsumenID != in.ActorID
			if _, err := tx.ExecContext(ctx, `INSERT INTO bank_transfer_verifications
				(payment_transaction_id, bank_name, account_name, account_number, proof_image_path, proof_disk,
				 status, channel, submitted_amount, submitted_by_user_id, submitted_on_behalf, paid_by_role,
				 submitted_at, cash_received_by_user_id, cash_received_at, receipt_reference,
				 created_at, updated_at)
				VALUES (?, 'TUNAI', '-', '-', ?, 'local', 'pending', 'cash', ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?,
				 UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
				transactionID, proofPath, money.String(amount), in.ActorID, boolInt(onBehalf), paidBy,
				receivedBy, receivedAtValue, derefString(in.ReceiptReference)); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE orders SET payment_status = 'pending_verification',
				updated_at = UTC_TIMESTAMP() WHERE id = ?`, order.ID); err != nil {
				return err
			}
		}

		result, err = s.loadPaymentTransaction(ctx, tx, transactionID, false)
		if err != nil {
			return err
		}
		return logActivity(ctx, tx, in.ActorID, order.ID, "payment.installment_requested",
			map[string]any{
				"payment_transaction_id": result.ID,
				"amount":                 amount,
				"channel":                channel,
				"remaining_amount":       remaining,
				"actor_role":             in.ActorRole,
			}, s.IP, s.UserAgent)
	})
	if err != nil {
		return nil, false, err
	}
	return result, replay, nil
}

// SetDueDateInput is PUT /orders/{order}/due-date.
type SetDueDateInput struct {
	OrderID   int64
	ActorID   int64
	ActorRole string
	DueDate   string
	Reason    string
}

// SetDueDate is PaymentService::setDueDate.
func (s *Service) SetDueDate(ctx context.Context, in SetDueDateInput) (string, error) {
	var out string
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var oldDue sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT CAST(due_date AS CHAR) FROM orders WHERE id = ? FOR UPDATE`,
			in.OrderID).Scan(&oldDue); errors.Is(err, sql.ErrNoRows) {
			return ErrOrderNotFound
		} else if err != nil {
			return err
		}
		newDate, err := parseFlexibleDate(in.DueDate)
		if err != nil {
			return &Problem{Status: 422, Message: s.Messages("system.validation_failed"),
				Fields: map[string]string{"due_date": s.Messages("validation.date", "attribute", "due date")}}
		}
		newValue := newDate.Format("2006-01-02")
		out = newValue
		if oldDue.Valid && oldDue.String == newValue {
			return nil
		}
		if _, err := tx.ExecContext(ctx, `UPDATE orders SET due_date = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, newValue, in.OrderID); err != nil {
			return err
		}
		var oldValue any
		if oldDue.Valid && oldDue.String != "" {
			oldValue = oldDue.String
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_due_date_changes
			(order_id, old_due_date, new_due_date, reason, changed_by, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			in.OrderID, oldValue, newValue, in.Reason, in.ActorID); err != nil {
			return err
		}
		return logActivityDescribed(ctx, tx, in.ActorID, "App\\Models\\Order", in.OrderID, "order.due_date_changed",
			&in.Reason, map[string]any{"old": oldValue, "new": newValue}, s.IP, s.UserAgent)
	})
	if err != nil {
		return "", err
	}
	return out, nil
}

// ---- shared helpers --------------------------------------------------------------------------------

func (s *Service) latestManualTransaction(ctx context.Context, orderID int64) (*PaymentTransaction, error) {
	var id int64
	err := s.Database.QueryRowContext(ctx, `SELECT pt.id FROM payment_transactions pt
		JOIN payment_methods pm ON pm.id = pt.payment_method_id
		WHERE pt.order_id = ? AND pm.type = 'manual'
		ORDER BY pt.id DESC LIMIT 1`, orderID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &Problem{Status: 422, Message: s.Messages("payment.order_not_awaiting_proof")}
	}
	if err != nil {
		return nil, err
	}
	return s.loadPaymentTransaction(ctx, s.Database, id, false)
}

func (s *Service) latestCodTransaction(ctx context.Context, orderID int64) (*PaymentTransaction, error) {
	var id int64
	err := s.Database.QueryRowContext(ctx, `SELECT pt.id FROM payment_transactions pt
		JOIN payment_methods pm ON pm.id = pt.payment_method_id
		WHERE pt.order_id = ? AND pm.type = 'cod'
		ORDER BY pt.id DESC LIMIT 1`, orderID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &Problem{Status: 422, Message: s.Messages("payment.cod_proof_not_found")}
	}
	if err != nil {
		return nil, err
	}
	return s.loadPaymentTransaction(ctx, s.Database, id, false)
}

// bankTransferConfig is activeConfigFor(bank_transfer) — a missing branch configuration is a 422.
func (s *Service) bankTransferConfig(ctx context.Context, q querier, agentID int64) (int64, map[string]any, error) {
	var methodID int64
	if err := q.QueryRowContext(ctx, `SELECT id FROM payment_methods WHERE code = 'bank_transfer'`).
		Scan(&methodID); err != nil {
		return 0, nil, err
	}
	environment := "sandbox"
	var stored string
	err := q.QueryRowContext(ctx, `SELECT active_environment FROM agent_payment_method_settings
		WHERE agent_id = ? AND payment_method_id = ?`, agentID, methodID).Scan(&stored)
	if err == nil && stored != "" {
		environment = stored
	} else if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return 0, nil, err
	}
	var configRaw sql.NullString
	err = q.QueryRowContext(ctx, `SELECT config FROM agent_payment_gateway_configs
		WHERE agent_id = ? AND payment_method_id = ? AND environment = ?`, agentID, methodID, environment).
		Scan(&configRaw)
	var methodName string
	_ = q.QueryRowContext(ctx, `SELECT name FROM payment_methods WHERE id = ?`, methodID).Scan(&methodName)
	notConfigured := &Problem{Status: 422, Message: s.Messages("payment.method_not_configured", "name", methodName)}
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil, notConfigured
	}
	if err != nil {
		return 0, nil, err
	}
	// The column is Laravel `encrypted:array`; an undecryptable value is the same "not configured" answer.
	decoded, derr := decryptJSON(s.AppKey, configRaw.String)
	if derr != nil {
		return 0, nil, notConfigured
	}
	return methodID, map[string]any{
		"bank_name": decoded["bank_name"], "account_name": decoded["account_name"],
		"account_number": decoded["account_number"],
	}, nil
}

// PaymentResponseMode picks which verification relation the controller eager loaded on paymentTransactions.
type PaymentResponseMode int

const (
	// ModeVerification loads paymentTransactions.bankTransferVerification (markCod, settle).
	ModeVerification PaymentResponseMode = iota
	// ModeCodProof loads paymentTransactions.codPaymentProof.proof (confirm).
	ModeCodProof
)

// LoadOrderForPaymentResponse loads the order with the relation set a payment response needs: items WITHOUT their
// shipment, actors, paymentMethod, shipments.courier, and every payment transaction with the requested relation.
func (s *Service) LoadOrderForPaymentResponse(ctx context.Context, orderID int64, mode PaymentResponseMode) (*Order, error) {
	order, err := s.loadOrder(ctx, orderID, 0)
	if err != nil {
		return nil, err
	}
	order.LoadActors = true
	order.LoadItemShipment = false
	order.LoadPaymentMethod = true
	order.LoadPaymentTransactions = true

	var latestID int64
	err = s.Database.QueryRowContext(ctx, `SELECT id FROM payment_transactions
		WHERE order_id = ? ORDER BY id DESC LIMIT 1`, orderID).Scan(&latestID)
	if errors.Is(err, sql.ErrNoRows) {
		return order, nil
	}
	if err != nil {
		return nil, err
	}
	p, err := s.loadPaymentTransaction(ctx, s.Database, latestID, false)
	if err != nil {
		return nil, err
	}
	if err := s.attachRelations(ctx, p, mode == ModeCodProof, mode == ModeVerification); err != nil {
		return nil, err
	}
	order.LoadTransactionVerification = mode == ModeVerification
	order.LoadTransactionCodProof = mode == ModeCodProof
	order.Payment = p
	return order, nil
}

// attachRelations eager loads the verification relations a response needs onto one transaction.
func (s *Service) attachRelations(ctx context.Context, p *PaymentTransaction, cod, verification bool) error {
	if verification {
		p.LoadVerification = true
		var id int64
		err := s.Database.QueryRowContext(ctx, `SELECT id FROM bank_transfer_verifications
			WHERE payment_transaction_id = ?`, p.ID).Scan(&id)
		if err == nil {
			v, err := s.loadVerification(ctx, s.Database, id)
			if err != nil {
				return err
			}
			p.Verification = v
		} else if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
	}
	if cod {
		p.LoadCodProof = true
		var id int64
		err := s.Database.QueryRowContext(ctx, `SELECT id FROM cod_payment_proofs
			WHERE payment_transaction_id = ?`, p.ID).Scan(&id)
		if err == nil {
			proof, err := s.loadCodProof(ctx, s.Database, id)
			if err != nil {
				return err
			}
			p.CodProof = proof
		} else if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
	}
	return nil
}

// LoadTransactionResource reloads one transaction with the requested relations (`$transaction->fresh(...)`).
func (s *Service) LoadTransactionResource(ctx context.Context, id int64, cod, verification bool) (*PaymentTransaction, error) {
	p, err := s.loadPaymentTransaction(ctx, s.Database, id, false)
	if err != nil {
		return nil, err
	}
	if err := s.attachRelations(ctx, p, cod, verification); err != nil {
		return nil, err
	}
	return p, nil
}

// RenderTransaction is PaymentTransactionResource for a standalone transaction response.
func RenderTransaction(p *PaymentTransaction) map[string]any {
	return renderPaymentTransaction(p, p.LoadVerification, p.LoadCodProof)
}

// PayOnBehalf is OrderPolicy::payOnBehalf.
func (s *Service) PayOnBehalf(ctx context.Context, v RoleScopes, orderID int64) (bool, error) {
	if !v.isRole("korsal", "sales", "sales-kurir-sub") {
		return false, nil
	}
	var (
		agentID, konsumenID, salesID sql.NullInt64
		crm                          sql.NullInt64
	)
	err := s.Database.QueryRowContext(ctx, `SELECT agent_id, konsumen_id, sales_id, crm_customer_id FROM orders WHERE id = ?`,
		orderID).Scan(&agentID, &konsumenID, &salesID, &crm)
	if err != nil {
		return false, err
	}
	if crm.Valid {
		return v.isRole("sales", "sales-kurir-sub") && v.AgentID != nil && agentID.Valid &&
			agentID.Int64 == *v.AgentID && salesID.Valid && salesID.Int64 == v.UserID, nil
	}
	if !konsumenID.Valid || konsumenID.Int64 == v.UserID {
		return false, nil
	}
	var (
		role                    string
		kAgent, kKorsal, kSales sql.NullInt64
	)
	err = s.Database.QueryRowContext(ctx, `SELECT r.slug, u.agent_id, u.korsal_id, u.sales_id FROM users u
		JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, konsumenID.Int64).Scan(&role, &kAgent, &kKorsal, &kSales)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if role != "konsumen" || v.AgentID == nil || !agentID.Valid || agentID.Int64 != *v.AgentID ||
		!kAgent.Valid || kAgent.Int64 != *v.AgentID {
		return false, nil
	}
	if v.Role == "korsal" {
		return kKorsal.Valid && kKorsal.Int64 == v.UserID, nil
	}
	return kSales.Valid && kSales.Int64 == v.UserID, nil
}

// OrderAgentScope reports (exists, agentID) for the route-model binding: Order carries BelongsToAgentScope.
func (s *Service) OrderAgentScope(ctx context.Context, orderID int64) (int64, error) {
	var agent int64
	err := s.Database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, orderID).Scan(&agent)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, ErrOrderNotFound
	}
	return agent, err
}

// ProofFile resolves a bank-transfer verification for the authorised download.
type ProofFile struct {
	Path     string
	Disk     string
	OrderID  int64
	AgentID  int64
	Konsumen *int64
}

// LoadProofFile is the scope-free load PaymentController::proofFile performs.
func (s *Service) LoadProofFile(ctx context.Context, verificationID int64) (*ProofFile, error) {
	var f ProofFile
	var konsumen sql.NullInt64
	err := s.Database.QueryRowContext(ctx, `SELECT v.proof_image_path, v.proof_disk, o.id, o.agent_id, o.konsumen_id
		FROM bank_transfer_verifications v
		JOIN payment_transactions t ON t.id = v.payment_transaction_id
		JOIN orders o ON o.id = t.order_id WHERE v.id = ?`, verificationID).
		Scan(&f.Path, &f.Disk, &f.OrderID, &f.AgentID, &konsumen)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrOrderNotFound
	}
	if err != nil {
		return nil, err
	}
	f.Konsumen = nullIntPtr(konsumen)
	return &f, nil
}

// ProofAbsolutePath resolves the stored proof to a filesystem path (private `local` disk vs legacy public).
func (f *ProofFile) ProofAbsolutePath() string {
	root := media.Root
	if f.Disk == "local" {
		root = privateDiskRoot()
	}
	return filepath.Join(root, filepath.FromSlash(f.Path))
}

// ---- private disk ----------------------------------------------------------------------------------

// privateDiskRoot is the `local` disk root (Laravel: storage/app/private).
func privateDiskRoot() string {
	return filepath.Join(filepath.Dir(media.Root), "private")
}

func storePrivateUpload(directory string, upload media.Upload, extension string) (string, error) {
	name, err := randomAlphaNumeric(40)
	if err != nil {
		return "", err
	}
	path := directory + "/" + strings.ToLower(name) + "." + extension
	absolute := filepath.Join(privateDiskRoot(), filepath.FromSlash(path))
	if err := os.MkdirAll(filepath.Dir(absolute), 0o755); err != nil {
		return "", err
	}
	if err := os.WriteFile(absolute, upload.Content, 0o644); err != nil {
		return "", err
	}
	return path, nil
}

// ---- small conversions -----------------------------------------------------------------------------

func nullMoneyPtr(v sql.NullString) *float64 {
	if !v.Valid {
		return nil
	}
	f := money.Round2(parseString(v.String))
	return &f
}

// derefInt64 is the int64 deref (create.go's derefInt renders a string for SQL interpolation).
func derefInt64(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

func boolInt(v bool) int {
	if v {
		return 1
	}
	return 0
}

func nullableColumn(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullableOrderID(onBehalf bool, id *int64) any {
	if !onBehalf || id == nil {
		return nil
	}
	return *id
}

func nullableIntValue(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}

func nullableStringValue(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func parseFlexibleDate(value string) (time.Time, error) {
	value = strings.TrimSpace(value)
	for _, layout := range []string{"2006-01-02", time.RFC3339, "2006-01-02 15:04:05"} {
		if parsed, err := time.Parse(layout, value); err == nil {
			return parsed, nil
		}
	}
	return time.Time{}, fmt.Errorf("orders: unparseable date %q", value)
}

// paymentMethodCode reads a transaction's payment-method code (the audit trail records it).
func paymentMethodCode(ctx context.Context, q querier, methodID int64) (any, error) {
	var code string
	if err := q.QueryRowContext(ctx, `SELECT code FROM payment_methods WHERE id = ?`, methodID).Scan(&code); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	return code, nil
}

// mimeExtension maps a sniffed MIME type to the extension Laravel's guessExtension() would produce.
func mimeExtension(content []byte) string {
	switch httpDetectContentType(content) {
	case "image/jpeg":
		return "jpg"
	case "image/png":
		return "png"
	case "image/webp":
		return "webp"
	case "image/gif":
		return "gif"
	case "image/bmp":
		return "bmp"
	}
	return ""
}

// httpDetectContentType sniffs the real content type (the reference validates the MIME, never the client header).
func httpDetectContentType(content []byte) string {
	return strings.ToLower(http.DetectContentType(content))
}

// payerSummary is PaymentTransactionResource::payerSummary: {id, name, role} or null.
func (s *Service) payerSummary(ctx context.Context, q querier, userID *int64) any {
	if userID == nil {
		return nil
	}
	var name, role sql.NullString
	if err := q.QueryRowContext(ctx, `SELECT u.name, r.slug FROM users u LEFT JOIN roles r ON r.id = u.role_id
		WHERE u.id = ?`, *userID).Scan(&name, &role); err != nil {
		return nil
	}
	return map[string]any{"id": *userID, "name": nullableStringRow(name), "role": nullableStringRow(role)}
}

// OrderIsCod is `$order->paymentMethod?->type === 'cod'`.
func (s *Service) OrderIsCod(ctx context.Context, orderID int64) (bool, error) {
	var kind sql.NullString
	err := s.Database.QueryRowContext(ctx, `SELECT pm.type FROM orders o
		LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id WHERE o.id = ?`, orderID).Scan(&kind)
	return kind.Valid && kind.String == "cod", err
}

// OrderPaymentCode is `$order->paymentMethod?->code`.
func (s *Service) OrderPaymentCode(ctx context.Context, orderID int64) (string, error) {
	var code sql.NullString
	err := s.Database.QueryRowContext(ctx, `SELECT pm.code FROM orders o
		LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id WHERE o.id = ?`, orderID).Scan(&code)
	return code.String, err
}

// LatestManualVerification is `latestManualTransaction($order)->bankTransferVerification` (id + status).
func (s *Service) LatestManualVerification(ctx context.Context, orderID int64) (*PaymentTransaction, *BankTransferVerification, error) {
	transaction, err := s.latestManualTransaction(ctx, orderID)
	if err != nil {
		return nil, nil, err
	}
	var id int64
	err = s.Database.QueryRowContext(ctx, `SELECT id FROM bank_transfer_verifications
		WHERE payment_transaction_id = ?`, transaction.ID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return transaction, nil, nil
	}
	if err != nil {
		return nil, nil, err
	}
	v, err := s.loadVerification(ctx, s.Database, id)
	return transaction, v, err
}

// CodProofOrder resolves a COD proof's owning order (route-model binding for {codPaymentProof}).
func (s *Service) CodProofOrder(ctx context.Context, proofID int64) (orderID, agentID int64, konsumen *int64, status string, err error) {
	var k sql.NullInt64
	err = s.Database.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.konsumen_id, cp.status
		FROM cod_payment_proofs cp JOIN payment_transactions t ON t.id = cp.payment_transaction_id
		JOIN orders o ON o.id = t.order_id WHERE cp.id = ?`, proofID).Scan(&orderID, &agentID, &k, &status)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, 0, nil, "", ErrOrderNotFound
	}
	return orderID, agentID, nullIntPtr(k), status, err
}
