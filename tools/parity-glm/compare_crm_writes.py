#!/usr/bin/env python3
"""GLM differential harness — CRM WRITES (5 Laravel-only routes).

Routes under test:
  POST  /api/v1/crm/customers                         (sales, sales-kurir-sub, korsal)
  PATCH /api/v1/crm/customers/{customer}              (sales, sales-kurir-sub, korsal)
  POST  /api/v1/crm/customers/{customer}/archive      (sales, sales-kurir-sub, korsal)
  POST  /api/v1/crm/customers/{customer}/visits       (sales, sales-kurir-sub, korsal)
  PATCH /api/v1/crm/visits/{visit}                    (sales, sales-kurir-sub, korsal)

Normalizations (proven non-contractual): record ids and row timestamps differ between the two
sequential runtime passes (mask id/agent_id/owner_user_id/user_id/crm_customer_id/created_at/
updated_at/archived_at — shape, nullness and message text are still compared); activity-log JSON
object key order; null-vs-absent response keys (Laravel omits never-set model attributes, Go renders
DB NULLs — identical semantics for consumers).

Scenarios: unauth 401, RBAC matrix (write roles vs read-only roles), validation 422s (missing name,
bad email, prospect out of set, FU status, D alias normalization, plan_next_date before visit_date,
phone duplicate), success shapes (Eloquent serialization incl. date casts and hidden audit),
audit-log rows, DB state (prospect_percent propagation on visit create), archive/restore cycle,
foreign-agent 404 and cross-owner 403.

Usage: python3 tools/parity-glm/compare_crm_writes.py
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

USERS = {
    "super_admin": "super@imp010.test",
    "agen": "agen@imp010.test",
    "admin": "admin@imp010.test",
    "korsal": "korsal@imp010.test",
    "sales": "sales@imp010.test",
}

WRITE_ROLES = {"sales", "korsal"}  # sales-kurir-sub has no fixture user

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

    def call(self, method, path, body=None):
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest"}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
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
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/json"}
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        for _ in range(10):
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


def db_query(sql):
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "mariadb", "-uprimeclassy_app",
                          "-pcW9CcIEd2eauUM6CVrsy7lk1E6Hx", "-N", "-B", "primeclassy_testing_glm", "-e", sql],
                         capture_output=True, text=True, timeout=30)
    return out.stdout.strip()


def drop_nulls(v):
    if isinstance(v, dict):
        return {k: drop_nulls(x) for k, x in v.items() if x is not None}
    if isinstance(v, list):
        return [drop_nulls(x) for x in v]
    return v


def norm_json(obj_str):
    if not isinstance(obj_str, str) or not obj_str.strip().startswith('{'):
        return obj_str
    try:
        return json.dumps(json.loads(obj_str), sort_keys=True)
    except Exception:
        return obj_str


def strip_volatile(v):
    """Strip non-contractual volatile fields: ids and timestamps that differ between passes."""
    if isinstance(v, dict):
        return {k: ("<volatile>" if k in ("id", "agent_id", "owner_user_id", "created_at", "updated_at",
                                         "crm_customer_id", "user_id") else strip_volatile(x))
                for k, x in v.items()}
    if isinstance(v, list):
        return [strip_volatile(x) for x in v]
    return v


def scenario(base):
    out = {}
    # stable fixture: one crm customer owned by korsal (user 20, agent 10) used by rbac/validation tests
    db_query("DELETE FROM crm_visits WHERE crm_customer_id IN (SELECT id FROM crm_customers WHERE owner_user_id=20 AND name='GLMFIX')")
    db_query("DELETE FROM crm_customers WHERE owner_user_id=20 AND name='GLMFIX'")
    db_query("INSERT INTO crm_customers (agent_id, owner_user_id, name, phone, created_at, updated_at) VALUES (10, 20, 'GLMFIX', '081100009999', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
    fixid = db_query("SELECT id FROM crm_customers WHERE owner_user_id=20 AND name='GLMFIX' ORDER BY id DESC LIMIT 1")

    def fresh(role):
        c = Client(base)
        st = c.login(USERS[role])
        return c, st

    # ---- 1. unauth ----
    c = Client(base)
    for method, path, body in [
        ("POST", "/api/v1/crm/customers", {"name": "X"}),
        ("PATCH", "/api/v1/crm/customers/1", {"name": "X"}),
        ("POST", "/api/v1/crm/customers/1/archive", {}),
        ("POST", "/api/v1/crm/customers/1/visits", {"visit_date": "2026-01-01", "status": "1"}),
        ("PATCH", "/api/v1/crm/visits/1", {"status": "2"}),
    ]:
        out[("unauth", method, path)] = c.call(method, path, body)

    # ---- 2. RBAC: non-write roles ----
    for role in ("super_admin", "agen", "admin"):
        c, st = fresh(role)
        out[("rbac", role)] = (c.call("POST", "/api/v1/crm/customers", {"name": "RBAC"}),
                               c.call("PATCH", f"/api/v1/crm/customers/{fixid}", {"name": "RBAC"}),
                               c.call("POST", f"/api/v1/crm/customers/{fixid}/archive", {}))

    # ---- 3. validation (sales) ----
    c, _ = fresh("sales")
    V = lambda p, b: c.call("POST", p, b)
    fid = fixid
    out[("v", "missing_name")] = V("/api/v1/crm/customers", {})
    out[("v", "bad_email")] = V("/api/v1/crm/customers", {"name": "V", "email": "not-an-email"})
    out[("v", "bad_prospect")] = V("/api/v1/crm/customers", {"name": "V", "prospect_percent": 33})
    out[("v", "name_long")] = V("/api/v1/crm/customers", {"name": "x" * 256})
    out[("v", "visit_missing_status")] = V(f"/api/v1/crm/customers/{fid}/visits", {"visit_date": "2026-01-01"})
    out[("v", "visit_fu_status")] = V(f"/api/v1/crm/customers/{fid}/visits", {"visit_date": "2026-01-01", "status": "FU"})
    out[("v", "visit_bad_date")] = V(f"/api/v1/crm/customers/{fid}/visits", {"visit_date": "nonsense", "status": "1"})
    out[("v", "visit_plan_before")] = V(f"/api/v1/crm/customers/{fid}/visits",
                                          {"visit_date": "2026-01-05", "status": "1", "plan_next_date": "2026-01-01"})
    out[("v", "visit_d_alias_valid")] = V(f"/api/v1/crm/customers/{fid}/visits", {"visit_date": "2026-01-01", "status": "D"})

    # ---- 4. lifecycle (korsal, seeded as agent-10 owner) ----
    db_query("DELETE FROM crm_visits WHERE crm_customer_id IN (SELECT id FROM crm_customers WHERE owner_user_id=20 AND (name LIKE 'GLMHARN%' OR name = 'X'))")
    db_query("DELETE FROM crm_customers WHERE owner_user_id=20 AND (name LIKE 'GLMHARN%' OR name = 'X')")
    db_query("DELETE FROM activity_logs WHERE event LIKE 'crm.%' AND (subject_type LIKE '%Crm%')")
    c, _ = fresh("korsal")
    st, body = c.call("POST", "/api/v1/crm/customers", {"name": "GLMHARN A", "phone": "+62 812-3456-7890",
                                                          "email": "harn@example.com", "prospect_percent": 50,
                                                          "note": "GLMHARN note"})
    out[("lc", "store")] = (st, body)
    if st != 201:
        return out
    cid = body["data"]["id"]
    phone = body["data"]["phone"]

    out[("lc", "phone_dup")] = c.call("POST", "/api/v1/crm/customers", {"name": "GLMHARN B", "phone": "+6281234567890"})

    out[("lc", "show_serialization")] = c.call("GET", f"/api/v1/crm/customers/{cid}")

    st, body = c.call("PATCH", f"/api/v1/crm/customers/{cid}", {"name": "GLMHARN A2", "note": None})
    out[("lc", "update")] = (st, body)
    out[("lc", "audit_update")] = db_query(
        f"SELECT event, properties FROM activity_logs WHERE subject_type='App\\\\Models\\\\CrmCustomer' AND subject_id={cid} AND event='crm.customer.updated'")

    # no-change PATCH → no audit row added
    before = db_query(f"SELECT COUNT(*) FROM activity_logs WHERE subject_id={cid} AND event='crm.customer.updated'")
    c.call("PATCH", f"/api/v1/crm/customers/{cid}", {"name": "GLMHARN A2"})
    after = db_query(f"SELECT COUNT(*) FROM activity_logs WHERE subject_id={cid} AND event='crm.customer.updated'")
    out[("lc", "noop_audit_count")] = (before, after)

    st, body = c.call("POST", f"/api/v1/crm/customers/{cid}/visits",
                       {"visit_date": "2026-11-19", "status": "5", "prospect_percent": 75,
                        "note": "GLMHARN visit", "plan_next_date": "2026-12-01"})
    out[("lc", "visit_store")] = (st, body)
    if st == 201:
        vid = body["data"]["id"]
        out[("lc", "customer_stage_propagated")] = db_query(f"SELECT prospect_percent FROM crm_customers WHERE id={cid}")
        out[("lc", "visits_list")] = strip_volatile(c.call("GET", f"/api/v1/crm/customers/{cid}/visits")[1])
        out[("lc", "visit_update")] = c.call("PATCH", f"/api/v1/crm/visits/{vid}", {"status": "6"})
        out[("lc", "visit_audit")] = db_query(
            f"SELECT event, properties FROM activity_logs WHERE subject_type='App\\\\Models\\\\CrmVisit' AND subject_id={vid} AND event='crm.visit.updated'")
        out[("lc", "visit_bad_patch")] = c.call("PATCH", f"/api/v1/crm/visits/{vid}", {"status": "9"})
        out[("lc", "visit_foreign")] = c.call("PATCH", "/api/v1/crm/visits/999999", {"status": "1"})

    # archive / restore cycle
    out[("lc", "archive")] = c.call("POST", f"/api/v1/crm/customers/{cid}/archive", {})
    out[("lc", "archived_write_blocked")] = c.call("PATCH", f"/api/v1/crm/customers/{cid}", {"name": "X"})
    out[("lc", "restore")] = c.call("POST", f"/api/v1/crm/customers/{cid}/archive", {"restore": True})
    out[("lc", "audit_archive_events")] = db_query(
        f"SELECT event FROM activity_logs WHERE subject_id={cid} AND event LIKE 'crm.customer.a%' OR subject_id={cid} AND event LIKE 'crm.customer.r%'")

    # foreign-agent: super_admin-visible id owned by another agent is 404 for korsal via binding scope
    out[("lc", "foreign_404")] = c.call("GET", "/api/v1/crm/customers/999999")

    # cross-owner: sales actor cannot write korsal's customer (403 unauthorized) — but binding scope
    # hides another owner's SAME-agent record only for sales (scope = own). Different agent → 404.
    c2, _ = fresh("sales")
    out[("lc", "sales_foreign_404")] = c2.call("PATCH", f"/api/v1/crm/customers/{cid}", {"name": "X"})
    return out


def norm(x):
    """Canonical comparison form: tuples→lists, volatile ids/timestamps masked, JSON object key
    order in DB strings normalized, null-vs-absent keys dropped (Laravel omits never-set model
    attributes; Go renders DB NULLs — same JSON semantics for consumers)."""
    if isinstance(x, (tuple, list)):
        return [norm(e) for e in x]
    if isinstance(x, dict):
        return {k: ("<volatile>" if k in ("id", "agent_id", "owner_user_id", "created_at", "updated_at", "crm_customer_id", "user_id", "archived_at") else norm(v))
                for k, v in x.items() if v is not None}
    if isinstance(x, str):
        # activity_logs DB rows: event\tproperties — JSON object key order is not contractual.
        if "\t" in x:
            return "\t".join(norm(part) for part in x.split("\t"))
        if x.startswith("{"):
            try:
                return json.dumps(json.loads(x), sort_keys=True)
            except Exception:
                return x
    return x


def main():
    lar = scenario(LARAVEL)
    go = scenario(GO)

    for key in lar:
        l, g = norm(lar[key]), norm(go.get(key))
        tag = ":".join(str(x).replace("/api/v1/", "") for x in key)
        check(tag, l == g,
              f"\n   L={json.dumps(l, sort_keys=True, default=str)[:500]}\n   G={json.dumps(g, sort_keys=True, default=str)[:500]}")

    failed = [r for r in results if not r[1]]
    print(f"crm-writes: {len(results) - len(failed)} passed, {len(failed)} failed")
    for name, ok, detail in failed:
        print(f"  FAIL {name}: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
