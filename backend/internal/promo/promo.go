// Package promo is the agent-scoped promotion domain: vouchers and product discounts.
//
// Both are the same shape (branch-owned, product/variation targeted, date-bounded) and share the agent
// resolution rules, so they live side by side here.
//
// AGENT RESOLUTION (Voucher/ProductDiscountController, preserved):
//   - index  : the actor's own branch; super_admin may pass ?agent_id= to read another branch.
//   - create : the actor's own branch; super_admin MUST pass ?agent_id=.
//   - update/destroy: the row's branch; a non-super_admin acting on another branch gets 403.
//
// Every path re-validates that the referenced agent really is an Agen.
package promo

import (
	"context"
	"database/sql"
	"errors"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrNotFound is a missing row.
var ErrNotFound = errors.New("not found")

// DomainError carries a status, the reference's exact message and optional field errors.
type DomainError struct {
	Status  int
	Message string
	Fields  map[string]string
}

func (e *DomainError) Error() string { return e.Message }

// Actor is the caller.
type Actor struct {
	ID      int64
	AgentID int64
	Role    string
}

func (a Actor) isSuperAdmin() bool { return a.Role == "super_admin" }

// Page is the meta block these lists return (current_page/last_page/total — no per_page).
type Page struct {
	CurrentPage int `json:"current_page"`
	LastPage    int `json:"last_page"`
	Total       int `json:"total"`
}

// ---- agent resolution ------------------------------------------------------------------------------

// isAgent reports whether the id is an Agen account.
func isAgent(ctx context.Context, d *db.DB, id int64) (bool, error) {
	var ok bool
	err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND r.slug = 'agen' AND u.deleted_at IS NULL)`, id).Scan(&ok)
	return ok, err
}

// resolveReadAgent is resolveAgentId: own branch, or an explicit branch for super_admin.
func resolveReadAgent(ctx context.Context, d *db.DB, actor Actor, explicit *int64) (int64, error) {
	if actor.isSuperAdmin() && explicit != nil {
		ok, err := isAgent(ctx, d, *explicit)
		if err != nil {
			return 0, err
		}
		if !ok {
			return 0, &DomainError{Status: 422, Message: "Agen tidak ditemukan."}
		}
		return *explicit, nil
	}
	return actor.AgentID, nil
}

// resolveCreateAgent is resolveCreateAgentId: super_admin MUST name the branch.
func resolveCreateAgent(ctx context.Context, d *db.DB, actor Actor, explicit *int64) (int64, error) {
	if actor.isSuperAdmin() {
		if explicit == nil || *explicit <= 0 {
			return 0, &DomainError{Status: 422, Message: "agent_id wajib diisi untuk super_admin."}
		}
		ok, err := isAgent(ctx, d, *explicit)
		if err != nil {
			return 0, err
		}
		if !ok {
			return 0, &DomainError{Status: 422, Message: "Agen tidak ditemukan."}
		}
		return *explicit, nil
	}
	return actor.AgentID, nil
}

// resolveManageAgent is resolveManageAgentId: the row's branch, with a 403 for a foreign non-super_admin.
func resolveManageAgent(ctx context.Context, d *db.DB, actor Actor, rowAgentID int64, explicit *int64) (int64, error) {
	if actor.isSuperAdmin() {
		target := rowAgentID
		if explicit != nil && *explicit > 0 {
			target = *explicit
		}
		if target <= 0 {
			return 0, &DomainError{Status: 422, Message: "agent_id wajib diisi untuk super_admin."}
		}
		ok, err := isAgent(ctx, d, target)
		if err != nil {
			return 0, err
		}
		if !ok {
			return 0, &DomainError{Status: 422, Message: "Agen tidak ditemukan."}
		}
		return target, nil
	}
	if actor.AgentID != rowAgentID {
		return 0, &DomainError{Status: 403, Message: "Voucher bukan milik cabang Anda."}
	}
	return rowAgentID, nil
}

// ---- vouchers --------------------------------------------------------------------------------------

// VoucherInput is the validated voucher payload.
type VoucherInput struct {
	Code        *string
	Name        *string
	Type        *string
	Value       *float64
	IsActive    *bool
	ValidFrom   *string
	ValidUntil  *string
	ProductID   *int64
	VariationID *int64
	MaxUses     *int64
	// Presence flags distinguish "not sent" from "explicitly null" on update.
	SetProduct, SetVariation, SetMaxUses, SetValidFrom, SetValidUntil, SetIsActive bool
}

const voucherColumns = `SELECT v.id, v.agent_id, v.code, v.name, v.type, v.value, v.is_active,
	CAST(v.valid_from AS CHAR), CAST(v.valid_until AS CHAR), v.product_id, v.product_variation_id,
	v.max_uses, v.used_count, CAST(v.created_at AS CHAR), CAST(v.updated_at AS CHAR),
	p.name, p.sku, pv.sku
	FROM vouchers v
	LEFT JOIN products p ON p.id = v.product_id
	LEFT JOIN product_variations pv ON pv.id = v.product_variation_id`

func scanVouchers(rows *sql.Rows) ([]map[string]any, error) {
	out := []map[string]any{}
	for rows.Next() {
		var (
			id, agentID                     int64
			code, name, typ, value          string
			isActive                        int
			validFrom, validUntil           sql.NullString
			productID, variationID          sql.NullInt64
			maxUses, usedCount              sql.NullInt64
			created, updated                sql.NullString
			productName, productSKU, varSKU sql.NullString
		)
		if err := rows.Scan(&id, &agentID, &code, &name, &typ, &value, &isActive, &validFrom, &validUntil,
			&productID, &variationID, &maxUses, &usedCount, &created, &updated,
			&productName, &productSKU, &varSKU); err != nil {
			return nil, err
		}
		var product, variation any
		if productID.Valid {
			product = map[string]any{"id": productID.Int64, "name": nullStr(productName), "sku": nullStr(productSKU)}
		}
		if variationID.Valid {
			variation = map[string]any{"id": variationID.Int64, "sku": nullStr(varSKU)}
		}
		out = append(out, map[string]any{
			"id":                   id,
			"agent_id":             agentID,
			"code":                 code,
			"name":                 name,
			"type":                 typ,
			"value":                value,
			"is_active":            isActive == 1,
			"valid_from":           dateOnly(validFrom),
			"valid_until":          dateOnly(validUntil),
			"product_id":           nullIntPtr(productID),
			"product_variation_id": nullIntPtr(variationID),
			"max_uses":             nullIntPtr(maxUses),
			"used_count":           nullIntPtr(usedCount),
			"created_at":           formatMySQLString(created),
			"updated_at":           formatMySQLString(updated),
			"product":              product,
			"variation":            variation,
		})
	}
	return out, rows.Err()
}

// ListVouchers is VoucherController::index.
func ListVouchers(ctx context.Context, d *db.DB, actor Actor, explicit *int64, page, perPage int) ([]map[string]any, Page, error) {
	agentID, err := resolveReadAgent(ctx, d, actor, explicit)
	if err != nil {
		return nil, Page{}, err
	}
	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM vouchers WHERE agent_id = ?`, agentID).Scan(&total); err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, Page{CurrentPage: 1, LastPage: 1}, nil
		}
		return nil, Page{}, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	rows, err := d.QueryContext(ctx, voucherColumns+` WHERE v.agent_id = ? ORDER BY v.id DESC LIMIT ? OFFSET ?`,
		agentID, perPage, (page-1)*perPage)
	if err != nil {
		return nil, Page{}, err
	}
	defer rows.Close()
	items, err := scanVouchers(rows)
	if err != nil {
		return nil, Page{}, err
	}
	return items, Page{CurrentPage: page, LastPage: lastPage, Total: total}, nil
}

