package orders

// Order lifecycle — status transitions and cancellation with full inventory reversal.
//
// Ported from OrderService::{updateStatus,cancel} + InventoryCancellationService + CancellationFinancialService
// + OrderFinancialSnapshotService + StockService::{releaseProduct,releaseVariation}.
//
// Two state machines, both locked: the ORDER (TRANSITIONS) and each ORDER ITEM (the same map). The per-item loop
// mirrors the order's new status but an item already 'dibatalkan' stays there — the bulk override never writes an
// item back into the forward flow.
//
// The inventory reverse on cancellation is idempotent per ITEM via inventory_cancellation_reversals (a locking
// existence check), takes the canonical Agent capacity locks BEFORE the per-item loop, releases only what is
// still releasable, and returns ACCEPTED (Shipping) units to Transit with cancellation_release movements.
//
// The financial side books an OrderSalesReduction, freezes an OrderFinancialSnapshot (integer-cent largest-
// remainder allocation of the voucher across units), and creates a RefundObligation for what was actually PAID —
// all inside the SAME transaction under the Order lock.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// orderTransitions is Order::TRANSITIONS (and OrderItem::TRANSITIONS — identical maps).
var orderTransitions = map[string][]string{
	"diterima":     {"diproses", "dibatalkan"},
	"diproses":     {"dikirim", "dibatalkan"},
	"dikirim":      {"terkirim"},
	"terkirim":     {"pengembalian"},
	"pengembalian": {"kembali"},
	"dibatalkan":   {},
	"kembali":      {},
}

// transitionError renders InvalidStateTransitionException's message through the catalogue, with the entity name.
func (s *Service) transitionError(entity, from, to string) string {
	msg := s.Messages("order.invalid_status_transition", "entity", entity, "from", from, "to", to)
	return msg
}

func canTransition(from, to string) bool {
	for _, next := range orderTransitions[from] {
		if next == to {
			return true
		}
	}
	return false
}

func transitionMessage(from, to string) string {
	return "Transisi status :entity dari \"" + from + "\" ke \"" + to + "\" tidak diperbolehkan."
}

// UpdateStatusInput is the PATCH /orders/{order}/status request.
type UpdateStatusInput struct {
	ActorID   int64
	ActorRole string
	OrderID   int64
	NewStatus string
}

