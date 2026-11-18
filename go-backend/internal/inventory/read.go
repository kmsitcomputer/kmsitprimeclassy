package inventory

import (
	"context"
	"database/sql"
	"errors"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrNotFound is returned when a scoped row does not exist (or belongs to another agent — the two are
// deliberately indistinguishable, exactly like the Laravel `abort(404)` after the agent check).
var ErrNotFound = errors.New("not found")

// Page is the pagination block the stock endpoints return. Note it carries current_page/last_page/total
// only — `per_page` is added by the endpoints that actually include it (see StockCards).
type Page struct {
	CurrentPage int `json:"current_page"`
	LastPage    int `json:"last_page"`
	Total       int `json:"total"`
}

// PagedRows is a page of rows plus its pagination block.
type PagedRows struct {
	Rows []map[string]any
	Page Page
}

// ---- lists required by these endpoints --------------------------------------------------------------

// ListStockProducts is StockController::products: simple (non-variation) products held by the agent, with the
// canonical sellable projection.
//
// The pagination `total` counts TARGETS (legacy ∪ warehouse), not rendered rows, because the controller
// paginates the target union before filtering by has_variations=false. Reproducing that keeps the page
// count identical to Laravel's even when a warehouse row exists for a variation product.
func ListStockProducts(ctx context.Context, d *db.DB, agentID int64, page, perPage int) (*PagedRows, error) {
	targets, total, lastPage, err := targetPage(ctx, d, agentID, "product_stocks", "product_id", page, perPage)
	if err != nil {
		return nil, err
	}
	rows := make([]map[string]any, 0, len(targets))
	if len(targets) > 0 {
		args := make([]any, 0, len(targets))
		for _, id := range targets {
			args = append(args, id)
		}
		res, err := d.QueryContext(ctx, `SELECT id, name, sku FROM products
			WHERE id IN (`+placeholders(len(targets))+`) AND has_variations = 0 AND deleted_at IS NULL
			ORDER BY id`, args...)
		if err != nil {
			return nil, err
		}
		defer res.Close()
		names := map[int64][2]any{}
		for res.Next() {
			var id int64
			var name string
			var sku sql.NullString
			if err := res.Scan(&id, &name, &sku); err != nil {
				return nil, err
			}
			names[id] = [2]any{name, nullStr(sku)}
		}
		if err := res.Err(); err != nil {
			return nil, err
		}
		for _, id := range targets {
			meta, ok := names[id]
			if !ok {
				continue // a target whose product is missing/variation-bearing is filtered out, as in Laravel
			}
			s, err := ForProduct(ctx, d, agentID, id)
			if err != nil {
				return nil, err
			}
			rows = append(rows, map[string]any{
				"id":                 id,
				"agent_id":           agentID,
				"product_id":         id,
				"product_name":       meta[0],
				"sku":                meta[1],
				"quantity_on_hand":   s.SellableBase,
				"quantity_reserved":  s.Reserved,
				"quantity_available": s.Available,
				"source":             s.Source,
				"updated_at":         nil,
			})
		}
	}
	return &PagedRows{Rows: rows, Page: Page{CurrentPage: page, LastPage: lastPage, Total: total}}, nil
}

// ListStockVariations is StockController::variations.
func ListStockVariations(ctx context.Context, d *db.DB, agentID int64, page, perPage int) (*PagedRows, error) {
	targets, total, lastPage, err := targetPage(ctx, d, agentID, "product_variation_stocks", "product_variation_id", page, perPage)
	if err != nil {
		return nil, err
	}
	rows := make([]map[string]any, 0, len(targets))
	if len(targets) > 0 {
		args := make([]any, 0, len(targets))
		for _, id := range targets {
			args = append(args, id)
		}
		res, err := d.QueryContext(ctx, `SELECT id, sku FROM product_variations
			WHERE id IN (`+placeholders(len(targets))+`) AND deleted_at IS NULL ORDER BY id`, args...)
		if err != nil {
			return nil, err
		}
		defer res.Close()
		skus := map[int64]string{}
		for res.Next() {
			var id int64
			var sku string
			if err := res.Scan(&id, &sku); err != nil {
				return nil, err
			}
			skus[id] = sku
		}
		if err := res.Err(); err != nil {
			return nil, err
		}
		labels, err := variationLabels(ctx, d, targets)
		if err != nil {
			return nil, err
		}
		for _, id := range targets {
			sku, ok := skus[id]
			if !ok {
				continue
			}
			s, err := ForVariation(ctx, d, agentID, id)
			if err != nil {
				return nil, err
			}
			rows = append(rows, map[string]any{
				"id":                   id,
				"agent_id":             agentID,
				"product_variation_id": id,
				"sku":                  sku,
				"variation_label":      labels[id],
				"quantity_on_hand":     s.SellableBase,
				"quantity_reserved":    s.Reserved,
				"quantity_available":   s.Available,
				"source":               s.Source,
				"updated_at":           nil,
			})
		}
	}
	return &PagedRows{Rows: rows, Page: Page{CurrentPage: page, LastPage: lastPage, Total: total}}, nil
}

// targetPage is StockController::targetPage: one paginated page of target ids the agent holds, legacy
// commitment rows UNION warehouse buckets (never another agent's rows).
func targetPage(ctx context.Context, d *db.DB, agentID int64, legacyTable, column string, page, perPage int) ([]int64, int, int, error) {
	union := `SELECT ` + column + ` AS target_id FROM ` + legacyTable + ` WHERE agent_id = ?
		UNION
		SELECT ` + column + ` AS target_id FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type IN ('transit','factory_plan','shipping') AND ` + column + ` IS NOT NULL`

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM (`+union+`) t`, agentID, agentID).Scan(&total); err != nil {
		return nil, 0, 0, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (page - 1) * perPage
	rows, err := d.QueryContext(ctx, `SELECT target_id FROM (`+union+`) t ORDER BY target_id ASC LIMIT ? OFFSET ?`,
		agentID, agentID, perPage, offset)
	if err != nil {
		return nil, 0, 0, err
	}
	defer rows.Close()
	var out []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, 0, 0, err
		}
		out = append(out, id)
	}
	return out, total, lastPage, rows.Err()
}

// ---- warehouse stock list --------------------------------------------------------------------------

// WarehouseStockFilter narrows the warehouse stock listing.
type WarehouseStockFilter struct {
	StockType string
	Search    string
	Page      int
	PerPage   int
}

// ListWarehouseStock is StockController::warehouse, including the WarehouseStockResource projection.
func ListWarehouseStock(ctx context.Context, d *db.DB, agentID int64, f WarehouseStockFilter) (*PagedRows, error) {
	where := []string{"ws.agent_id = ?"}
	args := []any{agentID}
	if f.StockType != "" {
		where = append(where, "ws.stock_type = ?")
		args = append(args, f.StockType)
	}
	if f.Search != "" {
		term := "%" + f.Search + "%"
		where = append(where, `(EXISTS(SELECT 1 FROM products p WHERE p.id = ws.product_id AND (p.name LIKE ? OR p.sku LIKE ?))
			OR EXISTS(SELECT 1 FROM product_variations v WHERE v.id = ws.product_variation_id AND v.sku LIKE ?))`)
		args = append(args, term, term, term)
	}
	whereSQL := strings.Join(where, " AND ")

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM warehouse_stocks ws WHERE `+whereSQL, args...).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := (total + f.PerPage - 1) / f.PerPage
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (f.Page - 1) * f.PerPage

	rows, err := d.QueryContext(ctx, `SELECT ws.id, ws.agent_id, ws.product_id, ws.product_variation_id, ws.stock_type,
			ws.sub_location_id, ws.quantity, ws.updated_at
		FROM warehouse_stocks ws
		WHERE `+whereSQL+`
		ORDER BY ws.id ASC LIMIT ? OFFSET ?`, append(append([]any{}, args...), f.PerPage, offset)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type wrow struct {
		id, agentID          int64
		productID, variation sql.NullInt64
		stockType            string
		subLocationID        sql.NullInt64
		quantity             int64
		updatedAt            sql.NullTime
	}
	var list []wrow
	for rows.Next() {
		var r wrow
		if err := rows.Scan(&r.id, &r.agentID, &r.productID, &r.variation, &r.stockType,
			&r.subLocationID, &r.quantity, &r.updatedAt); err != nil {
			return nil, err
		}
		list = append(list, r)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	var productIDs, variationIDs, subIDs []int64
	for _, r := range list {
		if pid, ok := nullInt(r.productID); ok {
			productIDs = append(productIDs, pid)
		}
		if vid, ok := nullInt(r.variation); ok {
			variationIDs = append(variationIDs, vid)
		}
		if sid, ok := nullInt(r.subLocationID); ok {
			subIDs = append(subIDs, sid)
		}
	}
	products, err := loadProductModels(ctx, d, productIDs, true)
	if err != nil {
		return nil, err
	}
	variations, err := loadVariationModels(ctx, d, variationIDs)
	if err != nil {
		return nil, err
	}
	subLocations, err := loadSubLocationModels(ctx, d, subIDs)
	if err != nil {
		return nil, err
	}

	out := make([]map[string]any, 0, len(list))
	for _, r := range list {
		var product any
		var productName any
		var imageURL any
		if pid, ok := nullInt(r.productID); ok {
			if p, found := products[pid]; found {
				// Shallow-copy before removing the internal helper key: several rows share one product
				// map, so mutating it in place would strip the key from every later row.
				product = publicProduct(p)
				productName = p["name"]
				imageURL = p["__primary_image_url"]
			}
		}
		var variation any
		var variationLabel any
		var sku any
		if vid, ok := nullInt(r.variation); ok {
			if v, found := variations[vid]; found {
				variation = publicVariation(v)
				variationLabel = v["__label"]
				sku = v["sku"]
			}
		}
		if sku == nil && product != nil {
			sku = productsValue(products, r.productID, "sku")
		}
		var subLocation any
		if sid, ok := nullInt(r.subLocationID); ok {
			if s, found := subLocations[sid]; found {
				subLocation = s
			}
		}
		out = append(out, map[string]any{
			"id":                   r.id,
			"agent_id":             r.agentID,
			"product_id":           nullIntPtr(r.productID),
			"product_variation_id": nullIntPtr(r.variation),
			"stock_type":           r.stockType,
			"sub_location_id":      nullIntPtr(r.subLocationID),
			"quantity":             r.quantity,
			"product":              product,
			"variation":            variation,
			"sub_location":         subLocation,
			"product_name":         productName,
			"variation_label":      variationLabel,
			"sku":                  sku,
			"product_image_url":    imageURL,
			"updated_at":           formatTime(r.updatedAt),
		})
	}
	return &PagedRows{Rows: out, Page: Page{CurrentPage: f.Page, LastPage: lastPage, Total: total}}, nil
}

// publicProduct strips the internal helper key from a product map without mutating the shared original.
func publicProduct(p map[string]any) map[string]any {
	out := make(map[string]any, len(p))
	for k, v := range p {
		if k == "__primary_image_url" {
			continue
		}
		out[k] = v
	}
	return out
}

// publicVariation strips the internal label helper key without mutating the shared original.
func publicVariation(v map[string]any) map[string]any {
	out := make(map[string]any, len(v))
	for k, val := range v {
		if k == "__label" {
			continue
		}
		out[k] = val
	}
	return out
}

func productsValue(products map[int64]map[string]any, id sql.NullInt64, key string) any {
	if pid, ok := nullInt(id); ok {
		if p, found := products[pid]; found {
			return p[key]
		}
	}
	return nil
}

// ---- sub locations ---------------------------------------------------------------------------------

// ListSubLocations is WarehouseSubLocationController::index — automatically tenant-scoped by the model's
// global BelongsToAgentScope in Laravel, so the scope is explicit here.
func ListSubLocations(ctx context.Context, d *db.DB, agentID int64) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT s.id, s.agent_id, s.owner_user_id, s.previous_owner_user_id, s.code,
			s.name, s.address, s.contact_number, s.description, s.is_active, s.created_by, s.created_at, s.updated_at,
			u.name
		FROM warehouse_sub_locations s
		LEFT JOIN users u ON u.id = s.owner_user_id
		WHERE s.agent_id = ?
		ORDER BY s.name ASC`, agentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			id, agent, createdBy int64
			owner, prevOwner     sql.NullInt64
			code, name           string
			address, contact     sql.NullString
			description          sql.NullString
			isActive             int
			createdAt, updatedAt sql.NullTime
			ownerName            sql.NullString
		)
		if err := rows.Scan(&id, &agent, &owner, &prevOwner, &code, &name, &address, &contact,
			&description, &isActive, &createdBy, &createdAt, &updatedAt, &ownerName); err != nil {
			return nil, err
		}
		var ownerObj any
		if owner.Valid {
			ownerObj = map[string]any{"id": owner.Int64, "name": nullStr(ownerName)}
		}
		out = append(out, map[string]any{
			"id":                     id,
			"agent_id":               agent,
			"owner_user_id":          nullIntPtr(owner),
			"previous_owner_user_id": nullIntPtr(prevOwner),
			"code":                   code,
			"name":                   name,
			"address":                nullStr(address),
			"contact_number":         nullStr(contact),
			"description":            nullStr(description),
			"is_active":              isActive == 1,
			"created_by":             createdBy,
			"created_at":             formatTime(createdAt),
			"updated_at":             formatTime(updatedAt),
			"owner":                  ownerObj,
		})
	}
	return out, rows.Err()
}

// SubLocationByID is WarehouseSubLocationController::show — a plain model serialisation inside the agent
// scope (a row owned by another agent is a 404, never a 403: the two are deliberately indistinguishable).
func SubLocationByID(ctx context.Context, d *db.DB, agentID, id int64) (map[string]any, error) {
	var (
		agent, createdBy     int64
		owner, prevOwner     sql.NullInt64
		code, name           string
		address, contact     sql.NullString
		description          sql.NullString
		isActive             int
		createdAt, updatedAt sql.NullTime
	)
	err := d.QueryRowContext(ctx, `SELECT agent_id, owner_user_id, previous_owner_user_id, code, name, address,
			contact_number, description, is_active, created_by, created_at, updated_at
		FROM warehouse_sub_locations WHERE id = ? AND agent_id = ? LIMIT 1`, id, agentID).
		Scan(&agent, &owner, &prevOwner, &code, &name, &address, &contact, &description, &isActive,
			&createdBy, &createdAt, &updatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id":                     id,
		"agent_id":               agent,
		"owner_user_id":          nullIntPtr(owner),
		"previous_owner_user_id": nullIntPtr(prevOwner),
		"code":                   code,
		"name":                   name,
		"address":                nullStr(address),
		"contact_number":         nullStr(contact),
		"description":            nullStr(description),
		"is_active":              isActive == 1,
		"created_by":             createdBy,
		"created_at":             formatTime(createdAt),
		"updated_at":             formatTime(updatedAt),
	}, nil
}

// SubLocationStocks is WarehouseSubLocationController::stocks: raw warehouse rows for the location with
// product/variation loaded — so the generated columns (target_identity/location_identity) are part of the
// response, exactly as Eloquent serialises them.
func SubLocationStocks(ctx context.Context, d *db.DB, agentID, subLocationID int64) ([]map[string]any, error) {
	exists, err := subLocationExists(ctx, d, agentID, subLocationID)
	if err != nil {
		return nil, err
	}
	if !exists {
		return nil, ErrNotFound
	}
	rows, err := d.QueryContext(ctx, `SELECT id, agent_id, product_id, product_variation_id, stock_type,
			sub_location_id, quantity, created_at, updated_at, target_identity, location_identity
		FROM warehouse_stocks WHERE agent_id = ? AND sub_location_id = ? ORDER BY id ASC`, agentID, subLocationID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type rrow struct {
		id, agentID          int64
		productID, variation sql.NullInt64
		stockType            string
		subID                sql.NullInt64
		quantity             int64
		createdAt, updatedAt sql.NullTime
		targetIdentity       sql.NullString
		locationIdentity     sql.NullInt64
	}
	var list []rrow
	var productIDs, variationIDs []int64
	for rows.Next() {
		var r rrow
		if err := rows.Scan(&r.id, &r.agentID, &r.productID, &r.variation, &r.stockType, &r.subID,
			&r.quantity, &r.createdAt, &r.updatedAt, &r.targetIdentity, &r.locationIdentity); err != nil {
			return nil, err
		}
		list = append(list, r)
		if pid, ok := nullInt(r.productID); ok {
			productIDs = append(productIDs, pid)
		}
		if vid, ok := nullInt(r.variation); ok {
			variationIDs = append(variationIDs, vid)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	// The `product`/`variation` relations here are loaded WITHOUT their own nested relations, so their
	// serialisation carries no images/compositions.
	products, err := loadProductModels(ctx, d, productIDs, false)
	if err != nil {
		return nil, err
	}
	variations, err := loadVariationModelsSimple(ctx, d, variationIDs)
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
		if vid, ok := nullInt(r.variation); ok {
			if v, found := variations[vid]; found {
				variation = v
			}
		}
		out = append(out, map[string]any{
			"id":                   r.id,
			"agent_id":             r.agentID,
			"product_id":           nullIntPtr(r.productID),
			"product_variation_id": nullIntPtr(r.variation),
			"stock_type":           r.stockType,
			"sub_location_id":      nullIntPtr(r.subID),
			"quantity":             r.quantity,
			"created_at":           formatTime(r.createdAt),
			"updated_at":           formatTime(r.updatedAt),
			"target_identity":      nullStr(r.targetIdentity),
			"location_identity":    nullIntPtr(r.locationIdentity),
			"product":              product,
			"variation":            variation,
		})
	}
	return out, nil
}

// ---- stock cards -----------------------------------------------------------------------------------

// StockCard is one product/variation row of the stock-card grid.
//
// The cards endpoints compute availability from the WAREHOUSE buckets only (transit + conditional plan) —
// they do not fall back to legacy product_stocks the way the canonical projection does. That asymmetry is
// Laravel's actual behaviour and is reproduced deliberately: a legacy-only target shows transit 0 here.
type StockCard struct {
	ProductID          any    `json:"product_id"`
	ProductVariationID any    `json:"product_variation_id"`
	ProductName        string `json:"product_name"`
	VariationLabel     any    `json:"variation_label"`
	SKU                any    `json:"sku"`
	ProductImageURL    any    `json:"product_image_url"`
	Transit            int    `json:"transit"`
	FactoryPlan        int    `json:"factory_plan"`
	Reserved           int    `json:"reserved"`
	Sellable           int    `json:"sellable"`
	FactoryPlanEnabled bool   `json:"factory_plan_enabled"`
}

// SubStockCard is one row of the sub-location stock-card grid.
type SubStockCard struct {
	ProductID          any    `json:"product_id"`
	ProductVariationID any    `json:"product_variation_id"`
	ProductName        string `json:"product_name"`
	VariationLabel     any    `json:"variation_label"`
	SKU                any    `json:"sku"`
	ProductImageURL    any    `json:"product_image_url"`
	SubStock           int    `json:"sub_stock"`
}

// CardsResult is a page of stock cards plus the meta block (which DOES include per_page here).
type CardsResult struct {
	Cards   []StockCard
	Page    Page
	PerPage int
}

// StockCards is WarehouseStockRequestController::cards.
func StockCards(ctx context.Context, d *db.DB, agentID int64, search string, page, perPage int) (*CardsResult, error) {
	products, total, lastPage, err := pageProducts(ctx, d, search, page, perPage)
	if err != nil {
		return nil, err
	}
	rows, err := loadWarehouseBuckets(ctx, d, agentID)
	if err != nil {
		return nil, err
	}
	reservedSimple, err := reservedSimple(ctx, d, agentID)
	if err != nil {
		return nil, err
	}
	reservedVariation, err := reservedVariations(ctx, d, agentID)
	if err != nil {
		return nil, err
	}
	planEnabled, err := factoryPlanEnabled(ctx, d, agentID)
	if err != nil {
		return nil, err
	}

	cards := make([]StockCard, 0, len(products))
	for _, p := range products {
		if !p.HasVariations {
			transit, plan := rows.sum(ProductTarget(p.ID))
			reserved := reservedSimple[p.ID]
			cards = append(cards, StockCard{
				ProductID:          p.ID,
				ProductVariationID: nil,
				ProductName:        p.Name,
				VariationLabel:     nil,
				SKU:                p.SKU,
				ProductImageURL:    p.ImageURL,
				Transit:            transit,
				FactoryPlan:        plan,
				Reserved:           reserved,
				Sellable:           max0(transit + planIf(planEnabled, plan) - reserved),
				FactoryPlanEnabled: planEnabled,
			})
			continue
		}
		for _, v := range p.Variations {
			transit, plan := rows.sum(VariationTarget(v.ID))
			reserved := reservedVariation[v.ID]
			cards = append(cards, StockCard{
				ProductID:          nil,
				ProductVariationID: v.ID,
				ProductName:        p.Name,
				VariationLabel:     v.Label,
				SKU:                v.SKU,
				ProductImageURL:    p.ImageURL,
				Transit:            transit,
				FactoryPlan:        plan,
				Reserved:           reserved,
				Sellable:           max0(transit + planIf(planEnabled, plan) - reserved),
				FactoryPlanEnabled: planEnabled,
			})
		}
	}
	return &CardsResult{Cards: cards, Page: Page{CurrentPage: page, LastPage: lastPage, Total: total}, PerPage: perPage}, nil
}

// SubLocationCards is WarehouseStockRequestController::subCards.
//
// It returns its pagination block as well (unlike the plain sub-location stock list), so the caller gets the
// page totals: current_page / last_page / total / per_page.
func SubLocationCards(ctx context.Context, d *db.DB, agentID, subLocationID int64, search string, page, perPage int) ([]SubStockCard, Page, error) {
	exists, err := subLocationExists(ctx, d, agentID, subLocationID)
	if err != nil {
		return nil, Page{}, err
	}
	if !exists {
		return nil, Page{}, ErrNotFound
	}
	products, total, lastPage, err := pageProducts(ctx, d, search, page, perPage)
	if err != nil {
		return nil, Page{}, err
	}
	rows, err := d.QueryContext(ctx, `SELECT product_id, product_variation_id, quantity FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = 'sub' AND sub_location_id = ?`, agentID, subLocationID)
	if err != nil {
		return nil, Page{}, err
	}
	defer rows.Close()
	simple := map[int64]int{}
	variation := map[int64]int{}
	for rows.Next() {
		var pid, vid sql.NullInt64
		var qty int
		if err := rows.Scan(&pid, &vid, &qty); err != nil {
			return nil, Page{}, err
		}
		if vid.Valid {
			variation[vid.Int64] += qty
		} else if pid.Valid {
			simple[pid.Int64] += qty
		}
	}
	if err := rows.Err(); err != nil {
		return nil, Page{}, err
	}

	cards := make([]SubStockCard, 0, len(products))
	for _, p := range products {
		if !p.HasVariations {
			cards = append(cards, SubStockCard{
				ProductID:       p.ID,
				ProductName:     p.Name,
				SKU:             p.SKU,
				ProductImageURL: p.ImageURL,
				SubStock:        simple[p.ID],
			})
			continue
		}
		for _, v := range p.Variations {
			cards = append(cards, SubStockCard{
				ProductVariationID: v.ID,
				ProductName:        p.Name,
				VariationLabel:     v.Label,
				SKU:                v.SKU,
				ProductImageURL:    p.ImageURL,
				SubStock:           variation[v.ID],
			})
		}
	}
	return cards, Page{CurrentPage: page, LastPage: lastPage, Total: total}, nil
}

// ---- sub stock (Sales-Kurir-Sub) -------------------------------------------------------------------

// ReplenishmentTarget is one row of GET /sub-stock/replenishment-targets.
type ReplenishmentTarget struct {
	ProductID          any            `json:"product_id"`
	ProductVariationID any            `json:"product_variation_id"`
	Product            map[string]any `json:"product"`
	Variation          any            `json:"variation"`
	CurrentTransit     int            `json:"current_transit"`
}

// ReplenishmentTargets is SubStockRequestService::replenishmentTargets.
func ReplenishmentTargets(ctx context.Context, d *db.DB, agentID int64) ([]ReplenishmentTarget, error) {
	transit, err := d.QueryContext(ctx, `SELECT product_id, product_variation_id, quantity FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = 'transit' AND sub_location_id IS NULL`, agentID)
	if err != nil {
		return nil, err
	}
	defer transit.Close()
	byProduct := map[int64]int{}
	byVariation := map[int64]int{}
	for transit.Next() {
		var pid, vid sql.NullInt64
		var qty int
		if err := transit.Scan(&pid, &vid, &qty); err != nil {
			return nil, err
		}
		if vid.Valid {
			byVariation[vid.Int64] += qty
		} else if pid.Valid {
			byProduct[pid.Int64] += qty
		}
	}
	if err := transit.Err(); err != nil {
		return nil, err
	}

	out := []ReplenishmentTarget{}

	simple, err := d.QueryContext(ctx, `SELECT id, name, sku FROM products
		WHERE status = 'active' AND has_variations = 0 AND deleted_at IS NULL ORDER BY name ASC`)
	if err != nil {
		return nil, err
	}
	defer simple.Close()
	for simple.Next() {
		var id int64
		var name string
		var sku sql.NullString
		if err := simple.Scan(&id, &name, &sku); err != nil {
			return nil, err
		}
		out = append(out, ReplenishmentTarget{
			ProductID:      id,
			Product:        map[string]any{"id": id, "name": name, "sku": nullStr(sku)},
			CurrentTransit: byProduct[id],
		})
	}
	if err := simple.Err(); err != nil {
		return nil, err
	}

	vars, err := d.QueryContext(ctx, `SELECT v.id, v.sku, p.id, p.name, p.sku
		FROM product_variations v JOIN products p ON p.id = v.product_id
		WHERE v.is_active = 1 AND v.deleted_at IS NULL AND p.status = 'active' AND p.has_variations = 1
			AND p.deleted_at IS NULL
		ORDER BY v.product_id ASC, v.sort_order ASC`)
	if err != nil {
		return nil, err
	}
	defer vars.Close()
	for vars.Next() {
		var vid, pid int64
		var vsku string
		var pname string
		var psku sql.NullString
		if err := vars.Scan(&vid, &vsku, &pid, &pname, &psku); err != nil {
			return nil, err
		}
		out = append(out, ReplenishmentTarget{
			ProductVariationID: vid,
			Product:            map[string]any{"id": pid, "name": pname, "sku": nullStr(psku)},
			Variation:          map[string]any{"id": vid, "sku": vsku},
			CurrentTransit:     byVariation[vid],
		})
	}
	if err := vars.Err(); err != nil {
		return nil, err
	}
	return out, nil
}

// SubLocationForOwner is SubLocationOwnershipService::locationOf.
func SubLocationForOwner(ctx context.Context, d *db.DB, agentID, ownerUserID int64) (*SubLocationRef, error) {
	var (
		id                     int64
		code, name             string
		address, contactNumber sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT id, code, name, address, contact_number FROM warehouse_sub_locations
		WHERE owner_user_id = ? AND agent_id = ? AND is_active = 1 LIMIT 1`, ownerUserID, agentID).
		Scan(&id, &code, &name, &address, &contactNumber)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	return &SubLocationRef{ID: id, Code: code, Name: name, Address: address, ContactNumber: contactNumber}, nil
}

// SubLocationRef is the trimmed location object myStock returns.
type SubLocationRef struct {
	ID            int64
	Code          string
	Name          string
	Address       sql.NullString
	ContactNumber sql.NullString
}

// JSON renders the `sub_location` object of myStock ($location->only([...])).
func (s SubLocationRef) JSON() map[string]any {
	return map[string]any{
		"id":             s.ID,
		"code":           s.Code,
		"name":           s.Name,
		"address":        nullStr(s.Address),
		"contact_number": nullStr(s.ContactNumber),
	}
}

// MySubStock is SubStockRequestController::myStock.
func MySubStock(ctx context.Context, d *db.DB, agentID int64, location SubLocationRef) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT product_id, product_variation_id FROM warehouse_stocks
		WHERE stock_type = 'sub' AND sub_location_id = ? ORDER BY id ASC`, location.ID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type target struct {
		productID, variationID sql.NullInt64
	}
	var targets []target
	var productIDs, variationIDs []int64
	for rows.Next() {
		var t target
		if err := rows.Scan(&t.productID, &t.variationID); err != nil {
			return nil, err
		}
		targets = append(targets, t)
		if pid, ok := nullInt(t.productID); ok {
			productIDs = append(productIDs, pid)
		}
		if vid, ok := nullInt(t.variationID); ok {
			variationIDs = append(variationIDs, vid)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	// The controller eager-loads `product:id,name,sku` (a PARTIAL model) and `variation` whole, so the
	// product object here must NOT carry the rest of the columns.
	products, err := productBriefs(ctx, d, productIDs)
	if err != nil {
		return nil, err
	}
	variations, err := loadVariationModelsSimple(ctx, d, variationIDs)
	if err != nil {
		return nil, err
	}

	out := make([]map[string]any, 0, len(targets))
	for _, t := range targets {
		var product, variation any
		var pidArg, vidArg *int64
		if pid, ok := nullInt(t.productID); ok {
			pidArg = &pid
			if p, found := products[pid]; found {
				product = p
			}
		}
		if vid, ok := nullInt(t.variationID); ok {
			vidArg = &vid
			if v, found := variations[vid]; found {
				variation = v
			}
		}
		sellable, err := subSellable(ctx, d, location.ID, pidArg, vidArg)
		if err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"product_id":           nullIntPtr(t.productID),
			"product_variation_id": nullIntPtr(t.variationID),
			"product":              product,
			"variation":            variation,
			"physical":             sellable.physical,
			"reserved":             sellable.reserved,
			"sellable":             max0(sellable.physical - sellable.reserved),
		})
	}
	return out, nil
}

type subSellableResult struct {
	physical int
	reserved int
}

// subSellable is SubStockService::sellable (read-only path: no locking).
func subSellable(ctx context.Context, d *db.DB, subLocationID int64, productID, variationID *int64) (subSellableResult, error) {
	var clause string
	var args []any
	if variationID != nil {
		clause, args = "product_variation_id = ?", []any{*variationID}
	} else if productID != nil {
		clause, args = "product_id = ? AND product_variation_id IS NULL", []any{*productID}
	} else {
		// No target: nothing can match. Never widen the query to the whole sub location.
		return subSellableResult{}, nil
	}
	var physical int
	err := d.QueryRowContext(ctx, `SELECT COALESCE(quantity,0) FROM warehouse_stocks
		WHERE stock_type = 'sub' AND sub_location_id = ? AND `+clause+`
		ORDER BY id ASC LIMIT 1`, append([]any{subLocationID}, args...)...).Scan(&physical)
	if errors.Is(err, sql.ErrNoRows) {
		physical = 0
	} else if err != nil {
		return subSellableResult{}, err
	}

	var reserved int
	if err := d.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM sub_stock_reservations
		WHERE sub_location_id = ? AND status = 'active' AND `+clause,
		append([]any{subLocationID}, args...)...).Scan(&reserved); err != nil {
		return subSellableResult{}, err
	}
	return subSellableResult{physical: physical, reserved: reserved}, nil
}

