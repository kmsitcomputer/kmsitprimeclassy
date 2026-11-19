package catalog

import (
	"bytes"
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
	"unicode"

	"github.com/go-sql-driver/mysql"
	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

var (
	ErrProductAdminNotFound  = errors.New("product not found")
	ErrProductSKU            = errors.New("catalog SKU already exists")
	ErrProductSlug           = errors.New("product slug already exists")
	ErrProductCategory       = errors.New("product category not found")
	ErrProductMode           = errors.New("product mode does not support this operation")
	ErrVariationConflict     = errors.New("variation composition is already used")
	ErrVariationNotFound     = errors.New("variation not found")
	ErrProductImageNotFound  = errors.New("product image not found")
	ErrProductImageVariation = errors.New("variation does not belong to product")
	ErrProductImageOrder     = errors.New("image order must contain the entire gallery")
	ErrProductDeleteConflict = errors.New("product has references")
)

// ProductAdminInput contains only mutable product columns. A nil pointer means
// the field was not supplied; SetCategory distinguishes null from omission.
// ProductDeleteBlockers is the 409 payload ProductHardDeleteService::delete throws with:
// message + errors {references: {table: count}}.
type ProductDeleteBlockers struct {
	Blockers map[string]int
}

func (e *ProductDeleteBlockers) Error() string {
	return "Produk tidak dapat dihapus permanen karena masih direferensikan."
}

type ProductAdminInput struct {
	Name                *string
	Slug                *string
	SKU                 *string
	CategoryID          *int64
	SetCategory         bool
	Description         *string
	SetDescription      bool
	ShortDescription    *string
	SetShortDescription bool
	HasVariations       *bool
	BasePrice           *float64
	SetBasePrice        bool
	WeightGrams         *int64
	SetWeightGrams      bool
	Status              *string
	// Actor identity goes onto the audit row (ActivityLogger records causer + actor_role).
	ActorID   int64
	ActorRole string
}

type VariationAdminInput struct {
	SKU            *string
	Price          *float64
	WeightGrams    *int64
	IsActive       *bool
	Attributes     map[string]string
	AttributeOrder []string
	SetAttrs       bool
}

type AdminListQuery struct {
	Status     string
	Search     string
	CategoryID int64
	Page       int
	PerPage    int
}

type AdminList struct {
	Items    []map[string]any
	Page     int
	LastPage int
	Total    int
}

// AttachSellableRow mirrors ProductManagementController::attachAgentAvailability → SellableStockService::
// forTargets — the BATCH rule, which differs subtly from calculate(): targetFromRows filters rows to
// transit+factory_plan and falls back to LEGACY when none match, so a target holding only 'sub' rows
// still reports its legacy quantity_on_hand (35 for fixture product 1), never 0.
func AttachSellableRow(ctx context.Context, d *db.DB, agentID int64, product map[string]any, variations []map[string]any) {
	if product == nil {
		return
	}
	hasVariations, _ := product["has_variations"].(bool)
	var planEnabled bool
	_ = d.QueryRowContext(ctx, `SELECT COALESCE(factory_plan_enabled, 0) FROM warehouse_settings WHERE agent_id = ?`, agentID).Scan(&planEnabled)
	if !hasVariations {
		pid := int64Value(product["id"])
		available := forTargetsAvailable(ctx, d, agentID, &pid, nil, planEnabled)
		product["stock"] = map[string]any{"available": available, "in_stock": available > 0, "login_to_view": false}
		product["agent_available_quantity"] = available
		return
	}
	for _, variation := range variations {
		vid := int64Value(variation["id"])
		available := forTargetsAvailable(ctx, d, agentID, nil, &vid, planEnabled)
		variation["stock"] = map[string]any{"available": available, "in_stock": available > 0, "login_to_view": false}
		variation["agent_available_quantity"] = available
	}
}

// AttachPublicAvailability mirrors ProductController's optional authenticated stock projection. Agent
// availability is resolved from the viewer's own agent. When an owned Sub location is selected, Laravel
// replaces only stock.available with Sub sellable stock and keeps agent_available_quantity as the Agent
// projection, so this helper preserves both values.
func AttachPublicAvailability(ctx context.Context, d *db.DB, agentID int64, subLocationID *int64, product map[string]any) error {
	variations, _ := product["variations"].([]map[string]any)
	AttachSellableRow(ctx, d, agentID, product, variations)
	if subLocationID == nil {
		return nil
	}
	hasVariations, _ := product["has_variations"].(bool)
	if !hasVariations {
		id := int64Value(product["id"])
		available, err := subCatalogAvailable(ctx, d, *subLocationID, &id, nil)
		if err != nil {
			return err
		}
		setCatalogStock(product, available)
		return nil
	}
	for _, variation := range variations {
		id := int64Value(variation["id"])
		available, err := subCatalogAvailable(ctx, d, *subLocationID, nil, &id)
		if err != nil {
			return err
		}
		setCatalogStock(variation, available)
	}
	return nil
}

func subCatalogAvailable(ctx context.Context, d *db.DB, subLocationID int64, productID, variationID *int64) (int, error) {
	clause, args := "product_id = ? AND product_variation_id IS NULL", []any{}
	if variationID != nil {
		clause, args = "product_variation_id = ?", []any{*variationID}
	} else {
		args = []any{*productID}
	}
	var physical, reserved int
	queryArgs := []any{subLocationID}
	queryArgs = append(queryArgs, args...)
	queryArgs = append(queryArgs, subLocationID)
	queryArgs = append(queryArgs, args...)
	if err := d.QueryRowContext(ctx, `SELECT COALESCE((SELECT quantity FROM warehouse_stocks
		WHERE stock_type = 'sub' AND sub_location_id = ? AND `+clause+` ORDER BY id ASC LIMIT 1), 0),
		COALESCE((SELECT SUM(quantity) FROM sub_stock_reservations
		WHERE status = 'active' AND sub_location_id = ? AND `+clause+`), 0)`,
		queryArgs...).Scan(&physical, &reserved); err != nil {
		return 0, err
	}
	return maxInt(physical-reserved, 0), nil
}

func setCatalogStock(target map[string]any, available int) {
	target["stock"] = map[string]any{"available": available, "in_stock": available > 0, "login_to_view": false}
}

func maxInt(value, floor int) int {
	if value < floor {
		return floor
	}
	return value
}

// forTargetsAvailable is SellableStockService::targetFromRows.
func forTargetsAvailable(ctx context.Context, d *db.DB, agentID int64, productID, variationID *int64, planEnabled bool) int {
	clause, args := "1 = 1", []any{}
	if productID != nil {
		clause, args = "product_id = ? AND product_variation_id IS NULL", []any{*productID}
	} else if variationID != nil {
		clause, args = "product_variation_id = ? AND product_id IS NULL", []any{*variationID}
	}
	var base int
	err := d.QueryRowContext(ctx, `SELECT COALESCE(SUM(CASE WHEN stock_type = 'transit' THEN quantity ELSE 0 END), 0)
			+ CASE WHEN ? THEN COALESCE(SUM(CASE WHEN stock_type = 'factory_plan' THEN quantity ELSE 0 END), 0) ELSE 0 END
		FROM warehouse_stocks WHERE agent_id = ? AND stock_type IN ('transit','factory_plan') AND `+clause,
		append([]any{planEnabled, agentID}, args...)...).Scan(&base)
	if err != nil {
		return 0
	}
	var reserved, legacy int
	rest := append([]any{agentID}, args...)
	if productID != nil {
		_ = d.QueryRowContext(ctx, `SELECT COALESCE((SELECT quantity_reserved FROM product_stocks
				WHERE agent_id = ? AND product_id = ?), 0),
			COALESCE((SELECT quantity_on_hand FROM product_stocks WHERE agent_id = ? AND product_id = ?), 0)`,
			agentID, *productID, agentID, *productID).Scan(&reserved, &legacy)
		_ = rest
	} else if variationID != nil {
		_ = d.QueryRowContext(ctx, `SELECT COALESCE((SELECT quantity_reserved FROM product_variation_stocks
				WHERE agent_id = ? AND product_variation_id = ?), 0),
			COALESCE((SELECT quantity_on_hand FROM product_variation_stocks WHERE agent_id = ? AND product_variation_id = ?), 0)`,
			agentID, *variationID, agentID, *variationID).Scan(&reserved, &legacy)
	}
	// rows.isEmpty() → legacy fallback, exactly like targetFromRows.
	var rowCount int
	_ = d.QueryRowContext(ctx, `SELECT COUNT(*) FROM warehouse_stocks WHERE agent_id = ?
		AND stock_type IN ('transit','factory_plan') AND `+clause, append([]any{agentID}, args...)...).Scan(&rowCount)
	if rowCount == 0 {
		if legacy-reserved > 0 {
			return legacy - reserved
		}
		return 0
	}
	if base-reserved > 0 {
		return base - reserved
	}
	return 0
}

func int64Value(v any) int64 {
	if n, ok := v.(int64); ok {
		return n
	}
	return 0
}

func ProductAdminList(ctx context.Context, d *db.DB, q AdminListQuery) (*AdminList, error) {
	if q.Page < 1 {
		q.Page = 1
	}
	if q.PerPage < 1 || q.PerPage > 100 {
		q.PerPage = 25
	}
	status := q.Status
	if status == "" {
		status = "all"
	}
	if status != "all" && status != "active" && status != "draft" && status != "inactive" {
		return nil, ErrProductMode
	}

	args := []any{}
	where := []string{"p.deleted_at IS NULL"}
	if status != "all" {
		where = append(where, "p.status = ?")
		args = append(args, status)
	}
	if q.CategoryID > 0 {
		ids, err := categoryAndDescendantIDsByID(ctx, d, q.CategoryID)
		if err != nil {
			return nil, err
		}
		if len(ids) == 0 {
			where = append(where, "1 = 0")
		} else {
			marks, catArgs := idMarks(ids)
			where = append(where, "p.category_id IN ("+marks+")")
			args = append(args, catArgs...)
		}
	}
	if q.Search != "" {
		pattern := "%" + escapeLike(q.Search) + "%"
		where = append(where, `(p.name LIKE ? ESCAPE '\\' OR p.sku LIKE ? ESCAPE '\\' OR EXISTS (
			SELECT 1 FROM product_variations v WHERE v.product_id = p.id AND v.deleted_at IS NULL
			AND v.sku LIKE ? ESCAPE '\\'))`)
		args = append(args, pattern, pattern, pattern)
	}
	condition := strings.Join(where, " AND ")
	var total int
	if err := d.QueryRowContext(ctx, "SELECT COUNT(*) FROM products p WHERE "+condition, args...).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := int(math.Ceil(float64(total) / float64(q.PerPage)))
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (q.Page - 1) * q.PerPage
	rows, err := d.QueryContext(ctx, `SELECT p.id FROM products p WHERE `+condition+`
		ORDER BY p.id DESC LIMIT ? OFFSET ?`, append(args, q.PerPage, offset)...)
	if err != nil {
		return nil, err
	}
	ids := make([]int64, 0)
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return nil, err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	items := make([]map[string]any, 0, len(ids))
	for _, id := range ids {
		row, err := ProductAdminByID(ctx, d, id)
		if err != nil {
			return nil, err
		}
		items = append(items, row)
	}
	return &AdminList{Items: items, Page: q.Page, LastPage: lastPage, Total: total}, nil
}

func ProductAdminByID(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	product, err := loadAdminProduct(ctx, d, id, false)
	if err != nil {
		return nil, err
	}
	return product, nil
}

// productAudit is ActivityLogger::log for App\Models\Product subjects (description NULL, no ip/ua).
func productAudit(ctx context.Context, tx *sql.Tx, actorID, productID int64, event string, properties map[string]any) error {
	payload := any(nil)
	if len(properties) > 0 {
		// PHP json_encode never escapes <, > and &; Go's Marshal does by default. The properties column
		// must hold the same bytes Laravel would store.
		var buf bytes.Buffer
		enc := json.NewEncoder(&buf)
		enc.SetEscapeHTML(false)
		if err := enc.Encode(properties); err != nil {
			return err
		}
		// PHP json_encode escapes "/" as "\/" by default — the column must hold the same bytes.
		payload = strings.ReplaceAll(strings.TrimSpace(buf.String()), "/", "\\/")
	}
	var causer any
	if actorID > 0 {
		causer = actorID
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, ?, ?, ?, NULL, ?, NULL, NULL, UTC_TIMESTAMP(6))`,
		causer, "App\\Models\\Product", productID, event, payload)
	return err
}

// productSnapshot is the old/new projection ProductController::update audits.
func productSnapshot(product map[string]any) map[string]any {
	var categoryID any
	if category, ok := product["category"].(map[string]any); ok {
		categoryID = nullableAny(category["id"])
	}
	return map[string]any{
		"sku":               nullableAny(product["sku"]),
		"category_id":       categoryID,
		"name":              product["name"],
		"slug":              product["slug"],
		"description":       nullableAny(product["description"]),
		"short_description": nullableAny(product["short_description"]),
		"base_price":        nullableAny(product["base_price"]),
		"weight_grams":      nullableAny(product["weight_grams"]),
		"status":            product["status"],
	}
}

func nullableAny(v any) any {
	if v == nil {
		return nil
	}
	return v
}

func CreateProductAdmin(ctx context.Context, d *db.DB, actorID int64, in ProductAdminInput) (map[string]any, error) {
	if in.Name == nil || strings.TrimSpace(*in.Name) == "" || in.HasVariations == nil {
		return nil, ErrProductMode
	}
	name := strings.TrimSpace(*in.Name)
	if in.Slug == nil || strings.TrimSpace(*in.Slug) == "" {
		slug, err := uniqueProductSlug(ctx, d, name)
		if err != nil {
			return nil, err
		}
		in.Slug = &slug
	}
	if *in.HasVariations {
		if in.SKU != nil || in.BasePrice != nil || in.WeightGrams != nil {
			return nil, ErrProductMode
		}
	} else if in.SKU == nil || strings.TrimSpace(*in.SKU) == "" || in.BasePrice == nil || in.WeightGrams == nil {
		return nil, ErrProductMode
	}
	var id int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if in.CategoryID != nil {
			if err := assertProductCategory(ctx, tx, *in.CategoryID); err != nil {
				return err
			}
		}
		if err := assertProductSlug(ctx, tx, *in.Slug, 0); err != nil {
			return err
		}
		now := time.Now().UTC()
		res, err := tx.ExecContext(ctx, `INSERT INTO products
			(category_id, created_by, name, slug, description, short_description, sku, has_variations,
			 base_price, weight_grams, status, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			in.CategoryID, actorID, name, *in.Slug, in.Description, in.ShortDescription, in.SKU, *in.HasVariations,
			in.BasePrice, in.WeightGrams, productStatus(in.Status), now, now)
		if err != nil {
			return duplicateProductError(err)
		}
		id, err = res.LastInsertId()
		if err != nil {
			return err
		}
		if in.SKU != nil {
			_, err = tx.ExecContext(ctx, "INSERT INTO catalog_skus (sku, owner_type, owner_id) VALUES (?, 'product', ?)", *in.SKU, id)
			if err != nil {
				return duplicateProductError(err)
			}
		}
		return productAudit(ctx, tx, actorID, id, "product.created", map[string]any{
			"actor_role": in.ActorRole,
		})
	})
	if err != nil {
		return nil, err
	}
	return ProductAdminByID(ctx, d, id)
}