// UpdateStatus is OrderService::updateStatus.
func (s *Service) UpdateStatus(ctx context.Context, in UpdateStatusInput) (*Order, error) {
	if in.NewStatus != "diproses" && in.NewStatus != "dikirim" && in.NewStatus != "terkirim" {
		return nil, &Problem{
			Status:  422,
			Message: s.Messages("order.status_endpoint_required", "status", in.NewStatus),
		}
	}

	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if err := s.assertStatusAuthority(ctx, tx, in); err != nil {
		return nil, err
	}

	var (
		status, paymentStatus string
		agentID               int64
		paymentTypeNull       sql.NullString
	)
	err = tx.QueryRowContext(ctx, `SELECT o.status, o.payment_status, o.agent_id, pm.type
		FROM orders o
		LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
		WHERE o.id = ? FOR UPDATE`, in.OrderID).
		Scan(&status, &paymentStatus, &agentID, &paymentTypeNull)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrOrderNotFound
	}
	if err != nil {
		return nil, err
	}
	paymentType := paymentTypeNull.String

	if !canTransition(status, in.NewStatus) {
		return nil, &Problem{Status: 422, Message: s.transitionError("order", status, in.NewStatus),
			Errors: map[string]any{"from": status, "to": in.NewStatus}}
	}

	// Payment eligibility is state-dependent: read it AFTER the row lock so a concurrent payment mutation
	// cannot leave this decision stale.
	isCOD := paymentType == "cod"
	paymentReady := paymentStatus == "paid" ||
		(paymentType == "" && paymentStatus == "partially_paid")
	if in.NewStatus == "diproses" && !isCOD && !paymentReady {
		return nil, &Problem{Status: 422, Message: s.Messages("order.payment_not_verified")}
	}

	if in.NewStatus == "dikirim" {
		if err := s.assertDispatchReady(ctx, tx, in.OrderID); err != nil {
			return nil, err
		}
	}
	if in.NewStatus == "terkirim" {
		if err := s.assertNoOpenRefusals(ctx, tx, in.OrderID); err != nil {
			return nil, err
		}
	}

	previousStatus := status
	if _, err := tx.ExecContext(ctx, `UPDATE orders SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		in.NewStatus, in.OrderID); err != nil {
		return nil, err
	}

	// A manual move to diproses creates the Stock Request (Agent-sourced items only).
	if in.NewStatus == "diproses" && previousStatus != "diproses" {
		if _, err := s.createStockRequest(ctx, tx, in.OrderID, agentID, in.ActorID); err != nil {
			return nil, err
		}
	}

	// Per-item status mirrors the order; a Sub-sourced item never advances to dikirim/terkirim through this
	// generic override (R-03: its own owner ships it, with proof).
	if err := s.mirrorItemStatus(ctx, tx, in.OrderID, in.NewStatus); err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	order, err := s.loadOrder(ctx, in.OrderID, 0)
	if err != nil {
		return nil, err
	}
	order.LoadActors = true
	order.LoadItemShipment = true
	return order, nil
}

// assertStatusAuthority is OrderPolicy::updateStatus.
func (s *Service) assertStatusAuthority(ctx context.Context, tx *sql.Tx, in UpdateStatusInput) error {
	if in.ActorRole == "super_admin" {
		return nil
	}
	if in.ActorRole != "agen" && in.ActorRole != "admin" {
		return &Problem{Status: 403, Message: "This action is unauthorized."}
	}
	var agentID int64
	if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, in.OrderID).Scan(&agentID); err != nil {
		return err
	}
	var actorAgent sql.NullInt64
	if err := s.Database.QueryRowContext(ctx, `SELECT agent_id FROM users WHERE id = ?`, in.ActorID).
		Scan(&actorAgent); err != nil {
		return err
	}
	if !actorAgent.Valid || actorAgent.Int64 != agentID {
		return &Problem{Status: 403, Message: "This action is unauthorized."}
	}
	return nil
}

// mirrorItemStatus advances every item that can follow, stamping the first handover (B-01 basis event).
func (s *Service) mirrorItemStatus(ctx context.Context, tx *sql.Tx, orderID int64, newStatus string) error {
	rows, err := tx.QueryContext(ctx, `SELECT id, stock_source, status, handed_over_at IS NULL
		FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE`, orderID)
	if err != nil {
		return err
	}
	type itemRow struct {
		id          int64
		stockSource string
		status      string
		noHandover  bool
	}
	var items []itemRow
	for rows.Next() {
		var it itemRow
		if err := rows.Scan(&it.id, &it.stockSource, &it.status, &it.noHandover); err != nil {
			rows.Close()
			return err
		}
		items = append(items, it)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}

	for _, it := range items {
		if !canTransition(it.status, newStatus) {
			continue // a dibatalkan item stays there; the override never writes it forward
		}
		if (newStatus == "dikirim" || newStatus == "terkirim") && it.stockSource == "sub" {
			return &Problem{Status: 422, Message: s.Messages("order.sub_item_requires_owner_shipment")}
		}
		if newStatus == "dikirim" && it.noHandover {
			if _, err := tx.ExecContext(ctx, `UPDATE order_items
				SET status = ?, handed_over_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?`,
				newStatus, it.id); err != nil {
				return err
			}
		} else {
			if _, err := tx.ExecContext(ctx, `UPDATE order_items
				SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`, newStatus, it.id); err != nil {
				return err
			}
		}
	}
	return nil
}

// assertDispatchReady is the REM-003 guard: an unpaid expedition shipment or an unassigned one cannot be
// dispatched by the office bulk override. The Kurir Toko acceptance check needs the courier dispatch service
// (later checkpoint) — recorded as an explicit gap, not silently skipped.
func (s *Service) assertDispatchReady(ctx context.Context, tx *sql.Tx, orderID int64) error {
	var (
		paymentStatus string
		paymentType   sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT o.payment_status, pm.type FROM orders o
		LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id WHERE o.id = ?`, orderID).
		Scan(&paymentStatus, &paymentType); err != nil {
		return err
	}
	if paymentType.Valid && paymentType.String == "cod" {
		return nil
	}
	if paymentStatus != "paid" {
		return &Problem{Status: 422, Message: s.Messages("order.payment_not_verified")}
	}

	var unassigned int
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM shipments
		WHERE order_id = ? AND shipping_provider_code = 'rajaongkir' AND courier_id IS NULL`, orderID).
		Scan(&unassigned); err != nil {
		return err
	}
	if unassigned > 0 {
		return &Problem{Status: 422, Message: "Ekspedisi belum memiliki kurir yang ditugaskan."}
	}
	return nil
}

// assertNoOpenRefusals is the F02 guard for terkirim: refused goods are still in custody.
func (s *Service) assertNoOpenRefusals(ctx context.Context, tx *sql.Tx, orderID int64) error {
	var open int
	err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM order_items oi
		JOIN delivery_refusals dr ON dr.order_item_id = oi.id AND dr.resolved_at IS NULL
		WHERE oi.order_id = ? AND oi.status = 'dikirim'`, orderID).Scan(&open)
	if err != nil {
		if strings.Contains(err.Error(), "doesn't exist") {
			return nil
		}
		return err
	}
	if open > 0 {
		return &Problem{Status: 422, Message: s.Messages("refusal.open_blocks_delivery")}
	}
	return nil
}

