package inventory_test

// Two-connection concurrency tests for the inventory mutation domain.
//
// These are the tests the migration brief requires: they prove the canonical lock order actually serialises
// competing writers, using TWO independent database connections (two real transactions), not a mock.
//
// What is verified:
//  1. Serialisation of two approvals on the SAME target: the second waits for the first and then either
//     completes or fails cleanly on insufficient stock — never both succeeding on the same units.
//  2. Opposite-order multi-target transfers do not deadlock (they would if the lock order were ad hoc).
//  3. A rollback leaves no partial stock movement and no quantity change.
//  4. The approval is idempotent: replaying it cannot move stock twice.
//
// Skipped when the isolated test database is not reachable.

import (
	"context"
	"database/sql"
	"os"
	"sync"
	"testing"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

func openTestDB(t *testing.T) *db.DB {
	t.Helper()
	envPath := os.Getenv("GO_ENV_FILE")
	if envPath == "" {
		envPath = "../../.env"
	}
	if _, err := os.Stat(envPath); err != nil {
		t.Skipf("no %s; run tools/parity/run_go_tests.sh", envPath)
	}
	cfg, err := config.Load(config.FileGetter(envPath))
	if err != nil {
		t.Fatalf("config: %v", err)
	}
	if !config.IsTestingDatabase(cfg.DB.Database) {
		t.Fatalf("refusing to run against %q (must contain _test/_testing)", cfg.DB.Database)
	}
	database, err := db.Open(context.Background(), cfg.DB)
	if err != nil {
		t.Skipf("test database unreachable: %v", err)
	}
	t.Cleanup(func() { _ = database.Close() })
	return database
}

func agentActor() inventory.Actor {
	return inventory.Actor{ID: 10, AgentID: 10, Role: "agen"}
}

func adminActor() inventory.Actor {
	return inventory.Actor{ID: 11, AgentID: 10, Role: "admin"}
}

func gudangActor() inventory.Actor {
	return inventory.Actor{ID: 15, AgentID: 10, Role: "gudang"}
}

// resetTransfers restores the inventory fixture baseline so every test starts from identical rows.
//
// It clears what a mutation test creates (transfers, handovers, transfer movements, warehouse rows created by
// the destination logic) and resets the six seeded warehouse quantities. Business fixtures (products, users,
// sub-locations) are never touched.
func resetTransfers(t *testing.T, d *db.DB) {
	t.Helper()
	ctx := context.Background()
	statements := []string{
		`DELETE FROM stock_handovers`,
		`DELETE FROM stock_transfer_items`,
		`DELETE FROM stock_transfers`,
		`DELETE FROM stock_movements WHERE type IN ('transfer_in','transfer_out','adjustment')`,
		`DELETE FROM warehouse_stocks WHERE id > 7`, // ids created by a destination insert
		`UPDATE stock_movements SET id = id WHERE 1=0`,
		`UPDATE warehouse_stocks SET quantity = 12 WHERE id = 2`,
		`UPDATE warehouse_stocks SET quantity = 4  WHERE id = 3`,
		`UPDATE warehouse_stocks SET quantity = 9  WHERE id = 4`,
		`UPDATE warehouse_stocks SET quantity = 6  WHERE id = 5`,
		`UPDATE warehouse_stocks SET quantity = 8  WHERE id = 6`,
		`UPDATE warehouse_stocks SET quantity = 3  WHERE id = 7`,
	}
	for _, stmt := range statements {
		if _, err := d.ExecContext(ctx, stmt); err != nil {
			t.Fatalf("reset (%s): %v", stmt, err)
		}
	}
}

func int64Ptr(v int64) *int64 { return &v }

// transferQuantity reads one warehouse bucket quantity.
func transferQuantity(t *testing.T, d *db.DB, target inventory.Target, stockType string) int64 {
	t.Helper()
	clause := "product_id = ? AND product_variation_id IS NULL"
	args := []any{10, stockType}
	if target.VariationID != nil {
		clause = "product_variation_id = ? AND product_id IS NULL"
		args = append(args, *target.VariationID)
	} else {
		args = append(args, *target.ProductID)
	}
	var qty int64
	err := d.QueryRowContext(context.Background(),
		`SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks WHERE agent_id = ? AND stock_type = ? AND `+clause,
		args...).Scan(&qty)
	if err != nil {
		t.Fatalf("read quantity: %v", err)
	}
	return qty
}

// createTransfer creates a pending transfer through the real service.
func createTransfer(t *testing.T, d *db.DB, source, dest string, destSub *int64, target inventory.Target, qty int64) int64 {
	t.Helper()
	row, err := inventory.CreateTransfer(context.Background(), d, gudangActor(), inventory.CreateTransferInput{
		SourceStockType:          source,
		DestinationStockType:     dest,
		DestinationSubLocationID: destSub,
		Items: []inventory.TransferItemInput{{
			ProductID:   target.ProductID,
			VariationID: target.VariationID,
			Quantity:    qty,
		}},
	})
	if err != nil {
		t.Fatalf("create transfer: %v", err)
	}
	return int64(row["id"].(int64))
}

// TestTwoConnectionsSerialiseSameTarget is the core invariant: two approvals competing for the same units must
// NOT both succeed. With the canonical lock order the second transaction blocks on the locked transfer row /
// stock row and then fails on insufficient stock.
func TestTwoConnectionsSerialiseSameTarget(t *testing.T) {
	d := openTestDB(t)
	resetTransfers(t, d)
	ctx := context.Background()

	target := inventory.ProductTarget(2) // transit 12 in the fixtures
	before := transferQuantity(t, d, target, "transit")

	// Two transfers, each taking 8 of the same 12 units: only one can complete.
	idA := createTransfer(t, d, "transit", "sub", int64Ptr(4), target, 8)
	idB := createTransfer(t, d, "transit", "sub", int64Ptr(4), target, 8)

	var wg sync.WaitGroup
	results := make([]error, 2)
	start := make(chan struct{})
	for i, id := range []int64{idA, idB} {
		wg.Add(1)
		go func(idx int, transferID int64) {
			defer wg.Done()
			<-start // release both at the same instant
			_, err := inventory.ApproveTransfer(ctx, d, adminActor(), transferID)
			results[idx] = err
		}(i, id)
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
		t.Fatalf("exactly one approval may succeed, got %d (errors: %v %v)", success, results[0], results[1])
	}

	after := transferQuantity(t, d, target, "transit")
	if after != before-8 {
		t.Errorf("transit after = %d, want %d (exactly one transfer of 8 applied)", after, before-8)
	}
	if after < 0 {
		t.Error("transit went negative — the insufficient-stock check must fire under the lock")
	}

	// The loser must be left pending (not completed), so it can be cancelled by a human.
	var completed int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_transfers WHERE status = 'completed'`).Scan(&completed); err != nil {
		t.Fatal(err)
	}
	if completed != 1 {
		t.Errorf("completed transfers = %d, want 1", completed)
	}
}

// TestOppositeOrderMultiTargetDoesNotDeadlock proves the canonical target ordering: two transfers listing the
// SAME two targets in OPPOSITE order must both complete (serialised), never deadlock.
//
// Without the canonical order this is the classic deadlock: A locks t1 then waits on t2 while B locks t2 then
// waits on t1. MySQL would answer 1213 for one of them — which AGENTS.md forbids "fixing" with sleeps.
func TestOppositeOrderMultiTargetDoesNotDeadlock(t *testing.T) {
	d := openTestDB(t)
	resetTransfers(t, d)
	ctx := context.Background()

	p1 := inventory.ProductTarget(2)   // transit 12
	v2 := inventory.VariationTarget(2) // transit 9

	// Two-line transfers, declared in opposite orders.
	orderA := []inventory.TransferItemInput{
		{ProductID: int64Ptr(2), Quantity: 1},
		{VariationID: int64Ptr(2), Quantity: 1},
	}
	orderB := []inventory.TransferItemInput{
		{VariationID: int64Ptr(2), Quantity: 1},
		{ProductID: int64Ptr(2), Quantity: 1},
	}
	mk := func(items []inventory.TransferItemInput) int64 {
		row, err := inventory.CreateTransfer(ctx, d, gudangActor(), inventory.CreateTransferInput{
			SourceStockType:          "transit",
			DestinationStockType:     "sub",
			DestinationSubLocationID: int64Ptr(4),
			Items:                    items,
		})
		if err != nil {
			t.Fatalf("create: %v", err)
		}
		return int64(row["id"].(int64))
	}
	idA, idB := mk(orderA), mk(orderB)

	beforeP1 := transferQuantity(t, d, p1, "transit")
	beforeV2 := transferQuantity(t, d, v2, "transit")

	var wg sync.WaitGroup
	errs := make([]error, 2)
	start := make(chan struct{})
	for i, id := range []int64{idA, idB} {
		wg.Add(1)
		go func(idx int, transferID int64) {
			defer wg.Done()
			<-start
			_, err := inventory.ApproveTransfer(ctx, d, adminActor(), transferID)
			errs[idx] = err
		}(i, id)
	}
	close(start)
	wg.Wait()

	for i, err := range errs {
		if err != nil {
			if db.IsDeadlock(err) {
				t.Fatalf("approval %d hit a deadlock (1213) — the canonical target order is not being used: %v", i, err)
			}
			t.Fatalf("approval %d failed: %v", i, err)
		}
	}

	if got := transferQuantity(t, d, p1, "transit"); got != beforeP1-2 {
		t.Errorf("product transit = %d, want %d (both transfers applied)", got, beforeP1-2)
	}
	if got := transferQuantity(t, d, v2, "transit"); got != beforeV2-2 {
		t.Errorf("variation transit = %d, want %d (both transfers applied)", got, beforeV2-2)
	}
}

// TestApproveIsIdempotent proves a replay cannot move stock twice.
func TestApproveIsIdempotent(t *testing.T) {
	d := openTestDB(t)
	resetTransfers(t, d)
	ctx := context.Background()

	target := inventory.ProductTarget(2)
	before := transferQuantity(t, d, target, "transit")
	id := createTransfer(t, d, "transit", "sub", int64Ptr(4), target, 3)

	if _, err := inventory.ApproveTransfer(ctx, d, adminActor(), id); err != nil {
		t.Fatalf("first approve: %v", err)
	}
	afterFirst := transferQuantity(t, d, target, "transit")
	if afterFirst != before-3 {
		t.Fatalf("after first approve transit = %d, want %d", afterFirst, before-3)
	}

	// Replay.
	if _, err := inventory.ApproveTransfer(ctx, d, adminActor(), id); err != nil {
		t.Fatalf("replay approve must be a no-op, got %v", err)
	}
	afterSecond := transferQuantity(t, d, target, "transit")
	if afterSecond != afterFirst {
		t.Errorf("replay moved stock again: %d → %d", afterFirst, afterSecond)
	}

	// Exactly two movements (one out, one in) for the whole transfer.
	var movements int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_movements WHERE transfer_id = ?`, id).Scan(&movements); err != nil {
		t.Fatal(err)
	}
	if movements != 2 {
		t.Errorf("movements = %d, want 2 (replay must not write more)", movements)
	}
}

