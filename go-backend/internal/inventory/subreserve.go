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

// ReduceSubReservation is SubStockService::reduce — shrink an order item's ACTIVE Sub reservation by qty.
//
// Physical Sub stock is unchanged (the units simply become sellable again). Reducing more than is currently
// reserved is refused; a reservation driven to zero is RELEASED so no lingering zero-quantity active row
// survives. Lock order is the canonical Sub one: stock row first, reservation rows second.
func ReduceSubReservation(ctx context.Context, tx *sql.Tx, orderItemID int64, qty int, actorID *int64, reason string) error {
	if qty < 1 {
		return fmt.Errorf("inventory: sub reservation reduction must be >= 1, got %d", qty)
	}

	var (
		subLocationID          int64
		productID, variationID sql.NullInt64
	)
	err := tx.QueryRowContext(ctx, `SELECT sub_location_id, product_id, product_variation_id
		FROM sub_stock_reservations WHERE order_item_id = ?`, orderItemID).
		Scan(&subLocationID, &productID, &variationID)
	if errors.Is(err, sql.ErrNoRows) {
		return errors.New("Reservasi stok Sub tidak ditemukan.")
	}
	if err != nil {
		return err
	}

	target := Target{ProductID: nullPtr(productID), VariationID: nullPtr(variationID)}
	clause, args := subStockClause(target.ProductID, target.VariationID)
	var stockID int64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_stocks
		WHERE sub_location_id = ? AND stock_type = 'sub' AND `+clause+` FOR UPDATE`,
		append([]any{subLocationID}, args...)...).Scan(&stockID); err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	var (
		reservationID int64
		quantity      int
		status        string
	)
	if err := tx.QueryRowContext(ctx, `SELECT id, quantity, status FROM sub_stock_reservations
		WHERE order_item_id = ? FOR UPDATE`, orderItemID).
		Scan(&reservationID, &quantity, &status); err != nil {
		return err
	}
	if status != "active" {
		return errors.New("Reservasi stok Sub tidak aktif dan tidak dapat dikurangi.")
	}
	if quantity < qty {
		return errors.New("Jumlah pengurangan melebihi reservasi stok Sub.")
	}

	remaining := quantity - qty
	if remaining <= 0 {
		_, err = tx.ExecContext(ctx, `UPDATE sub_stock_reservations
			SET quantity = 0, status = 'released', released_at = UTC_TIMESTAMP(), released_by = ?,
				release_reason = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, nullableInt(actorID), reason, reservationID)
		return err
	}
	_, err = tx.ExecContext(ctx, `UPDATE sub_stock_reservations SET quantity = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, remaining, reservationID)
	return err
}

// IncreaseSubReservation is SubStockService::increase — grow this order item's reservation after re-checking
// Sub sellable capacity. Physical Sub stock is unchanged. A previously released (zeroed) reservation is
// REACTIVATED rather than duplicated (the UNIQUE order_item_id). Agent reservations are never touched.
func IncreaseSubReservation(ctx context.Context, tx *sql.Tx, orderItemID int64, qty int, actorID *int64, reason, itemName string) error {
	if qty < 1 {
		return fmt.Errorf("inventory: sub reservation increase must be >= 1, got %d", qty)
	}

	var (
		subLocationID          int64
		productID, variationID sql.NullInt64
	)
	err := tx.QueryRowContext(ctx, `SELECT sub_location_id, product_id, product_variation_id
		FROM sub_stock_reservations WHERE order_item_id = ?`, orderItemID).
		Scan(&subLocationID, &productID, &variationID)
	if errors.Is(err, sql.ErrNoRows) {
		return errors.New("Reservasi stok Sub tidak ditemukan.")
	}
	if err != nil {
		return err
	}

	target := Target{ProductID: nullPtr(productID), VariationID: nullPtr(variationID)}
	clause, args := subStockClause(target.ProductID, target.VariationID)
	var (
		stockID  int64
		physical int
	)
	err = tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stocks
		WHERE sub_location_id = ? AND stock_type = 'sub' AND `+clause+` FOR UPDATE`,
		append([]any{subLocationID}, args...)...).Scan(&stockID, &physical)
	exists := err == nil
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	var (
		reservationID int64
		quantity      int
	)
	if err := tx.QueryRowContext(ctx, `SELECT id, quantity FROM sub_stock_reservations
		WHERE order_item_id = ? FOR UPDATE`, orderItemID).
		Scan(&reservationID, &quantity); err != nil {
		return err
	}

	// reserved(forWrite) is a locking read that INCLUDES this reservation's current quantity, so the free
	// headroom is exactly physical - reserved. A missing stock row means zero physical.
	reserved, err := ReservedSubStock(ctx, tx, subLocationID, target)
	if err != nil {
		return err
	}
	available := physical - reserved
	if !exists || available < qty {
		if available < 0 {
			available = 0
		}
		return &InsufficientStockError{Item: itemName, Available: available, Requested: qty}
	}

	_, err = tx.ExecContext(ctx, `UPDATE sub_stock_reservations
		SET quantity = quantity + ?, status = 'active', released_at = NULL, released_by = NULL,
			release_reason = NULL, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, qty, reservationID)
	return err
}

// nullPtr converts a nullable scan into the pointer Target expects.
func nullPtr(v sql.NullInt64) *int64 {
	if !v.Valid {
		return nil
	}
	out := v.Int64
	return &out
}
