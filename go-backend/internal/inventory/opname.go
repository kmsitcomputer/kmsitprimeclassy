// Package inventory — stock opname (physical / plan reconciliation).
//
// LOCK ORDER (StockOpnameService, preserved):
//
//	create : sub_location row (if any) -> warehouse stock row -> revision row  (per target, in
//	         canonicalReservationTargets order, so the snapshot takes the SAME locks multi-target writers take)
//	count  : stock_opnames row -> item rows
//	submit : stock_opnames row
//	approve: stock_opnames row -> sub_location row (for stock_type=sub) -> per item, in canonical target
//	         order: warehouse stock row (locked) ... writes ... then the status update
//	reject/cancel: stock_opnames row
//
// The approve path also re-validates the snapshot: if the stock changed since counting, it answers **409**
// ("Stock changed after this opname was counted. Recount is required.") instead of writing a stale correction.
// That check compares the row identity, the quantity AND the physical revision counter, which is why
// PhysicalStockRevision is a dependency of this package.
package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// revisionKey is PhysicalStockRevision's target_key: agent:type:location:(p|v):id.
func revisionKey(agentID int64, stockType string, locationID *int64, productID, variationID *int64) string {
	loc := int64(0)
	if locationID != nil {
		loc = *locationID
	}
	if variationID != nil {
		return fmt.Sprintf("%s:%s:%d:v:%d", itoa(agentID), stockType, loc, *variationID)
	}
	id := int64(0)
	if productID != nil {
		id = *productID
	}
	return fmt.Sprintf("%s:%s:%d:p:%d", itoa(agentID), stockType, loc, id)
}

// CurrentRevision is PhysicalStockRevision::current — it provisions the counter row and returns it under a
// locking read, so a concurrent writer that bumps the revision is observed.
func CurrentRevision(ctx context.Context, q queryExecer, agentID int64, stockType string, locationID, productID, variationID *int64) (int64, error) {
	key := revisionKey(agentID, stockType, locationID, productID, variationID)
	if _, err := q.ExecContext(ctx, `INSERT IGNORE INTO warehouse_stock_revisions (target_key, revision) VALUES (?, 0)`, key); err != nil {
		return 0, err
	}
	var revision int64
	err := q.QueryRowContext(ctx, `SELECT revision FROM warehouse_stock_revisions WHERE target_key = ? FOR UPDATE`, key).Scan(&revision)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	return revision, err
}

// OpnameItemInput is one target requested at creation.
type OpnameItemInput struct {
	ProductID   *int64
	VariationID *int64
}

// CreateOpname is StockOpnameService::create.
func CreateOpname(ctx context.Context, d *db.DB, actor Actor, opnameType string, stockType *string, subLocationID *int64, items []OpnameItemInput, notes *string) (map[string]any, error) {
	if !actor.IsRole("gudang") || actor.AgentID == 0 {
		return nil, ErrGudangOnly()
	}
	if err := assertOpnameTypeTarget(opnameType, stockType, subLocationID); err != nil {
		return nil, err
	}
	if opnameType == "sellable_reconciliation" {
		return nil, transferInvalid("Sellable reconciliation bersifat read-only.")
	}
	if len(items) == 0 {
		return nil, transferInvalid("Target reconciliation tidak valid.")
	}
	st := ""
	if stockType != nil {
		st = *stockType
	}

	var opnameID int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		seen := map[string]bool{}
		targets := make([]Target, 0, len(items))
		for _, it := range items {
			if err := assertOpnameItem(ctx, tx, it.ProductID, it.VariationID, subLocationID, actor.AgentID); err != nil {
				return err
			}
			key := targetKey(it.ProductID, it.VariationID)
			if seen[key] {
				return transferInvalid("Target opname tidak boleh duplikat.")
			}
			seen[key] = true
			targets = append(targets, Target{ProductID: it.ProductID, VariationID: it.VariationID})
		}

		number, err := uniqueOpnameNumber(ctx, tx)
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO stock_opnames
			(agent_id, opname_number, opname_type, status, stock_type, sub_location_id, notes, created_by,
			 created_at, updated_at)
			VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			actor.AgentID, number, opnameType, nullableStrMember(st), nullableInt(subLocationID),
			nullableStrPtr(notes), actor.ID)
		if err != nil {
			return err
		}
		opnameID, _ = res.LastInsertId()

		// Snapshot in the canonical target order (the same order a multi-target writer locks in).
		ordered := map[string]Target{}
		for _, t := range targets {
			ordered[targetKey(t.ProductID, t.VariationID)] = t
		}
		for _, key := range sortTargets(ordered) {
			t := ordered[key]
			snap, err := snapshotStock(ctx, tx, actor.AgentID, st, subLocationID, t)
			if err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO stock_opname_items
				(stock_opname_id, product_id, product_variation_id, sub_location_id, system_quantity,
				 system_stock_id, system_stock_revision, created_at, updated_at)
				VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
				opnameID, nullableInt(t.ProductID), nullableInt(t.VariationID), nullableInt(subLocationID),
				snap.quantity, nullableInt(snap.stockID), snap.revision); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return LoadOpname(ctx, d, actor, opnameID)
}