// TestInsufficientStockRollsBackEverything proves the failure path leaves NO partial state: no quantity change,
// no movements, no handover, and the transfer stays pending.
func TestInsufficientStockRollsBackEverything(t *testing.T) {
	d := openTestDB(t)
	resetTransfers(t, d)
	ctx := context.Background()

	target := inventory.ProductTarget(2)
	before := transferQuantity(t, d, target, "transit")
	// Ask for more than the 12 available.
	id := createTransfer(t, d, "transit", "sub", int64Ptr(4), target, 999)

	_, err := inventory.ApproveTransfer(ctx, d, adminActor(), id)
	if err == nil {
		t.Fatal("approved a transfer larger than available stock")
	}

	if got := transferQuantity(t, d, target, "transit"); got != before {
		t.Errorf("transit changed on a failed approval: %d → %d", before, got)
	}
	var movements, handovers int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_movements WHERE transfer_id = ?`, id).Scan(&movements); err != nil {
		t.Fatal(err)
	}
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_handovers WHERE stock_transfer_id = ?`, id).Scan(&handovers); err != nil {
		t.Fatal(err)
	}
	if movements != 0 {
		t.Errorf("movements written for a failed approval: %d", movements)
	}
	if handovers != 0 {
		t.Errorf("handover created for a failed approval: %d", handovers)
	}
	var status string
	if err := d.QueryRowContext(ctx, `SELECT status FROM stock_transfers WHERE id = ?`, id).Scan(&status); err != nil {
		t.Fatal(err)
	}
	if status != "pending" {
		t.Errorf("status = %q, want pending after a rolled-back approval", status)
	}
}

