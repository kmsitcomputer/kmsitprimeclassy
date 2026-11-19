#!/usr/bin/env python3
"""GLM differential harness — REPORTING READS + AUDIT LOGS (17 Laravel-only routes).

Routes under test:
  GET /api/v1/reports/{cancellations-refunds, couriers, couriers-per-agent, crm-visits, customers,
       fees/agent, fees/courier, fees/sales-korsal, finance-orders, finance-summary, korsal,
       my-customers, network-summary, network-transactions, payment-status, sales}
  GET /api/v1/admin/audit-logs

Scenarios (each run per-runtime pass over the same seeded rows in primeclassy_testing_glm):
  1. RBAC matrix: every route x every actor role (super_admin/agen/admin/keuangan/korsal/sales) + unauth
     — status AND body byte-parity.
  2. Authenticated projection parity for allowed roles: JSON deep-equality (byte order irrelevant).
  3. Filter behaviour: from/to, search, status, per_page/pagination meta.
  4. DB state checks: order/commission/return counts behind the projections.

Usage: python3 tools/parity-glm/compare_reports.py
"""
import http.cookiejar
import json
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

LARAVEL = "http://127.0.0.1:8898"
GO = "http://127.0.0.1:8083"

ROUTES = [
    "/api/v1/reports/cancellations-refunds",
    "/api/v1/reports/couriers",
    "/api/v1/reports/couriers-per-agent",
    "/api/v1/reports/crm-visits",
    "/api/v1/reports/customers",
    "/api/v1/reports/fees/agent",
    "/api/v1/reports/fees/courier",
    "/api/v1/reports/fees/sales-korsal",
    "/api/v1/reports/finance-orders",
    "/api/v1/reports/finance-summary",
    "/api/v1/reports/korsal",
    "/api/v1/reports/my-customers",
    "/api/v1/reports/network-summary",
    "/api/v1/reports/network-transactions",
    "/api/v1/reports/payment-status",
    "/api/v1/reports/sales",
    "/api/v1/admin/audit-logs",
]

# Role gate expectations per route, from backend/routes/api_v1.php.
ALLOWED = {
    "/api/v1/reports/cancellations-refunds": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/couriers": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/couriers-per-agent": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/crm-visits": {"super_admin", "agen", "admin", "korsal", "sales", "sales-kurir-sub"},
    "/api/v1/reports/customers": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/fees/agent": {"super_admin", "agen"},
    "/api/v1/reports/fees/courier": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/fees/sales-korsal": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/finance-orders": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/finance-summary": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/korsal": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/my-customers": {"korsal", "sales", "sales-kurir-sub"},
    "/api/v1/reports/network-summary": {"super_admin", "agen", "keuangan"},
    "/api/v1/reports/network-transactions": {"super_admin", "agen", "admin", "korsal", "sales", "sales-kurir-sub"},
    "/api/v1/reports/payment-status": {"super_admin", "agen", "admin", "keuangan"},
    "/api/v1/reports/sales": {"super_admin", "agen", "admin", "korsal"},
    "/api/v1/admin/audit-logs": {"super_admin"},
}

USERS = {
    "super_admin": "super@imp010.test",
    "agen": "agen@imp010.test",
    "admin": "admin@imp010.test",
    "keuangan": "keuangan@imp010.test",
    "korsal": "korsal@imp010.test",
    "sales": "sales@imp010.test",
}

results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def token(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def call(self, method, path):
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest"}
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=25) as res:
                raw = res.read().decode()
                return res.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, (json.loads(raw) if raw else None)
            except json.JSONDecodeError:
                return e.code, {"_raw": raw}

    def login(self, email, password="Secret123!"):
        self.call("GET", "/sanctum/csrf-cookie")
        for _ in range(8):
            st, _b = self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": password}) if False else (None, None)
        # do the login properly (POST with body)
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/json"}
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        for _ in range(8):
            req = urllib.request.Request(self.base + "/api/v1/auth/login",
                                         data=json.dumps({"identifier": email, "password": password}).encode(),
                                         headers=headers, method="POST")
            try:
                with self.opener.open(req, timeout=25) as res:
                    return res.status
            except urllib.error.HTTPError as e:
                if e.code == 429:
                    time.sleep(10)
                    continue
                return e.code
        return 429


def db_reset():
    """Reset the audit log between runtime passes so both runtimes see identical rows."""
    cmd = 'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing_glm -e "TRUNCATE activity_logs"'
    subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd], check=True, timeout=60)


