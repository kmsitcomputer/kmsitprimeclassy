package notifications

import (
	"context"
	"database/sql"
	"fmt"
	"math"
	"strings"
)

type eventExecutor interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
	ExecContext(context.Context, string, ...any) (sql.Result, error)
}

// RecordOrderCreated mirrors AdminNotificationService::orderEvent for the
// ORDER_CREATED event. It is called on the order's transaction connection so
// the notification is committed only with the order. Failures are best-effort
// to the caller, matching the Laravel observer's exception boundary.
func RecordOrderCreated(ctx context.Context, tx eventExecutor, orderID, agentID int64, orderNo string, total float64) error {
	body := "Pesanan " + orderNo + " · Total Rp " + groupedRupiah(int64(math.Round(total)))
	return recordBranchEvent(ctx, tx, "order.created", "order", orderID, orderID, agentID, "info", "Pesanan baru", body)
}

func RecordReturnRequested(ctx context.Context, tx eventExecutor, orderID, agentID, returnID int64, orderNo string, total float64) error {
	body := "Pesanan " + orderNo + " · Total Rp " + groupedRupiah(int64(math.Round(total))) +
		fmt.Sprintf(" · Retur RTN-%06d", returnID)
	return recordBranchEvent(ctx, tx, "return.requested", "return", returnID, orderID, agentID, "info", "Pengajuan retur baru",
		body)
}

func RecordPaymentFailed(ctx context.Context, tx eventExecutor, orderID, agentID, verificationID int64, orderNo string, total float64) error {
	body := "Pesanan " + orderNo + " · Total Rp " + groupedRupiah(int64(math.Round(total)))
	return recordBranchEvent(ctx, tx, "payment.failed", "bank_transfer_verification", verificationID, orderID, agentID, "warning", "Pembayaran ditolak", body)
}

func recordBranchEvent(ctx context.Context, tx eventExecutor, event, subjectType string, subjectID, orderID, agentID int64,
	severity, title, body string) error {
	rows, err := tx.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
		WHERE (u.id=? AND r.slug='agen') OR (u.agent_id=? AND r.slug='admin') ORDER BY u.id`, agentID, agentID)
	if err != nil {
		return err
	}
	defer rows.Close()
	recipients := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return err
		}
		recipients = append(recipients, id)
	}
	if err := rows.Err(); err != nil {
		return err
	}
	if err := rows.Close(); err != nil {
		return err
	}
	for _, recipientID := range recipients {
		key := fmt.Sprintf("%s:%s:%d:%d", event, subjectType, subjectID, recipientID)
		if _, err := tx.ExecContext(ctx, `INSERT IGNORE INTO admin_notifications
			(dedupe_key,recipient_user_id,agent_id,type,severity,title,body,order_id,subject_type,subject_id,created_at,updated_at)
			VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			key, recipientID, agentID, event, severity, title, body, orderID, subjectType, subjectID); err != nil {
			return err
		}
	}
	return nil
}

// RecordPaymentProofUploaded mirrors AdminNotificationService::paymentProofUploaded:
// ONLY the ACTIVE Keuangan accounts of the order's own branch (FinanceRecipientResolver —
// no operator fan-out, no Super Admin fallback), deduped per proof file, verification and
// recipient (identical re-upload ⇒ same keys ⇒ no duplicates).
func RecordPaymentProofUploaded(ctx context.Context, tx eventExecutor, orderID, agentID, verificationID int64,
	konsumenName, orderNo, proofHash string) error {
	rows, err := tx.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
		WHERE r.slug='keuangan' AND u.agent_id=? AND u.status='active' ORDER BY u.id`, agentID)
	if err != nil {
		return err
	}
	recipients := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return err
		}
		recipients = append(recipients, id)
	}
	if err := rows.Close(); err != nil {
		return err
	}
	if konsumenName == "" {
		konsumenName = "pelanggan"
	}
	body := fmt.Sprintf("Konsumen %s mengunggah bukti pembayaran untuk pesanan %s. Menunggu verifikasi Keuangan.",
		konsumenName, orderNo)
	if len(body) > 500 {
		body = body[:500]
	}
	for _, recipientID := range recipients {
		key := fmt.Sprintf("payment.proof:%d:%s:%d", verificationID, proofHash, recipientID)
		if len(key) > 191 {
			key = key[:191]
		}
		if _, err := tx.ExecContext(ctx, `INSERT IGNORE INTO admin_notifications
			(dedupe_key,recipient_user_id,agent_id,type,severity,title,body,order_id,subject_type,subject_id,created_at,updated_at)
			VALUES (?,?,?,'payment.proof_uploaded','warning','Bukti Pembayaran Baru',?,?, 'bank_transfer_verification',?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			key, recipientID, agentID, body, orderID, verificationID); err != nil {
			return err
		}
	}
	return nil
}

func groupedRupiah(value int64) string {
	negative := value < 0
	if negative {
		value = -value
	}
	text := fmt.Sprintf("%d", value)
	var groups []string
	for len(text) > 3 {
		groups = append([]string{text[len(text)-3:]}, groups...)
		text = text[:len(text)-3]
	}
	groups = append([]string{text}, groups...)
	formatted := strings.Join(groups, ".")
	if negative {
		return "-" + formatted
	}
	return formatted
}
