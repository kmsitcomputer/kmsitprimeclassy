package shipping_test

// Provider-level tests for the outbound shipping integrations.
//
// WHY THIS FILE EXISTS
// The three providers (RajaOngkir, OpenRouteService, KMSIT) are HTTP clients, so a differential test against the
// live Laravel reference would need real credentials. Instead the PARSING and the TARIFF arithmetic are pinned
// here against a local stub server, with the expected numbers derived from the reference formulas in
// ShippingQuoteService / OpenRouteProvider / RajaOngkirProvider / KurirTokoTariff.
//
// The differential harness (tools/parity/compare_order_quote.py) still proves the reference-observable part: an
// unconfigured provider produces exactly the same 422 on both runtimes.
//
// Skipped when the isolated test database is not reachable (same policy as the inventory concurrency tests).

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

const testAgentID = 900

func openTestDB(t *testing.T) *db.DB {
	t.Helper()
	envPath := os.Getenv("GO_ENV_FILE")
	if envPath == "" {
		envPath = "../../.env"
	}
	if _, err := os.Stat(envPath); err != nil {
		t.Skipf("no %s; run tools/parity/run_go_tests.sh", envPath)
	}
	cfg, err := config.Load(config.FileGetter(envPath))
	if err != nil {
		t.Fatalf("config: %v", err)
	}
	if !config.IsTestingDatabase(cfg.DB.Database) {
		t.Fatalf("refusing to run against %q (must contain _test/_testing)", cfg.DB.Database)
	}
	database, err := db.Open(context.Background(), cfg.DB)
	if err != nil {
		t.Skipf("test database unreachable: %v", err)
	}
	t.Cleanup(func() { _ = database.Close() })
	return database
}

// plaintextConfig decodes the credential blob as-is. Production rows are Laravel-encrypted and decrypted with
// APP_KEY (verified in internal/cryptox); here the seam keeps the provider tests free of key material.
func plaintextConfig(payload string) (map[string]any, error) {
	var out map[string]any
	if payload == "" {
		return map[string]any{}, nil
	}
	if err := json.Unmarshal([]byte(payload), &out); err != nil {
		return nil, err
	}
	return out, nil
}

// reset restores the provider-config baseline AND guarantees the dedicated test agent exists (the config tables
// have a real FK on users, so a synthetic agent id is not enough).
func reset(t *testing.T, d *db.DB) {
	t.Helper()
	ctx := context.Background()
	if _, err := d.ExecContext(ctx, `INSERT INTO users (id, role_id, name, status, password, created_at, updated_at)
		SELECT ?, r.id, 'Shipping Test Agent', 'active', 'x', NOW(), NOW() FROM roles r WHERE r.slug = 'agen'
		AND NOT EXISTS (SELECT 1 FROM users WHERE id = ?)`, testAgentID, testAgentID); err != nil {
		t.Fatalf("reset agent: %v", err)
	}
	for _, stmt := range []string{
		`DELETE FROM agent_shipping_provider_configs WHERE agent_id = ?`,
		`DELETE FROM shipping_configurations WHERE agent_id = ?`,
	} {
		if _, err := d.ExecContext(ctx, stmt, testAgentID); err != nil {
			t.Fatalf("reset: %v", err)
		}
	}
	t.Cleanup(func() {
		_, _ = d.ExecContext(ctx, `DELETE FROM agent_shipping_provider_configs WHERE agent_id = ?`, testAgentID)
		_, _ = d.ExecContext(ctx, `DELETE FROM shipping_configurations WHERE agent_id = ?`, testAgentID)
		_, _ = d.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, testAgentID)
	})
}

func providerID(t *testing.T, d *db.DB, code string) int64 {
	t.Helper()
	var id int64
	if err := d.QueryRow(`SELECT id FROM shipping_providers WHERE code = ?`, code).Scan(&id); err != nil {
		t.Skipf("provider %q is not seeded: %v", code, err)
	}
	return id
}