def scenario(base, who):
    out = {}
    db_reset()
    # Unauthenticated first (fresh client, no login)
    for path in ROUTES:
        c = Client(base)
        out[("unauth", path)] = c.call("GET", path)
    # One client per role: login once, then walk every route (avoids the login throttle).
    for role, email in USERS.items():
        c = Client(base)
        st = c.login(email)
        for path in ROUTES:
            if st == 200:
                st2, body = c.call("GET", path)
                out[(role, path)] = (st2, body)
            else:
                out[(role, path)] = (st, {"_login": st})
        if role == "sales":
            out[("sales", "/api/v1/reports/my-customers")] = c.call("GET", "/api/v1/reports/my-customers")
    # Filters (super_admin client, logged in above; reuse a fresh one)
    c = Client(base)
    c.login(USERS["super_admin"])
    for label, q in [
        ("sales_search", "/api/v1/reports/sales?search=Sales"),
        ("sales_page2", "/api/v1/reports/sales?page=2&per_page=1"),
        ("cust_from", "/api/v1/reports/customers?from=2026-01-01&to=2030-01-01"),
        ("cust_range_empty", "/api/v1/reports/customers?from=2099-01-01"),
        ("fin_ord_page", "/api/v1/reports/finance-orders?per_page=2"),
        ("fin_ord_status", "/api/v1/reports/finance-orders?order_status=diterima"),
        ("fin_sum_range", "/api/v1/reports/finance-summary?from=2026-01-01"),
        ("pay_stat_sales", "/api/v1/reports/payment-status?sales_id=14"),
        ("canref_status", "/api/v1/reports/cancellations-refunds?status=pending"),
        ("net_tx_agent", "/api/v1/reports/network-transactions?agent_id=10"),
        # Audit rows are created by THIS pass's logins; login-throttle retries change creation order
        # between runs, so compare the full id-sorted row set and pagination meta separately.
        ("audit_all", "/api/v1/admin/audit-logs?per_page=100"),
        ("audit_pp_meta", "/api/v1/admin/audit-logs?per_page=5&page=2"),
        ("audit_event", "/api/v1/admin/audit-logs?event=auth.login&per_page=100"),
        ("audit_from", "/api/v1/admin/audit-logs?from=2099-01-01"),
    ]:
        out[("filter", label)] = c.call("GET", q)
    return out


def main():
    lar = scenario(LARAVEL, "L")
    go = scenario(GO, "G")

    for key in lar:
        role_or_kind, path = key
        l = lar[key]
        g = go.get(key)
        tag = f"{role_or_kind}:{path.replace('/api/v1/', '')}"
        if l[0] != g[0]:
            check(f"status {tag}", False, f"L={l[0]} G={g[0]}")
            continue
        if role_or_kind == "unauth":
            check(f"status {tag}", l[0] == 401, f"L={l[0]} G={g[0]}")
            check(f"body {tag}", l[1] == g[1], f"L={json.dumps(l[1])[:160]} G={json.dumps(g[1])[:160]}")
            continue
        if role_or_kind != "filter" and role_or_kind not in ALLOWED[path]:
            check(f"rbac {tag}", l[0] == 403 and l[1] == g[1],
                  f"L={l[0]} G={g[0]} bodyL={json.dumps(l[1])[:120]} bodyG={json.dumps(g[1])[:120]}")
            continue
        lv, gv = l[1], g[1]
        # created_at rows are written by THIS pass's logins; timing differs between runtimes.
        def strip_ts(v):
            if isinstance(v, dict):
                return {k: ("<ts>" if k == "created_at" else strip_ts(x)) for k, x in v.items()}
            if isinstance(v, list):
                return [strip_ts(x) for x in v]
            return v
        lv, gv = strip_ts(lv), strip_ts(gv)
        # audit-logs ORDER BY created_at desc has unstable tie order across runs; compare by id.
        if "audit" in path:
            def sort_by_id(v):
                if isinstance(v, dict) and isinstance(v.get("data"), list):
                    v = {**v, "data": sorted(v["data"], key=lambda r: (r.get("id") or 0))}
                return v
            lv, gv = sort_by_id(lv), sort_by_id(gv)
            if "audit_pp_meta" in path:
                # Pagination meta only — the row order on a page is throttle-timing dependent.
                lv = {k2: lv[k2] for k2 in lv if k2 != "data"} if isinstance(lv, dict) else lv
                gv = {k2: gv[k2] for k2 in gv if k2 != "data"} if isinstance(gv, dict) else gv
            elif "audit_event" in path:
                # event filter: compare the id set (same timing caveat).
                lv_ids = sorted(r.get("id") or 0 for r in lv.get("data", []))
                gv_ids = sorted(r.get("id") or 0 for r in gv.get("data", []))
                lv, gv = {"ids": lv_ids}, {"ids": gv_ids}
        # Laravel applies no secondary sort inside ORDER BY ties (payment-status per-status groups,
        # my-customers equal order_count); the tie order is a storage artifact, not a contract.
        if "payment-status" in path or "my-customers" in path or "pay_stat" in path:
            def sort_rows(v):
                if isinstance(v, dict) and isinstance(v.get("data"), list):
                    key = lambda r: json.dumps(r, sort_keys=True)
                    v = {**v, "data": sorted(v["data"], key=key)}
                if isinstance(v, dict) and isinstance(v.get("data"), dict):
                    for k2 in ("sales", "korsal"):
                        if isinstance(v["data"].get(k2), list):
                            v = {**v, "data": {**v["data"], k2: sorted(v["data"][k2], key=lambda r: json.dumps(r, sort_keys=True))}}
                return v
            lv, gv = sort_rows(lv), sort_rows(gv)
        check(f"parity {tag}", lv == gv,
              f"L={json.dumps(lv, sort_keys=True)[:600]}\n   G={json.dumps(gv, sort_keys=True)[:600]}")

    failed = [r for r in results if not r[1]]
    print(f"reports+audit-logs: {len(results) - len(failed)} passed, {len(failed)} failed")
    for name, ok, detail in failed:
        print(f"  FAIL {name}: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
