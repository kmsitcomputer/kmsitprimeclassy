package orders

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// FinalizeCancellation mirrors CancellationFinancialService::finalize. The order row is the
// serialization point for both retry safety and the in-flight attempt decision.
func (s *Service) FinalizeCancellation(ctx context.Context, orderID, actorID int64) error {
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var status string
		if err := tx.QueryRowContext(ctx, `SELECT cancellation_financial_status FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&status); err != nil {
			return err
		}
		if status == "finalized" {
			return nil
		}
		rows, err := tx.QueryContext(ctx, `SELECT id,status,source_type,provenance,CAST(amount AS CHAR),CAST(deferred_amount AS CHAR),CAST(deferred_remaining AS CHAR) FROM refund_obligations WHERE order_id=? ORDER BY id FOR UPDATE`, orderID)
		if err != nil {
			return err
		}
		type obligation struct {
			id                                        int64
			status, sourceType, provenance            string
			amount, deferredAmount, deferredRemaining string
		}
		obligations := make([]obligation, 0)
		openIDs := make([]int64, 0)
		for rows.Next() {
			var o obligation
			if err := rows.Scan(&o.id, &o.status, &o.sourceType, &o.provenance, &o.amount, &o.deferredAmount, &o.deferredRemaining); err != nil {
				rows.Close()
				return err
			}
			obligations = append(obligations, o)
			if o.status == "pending" || o.status == "approved" || o.status == "partially_paid" || o.status == "needs_verification" {
				openIDs = append(openIDs, o.id)
			}
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return err
		}
		rows.Close()
		if len(openIDs) > 0 {
			placeholders := make([]any, len(openIDs))
			marks := ""
			for i, id := range openIDs {
				placeholders[i] = id
				if i > 0 {
					marks += ","
				}
				marks += "?"
			}
			var inFlight int
			query := `SELECT COUNT(*) FROM refund_attempts WHERE obligation_id IN (` + marks + `) AND status IN ('created','processing','unknown')`
			if err := tx.QueryRowContext(ctx, query, placeholders...).Scan(&inFlight); err != nil {
				return err
			}
			if inFlight > 0 {
				if _, err := tx.ExecContext(ctx, `UPDATE orders SET cancellation_financial_status='pending_reconciliation',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, orderID); err != nil {
					return err
				}
				return cancellationLedgerEvent(ctx, tx, orderID, 0, actorID, "cancellation.finalization_deferred", fmt.Sprintf("cancellation_deferred:%d", orderID), nil, map[string]any{"reason": "in_flight_attempt"})
			}
		}
		for _, o := range obligations {
			if o.status != "pending" && o.status != "approved" || o.provenance != "forward" || (o.sourceType != "return_stage" && o.sourceType != "fulfillment_shortfall") {
				continue
			}
			var payouts string
			if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount),0) FROM refund_payouts WHERE obligation_id=?`, o.id).Scan(&payouts); err != nil {
				return err
			}
			if toCents(parseDecimalString(payouts)) > 0 {
				continue
			}
			var inFlight, openChild int
			if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM refund_attempts WHERE obligation_id=? AND status IN ('created','processing','unknown')`, o.id).Scan(&inFlight); err != nil {
				return err
			}
			if inFlight > 0 {
				continue
			}
			if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM refund_obligations WHERE parent_obligation_id=? AND status NOT IN ('cancelled','completed')`, o.id).Scan(&openChild); err != nil {
				return err
			}
			if openChild > 0 {
				continue
			}
			if toCents(parseDecimalString(o.deferredRemaining)) > 0 {
				if _, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET deferred_remaining='0.00',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP(6)),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, o.id); err != nil {
					return err
				}
				if err := cancellationLedgerEvent(ctx, tx, orderID, o.id, actorID, "deferred.superseded", fmt.Sprintf("deferred_superseded:%d", o.id), "superseded_by_order_cancellation", map[string]any{"deferred_amount_cents": toCents(parseDecimalString(o.deferredAmount))}); err != nil {
					return err
				}
			}
			completedAt := ""
			if toCents(parseDecimalString(o.amount)) == 0 && toCents(parseDecimalString(o.deferredRemaining)) > 0 {
				completedAt = ",completed_at=COALESCE(completed_at,UTC_TIMESTAMP(6))"
			}
			if _, err := tx.ExecContext(ctx, `UPDATE refund_obligations SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP(6)),cancel_reason='superseded_by_order_cancellation',updated_at=UTC_TIMESTAMP(6)`+completedAt+` WHERE id=?`, o.id); err != nil {
				return err
			}
			if toCents(parseDecimalString(o.amount)) != 0 {
				if err := cancellationLedgerEvent(ctx, tx, orderID, o.id, actorID, "obligation.superseded", fmt.Sprintf("obligation_superseded:%d", o.id), "superseded_by_order_cancellation", nil); err != nil {
					return err
				}
			}
		}
		metrics, err := s.metricsForOrder(ctx, tx, orderID)
		if err != nil {
			return err
		}
		amount := metrics.PaidCents
		if metrics.AvailableCents < amount {
			amount = metrics.AvailableCents
		}
		if amount > 0 {
			key := fmt.Sprintf("cancel:%d", orderID)
			var existing int64
			err := tx.QueryRowContext(ctx, `SELECT id FROM refund_obligations WHERE obligation_key=? FOR UPDATE`, key).Scan(&existing)
			if errors.Is(err, sql.ErrNoRows) {
				obligationNo, err := ulid()
				if err != nil {
					return err
				}
				metadata, err := json.Marshal(map[string]any{"paid_cents": metrics.PaidCents, "available_cents": metrics.AvailableCents})
				if err != nil {
					return err
				}
				res, err := tx.ExecContext(ctx, `INSERT INTO refund_obligations (obligation_no,order_id,source_type,source_id,obligation_key,amount,status,provenance,reason,approved_at,approval_source,deferred_amount,deferred_remaining,created_by,metadata,created_at,updated_at) VALUES (?,?,'cancellation',?,?,?,'approved','forward','Cancellation refund',UTC_TIMESTAMP(6),'auto_cancellation_policy','0.00','0.00',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, obligationNo, orderID, orderID, key, money.String(float64(amount)/100), actorID, string(metadata))
				if err != nil {
					return err
				}
				obligationID, err := res.LastInsertId()
				if err != nil {
					return err
				}
				if err := cancellationLedgerEvent(ctx, tx, orderID, obligationID, actorID, "obligation.created", fmt.Sprintf("obligation_created:%d", obligationID), nil, map[string]any{"amount_cents": amount, "source_type": "cancellation"}); err != nil {
					return err
				}
			} else if err != nil {
				return err
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE orders SET cancellation_financial_status='finalized',cancellation_finalized_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, orderID); err != nil {
			return err
		}
		return cancellationLedgerEvent(ctx, tx, orderID, 0, actorID, "cancellation.finalized", fmt.Sprintf("cancellation_finalized:%d", orderID), nil, map[string]any{"paid_cents": metrics.PaidCents, "reserved_cents": metrics.ReservedCents, "available_cents": metrics.AvailableCents})
	})
}

func cancellationLedgerEvent(ctx context.Context, tx *sql.Tx, orderID, obligationID, actorID int64, eventType, eventKey string, reason any, payload map[string]any) error {
	var raw any
	if payload != nil {
		b, err := json.Marshal(payload)
		if err != nil {
			return err
		}
		raw = string(b)
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO refund_ledger_events(order_id,obligation_id,event_type,event_key,actor_type,actor_id,reason,payload,occurred_at,created_at,updated_at) VALUES (?,?,?,?,'user',?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, orderID, nullableID(obligationID), eventType, eventKey, actorID, reason, raw)
	return err
}
