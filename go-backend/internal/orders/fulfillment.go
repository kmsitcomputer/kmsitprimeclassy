package orders

// Per-item fulfillment lifecycle — quantity adjustment, undelivered cancellation and delivery reschedule.
//
// Ported from OrderFulfillmentController + OrderFulfillmentService:
//
//	PATCH /orders/{order}/items/{item}/fulfillment          adjustItemQuantity
//	GET   /orders/{order}/items/{item}/cancel-eligibility    cancellationBlocker (read-only)
//	POST  /orders/{order}/items/{item}/cancel-undelivered    cancelUndeliveredQuantity
//	PATCH /orders/{order}/items/{item}/reschedule            rescheduleItemDeliveryDate
//
// The invariants that must survive any refactor:
//
//  1. ORDER-FIRST LOCK ORDER. Every mutation locks the ORDER row, then the ORDER ITEM, then the Stock Request
//     demand, then inventory. Financial rows are only ever read (never locked) inside this window, so a
//     quantity change can never invert against Keuangan settlement.
//  2. original_quantity/subtotal_snapshot/unit_price_snapshot are HISTORICAL and are never rewritten by an
//     adjustment. Only fulfilled/cancelled/additional counters, the courier-fee accrual and the ledger move.
//  3. A refund exists ONLY when total valid paid now exceeds the recalculated total (overpayment), and only for
//     the INCREMENTAL overpayment this reduction created — a second reduction never re-refunds the same money.
//  4. An additional-payment obligation exists ONLY when the order was fully settled before the change
//     (previousRemaining <= 0) AND the new total owes more. A COD/partial order folds the delta into its own
//     outstanding balance; no side-channel ledger appears.
//  5. A reschedule is re-datable ONLY for Kurir Online and Self Delivery/Sub, enforced here under the canonical
//     Order lock — never inferred from whether a date happens to be set (allowlist).

import (
	"log/slog"

	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/whatsappnotify"
)

// AdjustInput is PATCH /orders/{order}/items/{item}/fulfillment.
type AdjustInput struct {
	OrderID                 int64
	ItemID                  int64
	FulfilledQuantity       int
	Reason                  string
	AdditionalPaymentMethod string
	ActorID                 int64
	ActorRole               string
}

// CancelUndeliveredInput is POST /orders/{order}/items/{item}/cancel-undelivered.
type CancelUndeliveredInput struct {
	OrderID          int64
	ItemID           int64
	Quantity         int
	ExpectedQuantity int
	Reason           string
	ActorID          int64
	ActorRole        string
}

// RescheduleInput is PATCH /orders/{order}/items/{item}/reschedule.
type RescheduleInput struct {
	OrderID   int64
	ItemID    int64
	NewDate   string
	Reason    string
	Quantity  *int
	ActorID   int64
	ActorRole string
}

// lockedItem carries the item columns the fulfillment logic reads under the row lock.
type lockedItem struct {
	ID                  int64
	OrderID             int64
	ProductID           int64
	VariationID         *int64
	StockSource         string
	SubLocationID       *int64
	ProductName         string
	VariationLabel      *string
	SKU                 *string
	UnitPrice           float64
	CourierFee          float64
	Subtotal            float64
	OriginalQuantity    int
	FulfilledQuantity   int
	CancelledQuantity   int
	ReturnedQuantity    int
	RefundQuantity      int
	AdditionalQuantity  int
	Status              string
	RequestedDate       *string
	ShipmentID          *int64
	AdditionalPaymentID *int64
}

func (it lockedItem) label() string {
	if it.VariationLabel != nil && *it.VariationLabel != "" {
		return *it.VariationLabel
	}
	if it.SKU != nil && *it.SKU != "" {
		return *it.SKU
	}
	return it.ProductName
}

// assertManageFulfillment is OrderPolicy::manageFulfillment + the route's role gate.
func (s *Service) assertManageFulfillment(ctx context.Context, tx *sql.Tx, orderID, actorID int64, actorRole string) (int64, error) {
	var agentID int64
	err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, orderID).Scan(&agentID)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, ErrOrderNotFound
	}
	if err != nil {
		return 0, err
	}
	if actorRole == "super_admin" {
		return agentID, nil
	}
	if actorRole == "agen" || actorRole == "admin" {
		var actorAgent sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM users WHERE id = ?`, actorID).Scan(&actorAgent); err != nil {
			return 0, err
		}
		if actorAgent.Valid && actorAgent.Int64 == agentID {
			return agentID, nil
		}
	}
	return 0, &Problem{Status: 403, Message: s.Messages("system.unauthorized_action")}
}

// lockItem reads one order item FOR UPDATE and verifies it belongs to the order (controller's abort(404)).
func lockItem(ctx context.Context, tx *sql.Tx, orderID, itemID int64) (*lockedItem, error) {
	var (
		it                         lockedItem
		variation, subLocation     sql.NullInt64
		label, sku, requestedDate  sql.NullString
		unit, courierFee, subtotal sql.NullString
		shipmentID, additionalPay  sql.NullInt64
	)
	err := tx.QueryRowContext(ctx, `SELECT id, order_id, product_id, product_variation_id, stock_source,
			sub_location_id, product_name_snapshot, variation_label_snapshot, sku_snapshot,
			CAST(unit_price_snapshot AS CHAR), CAST(courier_fee_amount AS CHAR), CAST(subtotal_snapshot AS CHAR),
			original_quantity, fulfilled_quantity, cancelled_quantity, returned_quantity, refund_quantity,
			additional_quantity, status, CAST(requested_delivery_date AS CHAR), shipment_id, additional_payment_id
		FROM order_items WHERE id = ? AND order_id = ? FOR UPDATE`, itemID, orderID).
		Scan(&it.ID, &it.OrderID, &it.ProductID, &variation, &it.StockSource,
			&subLocation, &it.ProductName, &label, &sku,
			&unit, &courierFee, &subtotal,
			&it.OriginalQuantity, &it.FulfilledQuantity, &it.CancelledQuantity, &it.ReturnedQuantity,
			&it.RefundQuantity, &it.AdditionalQuantity, &it.Status, &requestedDate, &shipmentID, &additionalPay)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrOrderItemNotFound
	}
	if err != nil {
		return nil, err
	}
	it.VariationID = nullIntPtr(variation)
	it.SubLocationID = nullIntPtr(subLocation)
	it.VariationLabel = nullStringPtr(label)
	it.SKU = nullStringPtr(sku)
	it.UnitPrice = parseString(unit.String)
	it.CourierFee = parseString(courierFee.String)
	it.Subtotal = parseString(subtotal.String)
	it.RequestedDate = nullStringPtr(requestedDate)
	it.ShipmentID = nullIntPtr(shipmentID)
	it.AdditionalPaymentID = nullIntPtr(additionalPay)
	return &it, nil
}

// ErrOrderItemNotFound is the controller's abort(404) for an item that does not belong to the routed order.
var ErrOrderItemNotFound = errors.New("order item not found for this order")

// AdjustItemQuantity is OrderFulfillmentService::adjustItemQuantity.
func (s *Service) AdjustItemQuantity(ctx context.Context, in AdjustInput) (*Item, error) {
	if in.FulfilledQuantity < 0 {
		return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.invalid_quantity")}
	}
	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := s.assertManageFulfillment(ctx, tx, in.OrderID, in.ActorID, in.ActorRole); err != nil {
		return nil, err
	}

	var status string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ? FOR UPDATE`, in.OrderID).
		Scan(&status); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrOrderNotFound
		}
		return nil, err
	}
	item, err := lockItem(ctx, tx, in.OrderID, in.ItemID)
	if err != nil {
		return nil, err
	}

	if err := s.assertQuantityAdjustable(ctx, tx, in.OrderID, status); err != nil {
		return nil, err
	}
	if status != "diproses" {
		return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.window_closed")}
	}

	delta := in.FulfilledQuantity - item.FulfilledQuantity
	if delta == 0 {
		if err := tx.Commit(); err != nil {
			return nil, err
		}
		return s.loadItemForResponse(ctx, item.ID)
	}

	var agentID int64
	if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, in.OrderID).Scan(&agentID); err != nil {
		return nil, err
	}

	method := in.AdditionalPaymentMethod
	if method == "" {
		method = "transfer"
	}
	if delta < 0 {
		if err := s.reduceFulfillment(ctx, tx, in.OrderID, agentID, item, -delta, in.ActorID, in.Reason); err != nil {
			return nil, err
		}
	} else {
		if err := s.increaseFulfillment(ctx, tx, in.OrderID, agentID, item, delta, in.ActorID,
			in.Reason, method); err != nil {
			return nil, err
		}
	}

	if err := s.writeSubjectActivity(ctx, tx, in.ActorID, "App\\Models\\OrderItem", item.ID,
		"order_item.fulfillment_adjusted", in.Reason, map[string]any{
			"order_id": in.OrderID, "delta": delta, "actor_role": in.ActorRole,
		}); err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.loadItemForResponse(ctx, item.ID)
}