func UpdateProductAdmin(ctx context.Context, d *db.DB, id int64, in ProductAdminInput) (map[string]any, error) {
	return updateProductAdmin(ctx, d, id, in)
}

func updateProductAdmin(ctx context.Context, d *db.DB, id int64, in ProductAdminInput) (map[string]any, error) {
	product, err := loadAdminProduct(ctx, d, id, false)
	if err != nil {
		return nil, err
	}
	before := productSnapshot(product)
	hasVariations := product["has_variations"].(bool)
	if hasVariations && (in.SKU != nil || in.SetBasePrice || in.SetWeightGrams) {
		return nil, ErrProductMode
	}
	if !hasVariations && in.SKU != nil && strings.TrimSpace(*in.SKU) == "" {
		return nil, ErrProductMode
	}
	sets, args := []string{}, []any{}
	add := func(column string, value any) { sets = append(sets, column+" = ?"); args = append(args, value) }
	if in.Name != nil {
		add("name", strings.TrimSpace(*in.Name))
	}
	if in.Slug != nil {
		add("slug", *in.Slug)
	}
	if in.SetDescription {
		add("description", in.Description)
	}
	if in.SetShortDescription {
		add("short_description", in.ShortDescription)
	}
	if in.Status != nil {
		add("status", *in.Status)
	}
	if in.SetCategory {
		add("category_id", in.CategoryID)
	}
	if in.SetBasePrice {
		add("base_price", in.BasePrice)
	}
	if in.SetWeightGrams {
		add("weight_grams", in.WeightGrams)
	}
	if in.SKU != nil {
		add("sku", *in.SKU)
	}
	if len(sets) == 0 {
		// Laravel ALWAYS logs product.updated (the controller audits after update(), even a no-op).
		err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
			return productAudit(ctx, tx, in.ActorID, id, "product.updated", map[string]any{
				"actor_role": in.ActorRole, "old": before, "new": before,
			})
		})
		if err != nil {
			return nil, err
		}
		return product, nil
	}
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var oldSKU sql.NullString
		var currentID int64
		if err := tx.QueryRowContext(ctx, "SELECT id, sku FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE", id).Scan(&currentID, &oldSKU); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrProductAdminNotFound
			}
			return err
		}
		if in.SetCategory && in.CategoryID != nil {
			if err := assertProductCategory(ctx, tx, *in.CategoryID); err != nil {
				return err
			}
		}
		if in.Slug != nil {
			if err := assertProductSlug(ctx, tx, *in.Slug, id); err != nil {
				return err
			}
		}
		if in.SKU != nil {
			if err := assertCatalogSKU(ctx, tx, *in.SKU, "product", id); err != nil {
				return err
			}
		}
		updateSets := append(append([]string(nil), sets...), "updated_at = ?")
		updateArgs := append(append([]any(nil), args...), time.Now().UTC(), id)
		if _, err := tx.ExecContext(ctx, "UPDATE products SET "+strings.Join(updateSets, ", ")+" WHERE id = ?", updateArgs...); err != nil {
			return duplicateProductError(err)
		}
		if in.SKU != nil {
			_, err := tx.ExecContext(ctx, `INSERT INTO catalog_skus (sku, owner_type, owner_id) VALUES (?, 'product', ?)
				ON DUPLICATE KEY UPDATE sku = VALUES(sku)`, *in.SKU, id)
			if err != nil {
				return duplicateProductError(err)
			}
		} else if oldSKU.Valid {
			// SKU edits happen only when a new SKU is supplied. Keeping this branch
			// explicit documents the same lifetime reservation as Laravel.
			_ = oldSKU
		}
		// after MUST be a copy: mutating the shared map would rewrite the audit's old values.
		after := map[string]any{}
		for k, v := range before {
			after[k] = v
		}
		if in.Name != nil {
			after["name"] = strings.TrimSpace(*in.Name)
		}
		if in.Slug != nil {
			after["slug"] = *in.Slug
		}
		if in.SetDescription {
			after["description"] = nullableAny(in.Description)
		}
		if in.SetShortDescription {
			after["short_description"] = nullableAny(in.ShortDescription)
		}
		if in.Status != nil {
			after["status"] = *in.Status
		}
		if in.SetCategory {
			after["category_id"] = nullableAny(in.CategoryID)
		}
		if in.SetBasePrice {
			after["base_price"] = nullableAny(in.BasePrice)
		}
		if in.SetWeightGrams {
			after["weight_grams"] = nullableAny(in.WeightGrams)
		}
		if in.SKU != nil {
			after["sku"] = *in.SKU
		}
		return productAudit(ctx, tx, in.ActorID, id, "product.updated", map[string]any{
			"actor_role": in.ActorRole, "old": before, "new": after,
		})
	})
	if err != nil {
		return nil, err
	}
	return ProductAdminByID(ctx, d, id)
}

