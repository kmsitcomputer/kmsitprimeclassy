package shipping

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

const timeSecond = time.Second

// CourierOption is the courier+service the customer picked under "Ekspedisi".
type CourierOption struct {
	Courier string
	Service string
}

// Context is ShippingQuoteContext.
type Context struct {
	OriginLat         float64
	OriginLng         float64
	DestLat           float64
	DestLng           float64
	DestRegencyID     *string
	DestVillageID     *string
	WeightGrams       int
	Subtotal          float64
	AgentID           int64
	PreferredProvider *string
	SelectedCourier   *CourierOption
}

// Result is ShippingQuoteResult.
type Result struct {
	Cost         float64
	DistanceKm   *float64
	RatePerKm    *float64
	ProviderCode string
	Meta         map[string]any
}

// QuoteError is a provider-side failure. The reference NEVER surfaces these raw: the caller maps every one to a
// 422 `shipping.method_not_available` with a `shipping_method` field error.
type QuoteError struct {
	// Message is the internal/log message; the API response uses the generic shipping sentence.
	Message string
	// Err is the underlying cause (never serialised).
	Err error
}

func (e *QuoteError) Error() string { return e.Message }
func (e *QuoteError) Unwrap() error { return e.Err }

// Service is ShippingQuoteService's quoting half (eligibility lives in shipping.go).
type Service struct {
	DB     *db.DB
	AppKey string
	Config config.ShippingConfig
	// Lookup is the DNS seam used by the SSRF guard (tests inject a resolver; nil = system resolver).
	Lookup func(string) ([]net.IP, error)
	// Now is the clock seam for the TTL cache.
	Now func() time.Time
	// DecryptConfig is the credential seam. Production leaves it nil (values are Laravel-encrypted); tests inject
	// a plaintext decoder so provider parsing can be exercised without a real APP_KEY.
	DecryptConfig func(string) (map[string]any, error)

	cacheMu sync.Mutex
	cache   map[string]cacheEntry
}

type cacheEntry struct {
	value   any
	expires time.Time
}

func (s *Service) now() time.Time {
	if s.Now != nil {
		return s.Now()
	}
	return time.Now()
}

func (s *Service) cacheGet(key string) (any, bool) {
	s.cacheMu.Lock()
	defer s.cacheMu.Unlock()
	entry, ok := s.cache[key]
	if !ok || s.now().After(entry.expires) {
		return nil, false
	}
	return entry.value, true
}

func (s *Service) cachePut(key string, value any, ttl time.Duration) {
	s.cacheMu.Lock()
	defer s.cacheMu.Unlock()
	if s.cache == nil {
		s.cache = map[string]cacheEntry{}
	}
	s.cache[key] = cacheEntry{value: value, expires: s.now().Add(ttl)}
}

// Quote is ShippingQuoteService::quote.
//
// Precedence (the reference's own rules, preserved exactly):
//   - an explicit `pickup` is free and never touches a provider;
//   - an explicit provider is used AS CHOSEN and never silently swapped for the other one on failure (the
//     customer picked a price/service, not "whichever works");
//   - without a choice, RajaOngkir is tried first, then OpenRoute, and only a request where NO provider was even
//     attempted falls back to free shipping.
func (s *Service) Quote(ctx context.Context, c Context) (Result, error) {
	preferred := c.PreferredProvider
	if preferred == nil && c.SelectedCourier != nil {
		value := "rajaongkir"
		preferred = &value
	}

	if preferred != nil && *preferred == "pickup" {
		return Result{Cost: 0, ProviderCode: "pickup"}, nil
	}
	if preferred != nil {
		return s.quoteWithPreferredProvider(ctx, c, *preferred)
	}

	attempted := false
	if row, err := providerRow(ctx, s.DB, "rajaongkir"); err != nil {
		return Result{}, err
	} else if row != nil && row.IsActive {
		disabled, derr := disabledProviderIDs(ctx, s.DB, &c.AgentID)
		if derr != nil {
			return Result{}, derr
		}
		if !disabled[row.ID] {
			attempted = true
			if result, qerr := s.quoteRajaOngkir(ctx, c, *row); qerr == nil {
				return result, nil
			}
		}
	}

	orRow, err := providerRow(ctx, s.DB, "openroute")
	if err != nil {
		return Result{}, err
	}
	if orRow != nil {
		available, aerr := OpenRouteAvailable(ctx, s.DB, &c.AgentID, *orRow)
		if aerr != nil {
			return Result{}, aerr
		}
		if available {
			attempted = true
			if result, qerr := s.quoteOpenRoute(ctx, c, *orRow); qerr == nil {
				return result, nil
			}
		}
	}

	if attempted {
		return Result{}, &QuoteError{Message: "every attempted provider failed"}
	}
	return Result{Cost: 0, ProviderCode: "free", Meta: map[string]any{"reason": "no_provider_enabled_or_all_failed"}}, nil
}

