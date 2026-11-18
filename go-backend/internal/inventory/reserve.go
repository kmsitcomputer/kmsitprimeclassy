package inventory

// Agent stock reservations — the primitive every order path is built on.
//
// Ported from StockService::{reserveForProduct,reserveForVariation,provisionProductCommitment,
// lockReservationTarget(s),lockReservationTargets,canonicalReservationTargets}.
//
// The invariants that make this correct, and that a "simplification" would break:
//
//  1. TARGET ORDER IS CANONICAL, NOT CART ORDER. A multi-line checkout must acquire every target lock through
//     LockReservationTargets (lexicographic `p:`/`v:` keys) BEFORE reserving anything. Two checkouts listing the
//     same two products in opposite cart order would otherwise deadlock. `targetKey`/`sortTargets` here are the
//     SAME functions the transfer path uses — there is deliberately only one ordering vocabulary.
//
//  2. COMMITMENT ROW FIRST. The canonical first lock of any capacity decision is the Agent commitment row
//     (product_stocks / product_variation_stocks). The Transit -> Sub transfer path takes it first too, so the
//     two serialise instead of racing.
//
//  3. THE AVAILABILITY READ MUST BE A LOCKING READ. Under REPEATABLE READ a plain SELECT can still return a
//     snapshot older than the row lock the caller just waited for — that is exactly the race that once let a
//     Transit -> Sub transfer AND an Agent reservation both observe enough capacity. `availableLocked` reads the
//     warehouse rows with FOR UPDATE.
//
//  4. A TARGET WITH NO WAREHOUSE ROWS FALLS BACK TO THE LEGACY on-hand quantity; a target with warehouse rows
//     (of ANY stock_type, even only `sub`) does NOT. This asymmetry is the canonical sellable rule — see the
//     package comment in sellable.go.
//
//  5. A MISSING COMMITMENT ROW IS PROVISIONED AT ZERO, never refused. The catalog shows warehouse-backed stock
//     before any legacy row exists; refusing here would let the storefront advertise sellable stock that checkout
//     rejects.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"sort"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// InsufficientStockError is InsufficientStockException: the caller maps it to a 422 with a field message.
type InsufficientStockError struct {
	Item      string // product name or variation SKU/label
	Available int
	Requested int
}

func (e *InsufficientStockError) Error() string {
	return fmt.Sprintf("stok %s tidak mencukupi (tersedia %d, diminta %d)", e.Item, e.Available, e.Requested)
}

// ReservationReference is the (type,id,actor) triple written onto the reserve movement, so a later release can
// find every movement belonging to one order.
type ReservationReference struct {
	Type      string // e.g. "order"
	ID        int64
	CreatedBy *int64
}

// CanonicalReservationTargets returns targets in the canonical lock order, deduped.
//
// A product target and a variation target with the same numeric id are DIFFERENT keys (p:7 vs v:7), which is
// intentional: they are different rows.
func CanonicalReservationTargets(targets []Target) []Target {
	byKey := make(map[string]Target, len(targets))
	for _, target := range targets {
		key := targetKey(target.ProductID, target.VariationID)
		if key == "" {
			continue
		}
		byKey[key] = target
	}
	keys := sortTargets(byKey)
	out := make([]Target, 0, len(keys))
	for _, key := range keys {
		out = append(out, byKey[key])
	}
	return out
}

// LockReservationTargets acquires every capacity lock a reservation pass needs, in ONE canonical order.
//
// Call this BEFORE reserving any line. Reserving line-by-line in cart order is the deadlock the canonical
// ordering exists to prevent.
func LockReservationTargets(ctx context.Context, tx *sql.Tx, agentID int64, targets []Target) error {
	for _, target := range CanonicalReservationTargets(targets) {
		if err := LockReservationTarget(ctx, tx, agentID, target); err != nil {
			return err
		}
	}
	return nil
}

// LockReservationTarget locks one target exactly as ReserveFor{Product,Variation} will: the Agent commitment row
// first, then the warehouse rows it competes with (still a locking read, so the availability figure the caller
// later sees cannot be a stale snapshot).
func LockReservationTarget(ctx context.Context, tx *sql.Tx, agentID int64, target Target) error {
	if err := lockAgentCapacity(ctx, tx, agentID, target); err != nil {
		return err
	}
	_, _, _, err := warehouseFiguresLocked(ctx, tx, agentID, target)
	return err
}