// CancelInput is the POST /orders/{order}/cancel request.
type CancelInput struct {
	ActorID   int64
	ActorRole string
	OrderID   int64
	Reason    string
}

// Cancel is OrderService::cancel.
func (s *Service) Cancel(ctx context.Context, in CancelInput) (*Order, error) {
	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if err := s.assertCancelAuthority(ctx, tx, in); err != nil {
		return nil, err
	}

	var (
		status, paymentStatus string
		agentID               int64
		paymentTypeNull       sql.NullString
	)
	err = tx.QueryRowContext(ctx, `SELECT o.status, o.payment_status, o.agent_id, pm.type
		FROM orders o LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
		WHERE o.id = ? FOR UPDATE`, in.OrderID).
		Scan(&status, &paymentStatus, &agentID, &paymentTypeNull)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrOrderNotFound
	}
	if err != nil {
		return nil, err
	}
	paymentType := paymentTypeNull.String

	if !canTransition(status, "dibatalkan") {
		return nil, &Problem{Status: 422, Message: s.transitionError("order", status, "dibatalkan"),
			Errors: map[string]any{"from": status, "to": "dibatalkan"}}
	}
	// COD has nothing to verify, so it can still be cancelled while being processed.
	allowed := []string{"diterima"}
	if paymentType == "cod" {
		allowed = []string{"diterima", "diproses"}
	}
	permitted := false
	for _, candidate := range allowed {
		if status == candidate {
			permitted = true
		}
	}
	if !permitted {
		return nil, &Problem{Status: 422, Message: s.transitionError("order", status, "dibatalkan"),
			Errors: map[string]any{"from": status, "to": "dibatalkan"}}
	}

	// Step 1: reverse the inventory (releases reservations, cancels the Stock Request).
	if err := s.reverseOrderInventory(ctx, tx, in.OrderID, agentID, in.ActorID); err != nil {
		return nil, err
	}

	// Item statuses mirror the cancellation.
	if _, err := tx.ExecContext(ctx, `UPDATE order_items
		SET status = 'dibatalkan',
			cancelled_quantity = cancelled_quantity + GREATEST(CAST(original_quantity AS SIGNED)
				- CAST(cancelled_quantity AS SIGNED) - CAST(returned_quantity AS SIGNED), 0),
			updated_at = UTC_TIMESTAMP()
		WHERE order_id = ? AND status IN ('diterima','diproses')`, in.OrderID); err != nil {
		return nil, err
	}

	if _, err := tx.ExecContext(ctx, `UPDATE orders
		SET status = 'dibatalkan', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancellation_reason = ?,
			updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, in.ActorID, in.Reason, in.OrderID); err != nil {
		return nil, err
	}

	// Step 2: the financial finalization — in the SAME transaction, under the same Order lock.
	if err := s.finalizeCancellationFinancials(ctx, tx, in.OrderID, in.ActorID); err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	order, err := s.loadOrder(ctx, in.OrderID, 0)
	if err != nil {
		return nil, err
	}
	order.LoadActors = true
	order.LoadItemShipment = true
	return order, nil
}

// assertCancelAuthority is OrderPolicy::cancel: super_admin anywhere; agen/admin on their own branch; korsal if
// the order is theirs; sales/sales-kurir-sub if the order is their sale; konsumen if they own the order.
func (s *Service) assertCancelAuthority(ctx context.Context, tx *sql.Tx, in CancelInput) error {
	if in.ActorRole == "super_admin" {
		return nil
	}
	var konsumenID, agentID int64
	var korsalID, salesID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT konsumen_id, agent_id, korsal_id, sales_id FROM orders WHERE id = ?`,
		in.OrderID).Scan(&konsumenID, &agentID, &korsalID, &salesID); err != nil {
		return err
	}

	switch in.ActorRole {
	case "agen", "admin":
		var actorAgent sql.NullInt64
		if err := s.Database.QueryRowContext(ctx, `SELECT agent_id FROM users WHERE id = ?`, in.ActorID).
			Scan(&actorAgent); err != nil {
			return err
		}
		if actorAgent.Valid && actorAgent.Int64 == agentID {
			return nil
		}
	case "korsal":
		if korsalID.Valid && korsalID.Int64 == in.ActorID {
			return nil
		}
	case "sales", "sales-kurir-sub":
		if salesID.Valid && salesID.Int64 == in.ActorID {
			return nil
		}
	case "konsumen":
		if konsumenID == in.ActorID {
			return nil
		}
	}
	return &Problem{Status: 403, Message: "This action is unauthorized."}
}