// ---- helpers ---------------------------------------------------------------------------------------

func subLocationExists(ctx context.Context, d *db.DB, agentID, id int64) (bool, error) {
	var ok bool
	err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_sub_locations WHERE id = ? AND agent_id = ?)`,
		id, agentID).Scan(&ok)
	return ok, err
}

type productRow struct {
	ID            int64
	Name          string
	SKU           any
	ImageURL      any
	HasVariations bool
	Variations    []variationRow
}

type variationRow struct {
	ID    int64
	SKU   string
	Label any
}

// pageProducts is the paginated Product query the stock-card endpoints share.
func pageProducts(ctx context.Context, d *db.DB, search string, page, perPage int) ([]productRow, int, int, error) {
	where := []string{"status = 'active'", "deleted_at IS NULL"}
	args := []any{}
	if search != "" {
		term := "%" + search + "%"
		where = append(where, `(name LIKE ? OR sku LIKE ? OR EXISTS(
			SELECT 1 FROM product_variations v WHERE v.product_id = products.id AND v.deleted_at IS NULL AND v.sku LIKE ?))`)
		args = append(args, term, term, term)
	}
	whereSQL := strings.Join(where, " AND ")

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM products WHERE `+whereSQL, args...).Scan(&total); err != nil {
		return nil, 0, 0, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (page - 1) * perPage
	rows, err := d.QueryContext(ctx, `SELECT id, name, sku, has_variations FROM products
		WHERE `+whereSQL+` ORDER BY id ASC LIMIT ? OFFSET ?`, append(append([]any{}, args...), perPage, offset)...)
	if err != nil {
		return nil, 0, 0, err
	}
	defer rows.Close()

	var products []productRow
	var ids []int64
	for rows.Next() {
		var p productRow
		var hasVar int
		var sku sql.NullString
		if err := rows.Scan(&p.ID, &p.Name, &sku, &hasVar); err != nil {
			return nil, 0, 0, err
		}
		p.SKU = nullStr(sku)
		p.HasVariations = hasVar == 1
		products = append(products, p)
		ids = append(ids, p.ID)
	}
	if err := rows.Err(); err != nil {
		return nil, 0, 0, err
	}
	if len(ids) == 0 {
		return products, total, lastPage, nil
	}

	images, err := primaryImages(ctx, d, ids)
	if err != nil {
		return nil, 0, 0, err
	}
	variations, err := activeVariations(ctx, d, ids)
	if err != nil {
		return nil, 0, 0, err
	}
	for i := range products {
		products[i].ImageURL = images[products[i].ID]
		products[i].Variations = variations[products[i].ID]
	}
	return products, total, lastPage, nil
}

