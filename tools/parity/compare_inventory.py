#!/usr/bin/env python3
"""Laravel ↔ Go comparison for AUTHENTICATED inventory read endpoints.

Unlike compare_public_api.py this needs a session in BOTH runtimes, and the two runtimes deliberately use
different session cookies. It therefore logs in through each one and replays the same requests, comparing the
normalised JSON.

It also keeps a small number of INTENTIONAL differences:
  * GET /warehouse/sellable with no parameters: Laravel answers 500 ("Undefined array key"), Go answers 422.
    That is an upstream defect; the difference is asserted explicitly so it cannot silently change.

Usage:
  python3 tools/parity/compare_inventory.py --email agen@imp010.test --password 'Secret123!'
"""
import argparse
import http.cookiejar
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"

PATHS = [
    "/api/v1/stock/products",
    "/api/v1/stock/products?per_page=1&page=2",
    "/api/v1/stock/variations",
    "/api/v1/warehouse-stock",
    "/api/v1/warehouse-stock?stock_type=transit",
    "/api/v1/warehouse-stock?stock_type=factory_plan",
    "/api/v1/warehouse-stock?search=ULT",
    "/api/v1/warehouse/sellable?product_id=2",
    "/api/v1/warehouse/sellable?variation_id=2",
    "/api/v1/warehouse/sellable?product_id=1",
    "/api/v1/warehouse/sellable?product_id=3",
    "/api/v1/warehouse/settings",
    "/api/v1/warehouse/sub-locations",
    "/api/v1/warehouse/sub-locations/1",
    "/api/v1/warehouse/sub-locations/2",
    "/api/v1/warehouse/sub-locations/1/stocks",
]

# Endpoints where the Laravel implementation paginates WITHOUT an ORDER BY, so row order is whatever MySQL's
# chosen index returns (it changes when a stock_type filter selects a different index). Such a list is compared
# as an ordered-by-id sequence after sorting both sides, and the defect is reported in docs/21 §5. The Go
# implementation always orders by id, which is deterministic and strictly safer for pagination.
UNORDERED = {"/api/v1/warehouse-stock", "/api/v1/warehouse-stock?stock_type=transit",
             "/api/v1/warehouse-stock?stock_type=factory_plan", "/api/v1/warehouse-stock?search=ULT"}


def normalise(path, body):
    """Sort id-bearing lists for endpoints that have no ORDER BY on the Laravel side."""
    base = path.split("?")[0]
    if base not in {p.split("?")[0] for p in UNORDERED}:
        return body
    if isinstance(body, dict) and isinstance(body.get("data"), list) and body["data"]:
        if all(isinstance(row, dict) and "id" in row for row in body["data"]):
            body = dict(body)
            body["data"] = sorted(body["data"], key=lambda row: row["id"])
    return body


# Endpoints reachable only with a different role; compared in a second phase.
ROLE_PATHS = {
    "admin,gudang": [
        "/api/v1/warehouse/stock-cards",
        "/api/v1/warehouse/stock-cards?search=Kue",
        "/api/v1/warehouse/sub-locations/1/stock-cards",
    ],
    "sales-kurir-sub": [
        "/api/v1/sub-stock/replenishment-targets",
        "/api/v1/sub-stock/requests",
        "/api/v1/sub-stock/requests?status=requested",
        "/api/v1/sub-stock/requests?direction=replenish",
        "/api/v1/sub-stock/requests?status=approved",
        "/api/v1/sub-stock/my",
    ],
}

results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def raw(self, path):
        req = urllib.request.Request(self.base + path, headers={
            "Accept": "application/json",
            "Origin": self.base,
            "Referer": self.base + "/",
            "X-Requested-With": "XMLHttpRequest",
        })
        try:
            with self.opener.open(req, timeout=20) as res:
                body = res.read().decode()
                return res.status, (json.loads(body) if body else None)
        except urllib.error.HTTPError as e:
            body = e.read().decode()
            try:
                return e.code, (json.loads(body) if body else None)
            except json.JSONDecodeError:
                return e.code, {"_raw": body}

    def token(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def login(self, email, password):
        self.raw("/sanctum/csrf-cookie")
        req = urllib.request.Request(
            self.base + "/api/v1/auth/login",
            data=json.dumps({"identifier": email, "password": password}).encode(),
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "Origin": self.base,
                "Referer": self.base + "/",
                "X-Requested-With": "XMLHttpRequest",
                "X-XSRF-TOKEN": self.token() or "",
            },
            method="POST",
        )
        try:
            with self.opener.open(req, timeout=20) as res:
                return res.status
        except urllib.error.HTTPError as e:
            return e.code


