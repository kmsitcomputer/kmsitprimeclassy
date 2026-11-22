package orders

import (
	"context"
	"database/sql"
	"errors"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// MarkAdditionalPaymentPaid mirrors OrderFulfillmentService::markAdditionalPaymentPaid.
// Transfer proof verification, order balance, transaction, and obligation settle atomically.
func (s *Service) MarkAdditionalPaymentPaid(ctx context.Context, id, actorID int64, actorRole string, paid bool) error {
	var orderID int64
	var transactionID sql.NullInt64
	if err := s.Database.QueryRowContext(ctx, `SELECT order_id,payment_transaction_id FROM order_additional_payments WHERE id=?`, id).
		Scan(&orderID, &transactionID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return ErrOrderNotFound
		}
		return err
	}
	verificationID := sql.NullInt64{}
	if transactionID.Valid {
		if err := s.Database.QueryRowContext(ctx, `SELECT id FROM bank_transfer_verifications WHERE payment_transaction_id=?`, transactionID.Int64).
			Scan(&verificationID); err != nil && !errors.Is(err, sql.ErrNoRows) {
			return err
		}
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var verificationStatus string
		if verificationID.Valid {
			err := tx.QueryRowContext(ctx, `SELECT status FROM bank_transfer_verifications WHERE id=? FOR UPDATE`, verificationID.Int64).Scan(&verificationStatus)
			if err != nil && !errors.Is(err, sql.ErrNoRows) {
				return err
			}
		}
		order, err := s.lockOrderForPayment(ctx, tx, orderID)
		if err != nil {
			return err
		}
		var transaction *PaymentTransaction
		if transactionID.Valid {
			transaction, err = s.loadPaymentTransaction(ctx, tx, transactionID.Int64, true)
			if err != nil {
				return err
			}
		}
		var status string
		var linkedTransaction sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT status,payment_transaction_id FROM order_additional_payments WHERE id=? FOR UPDATE`, id).
			Scan(&status, &linkedTransaction); err != nil {
			return err
		}
		if status != "pending" || linkedTransaction.Valid != transactionID.Valid ||
			(linkedTransaction.Valid && linkedTransaction.Int64 != transactionID.Int64) {
			return &Problem{Status: 422, Message: s.Messages("payment.already_processed")}
		}
		if transaction != nil && transaction.Status != "pending" {
			return &Problem{Status: 422, Message: s.Messages("payment.already_processed")}
		}
		var amountRaw string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(amount AS CHAR) FROM order_additional_payments WHERE id=?`, id).Scan(&amountRaw); err != nil {
			return err
		}
		amount := money.Round2(parseString(amountRaw))
		if paid {
			if err := s.assertPaymentAllowed(ctx, tx, order, amount, true); err != nil {
				return err
			}
		}
		newStatus := "failed"
		if paid {
			newStatus = "paid"
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_additional_payments SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?`, newStatus, id); err != nil {
			return err
		}
		if transaction != nil {
			transactionStatus := "failed"
			paidAt := "NULL"
			if paid {
				transactionStatus = "paid"
				paidAt = "UTC_TIMESTAMP()"
			}
			if _, err := tx.ExecContext(ctx, `UPDATE payment_transactions SET status=?,paid_at=`+paidAt+`,updated_at=UTC_TIMESTAMP() WHERE id=?`, transactionStatus, transaction.ID); err != nil {
				return err
			}
			if verificationID.Valid && verificationStatus == "pending" {
				if paid {
					_, err = tx.ExecContext(ctx, `UPDATE bank_transfer_verifications SET status='verified',verified_by=?,verified_at=UTC_TIMESTAMP(),verified_amount=?,updated_at=UTC_TIMESTAMP() WHERE id=?`, actorID, money.String(transaction.Amount), verificationID.Int64)
				} else {
					_, err = tx.ExecContext(ctx, `UPDATE bank_transfer_verifications SET status='rejected',verified_by=?,verified_at=UTC_TIMESTAMP(),rejection_reason='additional payment marked failed',updated_at=UTC_TIMESTAMP() WHERE id=?`, actorID, verificationID.Int64)
				}
				if err != nil {
					return err
				}
			}
		}
		if paid {
			if err := s.applyPaymentToOrder(ctx, tx, order, amount); err != nil {
				return err
			}
			if err := s.syncInvoiceAfterChange(ctx, tx, orderID, actorID, "additional_payment_settled"); err != nil {
				return err
			}
		}
		return logActivityDescribed(ctx, tx, actorID, "App\\Models\\OrderAdditionalPayment", id,
			"order_additional_payment.status_changed", nil, map[string]any{"status": newStatus, "actor_role": actorRole}, s.IP, s.UserAgent)
	})
}
