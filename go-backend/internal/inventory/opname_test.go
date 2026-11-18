package inventory_test

// Two-connection tests for STOCK OPNAME, the inventory corrective write.
//
// What is verified:
//  1. The stale-snapshot guard: if the stock changes between counting and approval, approval answers 409 and
//     writes NOTHING (no quantity change, no movement, still `submitted`).
//  2. The revision counter makes that detection reliable even when the quantity happens to be restored to the
//     same number — the warehouse trigger bumps the counter, so identity+revision catch it.
//  3. Approval is idempotent: a replay cannot apply the same correction twice.
//  4. Approval also refuses when the revision was bumped but the quantity is unchanged.

import (
	"context"
	"errors"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// dbHandle bundles the test database so helpers can share one signature.
type dbHandle struct{ db *db.DB }

// resetOpnames clears the opname state this package creates, leaving the fixtures intact.
func resetOpnames(t *testing.T, d *dbHandle) {
	t.Helper()
	ctx := context.Background()
	// Order matters: stock_movements.opname_id has a FK to stock_opnames, so the movements must go first.
	for _, stmt := range []string{
		`DELETE FROM stock_movements WHERE opname_id IS NOT NULL`,
		`DELETE FROM stock_opname_items`,
		`DELETE FROM stock_opnames`,
		`UPDATE warehouse_stocks SET quantity = 12 WHERE id = 2`,
		`UPDATE warehouse_stocks SET quantity = 4  WHERE id = 3`,
		`UPDATE warehouse_stocks SET quantity = 9  WHERE id = 4`,
	} {
		if _, err := d.db.ExecContext(ctx, stmt); err != nil {
			t.Fatalf("reset opname (%s): %v", stmt, err)
		}
	}
}

// createOpname makes a draft physical opname on transit for product 2 and returns (id, itemID).
func createOpname(t *testing.T, d *dbHandle) (int64, int64) {
	t.Helper()
	row, err := inventory.CreateOpname(context.Background(), d.db, gudangActor(), "physical_opname",
		strPtr("transit"), nil, []inventory.OpnameItemInput{{ProductID: int64Ptr(2)}}, strPtr("test"))
	if err != nil {
		t.Fatalf("create opname: %v", err)
	}
	id := int64(row["id"].(int64))
	items := row["items"].([]map[string]any)
	return id, int64(items[0]["id"].(int64))
}

func strPtr(v string) *string { return &v }

// countAndSubmit drives the gudang side to `submitted`.
func countAndSubmit(t *testing.T, d *dbHandle, opnameID, itemID, counted int64) {
	t.Helper()
	ctx := context.Background()
	if _, err := inventory.CountOpname(ctx, d.db, gudangActor(), opnameID,
		[]inventory.CountInput{{ItemID: itemID, CountedQuantity: counted}}); err != nil {
		t.Fatalf("count: %v", err)
	}
	if _, err := inventory.SubmitOpname(ctx, d.db, gudangActor(), opnameID); err != nil {
		t.Fatalf("submit: %v", err)
	}
}

// TestOpnameApproveAppliesCorrection is the happy path: the counted quantity becomes the warehouse quantity and
// exactly one opname_adjustment movement records the delta.
func TestOpnameApproveAppliesCorrection(t *testing.T) {
	d := &dbHandle{openTestDB(t)}
	resetTransfers(t, d.db)
	resetOpnames(t, d)
	ctx := context.Background()

	opnameID, itemID := createOpname(t, d)
	countAndSubmit(t, d, opnameID, itemID, 10) // system 12 → delta −2

	if _, err := inventory.ApproveOpname(ctx, d.db, adminActor(), opnameID); err != nil {
		t.Fatalf("approve: %v", err)
	}
	if got := transferQuantity(t, d.db, inventory.ProductTarget(2), "transit"); got != 10 {
		t.Errorf("transit = %d, want 10 (counted quantity applied)", got)
	}
	var movements int
	if err := d.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_movements
		WHERE opname_id = ? AND type = 'opname_adjustment'`, opnameID).Scan(&movements); err != nil {
		t.Fatal(err)
	}
	if movements != 1 {
		t.Errorf("opname_adjustment movements = %d, want 1", movements)
	}
	var status string
	if err := d.db.QueryRowContext(ctx, `SELECT status FROM stock_opnames WHERE id = ?`, opnameID).Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "approved" {
		t.Errorf("status = %q, want approved", status)
	}
}

// TestOpnameApproveRejectsStaleQuantity proves the 409 path writes nothing.
func TestOpnameApproveRejectsStaleQuantity(t *testing.T) {
	d := &dbHandle{openTestDB(t)}
	resetTransfers(t, d.db)
	resetOpnames(t, d)
	ctx := context.Background()

	opnameID, itemID := createOpname(t, d)
	countAndSubmit(t, d, opnameID, itemID, 10)

	// Someone else moves stock after the count.
	if _, err := d.db.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = 7 WHERE id = 2`); err != nil {
		t.Fatal(err)
	}

	_, err := inventory.ApproveOpname(ctx, d.db, adminActor(), opnameID)
	if err == nil {
		t.Fatal("approval must refuse a stale snapshot")
	}
	var de *inventory.DomainError
	if !errors.As(err, &de) || de.Status != 409 {
		t.Fatalf("expected a 409 DomainError, got %#v", err)
	}

	// Nothing was written.
	if got := transferQuantity(t, d.db, inventory.ProductTarget(2), "transit"); got != 7 {
		t.Errorf("transit = %d, want 7 (the concurrent value, untouched by the failed approval)", got)
	}
	var movements int
	if err := d.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_movements WHERE opname_id = ?`, opnameID).Scan(&movements); err != nil {
		t.Fatal(err)
	}
	if movements != 0 {
		t.Errorf("movements written for a rejected approval: %d", movements)
	}
	var status string
	if err := d.db.QueryRowContext(ctx, `SELECT status FROM stock_opnames WHERE id = ?`, opnameID).Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "submitted" {
		t.Errorf("status = %q, want submitted (unchanged)", status)
	}
}

// TestOpnameApproveDetectsRevisionBumpWithSameQuantity is the subtle half: the warehouse TRIGGER bumps
// warehouse_stock_revisions on every write, so even if the quantity ends up back at the snapshotted value the
// revision differs and the approval must still refuse.
func TestOpnameApproveDetectsRevisionBumpWithSameQuantity(t *testing.T) {
	d := &dbHandle{openTestDB(t)}
	resetTransfers(t, d.db)
	resetOpnames(t, d)
	ctx := context.Background()

	opnameID, itemID := createOpname(t, d)
	countAndSubmit(t, d, opnameID, itemID, 10)

	// Change then restore the SAME quantity: the value is back, the revision is not.
	for _, qty := range []int64{5, 12} {
		if _, err := d.db.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = ? WHERE id = 2`, qty); err != nil {
			t.Fatal(err)
		}
	}
	if got := transferQuantity(t, d.db, inventory.ProductTarget(2), "transit"); got != 12 {
		t.Fatalf("precondition: transit = %d, want 12 (restored)", got)
	}

	_, err := inventory.ApproveOpname(ctx, d.db, adminActor(), opnameID)
	if err == nil {
		t.Fatal("approval must refuse when the revision advanced, even with the same quantity")
	}
	var de *inventory.DomainError
	if !errors.As(err, &de) || de.Status != 409 {
		t.Fatalf("expected 409, got %#v", err)
	}
}

