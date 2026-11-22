// Package checkout — Quote is the server-computed Review-step preview.
//
// Ported from CheckoutController::quote + OrderService::{quote,quoteLine,resolveLine,resolveDestination,
// voucherBudgetForLines} + VoucherService::resolve/isApplicableTo + ShippingQuoteService::quote.
//
// Two invariants matter more than anything else here:
//  1. It creates NOTHING. No reservation, no order row, no voucher redemption — it only reads. (The reference
//     is explicit about this: "read-only, same as quote() — never reserves stock".)
//  2. The numbers it returns are computed by the SAME internal/pricing arithmetic the eventual order creation
//     uses, so the Review step can never promise a total the persisted order disagrees with.
package checkout

import (
	"context"
	"database/sql"
	"errors"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/pricing"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

// QuoteLine is one requested cart line.
type QuoteLine struct {
	ProductID          int64
	ProductVariationID *int64
	Quantity           int
}

// Destination is the checkout address: either an existing address book row, or the inline structured address.
type Destination struct {
	AddressID      *int64
	RecipientName  *string
	RecipientPhone *string
	AddressLine    *string
	VillageID      *string
	Latitude       *float64
	Longitude      *float64
	PostalCode     *string
	ProvinceID     *string
	RegencyID      *string
	DistrictID     *string
}

// QuoteRequest is the validated request.
type QuoteRequest struct {
	Lines          []QuoteLine
	Destination    Destination
	ShippingMethod *string
	Courier        *string
	Service        *string
	VoucherCode    *string
}

// Warning is the per-line "insufficient stock" preview.
type Warning struct {
	ProductID          int64  `json:"product_id"`
	ProductVariationID *int64 `json:"product_variation_id"`
	Message            string `json:"message"`
}

// QuoteResponse is the exact payload of POST /checkout/quote.
type QuoteResponse struct {
	SubtotalAmount    float64   `json:"subtotal_amount"`
	ShippingFeeAmount float64   `json:"shipping_fee_amount"`
	AdminFeeAmount    float64   `json:"admin_fee_amount"`
	DiscountAmount    float64   `json:"discount_amount"`
	VoucherID         *int64    `json:"voucher_id"`
	TotalAmount       float64   `json:"total_amount"`
	DistanceKm        *float64  `json:"distance_km"`
	ShippingProvider  string    `json:"shipping_provider"`
	ShippingEnabled   bool      `json:"shipping_enabled"`
	Warnings          []Warning `json:"warnings"`
}

// Problem is a domain failure the handler renders as an API error envelope.
//
// Fields is the single-sentence-per-field shape an ApiException with a [field => sentence] map serialises.
// Errors is the literal mixed-typed payload for the exceptions that carry numbers (InsufficientStockException).
type Problem struct {
	Status  int
	Message string
	Fields  map[string]string
	Errors  map[string]any
}

func (p *Problem) Error() string { return p.Message }

// fieldProblem renders the reference's `new ApiException($message, 422, [$field => $fieldMessage])` shape.
func fieldProblem(message, field, fieldMessage string) *Problem {
	return &Problem{Status: 422, Message: message, Fields: map[string]string{field: fieldMessage}}
}

// DestinationSnapshot is the resolved destination (address book or inline).
type DestinationSnapshot struct {
	AddressID      *int64
	RecipientName  *string
	RecipientPhone *string
	AddressLine    *string
	Latitude       *float64
	Longitude      *float64
	RegencyID      *string
	DistrictID     *string
	ProvinceID     *string
	VillageID      *string
	// The resolved NAMES are what an ORDER persists as its address snapshot; a later region rename must never
	// rewrite a historical order's text.
	VillageName  *string
	DistrictName *string
	RegencyName  *string
	ProvinceName *string
	PostalCode   *string
}

// ResolvedLine is OrderService::resolveLine's result. Exported because order creation reuses it verbatim —
// the preview and the persisted order must resolve the SAME inventory target.
type ResolvedLine struct {
	Product   ProductRow
	Variation *VariationRow
	Quantity  int
}

type ProductRow struct {
	ID            int64
	Name          string
	HasVariations bool
	BasePrice     float64
	WeightGrams   int
	SKU           *string
}

type VariationRow struct {
	ID          int64
	ProductID   int64
	Price       float64
	WeightGrams int
	SKU         *string
	Label       *string
}

// Quoter is the quote service. It is stateless; every call reads the current rows.
type Quoter struct {
	Database *db.DB
	// Messages renders an i18n key with :replacements — injected so this package stays free of HTTP/locale
	// plumbing while still producing the reference's exact sentences.
	Messages func(key string, params ...string) string
	// Shipping is the shipping decision engine (eligibility + pickup/free/provider attempts).
	Shipping *shipping.Service
}

// Quote is OrderService::quote.
func (q *Quoter) Quote(ctx context.Context, konsumenID int64, req QuoteRequest) (*QuoteResponse, error) {
	agentID, err := q.AgentBranchOf(ctx, konsumenID)
	if err != nil {
		return nil, err
	}
	if agentID == nil {
		return nil, &Problem{Status: 422, Message: q.Messages("order.no_agent_branch")}
	}
	if len(req.Lines) == 0 {
		return nil, &Problem{Status: 422, Message: q.Messages("order.empty_items")}
	}

	profile, err := shipping.PickupLocation(ctx, q.Database, agentID)
	if err != nil {
		return nil, err
	}
	if profile == nil {
		return nil, &Problem{Status: 422, Message: q.Messages("order.agent_profile_incomplete")}
	}

	dest, err := q.ResolveDestination(ctx, konsumenID, req.Destination)
	if err != nil {
		return nil, err
	}

	var (
		subtotal     float64
		totalWeight  int
		warnings     []Warning
		resolvedList []ResolvedLine
	)
	for _, line := range req.Lines {
		lineSubtotal, lineWeight, warning, resolved, err := q.quoteLine(ctx, *agentID, line)
		if err != nil {
			return nil, err
		}
		subtotal += lineSubtotal
		totalWeight += lineWeight
		if warning != nil {
			warnings = append(warnings, *warning)
		}
		resolvedList = append(resolvedList, resolved)
	}
	subtotal = money.Round2(subtotal)

	adminFee, err := q.AdminFee(ctx)
	if err != nil {
		return nil, err
	}

	originLat, originLng := 0.0, 0.0
	if profile.Latitude != nil {
		originLat = *profile.Latitude
	}
	if profile.Longitude != nil {
		originLng = *profile.Longitude
	}
	destLat, destLng := 0.0, 0.0
	if dest.Latitude != nil {
		destLat = *dest.Latitude
	}
	if dest.Longitude != nil {
		destLng = *dest.Longitude
	}

	shippingResult, err := q.Shipping.Quote(ctx, shipping.Context{
		OriginLat:         originLat,
		OriginLng:         originLng,
		DestLat:           destLat,
		DestLng:           destLng,
		DestRegencyID:     dest.RegencyID,
		DestVillageID:     dest.VillageID,
		WeightGrams:       totalWeight,
		Subtotal:          subtotal,
		AgentID:           *agentID,
		PreferredProvider: req.ShippingMethod,
		SelectedCourier:   courierOption(req),
	})
	if err != nil {
		return nil, shippingProblem(q, err)
	}

	// Voucher preview: existence + scoped validity, then the canonical per-line budget algorithm. No
	// redemption increment happens here.
	var (
		voucherDiscount float64
		voucherID       *int64
	)
	if req.VoucherCode != nil && strings.TrimSpace(*req.VoucherCode) != "" {
		voucher, err := q.LoadVoucher(ctx, *agentID, strings.TrimSpace(*req.VoucherCode))
		if err != nil {
			return nil, err
		}
		voucherDiscount, err = q.VoucherBudgetForLines(ctx, *agentID, voucher, resolvedList)
		if err != nil {
			return nil, err
		}
		id := voucher.ID
		voucherID = &id
	}

	enabled, err := shipping.SelectionAvailable(ctx, q.Database, agentID)
	if err != nil {
		return nil, err
	}

	if warnings == nil {
		warnings = []Warning{}
	}

	return &QuoteResponse{
		SubtotalAmount:    subtotal,
		ShippingFeeAmount: shippingResult.Cost,
		AdminFeeAmount:    adminFee,
		DiscountAmount:    voucherDiscount,
		VoucherID:         voucherID,
		TotalAmount:       money.Round2(subtotal - voucherDiscount + shippingResult.Cost + adminFee),
		DistanceKm:        shippingResult.DistanceKm,
		ShippingProvider:  shippingResult.ProviderCode,
		ShippingEnabled:   enabled,
		Warnings:          warnings,
	}, nil
}

func courierOption(req QuoteRequest) *shipping.CourierOption {
	if req.Courier == nil || req.Service == nil {
		return nil
	}
	return &shipping.CourierOption{Courier: *req.Courier, Service: *req.Service}
}

// shippingProblem maps the shipping engine's failures to the reference's 422 + `shipping_method` field error.
//
// The provider's internal reason is NEVER surfaced: the customer sees the generic "method not available"
// sentence, exactly like ShippingQuoteService does after it logs ShippingQuoteException.
func shippingProblem(q *Quoter, err error) error {
	var se *shipping.QuoteError
	if errors.As(err, &se) {
		return fieldProblem(q.Messages("shipping.method_not_available"), "shipping_method", q.Messages("system.field_invalid"))
	}
	return err
}

// agentBranchOf reads the buyer's own branch (`users.agent_id`). Quote never trusts a client-supplied branch.
func (q *Quoter) AgentBranchOf(ctx context.Context, userID int64) (*int64, error) {
	var agent sql.NullInt64
	err := q.Database.QueryRowContext(ctx, `SELECT agent_id FROM users WHERE id = ? AND deleted_at IS NULL`, userID).Scan(&agent)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, &Problem{Status: 404, Message: q.Messages("system.not_found")}
		}
		return nil, err
	}
	return db.NullInt64(agent), nil
}