// primaryImages resolves the product card image: primary first, else the lowest sort_order.
func primaryImages(ctx context.Context, d *db.DB, ids []int64) (map[int64]any, error) {
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT product_id, path, is_primary, sort_order FROM product_images
		WHERE product_id IN (`+placeholders(len(ids))+`) ORDER BY product_id, is_primary DESC, sort_order ASC, id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int64]any{}
	seen := map[int64]bool{}
	for rows.Next() {
		var pid int64
		var path string
		var isPrimary int
		var sortOrder int64
		if err := rows.Scan(&pid, &path, &isPrimary, &sortOrder); err != nil {
			return nil, err
		}
		if seen[pid] {
			continue
		}
		seen[pid] = true
		out[pid] = asset.URL(ctx, path)
	}
	return out, rows.Err()
}

// activeVariations loads the active variations of the given products with their composition labels.
func activeVariations(ctx context.Context, d *db.DB, ids []int64) (map[int64][]variationRow, error) {
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, sku FROM product_variations
		WHERE product_id IN (`+placeholders(len(ids))+`) AND is_active = 1 AND deleted_at IS NULL
		ORDER BY product_id ASC, sort_order ASC, id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int64][]variationRow{}
	var varIDs []int64
	index := map[int64]int{}
	for rows.Next() {
		var id, pid int64
		var sku string
		if err := rows.Scan(&id, &pid, &sku); err != nil {
			return nil, err
		}
		varIDs = append(varIDs, id)
		index[id] = len(out[pid])
		out[pid] = append(out[pid], variationRow{ID: id, SKU: sku})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if len(varIDs) == 0 {
		return out, nil
	}
	labels, err := labelsFor(ctx, d, varIDs)
	if err != nil {
		return nil, err
	}
	for pid, list := range out {
		for i := range list {
			if label, ok := labels[list[i].ID]; ok {
				list[i].Label = label
			}
			_ = index
		}
		out[pid] = list
	}
	return out, nil
}

