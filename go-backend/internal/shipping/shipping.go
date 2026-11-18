// Package shipping is the Go port of Services/Shipping/ShippingQuoteService's ELIGIBILITY layer.
//
// Only the predicates that decide "what can be offered at checkout" live here for now
// (activeProviders / isPickupAvailable / isShippingSelectionAvailable / availableShippingMethods and the
// OpenRoute-or-KMSIT availability rule). The quoting providers themselves (RajaOngkir HTTP, OpenRoute/ORS,
// KMSIT routing) are a separate, later unit — but their ELIGIBILITY already decides the checkout wizard, so it
// is ported first and exactly.
//
// Ported semantics that are easy to get wrong:
//   - `rajaongkir` is offered when its row is globally active AND the agent has not switched it off.
//   - `openroute` ("Kurir Toko") is offered when its row is ORS-eligible OR the agent's KMSIT fallback is
//     eligible. KMSIT itself is NEVER listed as a method (KmsitProvider is a distance source, not an offer).
//   - Pickup needs no provider row at all: it exists when the agent has an agent_profiles row, because that
//     row is where the customer collects from.
//   - availableShippingMethods sorts by CODE (byte order): openroute before rajaongkir; pickup is appended
//     last regardless of code.
package shipping

import (
	"context"
	"database/sql"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Provider is one shipping_providers row.
type Provider struct {
	ID       int64
	Code     string
	Name     string
	IsActive bool
}

// Method is one entry of `shipping_methods` in the checkout payload.
type Method struct {
	Code  string `json:"code"`
	Label string `json:"label"`
}

// Location is the `pickup_location` object. Latitude/longitude are decimal(10,7) in the schema; the reference
// casts them to float, so a whole number renders as -6.9 rather than "-6.9000000".
type Location struct {
	StoreName string   `json:"store_name"`
	Address   string   `json:"address"`
	Latitude  *float64 `json:"latitude"`
	Longitude *float64 `json:"longitude"`
	Phone     *string  `json:"phone"`
}

// labels is availableShippingMethods' own map. Pickup is appended separately.
var labels = map[string]string{
	"rajaongkir": "Ekspedisi",
	"openroute":  "Kurir Toko",
}

// pickupLabel is the literal the reference appends for self-collection.
const pickupLabel = "Ambil ke Toko"

// disabledProviderIDs is the agent's switched-off set (AgentShippingProviderSetting::where('is_active', false)).
func disabledProviderIDs(ctx context.Context, d *db.DB, agentID *int64) (map[int64]bool, error) {
	if agentID == nil {
		return nil, nil
	}
	rows, err := d.QueryContext(ctx, `SELECT shipping_provider_id FROM agent_shipping_provider_settings
		WHERE agent_id = ? AND is_active = 0`, *agentID)
	if err != nil {
		if db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	defer rows.Close()
	out := map[int64]bool{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		out[id] = true
	}
	return out, rows.Err()
}

// providerRow loads one shipping_providers row by code (nil when absent).
func providerRow(ctx context.Context, d *db.DB, code string) (*Provider, error) {
	var p Provider
	var active int
	err := d.QueryRowContext(ctx, `SELECT id, code, name, is_active FROM shipping_providers WHERE code = ?`, code).
		Scan(&p.ID, &p.Code, &p.Name, &active)
	if err != nil {
		if err == sql.ErrNoRows || db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	p.IsActive = active == 1
	return &p, nil
}

// KmsitEligible is KmsitProvider::isEligible: the agent must have the (globally active, not locally disabled)
// kmsit provider row AND an agent_shipping_provider_configs row for it. KMSIT is a routing source, never an
// offered shipping method.
func KmsitEligible(ctx context.Context, d *db.DB, agentID *int64) (bool, error) {
	if agentID == nil {
		return false, nil
	}
	row, err := providerRow(ctx, d, "kmsit")
	if err != nil || row == nil || !row.IsActive {
		return false, err
	}
	disabled, err := disabledProviderIDs(ctx, d, agentID)
	if err != nil {
		return false, err
	}
	if disabled[row.ID] {
		return false, nil
	}
	var exists bool
	err = d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM agent_shipping_provider_configs
		WHERE agent_id = ? AND shipping_provider_id = ?)`, *agentID, row.ID).Scan(&exists)
	if err != nil {
		if db.IsMissingSchema(err) {
			return false, nil
		}
		return false, err
	}
	return exists, nil
}

// OpenRouteAvailable is OpenRouteProvider::isAvailable: ORS eligible OR the agent's KMSIT fallback eligible.
//
// isAvailable is what BOTH activeProviders and ShippingQuoteService::quote consult, so a branch that only has
// KMSIT configured can still be offered "Kurir Toko" — the toggle state of the openroute row is irrelevant
// once KMSIT is eligible.
func OpenRouteAvailable(ctx context.Context, d *db.DB, agentID *int64, row Provider) (bool, error) {
	disabled, err := disabledProviderIDs(ctx, d, agentID)
	if err != nil {
		return false, err
	}
	orsEligible := row.IsActive && !(agentID != nil && disabled[row.ID])
	if orsEligible {
		return true, nil
	}
	return KmsitEligible(ctx, d, agentID)
}

// ActiveProviders is ShippingQuoteService::activeProviders.
func ActiveProviders(ctx context.Context, d *db.DB, agentID *int64) ([]Provider, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, code, name, is_active FROM shipping_providers
		WHERE code IN ('rajaongkir','openroute')`)
	if err != nil {
		if db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	defer rows.Close()

	byCode := map[string]Provider{}
	for rows.Next() {
		var p Provider
		var active int
		if err := rows.Scan(&p.ID, &p.Code, &p.Name, &active); err != nil {
			return nil, err
		}
		p.IsActive = active == 1
		byCode[p.Code] = p
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	disabled, err := disabledProviderIDs(ctx, d, agentID)
	if err != nil {
		return nil, err
	}

	out := make([]Provider, 0, 2)
	if ro, ok := byCode["rajaongkir"]; ok && ro.IsActive && !disabled[ro.ID] {
		out = append(out, ro)
	}
	if or, ok := byCode["openroute"]; ok {
		available, err := OpenRouteAvailable(ctx, d, agentID, or)
		if err != nil {
			return nil, err
		}
		if available {
			out = append(out, or)
		}
	}
	return out, nil
}

// AnyProviderEnabled is ShippingQuoteService::isAnyProviderEnabled.
func AnyProviderEnabled(ctx context.Context, d *db.DB, agentID *int64) (bool, error) {
	providers, err := ActiveProviders(ctx, d, agentID)
	if err != nil {
		return false, err
	}
	return len(providers) > 0, nil
}

// OpenRouteEnabled is ShippingQuoteService::isOpenRouteEnabled (drives `map_picker_enabled`).
func OpenRouteEnabled(ctx context.Context, d *db.DB, agentID *int64) (bool, error) {
	providers, err := ActiveProviders(ctx, d, agentID)
	if err != nil {
		return false, err
	}
	for _, p := range providers {
		if p.Code == "openroute" {
			return true, nil
		}
	}
	return false, nil
}

// PickupLocation is CheckoutStepResolver::pickupLocation. Nil when the agent has no agent_profiles row.
func PickupLocation(ctx context.Context, d *db.DB, agentID *int64) (*Location, error) {
	if agentID == nil {
		return nil, nil
	}
	var (
		loc                     Location
		lat, lng                sql.NullString
		phone                   sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT store_name, address, CAST(latitude AS CHAR), CAST(longitude AS CHAR), phone
		FROM agent_profiles WHERE user_id = ? LIMIT 1`, *agentID).
		Scan(&loc.StoreName, &loc.Address, &lat, &lng, &phone)
	if err != nil {
		if err == sql.ErrNoRows || db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	loc.Latitude = parseNullableFloat(lat)
	loc.Longitude = parseNullableFloat(lng)
	if phone.Valid {
		s := phone.String
		loc.Phone = &s
	}
	return &loc, nil
}

// PickupAvailable is ShippingQuoteService::isPickupAvailable.
func PickupAvailable(ctx context.Context, d *db.DB, agentID *int64) (bool, error) {
	loc, err := PickupLocation(ctx, d, agentID)
	if err != nil {
		return false, err
	}
	return loc != nil, nil
}

// SelectionAvailable is ShippingQuoteService::isShippingSelectionAvailable: a delivery provider OR pickup.
func SelectionAvailable(ctx context.Context, d *db.DB, agentID *int64) (bool, error) {
	any, err := AnyProviderEnabled(ctx, d, agentID)
	if err != nil {
		return false, err
	}
	if any {
		return true, nil
	}
	return PickupAvailable(ctx, d, agentID)
}

// AvailableMethods is ShippingQuoteService::availableShippingMethods.
//
// The provider list is sorted by CODE before mapping — deliberately not by id and not by insertion order, so
// the wizard's method order is stable and identical across runtimes.
func AvailableMethods(ctx context.Context, d *db.DB, agentID *int64) ([]Method, error) {
	providers, err := ActiveProviders(ctx, d, agentID)
	if err != nil {
		return nil, err
	}
	// Insertion sort on a max-2 element slice keeps the reference's stable sortBy('code') semantics without
	// pulling in a sort dependency for clarity's sake.
	for i := 1; i < len(providers); i++ {
		for j := i; j > 0 && providers[j-1].Code > providers[j].Code; j-- {
			providers[j-1], providers[j] = providers[j], providers[j-1]
		}
	}

	methods := make([]Method, 0, len(providers)+1)
	for _, p := range providers {
		methods = append(methods, Method{Code: p.Code, Label: labels[p.Code]})
	}
	pickup, err := PickupAvailable(ctx, d, agentID)
	if err != nil {
		return nil, err
	}
	if pickup {
		methods = append(methods, Method{Code: "pickup", Label: pickupLabel})
	}
	return methods, nil
}
