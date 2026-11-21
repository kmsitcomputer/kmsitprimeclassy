package orders

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/whatsappnotify"
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
		// obligationUpdated: pending → approved announces refund_approved.
		if nerr := whatsappnotify.NotifyObligationByID(ctx, tx, whatsappnotify.EventRefundApproved, obligationID); nerr != nil {
			slog.WarnContext(ctx, "refund-approved dispatch failed", "obligation_id", obligationID, "error", nerr)
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

func (s *Service) CancelRefundAttempt(ctx context.Context, attemptID, actorID int64, reason string) error {
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
		if err := tx.QueryRowContext(ctx, `SELECT status FROM refund_attempts WHERE id=? FOR UPDATE`, attemptID).Scan(&status); err != nil {
			return err
		}
		if status != "created" {
			return &Problem{Status: 422, Message: "Hanya attempt pra-inisiasi yang dapat dibatalkan.", Errors: map[string]any{"code": "attempt_cancel_window_closed"}}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='cancelled',finished_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, attemptID); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,attempt_id,event_type,event_key,actor_type,actor_id,reason,occurred_at,created_at,updated_at) VALUES (?,?,?,'attempt.cancelled',?,'user',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, orderID, obligationID, attemptID, fmt.Sprintf("attempt_cancelled:%d", attemptID), actorID, reason)
		return err
	})
}

func (s *Service) MarkRefundAttemptUnknown(ctx context.Context, attemptID, actorID int64, note *string) error {
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
		if err := tx.QueryRowContext(ctx, `SELECT status FROM refund_attempts WHERE id=? FOR UPDATE`, attemptID).Scan(&status); err != nil {
			return err
		}
		if status == "unknown" {
			return nil
		}
		if status != "processing" {
			return &Problem{Status: 422, Message: "Attempt tidak berada pada status processing.", Errors: map[string]any{"code": "invalid_attempt_state"}}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='unknown',unknown_since=UTC_TIMESTAMP(6),failure_message=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, note, attemptID); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,attempt_id,event_type,event_key,actor_type,actor_id,reason,occurred_at,created_at,updated_at) VALUES (?,?,?,'attempt.unknown',?,'user',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, orderID, obligationID, attemptID, fmt.Sprintf("attempt_unknown:%d", attemptID), actorID, note)
		return err
	})
}

