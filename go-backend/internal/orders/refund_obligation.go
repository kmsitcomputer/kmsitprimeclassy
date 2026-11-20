package orders

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
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

type CreateRefundAttemptInput struct {
	AmountCents         int64
	Method              string
	ExternalReference   *string
	DestinationSnapshot any
	IdempotencyKey      string
}

func (s *Service) CreateRefundAttempt(ctx context.Context, obligationID, actorID int64, input CreateRefundAttemptInput) (int64, error) {
	var orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=?`, obligationID).Scan(&orderID); err != nil {
		return 0, err
	}
	var crmCustomer sql.NullInt64
	if err := s.Database.QueryRowContext(ctx, `SELECT crm_customer_id FROM orders WHERE id=?`, orderID).Scan(&crmCustomer); err != nil {
		return 0, err
	}
	if crmCustomer.Valid {
		var count int
		if err := s.Database.QueryRowContext(ctx, `SELECT COUNT(*) FROM refund_recipient_evidences WHERE order_id=?`, orderID).Scan(&count); err != nil {
			return 0, err
		}
		if count == 0 {
			return 0, &Problem{Status: 422, Message: s.Messages("crm.recipient_evidence_required"), Errors: map[string]any{"code": "refund_recipient_evidence_required"}}
		}
	}
	var attemptID int64
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var lockedOrder int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrder); err != nil {
			return err
		}
		var status string
		var amountRaw string
		if err := tx.QueryRowContext(ctx, `SELECT status,CAST(amount AS CHAR) FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).Scan(&status, &amountRaw); err != nil {
			return err
		}
		var existingID int64
		var existingMethod, existingAmount string
		err := tx.QueryRowContext(ctx, `SELECT id,method,CAST(amount AS CHAR) FROM refund_attempts WHERE obligation_id=? AND idempotency_key=? FOR UPDATE`, obligationID, input.IdempotencyKey).Scan(&existingID, &existingMethod, &existingAmount)
		if err == nil {
			if toCents(parseDecimalString(existingAmount)) != input.AmountCents || existingMethod != input.Method {
				return &Problem{Status: 409, Message: "Idempotency-Key sudah digunakan untuk attempt dengan nominal atau metode berbeda.", Errors: map[string]any{"code": "idempotency_key_conflict"}}
			}
			attemptID = existingID
			return nil
		}
		if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		if status == "cancelled" || status == "completed" {
			return &Problem{Status: 422, Message: "Obligation tidak dapat menerima attempt baru.", Errors: map[string]any{"code": "obligation_not_payable"}}
		}
		if status != "approved" && status != "partially_paid" {
			return &Problem{Status: 422, Message: "Obligation belum disetujui.", Errors: map[string]any{"code": "obligation_not_approved"}}
		}
		obligationCents := toCents(parseDecimalString(amountRaw))
		if obligationCents <= 0 {
			return &Problem{Status: 422, Message: "Obligation bernilai nol tidak dapat menerima attempt.", Errors: map[string]any{"code": "zero_amount_obligation_no_attempt"}}
		}
		var paidRaw, inFlightRaw sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount),0) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&paidRaw); err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount),0) FROM refund_attempts WHERE obligation_id=? AND status IN ('created','processing','unknown')`, obligationID).Scan(&inFlightRaw); err != nil {
			return err
		}
		remaining := obligationCents - toCents(parseDecimalString(paidRaw.String)) - toCents(parseDecimalString(inFlightRaw.String))
		if input.AmountCents > remaining {
			return &Problem{Status: 422, Message: "Nominal attempt melebihi sisa obligation.", Errors: map[string]any{"code": "attempt_exceeds_remaining", "remaining_cents": remaining}}
		}
		var attemptNo int
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(MAX(attempt_no),0)+1 FROM refund_attempts WHERE obligation_id=?`, obligationID).Scan(&attemptNo); err != nil {
			return err
		}
		var destination any
		if input.DestinationSnapshot != nil {
			raw, err := json.Marshal(input.DestinationSnapshot)
			if err != nil {
				return err
			}
			destination = string(raw)
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO refund_attempts(obligation_id,attempt_no,method,amount,status,idempotency_key,external_reference,destination_snapshot,processed_by,created_at,updated_at) VALUES (?,?,?,?,'created',?, ?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, obligationID, attemptNo, input.Method, money.String(float64(input.AmountCents)/100), input.IdempotencyKey, input.ExternalReference, destination, actorID)
		if err != nil {
			return err
		}
		attemptID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		payload, _ := json.Marshal(map[string]any{"attempt_no": attemptNo, "amount_cents": input.AmountCents, "method": input.Method})
		_, err = tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,attempt_id,event_type,event_key,actor_type,actor_id,payload,occurred_at,created_at,updated_at) VALUES (?,?,?,'attempt.created',?,'user',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, orderID, obligationID, attemptID, fmt.Sprintf("attempt_created:%d", attemptID), actorID, string(payload))
		return err
	})
	if err != nil && strings.Contains(strings.ToLower(err.Error()), "duplicate") {
		var existingID int64
		var existingObligation int64
		var existingAmount string
		var existingMethod string
		if lookupErr := s.Database.QueryRowContext(ctx, `SELECT id,obligation_id,CAST(amount AS CHAR),method FROM refund_attempts WHERE idempotency_key=?`, input.IdempotencyKey).Scan(&existingID, &existingObligation, &existingAmount, &existingMethod); lookupErr == nil {
			if existingObligation == obligationID && toCents(parseDecimalString(existingAmount)) == input.AmountCents && existingMethod == input.Method {
				return existingID, nil
			}
			return 0, &Problem{Status: 409, Message: "Idempotency-Key sudah digunakan untuk operasi refund lain.", Errors: map[string]any{"code": "idempotency_key_conflict"}}
		}
	}
	return attemptID, err
}

func (s *Service) InitiateRefundAttempt(ctx context.Context, attemptID, actorID int64, externalReference *string) error {
	var obligationID, orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT a.obligation_id,o.order_id FROM refund_attempts a JOIN refund_obligations o ON o.id=a.obligation_id WHERE a.id=?`, attemptID).Scan(&obligationID, &orderID); err != nil {
		return err
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var lockedOrder int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrder); err != nil {
			return err
		}
		var status string
		var existingReference sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT status,external_reference FROM refund_attempts WHERE id=? FOR UPDATE`, attemptID).Scan(&status, &existingReference); err != nil {
			return err
		}
		if status == "processing" {
			return nil
		}
		if status != "created" {
			return &Problem{Status: 422, Message: "Attempt tidak berada pada status created.", Errors: map[string]any{"code": "invalid_attempt_state"}}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='processing',initiated_at=UTC_TIMESTAMP(6),started_at=UTC_TIMESTAMP(6),external_reference=COALESCE(?,external_reference),processed_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, externalReference, actorID, attemptID); err != nil {
			return err
		}
		payload, err := json.Marshal(map[string]any{"external_reference": externalReference})
		if err != nil {
			return err
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,attempt_id,event_type,event_key,actor_type,actor_id,payload,occurred_at,created_at,updated_at) VALUES (?,?,?,'attempt.initiated',?,'user',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, orderID, obligationID, attemptID, fmt.Sprintf("attempt_initiated:%d", attemptID), actorID, string(payload))
		return err
	})
}
