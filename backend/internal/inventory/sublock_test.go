package inventory_test

// Two-connection oracle: a Sub stock-request EXECUTION (location lock EXCLUSIVE, then stock rows) must never
// deadlock against a CHECKOUT (location lock SHARED first, then stock rows). The reference documents this exact
// pairing as the reason lockSubLocationForCheckout takes a SHARED lock — this test proves the Go port honours it
// with two real transactions racing at the same instant.

import (
	"context"
	"sync"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// TestSubRequestExecuteAgainstSharedCheckoutLock runs an execute pass and a shared-lock pass over the SAME Sub
// location concurrently. Both must complete (serialised by InnoDB), never error 1213/1205.
func TestSubRequestExecuteAgainstSharedCheckoutLock(t *testing.T) {
	database := openTestDB(t)
	ctx := context.Background()

	// Isolated agent + location + transit stock, so this test cannot disturb the shared fixtures.
	agentID := int64(930)
	subID := agentID + 1
	setup := []struct {
		stmt string
		args []any
	}{
		{`INSERT INTO users (id, role_id, name, status, password, created_at, updated_at)
		  SELECT ?, r.id, 'Lock Test Agent', 'active', 'x', NOW(), NOW() FROM roles r WHERE r.slug='agen'
		  AND NOT EXISTS (SELECT 1 FROM users WHERE id=?)`, []any{agentID, agentID}},
		{`INSERT INTO users (id, role_id, agent_id, name, status, password, created_at, updated_at)
		  SELECT ?, r.id, ?, 'Lock Test Sub', 'active', 'x', NOW(), NOW() FROM roles r WHERE r.slug='sales-kurir-sub'
		  AND NOT EXISTS (SELECT 1 FROM users WHERE id=?)`, []any{subID, agentID, subID}},
		{`INSERT INTO warehouse_sub_locations (id, agent_id, owner_user_id, code, name, address, is_active, created_by, created_at, updated_at)
		  SELECT ?, ?, ?, 'SUB-LOCK', 'Lock Sub', 'Jl. Lock', 1, ?, NOW(), NOW()
		  WHERE NOT EXISTS (SELECT 1 FROM warehouse_sub_locations WHERE id=?)`, []any{agentID, agentID, subID, agentID, agentID}},
		{`INSERT INTO warehouse_stocks (agent_id, product_id, stock_type, sub_location_id, quantity, created_at, updated_at)
		  VALUES (?, 1, 'transit', NULL, 50, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, []any{agentID}},
	}
	for _, step := range setup {
		if _, err := database.ExecContext(ctx, step.stmt, step.args...); err != nil {
			t.Fatalf("setup: %v", err)
		}
	}
	t.Cleanup(func() {
		_, _ = database.ExecContext(ctx, `DELETE FROM warehouse_stocks WHERE agent_id=?`, agentID)
		_, _ = database.ExecContext(ctx, `DELETE FROM warehouse_sub_locations WHERE agent_id=?`, agentID)
		_, _ = database.ExecContext(ctx, `DELETE FROM users WHERE id IN (?,?)`, agentID, subID)
	})

	// Execute pass: the same lock sequence ExecuteSubRequest takes (location EXCLUSIVE, then the stock rows).
	executePass := func() error {
		tx, err := database.BeginTx(ctx, nil)
		if err != nil {
			return err
		}
		defer func() { _ = tx.Rollback() }()
		var id int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_sub_locations WHERE id=? FOR UPDATE`, agentID).Scan(&id); err != nil {
			return err
		}
		var stockID int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_stocks
			WHERE agent_id=? AND stock_type='transit' AND sub_location_id IS NULL AND product_id=1 FOR UPDATE`,
			agentID).Scan(&stockID); err != nil {
			return err
		}
		return tx.Commit()
	}

	// Shared pass: checkout's lockSubLocationForCheckout shape (location SHARED, then the item-insert path's
	// sub reservation target rows).
	sharedPass := func() error {
		tx, err := database.BeginTx(ctx, nil)
		if err != nil {
			return err
		}
		defer func() { _ = tx.Rollback() }()
		var id int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_sub_locations WHERE id=? LOCK IN SHARE MODE`,
			agentID).Scan(&id); err != nil {
			return err
		}
		if err := inventory.LockSubReservationTargets(ctx, tx, agentID, []inventory.Target{inventory.ProductTarget(1)}); err != nil {
			return err
		}
		return tx.Commit()
	}

	var wg sync.WaitGroup
	results := make([]error, 2)
	start := make(chan struct{})
	wg.Add(2)
	go func() { defer wg.Done(); <-start; results[0] = executePass() }()
	go func() { defer wg.Done(); <-start; results[1] = sharedPass() }()
	close(start)
	wg.Wait()

	for i, err := range results {
		if err != nil {
			t.Fatalf("pass %d failed (deadlock or lock timeout): %v", i, err)
		}
	}
}

// Anti-drift guard: the config test database policy applies to this file too.
var _ = config.IsTestingDatabase

var _ = db.IsMissingSchema