// labelsFor joins compositions → option values per variation.
func labelsFor(ctx context.Context, d *db.DB, variationIDs []int64) (map[int64]string, error) {
	args := make([]any, 0, len(variationIDs))
	for _, id := range variationIDs {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT c.product_variation_id, o.value
		FROM product_variation_compositions c
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		JOIN product_variation_attributes a ON a.id = c.product_variation_attribute_id
		WHERE c.product_variation_id IN (`+placeholders(len(variationIDs))+`)
		ORDER BY c.product_variation_id ASC, a.sort_order ASC, o.sort_order ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	parts := map[int64][]string{}
	for rows.Next() {
		var vid int64
		var value string
		if err := rows.Scan(&vid, &value); err != nil {
			return nil, err
		}
		if value != "" {
			parts[vid] = append(parts[vid], value)
		}
	}
	out := map[int64]string{}
	for vid, list := range parts {
		out[vid] = strings.Join(list, " / ")
	}
	return out, rows.Err()
}

// variationLabels resolves the "label" of each variation (compositions joined with " / ").
func variationLabels(ctx context.Context, d *db.DB, ids []int64) (map[int64]any, error) {
	labels, err := labelsFor(ctx, d, ids)
	if err != nil {
		return nil, err
	}
	out := map[int64]any{}
	for id, label := range labels {
		out[id] = label
	}
	return out, nil
}

// bucketTotals aggregates warehouse transit/plan quantities per target.
type bucketTotals struct {
	transit map[string]int
	plan    map[string]int
}

func keyOf(t Target) string {
	if t.ProductID != nil {
		return "p:" + itoa(*t.ProductID)
	}
	return "v:" + itoa(*t.VariationID)
}

func (b bucketTotals) sum(t Target) (int, int) {
	k := keyOf(t)
	return b.transit[k], b.plan[k]
}

func loadWarehouseBuckets(ctx context.Context, d *db.DB, agentID int64) (bucketTotals, error) {
	rows, err := d.QueryContext(ctx, `SELECT product_id, product_variation_id, stock_type, quantity
		FROM warehouse_stocks WHERE agent_id = ? AND stock_type IN ('transit','factory_plan')`, agentID)
	if err != nil {
		return bucketTotals{}, err
	}
	defer rows.Close()
	out := bucketTotals{transit: map[string]int{}, plan: map[string]int{}}
	for rows.Next() {
		var pid, vid sql.NullInt64
		var stockType string
		var qty int
		if err := rows.Scan(&pid, &vid, &stockType, &qty); err != nil {
			return bucketTotals{}, err
		}
		var k string
		if vid.Valid {
			k = "v:" + itoa(vid.Int64)
		} else if pid.Valid {
			k = "p:" + itoa(pid.Int64)
		} else {
			continue
		}
		if stockType == "transit" {
			out.transit[k] += qty
		} else {
			out.plan[k] += qty
		}
	}
	return out, rows.Err()
}

func reservedSimple(ctx context.Context, d *db.DB, agentID int64) (map[int64]int, error) {
	return reservedMap(ctx, d, `SELECT product_id, quantity_reserved FROM product_stocks WHERE agent_id = ?`, agentID)
}

func reservedVariations(ctx context.Context, d *db.DB, agentID int64) (map[int64]int, error) {
	return reservedMap(ctx, d, `SELECT product_variation_id, quantity_reserved FROM product_variation_stocks WHERE agent_id = ?`, agentID)
}

func reservedMap(ctx context.Context, d *db.DB, query string, agentID int64) (map[int64]int, error) {
	rows, err := d.QueryContext(ctx, query, agentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int64]int{}
	for rows.Next() {
		var id int64
		var qty int
		if err := rows.Scan(&id, &qty); err != nil {
			return nil, err
		}
		out[id] = qty
	}
	return out, rows.Err()
}

// loadProductModels reproduces a products model serialisation.
//
// withImages=true means the caller eager-loaded `images` (the relation is then part of the JSON). A nil map
// is returned when no ids are given so callers can distinguish "not loaded" from "empty".
func loadProductModels(ctx context.Context, d *db.DB, ids []int64, withImages bool) (map[int64]map[string]any, error) {
	out := map[int64]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, category_id, created_by, name, slug, description, short_description,
			has_variations, base_price, weight_grams, status, created_at, updated_at, deleted_at, sku
		FROM products WHERE id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			id, createdBy        int64
			categoryID           sql.NullInt64
			name, slug           string
			description, short   sql.NullString
			hasVar               int
			basePrice            sql.NullString
			weightGrams          sql.NullInt64
			status               string
			createdAt, updatedAt sql.NullTime
			deletedAt            sql.NullTime
			sku                  sql.NullString
		)
		if err := rows.Scan(&id, &categoryID, &createdBy, &name, &slug, &description, &short, &hasVar,
			&basePrice, &weightGrams, &status, &createdAt, &updatedAt, &deletedAt, &sku); err != nil {
			return nil, err
		}
		m := map[string]any{
			"id":                id,
			"category_id":       nullIntPtr(categoryID),
			"created_by":        createdBy,
			"name":              name,
			"slug":              slug,
			"description":       nullStr(description),
			"short_description": nullStr(short),
			"has_variations":    hasVar == 1,
			"base_price":        nullStr(basePrice),
			"weight_grams":      nullIntPtr(weightGrams),
			"status":            status,
			"created_at":        formatTime(createdAt),
			"updated_at":        formatTime(updatedAt),
			"deleted_at":        formatTime(deletedAt),
			"sku":               nullStr(sku),
		}
		out[id] = m
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	if withImages {
		imageRows, err := d.QueryContext(ctx, `SELECT id, product_id, product_variation_id, path, is_primary, sort_order,
				created_at, updated_at
			FROM product_images WHERE product_id IN (`+placeholders(len(ids))+`) ORDER BY id ASC`, args...)
		if err != nil {
			return nil, err
		}
		defer imageRows.Close()
		for imageRows.Next() {
			var (
				iid, pid             int64
				variationID          sql.NullInt64
				path                 string
				isPrimary            int
				sortOrder            int64
				createdAt, updatedAt sql.NullTime
			)
			if err := imageRows.Scan(&iid, &pid, &variationID, &path, &isPrimary, &sortOrder, &createdAt, &updatedAt); err != nil {
				return nil, err
			}
			p, ok := out[pid]
			if !ok {
				continue
			}
			list, _ := p["images"].([]map[string]any)
			list = append(list, map[string]any{
				"id":                   iid,
				"product_id":           pid,
				"product_variation_id": nullIntPtr(variationID),
				"path":                 path,
				"is_primary":           isPrimary == 1,
				"sort_order":           sortOrder,
				"created_at":           formatTime(createdAt),
				"updated_at":           formatTime(updatedAt),
			})
			p["images"] = list
			if _, has := p["__primary_image_url"]; !has && isPrimary == 1 {
				p["__primary_image_url"] = asset.URL(ctx, path)
			}
		}
		if err := imageRows.Err(); err != nil {
			return nil, err
		}
		for _, p := range out {
			if list, ok := p["images"].([]map[string]any); ok {
				p["images"] = list
			} else {
				p["images"] = []map[string]any{}
			}
			if _, has := p["__primary_image_url"]; !has {
				p["__primary_image_url"] = nil
			}
		}
	}
	return out, nil
}

