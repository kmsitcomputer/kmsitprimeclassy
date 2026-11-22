package inventory_test

// Reservation oracles for the Agent stock primitives.
//
// Every test here uses TWO real connections (two real transactions) where concurrency matters — a mocked
// "row lock" would prove nothing about the invariant that actually protects the business: two checkouts must
// never both pass the availability check for the same units.
//
// Fixture availability (seed_inventory_fixtures.sql), which the expectations below depend on:
//
//	product 1    : a `sub` warehouse row exists → warehouse authoritative, transit 0 → available 0
//	product 2    : transit 12 + factory_plan 4, plan enabled → base 16, reserved 0 → available 16
//	product 3    : NO warehouse row at all → legacy fallback, 25 − 3 → available 22
//	variation 1  : a `sub` warehouse row exists → available 0
//	variation 2  : transit 9 → available 9

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// resetReservations restores the reserved figures and clears the ledger rows a reservation test writes.
func resetReservations(t *testing.T, d *db.DB) {
	t.Helper()
	ctx := context.Background()
	statements := []string{
		`DELETE FROM stock_movements WHERE type IN ('reserve','release')`,
		// The transfer tests move transit into sub buckets; restore the seeded bucket quantities so the
		// availability figures in the file header hold regardless of test order.
		`UPDATE warehouse_stocks SET quantity = 12 WHERE id = 2`,
		`UPDATE warehouse_stocks SET quantity = 4  WHERE id = 3`,
		`UPDATE warehouse_stocks SET quantity = 9  WHERE id = 4`,
		`UPDATE warehouse_stocks SET quantity = 6  WHERE id = 5`,
		`UPDATE warehouse_stocks SET quantity = 8  WHERE id = 6`,
		`UPDATE warehouse_stocks SET quantity = 3  WHERE id = 7`,
		`UPDATE product_stocks SET quantity_reserved = 5 WHERE agent_id = 10 AND product_id = 1`,
		`UPDATE product_stocks SET quantity_reserved = 0 WHERE agent_id = 10 AND product_id = 2`,
		`UPDATE product_stocks SET quantity_reserved = 3 WHERE agent_id = 10 AND product_id = 3`,
		`UPDATE product_variation_stocks SET quantity_reserved = 2 WHERE agent_id = 10 AND product_variation_id = 1`,
		`UPDATE product_variation_stocks SET quantity_reserved = 0 WHERE agent_id = 10 AND product_variation_id = 2`,
	}
	for _, stmt := range statements {
		if _, err := d.ExecContext(ctx, stmt); err != nil {
			t.Fatalf("reset reservations (%s): %v", stmt, err)
		}
	}
}

func reservedProduct(t *testing.T, d *db.DB, productID int64) int {
	t.Helper()
	var reserved int
	if err := d.QueryRow(`SELECT quantity_reserved FROM product_stocks WHERE agent_id = 10 AND product_id = ?`,
		productID).Scan(&reserved); err != nil {
		t.Fatalf("read reserved: %v", err)
	}
	return reserved
}

func reservedVariation(t *testing.T, d *db.DB, variationID int64) int {
	t.Helper()
	var reserved int
	if err := d.QueryRow(`SELECT quantity_reserved FROM product_variation_stocks
		WHERE agent_id = 10 AND product_variation_id = ?`, variationID).Scan(&reserved); err != nil {
		t.Fatalf("read reserved: %v", err)
	}
	return reserved
}

func movementCount(t *testing.T, d *db.DB, movementType string) int {
	t.Helper()
	var count int
	if err := d.QueryRow(`SELECT COUNT(*) FROM stock_movements WHERE type = ?`, movementType).Scan(&count); err != nil {
		t.Fatalf("count movements: %v", err)
	}
	return count
}

func orderRef() inventory.ReservationReference {
	createdBy := int64(13)
	return inventory.ReservationReference{Type: "order", ID: 9001, CreatedBy: &createdBy}
}