func SoftDeleteProductAdmin(ctx context.Context, d *db.DB, id int64, actorID int64, actorRole string) error {
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, "UPDATE products SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL",
			time.Now().UTC(), time.Now().UTC(), id); err != nil {
			return err
		}
		return productAudit(ctx, tx, actorID, id, "product.deleted", map[string]any{"actor_role": actorRole})
	})
	if err != nil {
		return err
	}
	// RowsAffected is unavailable through Tx; a missing row already surfaced as a 404 binding in the caller.
	return nil
}

func HardDeleteProductAdmin(ctx context.Context, d *db.DB, id int64, actorID int64, actorRole string) error {
	var paths []string
	var snapshot map[string]any
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var found int64
		if err := tx.QueryRowContext(ctx, "SELECT id FROM products WHERE id = ? FOR UPDATE", id).Scan(&found); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrProductAdminNotFound
			}
			return err
		}
		variationRows, err := tx.QueryContext(ctx, "SELECT id FROM product_variations WHERE product_id = ? ORDER BY id FOR UPDATE", id)
		if err != nil {
			return err
		}
		variationIDs := []int64{}
		for variationRows.Next() {
			var vid int64
			if err := variationRows.Scan(&vid); err != nil {
				variationRows.Close()
				return err
			}
			variationIDs = append(variationIDs, vid)
		}
		if err := variationRows.Err(); err != nil {
			variationRows.Close()
			return err
		}
		variationRows.Close()
		if len(variationIDs) > 0 {
			if err := assertProductUnreferenced(ctx, tx, id, variationIDs); err != nil {
				return err
			}
		} else if err := assertProductUnreferenced(ctx, tx, id, nil); err != nil {
			return err
		}
		imageRows, err := tx.QueryContext(ctx, "SELECT path FROM product_images WHERE product_id = ?", id)
		if err != nil {
			return err
		}
		for imageRows.Next() {
			var path string
			if err := imageRows.Scan(&path); err != nil {
				imageRows.Close()
				return err
			}
			paths = append(paths, path)
		}
		if err := imageRows.Err(); err != nil {
			imageRows.Close()
			return err
		}
		imageRows.Close()
		if _, err := tx.ExecContext(ctx, "DELETE FROM catalog_skus WHERE owner_type = 'product' AND owner_id = ?", id); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, "DELETE FROM catalog_skus WHERE owner_type = 'variant' AND owner_id IN (SELECT id FROM product_variations WHERE product_id = ?)", id); err != nil {
			return err
		}
		_, err = tx.ExecContext(ctx, "DELETE FROM products WHERE id = ?", id)
		if err != nil {
			return err
		}
		var sku sql.NullString
		var name, status string
		if err := tx.QueryRowContext(ctx, "SELECT sku, name, status FROM products WHERE id = ?", id).Scan(&sku, &name, &status); err == nil {
			snapshot = map[string]any{"id": id, "sku": nullStr(sku), "name": name, "status": status}
		}
		return productAudit(ctx, tx, actorID, id, "product.hard_deleted", map[string]any{
			"actor_role": actorRole, "old": snapshot,
		})
	})
	if err != nil {
		var mysqlErr *mysql.MySQLError
		if errors.As(err, &mysqlErr) && (mysqlErr.Number == 1451 || mysqlErr.Number == 1217) {
			return ErrProductDeleteConflict
		}
		return err
	}
	for _, path := range paths {
		var exists int
		if err := d.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM product_images WHERE path = ?)", path).Scan(&exists); err != nil {
			continue
		}
		if exists == 0 && filepath.IsLocal(filepath.FromSlash(path)) {
			_ = os.Remove(filepath.Join(mediaPublicRoot(), filepath.FromSlash(path)))
		}
	}
	return nil
}

