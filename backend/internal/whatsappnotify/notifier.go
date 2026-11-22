package whatsappnotify

import (
	"context"
	"database/sql"
	"fmt"
)

// notifier.go ports OrderNotifier + TransactionalEmailNotifier dispatch. Everything
// runs on the caller's transaction connection: rows and jobs commit with the business
// change or not at all (Laravel's observers defer to after-commit; the committed set
// is identical, and atomic dispatch can never strand a job on rollback).

// Extra event variables beyond data(order) (e.g. nomor_retur, jumlah_refund).
type Extra map[string]*string

// NotifyOrderEvent is OrderNotifier::orderEvent → send(): the generic order/payment
// lifecycle fan-out (observer-driven). subjectID is normally the order id.
func NotifyOrderEvent(ctx context.Context, tx executor, event string, orderID int64) error {
	return notify(ctx, tx, event, "order", orderID, orderID, nil)
}

// NotifyReturnEvent is OrderNotifier::returnEvent (subject = the return).
func NotifyReturnEvent(ctx context.Context, tx executor, event string, returnID, orderID int64) error {
	no := fmt.Sprintf("RTN-%06d", returnID)
	return notify(ctx, tx, event, "return", returnID, orderID, Extra{"nomor_retur": &no})
}

// NotifyRefundPayout is OrderNotifier::refundEvent (subject = the payout).
func NotifyRefundPayout(ctx context.Context, tx executor, payoutID, orderID int64, amount float64, paidAt sql.NullTime) error {
	extra := Extra{"jumlah_refund": Str(moneyID(amount))}
	if paidAt.Valid {
		extra["tanggal_refund"] = dateID(paidAt.Time)
	}
	return notify(ctx, tx, EventRefundCompleted, "refund_payout", payoutID, orderID, extra)
}

// NotifyObligationEvent is OrderNotifier::obligationEvent (subject = the obligation).
func NotifyObligationEvent(ctx context.Context, tx executor, event string, obligationID, orderID int64,
	obligationNo string, amount float64) error {
	return notify(ctx, tx, event, "refund_obligation", obligationID, orderID, Extra{
		"nomor_refund": &obligationNo, "jumlah_refund": Str(moneyID(amount))})
}

// NotifyOrderStatusChanged maps an order status transition to WhatsAppLifecycleObserver's
// ORDER_STATUS_EVENT (diproses→processed, dikirim→shipped, terkirim→delivered,
// dibatalkan→cancelled) and dispatches both channels when the status actually changed.
// No-op for unknown or unchanged statuses.
func NotifyOrderStatusChanged(ctx context.Context, tx executor, orderID int64, oldStatus, newStatus string) error {
	if oldStatus == newStatus {
		return nil
	}
	var event string
	switch newStatus {
	case "diproses":
		event = EventOrderProcessed
	case "dikirim":
		event = EventOrderShipped
	case "terkirim":
		event = EventOrderDelivered
	case "dibatalkan":
		event = EventOrderCancelled
	}
	if event == "" {
		return nil
	}
	if err := NotifyOrderEvent(ctx, tx, event, orderID); err != nil {
		return err
	}
	return NotifyEmailOrderEvent(ctx, tx, event, "order", orderID, orderID, nil)
}

// NotifyObligationByID loads one refund obligation (order, obligation_no, amount) and
// dispatches obligationEvent on both channels.
func NotifyObligationByID(ctx context.Context, tx executor, event string, obligationID int64) error {
	var orderID int64
	var obligationNo string
	var amount sql.NullFloat64
	err := tx.QueryRowContext(ctx, `SELECT order_id, obligation_no, amount FROM refund_obligations WHERE id = ?`,
		obligationID).Scan(&orderID, &obligationNo, &amount)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil
		}
		return err
	}
	if err := NotifyObligationEvent(ctx, tx, event, obligationID, orderID, obligationNo, amount.Float64); err != nil {
		return err
	}
	return NotifyEmailOrderEvent(ctx, tx, event, "refund_obligation", obligationID, orderID, Extra{
		"nomor_refund": &obligationNo, "jumlah_refund": Str(moneyID(amount.Float64))})
}

// NotifyPayoutByID loads one PROVEN refund payout and dispatches refund_completed on
// both channels (only money that provably left counts as "refund berhasil").
func NotifyPayoutByID(ctx context.Context, tx executor, payoutID int64) error {
	var orderID int64
	var amount sql.NullFloat64
	var paidAt sql.NullTime
	err := tx.QueryRowContext(ctx, `SELECT order_id, amount, paid_at FROM refund_payouts
		WHERE id = ? AND verification_status = 'proven'`, payoutID).Scan(&orderID, &amount, &paidAt)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil
		}
		return err
	}
	if err := NotifyRefundPayout(ctx, tx, payoutID, orderID, amount.Float64, paidAt); err != nil {
		return err
	}
	extra := Extra{"jumlah_refund": Str(moneyID(amount.Float64))}
	if paidAt.Valid {
		v := dateID(paidAt.Time)
		extra["tanggal_refund"] = v
	}
	return NotifyEmailOrderEvent(ctx, tx, EventRefundCompleted, "refund_payout", payoutID, orderID, extra)
}