// loadVariationModels reproduces a product_variations serialisation WITH compositions.option loaded.
func loadVariationModels(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	out, err := loadVariationModelsSimple(ctx, d, ids)
	if err != nil || len(out) == 0 {
		return out, err
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT c.id, c.product_variation_id, c.product_variation_attribute_id,
			c.product_variation_attribute_option_id, o.id, o.product_variation_attribute_id, o.value,
			o.sort_order, o.created_at, o.updated_at
		FROM product_variation_compositions c
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id IN (`+placeholders(len(ids))+`) ORDER BY c.id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			cid, vid, attrID, optionID int64
			oID, oAttrID               int64
			value                      string
			oSortOrder                 int64
			oCreatedAt, oUpdatedAt     sql.NullTime
		)
		if err := rows.Scan(&cid, &vid, &attrID, &optionID, &oID, &oAttrID, &value, &oSortOrder, &oCreatedAt, &oUpdatedAt); err != nil {
			return nil, err
		}
		v, ok := out[vid]
		if !ok {
			continue
		}
		list, _ := v["compositions"].([]map[string]any)
		list = append(list, map[string]any{
			"id":                                    cid,
			"product_variation_id":                  vid,
			"product_variation_attribute_id":        attrID,
			"product_variation_attribute_option_id": optionID,
			"option": map[string]any{
				"id":                             oID,
				"product_variation_attribute_id": oAttrID,
				"value":                          value,
				"sort_order":                     oSortOrder,
				"created_at":                     formatTime(oCreatedAt),
				"updated_at":                     formatTime(oUpdatedAt),
			},
		})
		v["compositions"] = list
		// `label` is derived by the model: compositions joined with " / " (null when empty).
		if label, ok := v["__label"]; !ok || label == nil {
			if value != "" {
				if existing, exists := v["__label"]; exists && existing != nil {
					v["__label"] = existing.(string) + " / " + value
				} else {
					v["__label"] = value
				}
			}
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	for _, v := range out {
		if _, ok := v["compositions"].([]map[string]any); !ok {
			v["compositions"] = []map[string]any{}
		}
	}
	return out, nil
}

// loadVariationModelsSimple serialises product_variations rows without nested relations.
func loadVariationModelsSimple(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	out := map[int64]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, sku, price, weight_grams, is_active, sort_order,
			created_at, updated_at, deleted_at
		FROM product_variations WHERE id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			id, pid                         int64
			sku, price                      string
			weight, sortOrd                 int64
			isActive                        int
			createdAt, updatedAt, deletedAt sql.NullTime
		)
		if err := rows.Scan(&id, &pid, &sku, &price, &weight, &isActive, &sortOrd, &createdAt, &updatedAt, &deletedAt); err != nil {
			return nil, err
		}
		out[id] = map[string]any{
			"id":           id,
			"product_id":   pid,
			"sku":          sku,
			"price":        price,
			"weight_grams": weight,
			"is_active":    isActive == 1,
			"sort_order":   sortOrd,
			"created_at":   formatTime(createdAt),
			"updated_at":   formatTime(updatedAt),
			"deleted_at":   formatTime(deletedAt),
		}
	}
	return out, rows.Err()
}