func assertProductUnreferenced(ctx context.Context, tx *sql.Tx, productID int64, variationIDs []int64) error {
	owned := map[string]bool{"products": true, "product_variations": true}
	rows, err := tx.QueryContext(ctx, `SELECT DISTINCT kcu.TABLE_NAME
		FROM information_schema.REFERENTIAL_CONSTRAINTS rc
		JOIN information_schema.KEY_COLUMN_USAGE kcu
		  ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
		 AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
		 AND kcu.TABLE_NAME = rc.TABLE_NAME
		WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.DELETE_RULE = 'CASCADE'
		  AND kcu.REFERENCED_TABLE_NAME IN ('products', 'product_variations')`)
	if err != nil {
		return err
	}
	for rows.Next() {
		var table string
		if err := rows.Scan(&table); err != nil {
			rows.Close()
			return err
		}
		owned[table] = true
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	columns, err := tx.QueryContext(ctx, `SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('product_id', 'product_variation_id')`)
	if err != nil {
		return err
	}
	type refColumn struct{ table, column string }
	var refs []refColumn
	for columns.Next() {
		var table, column string
		if err := columns.Scan(&table, &column); err != nil {
			columns.Close()
			return err
		}
		refs = append(refs, refColumn{table: table, column: column})
	}
	if err := columns.Err(); err != nil {
		columns.Close()
		return err
	}
	columns.Close()
	found := map[string]int{}
	for _, ref := range refs {
		table, column := ref.table, ref.column
		if owned[table] {
			continue
		}
		if column == "product_variation_id" && len(variationIDs) == 0 {
			continue
		}
		query := "SELECT COUNT(*) FROM `" + strings.ReplaceAll(table, "`", "``") + "` WHERE `" + strings.ReplaceAll(column, "`", "``") + "` = ?"
		args := []any{productID}
		if column == "product_variation_id" {
			marks, variationArgs := idMarks(variationIDs)
			query = "SELECT COUNT(*) FROM `" + strings.ReplaceAll(table, "`", "``") + "` WHERE `" + strings.ReplaceAll(column, "`", "``") + "` IN (" + marks + ")"
			args = variationArgs
		}
		var count int
		if err := tx.QueryRowContext(ctx, query, args...).Scan(&count); err != nil {
			return err
		}
		if count > 0 {
			found[table] += count
		}
	}
	if len(found) > 0 {
		return &ProductDeleteBlockers{Blockers: found}
	}
	return nil
}

func CreateProductVariationAdmin(ctx context.Context, d *db.DB, productID int64, in VariationAdminInput) (map[string]any, error) {
	if in.SKU == nil || in.Price == nil || in.WeightGrams == nil || len(in.Attributes) == 0 {
		return nil, ErrProductMode
	}
	var id int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var enabled bool
		if err := tx.QueryRowContext(ctx, "SELECT has_variations FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE", productID).Scan(&enabled); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrProductAdminNotFound
			}
			return err
		}
		if !enabled {
			return ErrProductMode
		}
		if err := assertCatalogSKU(ctx, tx, *in.SKU, "variant", 0); err != nil {
			return err
		}
		now := time.Now().UTC()
		active := true
		if in.IsActive != nil {
			active = *in.IsActive
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO product_variations
			(product_id, sku, price, weight_grams, is_active, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?)`, productID, *in.SKU, *in.Price, *in.WeightGrams, active, now, now)
		if err != nil {
			return duplicateProductError(err)
		}
		id, err = res.LastInsertId()
		if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, "INSERT INTO catalog_skus (sku, owner_type, owner_id) VALUES (?, 'variant', ?)", *in.SKU, id); err != nil {
			return duplicateProductError(err)
		}
		if err := replaceVariationAttributes(ctx, tx, productID, id, in.Attributes, in.AttributeOrder); err != nil {
			return err
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return ProductVariationAdminByID(ctx, d, productID, id)
}

func UpdateProductVariationAdmin(ctx context.Context, d *db.DB, productID, variationID int64, in VariationAdminInput) (map[string]any, error) {
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var exists int64
		if err := tx.QueryRowContext(ctx, "SELECT id FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE", productID).Scan(&exists); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrProductAdminNotFound
			}
			return err
		}
		var oldSKU string
		if err := tx.QueryRowContext(ctx, "SELECT sku FROM product_variations WHERE id = ? AND product_id = ? AND deleted_at IS NULL FOR UPDATE", variationID, productID).Scan(&oldSKU); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrVariationNotFound
			}
			return err
		}
		sets, args := []string{}, []any{}
		add := func(col string, value any) { sets = append(sets, col+" = ?"); args = append(args, value) }
		if in.SKU != nil {
			if err := assertCatalogSKU(ctx, tx, *in.SKU, "variant", variationID); err != nil {
				return err
			}
			add("sku", *in.SKU)
		}
		if in.Price != nil {
			add("price", *in.Price)
		}
		if in.WeightGrams != nil {
			add("weight_grams", *in.WeightGrams)
		}
		if in.IsActive != nil {
			add("is_active", *in.IsActive)
		}
		if len(sets) > 0 {
			add("updated_at", time.Now().UTC())
			args = append(args, variationID)
			if _, err := tx.ExecContext(ctx, "UPDATE product_variations SET "+strings.Join(sets, ", ")+" WHERE id = ?", args...); err != nil {
				return duplicateProductError(err)
			}
			if in.SKU != nil {
				if _, err := tx.ExecContext(ctx, "UPDATE catalog_skus SET sku = ? WHERE owner_type = 'variant' AND owner_id = ?", *in.SKU, variationID); err != nil {
					return duplicateProductError(err)
				}
			}
		}
		if in.SetAttrs {
			if err := replaceVariationAttributes(ctx, tx, productID, variationID, in.Attributes, in.AttributeOrder); err != nil {
				return err
			}
		}
		_ = oldSKU
		return nil
	})
	if err != nil {
		return nil, err
	}
	return ProductVariationAdminByID(ctx, d, productID, variationID)
}

func SoftDeleteProductVariationAdmin(ctx context.Context, d *db.DB, productID, variationID int64) error {
	return d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockProductAdmin(ctx, tx, productID); err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `UPDATE product_variations SET deleted_at = ?, updated_at = ?
			WHERE id = ? AND product_id = ? AND deleted_at IS NULL`, time.Now().UTC(), time.Now().UTC(), variationID, productID)
		if err != nil {
			return err
		}
		n, err := res.RowsAffected()
		if err == nil && n == 0 {
			return ErrVariationNotFound
		}
		return err
	})
}

func ProductVariationAdminByID(ctx context.Context, d *db.DB, productID, variationID int64) (map[string]any, error) {
	var id, weight int64
	var sku, price string
	var active bool
	err := d.QueryRowContext(ctx, `SELECT v.id, v.sku, v.price, v.weight_grams, v.is_active FROM product_variations v
		JOIN products p ON p.id = v.product_id
		WHERE v.id = ? AND v.product_id = ? AND v.deleted_at IS NULL AND p.deleted_at IS NULL`, variationID, productID).
		Scan(&id, &sku, &price, &weight, &active)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrVariationNotFound
	}
	if err != nil {
		return nil, err
	}
	attrs, label, err := variationAttributes(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": id, "sku": sku, "label": label, "price": price, "weight_grams": weight,
		"is_active": active, "attributes": attributesJSON(attrs)}, nil
}

func GetProductFees(ctx context.Context, d *db.DB, productID int64, variationID *int64) (map[string]float64, error) {
	table, key := "product_fees", "product_id"
	id := productID
	if variationID != nil {
		table, key, id = "product_variation_fees", "product_variation_id", *variationID
		if err := variationExists(ctx, d, *variationID); err != nil {
			return nil, err
		}
	} else {
		var hasVariations bool
		err := d.QueryRowContext(ctx, "SELECT has_variations FROM products WHERE id = ? AND deleted_at IS NULL", productID).Scan(&hasVariations)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrProductAdminNotFound
		}
		if err != nil {
			return nil, err
		}
		if hasVariations {
			return nil, ErrProductMode
		}
	}
	result := map[string]float64{"agent": 0, "sales": 0, "courier": 0}
	rows, err := d.QueryContext(ctx, "SELECT beneficiary_role, amount FROM "+table+" WHERE "+key+" = ? AND is_active = 1", id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var role string
		var amount float64
		if err := rows.Scan(&role, &amount); err != nil {
			return nil, err
		}
		result[role] = amount
	}
	return result, rows.Err()
}

func SetProductFees(ctx context.Context, d *db.DB, productID int64, variationID *int64, fees map[string]float64) (map[string]float64, error) {
	table, key := "product_fees", "product_id"
	id := productID
	if variationID != nil {
		table, key, id = "product_variation_fees", "product_variation_id", *variationID
		if err := variationExists(ctx, d, *variationID); err != nil {
			return nil, err
		}
	} else {
		var hasVariations bool
		err := d.QueryRowContext(ctx, "SELECT has_variations FROM products WHERE id = ? AND deleted_at IS NULL", productID).Scan(&hasVariations)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrProductAdminNotFound
		}
		if err != nil {
			return nil, err
		}
		if hasVariations {
			return nil, ErrProductMode
		}
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		now := time.Now().UTC()
		for _, role := range []string{"agent", "sales", "courier"} {
			if _, err := tx.ExecContext(ctx, "INSERT INTO "+table+" ("+key+", beneficiary_role, amount, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), is_active = 1, updated_at = VALUES(updated_at)",
				id, role, fees[role], now, now); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return GetProductFees(ctx, d, productID, variationID)
}

func CreateProductImageAdmin(ctx context.Context, d *db.DB, productID int64, variationID *int64, path string, primary bool, sortOrder int64) (map[string]any, error) {
	var id int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockProductAdmin(ctx, tx, productID); err != nil {
			return err
		}
		if err := assertImageVariation(ctx, tx, productID, variationID); err != nil {
			return err
		}
		if primary {
			if err := setOtherImagesNotPrimary(ctx, tx, productID, variationID, 0); err != nil {
				return err
			}
		}
		now := time.Now().UTC()
		res, err := tx.ExecContext(ctx, `INSERT INTO product_images
			(product_id, product_variation_id, path, is_primary, sort_order, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?)`, productID, variationID, path, primary, sortOrder, now, now)
		if err != nil {
			return err
		}
		id, err = res.LastInsertId()
		return err
	})
	if err != nil {
		return nil, err
	}
	return productImageAdminByID(ctx, d, productID, id)
}

func SetProductImagePrimaryAdmin(ctx context.Context, d *db.DB, productID, imageID int64, primary bool) (map[string]any, error) {
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockProductAdmin(ctx, tx, productID); err != nil {
			return err
		}
		var variationID sql.NullInt64
		if err := tx.QueryRowContext(ctx, "SELECT product_variation_id FROM product_images WHERE id = ? AND product_id = ? FOR UPDATE", imageID, productID).Scan(&variationID); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrProductImageNotFound
			}
			return err
		}
		scope := db.NullInt64(variationID)
		if primary {
			if err := setOtherImagesNotPrimary(ctx, tx, productID, scope, imageID); err != nil {
				return err
			}
		}
		_, err := tx.ExecContext(ctx, "UPDATE product_images SET is_primary = ?, updated_at = ? WHERE id = ?", primary, time.Now().UTC(), imageID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return productImageAdminByID(ctx, d, productID, imageID)
}

func DeleteProductImageAdmin(ctx context.Context, d *db.DB, productID, imageID int64) (string, error) {
	var path string
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockProductAdmin(ctx, tx, productID); err != nil {
			return err
		}
		var variation sql.NullInt64
		var primary bool
		if err := tx.QueryRowContext(ctx, "SELECT path, product_variation_id, is_primary FROM product_images WHERE id = ? AND product_id = ? FOR UPDATE", imageID, productID).Scan(&path, &variation, &primary); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrProductImageNotFound
			}
			return err
		}
		if _, err := tx.ExecContext(ctx, "DELETE FROM product_images WHERE id = ?", imageID); err != nil {
			return err
		}
		if primary {
			var next int64
			q := `SELECT id FROM product_images WHERE product_id = ? AND product_variation_id IS NULL ORDER BY sort_order, id LIMIT 1 FOR UPDATE`
			args := []any{productID}
			if variation.Valid {
				q = `SELECT id FROM product_images WHERE product_id = ? AND product_variation_id = ? ORDER BY sort_order, id LIMIT 1 FOR UPDATE`
				args = append(args, variation.Int64)
			}
			err := tx.QueryRowContext(ctx, q, args...).Scan(&next)
			if err == nil {
				_, err = tx.ExecContext(ctx, "UPDATE product_images SET is_primary = 1, updated_at = ? WHERE id = ?", time.Now().UTC(), next)
			} else if !errors.Is(err, sql.ErrNoRows) {
				return err
			}
			if err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return "", err
	}
	var remains int
	if err := d.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM product_images WHERE path = ?)", path).Scan(&remains); err != nil {
		return "", err
	}
	if remains == 0 && filepath.IsLocal(filepath.FromSlash(path)) {
		_ = os.Remove(filepath.Join(mediaPublicRoot(), filepath.FromSlash(path)))
	}
	return path, nil
}

func ReorderProductImagesAdmin(ctx context.Context, d *db.DB, productID int64, variationID *int64, ids []int64) ([]map[string]any, error) {
	if len(ids) == 0 || len(ids) > 100 {
		return nil, ErrProductImageOrder
	}
	seen := map[int64]bool{}
	for _, id := range ids {
		if seen[id] {
			return nil, ErrProductImageOrder
		}
		seen[id] = true
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockProductAdmin(ctx, tx, productID); err != nil {
			return err
		}
		if err := assertImageVariation(ctx, tx, productID, variationID); err != nil {
			return err
		}
		rows, err := queryGalleryIDs(ctx, tx, productID, variationID)
		if err != nil {
			return err
		}
		if !sameIDs(rows, ids) {
			return ErrProductImageOrder
		}
		for order, id := range ids {
			if _, err := tx.ExecContext(ctx, "UPDATE product_images SET sort_order = ?, updated_at = ? WHERE id = ?", order, time.Now().UTC(), id); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	out := make([]map[string]any, 0, len(ids))
	for _, id := range ids {
		image, err := productImageAdminByID(ctx, d, productID, id)
		if err != nil {
			return nil, err
		}
		out = append(out, image)
	}
	return out, nil
}

func productImageAdminByID(ctx context.Context, d *db.DB, productID, imageID int64) (map[string]any, error) {
	var id, pid, order int64
	var variation sql.NullInt64
	var path string
	var primary bool
	err := d.QueryRowContext(ctx, `SELECT id, product_id, product_variation_id, path, is_primary, sort_order
		FROM product_images WHERE id = ? AND product_id = ?`, imageID, productID).
		Scan(&id, &pid, &variation, &path, &primary, &order)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrProductImageNotFound
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": id, "product_id": pid, "product_variation_id": db.NullInt64(variation),
		"url": asset.URL(ctx, path), "is_primary": primary, "sort_order": order}, nil
}

func lockProductAdmin(ctx context.Context, tx *sql.Tx, id int64) error {
	var found int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE", id).Scan(&found)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrProductAdminNotFound
	}
	return err
}

func assertImageVariation(ctx context.Context, tx *sql.Tx, productID int64, variationID *int64) error {
	if variationID == nil {
		return nil
	}
	var found int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM product_variations WHERE id = ? AND product_id = ? AND deleted_at IS NULL", *variationID, productID).Scan(&found)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrProductImageVariation
	}
	return err
}

func setOtherImagesNotPrimary(ctx context.Context, tx *sql.Tx, productID int64, variationID *int64, exceptID int64) error {
	q := "UPDATE product_images SET is_primary = 0, updated_at = ? WHERE product_id = ? AND product_variation_id IS NULL AND id <> ?"
	args := []any{time.Now().UTC(), productID, exceptID}
	if variationID != nil {
		q = "UPDATE product_images SET is_primary = 0, updated_at = ? WHERE product_id = ? AND product_variation_id = ? AND id <> ?"
		args = []any{time.Now().UTC(), productID, *variationID, exceptID}
	}
	_, err := tx.ExecContext(ctx, q, args...)
	return err
}

func queryGalleryIDs(ctx context.Context, tx *sql.Tx, productID int64, variationID *int64) ([]int64, error) {
	q := "SELECT id FROM product_images WHERE product_id = ? AND product_variation_id IS NULL ORDER BY id FOR UPDATE"
	args := []any{productID}
	if variationID != nil {
		q = "SELECT id FROM product_images WHERE product_id = ? AND product_variation_id = ? ORDER BY id FOR UPDATE"
		args = append(args, *variationID)
	}
	rows, err := tx.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		out = append(out, id)
	}
	return out, rows.Err()
}

func sameIDs(a, b []int64) bool {
	if len(a) != len(b) {
		return false
	}
	set := make(map[int64]bool, len(a))
	for _, id := range a {
		if set[id] {
			return false
		}
		set[id] = true
	}
	for _, id := range b {
		if !set[id] {
			return false
		}
		delete(set, id)
	}
	return len(set) == 0
}

func mediaPublicRoot() string { return media.Root }

func replaceVariationAttributes(ctx context.Context, tx *sql.Tx, productID, variationID int64, attrs map[string]string, order []string) error {
	normalizedNames := map[string]bool{}
	for name, value := range attrs {
		name, value = strings.TrimSpace(name), strings.TrimSpace(value)
		if name == "" || value == "" || len([]rune(name)) > 100 || len([]rune(value)) > 100 {
			return ErrProductMode
		}
		key := strings.ToLower(name)
		if normalizedNames[key] {
			return ErrProductMode
		}
		normalizedNames[key] = true
	}
	if len(attrs) == 0 {
		return ErrProductMode
	}
	names := make([]string, 0, len(attrs))
	seenNames := map[string]bool{}
	for _, name := range order {
		if _, ok := attrs[name]; ok && !seenNames[name] {
			names = append(names, name)
			seenNames[name] = true
		}
	}
	for name := range attrs {
		if !seenNames[name] {
			names = append(names, name)
		}
	}
	sort.SliceStable(names, func(i, j int) bool {
		leftIndex, leftOrdered := indexOf(order, names[i])
		rightIndex, rightOrdered := indexOf(order, names[j])
		if leftOrdered && rightOrdered {
			return leftIndex < rightIndex
		}
		if leftOrdered != rightOrdered {
			return leftOrdered
		}
		return names[i] < names[j]
	})
	type pair struct{ attributeID, optionID int64 }
	pairs := make([]pair, 0, len(attrs))
	for _, rawName := range names {
		name, value := strings.TrimSpace(rawName), strings.TrimSpace(attrs[rawName])
		now := time.Now().UTC()
		var aid, oid int64
		err := tx.QueryRowContext(ctx, "SELECT id FROM product_variation_attributes WHERE product_id = ? AND name = ? ORDER BY id LIMIT 1", productID, name).Scan(&aid)
		if errors.Is(err, sql.ErrNoRows) {
			res, e := tx.ExecContext(ctx, "INSERT INTO product_variation_attributes (product_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)", productID, name, now, now)
			if e != nil {
				return e
			}
			aid, e = res.LastInsertId()
			if e != nil {
				return e
			}
		} else if err != nil {
			return err
		}
		err = tx.QueryRowContext(ctx, "SELECT id FROM product_variation_attribute_options WHERE product_variation_attribute_id = ? AND value = ? ORDER BY id LIMIT 1", aid, value).Scan(&oid)
		if errors.Is(err, sql.ErrNoRows) {
			res, e := tx.ExecContext(ctx, "INSERT INTO product_variation_attribute_options (product_variation_attribute_id, value, created_at, updated_at) VALUES (?, ?, ?, ?)", aid, value, now, now)
			if e != nil {
				return e
			}
			oid, e = res.LastInsertId()
			if e != nil {
				return e
			}
		} else if err != nil {
			return err
		}
		pairs = append(pairs, pair{aid, oid})
	}
	sort.Slice(pairs, func(i, j int) bool { return pairs[i].attributeID < pairs[j].attributeID })
	if _, err := tx.ExecContext(ctx, "DELETE FROM product_variation_compositions WHERE product_variation_id = ?", variationID); err != nil {
		return err
	}
	for _, p := range pairs {
		if _, err := tx.ExecContext(ctx, `INSERT INTO product_variation_compositions
			(product_variation_id, product_variation_attribute_id, product_variation_attribute_option_id) VALUES (?, ?, ?)`,
			variationID, p.attributeID, p.optionID); err != nil {
			return err
		}
	}
	rows, err := tx.QueryContext(ctx, `SELECT c.product_variation_id, c.product_variation_attribute_id, c.product_variation_attribute_option_id
		FROM product_variation_compositions c JOIN product_variations v ON v.id = c.product_variation_id
		WHERE v.product_id = ? AND v.id <> ? AND v.deleted_at IS NULL`, productID, variationID)
	if err != nil {
		return err
	}
	siblings := map[int64]map[int64]int64{}
	for rows.Next() {
		var vid, aid, oid int64
		if err := rows.Scan(&vid, &aid, &oid); err != nil {
			rows.Close()
			return err
		}
		if siblings[vid] == nil {
			siblings[vid] = map[int64]int64{}
		}
		siblings[vid][aid] = oid
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	for _, sibling := range siblings {
		if sameIntMap(sibling, func() map[int64]int64 {
			out := make(map[int64]int64, len(pairs))
			for _, p := range pairs {
				out[p.attributeID] = p.optionID
			}
			return out
		}()) {
			return ErrVariationConflict
		}
	}
	return nil
}

func indexOf(values []string, value string) (int, bool) {
	for i, candidate := range values {
		if candidate == value {
			return i, true
		}
	}
	return 0, false
}

func loadAdminProduct(ctx context.Context, d *db.DB, id int64, includeDeleted bool) (map[string]any, error) {
	where := "p.id = ? AND p.deleted_at IS NULL"
	if includeDeleted {
		where = "p.id = ?"
	}
	var pid int64
	var name, slug, status string
	var description, shortDesc, sku, basePrice, catName, catSlug sql.NullString
	var categoryID, weight sql.NullInt64
	var hasVariations bool
	err := d.QueryRowContext(ctx, `SELECT p.id, p.name, p.slug, p.description, p.short_description, p.sku,
		p.has_variations, p.base_price, p.weight_grams, p.status, p.category_id, c.name, c.slug
		FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE `+where, id).
		Scan(&pid, &name, &slug, &description, &shortDesc, &sku, &hasVariations, &basePrice, &weight, &status, &categoryID, &catName, &catSlug)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrProductAdminNotFound
	}
	if err != nil {
		return nil, err
	}
	images, err := loadProductImages(ctx, d, pid)
	if err != nil {
		return nil, err
	}
	variations, err := loadAllAdminVariations(ctx, d, pid)
	if err != nil {
		return nil, err
	}
	out := map[string]any{
		"id": pid, "name": name, "slug": slug, "description": nullStr(description),
		"short_description": nullStr(shortDesc), "sku": nullStr(sku), "has_variations": hasVariations,
		"images": images, "variations": variations, "status": status,
	}
	if categoryID.Valid {
		out["category"] = map[string]any{"id": categoryID.Int64, "name": nullStr(catName), "slug": nullStr(catSlug)}
	} else {
		out["category"] = nil
	}
	if !hasVariations {
		out["base_price"] = nullStr(basePrice)
		out["weight_grams"] = db.NullInt64(weight)
	}
	return out, nil
}

func loadProductImages(ctx context.Context, d *db.DB, productID int64) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, product_variation_id, path, is_primary, sort_order
		FROM product_images WHERE product_id = ? ORDER BY sort_order, id`, productID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, pid, order int64
		var vid sql.NullInt64
		var path string
		var primary bool
		if err := rows.Scan(&id, &pid, &vid, &path, &primary, &order); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": id, "product_id": pid, "product_variation_id": db.NullInt64(vid),
			"url": asset.URL(ctx, path), "is_primary": primary, "sort_order": order})
	}
	return out, rows.Err()
}