// NotifyPaymentRejected is OrderNotifier::paymentRejected (subject = the verification).
func NotifyPaymentRejected(ctx context.Context, tx executor, verificationID, orderID int64,
	submittedAmount sql.NullFloat64, rejectionReason sql.NullString) error {
	extra := Extra{}
	if submittedAmount.Valid {
		v := moneyID(submittedAmount.Float64)
		extra["jumlah_diajukan"] = &v
	}
	if rejectionReason.Valid {
		v := rejectionReason.String
		extra["alasan_penolakan"] = &v
	}
	return notify(ctx, tx, EventPaymentFailed, "bank_transfer_verification", verificationID, orderID, extra)
}

// notify is OrderNotifier::send (single dispatch path for every observer event).
func notify(ctx context.Context, tx executor, event, subjectType string, subjectID, orderID int64, extra Extra) error {
	o, err := LoadOrder(ctx, tx, orderID)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil
		}
		return fmt.Errorf("load order notification context: %w", err)
	}
	if !EventEnabledFor(ctx, tx, event, nilInt64(o.AgentID)) {
		return nil // disabled = no ledger row at all
	}
	base := LedgerAttrs{
		IdempotencyKey: fmt.Sprintf("txn:%s:%s:%d", event, subjectType, subjectID),
		Event:          event, AgentID: nilInt64(o.AgentID), OrderID: &o.ID,
		SubjectType: subjectType, SubjectID: subjectID,
	}
	if o.CRMOrigin || !o.KonsumenID.Valid {
		if err := SkipTx(ctx, tx, base, "recipient_not_verified"); err != nil {
			return fmt.Errorf("record recipient skip: %w", err)
		}
		return nil
	}
	targets := ResolveOrderRecipients(ctx, tx, o)
	if len(targets) == 0 {
		skip := base
		konsumenID := o.KonsumenID.Int64
		skip.RecipientUserID = &konsumenID
		var phone sql.NullString
		_ = tx.QueryRowContext(ctx, `SELECT phone FROM users WHERE id = ?`, konsumenID).Scan(&phone)
		reason := "number_not_verified"
		if !phone.Valid || NormalizeMSISDN(phone.String) == "" {
			reason = "no_phone"
		}
		if err := SkipTx(ctx, tx, skip, reason); err != nil {
			return fmt.Errorf("record recipient skip: %w", err)
		}
		return nil
	}
	vars := OrderVars(ctx, tx, o)
	for k, v := range extra {
		vars[k] = v
	}
	for _, t := range targets {
		if err := sendTo(ctx, tx, base, o, event, t, vars); err != nil {
			return fmt.Errorf("record %s recipient %s: %w", event, t.Role, err)
		}
	}
	return nil
}

func nilInt64(v sql.NullInt64) *int64 {
	if v.Valid {
		return &v.Int64
	}
	return nil
}

// sendTo is OrderNotifier::sendTo: one role-scoped row per verified recipient.
// The PRIMARY buyer row keeps the legacy txn:{event}:{subject}:{id} key; every other
// role appends ":{role}" as its own independent row.
func sendTo(ctx context.Context, tx executor, base LedgerAttrs, o *orderRow, event string, t Recipient,
	vars map[string]*string) error {
	key := base.IdempotencyKey
	if !t.Primary {
		key += ":" + t.Role
	}
	attrs := LedgerAttrs{
		IdempotencyKey: key, Event: base.Event, AgentID: base.AgentID, OrderID: base.OrderID,
		SubjectType: base.SubjectType, SubjectID: base.SubjectID,
		RecipientRole: t.Role, RecipientUserID: t.UserID, RecipientPhone: t.Msisdn,
		TemplateClass: t.TemplateClass,
	}
	var body string
	var ok bool
	if t.TemplateClass == "restricted" {
		// R-01 closed allow-list: an unproven number only ever hears status changes —
		// never payment/refund facts.
		if !restrictedEventEligible(t.Role, event) {
			return SkipTx(ctx, tx, attrs, "restricted_event_not_allowed")
		}
		var name *string
		if t.UserID != nil {
			var n sql.NullString
			_ = tx.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, *t.UserID).Scan(&n)
			name = nilIfEmpty(n)
		}
		body, ok = Render(event, RestrictedTemplate(event, o.OrderNo), map[string]*string{
			"nama_pelanggan": name, "nomor_pesanan": Str(o.OrderNo)})
	} else {
		body, ok = Render(event, TemplateFor(ctx, tx, event, nilInt64(o.AgentID)), vars)
	}
	if !ok {
		return SkipTx(ctx, tx, attrs, "template_unrenderable")
	}
	attrs.Body = body
	_, err := EnqueueTx(ctx, tx, attrs)
	return err
}
