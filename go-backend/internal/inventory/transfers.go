// Package inventory — stock transfers (create, approve/execute, cancel, reject) and the legacy stock
// adjustment gate.
//
// LOCK ORDER (extracted from StockTransferService and StockService, preserved exactly):
//
//  1. the stock_transfers row                      FOR UPDATE, agent-scoped
//  2. per target, in canonical target order         "p:<id>" then "v:<id>", ksorted
//     a. Agent commitment row (product_stocks / product_variation_stocks)  — ONLY for transit -> sub,
//     because that is the one direction that can starve an agent checkout's committed reservation.
//     Taking it first is what serialises a transfer against reserveFor*().
//     b. source warehouse row                      FOR UPDATE
//     c. destination warehouse row                 FOR UPDATE (may not exist -> created after checks)
//  3. writes + stock_movements, then the status update
//
// Taking any of these out of order reintroduces the deadlock the Laravel code was fixed to avoid; taking a
// plain (non-locking) read instead of (b)/(c) reintroduces the REPEATABLE READ oversell. Neither is optional.
package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Physical buckets a transfer may move between (StockTransferService::PHYSICAL_BUCKETS).
var physicalBuckets = map[string]bool{"transit": true, "shipping": true, "sub": true}

const shippingClosedMessage = "Stok Shipping hanya berubah melalui fulfillment yang disetujui Admin, bukan transfer Gudang."

// ErrGudangOnly is "Hanya Gudang dengan network valid." (403).
func ErrGudangOnly() error {
	return &DomainError{Status: 403, Message: "Hanya Gudang dengan network valid."}
}

// ErrAdminOnly is "Hanya Admin yang dapat menyetujui transfer." (403).
func ErrAdminOnly() error {
	return &DomainError{Status: 403, Message: "Hanya Admin yang dapat menyetujui transfer."}
}

// ErrAdminReject is "Hanya Admin yang dapat menolak transfer." (403).
func ErrAdminReject() error {
	return &DomainError{Status: 403, Message: "Hanya Admin yang dapat menolak transfer."}
}

// transferInvalid is a 422 with the reference's exact sentence.
func transferInvalid(msg string) error { return &DomainError{Status: 422, Message: msg} }

// Actor is the minimum identity the mutation paths need.
type Actor struct {
	ID      int64
	AgentID int64
	Role    string
}

// IsRole reports the canonical role membership.
func (a Actor) IsRole(slugs ...string) bool {
	cur := a.Role
	if cur == "sales-kurir" {
		cur = "sales-kurir-sub"
	}
	for _, s := range slugs {
		if s == cur {
			return true
		}
	}
	return false
}

// ---- canonical target ordering ---------------------------------------------------------------------

// targetKey is StockService::canonicalTargetKey — the single shared vocabulary for a stock target.
func targetKey(productID, variationID *int64) string {
	if variationID != nil {
		return "v:" + strconv.FormatInt(*variationID, 10)
	}
	if productID != nil {
		return "p:" + strconv.FormatInt(*productID, 10)
	}
	return ""
}

