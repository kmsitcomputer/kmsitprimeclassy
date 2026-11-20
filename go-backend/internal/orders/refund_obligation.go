package orders

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
)

func (s *Service) ApproveRefundObligation(ctx context.Context, obligationID, actorID int64) error {
	var orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=?`, obligationID).Scan(&orderID); err != nil {
		return err
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var agentID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&agentID); err != nil {
			return err
		}
		var role string
		var actorAgentID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT roles.slug,users.agent_id FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?`, actorID).Scan(&role, &actorAgentID); err != nil {
			return err
		}
		if role != "super_admin" && (!actorAgentID.Valid || !agentID.Valid || actorAgentID.Int64 != agentID.Int64) {
			return &Problem{Status: 404, Message: s.Messages("system.unauthorized_action")}
		}
		if role != "super_admin" && role != "agen" {
			return &Problem{Status: 403, Message: "Aksi ini memerlukan persetujuan Owner/Agent.", Errors: map[string]any{"code": "refund_owner_approval_required"}}
		}
		var status string
		if err := tx.QueryRowContext(ctx, `SELECT status FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).Scan(&status); err != nil {
			return err
		}
		if status == "approved" {
			return nil
		}
		if status != "pending" {
			return &Problem{Status: 422, Message: "Obligation tidak berada pada status yang dapat disetujui.", Errors: map[string]any{"code": "obligation_not_approvable"}}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP(6),approval_source='owner_manual_correction',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, actorID, obligationID); err != nil {
			return err
		}
		payload, err := json.Marshal(map[string]any{"approval_source": "owner_manual_correction"})
		if err != nil {
			return err
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,event_type,event_key,actor_type,actor_id,payload,occurred_at,created_at,updated_at) VALUES (?,?,'obligation.approved',?,'user',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, orderID, obligationID, fmt.Sprintf("obligation_approved:%d", obligationID), actorID, string(payload))
		return err
	})
}
