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
    print("- **PARTIAL** — served, with a documented behavioural gap in the Notes column.")
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
                status = "**PARTIAL**" if note else "**GO**"
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
    print(f"| Served by Go now (incl. partial) | {implemented} ({pct:.1f}%) |")
    print(f"| Still Laravel-only | {total - implemented} |")
    print(f"| Areas fully ported | "
          f"{sum(1 for a, i in by_area.items() if all((r['method'], r['uri']) in IMPLEMENTED and not IMPLEMENTED[(r['method'], r['uri'])] for r in i))}"
          f" / {len(by_area)} |")


if __name__ == "__main__":
    sys.exit(main())