func (s *Service) MarkRefundAttemptFailed(ctx context.Context, attemptID, actorID int64, code, message string) error {
	var obligationID, orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT a.obligation_id,o.order_id FROM refund_attempts a JOIN refund_obligations o ON o.id=a.obligation_id WHERE a.id=?`, attemptID).Scan(&obligationID, &orderID); err != nil {
		return err
	}
	changed := false
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var lockedOrder int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrder); err != nil {
			return err
		}
		var status string
		if err := tx.QueryRowContext(ctx, `SELECT status FROM refund_attempts WHERE id=? FOR UPDATE`, attemptID).Scan(&status); err != nil {
			return err
		}
		if status == "failed" {
			return nil
		}
		if status != "processing" {
			return &Problem{Status: 422, Message: "Attempt tidak berada pada status processing.", Errors: map[string]any{"code": "invalid_attempt_state"}}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='failed',failure_code=?,failure_message=?,finished_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, code, message, attemptID); err != nil {
			return err
		}
		payload, _ := json.Marshal(map[string]any{"failure_code": code})
		if _, err := tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,attempt_id,event_type,event_key,actor_type,actor_id,payload,occurred_at,created_at,updated_at) VALUES (?,?,?,'attempt.failed',?,'user',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, orderID, obligationID, attemptID, fmt.Sprintf("attempt_failed:%d", attemptID), actorID, string(payload)); err != nil {
			return err
		}
		changed = true
		return nil
	})
	if err != nil {
		return err
	}
	if changed {
		var orderStatus, cancellationStatus string
		if err := s.Database.QueryRowContext(ctx, `SELECT status,cancellation_financial_status FROM orders WHERE id=?`, orderID).Scan(&orderStatus, &cancellationStatus); err != nil {
			return err
		}
		if orderStatus == "dibatalkan" && cancellationStatus == "pending_reconciliation" {
			return s.FinalizeCancellation(ctx, orderID, actorID)
		}
	}
	return nil
}

func (s *Service) completeRefundAttemptTx(ctx context.Context, tx *sql.Tx, orderID, obligationID, attemptID, actorID int64, proofMediaID *int64, proofType, gatewayReference *string, payoutID *int64, changed *bool) error {
	var lockedOrder int64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrder); err != nil {
		return err
	}
	var obligationAmount, attemptAmount, attemptStatus, method, idempotencyKey string
	var obligationStatus, orderStatus string
	var existingProof sql.NullInt64
	var existingProofType, existingGateway sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT status,CAST(amount AS CHAR) FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).Scan(&obligationStatus, &obligationAmount); err != nil {
		return err
	}
	if err := tx.QueryRowContext(ctx, `SELECT status,CAST(amount AS CHAR),method,idempotency_key,proof_media_id,proof_type,external_reference FROM refund_attempts WHERE id=? FOR UPDATE`, attemptID).Scan(&attemptStatus, &attemptAmount, &method, &idempotencyKey, &existingProof, &existingProofType, &existingGateway); err != nil {
		return err
	}
	var existing sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM refund_payouts WHERE attempt_id=?`, attemptID).Scan(&existing); err == nil && existing.Valid {
		*payoutID = existing.Int64
		return nil
	} else if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	if attemptStatus != "processing" {
		code := "invalid_attempt_state"
		if attemptStatus == "created" {
			code = "attempt_not_initiated"
		}
		return &Problem{Status: 422, Message: "Attempt belum diinisiasi; penyelesaian tidak diizinkan.", Errors: map[string]any{"code": code}}
	}
	var paidRaw, inFlightRaw sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount),0) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&paidRaw); err != nil {
		return err
	}
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount),0) FROM refund_attempts WHERE obligation_id=? AND id<>? AND status IN ('created','processing','unknown')`, obligationID, attemptID).Scan(&inFlightRaw); err != nil {
		return err
	}
	remaining := toCents(parseDecimalString(obligationAmount)) - toCents(parseDecimalString(paidRaw.String)) - toCents(parseDecimalString(inFlightRaw.String))
	amount := toCents(parseDecimalString(attemptAmount))
	if amount > remaining {
		return &Problem{Status: 422, Message: "Nominal payout melebihi sisa obligation setelah self-exclusion.", Errors: map[string]any{"code": "attempt_exceeds_remaining", "remaining_cents": remaining}}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='succeeded',finished_at=UTC_TIMESTAMP(6),processed_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='processing'`, actorID, attemptID); err != nil {
		return err
	}
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id=?`, orderID).Scan(&orderStatus); err != nil {
		return err
	}
	recordedOnCancelled := obligationStatus == "cancelled" || orderStatus == "dibatalkan"
	if proofMediaID == nil && existingProof.Valid {
		v := existingProof.Int64
		proofMediaID = &v
	}
	if proofType == nil && existingProofType.Valid {
		v := existingProofType.String
		proofType = &v
	}
	if gatewayReference == nil && existingGateway.Valid {
		v := existingGateway.String
		gatewayReference = &v
	}
	verification := "proven"
	payoutNo, err := ulid()
	if err != nil {
		return err
	}
	var proofID any
	if proofMediaID != nil {
		proofID = *proofMediaID
	}
	var proofTypeValue, gatewayValue any
	if proofType != nil {
		proofTypeValue = *proofType
	}
	if gatewayReference != nil {
		gatewayValue = *gatewayReference
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO refund_payouts(payout_no,obligation_id,order_id,attempt_id,payout_key,amount,method,paid_at,proof_media_id,proof_type,gateway_reference,verification_status,affects_paid_amount,paid_effect_applied_at,recorded_on_cancelled_obligation,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?,?,?,'proven',1,UTC_TIMESTAMP(6),?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, "RP-"+payoutNo, obligationID, orderID, attemptID, idempotencyKey, money.String(float64(amount)/100), method, proofID, proofTypeValue, gatewayValue, recordedOnCancelled, actorID)
	if err != nil {
		return err
	}
	*payoutID, err = res.LastInsertId()
	if err != nil {
		return err
	}
	// refundCreated proven → refund_completed (only money PROVEN to have left counts).
	if nerr := whatsappnotify.NotifyPayoutByID(ctx, tx, *payoutID); nerr != nil {
		slog.WarnContext(ctx, "refund-completed dispatch failed", "payout_id", *payoutID, "error", nerr)
	}
	var paidRawOrder, totalRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(paid_amount AS CHAR),CAST(total_amount AS CHAR) FROM orders WHERE id=?`, orderID).Scan(&paidRawOrder, &totalRaw); err != nil {
		return err
	}
	newPaid := max64(0, toCents(parseDecimalString(paidRawOrder))-amount)
	remainingOrder := max64(0, toCents(parseDecimalString(totalRaw))-newPaid)
	paymentStatus := "unpaid"
	if remainingOrder == 0 {
		paymentStatus = "paid"
	} else if newPaid > 0 {
		paymentStatus = "partially_paid"
	}
	if _, err := tx.ExecContext(ctx, `UPDATE orders SET paid_amount=?,remaining_amount=?,payment_status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, money.String(float64(newPaid)/100), money.String(float64(remainingOrder)/100), paymentStatus, orderID); err != nil {
		return err
	}
	if err := s.syncInvoiceAfterChange(ctx, tx, orderID, actorID, "refund_processed"); err != nil {
		return err
	}
	if err := s.refreshRefundObligation(ctx, tx, obligationID); err != nil {
		return err
	}
	if err := refundEvent(ctx, tx, orderID, obligationID, attemptID, *payoutID, "payout.recorded", "payout:"+fmt.Sprint(attemptID), actorID, nil, map[string]any{"amount_cents": amount, "verification_status": verification, "recorded_on_cancelled_obligation": recordedOnCancelled}); err != nil {
		return err
	}
	*changed = true
	return nil
}

func (s *Service) CompleteRefundAttempt(ctx context.Context, attemptID, actorID int64, proofMediaID *int64, proofType, gatewayReference *string) (int64, error) {
	var obligationID, orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT a.obligation_id,o.order_id FROM refund_attempts a JOIN refund_obligations o ON o.id=a.obligation_id WHERE a.id=?`, attemptID).Scan(&obligationID, &orderID); err != nil {
		return 0, err
	}
	var payoutID int64
	changed := false
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		return s.completeRefundAttemptTx(ctx, tx, orderID, obligationID, attemptID, actorID, proofMediaID, proofType, gatewayReference, &payoutID, &changed)
	})
	if err != nil {
		return 0, err
	}
	if changed {
		var status string
		if err := s.Database.QueryRowContext(ctx, `SELECT status FROM orders WHERE id=?`, orderID).Scan(&status); err != nil {
			return 0, err
		}
		if status == "dibatalkan" {
			if err := s.FinalizeCancellation(ctx, orderID, actorID); err != nil {
				return 0, err
			}
		}
	}
	return payoutID, nil
}