// reverseOrderInventory is InventoryCancellationService::reverseOrder.
//
// Idempotent per item (inventory_cancellation_reversals), with the Agent capacity locks taken in canonical order
// BEFORE the per-item loop — the same ordering checkout and replenishment use.
func (s *Service) reverseOrderInventory(ctx context.Context, tx *sql.Tx, orderID, agentID, actorID int64) error {
	// The Stock Request (if any) is locked first: Order → Stock Request → inventory.
	var requestID int64
	var hasRequest bool
	err := tx.QueryRowContext(ctx, `SELECT id FROM stock_requests WHERE order_id = ? FOR UPDATE`, orderID).
		Scan(&requestID)
	if err == nil {
		hasRequest = true
	} else if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	items, err := s.loadCancelItems(ctx, tx, orderID)
	if err != nil {
		return err
	}

	// Canonical Agent capacity prelock over every non-Sub item.
	targets := make([]inventory.AgentTarget, 0, len(items))
	seen := map[string]bool{}
	for _, item := range items {
		if item.StockSource == "sub" {
			continue
		}
		key := targetKeyOf(item.VariationID, item.ProductID)
		if !seen[key] {
			seen[key] = true
			targets = append(targets, inventory.AgentTarget{ProductID: item.ProductID, VariationID: item.VariationID})
		}
	}
	if len(targets) > 0 {
		if err := inventory.LockAgentReservationTargets(ctx, tx, agentID, targets); err != nil {
			return err
		}
	}

	for _, item := range items {
		if !canTransition(item.Status, "dibatalkan") {
			continue
		}
		if err := s.reverseItem(ctx, tx, agentID, orderID, actorID, item, requestID, hasRequest); err != nil {
			return err
		}
	}

	if hasRequest {
		var status string
		if err := tx.QueryRowContext(ctx, `SELECT status FROM stock_requests WHERE id = ? FOR UPDATE`, requestID).
			Scan(&status); err != nil {
			return err
		}
		if status != "cancelled" {
			if _, err := tx.ExecContext(ctx, `UPDATE stock_requests
				SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
				WHERE id = ?`, requestID); err != nil {
				return err
			}
		}
	}
	return nil
}

type cancelItem struct {
	ID                int64
	ProductID         *int64
	VariationID       *int64
	StockSource       string
	Status            string
	FulfilledQuantity int
	CancelledQuantity int
	ReturnedQuantity  int
}

func (s *Service) loadCancelItems(ctx context.Context, tx *sql.Tx, orderID int64) ([]cancelItem, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, product_id, product_variation_id, stock_source, status,
			fulfilled_quantity, cancelled_quantity, returned_quantity
		FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []cancelItem
	for rows.Next() {
		var (
			it                     cancelItem
			productID, variationID sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &productID, &variationID, &it.StockSource, &it.Status,
			&it.FulfilledQuantity, &it.CancelledQuantity, &it.ReturnedQuantity); err != nil {
			return nil, err
		}
		it.ProductID = nullIntPtr(productID)
		it.VariationID = nullIntPtr(variationID)
		out = append(out, it)
	}
	return out, rows.Err()
}