func (s *Service) quoteWithPreferredProvider(ctx context.Context, c Context, preferred string) (Result, error) {
	row, err := providerRow(ctx, s.DB, preferred)
	if err != nil {
		return Result{}, err
	}
	if row == nil {
		return Result{}, &QuoteError{Message: "preferred provider row is missing"}
	}

	available := false
	if preferred == "openroute" {
		available, err = OpenRouteAvailable(ctx, s.DB, &c.AgentID, *row)
	} else {
		var disabled map[int64]bool
		disabled, err = disabledProviderIDs(ctx, s.DB, &c.AgentID)
		available = row.IsActive && !disabled[row.ID]
	}
	if err != nil {
		return Result{}, err
	}
	if !available {
		return Result{}, &QuoteError{Message: "preferred provider is not available"}
	}

	switch preferred {
	case "rajaongkir":
		return s.quoteRajaOngkir(ctx, c, *row)
	case "openroute":
		return s.quoteOpenRoute(ctx, c, *row)
	default:
		return Result{}, &QuoteError{Message: "unknown preferred provider"}
	}
}

// ---- agent credentials ----------------------------------------------------------------------------------

// providerConfig reads the agent's encrypted credential blob.
//
// The column is Laravel `encrypted:array`; an unreadable value (APP_KEY rotated) is degraded exactly like the
// reference — the provider simply cannot be used, never a crash.
func (s *Service) providerConfig(ctx context.Context, agentID, providerID int64) (map[string]any, error) {
	var raw sql.NullString
	err := s.DB.QueryRowContext(ctx, `SELECT config FROM agent_shipping_provider_configs
		WHERE agent_id = ? AND shipping_provider_id = ?`, agentID, providerID).Scan(&raw)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) || db.IsMissingSchema(err) {
			return map[string]any{}, nil
		}
		return nil, err
	}
	if !raw.Valid || strings.TrimSpace(raw.String) == "" {
		return map[string]any{}, nil
	}
	decrypt := s.DecryptConfig
	if decrypt == nil {
		decrypt = func(payload string) (map[string]any, error) { return cryptox.DecryptArray(s.AppKey, payload) }
	}
	decoded, derr := decrypt(raw.String)
	if derr != nil {
		return nil, &QuoteError{Message: "provider configuration cannot be decrypted (APP_KEY changed?)", Err: derr}
	}
	return decoded, nil
}

func configString(cfg map[string]any, key string) string {
	if v, ok := cfg[key]; ok {
		switch t := v.(type) {
		case string:
			return t
		case json.Number:
			return t.String()
		case float64:
			return strconv.FormatFloat(t, 'f', -1, 64)
		}
	}
	return ""
}

func configStrings(cfg map[string]any, key string) []string {
	raw, ok := cfg[key]
	if !ok {
		return nil
	}
	items, ok := raw.([]any)
	if !ok {
		return nil
	}
	out := make([]string, 0, len(items))
	for _, item := range items {
		if s, ok := item.(string); ok {
			out = append(out, s)
		}
	}
	return out
}

// ---- RajaOngkir ---------------------------------------------------------------------------------------

type rajaOngkirOption struct {
	Value   float64
	Courier string
	Service string
	ETD     *string
}