// TestOpnameApproveIsIdempotent proves a replay cannot apply the correction twice.
func TestOpnameApproveIsIdempotent(t *testing.T) {
	d := &dbHandle{openTestDB(t)}
	resetTransfers(t, d.db)
	resetOpnames(t, d)
	ctx := context.Background()

	opnameID, itemID := createOpname(t, d)
	countAndSubmit(t, d, opnameID, itemID, 10)

	if _, err := inventory.ApproveOpname(ctx, d.db, adminActor(), opnameID); err != nil {
		t.Fatalf("first approve: %v", err)
	}
	after := transferQuantity(t, d.db, inventory.ProductTarget(2), "transit")
	if after != 10 {
		t.Fatalf("transit = %d, want 10", after)
	}
	if _, err := inventory.ApproveOpname(ctx, d.db, adminActor(), opnameID); err != nil {
		t.Fatalf("replay must be a no-op, got %v", err)
	}
	if got := transferQuantity(t, d.db, inventory.ProductTarget(2), "transit"); got != after {
		t.Errorf("replay changed stock: %d → %d", after, got)
	}
	var movements int
	if err := d.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_movements WHERE opname_id = ?`, opnameID).Scan(&movements); err != nil {
		t.Fatal(err)
	}
	if movements != 1 {
		t.Errorf("movements = %d, want 1 (replay must not add more)", movements)
	}
}

// TestOpnameProgressGuards pins the state machine: no count after submit, no approve before submit.
func TestOpnameProgressGuards(t *testing.T) {
	d := &dbHandle{openTestDB(t)}
	resetTransfers(t, d.db)
	resetOpnames(t, d)
	ctx := context.Background()

	opnameID, itemID := createOpname(t, d)

	// Submit with an uncounted item fails.
	if _, err := inventory.SubmitOpname(ctx, d.db, gudangActor(), opnameID); err == nil {
		t.Error("submit must fail while an item is uncounted")
	}
	// Approve while draft fails.
	if _, err := inventory.ApproveOpname(ctx, d.db, adminActor(), opnameID); err == nil {
		t.Error("approve must fail while the opname is a draft")
	}

	countAndSubmit(t, d, opnameID, itemID, 11)
	// Counting after submit fails.
	if _, err := inventory.CountOpname(ctx, d.db, gudangActor(), opnameID,
		[]inventory.CountInput{{ItemID: itemID, CountedQuantity: 3}}); err == nil {
		t.Error("count must fail once the opname is submitted")
	}
	// Negative counts are refused.
	opnameID2, itemID2 := createOpname(t, d)
	if _, err := inventory.CountOpname(ctx, d.db, gudangActor(), opnameID2,
		[]inventory.CountInput{{ItemID: itemID2, CountedQuantity: -1}}); err == nil {
		t.Error("a negative count must be refused")
	}
}

var _ = context.Background