// CancellationBlocker is OrderFulfillmentService::cancellationBlocker (read-only projection).
func (s *Service) CancellationBlocker(ctx context.Context, in CancelUndeliveredInput) (eligible bool, reasonCode *string, maxQuantity, activeQuantity int, err error) {
	tx, txErr := s.Database.BeginTx(ctx, &sql.TxOptions{ReadOnly: true})
	if txErr != nil {
		return false, nil, 0, 0, txErr
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := s.assertManageFulfillment(ctx, tx, in.OrderID, in.ActorID, in.ActorRole); err != nil {
		return false, nil, 0, 0, err
	}
	var status string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ?`, in.OrderID).Scan(&status); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return false, nil, 0, 0, ErrOrderNotFound
		}
		return false, nil, 0, 0, err
	}
	item, err := lockItemForRead(ctx, tx, in.OrderID, in.ItemID)
	if err != nil {
		return false, nil, 0, 0, err
	}
	blocker, err := s.cancellationBlocker(ctx, tx, in.OrderID, status, item)
	if err != nil {
		return false, nil, 0, 0, err
	}
	if blocker == nil {
		return true, nil, item.FulfilledQuantity, item.FulfilledQuantity, nil
	}
	return false, blocker, 0, item.FulfilledQuantity, nil
}

// lockItemForRead reads an item without a row lock (the eligibility projection must not block writers).
func lockItemForRead(ctx context.Context, tx *sql.Tx, orderID, itemID int64) (*lockedItem, error) {
	var (
		it                         lockedItem
		variation, subLocation     sql.NullInt64
		label, sku, requestedDate  sql.NullString
		unit, courierFee, subtotal sql.NullString
		shipmentID, additionalPay  sql.NullInt64
	)
	err := tx.QueryRowContext(ctx, `SELECT id, order_id, product_id, product_variation_id, stock_source,
			sub_location_id, product_name_snapshot, variation_label_snapshot, sku_snapshot,
			CAST(unit_price_snapshot AS CHAR), CAST(courier_fee_amount AS CHAR), CAST(subtotal_snapshot AS CHAR),
			original_quantity, fulfilled_quantity, cancelled_quantity, returned_quantity, refund_quantity,
			additional_quantity, status, CAST(requested_delivery_date AS CHAR), shipment_id, additional_payment_id
		FROM order_items WHERE id = ? AND order_id = ?`, itemID, orderID).
		Scan(&it.ID, &it.OrderID, &it.ProductID, &variation, &it.StockSource,
			&subLocation, &it.ProductName, &label, &sku,
			&unit, &courierFee, &subtotal,
			&it.OriginalQuantity, &it.FulfilledQuantity, &it.CancelledQuantity, &it.ReturnedQuantity,
			&it.RefundQuantity, &it.AdditionalQuantity, &it.Status, &requestedDate, &shipmentID, &additionalPay)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrOrderItemNotFound
	}
	if err != nil {
		return nil, err
	}
	it.VariationID = nullIntPtr(variation)
	it.SubLocationID = nullIntPtr(subLocation)
	it.VariationLabel = nullStringPtr(label)
	it.SKU = nullStringPtr(sku)
	it.UnitPrice = parseString(unit.String)
	it.CourierFee = parseString(courierFee.String)
	it.Subtotal = parseString(subtotal.String)
	it.RequestedDate = nullStringPtr(requestedDate)
	it.ShipmentID = nullIntPtr(shipmentID)
	it.AdditionalPaymentID = nullIntPtr(additionalPay)
	return &it, nil
}

// cancellationBlocker is OrderFulfillmentService::cancellationBlocker. Returns nil when cancellable.
func (s *Service) cancellationBlocker(ctx context.Context, tx *sql.Tx, orderID int64, orderStatus string, item *lockedItem) (*string, error) {
	block := func(code string) (*string, error) { return &code, nil }

	if orderStatus != "diproses" && orderStatus != "dikirim" {
		return block("order_window_closed")
	}
	if item.StockSource == "sub" {
		return block("sub_sourced")
	}
	if item.Status != "diproses" {
		return block("not_undispatched")
	}
	open, err := hasOpenRefusal(ctx, tx, item.ID)
	if err != nil {
		return nil, err
	}
	if open {
		return block("open_refusal")
	}
	if item.FulfilledQuantity < 1 {
		return block("nothing_to_cancel")
	}
	if item.AdditionalPaymentID != nil {
		var pending bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_additional_payments
			WHERE id = ? AND status = 'pending')`, *item.AdditionalPaymentID).Scan(&pending); err != nil {
			return nil, err
		}
		if pending {
			return block("pending_additional_payment")
		}
	}
	return nil, nil
}