func targetKeyOf(variationID, productID *int64) string {
	if variationID != nil {
		return "v:" + strconv.FormatInt(*variationID, 10)
	}
	if productID != nil {
		return "p:" + strconv.FormatInt(*productID, 10)
	}
	return ""
}

// reverseItem reverses ONE item's inventory, idempotently.
func (s *Service) reverseItem(ctx context.Context, tx *sql.Tx, agentID, orderID, actorID int64,
	item cancelItem, requestID int64, hasRequest bool) error {

	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM inventory_cancellation_reversals WHERE order_item_id = ? FOR UPDATE`,
		item.ID).Scan(&existing)
	if err == nil {
		return nil // already reversed
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	if item.StockSource == "sub" {
		// Sub-sourced: release the Sub reservation (physical Sub stock unchanged); nothing in Agent stock.
		if err := inventory.ReleaseSubStock(ctx, tx, item.ID, &actorID, "order_cancellation"); err != nil {
			return err
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO inventory_cancellation_reversals
			(agent_id, order_id, order_item_id, fulfilled_quantity, released_quantity, processed_by, created_at, updated_at)
			VALUES (?, ?, ?, 0, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, agentID, orderID, item.ID, actorID)
		return err
	}

	// How much of this line was fulfilled through the Stock Request vs still outstanding.
	var fulfilled, remaining int
	var hasRequestItem bool
	if hasRequest {
		err := tx.QueryRowContext(ctx, `SELECT fulfilled_qty, remaining_qty FROM stock_request_items
			WHERE order_item_id = ? FOR UPDATE`, item.ID).Scan(&fulfilled, &remaining)
		if err == nil {
			hasRequestItem = true
		} else if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
	}
	if !hasRequestItem {
		remaining = maxInt(0, item.FulfilledQuantity-item.CancelledQuantity-item.ReturnedQuantity)
	}
	released := remaining

	if fulfilled > 0 {
		// ACCEPTED units sit in Shipping; they return to Transit (reservation was consumed on acceptance).
		if err := s.reverseShippingToTransit(ctx, tx, agentID, orderID, actorID, item, fulfilled); err != nil {
			return err
		}
	}
	if released > 0 {
		ref := inventory.ReservationReference{Type: "order_cancellation", ID: orderID, CreatedBy: &actorID}
		if item.VariationID != nil {
			if err := inventory.ReleaseForVariation(ctx, tx, agentID, *item.VariationID, released, ref); err != nil {
				return err
			}
		} else if item.ProductID != nil {
			if err := inventory.ReleaseForProduct(ctx, tx, agentID, *item.ProductID, released, ref); err != nil {
				return err
			}
		}
	}

	_, err = tx.ExecContext(ctx, `INSERT INTO inventory_cancellation_reversals
		(agent_id, order_id, order_item_id, fulfilled_quantity, released_quantity,
		 processed_by, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, orderID, item.ID, fulfilled, released, actorID)
	return err
}