func loadAllAdminVariations(ctx context.Context, d *db.DB, productID int64) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, sku, price, weight_grams, is_active FROM product_variations
		WHERE product_id = ? AND deleted_at IS NULL ORDER BY sort_order, id`, productID)
	if err != nil {
		return nil, err
	}
	type row struct {
		id, weight int64
		sku, price string
		active     bool
	}
	list := []row{}
	for rows.Next() {
		var r row
		if err := rows.Scan(&r.id, &r.sku, &r.price, &r.weight, &r.active); err != nil {
			rows.Close()
			return nil, err
		}
		list = append(list, r)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	out := make([]map[string]any, 0, len(list))
	for _, r := range list {
		attrs, label, err := variationAttributes(ctx, d, r.id)
		if err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": r.id, "sku": r.sku, "label": label, "price": r.price,
			"weight_grams": r.weight, "is_active": r.active, "attributes": attributesJSON(attrs)})
	}
	return out, nil
}

// attributesJSON shapes the attributes projection the PHP way: a non-empty map of name=>value, an
// EMPTY LIST when the variation has no compositions (mapWithKeys returns []).
func attributesJSON(attrs map[string]string) any {
	if len(attrs) == 0 {
		return []any{}
	}
	return attrs
}

func variationAttributes(ctx context.Context, q interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
}, variationID int64) (map[string]string, string, error) {
	rows, err := q.QueryContext(ctx, `SELECT a.name, o.value FROM product_variation_compositions c
		JOIN product_variation_attributes a ON a.id = c.product_variation_attribute_id
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id = ? ORDER BY a.sort_order, o.sort_order`, variationID)
	if err != nil {
		return nil, "", err
	}
	defer rows.Close()
	attrs := map[string]string{}
	labels := []string{}
	for rows.Next() {
		var name, value string
		if err := rows.Scan(&name, &value); err != nil {
			return nil, "", err
		}
		attrs[name] = value
		labels = append(labels, value)
	}
	return attrs, strings.Join(labels, " / "), rows.Err()
}

func categoryAndDescendantIDsByID(ctx context.Context, d *db.DB, id int64) ([]int64, error) {
	var found int64
	if err := d.QueryRowContext(ctx, "SELECT id FROM product_categories WHERE id = ?", id).Scan(&found); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrProductCategory
		}
		return nil, err
	}
	rows, err := d.QueryContext(ctx, "SELECT id, parent_id FROM product_categories WHERE parent_id IS NOT NULL")
	if err != nil {
		return nil, err
	}
	children := map[int64][]int64{}
	for rows.Next() {
		var child, parent int64
		if err := rows.Scan(&child, &parent); err != nil {
			rows.Close()
			return nil, err
		}
		children[parent] = append(children[parent], child)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	out, seen, queue := []int64{id}, map[int64]bool{id: true}, []int64{id}
	for len(queue) > 0 {
		current := queue[0]
		queue = queue[1:]
		for _, child := range children[current] {
			if !seen[child] {
				seen[child] = true
				out = append(out, child)
				queue = append(queue, child)
			}
		}
	}
	return out, nil
}

func assertProductCategory(ctx context.Context, tx *sql.Tx, id int64) error {
	var found int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM product_categories WHERE id = ?", id).Scan(&found)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrProductCategory
	}
	return err
}

func assertCatalogSKU(ctx context.Context, tx *sql.Tx, sku, ownerType string, ownerID int64) error {
	if len([]rune(sku)) > 60 || strings.TrimSpace(sku) == "" {
		return ErrProductSKU
	}
	var owner string
	var id int64
	err := tx.QueryRowContext(ctx, "SELECT owner_type, owner_id FROM catalog_skus WHERE sku = ? FOR UPDATE", sku).Scan(&owner, &id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if owner == ownerType && id == ownerID {
		return nil
	}
	return ErrProductSKU
}

func assertProductSlug(ctx context.Context, tx *sql.Tx, slug string, exceptID int64) error {
	var found int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1", slug, exceptID).Scan(&found)
	if err == nil {
		return ErrProductSlug
	}
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	return err
}

func uniqueProductSlug(ctx context.Context, d *db.DB, name string) (string, error) {
	base := productSlug(name)
	for range 5 {
		random := make([]byte, 7)
		if _, err := rand.Read(random); err != nil {
			return "", err
		}
		slug := base + "-" + hex.EncodeToString(random)[:13]
		var exists int
		if err := d.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM products WHERE slug = ?)", slug).Scan(&exists); err != nil {
			return "", err
		}
		if exists == 0 {
			return slug, nil
		}
	}
	return "", fmt.Errorf("generate unique product slug")
}

func productSlug(name string) string {
	var out strings.Builder
	dash := false
	for _, r := range strings.ToLower(strings.TrimSpace(name)) {
		if unicode.IsLetter(r) || unicode.IsDigit(r) {
			out.WriteRune(r)
			dash = false
		} else if out.Len() > 0 && !dash {
			out.WriteByte('-')
			dash = true
		}
	}
	return strings.Trim(out.String(), "-")
}

func productStatus(status *string) string {
	if status == nil {
		return "draft"
	}
	return *status
}

func duplicateProductError(err error) error {
	if err == nil {
		return nil
	}
	if db.IsDuplicateKey(err) {
		if strings.Contains(strings.ToLower(err.Error()), "slug") {
			return ErrProductSlug
		}
		return ErrProductSKU
	}
	return err
}

func idMarks(ids []int64) (string, []any) {
	marks, args := make([]string, len(ids)), make([]any, len(ids))
	for i, id := range ids {
		marks[i], args[i] = "?", id
	}
	return strings.Join(marks, ","), args
}

func escapeLike(value string) string {
	r := strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`)
	return r.Replace(value)
}

func sameIntMap(a, b map[int64]int64) bool {
	if len(a) != len(b) {
		return false
	}
	for k, v := range a {
		if b[k] != v {
			return false
		}
	}
	return true
}

// variationExists is the route-model binding check for {variation}: the row must exist (404 otherwise);
// the product path pairing is never consulted (FeeController binds {variation} by ID alone).
func variationExists(ctx context.Context, d *db.DB, variationID int64) error {
	var found int
	err := d.QueryRowContext(ctx, "SELECT COUNT(*) FROM product_variations WHERE id = ? AND deleted_at IS NULL", variationID).Scan(&found)
	if err != nil {
		return err
	}
	if found == 0 {
		return ErrProductAdminNotFound
	}
	return nil
}
