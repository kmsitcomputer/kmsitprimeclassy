#!/usr/bin/env python3
"""Generate the IMP-010 API parity matrix from the embedded route inventory.

Source of truth: go-backend/internal/api/parity_routes.json (produced from `php artisan route:list --json` on
the IMP-008/IMP-009 baseline). The IMPLEMENTED column is read from the same list the router consults, kept here
as an explicit table so the document and the code cannot disagree silently — the Go test
`TestImplementedRoutesAreRealRoutes` enforces the reverse direction.

Usage: python3 tools/parity/gen_matrix.py > /tmp/matrix.md
"""
import json
import os
import sys
from collections import Counter, OrderedDict

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ROUTES = os.path.join(ROOT, "go-backend", "internal", "api", "parity_routes.json")

# Endpoints the Go service serves. Anything absent is NOT PORTED.
IMPLEMENTED = {
    ("GET", "/api/v1/install/status"): "",
    ("GET", "/api/v1/settings"): "",
    ("GET", "/api/v1/languages"): "",
    ("GET", "/api/v1/categories"): "",
    ("GET", "/api/v1/agents"): "",
    ("GET", "/api/v1/regions/provinces"): "",
    ("GET", "/api/v1/regions/regencies"): "",
    ("GET", "/api/v1/regions/districts"): "",
    ("GET", "/api/v1/regions/villages"): "",
    ("POST", "/api/v1/auth/login"): "",
    ("POST", "/api/v1/auth/logout"): "",
    ("GET", "/api/v1/auth/me"): "",
    ("PATCH", "/api/v1/profile/password"): "",
    ("POST", "/api/v1/auth/register"): "user + referral chain + closures; welcome e-mail/WA notification not emitted (queue bridge outstanding)",
    ("GET", "/api/v1/products"): "anonymous exact; authenticated agent/Sub availability (stock) not ported",
    ("GET", "/api/v1/products/{slug}"): "anonymous exact; authenticated agent/Sub availability (stock) not ported",
    ("GET", "/api/v1/articles"): "",
    ("GET", "/api/v1/articles/{slug}"): "",
    ("GET", "/api/v1/pages/{slug}"): "",
    ("GET", "/api/v1/homepage"): "\"bestseller\" block returns [] for anonymous visitors (identical to the reference); authenticated ranking not ported",
    # --- checkpoint N: checkout wizard contract (compare_checkout.py, 38 checks over 11 scenarios)
    ("GET", "/api/v1/checkout/steps"): "public; dynamic step list with role-derived flags, agent provider switches, KMSIT fallback and the konsumen payment filter",
    ("POST", "/api/v1/checkout/quote"): "read-only Review preview through the same money/pricing arithmetic as order creation; never reserves stock or consumes a voucher",
    # --- checkpoint G: inventory read surface (contract-compared in tools/parity/compare_inventory.py)
    ("GET", "/api/v1/stock/products"): "",
    ("GET", "/api/v1/stock/variations"): "",
    ("GET", "/api/v1/warehouse-stock"): "Laravel paginates without ORDER BY (upstream defect); Go orders by id, so cross-page SET is compared instead of page bytes",
    ("GET", "/api/v1/warehouse/sellable"): "Go answers 422 when neither product_id nor variation_id is given; Laravel 500s there (upstream defect, documented divergence)",
    ("GET", "/api/v1/warehouse/settings"): "reproduces Laravel's firstOrCreate side effect (a GET may create the row)",
    ("GET", "/api/v1/warehouse/sub-locations"): "",
    ("GET", "/api/v1/warehouse/sub-locations/{subLocation}"): "",
    ("GET", "/api/v1/warehouse/sub-locations/{subLocation}/stocks"): "",
    ("GET", "/api/v1/warehouse/stock-cards"): "reads warehouse buckets only (no legacy fallback) — same asymmetry as the reference",
    ("GET", "/api/v1/warehouse/sub-locations/{subLocation}/stock-cards"): "",
    ("GET", "/api/v1/sub-stock/my"): "reserved always 0 in the verified fixture set (needs an order_item; belongs to the orders checkpoint)",
    ("GET", "/api/v1/sub-stock/replenishment-targets"): "",
    ("GET", "/api/v1/sub-stock/requests"): "list only; create/approve/execute/receive/cancel/reject are mutation domain and not ported",
    # --- checkpoint H: inventory mutations (behaviour + DB state compared in compare_inventory_mutations.py)
    ("POST", "/api/v1/stock/adjust"): "403 for every caller in warehouse-authoritative mode (the default) — identical to the reference; deviation register D-3",
    ("PATCH", "/api/v1/warehouse/settings/factory-plan"): "",
    ("GET", "/api/v1/warehouse/transfers"): "",
    ("POST", "/api/v1/warehouse/transfers"): "physical buckets only; factory_plan needs /transfers/plan-to-transit (not ported)",
    ("GET", "/api/v1/warehouse/transfers/{transfer}"): "",
    ("POST", "/api/v1/warehouse/transfers/{transfer}/approve"): "executes + writes the handover; canonical lock order preserved, verified by two-connection tests",
    ("POST", "/api/v1/warehouse/transfers/{transfer}/cancel"): "",
    ("POST", "/api/v1/warehouse/transfers/{transfer}/reject"): "",
    # --- checkpoint I: admin system configuration (compare_admin_config.py, 17 checks)
    ("GET", "/api/v1/admin/languages"): "admin listing: every language, active or not",
    ("POST", "/api/v1/admin/languages"): "",
    ("PATCH", "/api/v1/admin/languages/{language}"): "",
    ("PATCH", "/api/v1/admin/languages/{language}/set-default"): "exactly one default; the target must be active",
    ("PATCH", "/api/v1/admin/languages/{language}/toggle-active"): "the default language can never be deactivated",
    ("GET", "/api/v1/admin/settings"): "identical to the public read (single service in the reference)",
    ("PUT", "/api/v1/admin/settings"): "unknown keys ignored, JSON keys encoded, returns the resolved settings",
    # --- checkpoint J: opname + plan transfer + eligible owners (compare_inventory_mutations.py, 65 checks)
    ("POST", "/api/v1/warehouse/transfers/plan-to-transit"): "requires the factory plan to be enabled",
    ("GET", "/api/v1/warehouse/sub-locations/eligible-owners"): "active Sales-Kurir-Sub without a sub-location",
    ("GET", "/api/v1/warehouse/opnames"): "",
    ("POST", "/api/v1/warehouse/opnames"): "snapshots system_quantity + stock id + physical revision per target",
    ("GET", "/api/v1/warehouse/opnames/{opname}"): "",
    ("PATCH", "/api/v1/warehouse/opnames/{opname}/count"): "",
    ("POST", "/api/v1/warehouse/opnames/{opname}/submit"): "refuses while any item is uncounted",
    ("POST", "/api/v1/warehouse/opnames/{opname}/approve"): "writes the correction; 409 when the stock changed after counting (quantity OR revision); idempotent",
    ("POST", "/api/v1/warehouse/opnames/{opname}/cancel"): "",
    ("POST", "/api/v1/warehouse/opnames/{opname}/reject"): "",
    # --- checkpoint K: sub-location ownership
    ("POST", "/api/v1/warehouse/sub-locations"): "owner must be an active Sales-Kurir-Sub of the network that owns no other location",
    ("PATCH", "/api/v1/warehouse/sub-locations/{subLocation}"): "",
    ("POST", "/api/v1/warehouse/sub-locations/{subLocation}/assign-owner"): "unowned + active only; owner row locked before the location on create",
    # --- checkpoint M: promotions (vouchers + discounts)
    ("GET", "/api/v1/promo/vouchers"): "",
    ("POST", "/api/v1/promo/vouchers"): "code upper-cased/trimmed; super_admin must name the branch",
    ("PATCH", "/api/v1/promo/vouchers/{voucher}"): "",
    ("DELETE", "/api/v1/promo/vouchers/{voucher}"): "",
    ("GET", "/api/v1/promo/discounts"): "",
    ("POST", "/api/v1/promo/discounts"): "a discount MUST name a product; a variation must belong to it (audit A1-17)",
    ("PATCH", "/api/v1/promo/discounts/{discount}"): "",
    ("DELETE", "/api/v1/promo/discounts/{discount}"): "",
    # --- checkpoint L: konsumen address book
    ("GET", "/api/v1/addresses"): "own rows only; list ordering differs (deviation D-10)",
    ("POST", "/api/v1/addresses"): "",
    ("PATCH", "/api/v1/addresses/{address}"): "promotes the default correctly where the reference leaves none (deviation D-9)",
    ("DELETE", "/api/v1/addresses/{address}"): "a foreign id is a 404",
    ("POST", "/api/v1/warehouse/sub-locations/{subLocation}/deactivate"): "refused while physical stock remains; releases the owner and remembers them in previous_owner_user_id",
}