func (s *Service) ReconcileRefundAttempt(ctx context.Context, attemptID, actorID int64, succeeded bool, evidence string) (bool, error) {
	if strings.TrimSpace(evidence) == "" {
		return false, &Problem{Status: 422, Message: "Rekonsiliasi attempt unknown memerlukan bukti.", Errors: map[string]any{"code": "reconciliation_evidence_required"}}
	}
	var obligationID, orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT a.obligation_id,o.order_id FROM refund_attempts a JOIN refund_obligations o ON o.id=a.obligation_id WHERE a.id=?`, attemptID).Scan(&obligationID, &orderID); err != nil {
		return false, err
	}
	changed, payoutRecorded := false, false
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var lockedOrder int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrder); err != nil {
			return err
		}
		var lockedObligation int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM refund_obligations WHERE id=? AND order_id=? FOR UPDATE`, obligationID, orderID).Scan(&lockedObligation); err != nil {
			return err
		}
		var status string
		if err := tx.QueryRowContext(ctx, `SELECT status FROM refund_attempts WHERE id=? FOR UPDATE`, attemptID).Scan(&status); err != nil {
			return err
		}
		if status != "unknown" {
			return &Problem{Status: 422, Message: "Attempt bukan berstatus unknown.", Errors: map[string]any{"code": "invalid_attempt_state"}}
		}
		if succeeded {
			if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='processing',reconciliation_evidence=?,reconciled_by=?,reconciled_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, evidence, actorID, attemptID); err != nil {
				return err
			}
			var payoutID int64
			returnErr := s.completeRefundAttemptTx(ctx, tx, orderID, obligationID, attemptID, actorID, nil, nil, nil, &payoutID, &changed)
			if returnErr != nil {
				return returnErr
			}
			payoutRecorded = true
			return nil
		}
		if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='failed',failure_code='reconciled_failed',failure_message=?,reconciliation_evidence=?,reconciled_by=?,reconciled_at=UTC_TIMESTAMP(6),finished_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, "Reconciled as failed: "+evidence, evidence, actorID, attemptID); err != nil {
			return err
		}
		if err := refundEvent(ctx, tx, orderID, obligationID, attemptID, 0, "attempt.failed", "attempt_failed:"+fmt.Sprint(attemptID), actorID, &evidence, map[string]any{"reconciled": true}); err != nil {
			return err
		}
		changed = true
		return nil
	})
	if err != nil {
		return false, err
	}
	if changed {
		var status string
		if err := s.Database.QueryRowContext(ctx, `SELECT status FROM orders WHERE id=?`, orderID).Scan(&status); err != nil {
			return false, err
		}
		if status == "dibatalkan" {
			if err := s.FinalizeCancellation(ctx, orderID, actorID); err != nil {
				return false, err
			}
		}
	}
	return payoutRecorded, nil
}

