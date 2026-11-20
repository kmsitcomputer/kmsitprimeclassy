package orders

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

type AddLineInput struct {
	OrderID                 int64
	ProductID               int64
	VariationID             *int64
	Quantity                int
	RequestedDate           *string
	Reason                  string
	AdditionalPaymentMethod string
	IdempotencyKey          string
	ActorID                 int64
	ActorRole               string
	AgentID                 *int64
}

type addLineFingerprint struct {
	ProductID     int64   `json:"product_id"`
	VariationID   *int64  `json:"product_variation_id"`
	Quantity      int     `json:"quantity"`
	RequestedDate *string `json:"requested_delivery_date"`
	PaymentMethod string  `json:"additional_payment_method"`
}

func fingerprintAddLine(in AddLineInput) string {
	date := in.RequestedDate
	if date != nil {
		if parsed, err := parseRequestedDate(*date); err == nil {
			d := parsed.Format("2006-01-02")
			date = &d
		}
	}
	raw, _ := json.Marshal(addLineFingerprint{in.ProductID, in.VariationID, in.Quantity, date, in.AdditionalPaymentMethod})
	h := sha256.Sum256(raw)
	return hex.EncodeToString(h[:])
}

func parseRequestedDate(raw string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02 15:04:05", "2006-01-02"} {
		if t, err := time.Parse(layout, raw); err == nil {
			return t, nil
		}
	}
	return time.Time{}, fmt.Errorf("invalid date")
}

