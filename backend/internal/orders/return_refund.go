package orders

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// MarkReturnItemRefunded mirrors ReturnService::markItemRefunded. The old endpoint is a
// confirmation of an already-sent manual refund; it records a legacy_unverified payout through
// the append-only ledger instead of treating the status flip itself as money movement.
func (s *Service) MarkReturnItemRefunded(ctx context.Context, itemID, actorID int64, actorRole string) error {
	var returnID, orderItemID int64
	var status, refundStatus string
	if err := s.Database.QueryRowContext(ctx, `SELECT ri.return_id,ri.order_item_id,ri.status,ri.refund_status
		FROM return_items ri WHERE ri.id=?`, itemID).Scan(&returnID, &orderItemID, &status, &refundStatus); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return &Problem{Status: 404, Message: s.Messages("system.endpoint_not_found")}
		}
		return err
	}
	if status != "approved" || refundStatus == "processed" {
		return &Problem{Status: 422, Message: s.Messages("return.already_reviewed")}
	}
	var orderID int64
	var crmCustomerID sql.NullInt64
	if err := s.Database.QueryRowContext(ctx, `SELECT r.order_id,o.crm_customer_id FROM returns r JOIN order_items oi ON oi.order_id=r.order_id
		JOIN orders o ON o.id=r.order_id WHERE r.id=? AND oi.id=?`, returnID, orderItemID).Scan(&orderID, &crmCustomerID); err != nil {
		return err
	}
	if crmCustomerID.Valid {
		var evidence bool
		if err := s.Database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM refund_recipient_evidences WHERE order_id=?)`, orderID).Scan(&evidence); err != nil {
			return err
		}
		if !evidence {
			return &Problem{Status: 422, Message: s.Messages("crm.recipient_evidence_required"),
				Errors: map[string]any{"code": "refund_recipient_evidence_required"}}
		}
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var lockedReturnID, lockedOrderItemID int64
		var lockedRefundStatus string
		if err := tx.QueryRowContext(ctx, `SELECT return_id,order_item_id,refund_status FROM return_items WHERE id=? FOR UPDATE`, itemID).
			Scan(&lockedReturnID, &lockedOrderItemID, &lockedRefundStatus); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &Problem{Status: 404, Message: s.Messages("system.endpoint_not_found")}
			}
			return err
		}
		if lockedRefundStatus == "processed" {
			return &Problem{Status: 422, Message: s.Messages("return.already_reviewed")}
		}
		var total, paid, remaining, paymentStatus, orderStatus, crmID string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(remaining_amount AS CHAR),
			payment_status,status,COALESCE(CAST(crm_customer_id AS CHAR),'') FROM orders WHERE id=? FOR UPDATE`, orderID).
			Scan(&total, &paid, &remaining, &paymentStatus, &orderStatus, &crmID); err != nil {
			return err
		}
		if paymentStatus != "paid" || parseDecimalString(remaining) > 0 {
			return &Problem{Status: 422, Message: s.Messages("payment.not_fully_paid")}
		}

		var obligationID int64
		var obligationStatus, obligationKey, obligationAmount, deferredRemaining string
		err := tx.QueryRowContext(ctx, `SELECT id,status,obligation_key,CAST(amount AS CHAR),CAST(deferred_remaining AS CHAR)
			FROM refund_obligations WHERE order_id=? AND source_type='return_stage' AND source_id=?
			AND status IN ('pending','approved','partially_paid','needs_verification') ORDER BY id DESC LIMIT 1 FOR UPDATE`,
			orderID, itemID).Scan(&obligationID, &obligationStatus, &obligationKey, &obligationAmount, &deferredRemaining)
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		if err == nil {
			if obligationStatus == "needs_verification" {
				return &Problem{Status: 422, Message: "Obligation menunggu verifikasi; selesaikan verifikasi terlebih dahulu.",
					Errors: map[string]any{"code": "obligation_needs_verification"}}
			}
			if obligationStatus != "approved" && obligationStatus != "partially_paid" {
				return &Problem{Status: 422, Message: "Obligation belum disetujui.", Errors: map[string]any{"code": "obligation_not_approved"}}
			}
			var paidPayouts string
			if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&paidPayouts); err != nil {
				return err
			}
			attemptAmount := toCents(parseDecimalString(obligationAmount) - parseDecimalString(paidPayouts))
			if attemptAmount > 0 {
				if err := s.settleLegacyReturn(ctx, tx, orderID, obligationID, obligationKey, obligationStatus,
					attemptAmount, actorID, orderStatus); err != nil {
					return err
				}
			}
		}

		if _, err := tx.ExecContext(ctx, `UPDATE return_items SET refund_status='processed',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, itemID); err != nil {
			return err
		}
		var itemStatus string
		if err := tx.QueryRowContext(ctx, `SELECT status FROM order_items WHERE id=? FOR UPDATE`, lockedOrderItemID).
			Scan(&itemStatus); err != nil {
			if !errors.Is(err, sql.ErrNoRows) {
				return err
			}
		} else if itemStatus == "pengembalian" {
			if _, err := tx.ExecContext(ctx, `UPDATE order_items SET status='kembali',refund_quantity=refund_quantity+(SELECT quantity_returned FROM return_items WHERE id=?),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, itemID, lockedOrderItemID); err != nil {
				return err
			}
		}
		var pending int64
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM return_items WHERE return_id=? AND status='approved' AND refund_status<>'processed'`, lockedReturnID).Scan(&pending); err != nil {
			return err
		}
		if pending == 0 {
			if _, err := tx.ExecContext(ctx, `UPDATE returns SET status='completed',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, lockedReturnID); err != nil {
				return err
			}
		}
		if err := s.adjustReturnSalesFees(ctx, tx, itemID, lockedReturnID, lockedOrderItemID, actorID); err != nil {
			return err
		}
		properties, err := json.Marshal(map[string]any{"actor_role": actorRole})
		if err != nil {
			return err
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?,'App\\Models\\ReturnItem',?,'return_item.refund_marked',NULL,?,NULLIF(?,''),NULLIF(?,''),UTC_TIMESTAMP(6))`,
			actorID, itemID, string(properties), s.IP, s.UserAgent)
		return err
	})
}

func (s *Service) settleLegacyReturn(ctx context.Context, tx *sql.Tx, orderID, obligationID int64, key, status string,
	amountCents, actorID int64, orderStatus string) error {
	if amountCents <= 0 {
		return nil
	}
	if status != "approved" && status != "partially_paid" {
		return &Problem{Status: 422, Message: "Obligation belum disetujui.", Errors: map[string]any{"code": "obligation_not_approved"}}
	}
	idempotencyKey := "legacy-settle:" + key
	amount := amountOf(amountCents)
	var attemptID int64
	var attemptStatus, attemptAmount, method string
	err := tx.QueryRowContext(ctx, `SELECT id,status,CAST(amount AS CHAR),method FROM refund_attempts WHERE obligation_id=? AND idempotency_key=? FOR UPDATE`,
		obligationID, idempotencyKey).Scan(&attemptID, &attemptStatus, &attemptAmount, &method)
	if errors.Is(err, sql.ErrNoRows) {
		var foreignID int64
		foreignErr := tx.QueryRowContext(ctx, `SELECT id FROM refund_attempts WHERE idempotency_key=? FOR UPDATE`, idempotencyKey).Scan(&foreignID)
		if foreignErr == nil {
			return &Problem{Status: 409, Message: "Idempotency-Key sudah digunakan untuk operasi refund lain.", Errors: map[string]any{"code": "idempotency_key_conflict"}}
		}
		if !errors.Is(foreignErr, sql.ErrNoRows) {
			return foreignErr
		}
		var payoutTotal, inFlightTotal, obligationTotal string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&payoutTotal); err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_attempts WHERE obligation_id=? AND status IN ('created','processing','unknown')`, obligationID).Scan(&inFlightTotal); err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT CAST(amount AS CHAR) FROM refund_obligations WHERE id=?`, obligationID).Scan(&obligationTotal); err != nil {
			return err
		}
		available := toCents(parseDecimalString(obligationTotal) - parseDecimalString(payoutTotal) - parseDecimalString(inFlightTotal))
		if amountCents > available {
			return &Problem{Status: 422, Message: "Nominal attempt melebihi sisa obligation.", Errors: map[string]any{"code": "attempt_exceeds_remaining", "remaining_cents": available}}
		}
		var count int64
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(MAX(attempt_no),0) FROM refund_attempts WHERE obligation_id=?`, obligationID).Scan(&count); err != nil {
			return err
		}
		attemptNo := count + 1
		res, err := tx.ExecContext(ctx, `INSERT INTO refund_attempts
			(obligation_id,attempt_no,method,amount,status,idempotency_key,processed_by,created_at,updated_at)
			VALUES (?,?,'cash',?,'created',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, obligationID, attemptNo, amount, idempotencyKey, actorID)
		if err != nil {
			return err
		}
		attemptID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		attemptStatus, attemptAmount, method = "created", amount, "cash"
		if err := refundEvent(ctx, tx, orderID, obligationID, attemptID, 0, "attempt.created", fmt.Sprintf("attempt_created:%d", attemptID), actorID, nil,
			map[string]any{"attempt_no": attemptNo, "amount_cents": amountCents, "method": "cash"}); err != nil {
			return err
		}
	} else if err != nil {
		return err
	}
	if method != "cash" || toCents(parseDecimalString(attemptAmount)) != amountCents {
		return &Problem{Status: 409, Message: "Idempotency-Key sudah digunakan untuk attempt dengan nominal atau metode berbeda.", Errors: map[string]any{"code": "idempotency_key_conflict"}}
	}
	if attemptStatus == "created" {
		if _, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='processing',initiated_at=UTC_TIMESTAMP(6),started_at=UTC_TIMESTAMP(6),processed_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, actorID, attemptID); err != nil {
			return err
		}
		if err := refundEvent(ctx, tx, orderID, obligationID, attemptID, 0, "attempt.initiated", fmt.Sprintf("attempt_initiated:%d", attemptID), actorID, nil,
			map[string]any{"external_reference": nil}); err != nil {
			return err
		}
	} else if attemptStatus != "processing" {
		return &Problem{Status: 422, Message: "Attempt tidak berada pada status created.", Errors: map[string]any{"code": "invalid_attempt_state"}}
	}
	var existingPayout int64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM refund_payouts WHERE attempt_id=?`, attemptID).Scan(&existingPayout); err == nil {
		return nil
	} else if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	var amountO, paid, inflight string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(amount AS CHAR) FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).Scan(&amountO); err != nil {
		return err
	}
	if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&paid); err != nil {
		return err
	}
	if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_attempts WHERE obligation_id=? AND status IN ('created','processing','unknown') AND id<>?`, obligationID, attemptID).Scan(&inflight); err != nil {
		return err
	}
	remainingCents := toCents(parseDecimalString(amountO) - parseDecimalString(paid) - parseDecimalString(inflight))
	if amountCents > remainingCents {
		return &Problem{Status: 422, Message: "Nominal payout melebihi sisa obligation setelah self-exclusion.", Errors: map[string]any{"code": "attempt_exceeds_remaining", "remaining_cents": remainingCents}}
	}
	payoutNo, err := ulid()
	if err != nil {
		return err
	}
	payoutKey := idempotencyKey
	var recordedOnCancelled = orderStatus == "dibatalkan"
	var cancelled bool
	if err := tx.QueryRowContext(ctx, `SELECT status='cancelled' FROM refund_obligations WHERE id=?`, obligationID).Scan(&cancelled); err != nil {
		return err
	}
	recordedOnCancelled = recordedOnCancelled || cancelled
	res, err := tx.ExecContext(ctx, `UPDATE refund_attempts SET status='succeeded',finished_at=UTC_TIMESTAMP(6),processed_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='processing'`, actorID, attemptID)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n != 1 {
		return &Problem{Status: 409, Message: "Attempt sudah diselesaikan oleh proses lain.", Errors: map[string]any{"code": "attempt_already_settled"}}
	}
	var payoutID int64
	res, err = tx.ExecContext(ctx, `INSERT INTO refund_payouts
		(payout_no,obligation_id,order_id,attempt_id,payout_key,amount,method,paid_at,proof_type,verification_status,affects_paid_amount,
		 paid_effect_applied_at,recorded_on_cancelled_obligation,created_by,created_at,updated_at)
		VALUES (?,?,?,?,?,?,'cash',UTC_TIMESTAMP(6),'legacy_manual_confirmation','legacy_unverified',1,UTC_TIMESTAMP(6),?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		"RP-"+payoutNo, obligationID, orderID, attemptID, payoutKey, amount, recordedOnCancelled, actorID)
	if err != nil {
		return err
	}
	payoutID, err = res.LastInsertId()
	if err != nil {
		return err
	}
	var total, paidAmount, gross string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(gross_received_amount AS CHAR) FROM orders WHERE id=? FOR UPDATE`, orderID).
		Scan(&total, &paidAmount, &gross); err != nil {
		return err
	}
	newPaid := math.Max(0, parseDecimalString(paidAmount)-float64(amountCents)/100)
	newRemaining := math.Max(0, parseDecimalString(total)-newPaid)
	paymentStatus := "unpaid"
	if newRemaining <= 0 {
		paymentStatus = "paid"
	} else if newPaid > 0 {
		paymentStatus = "partially_paid"
	}
	if _, err := tx.ExecContext(ctx, `UPDATE orders SET paid_amount=?,remaining_amount=?,payment_status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`,
		money.String(newPaid), money.String(newRemaining), paymentStatus, orderID); err != nil {
		return err
	}
	if err := s.syncInvoiceAfterChange(ctx, tx, orderID, actorID, "refund_processed"); err != nil {
		return err
	}
	if err := s.refreshRefundObligation(ctx, tx, obligationID); err != nil {
		return err
	}
	return refundEvent(ctx, tx, orderID, obligationID, attemptID, payoutID, "payout.recorded", "payout:"+fmt.Sprint(attemptID), actorID, nil,
		map[string]any{"amount_cents": amountCents, "verification_status": "legacy_unverified", "recorded_on_cancelled_obligation": recordedOnCancelled})
}

func refundEvent(ctx context.Context, tx *sql.Tx, orderID, obligationID, attemptID, payoutID int64, eventType, eventKey string,
	actorID int64, reason any, payload map[string]any) error {
	raw, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO refund_ledger_events
		(order_id,obligation_id,attempt_id,payout_id,event_type,event_key,actor_type,actor_id,reason,payload,occurred_at,created_at,updated_at)
		VALUES (?,?,?,?,?,?,'user',?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, orderID, nullableID(obligationID), nullableID(attemptID),
		nullableID(payoutID), eventType, eventKey, actorID, reason, string(raw))
	return err
}

func nullableID(id int64) any {
	if id == 0 {
		return nil
	}
	return id
}

func (s *Service) refreshRefundObligation(ctx context.Context, tx *sql.Tx, obligationID int64) error {
	var status, amountRaw, deferredRaw, paidRaw string
	if err := tx.QueryRowContext(ctx, `SELECT status,CAST(amount AS CHAR),CAST(deferred_remaining AS CHAR) FROM refund_obligations WHERE id=? FOR UPDATE`, obligationID).
		Scan(&status, &amountRaw, &deferredRaw); err != nil {
		return err
	}
	if status == "cancelled" {
		return nil
	}
	if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&paidRaw); err != nil {
		return err
	}
	amount := toCents(parseDecimalString(amountRaw))
	paid := toCents(parseDecimalString(paidRaw))
	deferred := toCents(parseDecimalString(deferredRaw))
	newStatus := status
	completed := false
	if amount == 0 {
		if deferred == 0 {
			newStatus, completed = "completed", true
		} else if status == "pending" {
			newStatus = "pending"
		} else {
			newStatus = "approved"
		}
	} else if paid >= amount {
		if deferred == 0 {
			newStatus, completed = "completed", true
		} else {
			newStatus = "approved"
		}
	} else if paid > 0 {
		newStatus = "partially_paid"
	} else if status == "partially_paid" {
		newStatus = "approved"
	}
	if completed {
		_, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET status=?,completed_at=COALESCE(completed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, newStatus, obligationID)
		return err
	}
	_, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, newStatus, obligationID)
	return err
}

func min64(a, b int64) int64 {
	if a < b {
		return a
	}
	return b
}

func (s *Service) adjustReturnSalesFees(ctx context.Context, tx *sql.Tx, itemID, returnID, orderItemID, actorID int64) error {
	var quantity, originalQuantity int64
	var orderID int64
	if err := tx.QueryRowContext(ctx, `SELECT ri.quantity_returned,oi.original_quantity,oi.order_id FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id WHERE ri.id=? FOR UPDATE`, itemID).
		Scan(&quantity, &originalQuantity, &orderID); err != nil {
		return err
	}
	if quantity <= 0 || originalQuantity <= 0 {
		return nil
	}
	rows, err := tx.QueryContext(ctx, `SELECT id,beneficiary_user_id,CAST(amount AS CHAR) FROM commissions WHERE order_item_id=? AND beneficiary_role='sales' ORDER BY id FOR UPDATE`, orderItemID)
	if err != nil {
		return err
	}
	type commission struct {
		id, userID int64
		amount     string
	}
	var commissions []commission
	for rows.Next() {
		var c commission
		if err := rows.Scan(&c.id, &c.userID, &c.amount); err != nil {
			rows.Close()
			return err
		}
		commissions = append(commissions, c)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	if err := rows.Close(); err != nil {
		return err
	}
	for _, commission := range commissions {
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM commission_adjustments WHERE commission_id=? AND return_item_id=?)`, commission.id, itemID).Scan(&exists); err != nil {
			return err
		}
		if exists {
			continue
		}
		commissionCents := toCents(parseDecimalString(commission.amount))
		perUnit := int64(math.Floor(float64(commissionCents)/float64(originalQuantity) + 0.5))
		if perUnit <= 0 {
			continue
		}
		var cancelledRaw string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(-amount),0) AS CHAR) FROM commission_adjustments WHERE commission_id=?`, commission.id).Scan(&cancelledRaw); err != nil {
			return err
		}
		cancelled := toCents(parseDecimalString(cancelledRaw))
		if cancelled < 0 {
			cancelled = 0
		}
		outstanding := max64(0, commissionCents-cancelled)
		requested := perUnit * quantity
		cancel := min64(requested, outstanding)
		if cancel <= 0 {
			continue
		}
		reason := fmt.Sprintf("sales_fee_refund: units=%d return_id=%d", quantity, returnID)
		if _, err := tx.ExecContext(ctx, `INSERT INTO commission_adjustments
			(commission_id,order_id,order_item_id,beneficiary_user_id,beneficiary_role,return_item_id,amount,reason,policy_version,created_by,created_at,updated_at)
			VALUES (?,?,?,?,'sales',?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, commission.id, orderID, orderItemID,
			commission.userID, itemID, amountOf(-cancel), reason, "ws8-owner-2026-10-30", actorID); err != nil {
			return err
		}
		props, err := json.Marshal(map[string]any{"return_id": returnID, "return_item_id": itemID, "order_item_id": orderItemID,
			"commission_id": commission.id, "units": quantity, "amount": -float64(cancel) / 100, "policy_version": "ws8-owner-2026-10-30"})
		if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?,'App\\Models\\ReturnItem',?,'commission.sales_fee_refund_adjusted',NULL,?,NULLIF(?,''),NULLIF(?,''),UTC_TIMESTAMP(6))`,
			actorID, itemID, string(props), s.IP, s.UserAgent); err != nil {
			return err
		}
	}
	return nil
}