func seedTariff(t *testing.T, d *db.DB, providerID int64, pricePerKm, minDistance, minCharge string, freeEnabled bool, freeMin any) {
	t.Helper()
	enabled := 0
	if freeEnabled {
		enabled = 1
	}
	var freeValue any
	if freeMin != nil {
		freeValue = *(&freeMin)
	}
	_, err := d.Exec(`INSERT INTO shipping_configurations
		(agent_id, shipping_provider_id, price_per_km, minimum_distance_km, minimum_charge,
		 free_shipping_enabled, free_shipping_min_amount, is_active, created_at, updated_at)
		VALUES (?,?,?,?,?,?,?,1,NOW(),NOW())`,
		testAgentID, providerID, pricePerKm, minDistance, minCharge, enabled, freeValue)
	if err != nil {
		t.Fatalf("seed tariff: %v", err)
	}
}

func seedConfig(t *testing.T, d *db.DB, providerID int64, payload string) {
	t.Helper()
	if _, err := d.Exec(`INSERT INTO agent_shipping_provider_configs
		(agent_id, shipping_provider_id, config, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())`,
		testAgentID, providerID, payload); err != nil {
		t.Fatalf("seed config: %v", err)
	}
}

func newService(d *db.DB, baseURL string, trusted []string) *shipping.Service {
	return &shipping.Service{
		DB:            d,
		Config:        config.ShippingConfig{OpenRouteBaseURL: baseURL, OpenRouteProfile: "driving-car", RajaOngkirBaseURL: baseURL, KmsitTrustedHosts: trusted},
		DecryptConfig: plaintextConfig,
		Lookup:        func(string) ([]net.IP, error) { return []net.IP{net.ParseIP("127.0.0.1")}, nil },
	}
}

func strPtr(v string) *string { return &v }

// TestOpenRouteTariffArithmetic pins the Kurir Toko money rules: chargeable distance above the minimum, the
// free-shipping threshold, the below-minimum zero, and the minimum charge floor.
func TestOpenRouteTariffArithmetic(t *testing.T) {
	d := openTestDB(t)
	reset(t, d)

	ors := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "test-key" {
			t.Errorf("missing ORS Authorization header: %q", r.Header.Get("Authorization"))
		}
		_, _ = w.Write([]byte(`{"routes":[{"summary":{"distance":3456.78,"duration":600}}]}`))
	}))
	defer ors.Close()

	openrouteID := providerID(t, d, "openroute")
	seedConfig(t, d, openrouteID, `{"api_key":"test-key","profile":"driving-car"}`)

	svc := newService(d, ors.URL, nil)
	base := shipping.Context{
		OriginLat: -6.9, OriginLng: 107.6, DestLat: -6.95, DestLng: 107.65,
		WeightGrams: 1000, AgentID: testAgentID,
	}

	cases := []struct {
		name     string
		tariff   func()
		subtotal float64
		want     float64
	}{
		{
			name: "distance rate applied",
			tariff: func() {
				reset(t, d)
				seedConfig(t, d, openrouteID, `{"api_key":"test-key"}`)
				seedTariff(t, d, openrouteID, "5000.00", "1.00", "0.00", false, nil)
			},
			want: 12300, // round(3.46 - 1) * 5000
		},
		{
			name: "minimum charge floor",
			tariff: func() {
				reset(t, d)
				seedConfig(t, d, openrouteID, `{"api_key":"test-key"}`)
				seedTariff(t, d, openrouteID, "100.00", "3.00", "2500.00", false, nil)
			},
			want: 2500, // chargeable 0.46 * 100 = 46 → floored to the minimum charge
		},
		{
			name: "below minimum distance is free",
			tariff: func() {
				reset(t, d)
				seedConfig(t, d, openrouteID, `{"api_key":"test-key"}`)
				seedTariff(t, d, openrouteID, "5000.00", "10.00", "0.00", false, nil)
			},
			want: 0,
		},
		{
			name: "free shipping threshold met",
			tariff: func() {
				reset(t, d)
				seedConfig(t, d, openrouteID, `{"api_key":"test-key"}`)
				seedTariff(t, d, openrouteID, "5000.00", "1.00", "0.00", true, "50000.00")
			},
			subtotal: 60000,
			want:     0,
		},
		{
			name: "free shipping threshold NOT met",
			tariff: func() {
				reset(t, d)
				seedConfig(t, d, openrouteID, `{"api_key":"test-key"}`)
				seedTariff(t, d, openrouteID, "5000.00", "1.00", "0.00", true, "50000.00")
			},
			subtotal: 40000,
			want:     12300,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			tc.tariff()
			ctx := base
			ctx.Subtotal = tc.subtotal
			ctx.PreferredProvider = strPtr("openroute")
			result, err := svc.Quote(context.Background(), ctx)
			if err != nil {
				t.Fatalf("quote: %v", err)
			}
			if result.Cost != tc.want {
				t.Errorf("cost = %v, want %v (distance %v km)", result.Cost, tc.want, deref(result.DistanceKm))
			}
			if result.ProviderCode != "openroute" {
				t.Errorf("provider = %q", result.ProviderCode)
			}
		})
	}
}

