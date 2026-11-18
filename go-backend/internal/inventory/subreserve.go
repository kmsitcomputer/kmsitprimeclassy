package inventory

// Sub stock reservations — SubStockService::{reserve,lockReservationTargets,reserved}.
//
// The Sub ledger is separate from the Agent commitment row: a Sub-sourced order line reserves against ONE Sub
// Location's physical stock and never touches Agent reserved quantities. Physical stock is NOT moved here — the
// reservation only records intent, and consumption happens on fulfilment.
//
// LOCK ORDER (documented in the reference and preserved here): the Sub stock row FIRST, then the reservation
// rows. A checkout additionally takes the Sub Location parent row (shared) BEFORE any stock row — see
// lockSubLocationForCheckout in the orders package — because the executor locks the location exclusively first.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
)

// SubCounterpartyError is returned when a Sub location is no longer usable for the caller.
var ErrSubLocationUnavailable = errors.New("sub location is not available")

// SubReservationInput describes one Sub reservation.
type SubReservationInput struct {
	AgentID       int64
	SubLocationID int64
	OrderItemID   int64
	ProductID     *int64
	VariationID   *int64
	Quantity      int
	ReservedBy    *int64
	ItemName      string
}

// LockSubReservationTargets acquires the Sub WarehouseStock row locks a multi-line Sub checkout needs, in the
// SAME canonical target order the Agent domain uses. Missing rows are NOT created — the reserve call still
// reports insufficient stock normally.
func LockSubReservationTargets(ctx context.Context, tx *sql.Tx, subLocationID int64, targets []Target) error {
	for _, target := range CanonicalReservationTargets(targets) {
		clause, args := subStockClause(target.ProductID, target.VariationID)
		query := `SELECT id FROM warehouse_stocks WHERE sub_location_id = ? AND stock_type = 'sub' AND ` + clause + ` FOR UPDATE`
		var id int64
		err := tx.QueryRowContext(ctx, query, append([]any{subLocationID}, args...)...).Scan(&id)
		if errors.Is(err, sql.ErrNoRows) {
			// A gap lock would be the only effect; the reference explicitly does not create the row here.
			continue
		}
		if err != nil {
			return err
		}
	}
	return nil
}

func subStockClause(productID, variationID *int64) (string, []any) {
	if variationID != nil {
		return "product_variation_id = ? AND product_id IS NULL", []any{*variationID}
	}
	return "product_id = ? AND product_variation_id IS NULL", []any{*productID}
}

// ReservedSubStock is SubStockService::reserved — the ACTIVE reservation quantity for a target.
func ReservedSubStock(ctx context.Context, tx *sql.Tx, subLocationID int64, target Target) (int, error) {
	clause, args := subStockClause(target.ProductID, target.VariationID)
	var reserved int
	err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM sub_stock_reservations
		WHERE sub_location_id = ? AND status = 'active' AND `+clause+` FOR UPDATE`,
		append([]any{subLocationID}, args...)...).Scan(&reserved)
	if err != nil {
		return 0, err
	}
	return reserved, nil
}

// ReserveSubStock is SubStockService::reserve.
//
// Idempotent per order item (a retry returns the existing reservation instead of reserving twice) — that is what
// makes an idempotent order replay safe even if it reaches this call again.
func ReserveSubStock(ctx context.Context, tx *sql.Tx, in SubReservationInput) error {
	if in.Quantity < 1 {
		return fmt.Errorf("inventory: sub reservation quantity must be >= 1, got %d", in.Quantity)
	}

	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM sub_stock_reservations WHERE order_item_id = ?`, in.OrderItemID).Scan(&existing)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	target := Target{ProductID: in.ProductID, VariationID: in.VariationID}
	clause, args := subStockClause(in.ProductID, in.VariationID)

	var stockID int64
	var physical int
	err = tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stocks
		WHERE sub_location_id = ? AND stock_type = 'sub' AND `+clause+` FOR UPDATE`,
		append([]any{in.SubLocationID}, args...)...).Scan(&stockID, &physical)
	if errors.Is(err, sql.ErrNoRows) {
		return &InsufficientStockError{Item: in.ItemName, Available: 0, Requested: in.Quantity}
	}
	if err != nil {
		return err
	}

	// Re-check for a concurrent writer that inserted between the first probe and the stock lock.
	if err := tx.QueryRowContext(ctx, `SELECT id FROM sub_stock_reservations WHERE order_item_id = ? FOR UPDATE`,
		in.OrderItemID).Scan(&existing); err == nil {
		return nil
	} else if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	reserved, err := ReservedSubStock(ctx, tx, in.SubLocationID, target)
	if err != nil {
		return err
	}
	available := physical - reserved
	if available < in.Quantity {
		if available < 0 {
			available = 0
		}
		return &InsufficientStockError{Item: in.ItemName, Available: available, Requested: in.Quantity}
	}

	_, err = tx.ExecContext(ctx, `INSERT INTO sub_stock_reservations
		(agent_id, sub_location_id, order_item_id, product_id, product_variation_id, quantity, status,
		 reserved_by, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, 'active', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		in.AgentID, in.SubLocationID, in.OrderItemID, nullableInt(in.ProductID), nullableInt(in.VariationID),
		in.Quantity, nullableInt(in.ReservedBy))
	return err
}

// ReleaseSubStock frees an order item's active reservation (cancellation / item cancel).
func ReleaseSubStock(ctx context.Context, tx *sql.Tx, orderItemID int64, releasedBy *int64, reason string) error {
	_, err := tx.ExecContext(ctx, `UPDATE sub_stock_reservations
		SET status = 'released', released_at = UTC_TIMESTAMP(), released_by = ?, release_reason = ?,
			updated_at = UTC_TIMESTAMP()
		WHERE order_item_id = ? AND status = 'active'`, nullableInt(releasedBy), reason, orderItemID)
	return err
}
