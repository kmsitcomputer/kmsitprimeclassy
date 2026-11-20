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

type ReturnReviewInput struct {
	ReturnID  int64
	ActorID   int64
	ActorRole string
	Approved  bool
	Note      *string
}

func (s *Service) ReviewReturn(ctx context.Context, in ReturnReviewInput) error {
	var status string
	var orderID int64
	if err := s.Database.QueryRowContext(ctx, `SELECT status,order_id FROM returns WHERE id=?`, in.ReturnID).Scan(&status, &orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return &Problem{Status: 404, Message: "Endpoint tidak ditemukan."}
		}
		return err
	}
	if !reviewableReturnStatus(status) {
		return &Problem{Status: 422, Message: s.Messages("return.already_reviewed")}
	}
	return s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		// Match the source's canonical lock order: Order → ReturnRequest → ReturnItems → OrderItems.
		var lockedOrderID int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&lockedOrderID); err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT status FROM returns WHERE id=? FOR UPDATE`, in.ReturnID).Scan(&status); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &Problem{Status: 404, Message: "Endpoint tidak ditemukan."}
			}
			return err
		}
		if !reviewableReturnStatus(status) {
			return &Problem{Status: 422, Message: s.Messages("return.already_reviewed")}
		}
		snapshotID := int64(0)
		provenance := ""
		if in.Approved {
			var err error
			snapshotID, provenance, err = s.ensureSnapshot(ctx, tx, orderID, in.ActorID, triggerReturnApproval, "frozen")
			if err != nil {
				return err
			}
		}
		items, err := tx.QueryContext(ctx, `SELECT id,order_item_id,quantity_returned,status FROM return_items
			WHERE return_id=? ORDER BY id FOR UPDATE`, in.ReturnID)
		if err != nil {
			return err
		}
		type line struct {
			id, orderItemID, quantity int64
			status                    string
		}
		var lines []line
		for items.Next() {
			var row line
			if err := items.Scan(&row.id, &row.orderItemID, &row.quantity, &row.status); err != nil {
				items.Close()
				return err
			}
			lines = append(lines, row)
		}
		if err := items.Err(); err != nil {
			items.Close()
			return err
		}
		if err := items.Close(); err != nil {
			return err
		}
		for _, row := range lines {
			if row.status != "pending" {
				continue
			}
			var returnedQuantity int64
			if err := tx.QueryRowContext(ctx, `SELECT returned_quantity FROM order_items WHERE id=? FOR UPDATE`, row.orderItemID).Scan(&returnedQuantity); err != nil {
				if errors.Is(err, sql.ErrNoRows) {
					continue
				}
				return err
			}
			if in.Approved {
				if _, err := tx.ExecContext(ctx, `UPDATE return_items SET status='approved',refund_status='pending',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, row.id); err != nil {
					return err
				}
				if snapshotID != 0 {
					if err := s.recordReturnApprovalLedger(ctx, tx, orderID, row.id, row.orderItemID, row.quantity,
						returnedQuantity, snapshotID, provenance, in.ActorID); err != nil {
						return err
					}
				}
			} else {
				if _, err := tx.ExecContext(ctx, `UPDATE return_items SET status='rejected',refund_status='not_required',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, row.id); err != nil {
					return err
				}
				if _, err := tx.ExecContext(ctx, `UPDATE order_items SET returned_quantity=GREATEST(0,returned_quantity-?),status='terkirim',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, row.quantity, row.orderItemID); err != nil {
					return err
				}
			}
		}
		var noteValue any
		if in.Note != nil && strings.TrimSpace(*in.Note) != "" {
			noteValue = strings.TrimSpace(*in.Note)
		}
		if _, err := tx.ExecContext(ctx, `UPDATE returns SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`,
			map[bool]string{true: "approved", false: "rejected"}[in.Approved], in.ActorID, in.ReturnID); err != nil {
			return err
		}
		properties, err := json.Marshal(map[string]any{"approved": in.Approved, "actor_role": in.ActorRole})
		if err != nil {
			return err
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
			(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?, 'App\\Models\\ReturnRequest', ?, 'return.reviewed', ?, ?, NULLIF(?,''), NULLIF(?,''), UTC_TIMESTAMP(6))`,
			in.ActorID, in.ReturnID, noteValue, string(properties), s.IP, s.UserAgent)
		return err
	})
}

func reviewableReturnStatus(status string) bool {
	return status == "requested" || status == "under_review"
}

func (s *Service) recordReturnApprovalLedger(ctx context.Context, tx *sql.Tx, orderID, returnItemID, orderItemID, quantity,
	returnedQuantity, snapshotID int64, provenance string, actorID int64) error {
	available, err := s.availableUnits(ctx, tx, snapshotID, provenance, orderItemID)
	if err != nil {
		return err
	}
	reductionCents := int64(0)
	if available >= int(quantity) {
		reductionCents, _, err = s.consumeUnits(ctx, tx, snapshotID, provenance, orderItemID, int(quantity), triggerReturnApproval)
		if err != nil {
			return err
		}
	}
	stageTo, stageFrom := returnedQuantity, returnedQuantity-quantity
	key := fmt.Sprintf("return_item:%d:stage:%d-%d", returnItemID, stageFrom, stageTo)
	var refundRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(refund_amount AS CHAR) FROM return_items WHERE id=?`, returnItemID).Scan(&refundRaw); err != nil {
		return err
	}
	provisional := provenance == "provisional_legacy"
	if provisional || reductionCents <= 0 {
		eventCents := int64(money.Round2(parseDecimalString(refundRaw) * 100))
		if eventCents <= 0 {
			return nil
		}
		return s.createReturnVerificationObligation(ctx, tx, orderID, returnItemID, key, snapshotID, eventCents, actorID, provenance)
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO order_sales_reductions
		(order_id,source_type,source_id,amount,applied_to_order_total,provenance,created_at,updated_at)
		VALUES (?, 'return_stage', ?, ?, 0, ?, UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		orderID, returnItemID, amountOf(reductionCents), map[bool]string{true: "provisional_legacy", false: "forward"}[provisional]); err != nil {
		return err
	}
	return s.createForwardObligation(ctx, tx, orderID, returnItemID, key, snapshotID, reductionCents, actorID,
		"Return approved", "return_stage", "return_approval", "Return exceeds available cash")
}