// TestReserveProductMovesLedgerAndReserved covers the happy path end to end: the reserved figure increases, a
// `reserve` movement is written, and availability drops by exactly the reserved quantity.
func TestReserveProductMovesLedgerAndReserved(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	before, err := inventory.ForProduct(ctx, d, 10, 2)
	if err != nil {
		t.Fatal(err)
	}
	if before.Available != 16 {
		t.Fatalf("fixture drift: product 2 available = %d, want 16", before.Available)
	}

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	if err := inventory.ReserveForProduct(ctx, tx, 10, 2, 6, orderRef(), "Kue Ulang Tahun"); err != nil {
		t.Fatalf("reserve: %v", err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}

	if got := reservedProduct(t, d, 2); got != 6 {
		t.Errorf("quantity_reserved = %d, want 6", got)
	}
	if got := movementCount(t, d, "reserve"); got != 1 {
		t.Errorf("reserve movements = %d, want 1", got)
	}

	after, err := inventory.ForProduct(ctx, d, 10, 2)
	if err != nil {
		t.Fatal(err)
	}
	if after.Available != 10 {
		t.Errorf("available = %d, want 10 (16 − 6)", after.Available)
	}
	if after.Reserved != 6 {
		t.Errorf("reserved = %d, want 6", after.Reserved)
	}
}

// TestReserveRejectsOversellAndLeavesNoState proves the failure is total: no reserved change, no movement, and
// the error carries the numbers the API needs for its 422 field message.
func TestReserveRejectsOversell(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	err = inventory.ReserveForProduct(ctx, tx, 10, 2, 17, orderRef(), "Kue Ulang Tahun")
	if err == nil {
		t.Fatal("reserving 17 of 16 available must fail")
	}
	var insufficient *inventory.InsufficientStockError
	if !errors.As(err, &insufficient) {
		t.Fatalf("error type = %T, want InsufficientStockError", err)
	}
	if insufficient.Available != 16 || insufficient.Requested != 17 {
		t.Errorf("error = %+v, want available 16 requested 17", insufficient)
	}
	// The caller is expected to roll back; do it here and prove the rollback left nothing behind.
	if rbErr := tx.Rollback(); rbErr != nil {
		t.Fatal(rbErr)
	}

	if got := reservedProduct(t, d, 2); got != 0 {
		t.Errorf("quantity_reserved = %d, want 0 (rejected reservation must not persist)", got)
	}
	if got := movementCount(t, d, "reserve"); got != 0 {
		t.Errorf("reserve movements = %d, want 0", got)
	}
}

// TestReserveUsesLegacyFallbackOnlyWithoutWarehouseRows pins the canonical asymmetry: product 3 has no warehouse
// row anywhere, so its legacy on-hand (25 − 3 reserved) is the availability figure, and the boundary is exact.
func TestReserveUsesLegacyFallbackOnlyWithoutWarehouseRows(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	sellable, err := inventory.ForProduct(ctx, d, 10, 3)
	if err != nil {
		t.Fatal(err)
	}
	if sellable.Source != "legacy" || sellable.Available != 22 {
		t.Fatalf("product 3 = %+v, want source legacy available 22", sellable)
	}

	// Exactly at the boundary succeeds.
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	if err := inventory.ReserveForProduct(ctx, tx, 10, 3, 22, orderRef(), "Kue Susu"); err != nil {
		t.Fatalf("reserving exactly the available quantity must succeed: %v", err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	if got := reservedProduct(t, d, 3); got != 25 {
		t.Errorf("quantity_reserved = %d, want 25 (3 + 22)", got)
	}

	// One unit more fails.
	tx2, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx2.Rollback() }()
	defer func() { _ = tx2.Rollback() }()
	if err := inventory.ReserveForProduct(ctx, tx2, 10, 3, 1, orderRef(), "Kue Susu"); err == nil {
		t.Fatal("one unit past the boundary must fail")
	}
}

// TestReserveWarehouseOnlyTargetReportsZeroAvailability is the trap the canonical rule encodes: product 1 has 40
// on hand but a `sub` warehouse row, so the warehouse is authoritative with a transit base of 0. A naive
// implementation would happily reserve from the legacy 40.
func TestReserveWarehouseOnlyTargetReportsZeroAvailability(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	sellable, err := inventory.ForProduct(ctx, d, 10, 1)
	if err != nil {
		t.Fatal(err)
	}
	if sellable.Source != "warehouse" || sellable.Available != 0 {
		t.Fatalf("product 1 = %+v, want source warehouse available 0", sellable)
	}

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	defer func() { _ = tx.Rollback() }()
	if err := inventory.ReserveForProduct(ctx, tx, 10, 1, 1, orderRef(), "Kue Lumpur"); err == nil {
		t.Fatal("a target whose warehouse base is 0 must refuse even though legacy on-hand is 40")
	}
}

// TestReserveProvisionsMissingCommitmentAtZero proves the provisioning branch: a target that exists only in
// warehouse buckets is reservable, and the commit row is created carrying ZERO physical quantity (nothing invented).
func TestReserveProvisionsMissingCommitmentAtZero(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	// Give product 3 a transit row so the warehouse is authoritative, then remove its commitment row: the only
	// remaining way to reserve is provisioning.
	if _, err := d.Exec(`DELETE FROM product_stocks WHERE agent_id = 10 AND product_id = 3`); err != nil {
		t.Fatal(err)
	}
	if _, err := d.Exec(`INSERT INTO warehouse_stocks
		(id, agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity, created_at, updated_at)
		VALUES (90, 10, 3, NULL, 'transit', NULL, 7, UTC_TIMESTAMP(), UTC_TIMESTAMP())`); err != nil {
		t.Fatal(err)
	}
	// Restore the seeded legacy row EXACTLY. The provisioned row occupies the unique (agent_id, product_id) slot,
	// so it has to be deleted first — re-inserting without deleting left the fixture with on_hand 0 and made every
	// later test that relies on product 3's legacy availability 22 fail.
	t.Cleanup(func() {
		_, _ = d.Exec(`DELETE FROM warehouse_stocks WHERE id = 90`)
		_, _ = d.Exec(`DELETE FROM stock_movements WHERE type = 'reserve' AND product_id = 3`)
		_, _ = d.Exec(`DELETE FROM product_stocks WHERE agent_id = 10 AND product_id = 3`)
		_, _ = d.Exec(`INSERT INTO product_stocks
			(id, agent_id, product_id, quantity_on_hand, quantity_reserved, created_at, updated_at)
			VALUES (2, 10, 3, 25, 3, '2026-11-09 09:00:00', '2026-11-09 09:00:00')`)
	})

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	if err := inventory.ReserveForProduct(ctx, tx, 10, 3, 4, orderRef(), "Kue Susu"); err != nil {
		t.Fatalf("reserve with a provisioned commitment row: %v", err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}

	var onHand, reserved int
	if err := d.QueryRow(`SELECT quantity_on_hand, quantity_reserved FROM product_stocks
		WHERE agent_id = 10 AND product_id = 3`).Scan(&onHand, &reserved); err != nil {
		t.Fatalf("provisioned row is missing: %v", err)
	}
	if onHand != 0 {
		t.Errorf("provisioned quantity_on_hand = %d, want 0 (nothing may be invented)", onHand)
	}
	if reserved != 4 {
		t.Errorf("provisioned quantity_reserved = %d, want 4", reserved)
	}
}

// TestTwoConnectionsCannotBothOversell is THE anti-oversell test: two independent transactions each try to
// reserve 10 of the 16 available units. 10 + 10 > 16, so exactly one may succeed — never both.
//
// This is the invariant that protects real money: if both passed, two customers would be promised the same units.
func TestTwoConnectionsCannotBothOversell(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	var wg sync.WaitGroup
	results := make([]error, 2)
	start := make(chan struct{})
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			<-start
			tx, err := d.BeginTx(ctx, nil)
			if err != nil {
				results[idx] = err
				return
			}
			if err := inventory.ReserveForProduct(ctx, tx, 10, 2, 10, orderRef(), "Kue Ulang Tahun"); err != nil {
				_ = tx.Rollback()
				results[idx] = err
				return
			}
			results[idx] = tx.Commit()
		}(i)
	}
	close(start)
	wg.Wait()

	success := 0
	for _, err := range results {
		if err == nil {
			success++
		}
	}
	if success != 1 {
		t.Fatalf("exactly one reservation of 10/16 may succeed, got %d (%v | %v)", success, results[0], results[1])
	}
	if got := reservedProduct(t, d, 2); got != 10 {
		t.Errorf("quantity_reserved = %d, want 10 — a lost update would show 20", got)
	}
	if got := movementCount(t, d, "reserve"); got != 1 {
		t.Errorf("reserve movements = %d, want 1", got)
	}
}

// TestTwoConnectionsBothFitExactCapacity is the mirror image: 8 + 8 == 16 must BOTH succeed. This catches the
// opposite defect — an over-eager lock that serialises but drops an increment (a lost update would leave 8).
func TestTwoConnectionsBothFitExactCapacity(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	var wg sync.WaitGroup
	results := make([]error, 2)
	start := make(chan struct{})
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			<-start
			tx, err := d.BeginTx(ctx, nil)
			if err != nil {
				results[idx] = err
				return
			}
			if err := inventory.ReserveForProduct(ctx, tx, 10, 2, 8, orderRef(), "Kue Ulang Tahun"); err != nil {
				_ = tx.Rollback()
				results[idx] = err
				return
			}
			results[idx] = tx.Commit()
		}(i)
	}
	close(start)
	wg.Wait()

	for i, err := range results {
		if err != nil {
			t.Fatalf("reservation %d failed though both fit: %v", i, err)
		}
	}
	if got := reservedProduct(t, d, 2); got != 16 {
		t.Errorf("quantity_reserved = %d, want 16 (both increments must land)", got)
	}
	sellable, err := inventory.ForProduct(ctx, d, 10, 2)
	if err != nil {
		t.Fatal(err)
	}
	if sellable.Available != 0 {
		t.Errorf("available = %d, want 0", sellable.Available)
	}
}

