package api_test

// Inventory-domain integration tests against the isolated test database.
//
// They pin the canonical availability invariant (SellableStockService) — the single most reused rule in the
// system — plus the tenant scoping and the role gates. A change that makes these pass "by accident" would have
// to also satisfy the Laravel↔Go comparison (tools/parity/compare_inventory.py).

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
)

// loginAs authenticates the harness and returns the XSRF token for later mutations.
func (h *harness) loginAs(email, password string) bool {
	h.t.Helper()
	token := h.csrf()
	res := h.post("/api/v1/auth/login", map[string]any{"identifier": email, "password": password}, token)
	if res.status != http.StatusOK {
		h.t.Fatalf("login %s failed: %d %s", email, res.status, string(res.body))
	}
	return true
}

func (h *harness) getJSON(path string) map[string]any {
	h.t.Helper()
	res := h.do(http.MethodGet, path, nil)
	if res.status != http.StatusOK {
		h.t.Fatalf("GET %s = %d body=%s", path, res.status, string(res.body))
	}
	var env struct {
		Data any            `json:"data"`
		Meta map[string]any `json:"meta"`
	}
	if err := json.Unmarshal(res.body, &env); err != nil {
		h.t.Fatalf("GET %s: %v", path, err)
	}
	return map[string]any{"data": env.Data, "meta": env.Meta}
}

// TestSellableLegacyFallback pins the rule: with NO warehouse row at all, the legacy commitment row is the base
// and the source is reported as "legacy".
func TestSellableLegacyFallback(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse/sellable?product_id=3")
	data := body["data"].(map[string]any)

	if data["source"] != "legacy" {
		t.Errorf("source = %v, want legacy (product 3 has no warehouse row at all)", data["source"])
	}
	// Fixture: product_stocks(agent 10, product 3) = on_hand 25, reserved 3.
	if data["sellable_base"].(float64) != 25 {
		t.Errorf("sellable_base = %v, want 25", data["sellable_base"])
	}
	if data["reserved"].(float64) != 3 {
		t.Errorf("reserved = %v, want 3", data["reserved"])
	}
	if data["available"].(float64) != 22 {
		t.Errorf("available = %v, want 22", data["available"])
	}
	if data["physical_stock"].(float64) != 25 {
		t.Errorf("physical_stock = %v, want 25", data["physical_stock"])
	}
	if data["factory_plan_enabled"] != false {
		t.Errorf("legacy source must report factory_plan_enabled=false, got %v", data["factory_plan_enabled"])
	}
}

// TestSellableWarehouseAuthorityWithSubOnlyRows is the subtle half of the invariant: ANY warehouse row makes the
// warehouse authoritative, but only transit/factory_plan contribute to the base. A target holding just a `sub`
// row therefore has base 0 — not its legacy on-hand — and every unit of it is a commitment deficit.
func TestSellableWarehouseAuthorityWithSubOnlyRows(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse/sellable?variation_id=1")
	data := body["data"].(map[string]any)

	if data["source"] != "warehouse" {
		t.Errorf("source = %v, want warehouse (variation 1 has a `sub` row)", data["source"])
	}
	if data["sellable_base"].(float64) != 0 {
		t.Errorf("sellable_base = %v, want 0 (only `sub` rows exist; they do not feed the base)", data["sellable_base"])
	}
	// Fixture: product_variation_stocks(agent 10, variation 1) reserved = 2.
	if data["reserved"].(float64) != 2 {
		t.Errorf("reserved = %v, want 2", data["reserved"])
	}
	if data["available"].(float64) != 0 {
		t.Errorf("available = %v, want 0", data["available"])
	}
	if data["has_commitment_deficit"] != true {
		t.Error("reserved (2) > base (0) must report has_commitment_deficit=true")
	}
}

