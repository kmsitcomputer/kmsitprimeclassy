package whatsappnotify

import (
	"context"
	"database/sql"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
)

// ledger.go ports WhatsAppDelivery::enqueue/skip: idempotent whatsapp_messages rows
// plus their SendWhatsAppMessageJob queue payload, all inside the caller's
// transaction (a rollback strands nothing — strictly safer than after-commit dispatch).

type executor interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
	QueryRowContext(context.Context, string, ...any) *sql.Row
	ExecContext(context.Context, string, ...any) (sql.Result, error)
}

// LedgerAttrs describes one whatsapp_messages row (the enqueue-compatible subset).
type LedgerAttrs struct {
	IdempotencyKey  string
	Event           string
	AgentID         *int64
	OrderID         *int64
	SubjectType     string
	SubjectID       int64
	RecipientRole   string
	RecipientUserID *int64
	RecipientPhone  string
	TemplateClass   string
	Body            string
	CreatedBy       *int64
}

// EnqueueTx idempotently creates a QUEUED message and its delivery job. A duplicate
// idempotency key leaves the existing row untouched (returns inserted=false).
func EnqueueTx(ctx context.Context, tx executor, attrs LedgerAttrs) (bool, error) {
	var agentID, orderID, userID, createdBy any
	if attrs.AgentID != nil {
		agentID = *attrs.AgentID
	}
	if attrs.OrderID != nil {
		orderID = *attrs.OrderID
	}
	if attrs.RecipientUserID != nil {
		userID = *attrs.RecipientUserID
	}
	if attrs.CreatedBy != nil {
		createdBy = *attrs.CreatedBy
	}
	maxAttempts := MaxAttempts(ctx, tx)
	res, err := tx.ExecContext(ctx, `INSERT IGNORE INTO whatsapp_messages
		(idempotency_key, purpose, event, template_class, recipient_role, agent_id, order_id,
		 subject_type, subject_id, recipient_user_id, recipient_phone, body, status, max_attempts,
		 created_by, queued_at, created_at, updated_at)
		VALUES (?, 'transaction', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?, UTC_TIMESTAMP(6),
			UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
		attrs.IdempotencyKey, attrs.Event, attrs.TemplateClass, attrs.RecipientRole, agentID, orderID,
		attrs.SubjectType, attrs.SubjectID, userID, attrs.RecipientPhone, attrs.Body, maxAttempts, createdBy)
	if err != nil {
		return false, err
	}
	affected, err := res.RowsAffected()
	if err != nil {
		return false, err
	}
	if affected == 0 {
		return false, nil // existing idempotency row — Laravel returns it without dispatching again
	}
	messageID, err := res.LastInsertId()
	if err != nil {
		return false, err
	}
	if tx, ok := tx.(*sql.Tx); ok {
		if err := jobs.EnqueueWhatsAppTx(ctx, tx, messageID, time.Now()); err != nil {
			return false, err
		}
	}
	return true, nil
}

// SkipTx records a visible skip (firstOrCreate semantics on the idempotency key):
// the decision that a recipient/channel was unreachable is itself durable evidence.
func SkipTx(ctx context.Context, tx executor, attrs LedgerAttrs, reason string) error {
	var agentID, orderID, userID, createdBy any
	if attrs.AgentID != nil {
		agentID = *attrs.AgentID
	}
	if attrs.OrderID != nil {
		orderID = *attrs.OrderID
	}
	if attrs.RecipientUserID != nil {
		userID = *attrs.RecipientUserID
	}
	if attrs.CreatedBy != nil {
		createdBy = *attrs.CreatedBy
	}
	_, err := tx.ExecContext(ctx, `INSERT IGNORE INTO whatsapp_messages
		(idempotency_key, purpose, event, template_class, recipient_role, agent_id, order_id,
		 subject_type, subject_id, recipient_user_id, recipient_phone, body, status, skip_reason,
		 created_by, queued_at, created_at, updated_at)
		VALUES (?, 'transaction', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'skipped', ?, ?, UTC_TIMESTAMP(6),
			UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
		attrs.IdempotencyKey, attrs.Event, attrs.TemplateClass, attrs.RecipientRole, agentID, orderID,
		attrs.SubjectType, attrs.SubjectID, userID, attrs.RecipientPhone, reason, createdBy)
	return err
}