// TestMultiTargetReservationInOppositeOrderDoesNotDeadlock proves the canonical ordering: two reservations whose
// CART order lists the same two targets in opposite sequence must both complete. Without LockReservationTargets
// this is the classic 1213 deadlock (A: p2→p3, B: p3→p2).
func TestMultiTargetReservationInOppositeOrderDoesNotDeadlock(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	// product 2 (16 available) and product 3 (22 available): both have plenty for 1 unit each.
	first := []inventory.Target{inventory.ProductTarget(2), inventory.ProductTarget(3)}
	second := []inventory.Target{inventory.ProductTarget(3), inventory.ProductTarget(2)}

	reserveBoth := func(targets []inventory.Target) error {
		tx, err := d.BeginTx(ctx, nil)
		if err != nil {
			return err
		}
		if err := inventory.LockReservationTargets(ctx, tx, 10, targets); err != nil {
			_ = tx.Rollback()
			return err
		}
		for _, target := range inventory.CanonicalReservationTargets(targets) {
			if err := inventory.ReserveForProduct(ctx, tx, 10, *target.ProductID, 1, orderRef(), "line"); err != nil {
				_ = tx.Rollback()
				return fmt.Errorf("target p:%d: %w", *target.ProductID, err)
			}
		}
		return tx.Commit()
	}

	var wg sync.WaitGroup
	results := make([]error, 2)
	start := make(chan struct{})
	wg.Add(2)
	go func() { defer wg.Done(); <-start; results[0] = reserveBoth(first) }()
	go func() { defer wg.Done(); <-start; results[1] = reserveBoth(second) }()
	close(start)
	wg.Wait()

	for i, err := range results {
		if err != nil {
			t.Fatalf("reservation %d failed (deadlock or lock timeout): %v", i, err)
		}
	}
	if got := reservedProduct(t, d, 2); got != 2 {
		t.Errorf("product 2 reserved = %d, want 2 (one unit from each pass)", got)
	}
	if got := reservedProduct(t, d, 3); got != 5 {
		t.Errorf("product 3 reserved = %d, want 5 (3 fixture + 2)", got)
	}
}