func (s *Service) quoteRajaOngkir(ctx context.Context, c Context, row Provider) (Result, error) {
	options, err := s.rajaOngkirOptions(ctx, c, row)
	if err != nil {
		return Result{}, err
	}
	if len(options) == 0 {
		return Result{}, &QuoteError{Message: "no RajaOngkir service is available for this route"}
	}
	selected := options[0]
	if c.SelectedCourier != nil {
		selected = rajaOngkirOption{}
		for _, option := range options {
			if option.Courier == strings.ToLower(c.SelectedCourier.Courier) && option.Service == c.SelectedCourier.Service {
				selected = option
				break
			}
		}
		if selected.Service == "" {
			return Result{}, &QuoteError{Message: "selected RajaOngkir courier service is unavailable"}
		}
	}
	return Result{
		Cost:         selected.Value,
		ProviderCode: "rajaongkir",
		Meta:         map[string]any{"api_version": "komerce_v2", "courier": selected.Courier, "service": selected.Service},
	}, nil
}

func (s *Service) rajaOngkirOptions(ctx context.Context, c Context, row Provider) ([]rajaOngkirOption, error) {
	if c.WeightGrams < 1 {
		return nil, &QuoteError{Message: "shipping weight must be at least one gram"}
	}
	cfg, err := s.providerConfig(ctx, c.AgentID, row.ID)
	if err != nil {
		return nil, err
	}
	apiKey := configString(cfg, "api_key")
	originID := configString(cfg, "origin_destination_id")
	if apiKey == "" || originID == "" {
		return nil, &QuoteError{Message: "RajaOngkir configuration is incomplete"}
	}

	destinationID, destinationLabel, err := s.rajaOngkirDestination(ctx, c, apiKey)
	if err != nil {
		return nil, err
	}

	agentCouriers := map[string]bool{}
	for _, code := range configStrings(cfg, "couriers") {
		agentCouriers[strings.ToLower(code)] = true
	}
	// AVAILABLE = provider-supported (Super Admin's master list) ∩ agent-enabled. Enforced here, not only at
	// save time, so a courier deactivated later can never reach the provider or the customer.
	supported, err := s.activeCourierCodes(ctx)
	if err != nil {
		return nil, err
	}
	var couriers []string
	for _, code := range supported {
		if agentCouriers[code] {
			couriers = append(couriers, code)
		}
	}
	if len(couriers) == 0 {
		return nil, &QuoteError{Message: "no supported courier is enabled for this agent"}
	}
	sort.Strings(couriers)

	cacheKey := "rajaongkir:rate:" + hashKey(originID, destinationID, strconv.Itoa(c.WeightGrams), strings.Join(couriers, ":"))
	if cached, ok := s.cacheGet(cacheKey); ok {
		return cached.([]rajaOngkirOption), nil
	}

	form := url.Values{}
	form.Set("origin", originID)
	form.Set("destination", destinationID)
	form.Set("weight", strconv.Itoa(c.WeightGrams))
	form.Set("courier", strings.Join(couriers, ":"))
	form.Set("price", "lowest")

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.Config.RajaOngkirBaseURL+"/calculate/domestic-cost", strings.NewReader(form.Encode()))
	if err != nil {
		return nil, &QuoteError{Message: "cannot build the RajaOngkir request", Err: err}
	}
	req.Header.Set("key", apiKey)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")

	resp, err := (&http.Client{Timeout: 12 * time.Second}).Do(req)
	if err != nil {
		return nil, &QuoteError{Message: "RajaOngkir cannot be reached", Err: err}
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return nil, &QuoteError{Message: fmt.Sprintf("RajaOngkir rejected the request (HTTP %d)", resp.StatusCode)}
	}

	var payload struct {
		Data []struct {
			Name        string   `json:"name"`
			Code        string   `json:"code"`
			Service     string   `json:"service"`
			Description *string  `json:"description"`
			Cost        *float64 `json:"cost"`
			ETD         *string  `json:"etd"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &payload); err != nil {
		return nil, &QuoteError{Message: "RajaOngkir returned an unparseable payload: " + snippet(body), Err: err}
	}

	options := make([]rajaOngkirOption, 0, len(payload.Data))
	for _, row := range payload.Data {
		cost := 0.0
		if row.Cost != nil {
			cost = *row.Cost
		}
		courier := strings.ToLower(row.Code)
		if cost <= 0 || courier == "" || row.Service == "" {
			continue
		}
		options = append(options, rajaOngkirOption{Value: cost, Courier: courier, Service: row.Service, ETD: row.ETD})
	}
	if len(options) == 0 {
		return nil, &QuoteError{Message: "RajaOngkir returned no priced service"}
	}
	sort.SliceStable(options, func(i, j int) bool { return options[i].Value < options[j].Value })
	s.cachePut(cacheKey, options, 300*time.Second)
	_ = destinationLabel
	return options, nil
}

// activeCourierCodes loads the Super Admin master list (shipping_couriers.is_active).
func (s *Service) activeCourierCodes(ctx context.Context) ([]string, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT code FROM shipping_couriers WHERE is_active = 1`)
	if err != nil {
		if db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var code string
		if err := rows.Scan(&code); err != nil {
			return nil, err
		}
		out = append(out, strings.ToLower(code))
	}
	return out, rows.Err()
}