// hasOpenRefusal is DeliveryRefusalService::hasOpenRefusal.
func hasOpenRefusal(ctx context.Context, tx *sql.Tx, itemID int64) (bool, error) {
	var open bool
	err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM delivery_refusals
		WHERE order_item_id = ? AND status IN ('pending','approved'))`, itemID).Scan(&open)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	return open, err
}

// CancelUndeliveredQuantity is OrderFulfillmentService::cancelUndeliveredQuantity (REM-003 / Owner Option A).
func (s *Service) CancelUndeliveredQuantity(ctx context.Context, in CancelUndeliveredInput) (*Item, error) {
	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := s.assertManageFulfillment(ctx, tx, in.OrderID, in.ActorID, in.ActorRole); err != nil {
		return nil, err
	}
	var (
		status  string
		agentID int64
	)
	if err := tx.QueryRowContext(ctx, `SELECT status, agent_id FROM orders WHERE id = ? FOR UPDATE`, in.OrderID).
		Scan(&status, &agentID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrOrderNotFound
		}
		return nil, err
	}
	item, err := lockItem(ctx, tx, in.OrderID, in.ItemID)
	if err != nil {
		return nil, err
	}

	blocker, err := s.cancellationBlocker(ctx, tx, in.OrderID, status, item)
	if err != nil {
		return nil, err
	}
	if blocker != nil {
		return nil, &Problem{Status: 422, Message: s.Messages("cancellation.not_eligible"),
			Errors: map[string]any{"code": *blocker}}
	}
	if item.FulfilledQuantity != in.ExpectedQuantity {
		return nil, &Problem{Status: 409, Message: s.Messages("cancellation.stale"),
			Errors: map[string]any{"code": "quantity_changed"}}
	}
	if in.Quantity < 1 || in.Quantity > item.FulfilledQuantity {
		return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.invalid_quantity")}
	}

	if err := s.reduceFulfillment(ctx, tx, in.OrderID, agentID, item, in.Quantity, in.ActorID, in.Reason); err != nil {
		return nil, err
	}

	// `remaining_active` is the reference's `$item->fresh()->fulfilled_quantity` — the POST-reduction value,
	// not the pre-change one minus the cancelled quantity.
	if err := s.writeSubjectActivity(ctx, tx, in.ActorID, "App\\Models\\OrderItem", item.ID,
		"order_item.undelivered_cancelled", in.Reason, map[string]any{
			"order_id": in.OrderID, "quantity_cancelled": in.Quantity,
			"remaining_active": item.FulfilledQuantity,
			"actor_role":       in.ActorRole,
		}); err != nil {
		return nil, err
	}

	if err := s.recomputeOrderStatus(ctx, tx, in.OrderID); err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.loadItemForResponse(ctx, item.ID)
}

// reduceFulfillment is OrderFulfillmentService::reduceFulfillment.
func (s *Service) reduceFulfillment(ctx context.Context, tx *sql.Tx, orderID, agentID int64, item *lockedItem,
	quantityReduced int, actorID int64, reason string) error {

	// R-03 / MAJOR-7 + MAJOR-10: never reverse a quantity increase while its additional-payment obligation is
	// still outstanding. A normal (non-locking) read: the item is already locked, and taking a financial-row
	// lock here would invert against Keuangan settlement and deadlock.
	if item.AdditionalPaymentID != nil {
		var status string
		err := tx.QueryRowContext(ctx, `SELECT status FROM order_additional_payments WHERE id = ?`,
			*item.AdditionalPaymentID).Scan(&status)
		if err == nil && status == "pending" {
			return &Problem{Status: 422, Message: s.Messages("fulfillment.pending_additional_payment_blocks_reduction")}
		}
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return err
		}
	}

	// B-01: the shortfall is a basis-requiring event. Snapshot BEFORE the quantity changes so the frozen unit
	// set is the pre-shortfall order basis; those units are then consumed and can never also be returned.
	fromQuantity := item.FulfilledQuantity
	snapshotID, provenance, err := s.ensureSnapshot(ctx, tx, orderID, actorID,
		triggerFulfillmentShortfall, "frozen")
	if err != nil {
		return err
	}

	// R-03 / MAJOR-8: the courier fee is not earned until delivery, so it tracks the active fulfilled quantity.
	// The per-unit rate is derived from the PRE-mutation snapshot (courier_fee_amount is historical).
	courierPerUnit := 0.0
	if item.FulfilledQuantity > 0 {
		courierPerUnit = item.CourierFee / float64(item.FulfilledQuantity)
	}
	releasedCourierFee := money.Round2(courierPerUnit * float64(quantityReduced))

	// REM-001/002: Order → Stock Request → inventory. The line's warehouse demand is locked BEFORE any stock
	// row. Only the UN-accepted part releases reservation; accepted units (already consumed from the
	// reservation, sitting in Shipping) return to Transit.
	var demand *demandRow
	if item.StockSource != "sub" {
		if demand, err = s.lockDemandForItem(ctx, tx, orderID, item.ID); err != nil {
			return err
		}
	}
	// F07: units a receipt reported damaged are held, not sellable — a reduction takes un-accepted units that
	// are NOT held first, then accepted ones; it can never release a held unit.
	releasable := quantityReduced
	if demand != nil {
		releasable = maxInt(0, demand.RemainingQty-demand.DamagedHoldQty)
		if quantityReduced > releasable+demand.FulfilledQty {
			return &Problem{Status: 422, Message: s.Messages("cancellation.discrepancy_unresolved"),
				Errors: map[string]any{"code": "discrepancy_unresolved"}}
		}
	}
	unaccepted := quantityReduced
	if demand != nil && releasable < unaccepted {
		unaccepted = releasable
	}
	acceptedPart := quantityReduced - unaccepted

	if item.StockSource == "sub" {
		// A Sub-sourced line releases in its own Sub ledger (reservation shrunk, physical stock unchanged).
		if err := inventory.ReduceSubReservation(ctx, tx, item.ID, quantityReduced, &actorID, "order_item_adjustment"); err != nil {
			return err
		}
	} else {
		if acceptedPart > 0 {
			if err := s.reverseAcceptedUnits(ctx, tx, agentID, orderID, actorID, item, acceptedPart); err != nil {
				return err
			}
		}
		if unaccepted > 0 {
			ref := inventory.ReservationReference{Type: "order_item_adjustment", ID: item.ID, CreatedBy: &actorID}
			if item.VariationID != nil {
				if err := inventory.ReleaseForVariation(ctx, tx, agentID, *item.VariationID, unaccepted, ref); err != nil {
					return err
				}
			} else {
				if err := inventory.ReleaseForProduct(ctx, tx, agentID, item.ProductID, unaccepted, ref); err != nil {
					return err
				}
			}
		}
		if demand != nil {
			if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items
				SET requested_qty = requested_qty - ?, remaining_qty = remaining_qty - ?,
					fulfilled_qty = fulfilled_qty - ?, updated_at = UTC_TIMESTAMP()
				WHERE id = ?`, quantityReduced, unaccepted, acceptedPart, demand.ID); err != nil {
				return err
			}
			if err := s.refreshStockRequestStatus(ctx, tx, demand.StockRequestID); err != nil {
				return err
			}
		}
	}

	// Order-level paid/overpaid snapshot BEFORE the total changes.
	var paidRaw, totalRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(paid_amount AS CHAR), CAST(total_amount AS CHAR)
		FROM orders WHERE id = ?`, orderID).Scan(&paidRaw, &totalRaw); err != nil {
		return err
	}
	totalValidPaid := parseDecimalString(paidRaw)
	previousOverpaid := totalValidPaid - parseDecimalString(totalRaw)
	if previousOverpaid < 0 {
		previousOverpaid = 0
	}

	newFulfilled := item.FulfilledQuantity - quantityReduced
	newStatus := item.Status
	if newFulfilled <= 0 {
		newStatus = "dibatalkan"
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items
		SET fulfilled_quantity = fulfilled_quantity - ?, cancelled_quantity = cancelled_quantity + ?,
			courier_fee_amount = CAST(GREATEST(CAST(courier_fee_amount AS SIGNED) - ? / 100.0, 0) AS DECIMAL(12,2)),
			status = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`,
		quantityReduced, quantityReduced, toCents(releasedCourierFee), newStatus, item.ID); err != nil {
		return err
	}
	item.FulfilledQuantity = newFulfilled
	item.CancelledQuantity += quantityReduced
	item.CourierFee = mathMax0(money.Round2(item.CourierFee - releasedCourierFee))
	item.Status = newStatus

	available, err := s.availableUnits(ctx, tx, snapshotID, provenance, item.ID)
	if err != nil {
		return err
	}
	// A re-increase followed by a second reduction can outrun the frozen unit set: the snapshot never gains
	// units. When the line has no unconsumed frozen units left, do NOT invent any — fall back to the legacy
	// reversal for that movement instead of double-counting a unit.
	// F01: the legacy fallback exists ONLY for the pre-dispatch shortfall snapshot (re-increase cycles). Once a
	// handover/return/cancellation snapshot rules, an exhausted unit pool must refuse — never pay a refund
	// outside the ledger.
	if provenance != "provisional_legacy" && available < quantityReduced {
		var trigger string
		if err := tx.QueryRowContext(ctx, `SELECT `+"`trigger`"+` FROM order_financial_snapshots WHERE id = ?`,
			snapshotID).Scan(&trigger); err != nil {
			return err
		}
		if trigger != triggerFulfillmentShortfall {
			return &Problem{Status: 409, Message: "Unit snapshot tidak mencukupi untuk pembatalan ini.",
				Errors: map[string]any{"code": "snapshot_units_exhausted"}}
		}
	}

	var (
		consumptionCents int64
		consumed         bool
	)
	if available >= quantityReduced {
		consumptionCents, consumed, err = s.consumeUnits(ctx, tx, snapshotID, provenance, item.ID, quantityReduced, triggerFulfillmentShortfall)
		if err != nil {
			return err
		}
	}

	if err := s.recalculateTotals(ctx, tx, orderID); err != nil {
		return err
	}

	var newTotalRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR) FROM orders WHERE id = ?`, orderID).
		Scan(&newTotalRaw); err != nil {
		return err
	}
	newOverpaid := totalValidPaid - parseDecimalString(newTotalRaw)
	if newOverpaid < 0 {
		newOverpaid = 0
	}
	refundAmount := money.Round2(newOverpaid - previousOverpaid)

	var adjustmentID *int64
	if refundAmount > 0 {
		if _, err := tx.ExecContext(ctx, `UPDATE order_items
			SET refund_quantity = refund_quantity + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
			quantityReduced, item.ID); err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO order_item_adjustments
			(order_item_id, adjusted_by, quantity_reduced, reason, refund_amount, refund_status,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			item.ID, actorID, quantityReduced, reason, money.String(refundAmount))
		if err != nil {
			return err
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		adjustmentID = &id
	}

	if consumed {
		if err := s.recordShortfallLedger(ctx, tx, orderID, item.ID, snapshotID, provenance,
			consumptionCents, fromQuantity, newFulfilled, adjustmentID, actorID, reason); err != nil {
			return err
		}
	}

	return s.reconcileTotals(ctx, tx, orderID, actorID)
}