// adminFee is Setting::get('checkout.admin_fee_amount', '0') — a non-negative decimal in the settings table.
func (q *Quoter) AdminFee(ctx context.Context) (float64, error) {
	var raw sql.NullString
	err := q.Database.QueryRowContext(ctx, "SELECT `value` FROM settings WHERE `key` = 'checkout.admin_fee_amount'").Scan(&raw)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) || db.IsMissingSchema(err) {
			return 0, nil
		}
		return 0, err
	}
	if !raw.Valid || strings.TrimSpace(raw.String) == "" {
		return 0, nil
	}
	fee, parseErr := strconv.ParseFloat(strings.TrimSpace(raw.String), 64)
	if parseErr != nil {
		return 0, nil
	}
	return fee, nil
}

// quoteLine is OrderService::quoteLine: price, weight, and a stock warning. Quantity is clamped to >= 1 for the
// PREVIEW (the order path rejects < 1 instead), so a Review screen never shows a negative line.
func (q *Quoter) quoteLine(ctx context.Context, agentID int64, line QuoteLine) (float64, int, *Warning, ResolvedLine, error) {
	resolved, err := q.ResolveLine(ctx, line)
	if err != nil {
		return 0, 0, nil, resolved, err
	}
	qty := resolved.Quantity
	if qty < 1 {
		qty = 1
	}

	unitPrice, unitWeight, err := q.UnitPriceAndWeight(ctx, agentID, resolved)
	if err != nil {
		return 0, 0, nil, resolved, err
	}
	if unitWeight < 1 {
		return 0, 0, nil, resolved, &Problem{
			Status:  422,
			Message: "Berat produk belum dikonfigurasi.",
			Fields:  map[string]string{"items": "Berat produk wajib minimal 1 gram."},
		}
	}

	available, err := q.AvailableUnits(ctx, agentID, resolved)
	if err != nil {
		return 0, 0, nil, resolved, err
	}

	var warning *Warning
	if available < qty {
		item := resolved.Product.Name
		if resolved.Variation != nil && resolved.Variation.Label != nil {
			item = *resolved.Variation.Label
		}
		warning = &Warning{
			ProductID:          resolved.Product.ID,
			ProductVariationID: variationID(resolved.Variation),
			Message:            q.Messages("order.insufficient_stock", "item", item),
		}
	}

	return unitPrice * float64(qty), unitWeight * qty, warning, resolved, nil
}

