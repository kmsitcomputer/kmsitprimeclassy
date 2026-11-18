package api

import (
	_ "embed"
	"encoding/json"
	"net/http"
	"strings"
	"sync"
)

// parityRoutesJSON is the machine-generated inventory of every /api/v1 route the Laravel app serves
// (tools/parity/parse via `php artisan route:list --json`, REM-11 excluded). It is embedded so the Go
// binary can answer two questions at runtime and in tests:
//
//  1. is this path a REAL API route (→ 501 when not yet ported) or an unknown path (→ 404), and
//  2. how much of the contract does this build actually cover (GET /api/v1/_parity).
type routeRecord struct {
	Method string   `json:"method"`
	URI    string   `json:"uri"`
	Action string   `json:"action"`
	Auth   bool     `json:"auth"`
	Roles  []string `json:"roles"`
}

//go:embed parity_routes.json
var parityRoutesJSON []byte

var (
	inventoryOnce sync.Once
	inventory     []routeRecord
	inventoryErr  error
)

func loadInventory() ([]routeRecord, error) {
	inventoryOnce.Do(func() {
		inventoryErr = json.Unmarshal(parityRoutesJSON, &inventory)
	})
	return inventory, inventoryErr
}

// Inventory returns the embedded Laravel route inventory.
func Inventory() ([]routeRecord, error) { return loadInventory() }

// knownRoute reports whether method+path matches a real Laravel route, and returns that route.
//
// Matching is segment-wise: a Laravel `{param}` segment matches any single non-empty segment. Route
// ordering does not matter because a match is only used to decide 501-vs-404.
func knownRoute(method, path string) (routeRecord, bool) {
	routes, err := loadInventory()
	if err != nil {
		return routeRecord{}, false
	}
	want := strings.Split(strings.Trim(path, "/"), "/")
	for _, rt := range routes {
		if rt.Method != method {
			continue
		}
		have := strings.Split(strings.Trim(rt.URI, "/"), "/")
		if len(have) != len(want) {
			continue
		}
		ok := true
		for i := range have {
			if strings.HasPrefix(have[i], "{") && strings.HasSuffix(have[i], "}") {
				if want[i] == "" {
					ok = false
					break
				}
				continue
			}
			if have[i] != want[i] {
				ok = false
				break
			}
		}
		if ok {
			return rt, true
		}
	}
	return routeRecord{}, false
}

// implementsRoute is the single source of truth for what this build serves itself. It is consulted by the
// router (to decide 501 vs 404) and by the parity report, so the two can never disagree.
func implementsRoute(method, path string) bool {
	switch method + " " + path {
	case
		"GET /api/v1/install/status",
		"GET /api/v1/settings",
		"GET /api/v1/languages",
		"GET /api/v1/categories",
		"GET /api/v1/products",
		"GET /api/v1/articles",
		"GET /api/v1/homepage",
		"GET /api/v1/agents",
		"GET /api/v1/regions/provinces",
		"GET /api/v1/regions/regencies",
		"GET /api/v1/regions/districts",
		"GET /api/v1/regions/villages",
		"POST /api/v1/auth/login",
		"POST /api/v1/auth/register",
		"POST /api/v1/auth/logout",
		"GET /api/v1/auth/me",
		"PATCH /api/v1/profile/password":
		return true
	}
	// Parameterised public reads: exactly `/api/v1/<collection>/<one-segment>`. A plain prefix test would also
	// swallow deeper real routes such as /api/v1/products/{product}/fees, advertising an endpoint this build
	// does not serve.
	if method == http.MethodGet {
		for _, collection := range []string{"products", "articles", "pages"} {
			prefix := "/api/v1/" + collection + "/"
			if !strings.HasPrefix(path, prefix) {
				continue
			}
			rest := strings.TrimPrefix(path, prefix)
			if rest != "" && !strings.Contains(rest, "/") {
				return true
			}
		}
	}
	return false
}