// sortTargets dedupes + returns targets in canonical (lexicographic key) order. This is the ONE ordering
// every multi-target lock acquisition must use.
func sortTargets(keys map[string]Target) []string {
	out := make([]string, 0, len(keys))
	for k := range keys {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// ---- transfers -------------------------------------------------------------------------------------

// Transfer is one stock-transfer row.
type Transfer struct {
	ID                       int64
	AgentID                  int64
	TransferNumber           string
	SourceStockType          string
	SourceSubLocationID      *int64
	DestinationStockType     string
	DestinationSubLocationID *int64
	Status                   string
	Reference                *string
	Note                     *string
	CreatedBy                *int64
	CompletedBy              *int64
	RejectedBy               *int64
	RejectionReason          *string
	CreatedAt                string
	UpdatedAt                string
}

// TransferItem is one line of a transfer.
type TransferItem struct {
	ID          int64
	ProductID   *int64
	VariationID *int64
	Quantity    int64
}

// ListTransfers returns a page of transfers for the actor's branch (StockTransferController::index).
//
// super_admin sees every branch (the controller calls withoutGlobalScopes for that role only) — reproduced
// explicitly rather than by omitting the filter silently.
func ListTransfers(ctx context.Context, d *db.DB, actor Actor, status string) ([]map[string]any, Page, error) {
	where := []string{}
	args := []any{}
	if !actor.IsRole("super_admin") {
		where = append(where, "t.agent_id = ?")
		args = append(args, actor.AgentID)
	}
	if status != "" {
		if status != "pending" && status != "completed" && status != "rejected" && status != "cancelled" {
			return nil, Page{}, transferInvalid("Status transfer tidak valid.")
		}
		where = append(where, "t.status = ?")
		args = append(args, status)
	}
	whereSQL := "1=1"
	if len(where) > 0 {
		whereSQL = strings.Join(where, " AND ")
	}

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_transfers t WHERE `+whereSQL, args...).Scan(&total); err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, Page{CurrentPage: 1, LastPage: 1}, nil
		}
		return nil, Page{}, err
	}
	lastPage := (total + 15 - 1) / 15
	if lastPage < 1 {
		lastPage = 1
	}
	rows, err := d.QueryContext(ctx, transferColumns+` WHERE `+whereSQL+` ORDER BY t.created_at DESC LIMIT 15`, args...)
	if err != nil {
		return nil, Page{}, err
	}
	defer rows.Close()
	list, err := scanTransfers(rows)
	if err != nil {
		return nil, Page{}, err
	}
	out, err := hydrateTransfers(ctx, d, list, true)
	if err != nil {
		return nil, Page{}, err
	}
	return out, Page{CurrentPage: 1, LastPage: lastPage, Total: total}, nil
}

const transferColumns = `SELECT t.id, t.agent_id, t.transfer_number, t.source_stock_type, t.source_sub_location_id,
	t.destination_stock_type, t.destination_sub_location_id, t.status, t.reference, t.note, t.created_by,
	t.completed_by, t.rejected_by, t.rejection_reason,
	CAST(t.created_at AS CHAR), CAST(t.updated_at AS CHAR)
	FROM stock_transfers t`

func scanTransfers(rows *sql.Rows) ([]Transfer, error) {
	var out []Transfer
	for rows.Next() {
		var (
			t                  Transfer
			srcSub, dstSub     sql.NullInt64
			ref, note          sql.NullString
			createdBy          sql.NullInt64
			completedBy        sql.NullInt64
			rejectedBy         sql.NullInt64
			reason             sql.NullString
			createdAt, updated sql.NullString
		)
		if err := rows.Scan(&t.ID, &t.AgentID, &t.TransferNumber, &t.SourceStockType, &srcSub,
			&t.DestinationStockType, &dstSub, &t.Status, &ref, &note, &createdBy, &completedBy,
			&rejectedBy, &reason, &createdAt, &updated); err != nil {
			return nil, err
		}
		t.SourceSubLocationID = nullInt64Ptr(srcSub)
		t.DestinationSubLocationID = nullInt64Ptr(dstSub)
		t.Reference = strPtr(ref)
		t.Note = strPtr(note)
		t.CreatedBy = nullInt64Ptr(createdBy)
		t.CompletedBy = nullInt64Ptr(completedBy)
		t.RejectedBy = nullInt64Ptr(rejectedBy)
		t.RejectionReason = strPtr(reason)
		t.CreatedAt = stringOrEmpty(createdAt)
		t.UpdatedAt = stringOrEmpty(updated)
		out = append(out, t)
	}
	return out, rows.Err()
}

// hydrateTransfers renders the relation set the controller eager-loads.
//
//   - withProductImages: the index/show load `items.product.images`; the created/approved responses load
//     `items.product` WITHOUT images. That difference is part of the payload, so it is a parameter.
func hydrateTransfers(ctx context.Context, d *db.DB, list []Transfer, withProductImages bool) ([]map[string]any, error) {
	out := make([]map[string]any, 0, len(list))
	for _, t := range list {
		items, err := transferItems(ctx, d, t.ID, withProductImages)
		if err != nil {
			return nil, err
		}
		srcName, srcCode, err := subLocationBrief(ctx, d, t.SourceSubLocationID)
		if err != nil {
			return nil, err
		}
		dstName, dstCode, err := subLocationBrief(ctx, d, t.DestinationSubLocationID)
		if err != nil {
			return nil, err
		}
		creator, err := userBrief(ctx, d, t.CreatedBy)
		if err != nil {
			return nil, err
		}
		row := map[string]any{
			"id":                          t.ID,
			"agent_id":                    t.AgentID,
			"transfer_number":             t.TransferNumber,
			"source_stock_type":           t.SourceStockType,
			"source_sub_location_id":      t.SourceSubLocationID,
			"destination_stock_type":      t.DestinationStockType,
			"destination_sub_location_id": t.DestinationSubLocationID,
			"status":                      t.Status,
			"reference":                   t.Reference,
			"note":                        t.Note,
			"created_by":                  t.CreatedBy,
			"completed_by":                t.CompletedBy,
			"rejected_by":                 t.RejectedBy,
			"rejection_reason":            t.RejectionReason,
			"created_at":                  t.CreatedAt,
			"updated_at":                  t.UpdatedAt,
			"items":                       items,
			"handover":                    nil,
			"source_sub_location":         subLocationJSON(t.SourceSubLocationID, srcCode, srcName),
			"destination_sub_location":    subLocationJSON(t.DestinationSubLocationID, dstCode, dstName),
		}
		if withProductImages {
			row["creator"] = creator
		}
		out = append(out, row)
	}
	return out, nil
}

func subLocationJSON(id any, code, name *string) any {
	if id == nil {
		return nil
	}
	return map[string]any{"id": id, "code": strOrNil(code), "name": strOrNil(name)}
}

func transferItems(ctx context.Context, d *db.DB, transferID int64, withImages bool) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, product_variation_id, quantity
		FROM stock_transfer_items WHERE stock_transfer_id = ? ORDER BY id ASC`, transferID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var ids []int64
	var productIDs, variationIDs []int64
	for rows.Next() {
		var (
			id, qty  int64
			pid, vid sql.NullInt64
		)
		if err := rows.Scan(&id, &pid, &vid, &qty); err != nil {
			return nil, err
		}
		ids = append(ids, id)
		if p, ok := nullInt(pid); ok {
			productIDs = append(productIDs, p)
		}
		if v, ok := nullInt(vid); ok {
			variationIDs = append(variationIDs, v)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	// Re-read with the ids aligned (kept simple: one extra pass over the same rows).
	rows2, err := d.QueryContext(ctx, `SELECT id, product_id, product_variation_id, quantity
		FROM stock_transfer_items WHERE stock_transfer_id = ? ORDER BY id ASC`, transferID)
	if err != nil {
		return nil, err
	}
	defer rows2.Close()
	products, err := loadProductModels(ctx, d, productIDs, withImages)
	if err != nil {
		return nil, err
	}
	variations, err := loadVariationModels(ctx, d, variationIDs)
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for rows2.Next() {
		var (
			id, qty  int64
			pid, vid sql.NullInt64
		)
		if err := rows2.Scan(&id, &pid, &vid, &qty); err != nil {
			return nil, err
		}
		var product, variation any
		if p, ok := nullInt(pid); ok {
			if v, found := products[p]; found {
				product = publicProduct(v)
			}
		}
		if v, ok := nullInt(vid); ok {
			if vv, found := variations[v]; found {
				variation = publicVariation(vv)
			}
		}
		out = append(out, map[string]any{
			"id":                   id,
			"stock_transfer_id":    transferID,
			"product_id":           nullIntPtr(pid),
			"product_variation_id": nullIntPtr(vid),
			"quantity":             qty,
			"product":              product,
			"variation":            variation,
		})
	}
	return out, rows2.Err()
}

// ShowTransfer is StockTransferController::show with the policy: super_admin, or same-branch agen/admin/gudang.
func ShowTransfer(ctx context.Context, d *db.DB, actor Actor, id int64) (map[string]any, error) {
	t, err := loadTransfer(ctx, d, actor, id, false)
	if err != nil {
		return nil, err
	}
	rows, err := hydrateTransfers(ctx, d, []Transfer{*t}, true)
	if err != nil {
		return nil, err
	}
	return rows[0], nil
}

// loadTransfer reads one transfer inside the actor's scope, applying StockTransferPolicy::view.
func loadTransfer(ctx context.Context, d *db.DB, actor Actor, id int64, forUpdate bool) (*Transfer, error) {
	query := transferColumns + ` WHERE t.id = ?`
	args := []any{id}
	if !actor.IsRole("super_admin") {
		query += ` AND t.agent_id = ?`
		args = append(args, actor.AgentID)
	}
	if forUpdate {
		query += ` FOR UPDATE`
	}
	rows, err := d.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	list, err := scanTransfers(rows)
	if err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return nil, ErrNotFound
	}
	t := list[0]
	if !actor.IsRole("super_admin") &&
		!(actor.IsRole("agen", "admin", "gudang") && t.AgentID == actor.AgentID) {
		return nil, ErrNotFound // policy denial is indistinguishable from "not found"
	}
	return &t, nil
}

// CreateTransferInput is the validated create payload.
type CreateTransferInput struct {
	SourceStockType          string
	SourceSubLocationID      *int64
	DestinationStockType     string
	DestinationSubLocationID *int64
	Reference                *string
	Note                     *string
	Items                    []TransferItemInput
}

// TransferItemInput is one requested line.
type TransferItemInput struct {
	ProductID   *int64
	VariationID *int64
	Quantity    int64
}

// CreateTransfer is StockTransferService::create.
func CreateTransfer(ctx context.Context, d *db.DB, actor Actor, in CreateTransferInput) (map[string]any, error) {
	if !actor.IsRole("gudang") || actor.AgentID == 0 {
		return nil, ErrGudangOnly()
	}
	if in.SourceStockType == "factory_plan" || in.DestinationStockType == "factory_plan" {
		return nil, transferInvalid("Transfer Plan hanya melalui Stock Transfer Plan ke Transit dengan persetujuan Admin.")
	}
	if err := assertBucketPair(ctx, d, actor, in); err != nil {
		return nil, err
	}
	if err := assertNoOwnedSubLocation(ctx, d, []*int64{in.SourceSubLocationID, in.DestinationSubLocationID}, false); err != nil {
		return nil, err
	}
	if len(in.Items) == 0 {
		return nil, transferInvalid("Transfer harus memiliki item.")
	}

	var createdID int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		number, err := uniqueTransferNumber(ctx, tx, "TRF")
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO stock_transfers
			(agent_id, transfer_number, source_stock_type, source_sub_location_id, destination_stock_type,
			 destination_sub_location_id, status, reference, note, created_by, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			actor.AgentID, number, in.SourceStockType, nullableInt(in.SourceSubLocationID),
			in.DestinationStockType, nullableInt(in.DestinationSubLocationID), nullableStrPtr(in.Reference),
			nullableStrPtr(in.Note), actor.ID)
		if err != nil {
			return err
		}
		createdID, err = res.LastInsertId()
		if err != nil {
			return err
		}

		seen := map[string]bool{}
		for _, item := range in.Items {
			if (item.ProductID == nil) == (item.VariationID == nil) || item.Quantity <= 0 {
				return transferInvalid("Setiap item harus memiliki tepat satu target dan jumlah positif.")
			}
			key := targetKey(item.ProductID, item.VariationID)
			if seen[key] {
				return transferInvalid("Target transfer tidak boleh duplikat.")
			}
			seen[key] = true
			if err := assertTarget(ctx, tx, item.ProductID, item.VariationID); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO stock_transfer_items
				(stock_transfer_id, product_id, product_variation_id, quantity, created_at, updated_at)
				VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
				createdID, nullableInt(item.ProductID), nullableInt(item.VariationID), item.Quantity); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	t, err := loadTransfer(ctx, d, actor, createdID, false)
	if err != nil {
		return nil, err
	}
	rows, err := hydrateTransfers(ctx, d, []Transfer{*t}, false) // created response loads items.product WITHOUT images
	if err != nil {
		return nil, err
	}
	return rows[0], nil
}

// CreatePlanTransfer is StockTransferService::createPlanTransfer: a factory_plan → transit transfer, which is
// the only way plan stock may move. It requires the plan to be ENABLED for the agent.
func CreatePlanTransfer(ctx context.Context, d *db.DB, actor Actor, items []TransferItemInput, reference, note *string) (map[string]any, error) {
	if !actor.IsRole("gudang") || actor.AgentID == 0 {
		return nil, ErrGudangOnly()
	}
	planEnabled, err := factoryPlanEnabled(ctx, d, actor.AgentID)
	if err != nil {
		return nil, err
	}
	if !planEnabled {
		return nil, transferInvalid("Plan Pabrik sedang nonaktif. Aktifkan Plan Pabrik sebelum membuat Stock Transfer.")
	}
	if len(items) == 0 {
		return nil, transferInvalid("Transfer harus memiliki item.")
	}

	var createdID int64
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		seen := map[string]bool{}
		for _, item := range items {
			if (item.ProductID == nil) == (item.VariationID == nil) || item.Quantity <= 0 {
				return transferInvalid("Setiap item harus memiliki tepat satu target dan jumlah positif.")
			}
			key := targetKey(item.ProductID, item.VariationID)
			if seen[key] {
				return transferInvalid("Target transfer tidak boleh duplikat.")
			}
			seen[key] = true
			if err := assertTarget(ctx, tx, item.ProductID, item.VariationID); err != nil {
				return err
			}
		}

		number, err := uniqueTransferNumber(ctx, tx, "TRF")
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO stock_transfers
			(agent_id, transfer_number, source_stock_type, source_sub_location_id, destination_stock_type,
			 destination_sub_location_id, status, reference, note, created_by, created_at, updated_at)
			VALUES (?, ?, 'factory_plan', NULL, 'transit', NULL, 'pending', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			actor.AgentID, number, nullableStrPtr(reference), nullableStrPtr(note), actor.ID)
		if err != nil {
			return err
		}
		createdID, _ = res.LastInsertId()
		for _, item := range items {
			if _, err := tx.ExecContext(ctx, `INSERT INTO stock_transfer_items
				(stock_transfer_id, product_id, product_variation_id, quantity, created_at, updated_at)
				VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
				createdID, nullableInt(item.ProductID), nullableInt(item.VariationID), item.Quantity); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	t, err := loadTransfer(ctx, d, actor, createdID, false)
	if err != nil {
		return nil, err
	}
	// This endpoint's created response loads `items.variation.compositions.option` (unlike create()).
	rows, err := hydrateTransfers(ctx, d, []Transfer{*t}, false)
	if err != nil {
		return nil, err
	}
	return rows[0], nil
}

// EligibleOwners is WarehouseSubLocationController::eligibleOwners: every active Sales-Kurir-Sub in the agent's
// network that does not already own a sub-location.
func EligibleOwners(ctx context.Context, d *db.DB, actor Actor, search string, page, perPage int) ([]map[string]any, Page, error) {
	if !actor.IsRole("agen", "admin") || actor.AgentID == 0 {
		return nil, Page{}, &DomainError{Status: 403, Message: "Hanya Agen/Admin yang dapat melihat calon pemilik Sub Location."}
	}
	if perPage < 1 {
		perPage = 15
	}
	if perPage > 50 {
		perPage = 50
	}
	where := []string{
		"u.agent_id = ?",
		"u.status = 'active'",
		"u.deleted_at IS NULL",
		"r.slug IN ('sales-kurir-sub','sales-kurir')",
		"NOT EXISTS(SELECT 1 FROM warehouse_sub_locations s WHERE s.owner_user_id = u.id)",
	}
	args := []any{actor.AgentID}
	if search != "" {
		where = append(where, "(u.name LIKE ? OR u.phone LIKE ?)")
		term := "%" + search + "%"
		args = append(args, term, term)
	}
	whereSQL := strings.Join(where, " AND ")

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE `+whereSQL, args...).Scan(&total); err != nil {
		return nil, Page{}, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (page - 1) * perPage
	rows, err := d.QueryContext(ctx, `SELECT u.id, u.name, u.email, u.phone, u.referral_code, u.status,
			CAST(u.created_at AS CHAR)
		FROM users u JOIN roles r ON r.id = u.role_id
		WHERE `+whereSQL+` ORDER BY u.name ASC LIMIT ? OFFSET ?`,
		append(append([]any{}, args...), perPage, offset)...)
	if err != nil {
		return nil, Page{}, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			id                          int64
			name                        string
			email, phone, referral, cre sql.NullString
			status                      string
		)
		if err := rows.Scan(&id, &name, &email, &phone, &referral, &status, &cre); err != nil {
			return nil, Page{}, err
		}
		// UserResource shape for a LIST: the own-record-only fields (google_linked / korsal_name) are absent,
		// and avatar/role/korsal/sales ids are present with their nullability preserved.
		out = append(out, map[string]any{
			"id":            id,
			"name":          name,
			"email":         nullStr(email),
			"phone":         nullStr(phone),
			"avatar_url":    nil,
			"role":          "sales-kurir-sub",
			"referral_code": nullStr(referral),
			"agent_id":      actor.AgentID,
			"parent_id":     nil,
			"korsal_id":     nil,
			"sales_id":      nil,
			"status":        status,
			"created_at":    formatMySQLString(cre),
		})
	}
	return out, Page{CurrentPage: page, LastPage: lastPage, Total: total}, rows.Err()
}

// CancelTransfer is StockTransferService::cancel.
func CancelTransfer(ctx context.Context, d *db.DB, actor Actor, id int64) (map[string]any, error) {
	if !actor.IsRole("gudang") || actor.AgentID == 0 {
		return nil, ErrGudangOnly()
	}
	var cancelled bool
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		t, err := lockTransferTx(ctx, tx, actor, id)
		if err != nil {
			return err
		}
		if t.Status != "pending" {
			return transferInvalid("Hanya transfer pending yang dapat dibatalkan.")
		}
		if t.SourceStockType == "factory_plan" || t.DestinationStockType == "factory_plan" {
			return transferInvalid("Transfer Plan hanya melalui persetujuan Admin.")
		}
		if _, err := tx.ExecContext(ctx, `UPDATE stock_transfers SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, id); err != nil {
			return err
		}
		cancelled = true
		return nil
	})
	if err != nil {
		return nil, err
	}
	if !cancelled {
		return nil, ErrNotFound
	}
	t, err := loadTransfer(ctx, d, actor, id, false)
	if err != nil {
		return nil, err
	}
	rows, err := hydrateTransfers(ctx, d, []Transfer{*t}, false)
	if err != nil {
		return nil, err
	}
	return rows[0], nil
}

// RejectTransfer is StockTransferService::reject.
func RejectTransfer(ctx context.Context, d *db.DB, actor Actor, id int64, reason string) (map[string]any, error) {
	if !actor.IsRole("admin") {
		return nil, ErrAdminReject()
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		t, err := lockTransferTx(ctx, tx, actor, id)
		if err != nil {
			return err
		}
		if t.Status == "rejected" {
			return nil // idempotent
		}
		if t.Status != "pending" {
			return transferInvalid("Transfer ini tidak dapat ditolak.")
		}
		if t.SourceStockType == "factory_plan" || t.DestinationStockType == "factory_plan" {
			if t.SourceStockType != "factory_plan" || t.DestinationStockType != "transit" ||
				t.SourceSubLocationID != nil || t.DestinationSubLocationID != nil {
				return transferInvalid("Transfer ini bukan Plan Pabrik ke Transit.")
			}
		}
		_, err = tx.ExecContext(ctx, `UPDATE stock_transfers
			SET status = 'rejected', rejected_by = ?, rejected_at = UTC_TIMESTAMP(), rejection_reason = ?,
			    updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actor.ID, reason, id)
		return err
	})
	if err != nil {
		return nil, err
	}
	t, err := loadTransfer(ctx, d, actor, id, false)
	if err != nil {
		return nil, err
	}
	rows, err := hydrateTransfers(ctx, d, []Transfer{*t}, false)
	if err != nil {
		return nil, err
	}
	return rows[0], nil
}

// lockTransferTx locks one transfer row inside the caller's transaction, agent-scoped.
func lockTransferTx(ctx context.Context, tx *sql.Tx, actor Actor, id int64) (*Transfer, error) {
	rows, err := tx.QueryContext(ctx, transferColumns+` WHERE t.id = ? AND t.agent_id = ? FOR UPDATE`, id, actor.AgentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	list, err := scanTransfers(rows)
	if err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return nil, ErrNotFound
	}
	return &list[0], nil
}

// ApproveTransfer is StockTransferService::approve + executeApprovedTransfer.
//
// Two paths, both preserving the canonical lock order:
//   - Plan Pabrik -> Transit  : source (factory_plan) then destination (transit) per target.
//   - any physical bucket pair: [agent capacity only for transit->sub] then source then destination per target.
func ApproveTransfer(ctx context.Context, d *db.DB, actor Actor, id int64) (map[string]any, error) {
	if !actor.IsRole("admin") {
		return nil, ErrAdminOnly()
	}

	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		t, err := lockTransferTx(ctx, tx, actor, id)
		if err != nil {
			return err
		}
		if t.Status == "completed" {
			return nil // idempotent
		}
		if t.Status != "pending" {
			return transferInvalid("Transfer ini tidak dapat disetujui.")
		}

		items, err := transferItemsTx(ctx, tx, t.ID)
		if err != nil {
			return err
		}
		if len(items) == 0 {
			return transferInvalid("Transfer harus memiliki item.")
		}

		// Canonical order over the transfer's own lines (the same ksort Laravel performs).
		keys := map[string]Target{}
		for _, item := range items {
			keys[targetKey(item.ProductID, item.VariationID)] = Target{ProductID: item.ProductID, VariationID: item.VariationID}
		}
		ordered := sortTargets(keys)

		planPath := t.SourceStockType == "factory_plan" || t.DestinationStockType == "factory_plan"
		if planPath {
			if t.SourceStockType != "factory_plan" || t.DestinationStockType != "transit" ||
				t.SourceSubLocationID != nil || t.DestinationSubLocationID != nil {
				return transferInvalid("Transfer ini bukan Plan Pabrik ke Transit.")
			}
		} else {
			if t.SourceStockType == "shipping" || t.DestinationStockType == "shipping" {
				return transferInvalid(shippingClosedMessage)
			}
			if err := assertNoOwnedSubLocation(ctx, tx, []*int64{t.SourceSubLocationID, t.DestinationSubLocationID}, true); err != nil {
				return err
			}
		}

		type lockedPair struct {
			item        TransferItem
			source      *stockRow
			destination *stockRow
		}
		var pairs []lockedPair

		// --- phase 1: acquire every lock in canonical order, before any write ---
		for _, key := range ordered {
			target := keys[key]
			var item TransferItem
			for _, it := range items {
				if targetKey(it.ProductID, it.VariationID) == key {
					item = it
					break
				}
			}

			if !planPath && t.SourceStockType == "transit" && t.DestinationStockType == "sub" {
				// The ONLY direction that can starve an agent checkout: take its commitment row first.
				if err := lockAgentCapacity(ctx, tx, t.AgentID, target); err != nil {
					return err
				}
			}

			source, err := lockWarehouseRow(ctx, tx, t.AgentID, target, t.SourceStockType, t.SourceSubLocationID)
			if err != nil {
				return err
			}
			if source == nil || source.Quantity < item.Quantity {
				if planPath {
					return transferInvalid("Stok Plan Pabrik tidak mencukupi.")
				}
				return transferInvalid("Stok sumber tidak mencukupi.")
			}
			destination, err := lockWarehouseRow(ctx, tx, t.AgentID, target, t.DestinationStockType, t.DestinationSubLocationID)
			if err != nil {
				return err
			}
			pairs = append(pairs, lockedPair{item: item, source: source, destination: destination})
		}

		// --- phase 2: writes (no new locks are taken here) ---
		var handoverID *int64
		if !planPath {
			number, err := uniqueTransferNumber(ctx, tx, "HOV")
			if err != nil {
				return err
			}
			handedOverBy := actor.ID
			if t.CreatedBy != nil {
				handedOverBy = *t.CreatedBy
			}
			res, err := tx.ExecContext(ctx, `INSERT INTO stock_handovers
				(agent_id, stock_transfer_id, handover_number, handed_over_by, status, handed_over_at, note,
				 created_at, updated_at)
				VALUES (?, ?, ?, ?, 'handed_over', UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
				t.AgentID, t.ID, number, handedOverBy, nullableStrPtr(t.Note))
			if err != nil {
				return err
			}
			hid, _ := res.LastInsertId()
			handoverID = &hid
		}

		for _, pair := range pairs {
			sourceBefore := pair.source.Quantity
			if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity - ?, updated_at = UTC_TIMESTAMP()
				WHERE id = ?`, pair.item.Quantity, pair.source.ID); err != nil {
				return err
			}

			destID := int64(0)
			destBefore := int64(0)
			if pair.destination != nil {
				destID, destBefore = pair.destination.ID, pair.destination.Quantity
				if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity + ?, updated_at = UTC_TIMESTAMP()
					WHERE id = ?`, pair.item.Quantity, destID); err != nil {
					return err
				}
			} else {
				res, err := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks
					(agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity, created_at, updated_at)
					VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
					t.AgentID, nullableInt(pair.item.ProductID), nullableInt(pair.item.VariationID),
					t.DestinationStockType, nullableInt(t.DestinationSubLocationID), pair.item.Quantity)
				if err != nil {
					return err
				}
				destID, _ = res.LastInsertId()
			}

			if err := writeMovement(ctx, tx, movement{
				AgentID: t.AgentID, ProductID: pair.item.ProductID, VariationID: pair.item.VariationID,
				Type: "transfer_out", Quantity: -pair.item.Quantity, StockType: t.SourceStockType,
				SubLocationID: t.SourceSubLocationID, CounterpartStockType: strPtrVal(t.DestinationStockType),
				TransferID: &t.ID, HandoverID: handoverID, CreatedBy: actor.ID,
				Note: fmt.Sprintf("before=%d;after=%d", sourceBefore, sourceBefore-pair.item.Quantity),
			}); err != nil {
				return err
			}
			if err := writeMovement(ctx, tx, movement{
				AgentID: t.AgentID, ProductID: pair.item.ProductID, VariationID: pair.item.VariationID,
				Type: "transfer_in", Quantity: pair.item.Quantity, StockType: t.DestinationStockType,
				SubLocationID: t.DestinationSubLocationID, CounterpartStockType: strPtrVal(t.SourceStockType),
				TransferID: &t.ID, HandoverID: handoverID, CreatedBy: actor.ID,
				Note: fmt.Sprintf("before=%d;after=%d", destBefore, destBefore+pair.item.Quantity),
			}); err != nil {
				return err
			}
		}

		if _, err := tx.ExecContext(ctx, `UPDATE stock_transfers
			SET status = 'completed', completed_by = ?, completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actor.ID, t.ID); err != nil {
			return err
		}
		// `activity_logs.properties` carries a json_valid() CHECK constraint: build real JSON, never a Go
		// value with a nil rendered by %v.
		handoverValue := "null"
		if handoverID != nil {
			handoverValue = strconv.FormatInt(*handoverID, 10)
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
			(causer_id, subject_type, subject_id, event, description, properties, created_at)
			VALUES (?, 'App\\Models\\StockTransfer', ?, 'stock_transfer.approved', NULL, ?, UTC_TIMESTAMP())`,
			actor.ID, t.ID, fmt.Sprintf(`{"agent_id":%d,"handover_id":%s}`, t.AgentID, handoverValue))
		return err
	})
	if err != nil {
		return nil, err
	}
	t, err := loadTransfer(ctx, d, actor, id, false)
	if err != nil {
		return nil, err
	}
	rows, err := hydrateTransfers(ctx, d, []Transfer{*t}, false)
	if err != nil {
		return nil, err
	}
	return rows[0], nil
}

// stockRow is a locked warehouse_stocks row.
type stockRow struct {
	ID       int64
	Quantity int64
}

// lockWarehouseRow locks the warehouse row for a target/bucket (nil when it does not exist).
func lockWarehouseRow(ctx context.Context, tx *sql.Tx, agentID int64, target Target, stockType string, subLocationID *int64) (*stockRow, error) {
	clause, args := target.clause()
	query := `SELECT id, quantity FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = ? AND sub_location_id <=> ? AND ` + clause + ` FOR UPDATE`
	full := append([]any{agentID, stockType, nullableInt(subLocationID)}, args...)
	var r stockRow
	err := tx.QueryRowContext(ctx, query, full...).Scan(&r.ID, &r.Quantity)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &r, nil
}

// lockAgentCapacity locks the Agent commitment row FIRST (the canonical first lock for a capacity decision).
func lockAgentCapacity(ctx context.Context, tx *sql.Tx, agentID int64, target Target) error {
	if target.VariationID != nil {
		var id int64
		err := tx.QueryRowContext(ctx, `SELECT id FROM product_variation_stocks
			WHERE agent_id = ? AND product_variation_id = ? FOR UPDATE`, agentID, *target.VariationID).Scan(&id)
		if errors.Is(err, sql.ErrNoRows) {
			return nil
		}
		return err
	}
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM product_stocks
		WHERE agent_id = ? AND product_id = ? FOR UPDATE`, agentID, *target.ProductID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	return err
}

// movement is one stock_movements insert.
type movement struct {
	AgentID              int64
	ProductID            *int64
	VariationID          *int64
	Type                 string
	Quantity             int64
	StockType            string
	SubLocationID        *int64
	CounterpartStockType *string
	TransferID           *int64
	HandoverID           *int64
	ReferenceType        string
	ReferenceID          *int64
	CreatedBy            int64
	Note                 string
}

func writeMovement(ctx context.Context, tx *sql.Tx, m movement) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO stock_movements
		(agent_id, product_id, product_variation_id, type, quantity, stock_type, sub_location_id,
		 counterpart_stock_type, transfer_id, handover_id, reference_type, reference_id, note, created_by, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())`,
		m.AgentID, nullableInt(m.ProductID), nullableInt(m.VariationID), m.Type, m.Quantity,
		nullableStrMember(m.StockType), nullableInt(m.SubLocationID), nullableStrPtr(m.CounterpartStockType),
		nullableInt(m.TransferID), nullableInt(m.HandoverID),
		nullableStrMember(m.ReferenceType), nullableInt(m.ReferenceID), nullableStrMember(m.Note), m.CreatedBy)
	return err
}

// transferItemsTx reads the lines inside a transaction.
func transferItemsTx(ctx context.Context, tx *sql.Tx, transferID int64) ([]TransferItem, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, product_id, product_variation_id, quantity
		FROM stock_transfer_items WHERE stock_transfer_id = ? ORDER BY id ASC`, transferID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []TransferItem
	for rows.Next() {
		var (
			it       TransferItem
			pid, vid sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &pid, &vid, &it.Quantity); err != nil {
			return nil, err
		}
		it.ProductID = nullInt64Ptr(pid)
		it.VariationID = nullInt64Ptr(vid)
		out = append(out, it)
	}
	return out, rows.Err()
}

// ---- validation helpers ----------------------------------------------------------------------------

func assertBucketPair(ctx context.Context, d *db.DB, actor Actor, in CreateTransferInput) error {
	src, dst := in.SourceStockType, in.DestinationStockType
	if !physicalBuckets[src] || !physicalBuckets[dst] || (src == dst && src != "sub") {
		return transferInvalid("Transfer hanya mendukung perpindahan antar bucket fisik yang berbeda.")
	}
	if (src == "sub" && dst == "shipping") || (src == "shipping" && dst == "sub") {
		return transferInvalid("Transfer Shipping ke/dari Sub belum tersedia di fase ini.")
	}
	if src == "shipping" || dst == "shipping" {
		return transferInvalid(shippingClosedMessage)
	}
	if (src == "sub") != (in.SourceSubLocationID != nil) || (dst == "sub") != (in.DestinationSubLocationID != nil) {
		return transferInvalid("Sub location wajib sesuai dengan tipe bucket.")
	}
	if src == "sub" && dst == "sub" && in.SourceSubLocationID != nil && in.DestinationSubLocationID != nil &&
		*in.SourceSubLocationID == *in.DestinationSubLocationID {
		return transferInvalid("Sub location sumber dan tujuan harus berbeda.")
	}
	for _, locationID := range []*int64{in.SourceSubLocationID, in.DestinationSubLocationID} {
		if locationID == nil {
			continue
		}
		var active bool
		err := d.QueryRowContext(ctx, `SELECT is_active FROM warehouse_sub_locations WHERE id = ? AND agent_id = ?`,
			*locationID, actor.AgentID).Scan(&active)
		if errors.Is(err, sql.ErrNoRows) || !active {
			return transferInvalid("Sub location tidak aktif atau bukan milik network ini.")
		}
		if err != nil {
			return err
		}
	}
	return nil
}

// queryExecer is satisfied by both *db.DB and *sql.Tx.
type queryExecer interface {
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
	QueryContext(ctx context.Context, query string, args ...any) (*sql.Rows, error)
	ExecContext(ctx context.Context, query string, args ...any) (sql.Result, error)
}

// assertNoOwnedSubLocation refuses to move a Sub Location that belongs to a Sales-Kurir-Sub: those move only
// through the Sub stock-request workflow.
func assertNoOwnedSubLocation(ctx context.Context, q queryExecer, ids []*int64, lock bool) error {
	var present []int64
	for _, id := range ids {
		if id != nil {
			present = append(present, *id)
		}
	}
	if len(present) == 0 {
		return nil
	}
	sort.Slice(present, func(i, j int) bool { return present[i] < present[j] })
	for _, id := range present {
		query := `SELECT owner_user_id FROM warehouse_sub_locations WHERE id = ?`
		if lock {
			query += ` FOR UPDATE`
		}
		var owner sql.NullInt64
		err := q.QueryRowContext(ctx, query, id).Scan(&owner)
		if errors.Is(err, sql.ErrNoRows) {
			continue
		}
		if err != nil {
			return err
		}
		if owner.Valid {
			return transferInvalid("Sub Location milik Sales-Kurir-Sub hanya dapat dipindah melalui Permintaan Stok Sub (Admin menyetujui, Gudang mengeksekusi).")
		}
	}
	return nil
}

func assertTarget(ctx context.Context, q queryExecer, productID, variationID *int64) error {
	if variationID != nil {
		var exists bool
		if err := q.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM product_variations WHERE id = ? AND deleted_at IS NULL)`,
			*variationID).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return transferInvalid("Variation tidak valid.")
		}
	}
	if productID != nil {
		var exists bool
		if err := q.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM products WHERE id = ? AND deleted_at IS NULL)`,
			*productID).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return transferInvalid("Product tidak valid.")
		}
	}
	if productID != nil && variationID != nil {
		var linked bool
		if err := q.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM product_variations WHERE id = ? AND product_id = ?)`,
			*variationID, *productID).Scan(&linked); err != nil {
			return err
		}
		if !linked {
			return transferInvalid("Variation tidak terkait dengan Product.")
		}
	}
	return nil
}

// uniqueTransferNumber is StockTransferService::uniqueNumber.
func uniqueTransferNumber(ctx context.Context, q queryExecer, prefix string) (string, error) {
	table, column := "stock_transfers", "transfer_number"
	if prefix == "HOV" {
		table, column = "stock_handovers", "handover_number"
	}
	for attempt := 0; attempt < 10; attempt++ {
		number := fmt.Sprintf("%s-%s-%s", prefix, time.Now().UTC().Format("20060102150405"), randomSuffix(5))
		var exists bool
		if err := q.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM `+table+` WHERE `+column+` = ?)`, number).Scan(&exists); err != nil {
			return "", err
		}
		if !exists {
			return number, nil
		}
	}
	return "", errors.New("could not allocate a unique transfer number")
}