// TestSellableFactoryPlanSwitch proves the plan bucket only counts when the agent enabled it.
func TestSellableFactoryPlanSwitch(t *testing.T) {
	h := newHarness(t)
	// Establish the precondition explicitly instead of inheriting it from the fixtures: a previous run (or a
	// concurrent comparison harness) may legitimately have left the flag in the other state, and a test must not
	// fail for a reason it does not control.
	if _, err := h.db.ExecContext(context.Background(),
		`UPDATE warehouse_settings SET factory_plan_enabled = 1 WHERE agent_id = 10`); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = h.db.ExecContext(context.Background(),
			`UPDATE warehouse_settings SET factory_plan_enabled = 1 WHERE agent_id = 10`)
	})
	h.loginAs("agen@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse/sellable?product_id=2")
	data := body["data"].(map[string]any)
	// Fixture: transit 12 + factory_plan 4, plan ENABLED for agent 10.
	if data["transit"].(float64) != 12 || data["factory_plan"].(float64) != 4 {
		t.Errorf("buckets = %v/%v, want 12/4", data["transit"], data["factory_plan"])
	}
	if data["factory_plan_enabled"] != true {
		t.Error("factory_plan_enabled should be true for agent 10")
	}
	if data["sellable_base"].(float64) != 16 {
		t.Errorf("sellable_base = %v, want 16 (12 + 4)", data["sellable_base"])
	}
	if data["physical_stock"].(float64) != 12 {
		t.Errorf("physical_stock = %v, want 12 (transit only)", data["physical_stock"])
	}

	// Disable the plan and the base must drop back to transit only. The change is reverted immediately so the
	// shared fixtures stay stable for other tests.
	if _, err := h.db.ExecContext(t.Context(), `UPDATE warehouse_settings SET factory_plan_enabled = 0 WHERE agent_id = 10`); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		// context.Background(): t.Context() is already cancelled by the time cleanup runs.
		_, _ = h.db.ExecContext(context.Background(), `UPDATE warehouse_settings SET factory_plan_enabled = 1 WHERE agent_id = 10`)
	})
	body = h.getJSON("/api/v1/warehouse/sellable?product_id=2")
	data = body["data"].(map[string]any)
	if data["sellable_base"].(float64) != 12 {
		t.Errorf("with the plan disabled, sellable_base = %v, want 12", data["sellable_base"])
	}
	if data["factory_plan_enabled"] != false {
		t.Error("factory_plan_enabled must report false once disabled")
	}
}

// TestStockListsMetaShape pins the pagination block: these lists carry current_page/last_page/total and NOT
// per_page (the cards endpoints are the ones that include per_page).
func TestStockListsMetaShape(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	for _, path := range []string{"/api/v1/stock/products", "/api/v1/stock/variations", "/api/v1/warehouse-stock"} {
		body := h.getJSON(path)
		meta := body["meta"].(map[string]any)
		for _, key := range []string{"current_page", "last_page", "total"} {
			if _, ok := meta[key]; !ok {
				t.Errorf("%s meta missing %q", path, key)
			}
		}
		if _, ok := meta["per_page"]; ok {
			t.Errorf("%s meta must NOT include per_page (verified against the reference)", path)
		}
	}
}

// TestStockCardsMetaIncludesPerPage is the counterpart rule for the cards endpoints.
func TestStockCardsMetaIncludesPerPage(t *testing.T) {
	h := newHarness(t)
	h.loginAs("gudang@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse/stock-cards")
	meta := body["meta"].(map[string]any)
	if _, ok := meta["per_page"]; !ok {
		t.Error("stock-cards meta MUST include per_page")
	}
}

// TestStockCardsDoNotFallBackToLegacy pins the deliberate asymmetry: the card grid reads warehouse buckets only,
// so a legacy-only target shows transit 0 (while /stock/products reports its 40).
func TestStockCardsDoNotFallBackToLegacy(t *testing.T) {
	h := newHarness(t)
	h.loginAs("gudang@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse/stock-cards")
	cards := body["data"].([]any)
	if len(cards) == 0 {
		t.Fatal("expected stock cards")
	}
	// Product 1 specifically: it holds a `sub` warehouse row (so the card grid sees it) and a legacy
	// commitment row with reserved = 5.
	var simple map[string]any
	for _, c := range cards {
		row := c.(map[string]any)
		if pid, ok := row["product_id"].(float64); ok && pid == 1 {
			simple = row
		}
	}
	if simple == nil {
		t.Fatal("expected product 1's card")
	}
	if simple["transit"].(float64) != 0 {
		t.Errorf("legacy-only target transit = %v, want 0 (cards read warehouse buckets only)", simple["transit"])
	}
	if simple["reserved"].(float64) != 5 {
		t.Errorf("reserved = %v, want 5 (read from the legacy commitment row)", simple["reserved"])
	}
}