// increaseFulfillment is OrderFulfillmentService::increaseFulfillment.
func (s *Service) increaseFulfillment(ctx context.Context, tx *sql.Tx, orderID, agentID int64, item *lockedItem,
	quantityAdded int, actorID int64, reason, additionalPaymentMethod string) error {

	var pendingRefund bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_item_adjustments
		WHERE order_item_id = ? AND refund_status = 'pending')`, item.ID).Scan(&pendingRefund); err != nil {
		return err
	}
	if pendingRefund {
		return &Problem{Status: 422, Message: s.Messages("fulfillment.pending_refund_blocks_increase")}
	}

	if item.AdditionalPaymentID != nil {
		var status string
		err := tx.QueryRowContext(ctx, `SELECT status FROM order_additional_payments WHERE id = ?`,
			*item.AdditionalPaymentID).Scan(&status)
		if err == nil && status == "pending" {
			return &Problem{Status: 422, Message: s.Messages("fulfillment.pending_additional_payment_blocks_increase")}
		}
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return err
		}
	}

	// R-03: a fully cancelled line is TERMINAL — do not resurrect it, and never reactivate its Sub reservation.
	if item.Status == "dibatalkan" || item.FulfilledQuantity <= 0 {
		return &Problem{Status: 422, Message: s.Messages("fulfillment.cannot_increase_cancelled")}
	}

	// An increase first RESTORES previously cancelled units before anything counts as genuinely additional.
	restoreCancelled := quantityAdded
	if item.CancelledQuantity < restoreCancelled {
		restoreCancelled = item.CancelledQuantity
	}
	trulyAdditional := quantityAdded - restoreCancelled

	courierPerUnit := 0.0
	if item.FulfilledQuantity > 0 {
		courierPerUnit = item.CourierFee / float64(item.FulfilledQuantity)
	}
	addedCourierFee := money.Round2(courierPerUnit * float64(quantityAdded))

	var demand *demandRow
	var err error
	if item.StockSource != "sub" {
		if demand, err = s.lockDemandForItem(ctx, tx, orderID, item.ID); err != nil {
			return err
		}
	}

	if item.StockSource == "sub" {
		if err := inventory.IncreaseSubReservation(ctx, tx, item.ID, quantityAdded, &actorID,
			"order_item_adjustment", item.label()); err != nil {
			return mapInsufficient(err, s)
		}
	} else {
		ref := inventory.ReservationReference{Type: "order_item_adjustment", ID: item.ID, CreatedBy: &actorID}
		if item.VariationID != nil {
			if err := inventory.ReserveForVariation(ctx, tx, agentID, *item.VariationID, quantityAdded, ref,
				item.label()); err != nil {
				return mapInsufficient(err, s)
			}
		} else {
			if err := inventory.ReserveForProduct(ctx, tx, agentID, item.ProductID, quantityAdded, ref,
				item.label()); err != nil {
				return mapInsufficient(err, s)
			}
		}
	}

	if demand != nil {
		if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items
			SET requested_qty = requested_qty + ?, remaining_qty = remaining_qty + ?,
				updated_at = UTC_TIMESTAMP() WHERE id = ?`,
			quantityAdded, quantityAdded, demand.ID); err != nil {
			return err
		}
		if err := s.refreshStockRequestStatus(ctx, tx, demand.StockRequestID); err != nil {
			return err
		}
	}

	var paidRaw, totalRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(paid_amount AS CHAR), CAST(total_amount AS CHAR)
		FROM orders WHERE id = ?`, orderID).Scan(&paidRaw, &totalRaw); err != nil {
		return err
	}
	totalValidPaid := parseDecimalString(paidRaw)
	previousRemaining := parseDecimalString(totalRaw) - totalValidPaid
	if previousRemaining < 0 {
		previousRemaining = 0
	}

	if _, err := tx.ExecContext(ctx, `UPDATE order_items
		SET fulfilled_quantity = fulfilled_quantity + ?, cancelled_quantity = cancelled_quantity - ?,
			additional_quantity = additional_quantity + ?,
			courier_fee_amount = CAST(CAST(courier_fee_amount AS DECIMAL(12,2)) + ? AS DECIMAL(12,2)),
			updated_at = UTC_TIMESTAMP()
		WHERE id = ?`,
		quantityAdded, restoreCancelled, trulyAdditional, money.String(addedCourierFee), item.ID); err != nil {
		return err
	}
	item.FulfilledQuantity += quantityAdded
	item.CancelledQuantity -= restoreCancelled
	item.AdditionalQuantity += trulyAdditional
	item.CourierFee = money.Round2(item.CourierFee + addedCourierFee)

	if err := s.recalculateTotals(ctx, tx, orderID); err != nil {
		return err
	}
	return s.reconcileAdditionalObligation(ctx, tx, orderID, agentID, item, previousRemaining,
		additionalPaymentMethod, reason, actorID)
}

// reconcileAdditionalObligation is OrderFulfillmentService::reconcileAdditionalObligation: when the order was
// NOT carrying an outstanding balance before the change AND the new total owes more than was paid, create
// exactly ONE pending obligation for the delta and link it to the line. Otherwise the added value simply folds
// into the order's own remaining balance — never a side-channel ledger row.
func (s *Service) reconcileAdditionalObligation(ctx context.Context, tx *sql.Tx, orderID, agentID int64,
	item *lockedItem, previousRemaining float64, method, reason string, actorID int64) error {

	var paidRaw, totalRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(paid_amount AS CHAR), CAST(total_amount AS CHAR)
		FROM orders WHERE id = ?`, orderID).Scan(&paidRaw, &totalRaw); err != nil {
		return err
	}
	totalValidPaid := parseDecimalString(paidRaw)
	newRemaining := parseDecimalString(totalRaw) - totalValidPaid
	if newRemaining < 0 {
		newRemaining = 0
	}

	if previousRemaining <= 0 && newRemaining > 0 {
		additionalAmount := money.Round2(newRemaining - previousRemaining)
		transactionID, err := s.initiateAdditionalPayment(ctx, tx, orderID, agentID, method, additionalAmount)
		if err != nil {
			return err
		}
		var transactionValue any
		if transactionID != 0 {
			transactionValue = transactionID
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO order_additional_payments
			(order_id, requested_by, method, payment_transaction_id, amount, reason, status,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, actorID, method, transactionValue, money.String(additionalAmount), reason)
		if err != nil {
			return err
		}
		additionalID, err := res.LastInsertId()
		if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_items SET additional_payment_id = ?,
			updated_at = UTC_TIMESTAMP() WHERE id = ?`, additionalID, item.ID); err != nil {
			return err
		}
	}
	return s.reconcileTotals(ctx, tx, orderID, actorID)
}

// demandRow is one locked stock_request_items row.
type demandRow struct {
	ID             int64
	StockRequestID int64
	FulfilledQty   int
	RemainingQty   int
	DamagedHoldQty int
}

// lockDemandForItem locks the order's Stock Request then this line's demand row (null for lines without Agent
// demand, a cancelled request, or a Sub-sourced line).
func (s *Service) lockDemandForItem(ctx context.Context, tx *sql.Tx, orderID, itemID int64) (*demandRow, error) {
	var (
		requestID int64
		status    string
	)
	err := tx.QueryRowContext(ctx, `SELECT id, status FROM stock_requests WHERE order_id = ? FOR UPDATE`, orderID).
		Scan(&requestID, &status)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if status == "cancelled" {
		return nil, nil
	}
	var d demandRow
	err = tx.QueryRowContext(ctx, `SELECT id, stock_request_id, fulfilled_qty, remaining_qty, damaged_hold_qty
		FROM stock_request_items WHERE stock_request_id = ? AND order_item_id = ? FOR UPDATE`, requestID, itemID).
		Scan(&d.ID, &d.StockRequestID, &d.FulfilledQty, &d.RemainingQty, &d.DamagedHoldQty)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &d, nil
}

// refreshStockRequestStatus recomputes pending/partial/fulfilled from a LOCKING read of the items.
func (s *Service) refreshStockRequestStatus(ctx context.Context, tx *sql.Tx, stockRequestID int64) error {
	var status string
	err := tx.QueryRowContext(ctx, `SELECT status FROM stock_requests WHERE id = ?`, stockRequestID).Scan(&status)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if status == "cancelled" {
		return nil
	}
	var remaining, fulfilled int
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(remaining_qty),0), COALESCE(SUM(fulfilled_qty),0)
		FROM stock_request_items WHERE stock_request_id = ? FOR UPDATE`, stockRequestID).
		Scan(&remaining, &fulfilled); err != nil {
		return err
	}
	newStatus := "pending"
	if remaining == 0 {
		newStatus = "fulfilled"
	} else if fulfilled > 0 {
		newStatus = "partial"
	}
	if newStatus == "fulfilled" {
		_, err = tx.ExecContext(ctx, `UPDATE stock_requests
			SET status = 'fulfilled', fulfilled_at = COALESCE(fulfilled_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, stockRequestID)
		return err
	}
	_, err = tx.ExecContext(ctx, `UPDATE stock_requests SET status = ?, fulfilled_at = NULL,
		updated_at = UTC_TIMESTAMP() WHERE id = ?`, newStatus, stockRequestID)
	return err
}

// reverseAcceptedUnits is InventoryCancellationService::reverseAcceptedUnits — accepted units sitting in
// Shipping return to Transit (their reservation was consumed on acceptance, so nothing is released twice).
func (s *Service) reverseAcceptedUnits(ctx context.Context, tx *sql.Tx, agentID, orderID, actorID int64,
	item *lockedItem, qty int) error {
	return s.reverseShippingToTransit(ctx, tx, agentID, orderID, actorID, cancelItem{
		ID: item.ID, ProductID: &item.ProductID, VariationID: item.VariationID, StockSource: item.StockSource,
		Status: item.Status, FulfilledQuantity: item.FulfilledQuantity,
	}, qty)
}

// recomputeOrderStatus is CourierService::recomputeOrderStatus — only ever moves the order FORWARD.
func (s *Service) recomputeOrderStatus(ctx context.Context, tx *sql.Tx, orderID int64) error {
	var status string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ? FOR UPDATE`, orderID).
		Scan(&status); err != nil {
		return err
	}
	if status != "diproses" && status != "dikirim" && status != "terkirim" {
		return nil
	}
	rows, err := tx.QueryContext(ctx, `SELECT status FROM order_items
		WHERE order_id = ? AND status <> 'dibatalkan'`, orderID)
	if err != nil {
		return err
	}
	var statuses []string
	for rows.Next() {
		var v string
		if err := rows.Scan(&v); err != nil {
			rows.Close()
			return err
		}
		statuses = append(statuses, v)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	if len(statuses) == 0 {
		return nil
	}
	newStatus := status
	allFinal := true
	anyMoving := false
	for _, v := range statuses {
		switch v {
		case "terkirim", "pengembalian", "kembali":
			anyMoving = true
		default:
			allFinal = false
			if v == "dikirim" {
				anyMoving = true
			}
		}
	}
	if allFinal {
		newStatus = "terkirim"
	} else if anyMoving {
		newStatus = "dikirim"
	}
	rank := map[string]int{"diproses": 1, "dikirim": 2, "terkirim": 3}
	if rank[newStatus] > rank[status] {
		_, err = tx.ExecContext(ctx, `UPDATE orders SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
			newStatus, orderID)
		if err != nil {
			return err
		}
		// WhatsAppLifecycleObserver::orderUpdated (item fulfillment can advance the Order
		// lifecycle) — dispatched in the same transaction with the same event semantics.
		if nerr := whatsappnotify.NotifyOrderStatusChanged(ctx, tx, orderID, status, newStatus); nerr != nil {
			slog.WarnContext(ctx, "fulfillment-driven order notification dispatch failed", "order_id", orderID, "error", nerr)
		}
		return nil
	}
	return nil
}

// ---- reschedule ------------------------------------------------------------------------------------

// RescheduleItemDeliveryDate is OrderFulfillmentService::rescheduleItemDeliveryDate.
func (s *Service) RescheduleItemDeliveryDate(ctx context.Context, in RescheduleInput) (*Item, error) {
	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := s.assertManageFulfillment(ctx, tx, in.OrderID, in.ActorID, in.ActorRole); err != nil {
		return nil, err
	}
	var status string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ? FOR UPDATE`, in.OrderID).
		Scan(&status); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrOrderNotFound
		}
		return nil, err
	}
	item, err := lockItem(ctx, tx, in.OrderID, in.ItemID)
	if err != nil {
		return nil, err
	}

	shortageRemainder := false
	if status == "dikirim" {
		shortageRemainder, err = s.lineHasOpenShortage(ctx, tx, in.OrderID, in.ItemID)
		if err != nil {
			return nil, err
		}
	}
	windowClosed := status == "dikirim" || status == "terkirim" || status == "pengembalian" ||
		status == "kembali" || status == "dibatalkan"
	if windowClosed && !shortageRemainder {
		return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.window_closed")}
	}
	if item.Status != "diterima" && item.Status != "diproses" {
		return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.window_closed")}
	}

	shipment, err := s.shipmentForDeliveryMethod(ctx, tx, item)
	if err != nil {
		return nil, err
	}
	if shipment == nil || !shipmentIsReschedulable(shipment) {
		method := "—"
		if shipment != nil {
			method = shipmentDeliveryMethodLabel(shipment)
		}
		return nil, &Problem{Status: 422,
			Message: s.Messages("fulfillment.reschedule_courier_online_only", "method", method)}
	}

	if in.Quantity != nil {
		if *in.Quantity < 1 || *in.Quantity > item.FulfilledQuantity {
			return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.invalid_quantity")}
		}
		if *in.Quantity < item.FulfilledQuantity {
			childID, err := s.splitItemForReschedule(ctx, tx, item, *in.Quantity, &in.NewDate, in.ActorID,
				in.ActorRole, in.Reason, true)
			if err != nil {
				return nil, err
			}
			if err := tx.Commit(); err != nil {
				return nil, err
			}
			return s.loadItemForResponse(ctx, childID)
		}
	}

	if err := s.splitShipmentIfShared(ctx, tx, item, in.ActorID); err != nil {
		return nil, err
	}

	previousDate := ""
	if item.RequestedDate != nil {
		previousDate = *item.RequestedDate
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items SET requested_delivery_date = ?,
		updated_at = UTC_TIMESTAMP() WHERE id = ?`, in.NewDate, item.ID); err != nil {
		return nil, err
	}
	item.RequestedDate = &in.NewDate

	if err := s.consolidateIntoShipmentForDate(ctx, tx, item, in.NewDate, in.ActorID, "reschedule"); err != nil {
		return nil, err
	}

	deliveryMethod := ""
	if item.ShipmentID != nil {
		var f *shipmentRow
		f, err = s.loadShipment(ctx, tx, *item.ShipmentID)
		if err != nil {
			return nil, err
		}
		if f != nil {
			deliveryMethod = shipmentDeliveryMethodLabel(f)
		}
	}
	if err := s.writeSubjectActivity(ctx, tx, in.ActorID, "App\\Models\\OrderItem", item.ID,
		"order_item.delivery_rescheduled", in.Reason, map[string]any{
			"order_id": in.OrderID, "from": nullableDate(previousDate), "to": in.NewDate,
			"actor_role": in.ActorRole, "delivery_method": deliveryMethod,
		}); err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.loadItemForResponse(ctx, item.ID)
}

// lineHasOpenShortage is FulfillmentShortageService::lineHasOpenShortage.
func (s *Service) lineHasOpenShortage(ctx context.Context, tx *sql.Tx, orderID, itemID int64) (bool, error) {
	root, err := s.rootItemOf(ctx, tx, orderID, itemID)
	if err != nil {
		return false, err
	}
	rows, err := tx.QueryContext(ctx, `SELECT id, split_from_order_item_id FROM order_items WHERE order_id = ?`,
		orderID)
	if err != nil {
		return false, err
	}
	rootByID := map[int64]*int64{}
	for rows.Next() {
		var id int64
		var parent sql.NullInt64
		if err := rows.Scan(&id, &parent); err != nil {
			rows.Close()
			return false, err
		}
		rootByID[id] = nullIntPtr(parent)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return false, err
	}

	var sameLine []int64
	for id := range rootByID {
		if rootOfItem(rootByID, id) == root {
			sameLine = append(sameLine, id)
		}
	}
	if len(sameLine) == 0 {
		return false, nil
	}

	placeholders := strings.TrimSuffix(strings.Repeat("?,", len(sameLine)), ",")
	args := make([]any, len(sameLine))
	for i, id := range sameLine {
		args[i] = id
	}
	var demandIDs []int64
	demandPlaceholders := ""
	demandArgs := []any{}
	if demandPlaceholders, demandArgs, demandIDs = "", nil, nil; true {
		drows, derr := tx.QueryContext(ctx, `SELECT id, remaining_qty FROM stock_request_items
			WHERE order_item_id IN (`+placeholders+`)`, args...)
		if derr != nil {
			return false, derr
		}
		anyRemaining := false
		for drows.Next() {
			var id int64
			var remaining int
			if err := drows.Scan(&id, &remaining); err != nil {
				drows.Close()
				return false, err
			}
			demandIDs = append(demandIDs, id)
			if remaining > 0 {
				anyRemaining = true
			}
		}
		drows.Close()
		if err := drows.Err(); err != nil {
			return false, err
		}
		if !anyRemaining {
			return false, nil
		}
	}
	if len(demandIDs) == 0 {
		return false, nil
	}
	demandPlaceholders = strings.TrimSuffix(strings.Repeat("?,", len(demandIDs)), ",")
	demandArgs = make([]any, len(demandIDs))
	for i, id := range demandIDs {
		demandArgs[i] = id
	}
	var exists bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM activity_logs
		WHERE subject_type = 'App\\Models\\StockRequestItem' AND subject_id IN (`+demandPlaceholders+`)
		AND event = 'stock_request.fulfillment_shortage')`, demandArgs...).Scan(&exists); err != nil {
		return false, err
	}
	return exists, nil
}

func (s *Service) rootItemOf(ctx context.Context, tx *sql.Tx, orderID, itemID int64) (int64, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, split_from_order_item_id FROM order_items WHERE order_id = ?`,
		orderID)
	if err != nil {
		return 0, err
	}
	defer rows.Close()
	rootByID := map[int64]*int64{}
	for rows.Next() {
		var id int64
		var parent sql.NullInt64
		if err := rows.Scan(&id, &parent); err != nil {
			return 0, err
		}
		rootByID[id] = nullIntPtr(parent)
	}
	if err := rows.Err(); err != nil {
		return 0, err
	}
	return rootOfItem(rootByID, itemID), nil
}

func rootOfItem(rootByID map[int64]*int64, id int64) int64 {
	current := id
	for depth := 0; depth < 10; depth++ {
		parent, ok := rootByID[current]
		if !ok || parent == nil {
			return current
		}
		current = *parent
	}
	return current
}

// shipmentRow is the subset of shipments the reschedule grouping needs.
type shipmentRow struct {
	ID                 int64
	OrderID            int64
	CourierID          *int64
	ShippingProviderID *int64
	ProviderCode       *string
	Status             string
	DeliveryMode       string
	SelfDeliveredBy    *int64
	ProofMediaID       *int64
	TrackingNumber     *string
	ShippedAt          sql.NullTime
	DeliveredAt        sql.NullTime
}

func (s *Service) loadShipment(ctx context.Context, tx *sql.Tx, shipmentID int64) (*shipmentRow, error) {
	var (
		sh                             shipmentRow
		courierID, providerID, selfDel sql.NullInt64
		providerCode, tracking         sql.NullString
		proofID                        sql.NullInt64
	)
	err := tx.QueryRowContext(ctx, `SELECT id, order_id, courier_id, shipping_provider_id,
			shipping_provider_code, status, delivery_mode, self_delivered_by_user_id, proof_media_id,
			tracking_number, shipped_at, delivered_at
		FROM shipments WHERE id = ? FOR UPDATE`, shipmentID).
		Scan(&sh.ID, &sh.OrderID, &courierID, &providerID, &providerCode, &sh.Status, &sh.DeliveryMode,
			&selfDel, &proofID, &tracking, &sh.ShippedAt, &sh.DeliveredAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	sh.CourierID = nullIntPtr(courierID)
	sh.ShippingProviderID = nullIntPtr(providerID)
	sh.ProviderCode = nullStringPtr(providerCode)
	sh.SelfDeliveredBy = nullIntPtr(selfDel)
	sh.ProofMediaID = nullIntPtr(proofID)
	sh.TrackingNumber = nullStringPtr(tracking)
	return &sh, nil
}

func shipmentIsReschedulable(sh *shipmentRow) bool {
	if sh.DeliveryMode == "self_sub" {
		return true
	}
	return sh.ProviderCode != nil && *sh.ProviderCode == "openroute"
}

func shipmentDeliveryMethodLabel(sh *shipmentRow) string {
	switch {
	case sh.DeliveryMode == "self_sub":
		return "self_delivery"
	case sh.ProviderCode != nil && *sh.ProviderCode == "openroute":
		return "courier_online"
	case sh.ProviderCode != nil && *sh.ProviderCode == "rajaongkir":
		return "expedition"
	case sh.ProviderCode != nil && *sh.ProviderCode == "pickup":
		return "pickup"
	case sh.ProviderCode != nil && *sh.ProviderCode != "":
		return *sh.ProviderCode
	default:
		return "—"
	}
}

// shipmentForDeliveryMethod: the item's own shipment, otherwise the order's first one. No shipment at all is
// non-reschedulable (allowlist).
func (s *Service) shipmentForDeliveryMethod(ctx context.Context, tx *sql.Tx, item *lockedItem) (*shipmentRow, error) {
	if item.ShipmentID != nil {
		return s.loadShipment(ctx, tx, *item.ShipmentID)
	}
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM shipments WHERE order_id = ? ORDER BY id LIMIT 1`,
		item.OrderID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.loadShipment(ctx, tx, id)
}

// splitShipmentIfShared gives the item its OWN copy of a shipment it currently shares with siblings, cloning
// the delivery path (self_sub + owning actor) so a rescheduled item is independent of theirs.
func (s *Service) splitShipmentIfShared(ctx context.Context, tx *sql.Tx, item *lockedItem, actorID int64) error {
	if item.ShipmentID == nil {
		return nil
	}
	var stillShared bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items
		WHERE shipment_id = ? AND id <> ?)`, *item.ShipmentID, item.ID).Scan(&stillShared); err != nil {
		return err
	}
	if !stillShared {
		return nil
	}
	original, err := s.loadShipment(ctx, tx, *item.ShipmentID)
	if err != nil {
		return err
	}
	if original == nil {
		return nil
	}
	var selfDelivered any
	if original.SelfDeliveredBy != nil {
		selfDelivered = *original.SelfDeliveredBy
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO shipments
		(order_id, shipping_provider_id, shipping_provider_code, origin_latitude, origin_longitude,
		 destination_latitude, destination_longitude, distance_km, provider_meta, status, delivery_mode,
		 self_delivered_by_user_id, created_at, updated_at)
		SELECT order_id, shipping_provider_id, shipping_provider_code, origin_latitude, origin_longitude,
		 destination_latitude, destination_longitude, distance_km, provider_meta, 'pending', delivery_mode,
		 ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
		FROM shipments WHERE id = ?`, selfDelivered, original.ID)
	if err != nil {
		return err
	}
	splitID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, splitID, item.ID); err != nil {
		return err
	}
	item.ShipmentID = &splitID
	return s.writeSubjectActivity(ctx, tx, actorID, "App\\Models\\Shipment", splitID,
		"shipment.split_for_reschedule", "", map[string]any{
			"order_id": original.OrderID, "from_shipment_id": original.ID, "order_item_id": item.ID,
		})
}