func deref(v *float64) any {
	if v == nil {
		return nil
	}
	return *v
}

// TestKmsitFallbackAndSsrfGuard covers the fallback path AND the guard: the same endpoint is refused when the
// host is not on the operator allow-list.
func TestKmsitFallbackAndSsrfGuard(t *testing.T) {
	d := openTestDB(t)
	reset(t, d)

	stub := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-API-Key") != "kmsit-token" {
			t.Errorf("missing KMSIT API key header: %q", r.Header.Get("X-API-Key"))
		}
		if r.URL.Path != "/api/v1/routes" {
			t.Errorf("unexpected path %q", r.URL.Path)
		}
		if got := r.URL.Query().Get("profile"); got != "car" {
			t.Errorf("profile = %q, want car (driving normalises to car)", got)
		}
		_, _ = w.Write([]byte(`{"distance_m":2345.6,"duration_s":300}`))
	}))
	defer stub.Close()

	openrouteID := providerID(t, d, "openroute")
	kmsitID := providerID(t, d, "kmsit")
	// The openroute provider row is globally INACTIVE (fixture) and there is no ORS key, so Kurir Toko must come
	// from the agent's KMSIT fallback — exactly the branch a naive activeProviders port loses.
	if _, err := d.Exec(`UPDATE shipping_providers SET is_active = 1 WHERE id = ?`, kmsitID); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _, _ = d.Exec(`UPDATE shipping_providers SET is_active = 0 WHERE id = ?`, kmsitID) })
	seedConfig(t, d, kmsitID, `{"base_url":"`+stub.URL+`","token":"kmsit-token","profile":"driving"}`)
	seedTariff(t, d, openrouteID, "2000.00", "0.00", "0.00", false, nil)

	ctx := shipping.Context{
		OriginLat: -6.9, OriginLng: 107.6, DestLat: -6.95, DestLng: 107.65,
		WeightGrams: 1000, AgentID: testAgentID, PreferredProvider: strPtr("openroute"),
	}

	// Trusted host: the request succeeds and the distance is parsed (2.35 km rounded).
	trusted := newService(d, "", []string{"127.0.0.1"})
	result, err := trusted.Quote(context.Background(), ctx)
	if err != nil {
		t.Fatalf("trusted quote: %v", err)
	}
	if result.Cost != 4700 { // round(2.35 - 0) * 2000
		t.Errorf("cost = %v, want 4700", result.Cost)
	}
	if km := deref(result.DistanceKm); km != 2.35 {
		t.Errorf("distance = %v, want 2.35", km)
	}

	// Untrusted: the SSRF policy must refuse a loopback endpoint outright.
	untrusted := newService(d, "", nil)
	if _, err := untrusted.Quote(context.Background(), ctx); err == nil {
		t.Fatal("a loopback KMSIT endpoint was accepted — the SSRF guard is not applied")
	}
}

