// Package inventory is the stock read model: the canonical "sellable" projection plus the warehouse
// read surfaces (stock list, sub-locations, stock cards, replenishment targets).
//
// The canonical projection is the single most reused invariant in the system — the catalog, storefront,
// checkout, fulfilment and every operational report derive availability from it. It is therefore ported
// literally from SellableStockService, including its two subtleties:
//
//  1. A target is "warehouse-authoritative" if ANY warehouse_stocks row exists for it — regardless of
//     stock_type. The sellable BASE, however, only sums `transit` (+ `factory_plan` when the agent enabled
//     the factory plan). A target holding only `sub`/`shipping` rows therefore has a warehouse base of 0,
//     not its legacy quantity_on_hand. Reproducing only the sum and not the existence test silently
//     resurrects legacy stock for such targets.
//  2. With no warehouse row at all, the legacy product_stocks/product_variation_stocks quantity_on_hand is
//     the base and the source is reported as "legacy".
//
// Money never appears here (stock is integer quantity), so no decimal handling is required.
package inventory

import (
	"context"
	"database/sql"
	"errors"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Sellable is the canonical availability projection for one target (product or variation).
type Sellable struct {
	Transit              int    `json:"transit"`
	FactoryPlan          int    `json:"factory_plan"`
	FactoryPlanEnabled   bool   `json:"factory_plan_enabled"`
	SellableBase         int    `json:"sellable_base"`
	Reserved             int    `json:"reserved"`
	Available            int    `json:"available"`
	PhysicalStock        int    `json:"physical_stock"`
	HasCommitmentDeficit bool   `json:"has_commitment_deficit"`
	Source               string `json:"source"`
}

// Target identifies what a stock row belongs to. Exactly one of ProductID / VariationID is set, which is
// also what the `warehouse_stocks` CHECK constraint enforces.
type Target struct {
	ProductID   *int64
	VariationID *int64
}

// ProductTarget builds a product target.
func ProductTarget(id int64) Target { return Target{ProductID: &id} }

// VariationTarget builds a variation target.
func VariationTarget(id int64) Target { return Target{VariationID: &id} }

// targetClause renders the WHERE fragment that scopes warehouse rows to a target, matching
// SellableStockService::calculate ($q->when($productId, ...)->whereNull('product_variation_id')).
func (t Target) clause() (string, []any) {
	if t.ProductID != nil {
		return "product_id = ? AND product_variation_id IS NULL", []any{*t.ProductID}
	}
	if t.VariationID != nil {
		return "product_variation_id = ? AND product_id IS NULL", []any{*t.VariationID}
	}
	// Unreachable for a valid target; a construction bug must not silently widen the query to the whole agent.
	return "1 = 0", nil
}

// ForProduct is SellableStockService::forProduct.
func ForProduct(ctx context.Context, d *db.DB, agentID, productID int64) (Sellable, error) {
	return calculate(ctx, d, agentID, ProductTarget(productID))
}

// ForVariation is SellableStockService::forVariation.
func ForVariation(ctx context.Context, d *db.DB, agentID, variationID int64) (Sellable, error) {
	return calculate(ctx, d, agentID, VariationTarget(variationID))
}

// calculate ports SellableStockService::calculate for a read-only (non-locking) caller.
//
// The read path must NOT take row locks: a display must never serialise behind a capacity decision.
func calculate(ctx context.Context, d *db.DB, agentID int64, target Target) (Sellable, error) {
	clause, args := target.clause()

	var transit, plan int
	err := d.QueryRowContext(ctx, `SELECT
			COALESCE(SUM(CASE WHEN stock_type = 'transit' THEN quantity ELSE 0 END), 0),
			COALESCE(SUM(CASE WHEN stock_type = 'factory_plan' THEN quantity ELSE 0 END), 0)
		FROM warehouse_stocks
		WHERE agent_id = ? AND `+clause+` AND stock_type IN ('transit','factory_plan')`,
		append([]any{agentID}, args...)...).Scan(&transit, &plan)
	if err != nil {
		return Sellable{}, err
	}

	// The existence test deliberately does NOT filter stock_type (see the package comment).
	var hasWarehouseRows bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_stocks WHERE agent_id = ? AND `+clause+`)`,
		append([]any{agentID}, args...)...).Scan(&hasWarehouseRows); err != nil {
		return Sellable{}, err
	}

	planEnabled, err := factoryPlanEnabled(ctx, d, agentID)
	if err != nil {
		return Sellable{}, err
	}
	reserved, err := reservedFor(ctx, d, agentID, target)
	if err != nil {
		return Sellable{}, err
	}

	if !hasWarehouseRows {
		legacy, err := legacyOnHand(ctx, d, agentID, target)
		if err != nil {
			return Sellable{}, err
		}
		return Sellable{
			Transit:              legacy,
			FactoryPlan:          0,
			FactoryPlanEnabled:   false,
			SellableBase:         legacy,
			Reserved:             reserved,
			Available:            max0(legacy - reserved),
			PhysicalStock:        legacy,
			HasCommitmentDeficit: reserved > legacy,
			Source:               "legacy",
		}, nil
	}

	base := transit
	if planEnabled {
		base += plan
	}
	return Sellable{
		Transit:              transit,
		FactoryPlan:          plan,
		FactoryPlanEnabled:   planEnabled,
		SellableBase:         base,
		Reserved:             reserved,
		Available:            max0(base - reserved),
		PhysicalStock:        transit,
		HasCommitmentDeficit: reserved > base,
		Source:               "warehouse",
	}, nil
}

// factoryPlanEnabled reads warehouse_settings.factory_plan_enabled (absent row = false).
func factoryPlanEnabled(ctx context.Context, d *db.DB, agentID int64) (bool, error) {
	var enabled sql.NullBool
	err := d.QueryRowContext(ctx, `SELECT factory_plan_enabled FROM warehouse_settings WHERE agent_id = ? LIMIT 1`, agentID).Scan(&enabled)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return enabled.Valid && enabled.Bool, nil
}

// reservedFor reads the canonical reserved quantity from the legacy commitment row.
func reservedFor(ctx context.Context, d *db.DB, agentID int64, target Target) (int, error) {
	var reserved int
	var err error
	if target.ProductID != nil {
		err = d.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_stocks WHERE agent_id = ? AND product_id = ? LIMIT 1`,
			agentID, *target.ProductID).Scan(&reserved)
	} else {
		err = d.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_variation_stocks WHERE agent_id = ? AND product_variation_id = ? LIMIT 1`,
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

// reservedForTx is reservedFor inside a transaction (the opname plan check runs under the opname lock).
func reservedForTx(ctx context.Context, tx *sql.Tx, agentID int64, target Target) (int, error) {
	var reserved int
	var err error
	if target.ProductID != nil {
		err = tx.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_stocks WHERE agent_id = ? AND product_id = ? LIMIT 1`,
			agentID, *target.ProductID).Scan(&reserved)
	} else {
		err = tx.QueryRowContext(ctx, `SELECT quantity_reserved FROM product_variation_stocks WHERE agent_id = ? AND product_variation_id = ? LIMIT 1`,
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

// legacyOnHand reads the legacy quantity_on_hand.
func legacyOnHand(ctx context.Context, d *db.DB, agentID int64, target Target) (int, error) {
	var onHand int
	var err error
	if target.ProductID != nil {
		err = d.QueryRowContext(ctx, `SELECT quantity_on_hand FROM product_stocks WHERE agent_id = ? AND product_id = ? LIMIT 1`,
			agentID, *target.ProductID).Scan(&onHand)
	} else {
		err = d.QueryRowContext(ctx, `SELECT quantity_on_hand FROM product_variation_stocks WHERE agent_id = ? AND product_variation_id = ? LIMIT 1`,
			agentID, *target.VariationID).Scan(&onHand)
	}
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	return onHand, nil
}

func max0(v int) int {
	if v < 0 {
		return 0
	}
	return v
}
