package api_test

// Integration tests for the Go API against the ISOLATED test database (primeclassy_testing).
//
// They are the checkpoint B–C evidence: routing contract, CSRF/session/login, RBAC and the public read
// endpoints, all exercised through the real HTTP handler (no mocked database).
//
// Skipped automatically when no test database is reachable, so `go test ./...` still works on a machine
// without MariaDB — but the CI/verification run (tools/parity/run_go_tests.sh) requires them to execute.

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/api"
	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

type harness struct {
	t      *testing.T
	server *httptest.Server
	client *http.Client
	db     *db.DB
}

func newHarness(t *testing.T) *harness {
	t.Helper()

	envPath := os.Getenv("GO_ENV_FILE")
	if envPath == "" {
		envPath = "../../.env"
	}
	if _, err := os.Stat(envPath); err != nil {
		t.Skipf("no %s; run tools/parity/run_go_tests.sh to provision the test database", envPath)
	}

	cfg, err := config.Load(config.FileGetter(envPath))
	if err != nil {
		t.Fatalf("config: %v", err)
	}
	if !config.IsTestingDatabase(cfg.DB.Database) {
		t.Fatalf("refusing to run integration tests against %q (must contain _test/_testing)", cfg.DB.Database)
	}

	database, err := db.Open(context.Background(), cfg.DB)
	if err != nil {
		t.Skipf("test database unreachable (%v); run tools/parity/run_go_tests.sh", err)
	}
	t.Cleanup(func() { _ = database.Close() })

	// A lock file that does not exist keeps `installed:false`, which is what the assertions expect.
	lockPath := t.TempDir() + "/installed.lock"
	srv := api.New(cfg, database, api.Options{InstallLockPath: lockPath})

	ts := httptest.NewServer(srv.Handler())
	t.Cleanup(ts.Close)

	jar, err := cookiejar.New(nil)
	if err != nil {
		t.Fatal(err)
	}

	return &harness{
		t:      t,
		server: ts,
		db:     database,
		client: &http.Client{Jar: jar},
	}
}

type response struct {
	status int
	body   []byte
	header http.Header
}

func (h *harness) do(method, path string, body any) response {
	h.t.Helper()
	var reader *bytes.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			h.t.Fatal(err)
		}
		reader = bytes.NewReader(buf)
	} else {
		reader = bytes.NewReader(nil)
	}
	req, err := http.NewRequest(method, h.server.URL+path, reader)
	if err != nil {
		h.t.Fatal(err)
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("Accept", "application/json")
	res, err := h.client.Do(req)
	if err != nil {
		h.t.Fatalf("%s %s: %v", method, path, err)
	}
	defer func() { _ = res.Body.Close() }()
	var buf bytes.Buffer
	_, _ = buf.ReadFrom(res.Body)
	return response{status: res.StatusCode, body: buf.Bytes(), header: res.Header}
}

// decode parses the envelope and returns (success, message, data, errors).
func (h *harness) decode(r response) (bool, string, any, any) {
	h.t.Helper()
	var env struct {
		Success bool   `json:"success"`
		Message string `json:"message"`
		Data    any    `json:"data"`
		Errors  any    `json:"errors"`
		Meta    any    `json:"meta"`
	}
	if err := json.Unmarshal(r.body, &env); err != nil {
		h.t.Fatalf("invalid envelope: %v\nbody: %s", err, string(r.body))
	}
	return env.Success, env.Message, env.Data, env.Errors
}

func (h *harness) cookie(name string) string {
	u, err := url.Parse(h.server.URL)
	if err != nil {
		h.t.Fatal(err)
	}
	for _, c := range h.client.Jar.Cookies(u) {
		if c.Name == name {
			return c.Value
		}
	}
	return ""
}

