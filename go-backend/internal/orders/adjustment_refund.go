package orders

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"math"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// MarkAdjustmentRefundStatus records a fulfillment shortfall refund with the same Order-first lock
// and payment/ledger side effects as OrderFulfillmentService::markAdjustmentRefundStatus.
func (s *Service) MarkAdjustmentRefundStatus(ctx context.Context, adjustmentID, actorID int64, actorRole, status string) error {
	var orderID int64
	err := s.Database.QueryRowContext(ctx, `SELECT oi.order_id FROM order_item_adjustments a JOIN order_items oi ON oi.id=a.order_item_id WHERE a.id=?`, adjustmentID).Scan(&orderID)
	if errors.Is(err, sql.ErrNoRows) {
		return &Problem{Status: 404, Message: s.Messages("system.endpoint_not_found")}
	}
	if err != nil {
		return err
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var totalRaw, paidRaw, orderStatus string
		var crmCustomer sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),status,crm_customer_id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&totalRaw, &paidRaw, &orderStatus, &crmCustomer); err != nil {
			return err
		}
		var itemID int64
		var current string
		var refundRaw string
		if err := tx.QueryRowContext(ctx, `SELECT order_item_id,refund_status,CAST(refund_amount AS CHAR) FROM order_item_adjustments WHERE id=? FOR UPDATE`, adjustmentID).Scan(&itemID, &current, &refundRaw); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &Problem{Status: 404, Message: s.Messages("system.endpoint_not_found")}
			}
			return err
		}
		if current != "pending" {
			return &Problem{Status: 422, Message: s.Messages("payment.already_processed")}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_item_adjustments SET refund_status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, status, adjustmentID); err != nil {
			return err
		}
		if status == "processed" {
			if crmCustomer.Valid {
				var evidence bool
				if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM refund_recipient_evidences WHERE order_id=?)`, orderID).Scan(&evidence); err != nil {
					return err
				}
				if !evidence {
					return &Problem{Status: 422, Message: s.Messages("crm.recipient_evidence_required"), Errors: map[string]any{"code": "refund_recipient_evidence_required"}}
				}
			}
			var obligationID int64
			var obligationKey, obligationStatus, obligationAmount string
			err := tx.QueryRowContext(ctx, `SELECT id,obligation_key,status,CAST(amount AS CHAR) FROM refund_obligations WHERE obligation_key=? FOR UPDATE`, fmt.Sprintf("adjustment:%d", adjustmentID)).Scan(&obligationID, &obligationKey, &obligationStatus, &obligationAmount)
			if err != nil && !errors.Is(err, sql.ErrNoRows) {
				return err
			}
			if err == nil {
				var paid string
				if err := tx.QueryRowContext(ctx, `SELECT CAST(COALESCE(SUM(amount),0) AS CHAR) FROM refund_payouts WHERE obligation_id=?`, obligationID).Scan(&paid); err != nil {
					return err
				}
				remaining := toCents(parseDecimalString(obligationAmount) - parseDecimalString(paid))
				if remaining > 0 {
					if err := s.settleLegacyReturn(ctx, tx, orderID, obligationID, obligationKey, obligationStatus, remaining, actorID, orderStatus); err != nil {
						return err
					}
				}
			} else {
				var total, paidNow string
				if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR) FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&total, &paidNow); err != nil {
					return err
				}
				newPaid := int64(math.Max(0, float64(toCents(parseDecimalString(paidNow))-toCents(parseDecimalString(refundRaw)))))
				newRemaining := int64(math.Max(0, float64(toCents(parseDecimalString(total))-newPaid)))
				paymentStatus := "unpaid"
				if newRemaining == 0 {
					paymentStatus = "paid"
				} else if newPaid > 0 {
					paymentStatus = "partially_paid"
				}
				if _, err := tx.ExecContext(ctx, `UPDATE orders SET paid_amount=?,remaining_amount=?,payment_status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, money.String(float64(newPaid)/100), money.String(float64(newRemaining)/100), paymentStatus, orderID); err != nil {
					return err
				}
				if err := s.syncInvoiceAfterChange(ctx, tx, orderID, actorID, "refund_processed"); err != nil {
					return err
				}
			}
		}
		props := fmt.Sprintf(`{"refund_status":%q,"actor_role":%q}`, status, actorRole)
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\OrderItemAdjustment',?,'order_item_adjustment.refund_status_changed',NULL,?,NULLIF(?,''),NULLIF(?,''),UTC_TIMESTAMP(6))`, actorID, adjustmentID, props, s.IP, s.UserAgent)
		return err
	})
}