// rajaOngkirDestination maps the internal village hierarchy onto the provider's destination id, matching every
// level of the hierarchy (the reference refuses an ambiguous/incorrect mapping rather than guessing).
func (s *Service) rajaOngkirDestination(ctx context.Context, c Context, apiKey string) (string, string, error) {
	if c.DestVillageID == nil || *c.DestVillageID == "" {
		return "", "", &QuoteError{Message: "destination village is required for RajaOngkir"}
	}
	villageID := *c.DestVillageID
	cacheKey := "rajaongkir:destination:" + villageID
	if cached, ok := s.cacheGet(cacheKey); ok {
		pair := cached.([]string)
		return pair[0], pair[1], nil
	}

	var (
		villageName  string
		districtName string
		districtID   string
	)
	err := s.DB.QueryRowContext(ctx, `SELECT v.name, d.name, d.id FROM villages v
		JOIN districts d ON d.id = v.district_id WHERE v.id = ?`, villageID).
		Scan(&villageName, &districtName, &districtID)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return "", "", &QuoteError{Message: "destination hierarchy is incomplete"}
		}
		return "", "", err
	}
	var regencyName, provinceName string
	if err := s.DB.QueryRowContext(ctx, `SELECT r.name, p.name FROM districts d
		JOIN regencies r ON r.id = d.regency_id JOIN provinces p ON p.id = r.province_id WHERE d.id = ?`, districtID).
		Scan(&regencyName, &provinceName); err != nil {
		return "", "", &QuoteError{Message: "destination hierarchy is incomplete"}
	}

	results, err := s.rajaOngkirSearch(ctx, apiKey, villageName)
	if err != nil {
		return "", "", err
	}
	for _, row := range results {
		if sameName(row.SubdistrictName, villageName) && sameName(row.DistrictName, districtName) &&
			sameRegency(row.CityName, regencyName) && sameName(row.ProvinceName, provinceName) {
			s.cachePut(cacheKey, []string{row.ID, row.Label}, 30*24*time.Hour)
			return row.ID, row.Label, nil
		}
	}
	return "", "", &QuoteError{Message: "destination has no matching RajaOngkir mapping"}
}

type rajaOngkirDestinationRow struct {
	ID              string
	Label           string
	ProvinceName    string
	CityName        string
	DistrictName    string
	SubdistrictName string
}