// consolidateIntoShipmentForDate is the in-transaction regroup (algorithm G): re-home the just-re-dated item
// onto the canonical shipment for the date and remove only the rows this emptied. Candidates are ALWAYS the
// same order, the same delivery semantics, and only still-mutable shipments.
func (s *Service) consolidateIntoShipmentForDate(ctx context.Context, tx *sql.Tx, item *lockedItem,
	newDate string, actorID int64, context string) error {

	currentID := item.ShipmentID
	if currentID == nil {
		return nil
	}
	current, err := s.loadShipment(ctx, tx, *currentID)
	if err != nil {
		return err
	}
	if current == nil || current.OrderID != item.OrderID {
		return nil
	}

	isSelfSub := current.DeliveryMode == "self_sub"
	var ownerID any
	if isSelfSub && current.SelfDeliveredBy != nil {
		ownerID = *current.SelfDeliveredBy
	}

	query := `SELECT s.id FROM shipments s
		WHERE s.order_id = ? AND s.id <> ? AND s.delivery_mode = ?
		AND EXISTS (SELECT 1 FROM order_items i WHERE i.shipment_id = s.id
			AND i.requested_delivery_date = ?)`
	args := []any{item.OrderID, *currentID, current.DeliveryMode, newDate}
	if isSelfSub {
		query += ` AND s.self_delivered_by_user_id <=> ?`
		args = append(args, ownerID)
	}
	query += ` ORDER BY s.id FOR UPDATE`

	rows, err := tx.QueryContext(ctx, query, args...)
	if err != nil {
		return err
	}
	var candidateIDs []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return err
		}
		candidateIDs = append(candidateIDs, id)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}

	var eligible []int64
	for _, id := range candidateIDs {
		candidate, err := s.loadShipment(ctx, tx, id)
		if err != nil {
			return err
		}
		if candidate == nil {
			continue
		}
		blocker, err := s.mergeBlocker(ctx, tx, candidate)
		if err != nil {
			return err
		}
		if blocker == nil {
			eligible = append(eligible, id)
		}
	}

	if len(eligible) > 0 {
		canonical := eligible[0]
		if err := s.mergeGroupInto(ctx, tx, item.OrderID, eligible, canonical); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, canonical, item.ID); err != nil {
			return err
		}
		item.ShipmentID = &canonical

		if err := s.writeSubjectActivity(ctx, tx, actorID, "App\\Models\\Shipment", canonical,
			"shipment.reused_for_reschedule", context, map[string]any{
				"order_id": item.OrderID, "order_item_id": item.ID,
				"requested_delivery_date": newDate, "released_shipment_id": *currentID,
			}); err != nil {
			return err
		}
	}

	// The shipment the item just left must not linger as an empty card. Only ever removed when it is genuinely
	// empty, still unassigned and undelivered; anything with an executor, a status or items stays.
	orphan, err := s.loadShipment(ctx, tx, *currentID)
	if err != nil {
		return err
	}
	if orphan != nil && orphan.CourierID == nil && orphan.SelfDeliveredBy == nil && orphan.Status == "pending" {
		var hasItems bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE shipment_id = ?)`,
			orphan.ID).Scan(&hasItems); err != nil {
			return err
		}
		if !hasItems {
			if _, err := tx.ExecContext(ctx, `DELETE FROM shipments WHERE id = ?`, orphan.ID); err != nil {
				return err
			}
		}
	}
	return nil
}

// mergeBlocker is ShipmentCanonicalizationService::mergeBlocker. nil = may be merged.
func (s *Service) mergeBlocker(ctx context.Context, tx *sql.Tx, sh *shipmentRow) (*string, error) {
	block := func(reason string) *string { return &reason }
	if sh.Status != "pending" {
		return block("status is " + sh.Status + " (only a pending shipment is mutable)"), nil
	}
	if sh.CourierID != nil {
		return block("a courier/executor is already assigned"), nil
	}
	if sh.SelfDeliveredBy != nil && sh.DeliveryMode != "self_sub" {
		return block("a self-delivery owner is set on a non-self_sub shipment"), nil
	}
	if sh.ProofMediaID != nil {
		return block("a delivery proof already exists"), nil
	}
	if sh.TrackingNumber != nil {
		return block("a tracking/resi number already exists"), nil
	}
	if sh.ShippedAt.Valid || sh.DeliveredAt.Valid {
		return block("delivery progression already happened"), nil
	}
	var exists bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM delivery_verifications WHERE shipment_id = ?)`,
		sh.ID).Scan(&exists); err != nil {
		return nil, err
	}
	if exists {
		return block("a delivery verification record exists"), nil
	}
	return nil, nil
}