type snapshot struct {
	quantity int64
	stockID  *int64
	revision int64
}

// snapshotStock reads (and locks) the current warehouse row plus the revision counter for one target.
func snapshotStock(ctx context.Context, tx *sql.Tx, agentID int64, stockType string, subLocationID *int64, target Target) (snapshot, error) {
	if subLocationID != nil {
		var id int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_sub_locations WHERE id = ? FOR UPDATE`, *subLocationID).Scan(&id); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return snapshot{}, transferInvalid("Target reconciliation tidak valid.")
			}
			return snapshot{}, err
		}
	}
	row, err := lockWarehouseRow(ctx, tx, agentID, target, stockType, subLocationID)
	if err != nil {
		return snapshot{}, err
	}
	revision, err := CurrentRevision(ctx, tx, agentID, stockType, subLocationID, target.ProductID, target.VariationID)
	if err != nil {
		return snapshot{}, err
	}
	snap := snapshot{revision: revision}
	if row != nil {
		id := row.ID
		snap.stockID = &id
		snap.quantity = row.Quantity
	}
	return snap, nil
}

func assertOpnameTypeTarget(opnameType string, stockType *string, subLocationID *int64) error {
	if opnameType != "physical_opname" && opnameType != "plan_reconciliation" {
		return transferInvalid("Target reconciliation tidak valid.")
	}
	st := ""
	if stockType != nil {
		st = *stockType
	}
	if opnameType == "physical_opname" {
		if st != "transit" && st != "shipping" && st != "sub" {
			return transferInvalid("Target reconciliation tidak valid.")
		}
	}
	if opnameType == "plan_reconciliation" && st != "factory_plan" {
		return transferInvalid("Target reconciliation tidak valid.")
	}
	if (st == "sub") != (subLocationID != nil) {
		return transferInvalid("Target reconciliation tidak valid.")
	}
	return nil
}

func assertOpnameItem(ctx context.Context, tx *sql.Tx, productID, variationID *int64, subLocationID *int64, agentID int64) error {
	if (productID == nil) == (variationID == nil) {
		return transferInvalid("Target item opname tidak valid.")
	}
	if subLocationID != nil {
		var ok bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_sub_locations
			WHERE id = ? AND agent_id = ? AND is_active = 1)`, *subLocationID, agentID).Scan(&ok); err != nil {
			return err
		}
		if !ok {
			return transferInvalid("Target item opname tidak valid.")
		}
	}
	return nil
}

