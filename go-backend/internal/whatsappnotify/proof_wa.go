package whatsappnotify

import (
	"context"
	"database/sql"
	"fmt"
)

// NotifyPaymentProofUploadedWhatsApp is OrderNotifier::paymentProofUploaded's WhatsApp
// lane: recipients are the branch's Keuangan ONLY; the proof hash makes an identical
// re-upload idempotent.
func NotifyPaymentProofUploadedWhatsApp(ctx context.Context, tx executor, verificationID, orderID int64, proofHash string) error {
	o, err := LoadOrder(ctx, tx, orderID)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil
		}
		return err
	}
	if !EventEnabledFor(ctx, tx, EventPaymentProofUploaded, nilInt64(o.AgentID)) {
		return nil
	}
	users := financeRecipients(ctx, tx, o)
	vars := OrderVars(ctx, tx, o)
	for _, user := range users {
		msisdn := MsisdnForUser(ctx, tx, user)
		if msisdn == "" {
			attrs := LedgerAttrs{
				IdempotencyKey: fmt.Sprintf("txn:%s:bank_transfer_verification:%d:%s:u%d",
					EventPaymentProofUploaded, verificationID, proofHash, user),
				Event: EventPaymentProofUploaded, AgentID: nilInt64(o.AgentID), OrderID: &o.ID,
				SubjectType: "bank_transfer_verification", SubjectID: verificationID,
				RecipientRole: "finance", RecipientUserID: &user, TemplateClass: "full",
			}
			if err := SkipTx(ctx, tx, attrs, "number_not_verified"); err != nil {
				return err
			}
			continue
		}
		base := LedgerAttrs{
			IdempotencyKey: fmt.Sprintf("txn:%s:bank_transfer_verification:%d:%s:u%d",
				EventPaymentProofUploaded, verificationID, proofHash, user),
			Event: EventPaymentProofUploaded, AgentID: nilInt64(o.AgentID), OrderID: &o.ID,
			SubjectType: "bank_transfer_verification", SubjectID: verificationID,
			RecipientRole: "finance", RecipientUserID: &user, RecipientPhone: msisdn,
			TemplateClass: "full",
		}
		if err := sendTo(ctx, tx, base, o, EventPaymentProofUploaded,
			Recipient{Role: "finance", Msisdn: msisdn, UserID: &user, TemplateClass: "full", Primary: true}, vars); err != nil {
			return err
		}
	}
	return nil
}

// financeRecipients ports FinanceRecipientResolver::for: ACTIVE Keuangan of the branch.
func financeRecipients(ctx context.Context, tx executor, o *orderRow) []int64 {
	if !o.AgentID.Valid {
		return nil
	}
	rows, err := tx.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
		WHERE r.slug = 'keuangan' AND u.agent_id = ? AND u.status = 'active' AND u.deleted_at IS NULL
		ORDER BY u.id`, o.AgentID.Int64)
	if err != nil {
		return nil
	}
	defer rows.Close()
	var out []int64
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			out = append(out, id)
		}
	}
	return out
}