// ReserveForProduct is StockService::reserveForProduct.
func ReserveForProduct(ctx context.Context, tx *sql.Tx, agentID, productID int64, qty int, ref ReservationReference, itemName string) error {
	if qty < 1 {
		return fmt.Errorf("inventory: reservation quantity must be >= 1, got %d", qty)
	}
	target := ProductTarget(productID)

	reserved, err := lockCommitment(ctx, tx, agentID, target)
	if err != nil {
		return err
	}
	available, err := availableLocked(ctx, tx, agentID, target, reserved)
	if err != nil {
		return err
	}
	if available < qty {
		return &InsufficientStockError{Item: itemName, Available: available, Requested: qty}
	}

	if _, err := tx.ExecContext(ctx, `UPDATE product_stocks SET quantity_reserved = quantity_reserved + ?,
			updated_at = UTC_TIMESTAMP() WHERE agent_id = ? AND product_id = ?`, qty, agentID, productID); err != nil {
		return err
	}
	return writeMovement(ctx, tx, movement{
		AgentID:   agentID,
		ProductID: &productID,
		Type:      "reserve",
		Quantity:  int64(qty),
		CreatedBy: createdByOrZero(ref.CreatedBy),
		Note:      referenceNote(ref),
	})
}

// ReserveForVariation is StockService::reserveForVariation.
func ReserveForVariation(ctx context.Context, tx *sql.Tx, agentID, variationID int64, qty int, ref ReservationReference, itemName string) error {
	if qty < 1 {
		return fmt.Errorf("inventory: reservation quantity must be >= 1, got %d", qty)
	}
	target := VariationTarget(variationID)

	reserved, err := lockCommitment(ctx, tx, agentID, target)
	if err != nil {
		return err
	}
	available, err := availableLocked(ctx, tx, agentID, target, reserved)
	if err != nil {
		return err
	}
	if available < qty {
		return &InsufficientStockError{Item: itemName, Available: available, Requested: qty}
	}

	if _, err := tx.ExecContext(ctx, `UPDATE product_variation_stocks SET quantity_reserved = quantity_reserved + ?,
			updated_at = UTC_TIMESTAMP() WHERE agent_id = ? AND product_variation_id = ?`, qty, agentID, variationID); err != nil {
		return err
	}
	return writeMovement(ctx, tx, movement{
		AgentID:     agentID,
		VariationID: &variationID,
		Type:        "reserve",
		Quantity:    int64(qty),
		CreatedBy:   createdByOrZero(ref.CreatedBy),
		Note:        referenceNote(ref),
	})
}

// ReleaseForProduct returns reserved units to availability (order cancellation, failed payment, item cancel).
//
// It is the exact inverse of ReserveForProduct and is written here — next to it — so the pair can never drift.
//
// TRAP: `quantity_reserved` is an UNSIGNED column, and MySQL evaluates `quantity_reserved - ?` in UNSIGNED
// arithmetic, so releasing more than is reserved raises error 1690 ("BIGINT UNSIGNED value is out of range")
// BEFORE any GREATEST() clamp is applied. The subtraction therefore has to be cast to SIGNED; without that a
// cancellation of an already-released order fails with a 500 instead of being a harmless no-op.
func ReleaseForProduct(ctx context.Context, tx *sql.Tx, agentID, productID int64, qty int, ref ReservationReference) error {
	if qty < 1 {
		return fmt.Errorf("inventory: release quantity must be >= 1, got %d", qty)
	}
	if _, err := tx.ExecContext(ctx, `UPDATE product_stocks
		SET quantity_reserved = GREATEST(CAST(quantity_reserved AS SIGNED) - ?, 0), updated_at = UTC_TIMESTAMP()
		WHERE agent_id = ? AND product_id = ?`, qty, agentID, productID); err != nil {
		return err
	}
	return writeMovement(ctx, tx, movement{
		AgentID:   agentID,
		ProductID: &productID,
		Type:      "release",
		Quantity:  int64(qty),
		CreatedBy: createdByOrZero(ref.CreatedBy),
		Note:      referenceNote(ref),
	})
}