// reverseShippingToTransit moves ACCEPTED units back to Transit with cancellation_release movements.
func (s *Service) reverseShippingToTransit(ctx context.Context, tx *sql.Tx, agentID, orderID, actorID int64,
	item cancelItem, qty int) error {

	clause, args := bucketClause(item.ProductID, item.VariationID)
	var shippingID int64
	var shippingQty int
	err := tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = 'shipping' AND sub_location_id IS NULL AND `+clause+` FOR UPDATE`,
		append([]any{agentID}, args...)...).Scan(&shippingID, &shippingQty)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && shippingQty < qty) {
		return &Problem{Status: 422, Message: "Stok Shipping sudah tidak tersedia untuk pembatalan; gunakan return."}
	}
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity - ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, qty, shippingID); err != nil {
		return err
	}

	var transitID int64
	err = tx.QueryRowContext(ctx, `SELECT id FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = 'transit' AND sub_location_id IS NULL AND `+clause+` FOR UPDATE`,
		append([]any{agentID}, args...)...).Scan(&transitID)
	if errors.Is(err, sql.ErrNoRows) {
		res, ierr := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks
			(agent_id, product_id, product_variation_id, stock_type, quantity, created_at, updated_at)
			VALUES (?, ?, ?, 'transit', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			agentID, nullableInt(item.ProductID), nullableInt(item.VariationID))
		if ierr != nil {
			return ierr
		}
		if transitID, err = res.LastInsertId(); err != nil {
			return err
		}
	} else if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity + ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, qty, transitID); err != nil {
		return err
	}

	note := fmt.Sprintf("order_item=%d", item.ID)
	movements := []struct {
		stockType string
		quantity  int
	}{
		{"shipping", -qty},
		{"transit", qty},
	}
	for _, m := range movements {
		if _, err := tx.ExecContext(ctx, `INSERT INTO stock_movements
			(agent_id, product_id, product_variation_id, type, quantity, stock_type,
			 reference_type, reference_id, note, created_by, created_at)
			VALUES (?, ?, ?, 'cancellation_release', ?, ?, 'App\\Models\\Order', ?, ?, ?, UTC_TIMESTAMP())`,
			agentID, nullableInt(item.ProductID), nullableInt(item.VariationID),
			m.quantity, m.stockType, orderID, note, actorID); err != nil {
			return err
		}
	}
	return nil
}

func bucketClause(productID, variationID *int64) (string, []any) {
	if variationID != nil {
		return "product_variation_id = ? AND product_id IS NULL", []any{*variationID}
	}
	return "product_id = ? AND product_variation_id IS NULL", []any{*productID}
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}

// ---- financial finalization -----------------------------------------------------------------------

// finalizeCancellationFinancials is CancellationFinancialService::{bookCancellationReduction,finalize} in one
// transaction pass: freeze the basis, book the reduction, create the refund obligation for what was PAID.
func (s *Service) finalizeCancellationFinancials(ctx context.Context, tx *sql.Tx, orderID, actorID int64) error {
	// Idempotency: an existing cancellation reduction means step 1 already ran.
	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM order_sales_reductions
		WHERE order_id = ? AND source_type = 'cancellation' FOR UPDATE`, orderID).Scan(&existing)
	if err == nil {
		return s.markFinalized(ctx, tx, orderID, actorID)
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	// Freeze the financial basis if any unit was ever billable (OrderFinancialSnapshotService::ensure).
	var hasBillable bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items
		WHERE order_id = ? AND fulfilled_quantity > 0)`, orderID).Scan(&hasBillable); err != nil {
		return err
	}
	if hasBillable {
		if err := s.ensureFinancialSnapshot(ctx, tx, orderID, actorID); err != nil {
			return err
		}
	}

	// Metrics (RefundMetrics::forOrder) over the CURRENT row: billable = total − unapplied reductions.
	var total, paid string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR), CAST(paid_amount AS CHAR)
		FROM orders WHERE id = ? FOR UPDATE`, orderID).Scan(&total, &paid); err != nil {
		return err
	}
	// DECIMAL SUM arrives as text; scan as string then parse (never int64).
	var reductionsRaw sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(amount),0) FROM order_sales_reductions
		WHERE order_id = ? AND applied_to_order_total = 0 AND reinstated_at IS NULL`, orderID).
		Scan(&reductionsRaw); err != nil {
		return err
	}
	reductions := int64(money.Round2(parseDecimalString(reductionsRaw.String)))

	billable := int64(money.Round2(parseDecimalString(total))) - reductions
	if billable < 0 {
		billable = 0
	}
	if billable > 0 {
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_sales_reductions
			(order_id, source_type, source_id, amount, applied_to_order_total, provenance, created_at, updated_at)
			VALUES (?, 'cancellation', ?, ?, 0, 'forward', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, orderID, billable); err != nil {
			return err
		}
	}

	// Refund obligation for what was actually PAID beyond the (now reduced) billable amount.
	paidCents := int64(money.Round2(parseDecimalString(paid)))
	available := paidCents - billable
	if available < 0 {
		available = 0
	}
	if available > 0 {
		var existingObligation int64
		key := fmt.Sprintf("cancel:%d", orderID)
		err = tx.QueryRowContext(ctx, `SELECT id FROM refund_obligations WHERE obligation_key = ? FOR UPDATE`,
			key).Scan(&existingObligation)
		if errors.Is(err, sql.ErrNoRows) {
			obligationNo, oerr := ulid()
			if oerr != nil {
				return oerr
			}
			metadata, merr := json.Marshal(map[string]any{
				"paid_cents": paidCents, "available_cents": available,
			})
			if merr != nil {
				return merr
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO refund_obligations
				(obligation_no, order_id, source_type, source_id, obligation_key, amount, status, provenance,
				 reason, approved_at, approval_source, deferred_amount, deferred_remaining, created_by,
				 metadata, created_at, updated_at)
				VALUES (?, ?, 'cancellation', ?, ?, ?, 'approved', 'forward', 'Cancellation refund',
				 UTC_TIMESTAMP(), 'auto_cancellation_policy', '0.00', '0.00', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
				obligationNo, orderID, orderID, key, available, actorID, string(metadata)); err != nil {
				return err
			}
		} else if err != nil {
			return err
		}
	}
	return s.markFinalized(ctx, tx, orderID, actorID)
}

func (s *Service) markFinalized(ctx context.Context, tx *sql.Tx, orderID, actorID int64) error {
	var status string
	if err := tx.QueryRowContext(ctx, `SELECT cancellation_financial_status FROM orders WHERE id = ? FOR UPDATE`,
		orderID).Scan(&status); err != nil {
		return err
	}
	if status == "finalized" {
		return nil
	}
	if _, err := tx.ExecContext(ctx, `UPDATE orders
		SET cancellation_financial_status = 'finalized', cancellation_finalized_at = UTC_TIMESTAMP(),
			updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, orderID); err != nil {
		return err
	}
	return writeOrderActivity(ctx, tx, actorID, orderID, "cancellation.finalized", map[string]any{
		"order_id": orderID,
	})
}