// AddLine ports OrderLineAdditionService::addLine (SC-03). It locks Order -> Stock Request ->
// canonical Agent inventory targets, then writes the line, shipment grouping, demand row, totals,
// additional-payment obligation, and audit atomically.
func (s *Service) AddLine(ctx context.Context, in AddLineInput) (*Order, bool, error) {
	if in.ActorRole != "admin" {
		return nil, false, &Problem{Status: 403, Message: "This action is unauthorized."}
	}
	if in.IdempotencyKey == "" {
		return nil, false, &Problem{Status: 422, Message: s.Messages("payment.idempotency_key_required"), Fields: map[string]string{"idempotency_key": s.Messages("system.field_required")}}
	}
	if len([]rune(in.IdempotencyKey)) > 100 {
		return nil, false, &Problem{Status: 422, Message: s.Messages("order.idempotency_key_too_long"), Fields: map[string]string{"idempotency_key": s.Messages("order.idempotency_key_too_long")}}
	}
	if in.AdditionalPaymentMethod == "" {
		in.AdditionalPaymentMethod = "transfer"
	}
	fingerprint := fingerprintAddLine(in)
	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, false, err
	}
	defer func() { _ = tx.Rollback() }()
	var agentID int64
	var orderStatus string
	var totalRaw, paidRaw string
	var orderDate, konsumenID sql.NullString
	err = tx.QueryRowContext(ctx, `SELECT agent_id,status,CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),
		CAST(delivery_date_estimate AS CHAR),CAST(konsumen_id AS CHAR) FROM orders WHERE id=? FOR UPDATE`, in.OrderID).
		Scan(&agentID, &orderStatus, &totalRaw, &paidRaw, &orderDate, &konsumenID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, false, ErrOrderNotFound
	}
	if err != nil {
		return nil, false, err
	}
	if in.AgentID == nil || *in.AgentID != agentID {
		return nil, false, ErrOrderNotFound
	}
	// SC-03 is Admin-only; route middleware is backed by this independent service check.
	if in.ActorRole != "admin" {
		return nil, false, &Problem{Status: 403, Message: "This action is unauthorized."}
	}

	// Idempotent replay is checked before window/date/stock tests, matching Laravel after the order lock.
	var existingID, existingProduct int64
	var existingVariation sql.NullInt64
	var existingQty int
	var existingFingerprint sql.NullString
	err = tx.QueryRowContext(ctx, `SELECT id,product_id,product_variation_id,original_quantity,request_fingerprint
		FROM order_items WHERE order_id=? AND idempotency_key=?`, in.OrderID, in.IdempotencyKey).
		Scan(&existingID, &existingProduct, &existingVariation, &existingQty, &existingFingerprint)
	if err == nil {
		same := false
		if existingFingerprint.Valid {
			same = existingFingerprint.String == fingerprint
		} else {
			same = existingProduct == in.ProductID && existingVariation.Int64 == pointerInt(in.VariationID) && existingQty == in.Quantity
		}
		if !same {
			return nil, false, &Problem{Status: 409, Message: s.Messages("order.line_idempotency_conflict")}
		}
		if err = tx.Commit(); err != nil {
			return nil, false, err
		}
		order, err := s.LoadOrderForRead(ctx, in.OrderID, true)
		return order, true, err
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return nil, false, err
	}

	var frozen int
	if err = tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_financial_snapshots WHERE order_id=?)`, in.OrderID).Scan(&frozen); err != nil {
		return nil, false, err
	}
	if frozen != 0 {
		return nil, false, &Problem{Status: 422, Message: "Order sudah memiliki snapshot keuangan; perubahan quantity/voucher/diskon/tambah baris tidak diizinkan.", Errors: map[string]any{"code": "order_financial_snapshot_frozen"}}
	}
	if orderStatus != "diproses" {
		return nil, false, &Problem{Status: 422, Message: s.Messages("fulfillment.window_closed")}
	}

	rows, err := tx.QueryContext(ctx, `SELECT id FROM shipments WHERE order_id=? AND (courier_id IS NOT NULL OR self_delivered_by_user_id IS NOT NULL) FOR UPDATE`, in.OrderID)
	if err != nil {
		return nil, false, err
	}
	assigned := false
	for rows.Next() {
		var id int64
		if err = rows.Scan(&id); err != nil {
			rows.Close()
			return nil, false, err
		}
		assigned = true
	}
	rows.Close()
	if err = rows.Err(); err != nil {
		return nil, false, err
	}
	if assigned {
		return nil, false, &Problem{Status: 422, Message: s.Messages("order.line_addition_executor_assigned")}
	}
	if in.RequestedDate != nil {
		requested, derr := parseRequestedDate(*in.RequestedDate)
		if derr != nil {
			return nil, false, &Problem{Status: 422, Message: s.Messages("system.validation_failed"), Fields: map[string]string{"requested_delivery_date": s.Messages("system.field_invalid")}}
		}
		today := time.Now().Format("2006-01-02")
		if requested.Format("2006-01-02") < today {
			return nil, false, &Problem{Status: 422, Message: s.Messages("order.line_addition_date_in_past"), Fields: map[string]string{"requested_delivery_date": s.Messages("order.line_addition_date_in_past")}}
		}
	}
	var subCount int
	if err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM order_items WHERE order_id=? AND stock_source='sub'`, in.OrderID).Scan(&subCount); err != nil {
		return nil, false, err
	}
	if subCount > 0 {
		return nil, false, &Problem{Status: 422, Message: s.Messages("order.line_addition_sub_not_supported")}
	}
	if !konsumenID.Valid || strings.TrimSpace(konsumenID.String) == "" {
		return nil, false, &Problem{Status: 404, Message: "No query results for model [App\\Models\\User]"}
	}
	var konsumen int64
	if _, err = fmt.Sscan(konsumenID.String, &konsumen); err != nil {
		return nil, false, err
	}

	quoter := s.quoter()
	resolved, err := quoter.ResolveLine(ctx, checkout.QuoteLine{ProductID: in.ProductID, ProductVariationID: in.VariationID, Quantity: in.Quantity})
	if err != nil {
		return nil, false, err
	}
	// Active Stock Request is locked before inventory, preserving the warehouse approval lock order.
	var requestID int64
	var requestStatus string
	err = tx.QueryRowContext(ctx, `SELECT id,status FROM stock_requests WHERE order_id=? FOR UPDATE`, in.OrderID).Scan(&requestID, &requestStatus)
	if errors.Is(err, sql.ErrNoRows) || requestStatus == "cancelled" {
		return nil, false, &Problem{Status: 422, Message: s.Messages("order.line_addition_stock_request_unavailable")}
	}
	if err != nil {
		return nil, false, err
	}

	target := inventory.ProductTarget(resolved.Product.ID)
	if resolved.Variation != nil {
		target = inventory.VariationTarget(resolved.Variation.ID)
	}
	if err = inventory.LockReservationTargets(ctx, tx, agentID, []inventory.Target{target}); err != nil {
		return nil, false, err
	}
	date := in.RequestedDate
	if date != nil {
		parsed, parseErr := parseRequestedDate(*date)
		if parseErr != nil {
			return nil, false, &Problem{Status: 422, Message: s.Messages("system.validation_failed"), Fields: map[string]string{"requested_delivery_date": s.Messages("system.field_invalid")}}
		}
		v := parsed.Format("2006-01-02")
		date = &v
	}
	if date == nil && orderDate.Valid {
		v := orderDate.String[:10]
		date = &v
	}
	serviceInput := Input{ActorID: in.ActorID, KonsumenID: konsumen, DeliveryDate: date, OrderItemIdempotencyKey: in.IdempotencyKey, OrderItemFingerprint: fingerprint}
	before := parseDecimalString(totalRaw) - parseDecimalString(paidRaw)
	if before < 0 {
		before = 0
	}
	ref := inventory.ReservationReference{Type: "order", ID: in.OrderID, CreatedBy: &in.ActorID}
	if _, _, err = s.priceAndReserveLine(ctx, tx, quoter, in.OrderID, agentID, serviceInput, resolved, stockSourceAgent, nil, ref, "diproses"); err != nil {
		return nil, false, err
	}
	var itemID int64
	if err = tx.QueryRowContext(ctx, `SELECT id FROM order_items WHERE order_id=? AND idempotency_key=?`, in.OrderID, in.IdempotencyKey).Scan(&itemID); err != nil {
		return nil, false, err
	}
	if err = s.assignFreshShipment(ctx, tx, itemID, in.OrderID, stockSourceAgent, nil, in.ActorID, "shipment.added_for_line"); err != nil {
		return nil, false, err
	}
	item, err := lockItem(ctx, tx, in.OrderID, itemID)
	if err != nil {
		return nil, false, err
	}
	if item.RequestedDate != nil {
		if err = s.consolidateIntoShipmentForDate(ctx, tx, item, *item.RequestedDate, in.ActorID, "line_addition"); err != nil {
			return nil, false, err
		}
	}
	if err = appendStockRequestItem(ctx, tx, requestID, itemID, item); err != nil {
		return nil, false, err
	}
	if err = s.recalculateTotals(ctx, tx, in.OrderID); err != nil {
		return nil, false, err
	}
	if err = s.reconcileAdditionalObligation(ctx, tx, in.OrderID, agentID, item, before, in.AdditionalPaymentMethod, in.Reason, in.ActorID); err != nil {
		return nil, false, err
	}
	var totalAfterRaw string
	if err = tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR) FROM orders WHERE id=?`, in.OrderID).Scan(&totalAfterRaw); err != nil {
		return nil, false, err
	}
	if err = s.writeSubjectActivity(ctx, tx, in.ActorID, "App\\Models\\OrderItem", itemID, "order_item.added", in.Reason, map[string]any{
		"order_id": in.OrderID, "product_id": item.ProductID, "product_variation_id": item.VariationID, "quantity": item.OriginalQuantity,
		"stock_source": item.StockSource, "requested_delivery_date": item.RequestedDate, "idempotency_key": in.IdempotencyKey,
		"total_before": money.Round2(parseDecimalString(totalRaw)), "total_after": money.Round2(parseDecimalString(totalAfterRaw)), "actor_role": in.ActorRole,
	}); err != nil {
		return nil, false, err
	}
	if err = tx.Commit(); err != nil {
		return nil, false, err
	}
	order, err := s.LoadOrderForRead(ctx, in.OrderID, true)
	return order, false, err
}

func pointerInt(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

func appendStockRequestItem(ctx context.Context, tx *sql.Tx, requestID, itemID int64, item *lockedItem) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO stock_request_items(stock_request_id,order_item_id,product_id,product_variation_id,sku_snapshot,requested_qty,fulfilled_qty,remaining_qty,created_at,updated_at)
		VALUES(?,?,?,?,?,?,0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())`, requestID, itemID, item.ProductID, nullableInt(item.VariationID), nullableString(item.SKU), item.OriginalQuantity, item.OriginalQuantity)
	if err != nil {
		return err
	}
	rows, err := tx.QueryContext(ctx, `SELECT remaining_qty,fulfilled_qty FROM stock_request_items WHERE stock_request_id=? ORDER BY id FOR UPDATE`, requestID)
	if err != nil {
		return err
	}
	remaining, fulfilled := 0, 0
	for rows.Next() {
		var r, f int
		if err = rows.Scan(&r, &f); err != nil {
			rows.Close()
			return err
		}
		remaining += r
		fulfilled += f
	}
	rows.Close()
	if err = rows.Err(); err != nil {
		return err
	}
	status := "pending"
	if remaining == 0 {
		status = "fulfilled"
	} else if fulfilled > 0 {
		status = "partial"
	}
	if status != "fulfilled" {
		_, err = tx.ExecContext(ctx, `UPDATE stock_requests SET status=?,fulfilled_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?`, status, requestID)
	} else {
		_, err = tx.ExecContext(ctx, `UPDATE stock_requests SET status=?,fulfilled_at=COALESCE(fulfilled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=?`, status, requestID)
	}
	return err
}