func variationID(v *VariationRow) *int64 {
	if v == nil {
		return nil
	}
	id := v.ID
	return &id
}

// unitPriceAndWeight is the shared pricing rule, used by both the preview and (later) order creation.
func (q *Quoter) UnitPriceAndWeight(ctx context.Context, agentID int64, resolved ResolvedLine) (float64, int, error) {
	basePrice := resolved.Product.BasePrice
	unitWeight := resolved.Product.WeightGrams
	if resolved.Variation != nil {
		basePrice = resolved.Variation.Price
		if resolved.Variation.WeightGrams > 0 {
			unitWeight = resolved.Variation.WeightGrams
		}
	}

	var productID, variationIDArg *int64
	productID = &resolved.Product.ID
	if resolved.Variation != nil {
		id := resolved.Variation.ID
		variationIDArg = &id
	}
	discount, err := pricing.ActiveDiscount(ctx, q.Database, agentID, productID, variationIDArg)
	if err != nil {
		return 0, 0, err
	}
	return pricing.EffectiveUnitPrice(basePrice, discount).UnitPrice, unitWeight, nil
}

// availableUnits is the warning source: the canonical sellable figure, per target.
func (q *Quoter) AvailableUnits(ctx context.Context, agentID int64, resolved ResolvedLine) (int, error) {
	if resolved.Variation != nil {
		sellable, err := inventory.ForVariation(ctx, q.Database, agentID, resolved.Variation.ID)
		if err != nil {
			return 0, err
		}
		return sellable.Available, nil
	}
	sellable, err := inventory.ForProduct(ctx, q.Database, agentID, resolved.Product.ID)
	if err != nil {
		return 0, err
	}
	return sellable.Available, nil
}

