// Package catalog is the public product/category read model.
//
// Scope note (IMP-010 checkpoint C): this package implements the ANONYMOUS public catalog exactly as
// ProductController::index/show does for a visitor without a session. The agent/Sub availability attachment
// (AttachesAgentAvailability → WarehouseStockService::sellableForTargets) belongs to the inventory domain and
// is NOT ported here; the parity matrix marks that specific behaviour as outstanding so nobody mistakes the
// absence of `stock`/`agent_available_quantity` for parity.
package catalog

import (
	"context"
	"database/sql"
	"errors"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrProductNotFound is returned when no active product matches the slug.
var ErrProductNotFound = errors.New("product not found")

// Query is the public listing filter set (ProductController::index).
type Query struct {
	Search    string
	Category  string
	MinPrice  *float64
	MaxPrice  *float64
	ExcludeID *int64
	Sort      string // newest|price_asc|price_desc
	Random    bool
	Page      int
	PerPage   int
}

// List is a page of products plus the pagination totals.
type List struct {
	Items    []map[string]any
	Total    int
	Page     int
	LastPage int
	PerPage  int
}

// ListProducts returns one page of active products.
//
// The starting price is COALESCE(base_price, cheapest active variation) — the same expression the Laravel
// query uses, kept server-side so filtering/sorting never agrees with a different number than the card shows.
func ListProducts(ctx context.Context, d *db.DB, q Query) (*List, error) {
	if q.Page < 1 {
		q.Page = 1
	}
	if q.PerPage < 1 || q.PerPage > 100 {
		q.PerPage = 15
	}

	where := []string{"p.status = 'active'", "p.deleted_at IS NULL"}
	args := []any{}

	if q.Category != "" {
		ids, err := categoryAndDescendantIDs(ctx, d, q.Category)
		if err != nil {
			return nil, err
		}
		if len(ids) == 0 {
			// Unknown category slug: the Laravel query falls back to `whereIn('category_id', [0])`.
			return &List{Items: []map[string]any{}, Total: 0, Page: q.Page, LastPage: 1, PerPage: q.PerPage}, nil
		}
		where = append(where, "p.category_id IN ("+placeholders(len(ids))+")")
		for _, id := range ids {
			args = append(args, id)
		}
	}
	if q.Search != "" {
		term := "%" + q.Search + "%"
		where = append(where, `(p.name LIKE ? OR p.sku LIKE ? OR EXISTS(
			SELECT 1 FROM product_variations v WHERE v.product_id = p.id AND v.deleted_at IS NULL AND v.sku LIKE ?))`)
		args = append(args, term, term, term)
	}
	const startingPrice = "COALESCE(p.base_price, cv.min_price)"
	if q.MinPrice != nil {
		where = append(where, startingPrice+" >= ?")
		args = append(args, *q.MinPrice)
	}
	if q.MaxPrice != nil {
		where = append(where, startingPrice+" <= ?")
		args = append(args, *q.MaxPrice)
	}
	if q.ExcludeID != nil {
		where = append(where, "p.id <> ?")
		args = append(args, *q.ExcludeID)
	}

	whereSQL := strings.Join(where, " AND ")
	fromSQL := `FROM products p
		LEFT JOIN product_categories c ON c.id = p.category_id
		LEFT JOIN (SELECT product_id, MIN(price) AS min_price FROM product_variations
			WHERE is_active = 1 AND deleted_at IS NULL GROUP BY product_id) cv ON cv.product_id = p.id
		WHERE ` + whereSQL

	var total int
	if err := d.QueryRowContext(ctx, "SELECT COUNT(*) "+fromSQL, args...).Scan(&total); err != nil {
		return nil, err
	}

	// Ordering mirrors the Laravel query exactly: `latest()` for the default (created_at DESC), and the bare
	// starting-price expression for the price sorts. No secondary tiebreak is added — an extra `id` term would
	// silently change the order of rows that share a timestamp, which is a contract difference the frontend can
	// observe (pagination position).
	orderSQL := "p.created_at DESC"
	switch {
	case q.Random:
		orderSQL = "RAND()"
	case q.Sort == "price_asc":
		orderSQL = startingPrice + " ASC"
	case q.Sort == "price_desc":
		orderSQL = startingPrice + " DESC"
	}

	offset := (q.Page - 1) * q.PerPage
	rows, err := d.QueryContext(ctx, `SELECT p.id, p.name, p.slug, p.description, p.short_description,
			p.has_variations, p.base_price, p.weight_grams, p.status, p.sku,
			c.id, c.name, c.slug
		`+fromSQL+` ORDER BY `+orderSQL+` LIMIT ? OFFSET ?`, append(append([]any{}, args...), q.PerPage, offset)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type row struct {
		id            int64
		name, slug    string
		description   sql.NullString
		shortDesc     sql.NullString
		hasVariations bool
		basePrice     sql.NullString
		weightGrams   sql.NullInt64
		status        string
		sku           sql.NullString
		catID         sql.NullInt64
		catName       sql.NullString
		catSlug       sql.NullString
	}
	var items []row
	for rows.Next() {
		var r row
		var hasVar int
		if err := rows.Scan(&r.id, &r.name, &r.slug, &r.description, &r.shortDesc, &hasVar, &r.basePrice,
			&r.weightGrams, &r.status, &r.sku, &r.catID, &r.catName, &r.catSlug); err != nil {
			return nil, err
		}
		r.hasVariations = hasVar == 1
		items = append(items, r)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	ids := make([]int64, 0, len(items))
	for _, r := range items {
		ids = append(ids, r.id)
	}
	images, err := loadImages(ctx, d, ids)
	if err != nil {
		return nil, err
	}
	variations, err := loadVariations(ctx, d, ids)
	if err != nil {
		return nil, err
	}

	out := make([]map[string]any, 0, len(items))
	for _, r := range items {
		out = append(out, productJSON(ctx, r.id, r.name, r.slug, r.description, r.shortDesc, r.hasVariations,
			r.basePrice, r.weightGrams, r.status, r.sku, r.catID, r.catName, r.catSlug,
			images[r.id], variations[r.id]))
	}

	lastPage := (total + q.PerPage - 1) / q.PerPage
	if lastPage < 1 {
		lastPage = 1
	}
	return &List{Items: out, Total: total, Page: q.Page, LastPage: lastPage, PerPage: q.PerPage}, nil
}

// ProductBySlug renders one active product (ProductController::show).
func ProductBySlug(ctx context.Context, d *db.DB, slug string) (map[string]any, error) {
	var (
		id          int64
		name, s     string
		description sql.NullString
		shortDesc   sql.NullString
		hasVar      int
		basePrice   sql.NullString
		weightGrams sql.NullInt64
		status      string
		sku         sql.NullString
		catID       sql.NullInt64
		catName     sql.NullString
		catSlug     sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT p.id, p.name, p.slug, p.description, p.short_description,
			p.has_variations, p.base_price, p.weight_grams, p.status, p.sku, c.id, c.name, c.slug
		FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
		WHERE p.slug = ? AND p.status = 'active' AND p.deleted_at IS NULL
		LIMIT 1`, slug).Scan(&id, &name, &s, &description, &shortDesc, &hasVar, &basePrice,
		&weightGrams, &status, &sku, &catID, &catName, &catSlug)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrProductNotFound
	}
	if err != nil {
		return nil, err
	}
	images, err := loadImages(ctx, d, []int64{id})
	if err != nil {
		return nil, err
	}
	variations, err := loadVariations(ctx, d, []int64{id})
	if err != nil {
		return nil, err
	}
	return productJSON(ctx, id, name, s, description, shortDesc, hasVar == 1, basePrice, weightGrams, status, sku,
		catID, catName, catSlug, images[id], variations[id]), nil
}

// Categories returns every active category (ProductCategoryController::index).
func Categories(ctx context.Context, d *db.DB) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, parent_id, name, slug, is_active, sort_order
		FROM product_categories WHERE is_active = 1
		ORDER BY sort_order ASC, name ASC, id ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			id, sortOrder int64
			parentID      sql.NullInt64
			name, slug    string
			isActive      int
		)
		if err := rows.Scan(&id, &parentID, &name, &slug, &isActive, &sortOrder); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id":         id,
			"parent_id":  db.NullInt64(parentID),
			"name":       name,
			"slug":       slug,
			"is_active":  isActive == 1,
			"sort_order": sortOrder,
		})
	}
	return out, rows.Err()
}

// categoryAndDescendantIDs resolves a category slug to itself plus every descendant (any depth), matching
// CategoryHierarchyService::selfAndDescendantIds.
func categoryAndDescendantIDs(ctx context.Context, d *db.DB, slug string) ([]int64, error) {
	var rootID int64
	err := d.QueryRowContext(ctx, `SELECT id FROM product_categories WHERE slug = ? LIMIT 1`, slug).Scan(&rootID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	// Materialise the parent→child adjacency and walk it breadth-first, exactly like the PHP service, so the
	// set is identical (including the deterministic order used by the IN clause).
	rows, err := d.QueryContext(ctx, `SELECT id, parent_id FROM product_categories WHERE parent_id IS NOT NULL`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	children := map[int64][]int64{}
	for rows.Next() {
		var id, parent int64
		if err := rows.Scan(&id, &parent); err != nil {
			return nil, err
		}
		children[parent] = append(children[parent], id)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	seen := map[int64]bool{rootID: true}
	queue := []int64{rootID}
	out := []int64{rootID}
	for len(queue) > 0 {
		current := queue[0]
		queue = queue[1:]
		for _, child := range children[current] {
			if seen[child] {
				continue
			}
			seen[child] = true
			out = append(out, child)
			queue = append(queue, child)
		}
	}
	return out, nil
}

func placeholders(n int) string {
	if n <= 0 {
		return ""
	}
	return strings.TrimSuffix(strings.Repeat("?,", n), ",")
}

type imageRow struct {
	id, productID int64
	variationID   sql.NullInt64
	path          string
	isPrimary     bool
	sortOrder     int64
}

func loadImages(ctx context.Context, d *db.DB, productIDs []int64) (map[int64][]map[string]any, error) {
	out := map[int64][]map[string]any{}
	if len(productIDs) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(productIDs))
	for _, id := range productIDs {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, product_variation_id, path, is_primary, sort_order
		FROM product_images
		WHERE product_id IN (`+placeholders(len(productIDs))+`) AND is_primary = 1 AND product_variation_id IS NULL
		ORDER BY sort_order ASC, id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	for rows.Next() {
		var r imageRow
		var primary int
		if err := rows.Scan(&r.id, &r.productID, &r.variationID, &r.path, &primary, &r.sortOrder); err != nil {
			return nil, err
		}
		out[r.productID] = append(out[r.productID], map[string]any{
			"id":                   r.id,
			"product_id":           r.productID,
			"product_variation_id": db.NullInt64(r.variationID),
			"url":                  asset.URL(ctx, r.path),
			"is_primary":           primary == 1,
			"sort_order":           r.sortOrder,
		})
	}
	return out, rows.Err()
}

func loadVariations(ctx context.Context, d *db.DB, productIDs []int64) (map[int64][]map[string]any, error) {
	out := map[int64][]map[string]any{}
	if len(productIDs) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(productIDs))
	for _, id := range productIDs {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, sku, price, weight_grams, is_active
		FROM product_variations
		WHERE product_id IN (`+placeholders(len(productIDs))+`) AND is_active = 1 AND deleted_at IS NULL
		ORDER BY sort_order ASC, id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type vrow struct {
		id, productID, weight, isActive int64
		sku, price                      string
	}
	var list []vrow
	for rows.Next() {
		var r vrow
		if err := rows.Scan(&r.id, &r.productID, &r.sku, &r.price, &r.weight, &r.isActive); err != nil {
			return nil, err
		}
		list = append(list, r)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return out, nil
	}

	// One batched query for every composition label, so a page of products costs O(1) extra queries.
	ids := make([]int64, 0, len(list))
	byID := map[int64]vrow{}
	for _, r := range list {
		ids = append(ids, r.id)
		byID[r.id] = r
	}
	vargs := make([]any, 0, len(ids))
	for _, id := range ids {
		vargs = append(vargs, id)
	}
	attrRows, err := d.QueryContext(ctx, `SELECT c.product_variation_id, a.name, o.value
		FROM product_variation_compositions c
		JOIN product_variation_attributes a ON a.id = c.product_variation_attribute_id
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id IN (`+placeholders(len(ids))+`)
		ORDER BY c.product_variation_id ASC, a.sort_order ASC, o.sort_order ASC`, vargs...)
	if err != nil {
		return nil, err
	}
	defer attrRows.Close()
	attrs := map[int64][]string{}
	for attrRows.Next() {
		var vid int64
		var aName, oValue string
		if err := attrRows.Scan(&vid, &aName, &oValue); err != nil {
			return nil, err
		}
		total := len(attrs[vid]) + len(attrs[vid]) // no-op guard for readability
		_ = total
		attrs[vid] = append(attrs[vid], aName+"\x00"+oValue)
	}
	if err := attrRows.Err(); err != nil {
		return nil, err
	}

	for _, r := range list {
		pairs := attrs[r.id]
		labels := make([]string, 0, len(pairs))
		for _, pair := range pairs {
			parts := strings.SplitN(pair, "\x00", 2)
			if len(parts) == 2 {
				labels = append(labels, parts[1])
			}
		}
		// NOTE: no `attributes` key. The reference endpoint loads `variations` without `compositions`, so
		// ProductVariationResource omits that key entirely (it is a `whenLoaded` field). `label` is still
		// produced because label() queries the compositions itself.
		out[r.productID] = append(out[r.productID], map[string]any{
			"id":           r.id,
			"sku":          r.sku,
			"label":        strings.Join(labels, " / "),
			"price":        r.price,
			"weight_grams": r.weight,
			"is_active":    r.isActive == 1,
		})
	}
	return out, nil
}

// productJSON assembles ProductResource's shape. Keys that Laravel omits (`base_price`/`weight_grams` for a
// variation product) are omitted here too — a present-but-null key would change what the SPA renders.
func productJSON(ctx context.Context, id int64, name, slug string, description, shortDesc sql.NullString, hasVariations bool,
	basePrice sql.NullString, weightGrams sql.NullInt64, status string, sku sql.NullString,
	catID sql.NullInt64, catName, catSlug sql.NullString, images, variations []map[string]any) map[string]any {
	out := map[string]any{
		"id":                id,
		"name":              name,
		"slug":              slug,
		"description":       nullStr(description),
		"short_description": nullStr(shortDesc),
		"has_variations":    hasVariations,
		"sku":               nullStr(sku),
		"images":            nonNil(images),
		"variations":        nonNil(variations),
		"status":            status,
	}
	if catID.Valid {
		out["category"] = map[string]any{
			"id":   catID.Int64,
			"name": nullStr(catName),
			"slug": nullStr(catSlug),
		}
	}
	if !hasVariations {
		out["base_price"] = nullStr(basePrice)
		out["weight_grams"] = db.NullInt64(weightGrams)
	}
	return out
}

func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func nonNil(v []map[string]any) []map[string]any {
	if v == nil {
		return []map[string]any{}
	}
	return v
}