// TestReleaseRestoresAvailabilityAndNeverGoesNegative pins the inverse primitive, including the clamp: releasing
// more than is reserved must leave 0 rather than wrap the unsigned column.
func TestReleaseRestoresAvailabilityAndNeverGoesNegative(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	if err := inventory.ReserveForProduct(ctx, tx, 10, 2, 5, orderRef(), "Kue Ulang Tahun"); err != nil {
		t.Fatal(err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}

	tx2, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx2.Rollback() }()
	if err := inventory.ReleaseForProduct(ctx, tx2, 10, 2, 5, orderRef()); err != nil {
		t.Fatal(err)
	}
	if err := tx2.Commit(); err != nil {
		t.Fatal(err)
	}
	if got := reservedProduct(t, d, 2); got != 0 {
		t.Errorf("reserved after release = %d, want 0", got)
	}

	// A double release must not underflow.
	tx3, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx3.Rollback() }()
	if err := inventory.ReleaseForProduct(ctx, tx3, 10, 2, 5, orderRef()); err != nil {
		t.Fatal(err)
	}
	if err := tx3.Commit(); err != nil {
		t.Fatal(err)
	}
	if got := reservedProduct(t, d, 2); got != 0 {
		t.Errorf("reserved after a double release = %d, want 0 (never negative)", got)
	}
}