// resolveLine is OrderService::resolveLine.
//
// A variation product REQUIRES an active variation of that product; a simple product REFUSES a variation id
// rather than ignoring it — ignoring it would prelock a target the reserve path never touches.
func (q *Quoter) ResolveLine(ctx context.Context, line QuoteLine) (ResolvedLine, error) {
	product, err := q.loadProduct(ctx, line.ProductID)
	if err != nil {
		return ResolvedLine{}, err
	}
	if product == nil {
		return ResolvedLine{}, &Problem{Status: 404, Message: "No query results for model [App\\Models\\Product] " + strconv.FormatInt(line.ProductID, 10)}
	}

	if product.HasVariations {
		if line.ProductVariationID == nil {
			return ResolvedLine{}, &Problem{Status: 422, Message: q.Messages("product.variation_required", "name", product.Name)}
		}
		variation, err := q.loadVariation(ctx, product.ID, *line.ProductVariationID)
		if err != nil {
			return ResolvedLine{}, err
		}
		if variation == nil {
			return ResolvedLine{}, &Problem{Status: 404, Message: "No query results for model [App\\Models\\ProductVariation] " + strconv.FormatInt(*line.ProductVariationID, 10)}
		}
		return ResolvedLine{Product: *product, Variation: variation, Quantity: line.Quantity}, nil
	}

	if line.ProductVariationID != nil {
		return ResolvedLine{}, &Problem{Status: 422, Message: q.Messages("product.variation_not_allowed", "name", product.Name)}
	}
	return ResolvedLine{Product: *product, Quantity: line.Quantity}, nil
}

func (q *Quoter) loadProduct(ctx context.Context, id int64) (*ProductRow, error) {
	var (
		p         ProductRow
		has       int
		basePrice sql.NullString
		weight    sql.NullInt64
		sku       sql.NullString
	)
	err := q.Database.QueryRowContext(ctx, `SELECT id, name, has_variations, CAST(base_price AS CHAR), weight_grams, sku
		FROM products WHERE id = ? AND status = 'active' AND deleted_at IS NULL`, id).
		Scan(&p.ID, &p.Name, &has, &basePrice, &weight, &sku)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	p.HasVariations = has == 1
	p.BasePrice = parseNullableFloat(basePrice)
	if weight.Valid {
		p.WeightGrams = int(weight.Int64)
	}
	if sku.Valid {
		s := sku.String
		p.SKU = &s
	}
	return &p, nil
}