// loadSubLocationModels serialises warehouse_sub_locations rows (no relations).
func loadSubLocationModels(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	out := map[int64]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, agent_id, owner_user_id, previous_owner_user_id, code, name,
			address, contact_number, description, is_active, created_by, created_at, updated_at
		FROM warehouse_sub_locations WHERE id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			id, agent, createdBy int64
			owner, prevOwner     sql.NullInt64
			code, name           string
			address, contact     sql.NullString
			description          sql.NullString
			isActive             int
			createdAt, updatedAt sql.NullTime
		)
		if err := rows.Scan(&id, &agent, &owner, &prevOwner, &code, &name, &address, &contact, &description,
			&isActive, &createdBy, &createdAt, &updatedAt); err != nil {
			return nil, err
		}
		out[id] = map[string]any{
			"id":                     id,
			"agent_id":               agent,
			"owner_user_id":          nullIntPtr(owner),
			"previous_owner_user_id": nullIntPtr(prevOwner),
			"code":                   code,
			"name":                   name,
			"address":                nullStr(address),
			"contact_number":         nullStr(contact),
			"description":            nullStr(description),
			"is_active":              isActive == 1,
			"created_by":             createdBy,
			"created_at":             formatTime(createdAt),
			"updated_at":             formatTime(updatedAt),
		}
	}
	return out, rows.Err()
}