func writeOrderActivity(ctx context.Context, tx *sql.Tx, actorID, orderID int64, event string, properties map[string]any) error {
	raw, err := json.Marshal(properties)
	if err != nil {
		return err
	}
	var payload any = string(raw)
	if len(properties) == 0 {
		payload = nil
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, properties, created_at)
		VALUES (?, 'App\\Models\\Order', ?, ?, ?, UTC_TIMESTAMP())`, actorID, orderID, event, payload)
	return err
}

// snapshotUnit is one billable unit in the allocation.
type snapshotUnit struct {
	itemID int64
	index  int
	gross  int64
}

// ensureFinancialSnapshot is OrderFinancialSnapshotService::ensure — freeze every billable unit with the
// integer-cent largest-remainder allocation of the effective voucher across them.
func (s *Service) ensureFinancialSnapshot(ctx context.Context, tx *sql.Tx, orderID, actorID int64) error {
	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM order_financial_snapshots WHERE order_id = ? FOR UPDATE`,
		orderID).Scan(&existing)
	if err == nil {
		return nil // already frozen — first handover beat the cancellation to it
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	// captureLines: every item with fulfilled_quantity > 0, in id order.
	type line struct {
		itemID    int64
		quantity  int
		unitGross int64
	}
	rows, err := tx.QueryContext(ctx, `SELECT id, fulfilled_quantity, CAST(unit_price_snapshot AS CHAR)
		FROM order_items WHERE order_id = ? AND fulfilled_quantity > 0 ORDER BY id FOR UPDATE`, orderID)
	if err != nil {
		return err
	}
	var lines []line
	for rows.Next() {
		var l line
		var price string
		if err := rows.Scan(&l.itemID, &l.quantity, &price); err != nil {
			rows.Close()
			return err
		}
		l.unitGross = int64(money.Round2(parseDecimalString(price)))
		lines = append(lines, l)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	if len(lines) == 0 {
		return &Problem{Status: 422, Message: "Order tidak memiliki unit yang dapat di-snapshot."}
	}

	// Units + canonical subtotal law: subtotal = SUM(unit_price_snapshot * fulfilled_quantity).
	var units []snapshotUnit
	for _, l := range lines {
		for i := 1; i <= l.quantity; i++ {
			units = append(units, snapshotUnit{itemID: l.itemID, index: i, gross: l.unitGross})
		}
	}
	subtotal := int64(0)
	for _, u := range units {
		subtotal += u.gross
	}

	// Effective voucher over the frozen subtotal (Order::effectiveDiscountAmount).
	var discountRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(discount_amount AS CHAR) FROM orders WHERE id = ?`, orderID).
		Scan(&discountRaw); err != nil {
		return err
	}
	voucher := int64(money.Round2(effectiveDiscount(parseDecimalString(discountRaw), float64(subtotal))))

	allocated := allocateLargestRemainder(units, voucher)

	// Checksum: sha256 over the canonical JSON the reference hashes.
	type triplet struct {
		ItemID     int64 `json:"order_item_id"`
		UnitIndex  int   `json:"unit_index"`
		GrossCents int64 `json:"unit_gross_cents"`
		Allocated  int64 `json:"allocated"`
	}
	triplets := make([]triplet, 0, len(units))
	for i, u := range units {
		triplets = append(triplets, triplet{u.itemID, u.index, u.gross, allocated[i]})
	}
	checksumPayload, err := json.Marshal(map[string]any{
		"order_id": orderID, "subtotal": subtotal, "voucher": voucher, "units": triplets,
	})
	if err != nil {
		return err
	}
	checksum := sha256Hex(checksumPayload)

	res, err := tx.ExecContext(ctx, `INSERT INTO order_financial_snapshots
		(order_id, snapshot_version, `+"`trigger`"+`, subtotal_basis, effective_voucher_basis,
		 voucher_source_type, voucher_source_id, allocation_method, allocation_checksum, frozen_at, frozen_by,
		 provenance, metadata, created_at, updated_at)
		VALUES (?, 1, 'order_cancellation', ?, ?,
		 (SELECT CASE WHEN voucher_id IS NULL THEN NULL ELSE 'voucher' END FROM orders WHERE id = ?),
		 (SELECT voucher_id FROM orders WHERE id = ?),
		 'largest_remainder_unit_v1', ?, UTC_TIMESTAMP(), ?, 'frozen', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, subtotal, voucher, orderID, orderID, checksum, actorID, `{"trigger":"order_cancellation"}`)
	if err != nil {
		return err
	}
	snapshotID, err := res.LastInsertId()
	if err != nil {
		return err
	}

	// Per-unit rows and per-line bases.
	lineGross := map[int64]int64{}
	lineDiscount := map[int64]int64{}
	for _, l := range lines {
		lineGross[l.itemID] = 0
		lineDiscount[l.itemID] = 0
	}
	for i, u := range units {
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_financial_snapshot_units
			(snapshot_id, order_item_id, unit_index, unit_gross, allocated_discount, unit_net,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			snapshotID, u.itemID, u.index, u.gross, allocated[i], u.gross-allocated[i]); err != nil {
			return err
		}
		lineGross[u.itemID] += u.gross
		lineDiscount[u.itemID] += allocated[i]
	}
	for _, l := range lines {
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_item_financial_bases
			(order_id, order_item_id, snapshot_id, qty_basis, unit_gross_basis, line_gross_basis,
			 allocated_discount_total, line_net_basis, allocation_checksum, frozen_at, provenance,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), 'frozen', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, l.itemID, snapshotID, l.quantity, l.unitGross, lineGross[l.itemID],
			lineDiscount[l.itemID], lineGross[l.itemID]-lineDiscount[l.itemID], checksum); err != nil {
			return err
		}
	}
	return nil
}