func (q *Quoter) loadVariation(ctx context.Context, productID, variationID int64) (*VariationRow, error) {
	var (
		v      VariationRow
		price  sql.NullString
		weight sql.NullInt64
		sku    sql.NullString
	)
	err := q.Database.QueryRowContext(ctx, `SELECT id, product_id, CAST(price AS CHAR), weight_grams, sku
		FROM product_variations WHERE id = ? AND product_id = ? AND is_active = 1`, variationID, productID).
		Scan(&v.ID, &v.ProductID, &price, &weight, &sku)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	v.Price = parseNullableFloat(price)
	if weight.Valid {
		v.WeightGrams = int(weight.Int64)
	}
	if sku.Valid {
		s := sku.String
		v.SKU = &s
	}
	label, err := q.variationLabel(ctx, varietyID(v.ID))
	if err != nil {
		return nil, err
	}
	v.Label = label
	return &v, nil
}

func varietyID(id int64) *int64 { return &id }

// variationLabel is ProductVariation::label(): the comma-joined attribute values.
func (q *Quoter) variationLabel(ctx context.Context, variationID *int64) (*string, error) {
	if variationID == nil {
		return nil, nil
	}
	rows, err := q.Database.QueryContext(ctx, `SELECT o.value
		FROM product_variation_compositions c
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id = ?
		ORDER BY o.sort_order, o.id`, *variationID)
	if err != nil {
		if db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	defer rows.Close()
	var parts []string
	for rows.Next() {
		var value string
		if err := rows.Scan(&value); err != nil {
			return nil, err
		}
		parts = append(parts, value)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if len(parts) == 0 {
		return nil, nil
	}
	label := strings.Join(parts, ", ")
	return &label, nil
}

// resolveDestination is OrderService::resolveDestination.
func (q *Quoter) ResolveDestination(ctx context.Context, konsumenID int64, d Destination) (DestinationSnapshot, error) {
	if d.AddressID != nil {
		var (
			snap                       DestinationSnapshot
			lat, lng                   sql.NullString
			villageName, districtName2 sql.NullString
			regencyName, provinceName  sql.NullString
		)
		err := q.Database.QueryRowContext(ctx, `SELECT a.id, a.recipient_name, a.phone, a.address_line,
				CAST(a.latitude AS CHAR), CAST(a.longitude AS CHAR), a.regency_id, a.district_id, a.province_id, a.village_id,
				v.name, d.name, r.name, p.name
			FROM konsumen_addresses a
			LEFT JOIN villages v ON v.id = a.village_id
			LEFT JOIN districts d ON d.id = v.district_id
			LEFT JOIN regencies r ON r.id = d.regency_id
			LEFT JOIN provinces p ON p.id = r.province_id
			WHERE a.id = ? AND a.user_id = ?`, *d.AddressID, konsumenID).
			Scan(&snap.AddressID, &snap.RecipientName, &snap.RecipientPhone, &snap.AddressLine,
				&lat, &lng, &snap.RegencyID, &snap.DistrictID, &snap.ProvinceID, &snap.VillageID,
				&villageName, &districtName2, &regencyName, &provinceName)
		if err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				// findOrFail on a row that is not the caller's own: a 404, never a 403 — the reference does
				// not reveal whether the id exists for someone else.
				return snap, &Problem{Status: 404, Message: "No query results for model [App\\Models\\KonsumenAddress] " + strconv.FormatInt(*d.AddressID, 10)}
			}
			return snap, err
		}
		if lat.Valid {
			f := parseNullableFloat(lat)
			snap.Latitude = &f
		}
		if lng.Valid {
			f := parseNullableFloat(lng)
			snap.Longitude = &f
		}
		snap.VillageName = nullStrPtr(villageName)
		snap.DistrictName = nullStrPtr(districtName2)
		snap.RegencyName = nullStrPtr(regencyName)
		snap.ProvinceName = nullStrPtr(provinceName)
		return snap, nil
	}

	snap := DestinationSnapshot{
		PostalCode:     d.PostalCode,
		RecipientName:  d.RecipientName,
		RecipientPhone: d.RecipientPhone,
		AddressLine:    d.AddressLine,
		Latitude:       d.Latitude,
		Longitude:      d.Longitude,
		VillageID:      d.VillageID,
		DistrictID:     d.DistrictID,
		RegencyID:      d.RegencyID,
		ProvinceID:     d.ProvinceID,
	}

	if d.VillageID != nil && strings.TrimSpace(*d.VillageID) != "" {
		var (
			districtID   sql.NullString
			regencyID    sql.NullString
			provinceID   sql.NullString
			villageName  sql.NullString
			districtName sql.NullString
		)
		err := q.Database.QueryRowContext(ctx, `SELECT district_id, name FROM villages WHERE id = ?`, *d.VillageID).
			Scan(&districtID, &villageName)
		if err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return snap, fieldProblem(q.Messages("order.invalid_village"), "village_id", q.Messages("system.field_invalid"))
			}
			return snap, err
		}
		// The village is authoritative; district/regency/province are derived from it, and any client-supplied
		// id must match the canonical chain (IMP-002 hierarchy validation).
		var regencyName, provinceName sql.NullString
		if districtID.Valid {
			if err := q.Database.QueryRowContext(ctx, `SELECT regency_id, name FROM districts WHERE id = ?`, districtID.String).
				Scan(&regencyID, &districtName); err != nil && !errors.Is(err, sql.ErrNoRows) {
				return snap, err
			}
		}
		if regencyID.Valid {
			if err := q.Database.QueryRowContext(ctx, `SELECT province_id, name FROM regencies WHERE id = ?`, regencyID.String).
				Scan(&provinceID, &regencyName); err != nil && !errors.Is(err, sql.ErrNoRows) {
				return snap, err
			}
		}
		if provinceID.Valid {
			if err := q.Database.QueryRowContext(ctx, `SELECT name FROM provinces WHERE id = ?`, provinceID.String).
				Scan(&provinceName); err != nil && !errors.Is(err, sql.ErrNoRows) {
				return snap, err
			}
		}
		snap.VillageName = nullStrPtr(villageName)
		snap.DistrictName = nullStrPtr(districtName)
		snap.RegencyName = nullStrPtr(regencyName)
		snap.ProvinceName = nullStrPtr(provinceName)

		if d.DistrictID != nil && *d.DistrictID != "" && *d.DistrictID != districtID.String {
			return snap, fieldProblem(q.Messages("order.invalid_region_hierarchy"), "district_id", q.Messages("system.field_invalid"))
		}
		if d.RegencyID != nil && *d.RegencyID != "" && *d.RegencyID != regencyID.String {
			return snap, fieldProblem(q.Messages("order.invalid_region_hierarchy"), "regency_id", q.Messages("system.field_invalid"))
		}
		if d.ProvinceID != nil && *d.ProvinceID != "" && *d.ProvinceID != provinceID.String {
			return snap, fieldProblem(q.Messages("order.invalid_region_hierarchy"), "province_id", q.Messages("system.field_invalid"))
		}

		snap.VillageID = d.VillageID
		if districtID.Valid {
			snap.DistrictID = &districtID.String
		}
		if regencyID.Valid {
			snap.RegencyID = &regencyID.String
		}
		if provinceID.Valid {
			snap.ProvinceID = &provinceID.String
		}
	}
	return snap, nil
}