// TestCancelDoesNotTouchStock proves the non-completing path is inert.
func TestCancelDoesNotTouchStock(t *testing.T) {
	d := openTestDB(t)
	resetTransfers(t, d)
	ctx := context.Background()

	target := inventory.ProductTarget(2)
	before := transferQuantity(t, d, target, "transit")
	id := createTransfer(t, d, "transit", "sub", int64Ptr(4), target, 2)

	if _, err := inventory.CancelTransfer(ctx, d, gudangActor(), id); err != nil {
		t.Fatalf("cancel: %v", err)
	}
	if got := transferQuantity(t, d, target, "transit"); got != before {
		t.Errorf("cancel changed transit: %d → %d", before, got)
	}
	// A cancelled transfer cannot then be approved.
	if _, err := inventory.ApproveTransfer(ctx, d, adminActor(), id); err == nil {
		t.Error("a cancelled transfer must not be approvable")
	}
	if got := transferQuantity(t, d, target, "transit"); got != before {
		t.Errorf("failed approval of a cancelled transfer changed transit: %d → %d", before, got)
	}
}

// TestTransitSubTakesAgentCapacityRowFirst documents and verifies the one lock that makes a transit -> sub move
// safe: it must not starve a committed agent reservation. The test holds the agent commitment row in another
// connection and asserts the approval waits (i.e. it really does take that lock first).
func TestTransitSubTakesAgentCapacityRowFirst(t *testing.T) {
	d := openTestDB(t)
	resetTransfers(t, d)
	ctx := context.Background()

	target := inventory.ProductTarget(2)
	id := createTransfer(t, d, "transit", "sub", int64Ptr(4), target, 1)

	// Hold the agent commitment row in an independent transaction.
	holder, err := d.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	var stockID int64
	if err := holder.QueryRowContext(ctx, `SELECT id FROM product_stocks WHERE agent_id = 10 AND product_id = 2 FOR UPDATE`).
		Scan(&stockID); err != nil {
		// Product 2 may have no commitment row in the fixtures; the lock is then a gap lock and the approval
		// proceeds — in that case the test is not meaningful and is skipped rather than faked.
		_ = holder.Rollback()
		t.Skipf("no product_stocks row for product 2 (%v); the capacity lock is a no-op here", err)
	}

	done := make(chan error, 1)
	go func() {
		_, err := inventory.ApproveTransfer(ctx, d, adminActor(), id)
		done <- err
	}()

	select {
	case err := <-done:
		_ = holder.Rollback()
		t.Fatalf("approval completed while the agent capacity row was locked (it must wait): %v", err)
	case <-time.After(2 * time.Second):
		// Correct: the approval is blocked on the capacity row.
	}
	_ = holder.Rollback()

	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("approval failed after the lock was released: %v", err)
		}
	case <-time.After(15 * time.Second):
		t.Fatal("approval did not proceed after the capacity lock was released")
	}
}

// TestPlanTransferRejectsPlainTransferRoute proves a factory_plan transfer cannot be created through the plain
// transfer endpoint (it needs the plan-to-transit route, which is not ported yet).
func TestPlanTransferRejectsPlainTransferRoute(t *testing.T) {
	d := openTestDB(t)
	ctx := context.Background()

	_, err := inventory.CreateTransfer(ctx, d, gudangActor(), inventory.CreateTransferInput{
		SourceStockType:      "factory_plan",
		DestinationStockType: "transit",
		Items:                []inventory.TransferItemInput{{ProductID: int64Ptr(2), Quantity: 1}},
	})
	if err == nil {
		t.Fatal("a factory_plan transfer must be refused on the plain transfer endpoint")
	}
}

var _ = sql.ErrNoRows