// CountOpname is StockOpnameService::count.
func CountOpname(ctx context.Context, d *db.DB, actor Actor, opnameID int64, counts []CountInput) (map[string]any, error) {
	if !actor.IsRole("gudang") {
		return nil, transferInvalid("Opname tidak dapat diedit.")
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		op, err := lockOpname(ctx, tx, actor, opnameID)
		if err != nil {
			return err
		}
		if !actor.IsRole("gudang") || op.AgentID != actor.AgentID {
			return ErrNotFound
		}
		if op.Status != "draft" {
			return transferInvalid("Opname tidak dapat diedit.")
		}
		for _, c := range counts {
			if c.CountedQuantity < 0 {
				return transferInvalid("Jumlah hitung tidak boleh negatif.")
			}
			var systemQty int64
			if err := tx.QueryRowContext(ctx, `SELECT system_quantity FROM stock_opname_items
				WHERE id = ? AND stock_opname_id = ? FOR UPDATE`, c.ItemID, opnameID).Scan(&systemQty); err != nil {
				if errors.Is(err, sql.ErrNoRows) {
					return ErrNotFound
				}
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE stock_opname_items
				SET counted_quantity = ?, difference = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
				c.CountedQuantity, c.CountedQuantity-systemQty, c.ItemID); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return LoadOpname(ctx, d, actor, opnameID)
}

// CountInput is one counted line.
type CountInput struct {
	ItemID          int64
	CountedQuantity int64
}

// SubmitOpname is StockOpnameService::submit.
func SubmitOpname(ctx context.Context, d *db.DB, actor Actor, opnameID int64) (map[string]any, error) {
	if !actor.IsRole("gudang") {
		return nil, transferInvalid("Opname belum siap disubmit.")
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		op, err := lockOpname(ctx, tx, actor, opnameID)
		if err != nil {
			return err
		}
		if op.Status != "draft" {
			return transferInvalid("Opname belum siap disubmit.")
		}
		var uncounted int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_opname_items
			WHERE stock_opname_id = ? AND counted_quantity IS NULL`, opnameID).Scan(&uncounted); err != nil {
			return err
		}
		if uncounted > 0 {
			return transferInvalid("Opname belum siap disubmit.")
		}
		_, err = tx.ExecContext(ctx, `UPDATE stock_opnames
			SET status = 'submitted', submitted_by = ?, submitted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actor.ID, opnameID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return LoadOpname(ctx, d, actor, opnameID)
}

// ApproveOpname is StockOpnameService::approve — the corrective write, with the stale-snapshot 409.
func ApproveOpname(ctx context.Context, d *db.DB, actor Actor, opnameID int64) (map[string]any, error) {
	if !actor.IsRole("admin") {
		return nil, &DomainError{Status: 403, Message: "Hanya Admin yang dapat menyetujui opname."}
	}

	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		op, err := lockOpname(ctx, tx, actor, opnameID)
		if err != nil {
			return err
		}
		if op.Status == "approved" {
			return nil // idempotent
		}
		if op.Status != "submitted" {
			return transferInvalid("Hanya opname submitted yang dapat disetujui.")
		}
		physical := op.OpnameType == "physical_opname"
		plan := op.OpnameType == "plan_reconciliation"
		if !physical && !plan {
			return transferInvalid("Tipe opname ini tidak dapat disetujui.")
		}
		if op.StockType == "sub" && op.SubLocationID != nil {
			var id int64
			if err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_sub_locations WHERE id = ? FOR UPDATE`,
				*op.SubLocationID).Scan(&id); err != nil {
				if errors.Is(err, sql.ErrNoRows) {
					return ErrNotFound
				}
				return err
			}
		}

		items, err := opnameItemsTx(ctx, tx, opnameID)
		if err != nil {
			return err
		}
		// Canonical target order for the per-item locks + writes.
		byKey := map[string]OpnameItem{}
		for _, it := range items {
			byKey[targetKey(it.ProductID, it.VariationID)] = it
		}
		var ordered []OpnameItem
		keys := make([]string, 0, len(byKey))
		for k := range byKey {
			keys = append(keys, k)
		}
		sortStrings(keys)
		for _, k := range keys {
			ordered = append(ordered, byKey[k])
		}

		planEnabled, err := factoryPlanEnabled(ctx, d, op.AgentID)
		if err != nil {
			return err
		}

		for _, item := range ordered {
			target := Target{ProductID: item.ProductID, VariationID: item.VariationID}
			stock, err := lockWarehouseRow(ctx, tx, op.AgentID, target, op.StockType, op.SubLocationID)
			if err != nil {
				return err
			}
			current := int64(0)
			var currentID *int64
			if stock != nil {
				current = stock.Quantity
				id := stock.ID
				currentID = &id
			}

			if current != item.SystemQuantity {
				return &DomainError{Status: 409, Message: "Stock changed after this opname was counted. Recount is required."}
			}
			// Revision check: only when the item was snapshotted with revision tracking (any item created by
			// this implementation is; legacy rows may not be).
			if item.SystemStockRevision != nil {
				same := (item.SystemStockID == nil && currentID == nil) ||
					(item.SystemStockID != nil && currentID != nil && *item.SystemStockID == *currentID)
				revision, err := CurrentRevision(ctx, tx, op.AgentID, op.StockType, op.SubLocationID,
					item.ProductID, item.VariationID)
				if err != nil {
					return err
				}
				if !same || revision != *item.SystemStockRevision {
					return &DomainError{Status: 409, Message: "Stock changed after this opname was counted. Recount is required."}
				}
			}

			counted := int64(0)
			if item.CountedQuantity != nil {
				counted = *item.CountedQuantity
			}
			delta := counted - current
			if delta == 0 {
				continue
			}

			if plan {
				if !planEnabled || current+delta < 0 {
					return transferInvalid("Plan reconciliation tidak valid.")
				}
				reserved, err := reservedForTx(ctx, tx, op.AgentID, target)
				if err != nil {
					return err
				}
				transitRow, err := lockWarehouseRow(ctx, tx, op.AgentID, target, "transit", nil)
				if err != nil {
					return err
				}
				transit := int64(0)
				if transitRow != nil {
					transit = transitRow.Quantity
				}
				if transit+current+delta < int64(reserved) {
					return transferInvalid("Plan reconciliation melampaui komitmen reservasi.")
				}
			}

			if stock != nil {
				if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = ?, updated_at = UTC_TIMESTAMP()
					WHERE id = ?`, counted, stock.ID); err != nil {
					return err
				}
			} else {
				if _, err := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks
					(agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity,
					 created_at, updated_at)
					VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
					op.AgentID, nullableInt(item.ProductID), nullableInt(item.VariationID), op.StockType,
					nullableInt(op.SubLocationID), counted); err != nil {
					return err
				}
			}

			movementType := "opname_adjustment"
			if plan {
				if delta > 0 {
					movementType = "factory_plan_in"
				} else {
					movementType = "factory_plan_out"
				}
			}
			if err := writeMovementOpname(ctx, tx, movement{
				AgentID: op.AgentID, ProductID: item.ProductID, VariationID: item.VariationID,
				Type: movementType, Quantity: delta, StockType: op.StockType,
				SubLocationID: op.SubLocationID, CreatedBy: actor.ID,
			}, opnameID); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE stock_opname_items
				SET difference = ?, adjusted_quantity = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
				delta, counted, item.ID); err != nil {
				return err
			}
		}

		_, err = tx.ExecContext(ctx, `UPDATE stock_opnames
			SET status = 'approved', approved_by = ?, approved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actor.ID, opnameID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return LoadOpname(ctx, d, actor, opnameID)
}

// RejectOpname is StockOpnameService::reject.
func RejectOpname(ctx context.Context, d *db.DB, actor Actor, opnameID int64, reason string) (map[string]any, error) {
	if !actor.IsRole("admin") {
		return nil, transferInvalid("Opname tidak dapat ditolak.")
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		op, err := lockOpname(ctx, tx, actor, opnameID)
		if err != nil {
			return err
		}
		if op.Status != "submitted" {
			return transferInvalid("Opname tidak dapat ditolak.")
		}
		_, err = tx.ExecContext(ctx, `UPDATE stock_opnames
			SET status = 'rejected', rejected_by = ?, rejected_at = UTC_TIMESTAMP(), rejection_reason = ?,
			    updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actor.ID, reason, opnameID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return LoadOpname(ctx, d, actor, opnameID)
}