// Voucher is the subset of a vouchers row the preview and the create path need.
type Voucher struct {
	ID                 int64
	Type               string
	Value              float64
	IsActive           bool
	ValidFrom          *string
	ValidUntil         *string
	ProductID          *int64
	ProductVariationID *int64
	MaxUses            *int64
	UsedCount          int64
}

// resolveVoucher is VoucherService::resolve — existence + scoped validity only, never cart applicability.
func (q *Quoter) LoadVoucher(ctx context.Context, agentID int64, code string) (*Voucher, error) {
	var (
		v                     Voucher
		value                 sql.NullString
		validFrom, validUntil sql.NullString
		maxUses               sql.NullInt64
		active                int
	)
	err := q.Database.QueryRowContext(ctx, `SELECT id, type, CAST(value AS CHAR), is_active,
			CAST(valid_from AS CHAR), CAST(valid_until AS CHAR), product_id, product_variation_id, max_uses, used_count
		FROM vouchers WHERE agent_id = ? AND code = ?`, agentID, code).
		Scan(&v.ID, &v.Type, &value, &active, &validFrom, &validUntil, &v.ProductID, &v.ProductVariationID, &maxUses, &v.UsedCount)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, voucherInvalid(q)
		}
		return nil, err
	}
	v.IsActive = active == 1
	v.Value = parseNullableFloat(value)
	v.MaxUses = db.NullInt64(maxUses)
	if validFrom.Valid {
		s := validFrom.String
		v.ValidFrom = &s
	}
	if validUntil.Valid {
		s := validUntil.String
		v.ValidUntil = &s
	}

	today := pricing.Today().Format("2006-01-02")
	usable := v.IsActive
	if usable && v.ValidFrom != nil && today < *v.ValidFrom {
		usable = false
	}
	if usable && v.ValidUntil != nil && today > *v.ValidUntil {
		usable = false
	}
	if usable && v.MaxUses != nil && v.UsedCount >= *v.MaxUses {
		usable = false
	}
	if !usable {
		return nil, voucherInvalid(q)
	}
	return &v, nil
}