func (s *Service) ResolveRefundNeedsVerification(ctx context.Context, obligationID, actorID int64, exit, evidence string, reason *string) error {
	if strings.TrimSpace(evidence) == "" {
		return &Problem{Status: 422, Message: "Resolusi needs_verification memerlukan bukti.", Errors: map[string]any{"code": "reconciliation_evidence_required"}}
	}
	if exit != "A" && exit != "B" {
		return &Problem{Status: 422, Message: "Exit needs_verification tidak valid.", Errors: map[string]any{"code": "invalid_needs_verification_exit"}}
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderID int64
		var status, provenance, source string
		if err := tx.QueryRowContext(ctx, `SELECT order_id,status,provenance,source_type FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).Scan(&orderID, &status, &provenance, &source); err != nil {
			return err
		}
		var agentID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&agentID); err != nil {
			return err
		}
		var role string
		var actorAgent sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT roles.slug,users.agent_id FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?`, actorID).Scan(&role, &actorAgent); err != nil {
			return err
		}
		if role != "super_admin" && (!actorAgent.Valid || !agentID.Valid || actorAgent.Int64 != agentID.Int64) {
			return &Problem{Status: 404, Message: s.Messages("system.unauthorized_action")}
		}
		if status != "needs_verification" {
			return nil
		}
		historical := provenance == "legacy" || provenance == "provisional_legacy" || source == "legacy" || source == "manual"
		if exit == "A" {
			if role != "super_admin" && role != "keuangan" {
				return &Problem{Status: 403, Message: "Hanya Keuangan yang dapat melakukan aksi ini.", Errors: map[string]any{"code": "refund_role_forbidden"}}
			}
			if historical && role != "super_admin" && role != "agen" {
				return &Problem{Status: 403, Message: "Aksi ini memerlukan persetujuan Owner/Agent.", Errors: map[string]any{"code": "refund_owner_approval_required"}}
			}
			_, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP(6),approval_source='manual',reason=COALESCE(?,reason),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, actorID, reason, obligationID)
			if err != nil {
				return err
			}
			// obligationUpdated: needs_verification → approved announces refund_approved.
			if nerr := whatsappnotify.NotifyObligationByID(ctx, tx, whatsappnotify.EventRefundApproved, obligationID); nerr != nil {
				slog.WarnContext(ctx, "refund-approved dispatch failed", "obligation_id", obligationID, "error", nerr)
			}
		} else {
			if role != "super_admin" && role != "agen" {
				return &Problem{Status: 403, Message: "Aksi ini memerlukan persetujuan Owner/Agent.", Errors: map[string]any{"code": "refund_owner_approval_required"}}
			}
			r := "needs_verification_exit_b"
			if reason != nil {
				r = *reason
			}
			if _, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET status='cancelled',cancelled_at=UTC_TIMESTAMP(6),cancel_reason=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, r, obligationID); err != nil {
				return err
			}
			// obligationUpdated: needs_verification → cancelled announces refund_rejected.
			if nerr := whatsappnotify.NotifyObligationByID(ctx, tx, whatsappnotify.EventRefundRejected, obligationID); nerr != nil {
				slog.WarnContext(ctx, "refund-rejected dispatch failed", "obligation_id", obligationID, "error", nerr)
			}
		}
		why := reason
		return refundEvent(ctx, tx, orderID, obligationID, 0, 0, "needs_verification.resolved", "nv_resolved:"+fmt.Sprint(obligationID), actorID, why, map[string]any{"exit": exit, "evidence": evidence, "historical": historical})
	})
}

