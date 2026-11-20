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
	inventoryOnce  sync.Once
	routeInventory []routeRecord
	inventoryErr   error
)

func loadInventory() ([]routeRecord, error) {
	inventoryOnce.Do(func() {
		inventoryErr = json.Unmarshal(parityRoutesJSON, &routeInventory)
	})
	return routeInventory, inventoryErr
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
		"GET /api/v1/admin/returns",
		"GET /api/v1/settings",
		"GET /api/v1/languages",
		"GET /api/v1/categories",
		"POST /api/v1/categories",
		"PATCH /api/v1/categories/{category}",
		"DELETE /api/v1/categories/{category}",
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
		"PATCH /api/v1/profile/password",
		"GET /api/v1/stock/products",
		"GET /api/v1/stock/variations",
		"GET /api/v1/warehouse-stock",
		"GET /api/v1/warehouse/sellable",
		"GET /api/v1/warehouse/settings",
		"GET /api/v1/warehouse/sub-locations",
		"GET /api/v1/warehouse/stock-cards",
		"GET /api/v1/sub-stock/my",
		"GET /api/v1/sub-stock/replenishment-targets",
		"GET /api/v1/sub-stock/requests",
		"POST /api/v1/sub-stock/requests",
		"POST /api/v1/stock/adjust",
		"PATCH /api/v1/warehouse/settings/factory-plan",
		"GET /api/v1/warehouse/transfers",
		"POST /api/v1/warehouse/transfers",
		"GET /api/v1/admin/languages",
		"POST /api/v1/admin/languages",
		"GET /api/v1/admin/settings",
		"PUT /api/v1/admin/settings",
		"POST /api/v1/warehouse/transfers/plan-to-transit",
		"GET /api/v1/warehouse/sub-locations/eligible-owners",
		"GET /api/v1/warehouse/opnames",
		"POST /api/v1/warehouse/opnames",
		"POST /api/v1/warehouse/sub-locations",
		"GET /api/v1/addresses",
		"POST /api/v1/addresses",
		"GET /api/v1/promo/vouchers",
		"POST /api/v1/promo/vouchers",
		"GET /api/v1/promo/discounts",
		"POST /api/v1/promo/discounts",
		"GET /api/v1/checkout/steps",
		"POST /api/v1/checkout/quote",
		"GET /api/v1/orders",
		"GET /api/v1/orders/regions",
		"POST /api/v1/orders",
		"POST /api/v1/orders/{order}/cancel",
		"POST /api/v1/orders/{order}/items",
		"PATCH /api/v1/orders/{order}/items/{item}/fulfillment",
		"PATCH /api/v1/orders/{order}/items/{item}/reschedule",
		"GET /api/v1/orders/{order}/items/{item}/cancel-eligibility",
		"POST /api/v1/orders/{order}/items/{item}/cancel-undelivered",
		"POST /api/v1/orders/{order}/payment/proof",
		"POST /api/v1/orders/{order}/payment/cod-proof",
		"POST /api/v1/orders/{order}/payment/installment",
		"GET /api/v1/payment-proofs/{verification}",
		"PUT /api/v1/orders/{order}/due-date",
		"PATCH /api/v1/orders/{order}/payment/cod",
		"POST /api/v1/orders/{order}/payment/verify",
		"POST /api/v1/orders/{order}/payment/settle",
		"POST /api/v1/return-items/{returnItem}/receipt-allocations",
		"PATCH /api/v1/admin/cod-payment-proofs/{codPaymentProof}/confirm",
		"GET /api/v1/warehouse/stock-addition-requests",
		"POST /api/v1/warehouse/stock-addition-requests",
		"POST /api/v1/warehouse/sub-adjustment-requests",
		"GET /api/v1/warehouse/handovers/{handover}/print",
		"GET /api/v1/warehouse/fulfillment-proposals",
		"POST /api/v1/warehouse/stock-requests/{stockRequest}/fulfill",
		"POST /api/v1/warehouse/stock-requests/{stockRequest}/proposals":
		return true
	case "GET /api/v1/warehouse/orders/diproses":
		return true
	case "POST /api/v1/crm/customers/{customer}/orders":
		return true
	case "GET /api/v1/warehouse/returns":
		return true
	}
	if method == http.MethodGet && strings.HasPrefix(path, "/api/v1/warehouse/orders/") {
		rest := strings.TrimPrefix(path, "/api/v1/warehouse/orders/")
		return strings.HasSuffix(rest, "/stock-request") && strings.Count(rest, "/") == 1
	}
	if method == http.MethodPost && strings.HasPrefix(path, "/api/v1/warehouse/returns/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/warehouse/returns/"), "/")
		return len(parts) == 2 && parts[0] != "" && (parts[1] == "inspect" || parts[1] == "finalize")
	}
	if method == http.MethodPost && strings.HasPrefix(path, "/api/v1/return-items/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/return-items/"), "/")
		return len(parts) == 2 && parts[0] != "" && parts[1] == "receipt-allocations"
	}
	if method == http.MethodGet && strings.HasPrefix(path, "/api/v1/admin/returns/") {
		rest := strings.TrimPrefix(path, "/api/v1/admin/returns/")
		return rest != "" && !strings.Contains(rest, "/")
	}
	if method == http.MethodPatch && strings.HasPrefix(path, "/api/v1/admin/returns/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/admin/returns/"), "/")
		return len(parts) == 2 && parts[0] != "" && (parts[1] == "review" || parts[1] == "pickup-schedule")
	}
	if method == http.MethodGet && path == "/api/v1/dispatch/returns" {
		return true
	}
	if method == http.MethodPatch && strings.HasPrefix(path, "/api/v1/admin/return-items/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/admin/return-items/"), "/")
		return len(parts) == 2 && parts[0] != "" && parts[1] == "refund"
	}
	if matchesDomainERoute(method, path) || matchesDomainEReadRoute(method, path) || matchesGLMLaravelOnlyRoute(method, path) {
		return true
	}
	for _, collection := range []string{"vouchers", "discounts"} {
		prefix := "/api/v1/promo/" + collection + "/"
		if (method == http.MethodPatch || method == http.MethodDelete) && strings.HasPrefix(path, prefix) {
			rest := strings.TrimPrefix(path, prefix)
			return rest != "" && !strings.Contains(rest, "/")
		}
	}
	if (method == http.MethodPatch || method == http.MethodDelete) && strings.HasPrefix(path, "/api/v1/categories/") {
		rest := strings.TrimPrefix(path, "/api/v1/categories/")
		return rest != "" && !strings.Contains(rest, "/")
	}
	if (method == http.MethodPatch || method == http.MethodDelete) && strings.HasPrefix(path, "/api/v1/addresses/") {
		rest := strings.TrimPrefix(path, "/api/v1/addresses/")
		return rest != "" && !strings.Contains(rest, "/")
	}
	// /api/v1/warehouse/stock-addition-requests/<id> and /<id>/(approve|reject).
	if strings.HasPrefix(path, "/api/v1/warehouse/stock-addition-requests/") {
		rest := strings.TrimPrefix(path, "/api/v1/warehouse/stock-addition-requests/")
		parts := strings.Split(rest, "/")
		if len(parts) == 1 && parts[0] != "" && method == http.MethodGet {
			return true
		}
		if len(parts) == 2 && parts[0] != "" && method == http.MethodPost &&
			(parts[1] == "approve" || parts[1] == "reject") {
			return true
		}
		return false
	}
	// /api/v1/orders/<id> (GET show) and /<id>/cancel (POST) and /<id>/status (PATCH).
	if strings.HasPrefix(path, "/api/v1/orders/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/orders/"), "/")
		if parts[0] == "" {
			return false
		}
		if len(parts) == 1 && method == http.MethodGet {
			return true
		}
		if len(parts) == 2 {
			if method == http.MethodPost && parts[1] == "items" {
				return true
			}
			if method == http.MethodPost && parts[1] == "cancel" {
				return true
			}
			if method == http.MethodPatch && parts[1] == "status" {
				return true
			}
			if method == http.MethodPut && parts[1] == "due-date" {
				return true
			}
		}
		// /api/v1/orders/<id>/payment/(proof|cod-proof|installment|cod|verify|settle).
		if len(parts) == 3 && parts[1] == "payment" {
			switch parts[2] {
			case "proof", "cod-proof", "installment", "verify", "settle":
				return method == http.MethodPost
			case "cod":
				return method == http.MethodPatch
			}
		}
		// /api/v1/orders/<id>/items/<item>/(fulfillment|reschedule|cancel-eligibility|cancel-undelivered).
		if len(parts) == 4 && parts[0] != "" && parts[1] == "items" && parts[2] != "" {
			switch parts[3] {
			case "fulfillment", "reschedule":
				return method == http.MethodPatch
			case "cancel-eligibility":
				return method == http.MethodGet
			case "cancel-undelivered":
				return method == http.MethodPost
			}
		}
	}
	// /api/v1/payment-proofs/<id> (GET) and /api/v1/admin/cod-payment-proofs/<id>/confirm (PATCH).
	if strings.HasPrefix(path, "/api/v1/payment-proofs/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/payment-proofs/"), "/")
		return len(parts) == 1 && parts[0] != "" && method == http.MethodGet
	}
	if strings.HasPrefix(path, "/api/v1/admin/cod-payment-proofs/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/admin/cod-payment-proofs/"), "/")
		return len(parts) == 2 && parts[0] != "" && parts[1] == "confirm" && method == http.MethodPatch
	}
	// /api/v1/sub-stock/requests/<id> and /<id>/(approve|reject|cancel|execute|receive).
	if strings.HasPrefix(path, "/api/v1/sub-stock/requests/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/sub-stock/requests/"), "/")
		if parts[0] == "" {
			return false
		}
		if len(parts) == 1 && method == http.MethodGet {
			return true
		}
		if len(parts) == 2 && parts[0] != "" && method == http.MethodPost &&
			(parts[1] == "approve" || parts[1] == "reject" || parts[1] == "cancel" ||
				parts[1] == "execute" || parts[1] == "receive") {
			return true
		}
		return false
	}
	// /api/v1/warehouse/handovers/<id> and /<id>/print.
	if strings.HasPrefix(path, "/api/v1/warehouse/handovers/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/warehouse/handovers/"), "/")
		if parts[0] == "" {
			return false
		}
		if len(parts) == 1 && method == http.MethodGet {
			return true
		}
		if len(parts) == 2 && parts[1] == "print" && method == http.MethodGet {
			return true
		}
		return false
	}
	// /api/v1/warehouse/fulfillment-proposals/<id> and /<id>/(receive|approve|reject).
	if strings.HasPrefix(path, "/api/v1/warehouse/fulfillment-proposals/") {
		rest := strings.TrimPrefix(path, "/api/v1/warehouse/fulfillment-proposals/")
		parts := strings.Split(rest, "/")
		if len(parts) == 1 && parts[0] != "" && method == http.MethodGet {
			return true
		}
		if len(parts) == 2 && parts[0] != "" && method == http.MethodPost &&
			(parts[1] == "receive" || parts[1] == "approve" || parts[1] == "reject") {
			return true
		}
		return false
	}
	// /api/v1/warehouse/stock-requests/<id>/(fulfill|proposals).
	if strings.HasPrefix(path, "/api/v1/warehouse/stock-requests/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/warehouse/stock-requests/"), "/")
		if len(parts) == 2 && parts[0] != "" && method == http.MethodPost &&
			(parts[1] == "fulfill" || parts[1] == "proposals") {
			return true
		}
		return false
	}
	// /api/v1/warehouse/stock-request-items/<id>/resolve-discrepancy.
	if strings.HasPrefix(path, "/api/v1/warehouse/stock-request-items/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/warehouse/stock-request-items/"), "/")
		return len(parts) == 2 && parts[0] != "" && method == http.MethodPost && parts[1] == "resolve-discrepancy"
	}
	// /api/v1/warehouse/opnames/<id> and /<id>/(count|submit|approve|cancel|reject).
	if strings.HasPrefix(path, "/api/v1/warehouse/opnames/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/warehouse/opnames/"), "/")
		if len(parts) == 1 && parts[0] != "" && method == http.MethodGet {
			return true
		}
		if len(parts) == 2 && method == http.MethodPost &&
			(parts[1] == "submit" || parts[1] == "approve" || parts[1] == "cancel" || parts[1] == "reject") {
			return true
		}
		if len(parts) == 2 && method == http.MethodPatch && parts[1] == "count" {
			return true
		}
		return false
	}
	// /api/v1/admin/languages/<id> and /<id>/(set-default|toggle-active).
	if method == http.MethodPatch && strings.HasPrefix(path, "/api/v1/admin/languages/") {
		parts := strings.Split(strings.TrimPrefix(path, "/api/v1/admin/languages/"), "/")
		if len(parts) == 1 && parts[0] != "" {
			return true
		}
		if len(parts) == 2 && (parts[1] == "set-default" || parts[1] == "toggle-active") {
			return true
		}
		return false
	}
	// /api/v1/warehouse/transfers/<id> and /<id>/(approve|cancel|reject) — literal ids only.
	if (method == http.MethodGet || method == http.MethodPost) && strings.HasPrefix(path, "/api/v1/warehouse/transfers/") {
		rest := strings.TrimPrefix(path, "/api/v1/warehouse/transfers/")
		parts := strings.Split(rest, "/")
		if parts[0] == "" || parts[0] == "plan-to-transit" {
			return false // plan-to-transit is a real route that is NOT ported
		}
		if len(parts) == 1 && method == http.MethodGet {
			return true // show
		}
		if len(parts) == 2 && method == http.MethodPost &&
			(parts[1] == "approve" || parts[1] == "cancel" || parts[1] == "reject") {
			return true
		}
		return false
	}
	// Deeper parameterised inventory routes: `/api/v1/warehouse/sub-locations/<id>[/<tail>]`.
	//
	// The method is part of the match: `assign-owner`/`deactivate` are POST-only literals while
	// `{subLocation}`/`stocks`/`stock-cards` are GET (and PATCH for the id itself). An outer
	// `method is GET or PATCH` gate used to make the two POST literals unreachable here, which silently
	// admitted them through the router while this predicate said "not implemented" — that skipped CSRF
	// validation on two state-changing endpoints and undercounted the parity report. Keep the verb check
	// inside each branch, never around the whole block.
	if strings.HasPrefix(path, "/api/v1/warehouse/sub-locations/") {
		rest := strings.TrimPrefix(path, "/api/v1/warehouse/sub-locations/")
		parts := strings.Split(rest, "/")
		if len(parts) == 1 && parts[0] != "" {
			if method != http.MethodGet && method != http.MethodPatch {
				return false
			}
			// `eligible-owners` is a literal sibling with its own route; it must not be swallowed by the
			// {subLocation} pattern here.
			return parts[0] != "eligible-owners"
		}
		if len(parts) == 2 && parts[0] != "" {
			switch {
			case method == http.MethodGet && (parts[1] == "stocks" || parts[1] == "stock-cards"):
				return true
			case method == http.MethodPost && (parts[1] == "assign-owner" || parts[1] == "deactivate"):
				return true
			}
		}
		return false
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

func matchesDomainERoute(method, path string) bool {
	exact := map[string]bool{
		"GET /api/v1/manage/products":                   true,
		"POST /api/v1/products":                         true,
		"GET /api/v1/users":                             true,
		"POST /api/v1/users":                            true,
		"GET /api/v1/agent/store-profile":               true,
		"PUT /api/v1/agent/store-profile":               true,
		"GET /api/v1/agent/payment-methods":             true,
		"GET /api/v1/agent/shipping-tariff":             true,
		"PUT /api/v1/agent/shipping-tariff":             true,
		"GET /api/v1/agent/shipping-providers":          true,
		"GET /api/v1/agent/whatsapp/scope":              true,
		"GET /api/v1/agent/whatsapp/events":             true,
		"GET /api/v1/agent/whatsapp/test-numbers":       true,
		"POST /api/v1/agent/whatsapp/test-numbers":      true,
		"PATCH /api/v1/profile":                         true,
		"GET /api/v1/profile/email/verification":        true,
		"PATCH /api/v1/profile/referral-code":           true,
		"POST /api/v1/profile/referral-code/regenerate": true,
		"DELETE /api/v1/profile/referral-code":          true,
		"GET /api/v1/cms/homepage-blocks":               true,
		"POST /api/v1/cms/homepage-blocks":              true,
		"PATCH /api/v1/cms/homepage-blocks/reorder":     true,
		"GET /api/v1/cms/articles":                      true,
		"POST /api/v1/cms/articles":                     true,
		"GET /api/v1/cms/pages":                         true,
		"POST /api/v1/cms/pages":                        true,
	}
	if exact[method+" "+path] {
		return true
	}
	p := strings.Split(strings.TrimPrefix(path, "/api/v1/"), "/")
	if len(p) == 0 {
		return false
	}
	switch p[0] {
	case "admin":
		return method == http.MethodPost && ((len(p) == 4 && p[1] == "refund-attempts" && p[2] != "" && p[3] == "complete") || (len(p) == 5 && p[1] == "refund-obligations" && p[2] != "" && p[3] == "needs-verification" && p[4] == "resolve") || (len(p) == 5 && p[1] == "refund-obligations" && p[2] != "" && p[3] == "deferred" && (p[4] == "request" || p[4] == "materialize")))
	case "orders":
		return method == http.MethodPost && len(p) == 3 && p[1] != "" && (p[2] == "assisted-returns" || p[2] == "returns")
	case "referral":
		return method == http.MethodGet && len(p) == 2 && p[1] != ""
	case "users":
		if len(p) == 2 {
			return p[1] != "" && (method == http.MethodGet || method == http.MethodPatch || method == http.MethodDelete)
		}
		if len(p) == 3 {
			return p[1] != "" && method == http.MethodPatch &&
				(p[2] == "reassign-referral" || p[2] == "convert-to-sales-kurir-sub" || p[2] == "convert-to-sales-kurir")
		}
	case "manage":
		return method == http.MethodGet && len(p) == 3 && p[1] == "products" && p[2] != ""
	case "products":
		if len(p) == 2 {
			return p[1] != "" && (method == http.MethodPatch || method == http.MethodDelete)
		}
		if len(p) == 3 && p[1] != "" {
			if p[2] == "variations" {
				return method == http.MethodPost
			}
			if p[2] == "fees" {
				return method == http.MethodGet || method == http.MethodPut
			}
			if p[2] == "permanent" {
				return method == http.MethodDelete
			}
		}
		if len(p) == 4 && p[1] != "" && p[2] == "images" {
			return p[3] != "" && (p[3] == "order" && method == http.MethodPut ||
				p[3] != "order" && (method == http.MethodPatch || method == http.MethodDelete))
		}
		if len(p) == 5 && p[1] != "" && p[2] == "variations" && p[3] != "" {
			if method == http.MethodPatch || method == http.MethodDelete {
				return true
			}
			return (method == http.MethodGet || method == http.MethodPut) && p[4] == "fees"
		}
	case "cms":
		if len(p) == 3 && p[2] != "" && (p[1] == "articles" || p[1] == "pages" || p[1] == "homepage-blocks") {
			if p[1] == "homepage-blocks" && p[2] == "reorder" {
				return method == http.MethodPatch
			}
			return method == http.MethodPatch || method == http.MethodDelete
		}
	case "agent":
		rest := strings.TrimPrefix(path, "/api/v1/agent/")
		if strings.HasPrefix(rest, "payment-methods/") {
			q := strings.Split(rest, "/")
			return len(q) == 3 && q[1] != "" &&
				((method == http.MethodPatch && q[2] == "toggle") || (method == http.MethodPut && q[2] == "config"))
		}
		if strings.HasPrefix(rest, "shipping-providers/") {
			q := strings.Split(rest, "/")
			if len(q) == 2 {
				return q[1] != "" && (method == http.MethodPatch || method == http.MethodGet)
			}
			if len(q) == 3 {
				return q[1] != "" &&
					((method == http.MethodPut && (q[2] == "config" || q[2] == "couriers")) ||
						(method == http.MethodGet && q[2] == "couriers"))
			}
		}
		if strings.HasPrefix(rest, "whatsapp/events/") {
			q := strings.Split(rest, "/")
			if len(q) == 3 {
				return q[1] != "" && method == http.MethodPut
			}
			if len(q) == 4 {
				return q[1] != "" && q[2] == "template" && method == http.MethodDelete
			}
		}
		if strings.HasPrefix(rest, "whatsapp/test-numbers/") {
			q := strings.Split(rest, "/")
			return len(q) == 2 && q[1] != "" && method == http.MethodDelete
		}
	}
	return false
}