// TestSuperAdminMustNameAgent proves there is no implicit branch for the global role.
func TestSuperAdminMustNameAgent(t *testing.T) {
	h := newHarness(t)
	h.loginAs("super@imp010.test", "Secret123!")

	res := h.do(http.MethodGet, "/api/v1/stock/products", nil)
	if res.status != http.StatusUnprocessableEntity {
		t.Fatalf("super_admin without agent_id = %d, want 422", res.status)
	}
	_, message, _, errs := h.decode(res)
	if message == "" {
		t.Error("expected the stock.agent_id_required message")
	}
	payload, ok := errs.(map[string]any)
	if !ok {
		t.Fatalf("errors = %#v", errs)
	}
	if _, ok := payload["agent_id"]; !ok {
		t.Errorf("expected an agent_id field error, got %#v", payload)
	}

	// With an explicit, valid Agent it works.
	ok200 := h.do(http.MethodGet, "/api/v1/stock/products?agent_id=10", nil)
	if ok200.status != http.StatusOK {
		t.Fatalf("super_admin with agent_id = %d, want 200 body=%s", ok200.status, string(ok200.body))
	}
	// A non-Agent id is refused with its own message.
	bad := h.do(http.MethodGet, "/api/v1/stock/products?agent_id=13", nil)
	if bad.status != http.StatusUnprocessableEntity {
		t.Fatalf("agent_id=konsumen = %d, want 422", bad.status)
	}
}

// TestInventoryRoleGates pins the route-level gate for every ported inventory endpoint.
func TestInventoryRoleGates(t *testing.T) {
	cases := []struct {
		email string
		path  string
		want  int
	}{
		// konsumen is not in any stock role group.
		{"konsumen@imp010.test", "/api/v1/stock/products", http.StatusForbidden},
		{"konsumen@imp010.test", "/api/v1/warehouse-stock", http.StatusForbidden},
		{"konsumen@imp010.test", "/api/v1/warehouse/stock-cards", http.StatusForbidden},
		// A sales role is not allowed on the stock grid but IS allowed on sub-stock endpoints.
		{"sales@imp010.test", "/api/v1/stock/products", http.StatusForbidden},
		{"sales@imp010.test", "/api/v1/sub-stock/replenishment-targets", http.StatusForbidden},
		// sales-kurir-sub may not read the agent stock grid.
		{"sub@imp010.test", "/api/v1/stock/products", http.StatusForbidden},
		// an agen may not read the admin/gudang-only cards grid.
		{"agen@imp010.test", "/api/v1/warehouse/stock-cards", http.StatusForbidden},
		// but gudang may.
		{"gudang@imp010.test", "/api/v1/warehouse/stock-cards", http.StatusOK},
	}
	for _, tc := range cases {
		h := newHarness(t)
		h.loginAs(tc.email, "Secret123!")
		res := h.do(http.MethodGet, tc.path, nil)
		if res.status != tc.want {
			t.Errorf("%s on %s = %d, want %d (body=%s)", tc.email, tc.path, res.status, tc.want, string(res.body))
		}
	}
}