func (s *Service) RequestDeferredMaterialization(ctx context.Context, obligationID, amountCents, actorID int64, clientKey string) error {
	if amountCents <= 0 {
		return &Problem{Status: 422, Message: "Nominal materialisasi harus lebih dari 0.", Errors: map[string]any{"code": "invalid_materialization_amount"}}
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderID int64
		if err := tx.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).Scan(&orderID); err != nil {
			return err
		}
		var lockedOrder int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrder); err != nil {
			return err
		}
		var role string
		if err := tx.QueryRowContext(ctx, `SELECT roles.slug FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?`, actorID).Scan(&role); err != nil {
			return err
		}
		if role != "super_admin" && role != "keuangan" {
			return &Problem{Status: 403, Message: "Hanya Keuangan yang dapat melakukan aksi ini.", Errors: map[string]any{"code": "refund_role_forbidden"}}
		}
		eventKey := "deferred_request:" + fmt.Sprint(obligationID) + ":" + clientKey
		var exists int
		if err := tx.QueryRowContext(ctx, `SELECT 1 FROM refund_ledger_events WHERE event_key=? LIMIT 1`, eventKey).Scan(&exists); err == nil {
			return nil
		} else if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		var deferredRaw string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(deferred_remaining AS CHAR) FROM refund_obligations WHERE id=?`, obligationID).Scan(&deferredRaw); err != nil {
			return err
		}
		return refundEvent(ctx, tx, orderID, obligationID, 0, 0, "deferred.materialization_requested", eventKey, actorID, nil, map[string]any{"amount_cents": amountCents, "deferred_remaining_cents": toCents(parseDecimalString(deferredRaw))})
	})
}

func (s *Service) MaterializeDeferred(ctx context.Context, originID, amountCents, actorID int64, clientKey string) (int64, error) {
	if amountCents <= 0 {
		return 0, &Problem{Status: 422, Message: "Nominal materialisasi harus lebih dari 0.", Errors: map[string]any{"code": "invalid_materialization_amount"}}
	}
	var childID int64
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderID int64
		var originStatus, source, provenance, deferredAmount, deferredRemaining string
		var snapshot sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT order_id,status,source_type,provenance,CAST(deferred_amount AS CHAR),CAST(deferred_remaining AS CHAR),snapshot_id FROM refund_obligations WHERE id=? FOR UPDATE`, originID).Scan(&orderID, &originStatus, &source, &provenance, &deferredAmount, &deferredRemaining, &snapshot); err != nil {
			return err
		}
		var agentID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&agentID); err != nil {
			return err
		}
		var role string
		var actorAgent sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT roles.slug,users.agent_id FROM users JOIN roles ON roles.id=users.role_id WHERE users.id=?`, actorID).Scan(&role, &actorAgent); err != nil {
			return err
		}
		if role != "super_admin" && (role != "agen" || !actorAgent.Valid || !agentID.Valid || actorAgent.Int64 != agentID.Int64) {
			return &Problem{Status: 403, Message: "Aksi ini memerlukan persetujuan Owner/Agent.", Errors: map[string]any{"code": "refund_owner_approval_required"}}
		}
		if source != "deferred" && toCents(parseDecimalString(deferredAmount)) <= 0 {
			return &Problem{Status: 422, Message: "Obligation ini tidak memiliki entitlement deferred.", Errors: map[string]any{"code": "not_a_deferred_origin"}}
		}
		childKey := "deferred:" + fmt.Sprint(originID) + ":" + clientKey
		var existingAmount string
		var existingParent sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT id,CAST(amount AS CHAR),parent_obligation_id FROM refund_obligations WHERE obligation_key=? FOR UPDATE`, childKey).Scan(&childID, &existingAmount, &existingParent); err == nil {
			if toCents(parseDecimalString(existingAmount)) != amountCents || !existingParent.Valid || existingParent.Int64 != originID {
				return &Problem{Status: 409, Message: "Idempotency key sudah digunakan dengan parameter berbeda.", Errors: map[string]any{"code": "idempotency_key_conflict"}}
			}
			eventKey := "deferred_replay:" + clientKey
			var eventExists int
			if e := tx.QueryRowContext(ctx, `SELECT 1 FROM refund_ledger_events WHERE event_key=? LIMIT 1`, eventKey).Scan(&eventExists); errors.Is(e, sql.ErrNoRows) {
				if e := refundEvent(ctx, tx, orderID, originID, 0, 0, "deferred.materialization_replayed", eventKey, actorID, nil, map[string]any{"child_obligation_id": childID}); e != nil {
					return e
				}
			} else if e != nil {
				return e
			}
			return nil
		} else if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		if originStatus == "cancelled" {
			return &Problem{Status: 422, Message: "Origin deferred sudah dibatalkan/superseded.", Errors: map[string]any{"code": "deferred_origin_not_materializable"}}
		}
		remainingDeferred := toCents(parseDecimalString(deferredRemaining))
		if remainingDeferred <= 0 {
			return &Problem{Status: 422, Message: "Entitlement deferred sudah habis.", Errors: map[string]any{"code": "deferred_exhausted"}}
		}
		metrics, err := s.metricsForOrder(ctx, tx, orderID)
		if err != nil {
			return err
		}
		cap := min64(remainingDeferred, metrics.AvailableCents)
		if amountCents > cap {
			return &Problem{Status: 422, Message: "Nominal materialisasi melebihi batas deferred/kas tersedia.", Errors: map[string]any{"code": "deferred_materialization_exceeds_cap", "cap_cents": cap}}
		}
		no, err := ulid()
		if err != nil {
			return err
		}
		var snapshotValue any
		if snapshot.Valid {
			snapshotValue = snapshot.Int64
		}
		metadata, _ := json.Marshal(map[string]any{"client_idempotency_key": clientKey})
		res, err := tx.ExecContext(ctx, `INSERT INTO refund_obligations(obligation_no,order_id,source_type,source_id,obligation_key,amount,status,provenance,reason,approved_by,approved_at,approval_source,snapshot_id,deferred_amount,deferred_remaining,parent_obligation_id,created_by,metadata,created_at,updated_at) VALUES (?,?,'deferred',?,?,?,'approved',?,'Deferred materialization',?,UTC_TIMESTAMP(6),'owner_manual_correction',?,0,0,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, "RO-"+no, orderID, originID, childKey, money.String(float64(amountCents)/100), provenance, actorID, snapshotValue, originID, actorID, string(metadata))
		if err != nil {
			return err
		}
		childID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		res, err = tx.ExecContext(ctx, `UPDATE refund_obligations SET deferred_remaining=deferred_remaining-?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND deferred_remaining>=?`, money.String(float64(amountCents)/100), originID, money.String(float64(amountCents)/100))
		if err != nil {
			return err
		}
		affected, err := res.RowsAffected()
		if err != nil {
			return err
		}
		if affected != 1 {
			return &Problem{Status: 409, Message: "Konflik materialisasi deferred; ulangi.", Errors: map[string]any{"code": "deferred_materialization_conflict"}}
		}
		if err := refundEvent(ctx, tx, orderID, childID, 0, 0, "deferred.materialized", "deferred_materialized:"+clientKey, actorID, nil, map[string]any{"origin_obligation_id": originID, "amount_cents": amountCents}); err != nil {
			return err
		}
		return s.refreshRefundObligation(ctx, tx, originID)
	})
	return childID, err
}