def walk(a, b, path="", out=None):
    if out is None:
        out = []
    if type(a) is not type(b) and not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
        out.append(f"{path or '$'}: type {type(a).__name__} vs {type(b).__name__} ({a!r} vs {b!r})")
        return out
    if isinstance(a, dict):
        for key in sorted(set(a) | set(b)):
            child = f"{path}.{key}" if path else key
            if key not in a:
                out.append(f"{child}: missing in Laravel")
            elif key not in b:
                out.append(f"{child}: missing in Go")
            else:
                walk(a[key], b[key], child, out)
        return out
    if isinstance(a, list):
        if len(a) != len(b):
            out.append(f"{path or '$'}: list length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            walk(x, y, f"{path}[{i}]", out)
        return out
    if a != b:
        out.append(f"{path or '$'}: {a!r} vs {b!r}")
    return out


def compare_phase(label, laravel, go, paths):
    for path in paths:
        ls, lbody = laravel.raw(path)
        gs, gbody = go.raw(path)
        if ls != gs:
            check(f"{label} {path}: status", False, f"{ls} vs {gs}")
            continue
        diffs = walk(normalise(path, lbody), normalise(path, gbody))
        if diffs:
            check(f"{label} {path}", False, f"{len(diffs)} diff(s): " + " | ".join(diffs[:6]))
        else:
            check(f"{label} {path}", True, f"status {ls}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--email", default="agen@imp010.test")
    ap.add_argument("--password", default="Secret123!")
    ap.add_argument("--admin-email", default="gudang@imp010.test")
    ap.add_argument("--sub-email", default="sub@imp010.test")
    args = ap.parse_args()

    laravel, go = Client(LARAVEL), Client(GO)
    ls, gs = laravel.login(args.email, args.password), go.login(args.email, args.password)
    check("login (agent) in both runtimes", ls == 200 and gs == 200, f"{ls} vs {gs}")
    if ls != 200 or gs != 200:
        report()
        return 1

    compare_phase("[agent]", laravel, go, PATHS)

    # Warehouse stock is paginated by Laravel WITHOUT an ORDER BY, so a page boundary is not reproducible
    # (the row order depends on which index MySQL picks). Instead of comparing pages, assert that both
    # runtimes expose the SAME SET of rows across the whole collection — which is what the defect can still
    # guarantee — and report the missing ORDER BY as an upstream finding (docs/21 §5).
    idsets = []
    for client in (laravel, go):
        seen, page = [], 1
        while True:
            st, body = client.raw(f"/api/v1/warehouse-stock?per_page=2&page={page}")
            if st != 200 or not body.get("data"):
                break
            seen.extend(row["id"] for row in body["data"])
            if page >= body["meta"]["last_page"]:
                break
            page += 1
        idsets.append(sorted(seen))
    check("[agent] /warehouse-stock: same row SET across all pages (Laravel paginates without ORDER BY)",
          idsets[0] == idsets[1], f"{idsets[0]} vs {idsets[1]}")

    # Documented, asserted divergence: Laravel 500s on a parameterless sellable read.
    l500, _ = laravel.raw("/api/v1/warehouse/sellable")
    g422, _ = go.raw("/api/v1/warehouse/sellable")
    check("sellable with no params: Laravel 500 (upstream defect), Go 422 (documented divergence)",
          l500 == 500 and g422 == 422, f"{l500} vs {g422}")

    lc, gc = Client(LARAVEL), Client(GO)
    lc.login(args.admin_email, args.password)
    gc.login(args.admin_email, args.password)
    compare_phase("[admin/gudang]", lc, gc, ROLE_PATHS["admin,gudang"])

    # A non-admin must be refused by both (401/403, never a partial payload).
    ldeny, _ = laravel.raw("/api/v1/warehouse/stock-cards")
    gdeny, _ = go.raw("/api/v1/warehouse/stock-cards")
    check("stock-cards refused for a non-admin role in both", ldeny == 403 and gdeny == 403, f"{ldeny} vs {gdeny}")

    ls2, gs2 = Client(LARAVEL), Client(GO)
    ls2.login(args.sub_email, args.password)
    gs2.login(args.sub_email, args.password)
    compare_phase("[sales-kurir-sub]", ls2, gs2, ROLE_PATHS["sales-kurir-sub"])

    return report()


def report():
    passed = sum(1 for _, ok, _ in results if ok)
    for name, ok, detail in results:
        print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f" — {detail}" if detail and not ok else ""))
    print(f"\n{passed} passed, {len(results) - passed} failed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(main())