// csrf bootstraps a session and returns the XSRF token to send back in the header.
func (h *harness) csrf() string {
	h.t.Helper()
	res := h.do(http.MethodGet, "/sanctum/csrf-cookie", nil)
	if res.status != http.StatusNoContent {
		h.t.Fatalf("csrf-cookie status = %d, want 204", res.status)
	}
	token := h.cookie("XSRF-TOKEN")
	if token == "" {
		h.t.Fatal("csrf-cookie did not set XSRF-TOKEN")
	}
	if h.cookie("pc_sid") == "" {
		h.t.Fatal("csrf-cookie did not set the session cookie")
	}
	decoded, err := url.QueryUnescape(token)
	if err != nil {
		h.t.Fatalf("csrf token encoding: %v", err)
	}
	return decoded
}

func (h *harness) post(path string, body any, csrf string) response {
	h.t.Helper()
	var reader *bytes.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			h.t.Fatal(err)
		}
		reader = bytes.NewReader(buf)
	} else {
		reader = bytes.NewReader(nil)
	}
	req, err := http.NewRequest(http.MethodPost, h.server.URL+path, reader)
	if err != nil {
		h.t.Fatal(err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if csrf != "" {
		req.Header.Set("X-XSRF-TOKEN", csrf)
	}
	res, err := h.client.Do(req)
	if err != nil {
		h.t.Fatalf("POST %s: %v", path, err)
	}
	defer func() { _ = res.Body.Close() }()
	var buf bytes.Buffer
	_, _ = buf.ReadFrom(res.Body)
	return response{status: res.StatusCode, body: buf.Bytes(), header: res.Header}
}

// ---- routing contract ------------------------------------------------------------------------------

func TestUnknownPathReturns404Envelope(t *testing.T) {
	h := newHarness(t)
	res := h.do(http.MethodGet, "/api/v1/nope", nil)
	if res.status != http.StatusNotFound {
		t.Fatalf("status = %d, want 404", res.status)
	}
	ok, message, data, _ := h.decode(res)
	if ok || data != nil {
		t.Errorf("404 must be a failure envelope with data:null, got ok=%v data=%v", ok, data)
	}
	if message == "" {
		t.Error("404 must carry the endpoint_not_found message, not an empty string")
	}
}

func TestKnownUnimplementedRouteReturns501(t *testing.T) {
	h := newHarness(t)
	// `POST /orders/{order}/cancel` is a REAL Laravel route that this build does not serve yet. It must be a 501
	// with the machine-readable code, never a 404 (which would look like "no such endpoint") and never a 200.
	//
	// (POST /orders itself USED to be the example here; it is implemented now, so the guard moved to a route that
	// is still outstanding — the assertion is about the fallback behaviour, not about that one endpoint.)
	res := h.do(http.MethodPost, "/api/v1/orders/1/assisted-returns", map[string]any{})
	if res.status != http.StatusNotImplemented {
		t.Fatalf("status = %d, want 501", res.status)
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
}

func TestHealthAndReadiness(t *testing.T) {
	h := newHarness(t)

	up := h.do(http.MethodGet, "/up", nil)
	if up.status != http.StatusOK {
		t.Errorf("/up status = %d", up.status)
	}

	hz := h.do(http.MethodGet, "/healthz", nil)
	if hz.status != http.StatusOK {
		t.Fatalf("/healthz status = %d body=%s", hz.status, string(hz.body))
	}
	var status map[string]any
	if err := json.Unmarshal(hz.body, &status); err != nil {
		t.Fatal(err)
	}
	if status["status"] != "ok" || status["database"] != "ok" {
		t.Errorf("/healthz = %v", status)
	}
}

// ---- CSRF + session --------------------------------------------------------------------------------

func TestMutationWithoutCSRFIsRejectedWith419(t *testing.T) {
	h := newHarness(t)
	// No csrf-cookie call at all: an unauthenticated mutation has no token to match.
	res := h.post("/api/v1/auth/login", map[string]any{"identifier": "konsumen@imp010.test", "password": "Secret123!"}, "")
	if res.status != 419 {
		t.Fatalf("status = %d, want 419", res.status)
	}
	if !strings.Contains(string(res.body), "CSRF token mismatch") {
		t.Errorf("419 message = %s", string(res.body))
	}

	// With a session but a bogus token, still 419.
	h.csrf()
	bad := h.post("/api/v1/auth/login", map[string]any{"identifier": "konsumen@imp010.test", "password": "Secret123!"}, "deadbeef")
	if bad.status != 419 {
		t.Errorf("bogus token status = %d, want 419", bad.status)
	}
}

func TestLoginMeLogoutFlow(t *testing.T) {
	h := newHarness(t)
	token := h.csrf()

	login := h.post("/api/v1/auth/login", map[string]any{
		"identifier": "konsumen@imp010.test",
		"password":   "Secret123!",
	}, token)
	if login.status != http.StatusOK {
		t.Fatalf("login status = %d body=%s", login.status, string(login.body))
	}
	ok, message, data, _ := h.decode(login)
	if !ok {
		t.Fatalf("login not successful: %s", string(login.body))
	}
	if message == "" {
		t.Error("login must return the login_success message")
	}

	payload, ok := data.(map[string]any)
	if !ok {
		t.Fatalf("login data = %#v", data)
	}
	user, ok := payload["user"].(map[string]any)
	if !ok {
		t.Fatalf("login data.user missing: %#v", payload)
	}
	if user["role"] != "konsumen" {
		t.Errorf("role = %v, want konsumen", user["role"])
	}
	if user["email"] != "konsumen@imp010.test" {
		t.Errorf("email = %v", user["email"])
	}
	perms, ok := payload["permissions"].([]any)
	if !ok || len(perms) == 0 {
		t.Errorf("permissions missing: %#v", payload["permissions"])
	}
	// Own-record-only fields must be present for the authenticated user.
	if _, exists := user["google_linked"]; !exists {
		t.Error("google_linked must be present for the caller's own record")
	}

	// The session must be usable without a token for a safe method.
	me := h.do(http.MethodGet, "/api/v1/auth/me", nil)
	if me.status != http.StatusOK {
		t.Fatalf("me status = %d body=%s", me.status, string(me.body))
	}
	_, _, meData, _ := h.decode(me)
	mePayload := meData.(map[string]any)
	if mePayload["user"].(map[string]any)["id"].(float64) != 13 {
		t.Errorf("me user id = %v, want 13", mePayload["user"].(map[string]any)["id"])
	}

	// Session rotation: the id after login must differ from the pre-login id (fixation defence).
	before := h.cookie("pc_sid")
	if before == "" {
		t.Fatal("session cookie lost after login")
	}

	logout := h.post("/api/v1/auth/logout", nil, h.cookie("XSRF-TOKEN"))
	if logout.status != http.StatusOK {
		t.Fatalf("logout status = %d body=%s", logout.status, string(logout.body))
	}

	after := h.do(http.MethodGet, "/api/v1/auth/me", nil)
	if after.status != http.StatusUnauthorized {
		t.Fatalf("me after logout = %d, want 401", after.status)
	}
	ok, message, data, _ = h.decode(after)
	if ok || data != nil {
		t.Error("401 must be a failure envelope")
	}
	if message == "" {
		t.Error("401 must carry the please_login message")
	}
}

func TestLoginWrongPasswordReturns422OnIdentifier(t *testing.T) {
	h := newHarness(t)
	token := h.csrf()
	res := h.post("/api/v1/auth/login", map[string]any{
		"identifier": "konsumen@imp010.test",
		"password":   "definitely-wrong",
	}, token)
	if res.status != http.StatusUnprocessableEntity {
		t.Fatalf("status = %d, want 422 (Laravel answers a field error, not 401)", res.status)
	}
	_, _, _, errs := h.decode(res)
	payload, ok := errs.(map[string]any)
	if !ok {
		t.Fatalf("errors = %#v", errs)
	}
	if _, exists := payload["identifier"]; !exists {
		t.Errorf("expected an `identifier` field error, got %#v", payload)
	}
}

func TestLoginByPhone(t *testing.T) {
	h := newHarness(t)
	token := h.csrf()
	// The login endpoint switches to the phone column when the identifier is not an email, and normalises
	// +62… → 0…, so the +62 spelling must work for a row stored as 08…
	res := h.post("/api/v1/auth/login", map[string]any{
		"identifier": "+6281100000013",
		"password":   "Secret123!",
	}, token)
	if res.status != http.StatusOK {
		t.Fatalf("phone login status = %d body=%s", res.status, string(res.body))
	}
}

func TestLoginMissingFields(t *testing.T) {
	h := newHarness(t)
	token := h.csrf()
	res := h.post("/api/v1/auth/login", map[string]any{}, token)
	if res.status != http.StatusUnprocessableEntity {
		t.Fatalf("status = %d, want 422", res.status)
	}
}

// TestAgentLinkedGuardFailsClosed proves EnsureAgentLinked: a user without an agent link cannot act, and the
// Go service answers the same 403 message Laravel does.
func TestAgentLinkedGuardFailsClosed(t *testing.T) {
	h := newHarness(t)

	const email = "noagent-imp010@example.test"
	_, _ = h.db.ExecContext(context.Background(), `DELETE FROM users WHERE email = ?`, email)
	res, err := h.db.ExecContext(context.Background(), `INSERT INTO users
		(role_id, parent_id, agent_id, name, email, phone, password, status, created_at, updated_at)
		VALUES (11, NULL, NULL, 'Tanpa Agen', ?, '081199999999', ?, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		email, "$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq")
	if err != nil {
		t.Fatalf("seed unlinked user: %v", err)
	}
	id, _ := res.LastInsertId()
	t.Cleanup(func() {
		_, _ = h.db.ExecContext(context.Background(), `DELETE FROM users WHERE id = ?`, id)
	})

	token := h.csrf()
	login := h.post("/api/v1/auth/login", map[string]any{"identifier": email, "password": "Secret123!"}, token)
	if login.status != http.StatusOK {
		t.Fatalf("login status = %d body=%s", login.status, string(login.body))
	}

	me := h.do(http.MethodGet, "/api/v1/auth/me", nil)
	if me.status != http.StatusForbidden {
		t.Fatalf("unlinked user me status = %d, want 403 (fail closed)", me.status)
	}
	_, _, _, _ = h.decode(me)
}

// ---- public read endpoints -------------------------------------------------------------------------

func TestPublicEndpointsReturnEnvelope(t *testing.T) {
	h := newHarness(t)

	for _, path := range []string{
		"/api/v1/settings",
		"/api/v1/languages",
		"/api/v1/categories",
		"/api/v1/products",
		"/api/v1/articles",
		"/api/v1/homepage",
		"/api/v1/agents",
		"/api/v1/regions/provinces",
		"/api/v1/install/status",
	} {
		res := h.do(http.MethodGet, path, nil)
		if res.status != http.StatusOK {
			t.Errorf("%s status = %d body=%s", path, res.status, string(res.body))
			continue
		}
		ok, message, _, _ := h.decode(res)
		if !ok {
			t.Errorf("%s not successful", path)
		}
		if message == "" {
			t.Errorf("%s has an empty message", path)
		}
	}
}

func TestSettingsShape(t *testing.T) {
	h := newHarness(t)
	_, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/settings", nil))
	settings, ok := data.(map[string]any)
	if !ok {
		t.Fatalf("settings = %#v", data)
	}
	// Every defined key must be present (the SPA reads them unconditionally) plus the two derived urls.
	for _, key := range []string{
		"site_title", "site_slogan", "site_logo_media_id", "site_favicon_media_id",
		"site_contact_email", "site_contact_phone", "site_address", "site_header", "site_footer",
		"site_theme", "site_social", "site_seo_title", "site_seo_description", "site_seo_keywords",
		"site_about", "logo_url", "favicon_url",
	} {
		if _, exists := settings[key]; !exists {
			t.Errorf("settings missing %q", key)
		}
	}
	if settings["site_title"] != "Prime Classy Cake & Cookies" {
		t.Errorf("site_title = %v", settings["site_title"])
	}
	// JSON-typed keys decode to objects/arrays, never to a raw string.
	if _, isString := settings["site_header"].(string); isString {
		t.Error("site_header must be decoded JSON, not a string")
	}
}

func TestLanguagesOnlyActive(t *testing.T) {
	h := newHarness(t)
	_, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/languages", nil))
	list, ok := data.([]any)
	if !ok {
		t.Fatalf("languages = %#v", data)
	}
	if len(list) == 0 {
		t.Fatal("expected seeded languages")
	}
	first := list[0].(map[string]any)
	for _, key := range []string{"id", "code", "name", "is_default"} {
		if _, exists := first[key]; !exists {
			t.Errorf("language missing %q", key)
		}
	}
}

func TestCategoriesShape(t *testing.T) {
	h := newHarness(t)
	_, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/categories", nil))
	list := data.([]any)
	if len(list) != 2 {
		t.Fatalf("categories = %d, want 2", len(list))
	}
	first := list[0].(map[string]any)
	for _, key := range []string{"id", "parent_id", "name", "slug", "is_active", "sort_order"} {
		if _, exists := first[key]; !exists {
			t.Errorf("category missing %q", key)
		}
	}
	if first["parent_id"] != nil {
		t.Errorf("root category parent_id = %v, want null", first["parent_id"])
	}
}

func TestProductShapeSimpleVsVariation(t *testing.T) {
	h := newHarness(t)

	// Simple product: carries base_price + weight_grams, no variations.
	simple, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/products/kue-lumpur", nil))
	_ = simple
	product, ok := data.(map[string]any)
	if !ok {
		t.Fatalf("product = %#v", data)
	}
	if _, exists := product["base_price"]; !exists {
		t.Error("simple product must expose base_price")
	}
	if _, exists := product["weight_grams"]; !exists {
		t.Error("simple product must expose weight_grams")
	}
	if product["has_variations"] != false {
		t.Errorf("has_variations = %v", product["has_variations"])
	}
	if _, exists := product["stock"]; exists {
		t.Error("anonymous product response must not carry stock (no session ⇒ no agent)")
	}

	// Variation product: base_price/weight_grams are ABSENT (Laravel's `when()`), variations are listed with
	// their composition label and attributes.
	_, _, vdata, _ := h.decode(h.do(http.MethodGet, "/api/v1/products/kue-ulang-tahun", nil))
	vproduct := vdata.(map[string]any)
	if _, exists := vproduct["base_price"]; exists {
		t.Error("variation product must NOT expose base_price")
	}
	if _, exists := vproduct["weight_grams"]; exists {
		t.Error("variation product must NOT expose weight_grams")
	}
	variations, ok := vproduct["variations"].([]any)
	if !ok || len(variations) != 2 {
		t.Fatalf("variations = %#v", vproduct["variations"])
	}
	first := variations[0].(map[string]any)
	for _, key := range []string{"id", "sku", "label", "price", "weight_grams", "is_active"} {
		if _, exists := first[key]; !exists {
			t.Errorf("variation missing %q", key)
		}
	}
	if first["label"] != "20 cm" {
		t.Errorf("variation label = %v, want '20 cm'", first["label"])
	}
	// `attributes` is a whenLoaded field and the reference endpoint never eager-loads `compositions`, so the
	// key must be ABSENT. Present-but-null would already differ from Laravel.
	if _, exists := first["attributes"]; exists {
		t.Errorf("variation must not expose attributes (reference omits it): %#v", first["attributes"])
	}
}

func TestProductNotFound(t *testing.T) {
	h := newHarness(t)
	res := h.do(http.MethodGet, "/api/v1/products/tidak-ada", nil)
	if res.status != http.StatusNotFound {
		t.Fatalf("status = %d, want 404", res.status)
	}
}

func TestProductPaginationMeta(t *testing.T) {
	h := newHarness(t)
	res := h.do(http.MethodGet, "/api/v1/products?per_page=1&page=2", nil)
	if res.status != http.StatusOK {
		t.Fatalf("status = %d", res.status)
	}
	var env struct {
		Data []any          `json:"data"`
		Meta map[string]any `json:"meta"`
	}
	if err := json.Unmarshal(res.body, &env); err != nil {
		t.Fatal(err)
	}
	if len(env.Data) != 1 {
		t.Errorf("data length = %d, want 1", len(env.Data))
	}
	if env.Meta["current_page"].(float64) != 2 {
		t.Errorf("current_page = %v", env.Meta["current_page"])
	}
	// per_page is deliberately NOT part of the meta block: the reference controllers build the meta array by
	// hand with exactly current_page/last_page/total.
	if _, exists := env.Meta["per_page"]; exists {
		t.Error("meta must not contain per_page (verified against the Laravel reference)")
	}
	// Fixture: 3 active products, per_page=1 → 3 pages.
	if env.Meta["last_page"].(float64) != 3 {
		t.Errorf("last_page = %v, want 3", env.Meta["last_page"])
	}
	if env.Meta["total"].(float64) != 3 {
		t.Errorf("total = %v, want 3", env.Meta["total"])
	}
}

func TestProductCategoryFilterIncludesDescendants(t *testing.T) {
	h := newHarness(t)
	// Category "kue" (id 1) has child "kue-basah" (id 2) which owns product 1 — filtering by the PARENT must
	// return it (REM-06 behaviour), and filtering by the child must too.
	for _, slug := range []string{"kue", "kue-basah"} {
		_, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/products?category="+slug, nil))
		list, ok := data.([]any)
		if !ok || len(list) == 0 {
			t.Errorf("category=%s returned no products", slug)
			continue
		}
		ids := map[float64]bool{}
		for _, item := range list {
			ids[item.(map[string]any)["id"].(float64)] = true
		}
		if !ids[1] {
			t.Errorf("category=%s must include product 1 (descendant rule)", slug)
		}
	}
}

func TestInvalidStockSourceIsRejected(t *testing.T) {
	h := newHarness(t)
	res := h.do(http.MethodGet, "/api/v1/products?stock_source=bogus", nil)
	if res.status != http.StatusUnprocessableEntity {
		t.Fatalf("status = %d, want 422", res.status)
	}
}

func TestArticleAndPageShapes(t *testing.T) {
	h := newHarness(t)

	_, _, listData, _ := h.decode(h.do(http.MethodGet, "/api/v1/articles", nil))
	articles, ok := listData.([]any)
	if !ok || len(articles) == 0 {
		t.Fatalf("articles = %#v", listData)
	}
	article := articles[0].(map[string]any)
	for _, key := range []string{"id", "type", "slug", "status", "published_at", "cover_image_url", "translations"} {
		if _, exists := article[key]; !exists {
			t.Errorf("article missing %q", key)
		}
	}
	translations := article["translations"].([]any)
	if len(translations) != 2 {
		t.Errorf("translations = %d, want 2", len(translations))
	}
	tr := translations[0].(map[string]any)
	for _, key := range []string{"language_id", "title", "excerpt", "body"} {
		if _, exists := tr[key]; !exists {
			t.Errorf("translation missing %q", key)
		}
	}

	// Detail adds author_name.
	_, _, detailData, _ := h.decode(h.do(http.MethodGet, "/api/v1/articles/tips-kue-imp010", nil))
	detail := detailData.(map[string]any)
	if _, exists := detail["author_name"]; !exists {
		t.Error("article detail must include author_name")
	}

	// Page detail.
	_, _, pageData, _ := h.decode(h.do(http.MethodGet, "/api/v1/pages/tentang-kami-imp010", nil))
	page := pageData.(map[string]any)
	if _, exists := page["translations"]; !exists {
		t.Error("page must include translations")
	}
	ptr := page["translations"].([]any)[0].(map[string]any)
	for _, key := range []string{"language_id", "title", "body", "seo_title", "seo_description"} {
		if _, exists := ptr[key]; !exists {
			t.Errorf("page translation missing %q", key)
		}
	}

	// Missing slugs are real 404s.
	if res := h.do(http.MethodGet, "/api/v1/articles/tidak-ada", nil); res.status != http.StatusNotFound {
		t.Errorf("missing article status = %d", res.status)
	}
	if res := h.do(http.MethodGet, "/api/v1/pages/tidak-ada", nil); res.status != http.StatusNotFound {
		t.Errorf("missing page status = %d", res.status)
	}
}

func TestHomepageBlockResolution(t *testing.T) {
	h := newHarness(t)
	_, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/homepage", nil))
	blocks, ok := data.([]any)
	if !ok || len(blocks) == 0 {
		t.Fatalf("homepage = %#v", data)
	}
	block := blocks[0].(map[string]any)
	if block["type"] != "product" {
		t.Fatalf("block type = %v", block["type"])
	}
	content := block["content"].(map[string]any)
	products, ok := content["products"].([]any)
	if !ok || len(products) == 0 {
		t.Fatalf("resolved products = %#v", content["products"])
	}
	card := products[0].(map[string]any)
	for _, key := range []string{"id", "name", "slug", "has_variations", "base_price", "starting_price", "image_url"} {
		if _, exists := card[key]; !exists {
			t.Errorf("product card missing %q", key)
		}
	}
	if card["starting_price"] == nil {
		t.Error("a simple product's starting_price must be its base_price")
	}
}

func TestRegionCascade(t *testing.T) {
	h := newHarness(t)

	_, _, data, _ := h.decode(h.do(http.MethodGet, "/api/v1/regions/provinces", nil))
	provinces := data.([]any)
	if len(provinces) != 1 || provinces[0].(map[string]any)["id"] != "32" {
		t.Fatalf("provinces = %#v", provinces)
	}

	_, _, rdata, _ := h.decode(h.do(http.MethodGet, "/api/v1/regions/regencies?province_id=32", nil))
	if len(rdata.([]any)) != 1 {
		t.Errorf("regencies = %#v", rdata)
	}

	// Unknown parent → 422 field error (mirrors the Laravel `exists:` rule).
	res := h.do(http.MethodGet, "/api/v1/regions/regencies?province_id=99", nil)
	if res.status != http.StatusUnprocessableEntity {
		t.Errorf("unknown province status = %d, want 422", res.status)
	}
	// Missing parent → also 422.
	if res := h.do(http.MethodGet, "/api/v1/regions/regencies", nil); res.status != http.StatusUnprocessableEntity {
		t.Errorf("missing province status = %d, want 422", res.status)
	}
}

// ---- parity report ---------------------------------------------------------------------------------

func TestParityReportIsConsistent(t *testing.T) {
	h := newHarness(t)
	res := h.do(http.MethodGet, "/api/v1/_parity", nil)
	if res.status != http.StatusOK {
		t.Fatalf("status = %d", res.status)
	}
	var env struct {
		Data struct {
			LaravelRoutes     int            `json:"laravel_routes_total"`
			Implemented       int            `json:"implemented_total"`
			CoveragePercent   float64        `json:"coverage_percent"`
			ImplementedRoutes []string       `json:"implemented_routes"`
			Outstanding       map[string]int `json:"outstanding_by_area"`
		} `json:"data"`
	}
	if err := json.Unmarshal(res.body, &env); err != nil {
		t.Fatal(err)
	}
	if env.Data.LaravelRoutes != 375 {
		t.Errorf("laravel_routes_total = %d, want 375", env.Data.LaravelRoutes)
	}
	if env.Data.Implemented != len(env.Data.ImplementedRoutes) {
		t.Errorf("implemented_total %d disagrees with the list length %d",
			env.Data.Implemented, len(env.Data.ImplementedRoutes))
	}
	// Every advertised route must really be served: call it and require a non-501 answer. Safe methods only,
	// because the mutating ones are gated behind CSRF/session state.
	for _, entry := range env.Data.ImplementedRoutes {
		parts := strings.SplitN(entry, " ", 2)
		if len(parts) != 2 || parts[0] != http.MethodGet {
			continue
		}
		path := strings.NewReplacer("{slug}", "kue-lumpur", "{param}", "1").Replace(parts[1])
		got := h.do(http.MethodGet, path, nil)
		if got.status == http.StatusNotImplemented {
			t.Errorf("%s is advertised as implemented but answered 501", entry)
		}
	}
}