// CreateVoucher is VoucherController::store (code is upper-cased and trimmed).
func CreateVoucher(ctx context.Context, d *db.DB, actor Actor, explicit *int64, in VoucherInput) (map[string]any, error) {
	agentID, err := resolveCreateAgent(ctx, d, actor, explicit)
	if err != nil {
		return nil, err
	}
	if err := assertProductTarget(ctx, d, in.ProductID, in.VariationID); err != nil {
		return nil, err
	}
	res, err := d.ExecContext(ctx, `INSERT INTO vouchers
		(agent_id, code, name, type, value, is_active, valid_from, valid_until, product_id,
		 product_variation_id, max_uses, used_count, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, strings.ToUpper(strings.TrimSpace(strVal(in.Code))), strVal(in.Name), strVal(in.Type),
		decimalVal(in.Value), boolOr(in.IsActive, true), nullableStrPtr(in.ValidFrom), nullableStrPtr(in.ValidUntil),
		nullableInt(in.ProductID), nullableInt(in.VariationID), nullableInt(in.MaxUses))
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return voucherByID(ctx, d, id)
}

// UpdateVoucher is VoucherController::update (partial; "sometimes" semantics).
func UpdateVoucher(ctx context.Context, d *db.DB, actor Actor, id int64, explicit *int64, in VoucherInput) (map[string]any, error) {
	row, err := voucherRow(ctx, d, id)
	if err != nil {
		return nil, err
	}
	if _, err := resolveManageAgent(ctx, d, actor, row.agentID, explicit); err != nil {
		return nil, err
	}
	sets := []string{}
	args := []any{}
	if in.Code != nil {
		sets = append(sets, "code = ?")
		args = append(args, strings.ToUpper(strings.TrimSpace(*in.Code)))
	}
	if in.Name != nil {
		sets = append(sets, "name = ?")
		args = append(args, *in.Name)
	}
	if in.Type != nil {
		sets = append(sets, "type = ?")
		args = append(args, *in.Type)
	}
	if in.Value != nil {
		sets = append(sets, "value = ?")
		args = append(args, decimalVal(in.Value))
	}
	if in.SetIsActive {
		sets = append(sets, "is_active = ?")
		args = append(args, boolOr(in.IsActive, false))
	}
	if in.SetValidFrom {
		sets = append(sets, "valid_from = ?")
		args = append(args, nullableStrPtr(in.ValidFrom))
	}
	if in.SetValidUntil {
		sets = append(sets, "valid_until = ?")
		args = append(args, nullableStrPtr(in.ValidUntil))
	}
	if in.SetProduct {
		sets = append(sets, "product_id = ?")
		args = append(args, nullableInt(in.ProductID))
	}
	if in.SetVariation {
		sets = append(sets, "product_variation_id = ?")
		args = append(args, nullableInt(in.VariationID))
	}
	if in.SetMaxUses {
		sets = append(sets, "max_uses = ?")
		args = append(args, nullableInt(in.MaxUses))
	}
	if len(sets) > 0 {
		args = append(args, id)
		if _, err := d.ExecContext(ctx, `UPDATE vouchers SET `+strings.Join(sets, ", ")+
			`, updated_at = UTC_TIMESTAMP() WHERE id = ?`, args...); err != nil {
			return nil, err
		}
	}
	return voucherByID(ctx, d, id)
}

// DeleteVoucher is VoucherController::destroy ("Voucher dihapus." — a literal, not a catalogue key).
func DeleteVoucher(ctx context.Context, d *db.DB, actor Actor, id int64, explicit *int64) error {
	row, err := voucherRow(ctx, d, id)
	if err != nil {
		return err
	}
	if _, err := resolveManageAgent(ctx, d, actor, row.agentID, explicit); err != nil {
		return err
	}
	_, err = d.ExecContext(ctx, `DELETE FROM vouchers WHERE id = ?`, id)
	return err
}

// voucherRef is the minimal row the manage-path needs.
type voucherRef struct{ agentID int64 }

func voucherRow(ctx context.Context, d *db.DB, id int64) (*voucherRef, error) {
	var r voucherRef
	err := d.QueryRowContext(ctx, `SELECT agent_id FROM vouchers WHERE id = ?`, id).Scan(&r.agentID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return &r, nil
}

func voucherByID(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	rows, err := d.QueryContext(ctx, voucherColumns+` WHERE v.id = ?`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	list, err := scanVouchers(rows)
	if err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return nil, ErrNotFound
	}
	return list[0], nil
}

// ---- product discounts -----------------------------------------------------------------------------

// DiscountInput is the validated discount payload.
type DiscountInput struct {
	Name                                                          *string
	ProductID                                                     *int64
	VariationID                                                   *int64
	Percentage                                                    *int64
	IsActive                                                      *bool
	StartsAt                                                      *string
	EndsAt                                                        *string
	SetProduct, SetVariation, SetIsActive, SetStartsAt, SetEndsAt bool
}

const discountColumns = `SELECT d.id, d.agent_id, d.name, d.product_id, d.product_variation_id, d.percentage,
	d.is_active, CAST(d.starts_at AS CHAR), CAST(d.ends_at AS CHAR),
	CAST(d.created_at AS CHAR), CAST(d.updated_at AS CHAR), p.name, p.sku, pv.sku
	FROM product_discounts d
	LEFT JOIN products p ON p.id = d.product_id
	LEFT JOIN product_variations pv ON pv.id = d.product_variation_id`

func scanDiscounts(rows *sql.Rows) ([]map[string]any, error) {
	out := []map[string]any{}
	for rows.Next() {
		var (
			id, agentID                     int64
			name                            string
			productID, variationID          sql.NullInt64
			percentage                      int64
			isActive                        int
			startsAt, endsAt                sql.NullString
			created, updated                sql.NullString
			productName, productSKU, varSKU sql.NullString
		)
		if err := rows.Scan(&id, &agentID, &name, &productID, &variationID, &percentage, &isActive,
			&startsAt, &endsAt, &created, &updated, &productName, &productSKU, &varSKU); err != nil {
			return nil, err
		}
		var product, variation any
		if productID.Valid {
			product = map[string]any{"id": productID.Int64, "name": nullStr(productName), "sku": nullStr(productSKU)}
		}
		if variationID.Valid {
			variation = map[string]any{"id": variationID.Int64, "sku": nullStr(varSKU)}
		}
		out = append(out, map[string]any{
			"id":                   id,
			"agent_id":             agentID,
			"name":                 name,
			"product_id":           nullIntPtr(productID),
			"product_variation_id": nullIntPtr(variationID),
			"percentage":           percentage,
			"is_active":            isActive == 1,
			"starts_at":            dateOnly(startsAt),
			"ends_at":              dateOnly(endsAt),
			"created_at":           formatMySQLString(created),
			"updated_at":           formatMySQLString(updated),
			"product":              product,
			"variation":            variation,
		})
	}
	return out, rows.Err()
}

// ListDiscounts is ProductDiscountController::index.
func ListDiscounts(ctx context.Context, d *db.DB, actor Actor, explicit *int64, page, perPage int) ([]map[string]any, Page, error) {
	agentID, err := resolveReadAgent(ctx, d, actor, explicit)
	if err != nil {
		return nil, Page{}, err
	}
	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM product_discounts WHERE agent_id = ?`, agentID).Scan(&total); err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, Page{CurrentPage: 1, LastPage: 1}, nil
		}
		return nil, Page{}, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	rows, err := d.QueryContext(ctx, discountColumns+` WHERE d.agent_id = ? ORDER BY d.id DESC LIMIT ? OFFSET ?`,
		agentID, perPage, (page-1)*perPage)
	if err != nil {
		return nil, Page{}, err
	}
	defer rows.Close()
	items, err := scanDiscounts(rows)
	if err != nil {
		return nil, Page{}, err
	}
	return items, Page{CurrentPage: page, LastPage: lastPage, Total: total}, nil
}

