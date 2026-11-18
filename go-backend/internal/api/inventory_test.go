package api

import (
	"net/http"
	"os"
	"regexp"
	"strings"
	"testing"
)

// TestInventoryIsWellFormed guards the embedded Laravel route inventory: it is generated from
// `php artisan route:list --json` and must stay machine-readable, otherwise the 501-vs-404 distinction (and
// the parity report) silently degrades to "everything is unknown".
func TestInventoryIsWellFormed(t *testing.T) {
	routes, err := loadInventory()
	if err != nil {
		t.Fatalf("inventory: %v", err)
	}
	if len(routes) == 0 {
		t.Fatal("inventory is empty")
	}
	validMethods := map[string]bool{"GET": true, "POST": true, "PUT": true, "PATCH": true, "DELETE": true}
	for _, r := range routes {
		if !validMethods[r.Method] {
			t.Errorf("unexpected method %q for %s", r.Method, r.URI)
		}
		if !strings.HasPrefix(r.URI, "/api/v1/") {
			t.Errorf("uri outside /api/v1: %s", r.URI)
		}
		if strings.Contains(r.URI, "historical") || strings.Contains(r.Action, "Historical") ||
			strings.Contains(r.URI, "report_registry") || strings.Contains(r.Action, "ReportRegistry") {
			t.Errorf("REM-11 route must not be in the baseline inventory: %s %s", r.Method, r.URI)
		}
	}
}

// TestInventoryMatchesBaselineExpectations pins the audited surface. These numbers come from the IMP-010
// baseline audit; if they change, the parity matrix and docs/21 must be updated in the same commit.
func TestInventoryMatchesBaselineExpectations(t *testing.T) {
	routes, err := loadInventory()
	if err != nil {
		t.Fatal(err)
	}
	if len(routes) != 375 {
		t.Errorf("api/v1 route count = %d, want 375 (update docs/21 + the parity matrix if the Laravel surface changed)", len(routes))
	}

	// Every route carries auth metadata and, when authenticated, the route-level role gate is recorded.
	var public, authed int
	for _, r := range routes {
		if r.Auth {
			authed++
		} else {
			public++
		}
	}
	if public != 31 {
		t.Errorf("public route count = %d, want 31", public)
	}
	if authed != 344 {
		t.Errorf("authenticated route count = %d, want 344", authed)
	}
}

// TestImplementedRoutesAreRealRoutes is the anti-drift rule: implementsRoute is consulted by the router, so a
// typo there would advertise an endpoint that no Laravel route ever had (and the parity report would lie).
func TestImplementedRoutesAreRealRoutes(t *testing.T) {
	routes, err := loadInventory()
	if err != nil {
		t.Fatal(err)
	}
	checked := 0
	for _, r := range routes {
		if !implementsRoute(r.Method, r.URI) {
			continue
		}
		checked++
		if _, ok := knownRoute(r.Method, r.URI); !ok {
			t.Errorf("implementsRoute claims %s %s, but it is not in the Laravel inventory", r.Method, r.URI)
		}
	}
	if checked == 0 {
		t.Fatal("no route is marked implemented — the parity report would show 0%")
	}
}

// TestEveryRegisteredRouteIsImplemented is the FORWARD anti-drift rule and the one that would have caught a
// real defect: `POST /warehouse/sub-locations/{id}/assign-owner` was registered in routes() and handled, but
// implementsRoute said "not implemented". That combination is never acceptable — it undercounts the parity
// report and (because ValidateCSRF consults the same predicate) skips CSRF validation on a state-changing
// request. So every route the mux actually serves must be reported as implemented.
func TestEveryRegisteredRouteIsImplemented(t *testing.T) {
	source, err := os.ReadFile("server.go")
	if err != nil {
		t.Fatal(err)
	}
	// s.mux.Handle("METHOD /path", …) and s.mux.HandleFunc("METHOD /path", …)
	re := regexp.MustCompile(`s\.mux\.Handle(?:Func)?\("([A-Z]+) ([^"]+)"`)
	matches := re.FindAllStringSubmatch(string(source), -1)
	if len(matches) < 60 {
		t.Fatalf("only %d registrations found in server.go — the scan pattern is stale", len(matches))
	}
	checked := 0
	for _, m := range matches {
		method, pattern := m[1], m[2]
		if !strings.HasPrefix(pattern, "/api/v1/") {
			continue // /up, /healthz, /sanctum/csrf-cookie are Go-native, not Laravel parity routes
		}
		if pattern == "/api/v1/_parity" {
			continue // machine-readable report, additive by design
		}
		// A concrete path with a representative id for each placeholder.
		concrete := regexp.MustCompile(`\{[^}]+\}`).ReplaceAllString(pattern, "1")
		checked++
		if !implementsRoute(method, concrete) {
			t.Errorf("routes() serves %s %s but implementsRoute(%s, %q) is false — "+
				"the parity report and CSRF validation would both be wrong", method, pattern, method, concrete)
		}
	}
	if checked < 60 {
		t.Fatalf("only %d /api/v1 routes checked — expected the full migrated surface", checked)
	}
}