// mergeGroupInto moves every item of the group onto the canonical shipment and removes the emptied rows, each
// re-verified under the lock (ShipmentCanonicalizationService::mergeGroupInto).
func (s *Service) mergeGroupInto(ctx context.Context, tx *sql.Tx, orderID int64, ids []int64, canonical int64) error {
	placeholders := strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")
	args := make([]any, 0, len(ids)+2)
	args = append(args, canonical, orderID)
	for _, id := range ids {
		args = append(args, id)
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP()
		WHERE order_id = ? AND shipment_id IN (`+placeholders+`) AND shipment_id <> ?`,
		append(args, canonical)...); err != nil {
		return err
	}
	for _, id := range ids {
		if id == canonical {
			continue
		}
		fresh, err := s.loadShipment(ctx, tx, id)
		if err != nil {
			return err
		}
		if fresh == nil {
			continue
		}
		if fresh.Status != "pending" || fresh.CourierID != nil || fresh.ProofMediaID != nil {
			return &Problem{Status: 500, Message: fmt.Sprintf("Refusing to delete shipment %d: it is no longer safe to remove.", id)}
		}
		var hasItems bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE shipment_id = ?)`,
			id).Scan(&hasItems); err != nil {
			return err
		}
		if hasItems {
			return &Problem{Status: 500, Message: fmt.Sprintf("Refusing to delete shipment %d: it is no longer safe to remove.", id)}
		}
		if _, err := tx.ExecContext(ctx, `DELETE FROM shipments WHERE id = ?`, id); err != nil {
			return err
		}
	}
	return nil
}