// CreateDiscount is ProductDiscountController::store — a discount MUST name a product, and a variation must
// belong to that product (audit A1-17).
func CreateDiscount(ctx context.Context, d *db.DB, actor Actor, explicit *int64, in DiscountInput) (map[string]any, error) {
	agentID, err := resolveCreateAgent(ctx, d, actor, explicit)
	if err != nil {
		return nil, err
	}
	if err := assertDiscountTarget(ctx, d, in.ProductID, in.VariationID); err != nil {
		return nil, err
	}
	res, err := d.ExecContext(ctx, `INSERT INTO product_discounts
		(agent_id, name, product_id, product_variation_id, percentage, is_active, starts_at, ends_at,
		 created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, strVal(in.Name), nullableInt(in.ProductID), nullableInt(in.VariationID),
		clampPercentage(in.Percentage), boolOr(in.IsActive, true),
		nullableStrPtr(in.StartsAt), nullableStrPtr(in.EndsAt))
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return discountByID(ctx, d, id)
}

// UpdateDiscount is ProductDiscountController::update.
func UpdateDiscount(ctx context.Context, d *db.DB, actor Actor, id int64, explicit *int64, in DiscountInput) (map[string]any, error) {
	existing, err := discountRow(ctx, d, id)
	if err != nil {
		return nil, err
	}
	if _, err := resolveManageAgent(ctx, d, actor, existing.agentID, explicit); err != nil {
		return nil, err
	}
	// Re-validate the target only when the target changed (as the reference does).
	if in.SetProduct || in.SetVariation {
		productID := existing.productID
		variationID := existing.variationID
		if in.SetProduct {
			productID = in.ProductID
		}
		if in.SetVariation {
			variationID = in.VariationID
		}
		if err := assertDiscountTarget(ctx, d, productID, variationID); err != nil {
			return nil, err
		}
	}

	sets := []string{}
	args := []any{}
	if in.Name != nil {
		sets = append(sets, "name = ?")
		args = append(args, *in.Name)
	}
	if in.SetProduct {
		sets = append(sets, "product_id = ?")
		args = append(args, nullableInt(in.ProductID))
	}
	if in.SetVariation {
		sets = append(sets, "product_variation_id = ?")
		args = append(args, nullableInt(in.VariationID))
	}
	if in.Percentage != nil {
		sets = append(sets, "percentage = ?")
		args = append(args, clampPercentage(in.Percentage))
	}
	if in.SetIsActive {
		sets = append(sets, "is_active = ?")
		args = append(args, boolOr(in.IsActive, false))
	}
	if in.SetStartsAt {
		sets = append(sets, "starts_at = ?")
		args = append(args, nullableStrPtr(in.StartsAt))
	}
	if in.SetEndsAt {
		sets = append(sets, "ends_at = ?")
		args = append(args, nullableStrPtr(in.EndsAt))
	}
	if len(sets) > 0 {
		args = append(args, id)
		if _, err := d.ExecContext(ctx, `UPDATE product_discounts SET `+strings.Join(sets, ", ")+
			`, updated_at = UTC_TIMESTAMP() WHERE id = ?`, args...); err != nil {
			return nil, err
		}
	}
	return discountByID(ctx, d, id)
}

// DeleteDiscount is ProductDiscountController::destroy ("Discount dihapus.").
func DeleteDiscount(ctx context.Context, d *db.DB, actor Actor, id int64, explicit *int64) error {
	existing, err := discountRow(ctx, d, id)
	if err != nil {
		return err
	}
	if _, err := resolveManageAgent(ctx, d, actor, existing.agentID, explicit); err != nil {
		return err
	}
	_, err = d.ExecContext(ctx, `DELETE FROM product_discounts WHERE id = ?`, id)
	return err
}

type discountRef struct {
	agentID     int64
	productID   *int64
	variationID *int64
}

func discountRow(ctx context.Context, d *db.DB, id int64) (*discountRef, error) {
	var (
		r        discountRef
		pid, vid sql.NullInt64
	)
	err := d.QueryRowContext(ctx, `SELECT agent_id, product_id, product_variation_id FROM product_discounts WHERE id = ?`,
		id).Scan(&r.agentID, &pid, &vid)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	r.productID = nullIntPtr64(pid)
	r.variationID = nullIntPtr64(vid)
	return &r, nil
}

func discountByID(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	rows, err := d.QueryContext(ctx, discountColumns+` WHERE d.id = ?`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	list, err := scanDiscounts(rows)
	if err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return nil, ErrNotFound
	}
	return list[0], nil
}

// assertDiscountTarget rejects a discount with no product and a variation that belongs to another product.
func assertDiscountTarget(ctx context.Context, d *db.DB, productID, variationID *int64) error {
	if productID == nil {
		return &DomainError{Status: 422, Message: "Diskon harus menargetkan produk atau variasi."}
	}
	if variationID == nil {
		return nil
	}
	var ok bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM product_variations
		WHERE id = ? AND product_id = ?)`, *variationID, *productID).Scan(&ok); err != nil {
		return err
	}
	if !ok {
		return &DomainError{Status: 422, Message: "Variasi tidak cocok dengan produk target."}
	}
	return nil
}

// assertProductTarget is the voucher equivalent: the variation, when given, must belong to the product.
func assertProductTarget(ctx context.Context, d *db.DB, productID, variationID *int64) error {
	if productID == nil || variationID == nil {
		return nil
	}
	var ok bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM product_variations
		WHERE id = ? AND product_id = ?)`, *variationID, *productID).Scan(&ok); err != nil {
		return err
	}
	if !ok {
		return &DomainError{Status: 422, Message: "Variasi tidak cocok dengan produk target."}
	}
	return nil
}

// clampPercentage mirrors max(1, min(100, n)).
func clampPercentage(v *int64) any {
	if v == nil {
		return 1
	}
	n := *v
	if n < 1 {
		n = 1
	}
	if n > 100 {
		n = 100
	}
	return n
}