// TestRajaOngkirOptionSelection pins the courier intersection (agent-enabled ∩ Super-Admin-supported) and the
// cheapest-first ordering, plus the explicit courier+service selection.
func TestRajaOngkirOptionSelection(t *testing.T) {
	d := openTestDB(t)
	reset(t, d)

	stub := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/destination/domestic-destination":
			// city_name has NO "Kota" prefix because the reference strips that prefix from the INTERNAL name only
			// (sameRegency) and then compares the two strings — returning "Kota Bandung" here would make the
			// reference fail the mapping too. The stub models the reference's rule, not an assumption.
			_, _ = w.Write([]byte(`{"data":[
				{"id":"999","label":"Elsewhere","province_name":"Lain","city_name":"Lain","district_name":"Lain","subdistrict_name":"Lain"},
				{"id":"32730101","label":"Sukawarna, Sukajadi, Bandung, Jawa Barat",
				 "province_name":"Jawa Barat","city_name":"Bandung","district_name":"Sukajadi","subdistrict_name":"Sukawarna"}
			]}`))
		case r.URL.Path == "/calculate/domestic-cost":
			if err := r.ParseForm(); err != nil {
				t.Errorf("form: %v", err)
			}
			if got := r.Form.Get("courier"); got != "jne" {
				t.Errorf("courier sent = %q, want jne (pos is deactivated in the master list)", got)
			}
			if got := r.Form.Get("weight"); got != "1000" {
				t.Errorf("weight = %q", got)
			}
			_, _ = w.Write([]byte(`{"data":[
				{"name":"JNE","code":"jne","service":"YES","cost":20000,"etd":"1-2"},
				{"name":"JNE","code":"jne","service":"REG","cost":12000,"etd":"2-3"}
			]}`))
		default:
			t.Errorf("unexpected path %q", r.URL.Path)
		}
	}))
	defer stub.Close()

	rajaID := providerID(t, d, "rajaongkir")
	seedConfig(t, d, rajaID, `{"api_key":"raja-key","origin_destination_id":"456","origin_label":"Bandung","couriers":["jne","pos"]}`)

	svc := newService(d, stub.URL, nil)
	village := "32730101"
	ctx := shipping.Context{
		OriginLat: -6.9, OriginLng: 107.6, DestLat: -6.95, DestLng: 107.65,
		DestVillageID: &village, WeightGrams: 1000, AgentID: testAgentID,
		PreferredProvider: strPtr("rajaongkir"),
	}

	result, err := svc.Quote(context.Background(), ctx)
	if err != nil {
		t.Fatalf("quote: %v (cause: %v)", err, errors.Unwrap(err))
	}
	if result.Cost != 12000 {
		t.Errorf("cost = %v, want the cheapest 12000", result.Cost)
	}

	// An explicit courier+service must pick that exact option, never the cheapest.
	ctx.SelectedCourier = &shipping.CourierOption{Courier: "JNE", Service: "YES"}
	result, err = svc.Quote(context.Background(), ctx)
	if err != nil {
		t.Fatalf("selected quote: %v", err)
	}
	if result.Cost != 20000 {
		t.Errorf("selected cost = %v, want 20000", result.Cost)
	}
}

// TestRajaOngkirRejectsUnmappedDestination pins that an unmatched hierarchy fails closed instead of guessing.
func TestRajaOngkirRejectsUnmappedDestination(t *testing.T) {
	d := openTestDB(t)
	reset(t, d)

	stub := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"data":[]}`))
	}))
	defer stub.Close()

	rajaID := providerID(t, d, "rajaongkir")
	seedConfig(t, d, rajaID, `{"api_key":"raja-key","origin_destination_id":"456","origin_label":"Bandung","couriers":["jne"]}`)

	svc := newService(d, stub.URL, nil)
	village := "32730101"
	ctx := shipping.Context{
		OriginLat: -6.9, OriginLng: 107.6, DestLat: -6.95, DestLng: 107.65,
		DestVillageID: &village, WeightGrams: 1000, AgentID: testAgentID,
		PreferredProvider: strPtr("rajaongkir"),
	}
	if _, err := svc.Quote(context.Background(), ctx); err == nil {
		t.Fatal("an unmapped destination produced a quote — it must fail closed")
	}
}

var _ = sql.ErrNoRows