// splitItemForReschedule carves quantityMoved units off the item onto a brand-new OrderItem with its own fresh
// Shipment and the new date. subtotal_snapshot is split in proportion; agent_fee/sales_fee stay on the original
// (already recorded once in `commissions` against the original item id); the courier fee follows the moved units.
func (s *Service) splitItemForReschedule(ctx context.Context, tx *sql.Tx, item *lockedItem, quantityMoved int,
	newDate *string, actorID int64, actorRole, reason string, consolidate bool) (int64, error) {

	remainingQuantity := item.FulfilledQuantity - quantityMoved
	movedSubtotal := money.Round2(item.UnitPrice * float64(quantityMoved))

	courierPerUnit := 0.0
	if item.FulfilledQuantity > 0 {
		courierPerUnit = item.CourierFee / float64(item.FulfilledQuantity)
	}
	movedCourierFee := money.Round2(courierPerUnit * float64(quantityMoved))
	retainedCourierFee := money.Round2(item.CourierFee - movedCourierFee)

	var demand *demandRow
	var err error
	if item.StockSource != "sub" {
		if demand, err = s.lockStockRequestItemForSplit(ctx, tx, item, quantityMoved); err != nil {
			return 0, err
		}
	}

	var dateValue any
	if newDate != nil {
		dateValue = *newDate
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO order_items
		(order_id, split_from_order_item_id, product_id, product_variation_id, stock_source, sub_location_id,
		 product_name_snapshot, variation_label_snapshot, sku_snapshot, unit_price_snapshot,
		 agent_fee_amount, sales_fee_amount, courier_fee_amount, subtotal_snapshot,
		 original_quantity, fulfilled_quantity, requested_delivery_date, status, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		item.OrderID, item.ID, item.ProductID, nullableInt(item.VariationID), item.StockSource,
		nullableInt(item.SubLocationID), item.ProductName, nullableString(item.VariationLabel),
		nullableString(item.SKU), money.String(item.UnitPrice), money.String(movedCourierFee),
		money.String(movedSubtotal), quantityMoved, quantityMoved, dateValue, item.Status)
	if err != nil {
		return 0, err
	}
	childID, err := res.LastInsertId()
	if err != nil {
		return 0, err
	}

	if demand != nil {
		if err := s.applyStockRequestSplit(ctx, tx, demand, childID, item, quantityMoved); err != nil {
			return 0, err
		}
	}

	if err := s.assignFreshShipment(ctx, tx, childID, item.OrderID, item.StockSource, item.SubLocationID,
		actorID, "shipment.split_for_reschedule"); err != nil {
		return 0, err
	}

	child := &lockedItem{ID: childID, OrderID: item.OrderID, ProductID: item.ProductID,
		VariationID: item.VariationID, StockSource: item.StockSource, SubLocationID: item.SubLocationID}
	if err := s.reloadItemShipment(ctx, tx, child); err != nil {
		return 0, err
	}
	if consolidate {
		if err := s.consolidateIntoShipmentForDate(ctx, tx, child, derefStringPtr(newDate), actorID,
			"reschedule_split"); err != nil {
			return 0, err
		}
	}

	// R-03 / decision E: Sub inventory reconciliation — the source reservation is reduced by the moved
	// quantity, then the child receives its own ACTIVE reservation. Physical Sub stock is unchanged.
	if item.StockSource == "sub" {
		if err := inventory.ReduceSubReservation(ctx, tx, item.ID, quantityMoved, &actorID, "order_item_split"); err != nil {
			return 0, err
		}
		var subLocationID int64
		if item.SubLocationID != nil {
			subLocationID = *item.SubLocationID
		}
		if err := inventory.ReserveSubStock(ctx, tx, inventory.SubReservationInput{
			AgentID: itemAgentID(ctx, tx, item.OrderID), SubLocationID: subLocationID,
			OrderItemID: childID, ProductID: productOrNil(item), VariationID: item.VariationID,
			Quantity: quantityMoved, ReservedBy: &actorID, ItemName: item.label(),
		}); err != nil {
			return 0, err
		}
	}

	if _, err := tx.ExecContext(ctx, `UPDATE order_items
		SET original_quantity = original_quantity - ?, fulfilled_quantity = ?,
			subtotal_snapshot = CAST(CAST(subtotal_snapshot AS DECIMAL(12,2)) - ? AS DECIMAL(12,2)),
			courier_fee_amount = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`,
		quantityMoved, remainingQuantity, money.String(movedSubtotal), money.String(retainedCourierFee),
		item.ID); err != nil {
		return 0, err
	}
	item.OriginalQuantity -= quantityMoved
	item.FulfilledQuantity = remainingQuantity
	item.Subtotal = money.Round2(item.Subtotal - movedSubtotal)
	item.CourierFee = retainedCourierFee

	if err := s.writeSubjectActivity(ctx, tx, actorID, "App\\Models\\OrderItem", childID,
		"order_item.split_for_reschedule", reason, map[string]any{
			"order_id": item.OrderID, "from_order_item_id": item.ID,
			"quantity_moved": quantityMoved, "new_delivery_date": derefStringPtr(newDate),
			"actor_role": actorRole,
		}); err != nil {
		return 0, err
	}
	return childID, nil
}

// itemAgentID is a tiny cache-free read for the split path (the order row is already locked by the caller).
func itemAgentID(ctx context.Context, tx *sql.Tx, orderID int64) int64 {
	var agentID int64
	_ = tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, orderID).Scan(&agentID)
	return agentID
}

func productOrNil(item *lockedItem) *int64 {
	if item.VariationID != nil {
		return nil
	}
	id := item.ProductID
	return &id
}