// CancelOpname is StockOpnameService::cancel.
func CancelOpname(ctx context.Context, d *db.DB, actor Actor, opnameID int64) (map[string]any, error) {
	if !actor.IsRole("gudang") {
		return nil, transferInvalid("Opname tidak dapat dibatalkan.")
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		op, err := lockOpname(ctx, tx, actor, opnameID)
		if err != nil {
			return err
		}
		if op.Status != "draft" {
			return transferInvalid("Opname tidak dapat dibatalkan.")
		}
		_, err = tx.ExecContext(ctx, `UPDATE stock_opnames SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, opnameID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return LoadOpname(ctx, d, actor, opnameID)
}

// Opname is one stock_opnames row.
type Opname struct {
	ID            int64
	AgentID       int64
	OpnameNumber  string
	OpnameType    string
	Status        string
	StockType     string
	SubLocationID *int64
	Notes         *string
	CreatedBy     int64
}

// OpnameItem is one stock_opname_items row.
type OpnameItem struct {
	ID                  int64
	ProductID           *int64
	VariationID         *int64
	SubLocationID       *int64
	SystemQuantity      int64
	CountedQuantity     *int64
	SystemStockID       *int64
	SystemStockRevision *int64
}

// lockOpname locks one opname row and applies the tenant rule (another network is a 404).
func lockOpname(ctx context.Context, tx *sql.Tx, actor Actor, id int64) (*Opname, error) {
	var (
		op          Opname
		subLocation sql.NullInt64
		stockType   sql.NullString
		notes       sql.NullString
	)
	err := tx.QueryRowContext(ctx, `SELECT id, agent_id, opname_number, opname_type, status, stock_type,
			sub_location_id, notes, created_by
		FROM stock_opnames WHERE id = ? AND agent_id = ? FOR UPDATE`, id, actor.AgentID).
		Scan(&op.ID, &op.AgentID, &op.OpnameNumber, &op.OpnameType, &op.Status, &stockType, &subLocation, &notes, &op.CreatedBy)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	op.SubLocationID = nullInt64Ptr(subLocation)
	op.Notes = strPtr(notes)
	op.StockType = stockType.String
	return &op, nil
}

func opnameItemsTx(ctx context.Context, tx *sql.Tx, opnameID int64) ([]OpnameItem, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, product_id, product_variation_id, sub_location_id,
			system_quantity, counted_quantity, system_stock_id, system_stock_revision
		FROM stock_opname_items WHERE stock_opname_id = ? ORDER BY id ASC`, opnameID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []OpnameItem
	for rows.Next() {
		var (
			it                            OpnameItem
			pid, vid, subID, stockID, rev sql.NullInt64
			counted                       sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &pid, &vid, &subID, &it.SystemQuantity, &counted, &stockID, &rev); err != nil {
			return nil, err
		}
		it.ProductID = nullInt64Ptr(pid)
		it.VariationID = nullInt64Ptr(vid)
		it.SubLocationID = nullInt64Ptr(subID)
		it.CountedQuantity = nullInt64Ptr(counted)
		it.SystemStockID = nullInt64Ptr(stockID)
		it.SystemStockRevision = nullInt64Ptr(rev)
		out = append(out, it)
	}
	return out, rows.Err()
}

// LoadOpname renders an opname with items.product + items.variation.compositions.option, the relation set every
// opname endpoint returns.
func LoadOpname(ctx context.Context, d *db.DB, actor Actor, id int64) (map[string]any, error) {
	var (
		op                                                        Opname
		stockType, notes                                          sql.NullString
		subLocation                                               sql.NullInt64
		submittedBy, approvedBy, rejectedBy                       sql.NullInt64
		submittedAt, approvedAt, rejectedAt, createdAt, updatedAt sql.NullString
		rejectionReason                                           sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT id, agent_id, opname_number, opname_type, status, stock_type,
			sub_location_id, notes, created_by, submitted_by, CAST(submitted_at AS CHAR), approved_by,
			CAST(approved_at AS CHAR), rejected_by, CAST(rejected_at AS CHAR), rejection_reason,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM stock_opnames WHERE id = ?`, id).
		Scan(&op.ID, &op.AgentID, &op.OpnameNumber, &op.OpnameType, &op.Status, &stockType, &subLocation,
			&notes, &op.CreatedBy, &submittedBy, &submittedAt, &approvedBy, &approvedAt, &rejectedBy,
			&rejectedAt, &rejectionReason, &createdAt, &updatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}

	items, err := opnameItemsJSON(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id":               op.ID,
		"agent_id":         op.AgentID,
		"opname_number":    op.OpnameNumber,
		"opname_type":      op.OpnameType,
		"status":           op.Status,
		"stock_type":       nullStr(stockType),
		"sub_location_id":  nullIntPtr(subLocation),
		"notes":            nullStr(notes),
		"created_by":       op.CreatedBy,
		"submitted_by":     nullIntPtr(submittedBy),
		"submitted_at":     formatMySQLString(submittedAt),
		"approved_by":      nullIntPtr(approvedBy),
		"approved_at":      formatMySQLString(approvedAt),
		"rejected_by":      nullIntPtr(rejectedBy),
		"rejected_at":      formatMySQLString(rejectedAt),
		"rejection_reason": nullStr(rejectionReason),
		"created_at":       formatMySQLString(createdAt),
		"updated_at":       formatMySQLString(updatedAt),
		"items":            items,
	}, nil
}

// opnameItemsJSON renders the items with the nested product and variation (with compositions) relations.
func opnameItemsJSON(ctx context.Context, d *db.DB, opnameID int64) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, stock_opname_id, product_id, product_variation_id,
			sub_location_id, system_quantity, counted_quantity, difference, adjusted_quantity,
			import_row_key, import_fingerprint, import_target_quantity, CAST(catalog_published_at AS CHAR),
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR), system_stock_id, system_stock_revision
		FROM stock_opname_items WHERE stock_opname_id = ? ORDER BY id ASC`, opnameID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type irow struct {
		id, opnameID                       int64
		productID, variationID, subID      sql.NullInt64
		systemQty                          int64
		counted, difference, adjusted      sql.NullInt64
		importRowKey, importFingerprint    sql.NullString
		importTargetQty                    sql.NullInt64
		catalogPublished, created, updated sql.NullString
		systemStockID, systemRevision      sql.NullInt64
	}
	var list []irow
	var productIDs, variationIDs []int64
	for rows.Next() {
		var r irow
		if err := rows.Scan(&r.id, &r.opnameID, &r.productID, &r.variationID, &r.subID, &r.systemQty,
			&r.counted, &r.difference, &r.adjusted, &r.importRowKey, &r.importFingerprint,
			&r.importTargetQty, &r.catalogPublished, &r.created, &r.updated, &r.systemStockID,
			&r.systemRevision); err != nil {
			return nil, err
		}
		list = append(list, r)
		if pid, ok := nullInt(r.productID); ok {
			productIDs = append(productIDs, pid)
		}
		if vid, ok := nullInt(r.variationID); ok {
			variationIDs = append(variationIDs, vid)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	// The controller loads `items.product` (whole model, no images) and `items.variation.compositions.option`.
	products, err := loadProductModels(ctx, d, productIDs, false)
	if err != nil {
		return nil, err
	}
	variations, err := loadVariationModels(ctx, d, variationIDs)
	if err != nil {
		return nil, err
	}

	out := make([]map[string]any, 0, len(list))
	for _, r := range list {
		var product, variation any
		if pid, ok := nullInt(r.productID); ok {
			if p, found := products[pid]; found {
				product = p
			}
		}
		if vid, ok := nullInt(r.variationID); ok {
			if v, found := variations[vid]; found {
				variation = publicVariation(v)
			}
		}
		out = append(out, map[string]any{
			"id":                     r.id,
			"stock_opname_id":        r.opnameID,
			"product_id":             nullIntPtr(r.productID),
			"product_variation_id":   nullIntPtr(r.variationID),
			"sub_location_id":        nullIntPtr(r.subID),
			"system_quantity":        r.systemQty,
			"counted_quantity":       nullIntPtr(r.counted),
			"difference":             nullIntPtr(r.difference),
			"adjusted_quantity":      nullIntPtr(r.adjusted),
			"import_row_key":         nullStr(r.importRowKey),
			"import_fingerprint":     nullStr(r.importFingerprint),
			"import_target_quantity": nullIntPtr(r.importTargetQty),
			"pending_catalog_change": nil,
			"catalog_published_at":   formatMySQLString(r.catalogPublished),
			"created_at":             formatMySQLString(r.created),
			"updated_at":             formatMySQLString(r.updated),
			"system_stock_id":        nullIntPtr(r.systemStockID),
			"system_stock_revision":  nullIntPtr(r.systemRevision),
			"product":                product,
			"variation":              variation,
		})
	}
	return out, nil
}

// ListOpnames is StockOpnameController::index (meta includes per_page).
func ListOpnames(ctx context.Context, d *db.DB, actor Actor, page, perPage int) ([]map[string]any, Page, int, error) {
	where := "1=1"
	args := []any{}
	if !actor.IsRole("super_admin") {
		where = "agent_id = ?"
		args = append(args, actor.AgentID)
	}
	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_opnames WHERE `+where, args...).Scan(&total); err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, Page{CurrentPage: 1, LastPage: 1}, perPage, nil
		}
		return nil, Page{}, 0, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (page - 1) * perPage
	rows, err := d.QueryContext(ctx, `SELECT id FROM stock_opnames WHERE `+where+`
		ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?`, append(append([]any{}, args...), perPage, offset)...)
	if err != nil {
		return nil, Page{}, 0, err
	}
	defer rows.Close()
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, Page{}, 0, err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		return nil, Page{}, 0, err
	}
	out := make([]map[string]any, 0, len(ids))
	for _, id := range ids {
		row, err := LoadOpname(ctx, d, actor, id)
		if err != nil {
			return nil, Page{}, 0, err
		}
		out = append(out, row)
	}
	return out, Page{CurrentPage: page, LastPage: lastPage, Total: total}, perPage, nil
}

// uniqueOpnameNumber is StockOpnameService::uniqueNumber.
func uniqueOpnameNumber(ctx context.Context, q queryExecer) (string, error) {
	for attempt := 0; attempt < 10; attempt++ {
		number := "OPN-" + nowStamp() + "-" + randomSuffix(5)
		var exists bool
		if err := q.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_opnames WHERE opname_number = ?)`, number).Scan(&exists); err != nil {
			return "", err
		}
		if !exists {
			return number, nil
		}
	}
	return "", errors.New("could not allocate a unique opname number")
}

// writeMovementOpname writes a stock movement linked to an opname.
func writeMovementOpname(ctx context.Context, tx *sql.Tx, m movement, opnameID int64) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO stock_movements
		(agent_id, product_id, product_variation_id, type, quantity, stock_type, sub_location_id,
		 reference_type, reference_id, opname_id, created_by, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, 'App\\Models\\StockOpname', ?, ?, ?, UTC_TIMESTAMP())`,
		m.AgentID, nullableInt(m.ProductID), nullableInt(m.VariationID), m.Type, m.Quantity,
		nullableStrMember(m.StockType), nullableInt(m.SubLocationID), opnameID, opnameID, m.CreatedBy)
	return err
}

// sortStrings is a tiny wrapper so this file does not need the sort package twice.
func sortStrings(v []string) {
	for i := 1; i < len(v); i++ {
		for j := i; j > 0 && v[j] < v[j-1]; j-- {
			v[j], v[j-1] = v[j-1], v[j]
		}
	}
}

var _ = strconv.Itoa
var _ = strings.TrimSpace
