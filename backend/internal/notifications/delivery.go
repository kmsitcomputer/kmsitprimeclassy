package notifications

import (
	"context"
	"database/sql"
	"fmt"
)

// RecordDeliveryFailed mirrors AdminNotificationService::deliveryFailed. Failure
// notices go to the owning branch's Agen/Admin operators and all Super Admins.
func RecordDeliveryFailed(ctx context.Context, tx *sql.Tx, channel, event string, agentID, orderID *int64,
	subjectType string, subjectID int64, status string, eventLabel string) error {
	label := eventLabel
	if label == "" {
		label = event
	}
	orderNo := ""
	if orderID != nil {
		_ = tx.QueryRowContext(ctx, `SELECT order_no FROM orders WHERE id=?`, *orderID).Scan(&orderNo)
	}
	channelLabel := "WhatsApp"
	if channel == "email" {
		channelLabel = "Email"
	}
	body := fmt.Sprintf("Kanal %s · %s · status %s", channelLabel, label, status)
	if orderNo != "" {
		body += " · Pesanan " + orderNo
	}
	severity := "critical"
	if status == "unknown" {
		severity = "warning"
	}
	rows, err := tx.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
		WHERE r.slug='super_admin' OR (? IS NOT NULL AND ((u.id=? AND r.slug='agen') OR (u.agent_id=? AND r.slug='admin')))
		ORDER BY u.id`, agentID, agentID, agentID)
	if err != nil {
		return err
	}
	var recipients []int64
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
	for _, recipientID := range recipients {
		key := fmt.Sprintf("delivery.failed:%s:%d:%d", subjectType, subjectID, recipientID)
		if _, err := tx.ExecContext(ctx, `INSERT IGNORE INTO admin_notifications
			(dedupe_key,recipient_user_id,agent_id,type,severity,title,body,order_id,subject_type,subject_id,created_at,updated_at)
			VALUES (?,?,?,'delivery.failed',?,'Pengiriman notifikasi membutuhkan perhatian',?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			key, recipientID, agentID, severity, body, orderID, subjectType, subjectID); err != nil {
			return err
		}
	}
	return nil
}