// ReleaseForVariation is ReleaseForProduct for a variation target.
func ReleaseForVariation(ctx context.Context, tx *sql.Tx, agentID, variationID int64, qty int, ref ReservationReference) error {
	if qty < 1 {
		return fmt.Errorf("inventory: release quantity must be >= 1, got %d", qty)
	}
	if _, err := tx.ExecContext(ctx, `UPDATE product_variation_stocks
		SET quantity_reserved = GREATEST(CAST(quantity_reserved AS SIGNED) - ?, 0), updated_at = UTC_TIMESTAMP()
		WHERE agent_id = ? AND product_variation_id = ?`, qty, agentID, variationID); err != nil {
		return err
	}
	return writeMovement(ctx, tx, movement{
		AgentID:     agentID,
		VariationID: &variationID,
		Type:        "release",
		Quantity:    int64(qty),
		CreatedBy:   createdByOrZero(ref.CreatedBy),
		Note:        referenceNote(ref),
	})
}

// ---- internals -------------------------------------------------------------------------------------

func createdByOrZero(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

// referenceNote renders the reference onto the movement note.
//
// The reference keeps the {type,id} pair in dedicated columns, but the Go port writes it into `note` as well so
// an operator reading the ledger can see which order a reserve belongs to without a join. It is additive: the
// ledger columns are unchanged.
func referenceNote(ref ReservationReference) string {
	if ref.Type == "" {
		return ""
	}
	return fmt.Sprintf("%s#%d", ref.Type, ref.ID)
}

// lockCommitment locks (and, when absent, provisions) the Agent commitment row, returning the reserved figure
// read under that lock.
func lockCommitment(ctx context.Context, tx *sql.Tx, agentID int64, target Target) (int, error) {
	if target.VariationID != nil {
		var reserved int
		err := tx.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_variation_stocks
			WHERE agent_id = ? AND product_variation_id = ? FOR UPDATE`, agentID, *target.VariationID).Scan(&reserved)
		if errors.Is(err, sql.ErrNoRows) {
			return provisionVariationCommitment(ctx, tx, agentID, *target.VariationID)
		}
		return reserved, err
	}

	var reserved int
	err := tx.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_stocks
		WHERE agent_id = ? AND product_id = ? FOR UPDATE`, agentID, *target.ProductID).Scan(&reserved)
	if errors.Is(err, sql.ErrNoRows) {
		return provisionProductCommitment(ctx, tx, agentID, *target.ProductID)
	}
	return reserved, err
}

// provisionProductCommitment creates the commitment row at ZERO.
//
// The reference wraps the insert in a try/catch and re-reads under lock when a concurrent writer won the race. The
// same thing is expressed here as an explicit insert-then-reread, so a unique-key collision is an expected branch
// rather than an exception.
func provisionProductCommitment(ctx context.Context, tx *sql.Tx, agentID, productID int64) (int, error) {
	if _, err := tx.ExecContext(ctx, `INSERT INTO product_stocks
		(agent_id, product_id, quantity_on_hand, quantity_reserved, created_at, updated_at)
		VALUES (?, ?, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, agentID, productID); err != nil {
		if !isDuplicateKey(err) {
			return 0, err
		}
	}
	var reserved int
	if err := tx.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_stocks
		WHERE agent_id = ? AND product_id = ? FOR UPDATE`, agentID, productID).Scan(&reserved); err != nil {
		return 0, err
	}
	return reserved, nil
}

func provisionVariationCommitment(ctx context.Context, tx *sql.Tx, agentID, variationID int64) (int, error) {
	if _, err := tx.ExecContext(ctx, `INSERT INTO product_variation_stocks
		(agent_id, product_variation_id, quantity_on_hand, quantity_reserved, created_at, updated_at)
		VALUES (?, ?, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, agentID, variationID); err != nil {
		if !isDuplicateKey(err) {
			return 0, err
		}
	}
	var reserved int
	if err := tx.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_variation_stocks
		WHERE agent_id = ? AND product_variation_id = ? FOR UPDATE`, agentID, variationID).Scan(&reserved); err != nil {
		return 0, err
	}
	return reserved, nil
}

func isDuplicateKey(err error) bool {
	var mysqlErr interface{ Number() uint16 }
	if errors.As(err, &mysqlErr) {
		return mysqlErr.Number() == 1062
	}
	return false
}

// availableLocked is the canonical sellable `available` figure, read under lock.
//
// `reserved` comes from the already-locked commitment row, so no extra query is needed for it (that is also what
// the reference does: `$stock->quantity_reserved`).
func availableLocked(ctx context.Context, tx *sql.Tx, agentID int64, target Target, reserved int) (int, error) {
	transit, plan, hasRows, err := warehouseFiguresLocked(ctx, tx, agentID, target)
	if err != nil {
		return 0, err
	}
	if hasRows {
		planEnabled, err := factoryPlanEnabledLocked(ctx, tx, agentID)
		if err != nil {
			return 0, err
		}
		base := transit
		if planEnabled {
			base += plan
		}
		return max0(base - reserved), nil
	}
	legacy, err := legacyOnHandLocked(ctx, tx, agentID, target)
	if err != nil {
		return 0, err
	}
	return max0(legacy - reserved), nil
}

// warehouseFiguresLocked reads the target's warehouse rows with FOR UPDATE and returns (transit, plan, hasAnyRows).
//
// The read deliberately covers EVERY stock_type for the target, not only transit/factory_plan:
//   - the existence test must not be filtered by stock_type (a target holding only `sub` rows is
//     warehouse-authoritative with a base of 0), and
//   - taking the rows in ONE locking statement means the locks are acquired in a single, index-ordered pass, so
//     two transactions cannot interleave into a row-by-row cycle.
//
// It cannot deadlock against the Sub domain: the Sub path locks the sub-location row and then its stock rows, and
// an Agent reservation never takes a sub-location lock. Against the Transfer path the order is identical
// (commitment row -> warehouse rows).
func warehouseFiguresLocked(ctx context.Context, tx *sql.Tx, agentID int64, target Target) (int, int, bool, error) {
	clause, args := target.clause()
	rows, err := tx.QueryContext(ctx, `SELECT stock_type, quantity FROM warehouse_stocks
		WHERE agent_id = ? AND `+clause+` FOR UPDATE`, append([]any{agentID}, args...)...)
	if err != nil {
		return 0, 0, false, err
	}
	defer rows.Close()

	var transit, plan int
	any := false
	for rows.Next() {
		var (
			stockType string
			quantity  int
		)
		if err := rows.Scan(&stockType, &quantity); err != nil {
			return 0, 0, false, err
		}
		any = true
		switch stockType {
		case "transit":
			transit += quantity
		case "factory_plan":
			plan += quantity
		}
	}
	if err := rows.Err(); err != nil {
		return 0, 0, false, err
	}
	return transit, plan, any, nil
}

func factoryPlanEnabledLocked(ctx context.Context, tx *sql.Tx, agentID int64) (bool, error) {
	var enabled sql.NullBool
	err := tx.QueryRowContext(ctx, `SELECT factory_plan_enabled FROM warehouse_settings WHERE agent_id = ? LIMIT 1`, agentID).
		Scan(&enabled)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return enabled.Valid && enabled.Bool, nil
}

func legacyOnHandLocked(ctx context.Context, tx *sql.Tx, agentID int64, target Target) (int, error) {
	var onHand int
	var err error
	if target.ProductID != nil {
		err = tx.QueryRowContext(ctx, `SELECT quantity_on_hand FROM product_stocks
			WHERE agent_id = ? AND product_id = ? LIMIT 1`, agentID, *target.ProductID).Scan(&onHand)
	} else {
		err = tx.QueryRowContext(ctx, `SELECT quantity_on_hand FROM product_variation_stocks
			WHERE agent_id = ? AND product_variation_id = ? LIMIT 1`, agentID, *target.VariationID).Scan(&onHand)
	}
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	return onHand, nil
}

// ReservedQuantity reads the current reserved figure for a target (diagnostics and reconciliation only — a
// capacity decision must use the locking path above).
func ReservedQuantity(ctx context.Context, q queryExecer, agentID int64, target Target) (int, error) {
	return reservedForQuery(ctx, q, agentID, target)
}

func reservedForQuery(ctx context.Context, q queryExecer, agentID int64, target Target) (int, error) {
	var (
		reserved int
		err      error
	)
	if target.ProductID != nil {
		err = q.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_stocks WHERE agent_id = ? AND product_id = ? LIMIT 1`,
			agentID, *target.ProductID).Scan(&reserved)
	} else {
		err = q.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_variation_stocks WHERE agent_id = ? AND product_variation_id = ? LIMIT 1`,
			agentID, *target.VariationID).Scan(&reserved)
	}
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	return reserved, nil
}

// sortTargetsInPlace is exported for callers that already hold a []Target and want the canonical order without
// allocating a key map (the order creation path lists targets once).
func sortTargetsInPlace(targets []Target) {
	sort.SliceStable(targets, func(i, j int) bool {
		return targetKey(targets[i].ProductID, targets[i].VariationID) <
			targetKey(targets[j].ProductID, targets[j].VariationID)
	})
}

var _ = db.IsMissingSchema