// TestKnownAndUnknownRouteDiscrimination proves the 501-vs-404 split.
func TestKnownAndUnknownRouteDiscrimination(t *testing.T) {
	cases := []struct {
		method, path string
		known        bool
		implemented  bool
	}{
		{http.MethodGet, "/api/v1/products", true, true},
		{http.MethodGet, "/api/v1/products/kue-lumpur", true, true},
		// The POST literals that sit under the {subLocation} prefix must be implemented — and only as POST.
		{http.MethodPost, "/api/v1/warehouse/sub-locations/1/assign-owner", true, true},
		{http.MethodPost, "/api/v1/warehouse/sub-locations/1/deactivate", true, true},
		{http.MethodGet, "/api/v1/warehouse/sub-locations/1/stocks", true, true},
		{http.MethodPost, "/api/v1/warehouse/sub-locations/1/stocks", false, false},
		{http.MethodPost, "/api/v1/warehouse/sub-locations/1/nonsense", false, false},
		{http.MethodGet, "/api/v1/orders", true, false},
		{http.MethodPost, "/api/v1/orders", true, false},
		{http.MethodPost, "/api/v1/admin/refund-attempts/{attempt}/initiate", true, false},
		{http.MethodGet, "/api/v1/reports/finance-summary", true, false},
		{http.MethodGet, "/api/v1/nope", false, false},
		{http.MethodGet, "/api/v2/products", false, false},
		// A real path with the wrong verb is unknown, not implemented.
		{http.MethodDelete, "/api/v1/products", false, false},
	}
	for _, tc := range cases {
		_, known := knownRoute(tc.method, tc.path)
		if known != tc.known {
			t.Errorf("knownRoute(%s %s) = %v, want %v", tc.method, tc.path, known, tc.known)
		}
		if got := implementsRoute(tc.method, tc.path); got != tc.implemented {
			t.Errorf("implementsRoute(%s %s) = %v, want %v", tc.method, tc.path, got, tc.implemented)
		}
	}
}

// TestParameterisedMatching ensures a {param} segment matches exactly one segment and never a longer path.
func TestParameterisedMatching(t *testing.T) {
	if _, ok := knownRoute(http.MethodGet, "/api/v1/products/a/b/c"); ok {
		t.Error("too many segments must not match a single-parameter route")
	}
	// A trailing slash addresses the collection itself (the router normalises it), never an empty parameter.
	if _, ok := knownRoute(http.MethodGet, "/api/v1/products/"); !ok {
		t.Error("/api/v1/products/ should match the collection route after normalisation")
	}
	if _, ok := knownRoute(http.MethodGet, "/api/v1/pages/tentang-kami"); !ok {
		t.Error("/api/v1/pages/{slug} should match")
	}
}

func TestAreaGrouping(t *testing.T) {
	cases := map[string]string{
		"/api/v1/products":          "products",
		"/api/v1/warehouse/opnames": "warehouse",
		"/api/v1/reports/sales":     "reports",
		"/api/v1":                   "(root)",
	}
	for uri, want := range cases {
		if got := areaOf(uri); got != want {
			t.Errorf("areaOf(%q) = %q, want %q", uri, got, want)
		}
	}
}