func (s *Service) rajaOngkirSearch(ctx context.Context, apiKey, search string) ([]rajaOngkirDestinationRow, error) {
	if apiKey == "" || strings.TrimSpace(search) == "" {
		return nil, &QuoteError{Message: "RajaOngkir credential or destination search is missing"}
	}
	query := url.Values{}
	query.Set("search", strings.TrimSpace(search))
	query.Set("limit", "100")
	query.Set("offset", "0")
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, s.Config.RajaOngkirBaseURL+"/destination/domestic-destination?"+query.Encode(), nil)
	if err != nil {
		return nil, &QuoteError{Message: "cannot build the RajaOngkir request", Err: err}
	}
	req.Header.Set("key", apiKey)
	req.Header.Set("Accept", "application/json")

	resp, err := (&http.Client{Timeout: 12 * time.Second}).Do(req)
	if err != nil {
		return nil, &QuoteError{Message: "RajaOngkir cannot be reached", Err: err}
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return nil, &QuoteError{Message: fmt.Sprintf("RajaOngkir destination search failed (HTTP %d)", resp.StatusCode)}
	}
	var payload struct {
		Data []struct {
			ID              string `json:"id"`
			Label           string `json:"label"`
			ProvinceName    string `json:"province_name"`
			CityName        string `json:"city_name"`
			DistrictName    string `json:"district_name"`
			SubdistrictName string `json:"subdistrict_name"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &payload); err != nil {
		return nil, &QuoteError{Message: "RajaOngkir returned an unparseable payload: " + snippet(body), Err: err}
	}
	out := make([]rajaOngkirDestinationRow, 0, len(payload.Data))
	for _, row := range payload.Data {
		if row.ID == "" || row.Label == "" {
			continue
		}
		out = append(out, rajaOngkirDestinationRow{
			ID: row.ID, Label: row.Label, ProvinceName: row.ProvinceName,
			CityName: row.CityName, DistrictName: row.DistrictName, SubdistrictName: row.SubdistrictName,
		})
	}
	return out, nil
}

// snippet keeps a bounded, non-secret prefix of a provider response for diagnostics.
func snippet(body []byte) string {
	const max = 120
	if len(body) > max {
		return string(body[:max])
	}
	return string(body)
}

func normalizeName(v string) string {
	return strings.ToLower(strings.TrimSpace(strings.Join(strings.Fields(v), " ")))
}

func sameName(a, b string) bool { return normalizeName(a) == normalizeName(b) }

func sameRegency(external, internal string) bool {
	trimmed := regexpKotaKabupaten.ReplaceAllString(internal, "")
	return normalizeName(external) == normalizeName(trimmed)
}

var regexpKotaKabupaten = mustRegexp(`(?i)^(kota|kabupaten)\s+`)

// ---- OpenRoute / KMSIT --------------------------------------------------------------------------------

func (s *Service) quoteOpenRoute(ctx context.Context, c Context, row Provider) (Result, error) {
	rate, err := s.kurirTokoTariff(ctx, c.AgentID, row.ID)
	if err != nil {
		return Result{}, err
	}
	if rate == nil {
		return Result{}, &QuoteError{Message: "OpenRoute has no active rate configuration"}
	}

	distance, err := s.openRouteDistance(ctx, c, row)
	if err != nil {
		return Result{}, err
	}

	meta := map[string]any{"distance_meters": distance.Meters, "duration_seconds": distance.Seconds, "routing_source": distance.Source}
	if rate.freeShippingEnabled && rate.freeShippingMinAmount != nil && c.Subtotal >= *rate.freeShippingMinAmount {
		return Result{Cost: 0, DistanceKm: &distance.Km, RatePerKm: &rate.pricePerKm, ProviderCode: "openroute",
			Meta: mergeMeta(meta, "rule", "subtotal_threshold_met")}, nil
	}

	chargeable := distance.Km - rate.minimumDistanceKm
	if chargeable < 0 {
		chargeable = 0
	}
	meta = mergeMeta(meta, "chargeable_distance_km", chargeable)
	if chargeable <= 0 {
		return Result{Cost: 0, DistanceKm: &distance.Km, RatePerKm: &rate.pricePerKm, ProviderCode: "openroute",
			Meta: mergeMeta(meta, "rule", "below_minimum_distance")}, nil
	}
	cost := money.Round2(chargeable * rate.pricePerKm)
	if rate.minimumCharge > 0 && cost < rate.minimumCharge {
		cost = rate.minimumCharge
	}
	return Result{Cost: cost, DistanceKm: &distance.Km, RatePerKm: &rate.pricePerKm, ProviderCode: "openroute",
		Meta: mergeMeta(meta, "rule", "distance_rate_applied")}, nil
}

func mergeMeta(meta map[string]any, key string, value any) map[string]any {
	out := make(map[string]any, len(meta)+1)
	for k, v := range meta {
		out[k] = v
	}
	out[key] = value
	return out
}

type kurirTokoRate struct {
	pricePerKm            float64
	minimumDistanceKm     float64
	minimumCharge         float64
	freeShippingEnabled   bool
	freeShippingMinAmount *float64
}

func (s *Service) kurirTokoTariff(ctx context.Context, agentID, anchorProviderID int64) (*kurirTokoRate, error) {
	var (
		price, minimumDistance, minimumCharge sql.NullString
		freeEnabled                           int
		freeMin                               sql.NullString
	)
	err := s.DB.QueryRowContext(ctx, `SELECT CAST(price_per_km AS CHAR), CAST(minimum_distance_km AS CHAR),
			CAST(minimum_charge AS CHAR), free_shipping_enabled, CAST(free_shipping_min_amount AS CHAR)
		FROM shipping_configurations WHERE agent_id = ? AND shipping_provider_id = ? AND is_active = 1
		LIMIT 1`, agentID, anchorProviderID).
		Scan(&price, &minimumDistance, &minimumCharge, &freeEnabled, &freeMin)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) || db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	rate := &kurirTokoRate{
		pricePerKm:          parseFloatString(price),
		minimumDistanceKm:   parseFloatString(minimumDistance),
		minimumCharge:       parseFloatString(minimumCharge),
		freeShippingEnabled: freeEnabled == 1,
	}
	if freeMin.Valid && strings.TrimSpace(freeMin.String) != "" {
		v := parseFloatString(freeMin)
		rate.freeShippingMinAmount = &v
	}
	return rate, nil
}

type distanceResult struct {
	Meters  float64
	Km      float64
	Seconds float64
	Source  string
}

// openRouteDistance is resolveRoute: ORS first (eligible + credentialed), else the agent's KMSIT fallback, else
// fail closed. The tariff is identical for both sources; the provider code stays `openroute`.
func (s *Service) openRouteDistance(ctx context.Context, c Context, row Provider) (distanceResult, error) {
	if row.IsActive {
		cfg, err := s.providerConfig(ctx, c.AgentID, row.ID)
		if err != nil {
			return distanceResult{}, err
		}
		apiKey := configString(cfg, "api_key")
		if apiKey == "" {
			apiKey = s.Config.OpenRouteKey
		}
		if apiKey != "" {
			profile := configString(cfg, "profile")
			if profile == "" {
				profile = s.Config.OpenRouteProfile
			}
			result, oerr := s.orsRoute(ctx, c, apiKey, profile)
			if oerr == nil {
				return result, nil
			}
		}
	}

	eligible, err := KmsitEligible(ctx, s.DB, &c.AgentID)
	if err != nil {
		return distanceResult{}, err
	}
	if eligible {
		return s.kmsitRoute(ctx, c)
	}
	return distanceResult{}, &QuoteError{Message: "no routing source is available for Kurir Toko"}
}

func (s *Service) orsRoute(ctx context.Context, c Context, apiKey, profile string) (distanceResult, error) {
	if err := validateCoordinates(c); err != nil {
		return distanceResult{}, err
	}
	body, err := json.Marshal(map[string]any{
		// ORS requires [longitude, latitude]; the database stores lat/lng.
		"coordinates":  [][]float64{{c.OriginLng, c.OriginLat}, {c.DestLng, c.DestLat}},
		"instructions": false,
	})
	if err != nil {
		return distanceResult{}, &QuoteError{Message: "cannot encode the ORS request", Err: err}
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		s.Config.OpenRouteBaseURL+"/v2/directions/"+url.PathEscape(profile)+"/json", strings.NewReader(string(body)))
	if err != nil {
		return distanceResult{}, &QuoteError{Message: "cannot build the ORS request", Err: err}
	}
	req.Header.Set("Authorization", apiKey)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := (&http.Client{Timeout: 12 * time.Second}).Do(req)
	if err != nil {
		return distanceResult{}, &QuoteError{Message: "OpenRouteService cannot be reached", Err: err}
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return distanceResult{}, &QuoteError{Message: fmt.Sprintf("OpenRouteService rejected the route (HTTP %d)", resp.StatusCode)}
	}
	var payload struct {
		Routes []struct {
			Summary struct {
				Distance *float64 `json:"distance"`
				Duration *float64 `json:"duration"`
			} `json:"summary"`
		} `json:"routes"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil || len(payload.Routes) == 0 {
		return distanceResult{}, &QuoteError{Message: "OpenRouteService returned an unparseable route", Err: err}
	}
	summary := payload.Routes[0].Summary
	if summary.Distance == nil || summary.Duration == nil || *summary.Distance < 0 || *summary.Duration < 0 {
		return distanceResult{}, &QuoteError{Message: "OpenRouteService returned an invalid summary"}
	}
	return distanceResult{
		Meters:  *summary.Distance,
		Km:      money.Round2(*summary.Distance / 1000),
		Seconds: *summary.Duration,
		Source:  "openrouteservice",
	}, nil
}

// kmsitRoute talks to the agent's KMSIT gateway with the SSRF guard applied to its endpoint.
func (s *Service) kmsitRoute(ctx context.Context, c Context) (distanceResult, error) {
	if err := validateCoordinates(c); err != nil {
		return distanceResult{}, err
	}
	kmsitRow, err := providerRow(ctx, s.DB, "kmsit")
	if err != nil || kmsitRow == nil {
		return distanceResult{}, &QuoteError{Message: "KMSIT provider row is missing"}
	}
	cfg, err := s.providerConfig(ctx, c.AgentID, kmsitRow.ID)
	if err != nil {
		return distanceResult{}, err
	}
	profile := normalizeProfile(configString(cfg, "profile"))
	if profile != "car" && profile != "motorcycle" {
		return distanceResult{}, &QuoteError{Message: "invalid KMSIT routing profile"}
	}
	token := configString(cfg, "token")
	if token == "" {
		return distanceResult{}, &QuoteError{Message: "KMSIT API key is missing"}
	}

	g := &guard{trusted: s.Config.KmsitTrustedHosts, lookup: s.Lookup}
	vetted, err := g.vet(configString(cfg, "base_url"))
	if err != nil {
		return distanceResult{}, &QuoteError{Message: err.Error(), Err: err}
	}

	origin := strings.TrimSuffix(vetted.URL, "/api/v1")
	coordinates := fmt.Sprintf("%v,%v;%v,%v", c.OriginLng, c.OriginLat, c.DestLng, c.DestLat)
	query := url.Values{}
	query.Set("profile", profile)
	query.Set("coordinates", coordinates)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, origin+"/api/v1/routes?"+query.Encode(), nil)
	if err != nil {
		return distanceResult{}, &QuoteError{Message: "cannot build the KMSIT request", Err: err}
	}
	req.Header.Set("X-API-Key", token)
	req.Header.Set("Accept", "application/json")

	client := &http.Client{Timeout: 8 * time.Second}
	if !isIPLiteral(vetted.Host) {
		client.Transport = &http.Transport{
			DialContext:           vetted.dialer(),
			ResponseHeaderTimeout: 8 * time.Second,
		}
	}

	resp, err := client.Do(req)
	if err != nil {
		return distanceResult{}, &QuoteError{Message: "KMSIT Routing Server cannot be reached", Err: err}
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return distanceResult{}, &QuoteError{Message: fmt.Sprintf("KMSIT rejected the route (HTTP %d)", resp.StatusCode)}
	}
	var payload struct {
		DistanceM *float64 `json:"distance_m"`
		DurationS *float64 `json:"duration_s"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil || payload.DistanceM == nil || payload.DurationS == nil {
		return distanceResult{}, &QuoteError{Message: "KMSIT returned an invalid route"}
	}
	if *payload.DistanceM < 0 || *payload.DurationS < 0 {
		return distanceResult{}, &QuoteError{Message: "KMSIT returned an invalid route"}
	}
	return distanceResult{
		Meters:  *payload.DistanceM,
		Km:      money.Round2(*payload.DistanceM / 1000),
		Seconds: *payload.DurationS,
		Source:  "kmsit",
	}, nil
}

func isIPLiteral(host string) bool { return net.ParseIP(host) != nil }

func normalizeProfile(profile string) string {
	switch profile {
	case "", "driving":
		return "car"
	default:
		return profile
	}
}

func validateCoordinates(c Context) error {
	for _, v := range []float64{c.OriginLat, c.OriginLng, c.DestLat, c.DestLng} {
		if v != v { // NaN
			return &QuoteError{Message: "invalid shipping coordinates"}
		}
	}
	if abs(c.OriginLat) > 90 || abs(c.DestLat) > 90 || abs(c.OriginLng) > 180 || abs(c.DestLng) > 180 {
		return &QuoteError{Message: "invalid shipping coordinates"}
	}
	return nil
}

func abs(v float64) float64 {
	if v < 0 {
		return -v
	}
	return v
}

func parseFloatString(v sql.NullString) float64 {
	if !v.Valid {
		return 0
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(v.String), 64)
	if err != nil {
		return 0
	}
	return f
}

func hashKey(parts ...string) string {
	// A short, stable key: the cache is per-process, so collision risk is a non-issue and readability helps.
	var sb strings.Builder
	for i, part := range parts {
		if i > 0 {
			sb.WriteByte('|')
		}
		sb.WriteString(part)
	}
	return sb.String()
}