// allocateLargestRemainder is the reference's integer-cent allocation: quota = floor(voucher*gross/total),
// capped at the unit gross; the remainder is handed out one cent at a time to the largest remainder (ties keep
// the earlier unit).
func allocateLargestRemainder(units []snapshotUnit, voucher int64) []int64 {
	allocated := make([]int64, len(units))
	if voucher <= 0 {
		return allocated
	}
	total := int64(0)
	for _, u := range units {
		total += u.gross
	}
	if total <= 0 {
		return allocated
	}

	remainders := make([]int64, len(units))
	assigned := int64(0)
	for i, u := range units {
		numerator := voucher * u.gross
		quota := numerator / total
		remainder := numerator % total
		if quota > u.gross {
			quota = u.gross
		}
		allocated[i] = quota
		remainders[i] = remainder
		assigned += quota
	}

	eligible := make([]bool, len(units))
	for i, u := range units {
		eligible[i] = allocated[i] < u.gross
	}
	left := voucher - assigned
	for left > 0 {
		best := -1
		bestRemainder := int64(-1)
		for i := range units {
			if !eligible[i] {
				continue
			}
			if remainders[i] > bestRemainder {
				best = i
				bestRemainder = remainders[i]
			}
		}
		if best < 0 {
			break
		}
		allocated[best]++
		eligible[best] = allocated[best] < units[best].gross
		remainders[best] = -1
		left--
	}
	return allocated
}
