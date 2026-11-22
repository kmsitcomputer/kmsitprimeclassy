package whatsappnotify

import (
	"context"
	"database/sql"
	"fmt"
	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
)

// EmailRecipient is one distinct verified address (role precedence admin > creator).
type EmailRecipient struct {
	Role  string
	ID    int64
	Email string
}

// emailRecipients ports TransactionalEmailNotifier::recipients.
func emailRecipients(ctx context.Context, tx executor, o *orderRow) []EmailRecipient {
	type candidate struct {
		role string
		id   int64
	}
	var candidates []candidate
	if o.AgentID.Valid {
		rows, err := tx.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
			WHERE r.slug = 'admin' AND u.agent_id = ? AND u.deleted_at IS NULL ORDER BY u.id`, o.AgentID.Int64)
		if err == nil {
			for rows.Next() {
				var id int64
				if rows.Scan(&id) == nil {
					candidates = append(candidates, candidate{"admin", id})
				}
			}
			rows.Close()
		}
	}
	if o.KonsumenID.Valid {
		candidates = append(candidates, candidate{"creator", o.KonsumenID.Int64})
	}
	if o.PlacedByUserID.Valid && (!o.KonsumenID.Valid || o.PlacedByUserID.Int64 != o.KonsumenID.Int64) {
		candidates = append(candidates, candidate{"creator", o.PlacedByUserID.Int64})
	}
	byEmail := map[string]EmailRecipient{}
	var order []string
	for _, c := range candidates {
		email := verifiedEmail(ctx, tx, c.id)
		if email == "" {
			continue
		}
		if _, ok := byEmail[email]; !ok {
			order = append(order, email)
		}
		if prev, ok := byEmail[email]; !ok || rolePrecedence[c.role] > rolePrecedence[prev.Role] {
			byEmail[email] = EmailRecipient{Role: c.role, ID: c.id, Email: email}
		}
	}
	out := make([]EmailRecipient, 0, len(order))
	for _, e := range order {
		out = append(out, byEmail[e])
	}
	return out
}

// emailRow is one email_messages ledger row to create.
type emailRow struct {
	IdempotencyKey string
	Event          string
	AgentID        *int64
	OrderID        *int64
	SubjectType    string
	SubjectID      int64
	Role           string
	UserID         int64
	Email          string
	Subject        string
	Body           string
	Skipped        bool
	SkipReason     string
}

func insertEmailRow(ctx context.Context, tx executor, row emailRow) error {
	var agentID, orderID any
	if row.AgentID != nil {
		agentID = *row.AgentID
	}
	if row.OrderID != nil {
		orderID = *row.OrderID
	}
	status := "queued"
	var skipReason any
	if row.Skipped {
		status = "skipped"
		skipReason = row.SkipReason
	}
	res, err := tx.ExecContext(ctx, `INSERT IGNORE INTO email_messages
		(idempotency_key, event, agent_id, order_id, subject_type, subject_id, recipient_role,
		 recipient_user_id, recipient_email, subject, body, status, skip_reason, created_at, updated_at)
		VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		row.IdempotencyKey, row.Event, agentID, orderID, row.SubjectType, row.SubjectID, row.Role,
		row.UserID, row.Email, row.Subject, row.Body, status, skipReason)
	if err != nil {
		return err
	}
	affected, err := res.RowsAffected()
	if err != nil {
		return err
	}
	if affected == 0 || row.Skipped {
		return nil
	}
	id, err := res.LastInsertId()
	if err != nil {
		return err
	}
	if tx, ok := tx.(*sql.Tx); ok {
		return jobs.EnqueueEmailTx(ctx, tx, id)
	}
	return nil
}

func mailKey(event, subjectType string, subjectID, userID int64, proofHash string) string {
	if proofHash != "" {
		return fmt.Sprintf("mail:%s:%s:%d:%s:%d", event, subjectType, subjectID, proofHash, userID)
	}
	return fmt.Sprintf("mail:%s:%s:%d:%d", event, subjectType, subjectID, userID)
}

// NotifyEmailOrderEvent ports TransactionalEmailNotifier::dispatch (the generic lane).
// Skipped entirely for CRM-origin orders (Laravel behaviour). The email body always
// renders the DEFAULT template (never a per-agent WhatsApp override).
func NotifyEmailOrderEvent(ctx context.Context, tx executor, event, subjectType string, subjectID, orderID int64, extra Extra) error {
	if _, known := emailSubjects[event]; !known || !smtpTransactionalEnabled(ctx, tx) {
		return nil
	}
	o, err := LoadOrder(ctx, tx, orderID)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil
		}
		return err
	}
	if o.CRMOrigin || !o.KonsumenID.Valid {
		return nil
	}
	vars := OrderVars(ctx, tx, o)
	for k, v := range extra {
		vars[k] = v
	}
	body, ok := Render(event, DefaultTemplate(event), vars)
	if !ok {
		return nil
	}
	body = plainBody(body)
	subject := subjectFor(event, vars)
	for _, r := range emailRecipients(ctx, tx, o) {
		if err := insertEmailRow(ctx, tx, emailRow{
			IdempotencyKey: mailKey(event, subjectType, subjectID, r.ID, ""),
			Event:          event, AgentID: nilInt64(o.AgentID), OrderID: &o.ID,
			SubjectType: subjectType, SubjectID: subjectID, Role: r.Role, UserID: r.ID,
			Email: r.Email, Subject: subject, Body: body,
		}); err != nil {
			return err
		}
	}
	return nil
}