// TestSubLocationTenantIsolation proves the explicit tenant scope: another agent's sub-location is a 404 (not a
// 403 and never a payload), the same status Laravel produces after its global scope.
func TestSubLocationTenantIsolation(t *testing.T) {
	h := newHarness(t)

	// Create a second agent + sub-location directly, outside agent 10's branch.
	ctx := context.Background()
	// Idempotent: a previous aborted run may have left this fixture behind.
	_, _ = h.db.ExecContext(ctx, `DELETE FROM warehouse_sub_locations WHERE code = 'SUB-OTHER-01'`)
	_, _ = h.db.ExecContext(ctx, `DELETE FROM users WHERE email = 'agen2-imp010@example.test'`)
	res, err := h.db.ExecContext(ctx, `INSERT INTO users (role_id, parent_id, agent_id, name, email, phone, password, status, created_at, updated_at)
		VALUES (2, NULL, NULL, 'Agen Dua', 'agen2-imp010@example.test', '081100000099',
		        '$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())`)
	if err != nil {
		t.Fatal(err)
	}
	agent2, _ := res.LastInsertId()
	if _, err := h.db.ExecContext(ctx, `UPDATE users SET agent_id = ? WHERE id = ?`, agent2, agent2); err != nil {
		t.Fatal(err)
	}
	res, err = h.db.ExecContext(ctx, `INSERT INTO warehouse_sub_locations
		(agent_id, owner_user_id, code, name, address, contact_number, description, is_active, created_by, created_at, updated_at)
		VALUES (?, NULL, 'SUB-OTHER-01', 'Sub Agen Lain', NULL, NULL, NULL, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, agent2, agent2)
	if err != nil {
		t.Fatal(err)
	}
	otherLocation, _ := res.LastInsertId()
	t.Cleanup(func() {
		_, _ = h.db.ExecContext(ctx, `DELETE FROM warehouse_sub_locations WHERE id = ?`, otherLocation)
		_, _ = h.db.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, agent2)
	})

	h.loginAs("agen@imp010.test", "Secret123!")
	res2 := h.do(http.MethodGet, "/api/v1/warehouse/sub-locations/"+itoa(otherLocation), nil)
	if res2.status != http.StatusNotFound {
		t.Fatalf("foreign sub-location = %d, want 404 (tenant scope, not 403)", res2.status)
	}

	// The list must never contain it either.
	body := h.getJSON("/api/v1/warehouse/sub-locations")
	for _, row := range body["data"].([]any) {
		if row.(map[string]any)["id"].(float64) == float64(otherLocation) {
			t.Error("another agent's sub-location leaked into the list")
		}
	}
}

// TestSubStockRequestsAreScopedToOwnRequests proves a Sales-Kurir-Sub cannot widen its own list by query string.
func TestSubStockRequestsAreScopedToOwnRequests(t *testing.T) {
	h := newHarness(t)
	h.loginAs("sub@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/sub-stock/requests")
	rows := body["data"].([]any)
	if len(rows) == 0 {
		t.Fatal("expected the seeded request owned by the Sales-Kurir-Sub")
	}
	for _, row := range rows {
		requester := row.(map[string]any)["requested_by"].(float64)
		if requester != 16 {
			t.Errorf("list leaked a request from user %v (scope must be requested_by = caller)", requester)
		}
	}

	// The extra query-string filters must not widen the scope.
	body = h.getJSON("/api/v1/sub-stock/requests?status=requested")
	for _, row := range body["data"].([]any) {
		if row.(map[string]any)["requested_by"].(float64) != 16 {
			t.Error("status filter widened the scope")
		}
	}
}

// TestSubStockRequestsAdminSeesBranch is the counterpart: an admin sees the branch, not just its own rows.
func TestSubStockRequestsAdminSeesBranch(t *testing.T) {
	h := newHarness(t)
	h.loginAs("admin@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/sub-stock/requests")
	rows := body["data"].([]any)
	if len(rows) == 0 {
		t.Fatal("an admin must see the branch's requests")
	}
	// The seeded row belongs to user 16, not the admin — proving the scope is the branch.
	if rows[0].(map[string]any)["requested_by"].(float64) != 16 {
		t.Errorf("admin list should include the branch request, got %v", rows[0].(map[string]any)["requested_by"])
	}
}

// TestWarehouseStockResourceShape pins the WarehouseStockResource projection keys.
func TestWarehouseStockResourceShape(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse-stock?per_page=100")
	rows := body["data"].([]any)
	if len(rows) == 0 {
		t.Fatal("expected warehouse stock rows")
	}
	for _, key := range []string{
		"id", "agent_id", "product_id", "product_variation_id", "stock_type", "sub_location_id", "quantity",
		"product", "variation", "sub_location", "product_name", "variation_label", "sku",
		"product_image_url", "updated_at",
	} {
		if _, ok := rows[0].(map[string]any)[key]; !ok {
			t.Errorf("warehouse stock row missing %q", key)
		}
	}

	// Every product-bearing row must resolve its image, even when several rows share the same product
	// (a regression guard: the renderer used to consume a shared map and blank the second row).
	seen := map[float64]bool{}
	for _, row := range rows {
		r := row.(map[string]any)
		pid, ok := r["product_id"].(float64)
		if !ok || seen[pid] {
			continue
		}
		seen[pid] = true
		if r["product_image_url"] == nil {
			t.Errorf("product %v has no image URL although the product has a primary image", pid)
		}
	}
}

// TestSubLocationStocksExposeGeneratedColumns proves the model-style serialisation keeps the generated columns.
func TestSubLocationStocksExposeGeneratedColumns(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	body := h.getJSON("/api/v1/warehouse/sub-locations/1/stocks")
	rows := body["data"].([]any)
	if len(rows) == 0 {
		t.Fatal("expected sub-location stock rows")
	}
	row := rows[0].(map[string]any)
	for _, key := range []string{"id", "agent_id", "product_id", "product_variation_id", "stock_type",
		"sub_location_id", "quantity", "created_at", "updated_at", "target_identity", "location_identity",
		"product", "variation"} {
		if _, ok := row[key]; !ok {
			t.Errorf("sub-location stock row missing %q", key)
		}
	}
}

// TestSellableRejectsMissingTarget documents the ONE place where Go is stricter than Laravel.
func TestSellableRejectsMissingTarget(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	res := h.do(http.MethodGet, "/api/v1/warehouse/sellable", nil)
	if res.status != http.StatusUnprocessableEntity {
		t.Fatalf("no target = %d, want 422 (Laravel 500s here; documented divergence)", res.status)
	}
}

// TestLiteralSiblingRouteIsNotSwallowed guards the {subLocation} pattern: `eligible-owners` is a REAL sibling
// route that this build does not serve, so it must answer 501 (not 404, and never a payload from the show
// handler). This exact bug was found by the parity report disagreeing with the generator.
func TestLiteralSiblingRouteIsNotSwallowed(t *testing.T) {
	h := newHarness(t)
	h.loginAs("agen@imp010.test", "Secret123!")

	res := h.do(http.MethodGet, "/api/v1/warehouse/sub-locations/eligible-owners", nil)
	if res.status != http.StatusNotImplemented {
		t.Fatalf("eligible-owners = %d, want 501 (real route, not ported)", res.status)
	}
	_, _, _, errs := h.decode(res)
	payload, ok := errs.(map[string]any)
	if !ok {
		t.Fatalf("errors = %#v", errs)
	}
	codes, ok := payload["code"].([]any)
	if !ok || len(codes) != 1 || codes[0] != "not_implemented" {
		t.Errorf("errors.code = %#v", payload["code"])
	}

	// A genuinely unknown suffix stays a 404.
	unknown := h.do(http.MethodGet, "/api/v1/warehouse/sub-locations/bukan-angka", nil)
	if unknown.status != http.StatusNotFound {
		t.Errorf("unknown segment = %d, want 404", unknown.status)
	}
}

func itoa(v int64) string {
	if v == 0 {
		return "0"
	}
	var buf [20]byte
	i := len(buf)
	neg := v < 0
	if neg {
		v = -v
	}
	for v > 0 {
		i--
		buf[i] = byte('0' + v%10)
		v /= 10
	}
	if neg {
		i--
		buf[i] = '-'
	}
	return string(buf[i:])
}