# Routes whose OWN behaviour is incomplete. This is deliberately a separate, explicit set: a Notes entry alone
# (e.g. "admin listing: every language, active or not") documents a COST or a deviation, not a gap — marking
# every annotated route PARTIAL made the status column meaningless and produced two different published counts
# (the matrix said 31 PARTIAL while the execution state said 6). Anything here must name the missing behaviour
# in its note.
GAPS = {
    ("POST", "/api/v1/auth/register"),        # welcome e-mail / WA notification (queue bridge) not emitted
    ("GET", "/api/v1/homepage"),              # authenticated bestseller ranking not ported
    ("GET", "/api/v1/products"),              # authenticated agent/Sub stock availability not ported
    ("GET", "/api/v1/products/{slug}"),       # same
    ("GET", "/api/v1/sub-stock/my"),          # `reserved` not yet derived from order_items
    ("POST", "/api/v1/warehouse/opnames"),    # pending_catalog_change publication not triggered
}

AREA_TITLES = OrderedDict([
    ("auth", "Identity & session"),
    ("profile", "Profile"),
    ("install", "Installer"),
    ("products", "Catalog — products"),
    ("categories", "Catalog — categories"),
    ("bestsellers", "Catalog — bestsellers"),
    ("manage", "Catalog — management"),
    ("migrate", "Catalog — legacy data"),
    ("articles", "CMS — articles"),
    ("pages", "CMS — pages"),
    ("homepage", "CMS — homepage"),
    ("cms", "CMS — admin"),
    ("media", "Media"),
    ("languages", "Site configuration — languages"),
    ("settings", "Site configuration — website"),
    ("agents", "Agent directory"),
    ("regions", "Regions"),
    ("referral", "Referral"),
    ("orders", "Orders"),
    ("order-items", "Orders — item receipts"),
    ("checkout", "Checkout"),
    ("stock", "Inventory — agent"),
    ("sub-stock", "Inventory — sub"),
    ("warehouse", "Inventory — warehouse"),
    ("sub-stock", "Inventory — sub stock"),
    ("warehouse-stock", "Inventory — warehouse stock"),
    ("shipments", "Shipping & delivery"),
    ("kurir", "Courier"),
    ("dispatch", "Dispatch"),
    ("returns", "Returns"),
    ("return-items", "Returns — items"),
    ("payment-proofs", "Payments — proofs"),
    ("promo", "Promotions"),
    ("fee", "Commissions & fees"),
    ("invoice", "Invoices"),
    ("refund", "Refunds"),
    ("admin", "Admin operations"),
    ("reports", "Reporting"),
    ("crm", "CRM"),
    ("consent", "Consent"),
    ("notifications", "Notifications"),
    ("users", "User management"),
    ("google-sheets", "Integrations — Google Sheets"),
])