func (s *Service) reloadItemShipment(ctx context.Context, tx *sql.Tx, item *lockedItem) error {
	var shipmentID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT shipment_id FROM order_items WHERE id = ?`, item.ID).
		Scan(&shipmentID); err != nil {
		return err
	}
	item.ShipmentID = nullIntPtr(shipmentID)
	return nil
}

// lockStockRequestItemForSplit validates + locks the order's Stock Request line BEFORE the split child exists,
// so a request whose unfulfilled remainder cannot cover the moved quantity is rejected without leaving
// inconsistent demand rows behind.
func (s *Service) lockStockRequestItemForSplit(ctx context.Context, tx *sql.Tx, item *lockedItem,
	quantityMoved int) (*demandRow, error) {

	var (
		requestID int64
		status    string
	)
	err := tx.QueryRowContext(ctx, `SELECT id, status FROM stock_requests WHERE order_id = ? FOR UPDATE`,
		item.OrderID).Scan(&requestID, &status)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if status == "cancelled" {
		return nil, nil
	}
	var d demandRow
	err = tx.QueryRowContext(ctx, `SELECT id, stock_request_id, fulfilled_qty, remaining_qty, damaged_hold_qty
		FROM stock_request_items WHERE stock_request_id = ? AND order_item_id = ? FOR UPDATE`, requestID, item.ID).
		Scan(&d.ID, &d.StockRequestID, &d.FulfilledQty, &d.RemainingQty, &d.DamagedHoldQty)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if d.RemainingQty < quantityMoved {
		return nil, &Problem{Status: 422, Message: s.Messages("fulfillment.split_stock_request_locked")}
	}
	return &d, nil
}

// applyStockRequestSplit moves the demand onto the split child, conserving requested/fulfilled/remaining.
func (s *Service) applyStockRequestSplit(ctx context.Context, tx *sql.Tx, demand *demandRow, childID int64,
	item *lockedItem, quantityMoved int) error {
	if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items
		SET requested_qty = requested_qty - ?, remaining_qty = remaining_qty - ?,
			updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		quantityMoved, quantityMoved, demand.ID); err != nil {
		return err
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO stock_request_items
		(stock_request_id, order_item_id, product_id, product_variation_id, sku_snapshot,
		 requested_qty, fulfilled_qty, remaining_qty, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		demand.StockRequestID, childID, item.ProductID, nullableInt(item.VariationID),
		nullableString(item.SKU), quantityMoved, quantityMoved)
	return err
}

// assignFreshShipment gives an item a brand-new pending shipment, cloning the order's destination/provider
// snapshot. A Sub-sourced child stays on the self-delivery path — self_sub + the owning Sales-Kurir-Sub, never
// a Kurir (courier_id stays NULL).
func (s *Service) assignFreshShipment(ctx context.Context, tx *sql.Tx, itemID, orderID int64, stockSource string,
	subLocationID *int64, actorID int64, event string) error {

	var referenceID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1`,
		orderID).Scan(&referenceID); err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	var (
		selfDeliveredID                                  sql.NullInt64
		providerID                                       sql.NullInt64
		providerCode                                     sql.NullString
		originLat, originLng, destLat, destLng, distance sql.NullString
		providerMeta                                     sql.NullString
	)
	if referenceID.Valid {
		if err := tx.QueryRowContext(ctx, `SELECT self_delivered_by_user_id, shipping_provider_id,
				shipping_provider_code, CAST(origin_latitude AS CHAR), CAST(origin_longitude AS CHAR),
				CAST(destination_latitude AS CHAR), CAST(destination_longitude AS CHAR),
				CAST(distance_km AS CHAR), provider_meta
			FROM shipments WHERE id = ?`, referenceID.Int64).
			Scan(&selfDeliveredID, &providerID, &providerCode, &originLat, &originLng, &destLat, &destLng,
				&distance, &providerMeta); err != nil {
			return err
		}
	}

	deliveryMode := "standard"
	var selfDelivered any
	if stockSource == "sub" {
		deliveryMode = "self_sub"
		if selfDeliveredID.Valid {
			selfDelivered = selfDeliveredID.Int64
		} else if subLocationID != nil {
			var owner sql.NullInt64
			if err := tx.QueryRowContext(ctx, `SELECT owner_user_id FROM warehouse_sub_locations WHERE id = ?`,
				*subLocationID).Scan(&owner); err != nil && !errors.Is(err, sql.ErrNoRows) {
				return err
			}
			if owner.Valid {
				selfDelivered = owner.Int64
			}
		}
	}

	nullable := func(v sql.NullString) any {
		if !v.Valid {
			return nil
		}
		return v.String
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO shipments
		(order_id, shipping_provider_id, shipping_provider_code, origin_latitude, origin_longitude,
		 destination_latitude, destination_longitude, distance_km, provider_meta, status, delivery_mode,
		 self_delivered_by_user_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, nullableInt(nullIntPtr(providerID)), nullableString(nullStringPtr(providerCode)),
		nullable(originLat), nullable(originLng), nullable(destLat), nullable(destLng), nullable(distance),
		nullable(providerMeta), deliveryMode, selfDelivered)
	if err != nil {
		return err
	}
	shipmentID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, shipmentID, itemID); err != nil {
		return err
	}
	return s.writeSubjectActivity(ctx, tx, actorID, "App\\Models\\Shipment", shipmentID, event, "",
		map[string]any{"order_id": orderID, "order_item_id": itemID})
}

// loadItemForResponse reloads one item in the shape OrderItemResource renders (no shipment relation loaded:
// the fulfillment controller returns `$item->fresh()`).
func (s *Service) loadItemForResponse(ctx context.Context, itemID int64) (*Item, error) {
	row := s.Database.QueryRowContext(ctx, `SELECT i.id, i.product_id, i.product_variation_id, i.stock_source,
			i.sub_location_id, i.product_name_snapshot, i.variation_label_snapshot, i.sku_snapshot,
			CAST(i.unit_price_snapshot AS CHAR), CAST(i.agent_fee_amount AS CHAR),
			CAST(i.sales_fee_amount AS CHAR), CAST(i.courier_fee_amount AS CHAR),
			CAST(i.subtotal_snapshot AS CHAR), i.original_quantity, i.fulfilled_quantity,
			i.cancelled_quantity, i.returned_quantity, i.refund_quantity, i.additional_quantity,
			i.status, CAST(i.requested_delivery_date AS CHAR), i.shipment_id
		FROM order_items i WHERE i.id = ?`, itemID)
	var (
		it                       Item
		variation, subLocation   sql.NullInt64
		label, sku, requested    sql.NullString
		unit, agentFee, salesFee sql.NullString
		courierFee, subtotal     sql.NullString
		shipmentID               sql.NullInt64
	)
	if err := row.Scan(&it.ID, &it.ProductID, &variation, &it.StockSource, &subLocation,
		&it.ProductName, &label, &sku, &unit, &agentFee, &salesFee, &courierFee, &subtotal,
		&it.OriginalQuantity, &it.FulfilledQuantity, &it.CancelledQuantity, &it.ReturnedQuantity,
		&it.RefundQuantity, &it.AdditionalQuantity, &it.Status, &requested, &shipmentID); err != nil {
		return nil, err
	}
	it.ProductVariationID = nullIntPtr(variation)
	it.SubLocationID = nullIntPtr(subLocation)
	it.VariationLabel = nullStringPtr(label)
	it.SKU = nullStringPtr(sku)
	it.UnitPrice = parseString(unit.String)
	it.AgentFee = parseString(agentFee.String)
	it.SalesFee = parseString(salesFee.String)
	it.CourierFee = parseString(courierFee.String)
	it.Subtotal = parseString(subtotal.String)
	it.RequestedDate = nullStringPtr(requested)
	it.ShipmentID = nullIntPtr(shipmentID)
	return &it, nil
}

// writeSubjectActivity is ActivityLogger::log for an arbitrary subject (Order, OrderItem, Shipment, ...).
func (s *Service) writeSubjectActivity(ctx context.Context, tx *sql.Tx, actorID int64, subjectType string,
	subjectID int64, event, description string, properties map[string]any) error {

	var props any
	if len(properties) > 0 {
		redacted := redactProperties(properties)
		raw, err := json.Marshal(redacted)
		if err != nil {
			return err
		}
		props = string(raw)
	}
	var desc any
	if description != "" {
		desc = description
	}
	var ip, ua any
	if s.IP != "" {
		ip = s.IP
	}
	if s.UserAgent != "" {
		ua = s.UserAgent
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())`,
		actorID, subjectType, subjectID, event, desc, props, ip, ua)
	return err
}

func mathMax0(v float64) float64 {
	if v < 0 {
		return 0
	}
	return v
}

func nullableDate(v string) any {
	if v == "" {
		return nil
	}
	return v
}

var _ = strconv.Itoa