// randomSuffix produces the 5-character suffix of a transfer/handover number.
//
// The seed is kept in uint64 so the modulo can never go negative (a signed shift of a negative value is what
// produced an out-of-range index here); the alphabet covers A-Z0-9, matching Str::random's character space.
func randomSuffix(n int) string {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	buf := make([]byte, n)
	seed := uint64(time.Now().UnixNano())
	for i := range buf {
		seed = seed*6364136223846793005 + 1442695040888963407
		buf[i] = alphabet[(seed>>33)%uint64(len(alphabet))]
	}
	return string(buf)
}

// ---- setting: factory plan -------------------------------------------------------------------------

// SetFactoryPlan is WarehouseSettingsController::update (admin only, validated boolean).
func SetFactoryPlan(ctx context.Context, d *db.DB, actor Actor, enabled bool) (map[string]any, error) {
	if !actor.IsRole("admin") {
		return nil, &DomainError{Status: 403, Message: "Anda tidak memiliki akses untuk melakukan aksi ini."}
	}
	var id int64
	err := d.QueryRowContext(ctx, `SELECT id FROM warehouse_settings WHERE agent_id = ? LIMIT 1`, actor.AgentID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		res, insErr := d.ExecContext(ctx, `INSERT INTO warehouse_settings (agent_id, factory_plan_enabled, created_at, updated_at)
			VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, actor.AgentID, enabled)
		if insErr != nil {
			return nil, insErr
		}
		id, _ = res.LastInsertId()
	} else if err != nil {
		return nil, err
	} else {
		if _, err := d.ExecContext(ctx, `UPDATE warehouse_settings SET factory_plan_enabled = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, enabled, id); err != nil {
			return nil, err
		}
	}
	var (
		agentID    int64
		value      int
		createdRaw sql.NullString
		updatedRaw sql.NullString
	)
	if err := d.QueryRowContext(ctx, `SELECT agent_id, factory_plan_enabled, CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM warehouse_settings WHERE id = ?`, id).Scan(&agentID, &value, &createdRaw, &updatedRaw); err != nil {
		return nil, err
	}
	return map[string]any{
		"id":                   id,
		"agent_id":             agentID,
		"factory_plan_enabled": value == 1,
		"created_at":           formatMySQLString(createdRaw),
		"updated_at":           formatMySQLString(updatedRaw),
	}, nil
}