def area_of(uri):
    parts = [p for p in uri.split("/") if p]
    return parts[2] if len(parts) >= 3 else "(root)"


def main():
    routes = json.load(open(ROUTES))
    by_area = OrderedDict()
    for r in routes:
        by_area.setdefault(area_of(r["uri"]), []).append(r)

    total = len(routes)
    implemented = 0

    print("# IMP-010 — Complete API parity matrix\n")
    print("Generated from `go-backend/internal/api/parity_routes.json` "
          "(375 `/api/v1` routes on the IMP-008/IMP-009 baseline, REM-11 excluded) by "
          "`tools/parity/gen_matrix.py`. **Do not edit by hand.**\n")
    print("Status legend:\n")
    print("- **GO** — served by the Go service, contract-compared against Laravel (`tools/parity/compare_*.py`).")
    print("- **PARTIAL** — served, but the route's own behaviour is still incomplete (see the note). A note "
          "alone does NOT make a route partial: informational notes and documented deviations stay **GO**.")
    print("- **LARAVEL** — still served by Laravel only; the Go service answers `501 not_implemented`.\n")

    for area, items in by_area.items():
        items.sort(key=lambda r: (r["uri"], r["method"]))
        title = AREA_TITLES.get(area, area)
        go = sum(1 for r in items if (r["method"], r["uri"]) in IMPLEMENTED)
        implemented += go
        print(f"## {title} — `{area}` ({go}/{len(items)} in Go)\n")
        print("| Method | Path | Auth | Roles | Go status | Notes |")
        print("|---|---|---|---|---|---|")
        for r in items:
            key = (r["method"], r["uri"])
            if key in IMPLEMENTED:
                note = IMPLEMENTED[key]
                status = "**PARTIAL**" if key in GAPS else "**GO**"
            else:
                note = ""
                status = "LARAVEL"
            roles = ", ".join(r["roles"]) if r["roles"] else "—"
            auth = "session" if r["auth"] else "public"
            print(f"| {r['method']} | `{r['uri']}` | {auth} | {roles} | {status} | {note} |")
        print()

    pct = implemented / total * 100
    print("## Totals\n")
    print(f"| Metric | Value |\n|---|---|")
    print(f"| Laravel `/api/v1` routes on this baseline | {total} |")
    partially = sum(1 for k in GAPS if k in IMPLEMENTED)
    print(f"| Served by Go now | {implemented} ({pct:.1f}%) |")
    print(f"|   of which fully GO | {implemented - partially} |")
    print(f"|   of which PARTIAL (incomplete behaviour) | {partially} |")
    print(f"| Still Laravel-only | {total - implemented} |")
    print(f"| Areas fully ported | "
          f"{sum(1 for a, i in by_area.items() if all((r['method'], r['uri']) in IMPLEMENTED for r in i))}"
          f" / {len(by_area)} |")


if __name__ == "__main__":
    sys.exit(main())