// TestReserveVariationUsesItsOwnTarget proves the variation path is separate: variation 2 (transit 9) is
// reservable while variation 1 (warehouse-`sub` only) is not, and neither touches the product commitment row.
func TestReserveVariationUsesItsOwnTarget(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx.Rollback() }()
	if err := inventory.ReserveForVariation(ctx, tx, 10, 2, 9, orderRef(), "SKU-ULT-24"); err != nil {
		t.Fatalf("reserve variation 2: %v", err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	if got := reservedVariation(t, d, 2); got != 9 {
		t.Errorf("variation 2 reserved = %d, want 9", got)
	}
	// The product-level commitment row must be untouched by a variation reservation.
	if got := reservedProduct(t, d, 2); got != 0 {
		t.Errorf("product 2 reserved = %d, want 0 (a variation reservation must not touch it)", got)
	}

	tx2, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = tx2.Rollback() }()
	defer func() { _ = tx2.Rollback() }()
	if err := inventory.ReserveForVariation(ctx, tx2, 10, 1, 1, orderRef(), "SKU-ULT-20"); err == nil {
		t.Fatal("variation 1 has only `sub` warehouse rows, so its base is 0 and it must refuse")
	}
}

// TestReserveRejectsNonPositiveQuantity guards the primitive itself: a zero/negative reservation is a caller bug,
// not a silent no-op that would leave an order line unpaid for.
func TestReserveRejectsNonPositiveQuantity(t *testing.T) {
	d := openTestDB(t)
	resetReservations(t, d)
	ctx := context.Background()

	for _, qty := range []int{0, -3} {
		tx, err := d.BeginTx(ctx, nil)
		if err != nil {
			t.Fatal(err)
		}
		if err := inventory.ReserveForProduct(ctx, tx, 10, 2, qty, orderRef(), "x"); err == nil {
			t.Errorf("quantity %d must be rejected", qty)
		}
		_ = tx.Rollback()
	}
}