func voucherInvalid(q *Quoter) *Problem {
	return fieldProblem(q.Messages("voucher.invalid"), "voucher_code", q.Messages("voucher.invalid"))
}

// isApplicableTo is VoucherService::isApplicableTo: variation-targeted → exact variation; product-targeted →
// any variation of that product; whole-cart → everything.
func (v *Voucher) isApplicableTo(productID int64, variationID *int64) bool {
	if v.ProductVariationID != nil {
		return variationID != nil && *v.ProductVariationID == *variationID
	}
	if v.ProductID != nil {
		return *v.ProductID == productID
	}
	return true
}

// voucherBudgetForLines is OrderService::voucherBudgetForLines — the canonical A1-06/A1-07 calculation the
// order path uses too, so the preview can never disagree with the created order.
func (q *Quoter) VoucherBudgetForLines(ctx context.Context, agentID int64, v *Voucher, lines []ResolvedLine) (float64, error) {
	type applicable struct {
		resolved ResolvedLine
		amount   float64
	}
	var chosen []applicable
	var eligibleSubtotal float64
	for _, line := range lines {
		if !v.isApplicableTo(line.Product.ID, variationID(line.Variation)) {
			continue
		}
		unitPrice, _, err := q.UnitPriceAndWeight(ctx, agentID, line)
		if err != nil {
			return 0, err
		}
		amount := money.Round2(unitPrice * float64(line.Quantity))
		eligibleSubtotal += amount
		chosen = append(chosen, applicable{resolved: line, amount: amount})
	}
	if len(chosen) == 0 {
		return 0, fieldProblem(q.Messages("voucher.not_applicable"), "voucher_code", q.Messages("voucher.not_applicable"))
	}

	remainingBudget := eligibleSubtotal
	if v.Type == "fixed" && v.Value < eligibleSubtotal {
		remainingBudget = v.Value
	}
	remainingEligible := eligibleSubtotal

	total := 0.0
	for _, line := range chosen {
		discount, newBudget := pricing.VoucherLineDiscount(pricing.Voucher{
			ID:        v.ID,
			Type:      v.Type,
			Value:     v.Value,
			IsActive:  v.IsActive,
			UsedCount: v.UsedCount,
		}, line.amount, remainingBudget, remainingEligible)
		remainingBudget = newBudget
		remainingEligible = money.Round2(maxFloat(0, remainingEligible-discount))
		total += discount
	}
	return money.Round2(total), nil
}

func maxFloat(a, b float64) float64 {
	if a > b {
		return a
	}
	return b
}