func (s *Service) createReturnVerificationObligation(ctx context.Context, tx *sql.Tx, orderID, returnItemID int64,
	key string, snapshotID, eventCents, actorID int64, provenance string) error {
	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM refund_obligations WHERE obligation_key=? FOR UPDATE`, key).Scan(&existing)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	var finalized sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT cancellation_financial_status FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&finalized); err != nil {
		return err
	}
	if finalized.Valid && finalized.String == "finalized" {
		return &Problem{Status: 422, Message: "Order sudah difinalisasi secara finansial; obligation baru memerlukan koreksi manual Owner.", Errors: map[string]any{"code": "order_financially_finalized"}}
	}
	obligationNo, err := ulid()
	if err != nil {
		return err
	}
	metadata, err := json.Marshal(map[string]any{"event_value_cents": eventCents, "requires_evidence": true})
	if err != nil {
		return err
	}
	var snapshotValue any
	if snapshotID != 0 {
		snapshotValue = snapshotID
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO refund_obligations
		(obligation_no,order_id,source_type,source_id,obligation_key,amount,status,provenance,reason,snapshot_id,
		 deferred_amount,deferred_remaining,created_by,metadata,created_at,updated_at)
		VALUES (?,?,'return_stage',?,?,?,'needs_verification',?,'Return approved',?,'0.00','0.00',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())`,
		"RO-"+obligationNo, orderID, returnItemID, key, amountOf(eventCents), provenance, snapshotValue, actorID, string(metadata))
	if err != nil {
		return err
	}
	obligationID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	return s.refundAudit(ctx, tx, "obligation.needs_verification", orderID, obligationID, actorID, "Return approved",
		map[string]any{"amount_cents": eventCents, "provenance": provenance})
}
