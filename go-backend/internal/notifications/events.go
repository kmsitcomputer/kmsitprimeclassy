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
	body := "Pesanan " + orderNo + " · Total Rp " + groupedRupiah(int64(math.Round(total)))
	for _, recipientID := range recipients {
		key := fmt.Sprintf("order.created:order:%d:%d", orderID, recipientID)
		if _, err := tx.ExecContext(ctx, `INSERT IGNORE INTO admin_notifications
			(dedupe_key,recipient_user_id,agent_id,type,severity,title,body,order_id,subject_type,subject_id,created_at,updated_at)
			VALUES (?,?,?,'order.created','info','Pesanan baru',?,?, 'order',?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			key, recipientID, agentID, body, orderID, orderID); err != nil {
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
