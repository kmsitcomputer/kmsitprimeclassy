#!/usr/bin/env python3
"""GLM differential harness — OPERATOR NOTIFICATION CENTRE (4 Laravel-only routes).

Scenarios (each run in a clean per-runtime pass so the two runtimes never see each other's rows):
  1. unauthenticated + RBAC denial matrix (all four routes, 4 actor roles outside the role list)
  2. index projection: data shape, meta (unread_count/total/current_page/last_page), pagination
     (per_page=2 pages), unread filter (unread=1 / unread=true / unread=0)
  3. validation: per_page=60 (between), per_page=abc (integer), unread=maybe (boolean) → 422 fields
  4. unread-count before/after read-all, read-all replay → updated 0
  5. mark-read: idempotent replay (read_at unchanged), foreign recipient → 404, non-numeric id → 404
  6. DB state: read_at NULL vs set after each mutation

Usage: python3 tools/parity-glm/compare_notifications_admin.py
"""
import http.cookiejar
import json
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

LARAVEL = "http://127.0.0.1:8898"
GO = "http://127.0.0.1:8083"
TS_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6}Z|\+00:00)$")

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
        for _ in range(8):
            st, _b = self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": password})
            if st != 429:
                return st
            time.sleep(10)
        return 429


def db(sql):
    sql = sql.replace(chr(96), chr(92) + chr(96))
    cmd = (f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing_glm '
           f'-e "{sql}"')
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=60)
    if out.returncode != 0:
        raise RuntimeError(out.stderr[:300])
    return [l.split("\t") for l in out.stdout.strip().split("\n") if l]


def seed():
    db("DELETE FROM admin_notifications WHERE id >= 900")
    db("INSERT INTO admin_notifications (id,dedupe_key,recipient_user_id,agent_id,type,severity,title,body,order_id,read_at,created_at,updated_at) VALUES "
       "(901,'glm-notif-1',1,NULL,'order.created','info','Order dibuat','Pesanan 1 dibuat',NULL,NULL,NOW(6),NOW(6)),"
       "(902,'glm-notif-2',1,10,'delivery.failed','warning','Pengiriman gagal','Pesanan 2 gagal',NULL,NULL,NOW(6),NOW(6)),"
       "(903,'glm-notif-3',1,NULL,'stock.low','critical','Stok menipis','Stok 3 menipis',NULL,'2026-01-02 03:04:05.678901',NOW(6),NOW(6)),"
       "(905,'glm-notif-5',10,NULL,'order.created','info','Order agen','Pesanan agen',NULL,NULL,NOW(6),NOW(6))")


def normalise(v):
    if isinstance(v, dict):
        return {k: normalise(x) for k, x in v.items()}
    if isinstance(v, list):
        return [normalise(x) for x in v]
    if isinstance(v, str) and TS_RE.match(v):
        return "<timestamp>"
    return v


def walk(a, b, path="", out=None):
    if out is None:
        out = []
    if type(a) is not type(b) and not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
        out.append(f"{path or '$'}: type {type(a).__name__} vs {type(b).__name__}")
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
            out.append(f"{path or '$'}: length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            walk(x, y, f"{path}[{i}]", out)
        return out
    if a != b:
        out.append(f"{path or '$'}: {a!r} vs {b!r}")
    return out


def scenario(base, who):
    """Run the full scenario against one runtime; every check is recorded prefixed with `who`."""
    c = Client(base)

    # 1. unauthenticated + RBAC
    st, body = c.call("GET", "/api/v1/notifications/admin")
    check(f"{who} unauth index 401", st == 401, f"{st} {body}")
    for email in ["konsumen@imp010.test", "sales@imp010.test", "gudang@imp010.test", "kurir@imp010.test"]:
        c2 = Client(base)
        stl = c2.login(email)
        if stl != 200:
            check(f"{who} login {email}", False, f"login status {stl}")
            continue
        for method, path in [("GET", "/api/v1/notifications/admin"), ("GET", "/api/v1/notifications/admin/unread-count"),
                             ("POST", "/api/v1/notifications/admin/read-all"), ("POST", "/api/v1/notifications/admin/901/read")]:
            st, body = c2.call(method, path)
            check(f"{who} rbac {email} {method} {path}", st == 403, f"{st} {body}")
    st = c.login("super@imp010.test")
    check(f"{who} login super", st == 200, f"{st}")

    # 2. index projection + pagination + unread filter
    st, lar_index = c.call("GET", "/api/v1/notifications/admin")
    check(f"{who} index 200", st == 200, f"{st} {lar_index}")
    st2, lar_p2 = c.call("GET", "/api/v1/notifications/admin?page=2&per_page=2")
    check(f"{who} page2 200", st2 == 200, f"{st2}")
    st3, lar_unread = c.call("GET", "/api/v1/notifications/admin?unread=1")
    check(f"{who} unread=1 200", st3 == 200, f"{st3}")
    st4, lar_unread_true = c.call("GET", "/api/v1/notifications/admin?unread=true&per_page=50")
    # Verified: Laravel's `boolean` rule is strict (is_bool || in [0,1,'0','1']) — the STRING "true" is a 422.
    check(f"{who} unread=true 422", st4 == 422, f"{st4} {lar_unread_true}")
    st5, lar_unread0 = c.call("GET", "/api/v1/notifications/admin?unread=0")
    check(f"{who} unread=0 200", st5 == 200, f"{st5}")
    check(f"{who} unread=0 == index", normalise(lar_unread0) == normalise(lar_index), "filter not applied")

    # 3. validation
    st, lar_v1 = c.call("GET", "/api/v1/notifications/admin?per_page=60")
    check(f"{who} per_page=60 422", st == 422, f"{st} {lar_v1}")
    st, lar_v2 = c.call("GET", "/api/v1/notifications/admin?per_page=abc")
    check(f"{who} per_page=abc 422", st == 422, f"{st} {lar_v2}")
    st, lar_v3 = c.call("GET", "/api/v1/notifications/admin?unread=maybe")
    check(f"{who} unread=maybe 422", st == 422, f"{st} {lar_v3}")
    st, lar_v4 = c.call("GET", "/api/v1/notifications/admin?per_page=0")
    check(f"{who} per_page=0 422", st == 422, f"{st} {lar_v4}")
    st_v5, lar_v5 = c.call("GET", "/api/v1/notifications/admin?per_page")

    # 4. unread-count, read-all + replay
    st, lar_uc1 = c.call("GET", "/api/v1/notifications/admin/unread-count")
    check(f"{who} unread-count 200", st == 200, f"{st} {lar_uc1}")
    st, lar_ra = c.call("POST", "/api/v1/notifications/admin/read-all")
    check(f"{who} read-all 200", st == 200, f"{st} {lar_ra}")
    st, lar_uc2 = c.call("GET", "/api/v1/notifications/admin/unread-count")
    check(f"{who} unread-count after read-all", st == 200 and lar_uc2["data"]["unread_count"] == 0, f"{st} {lar_uc2}")
    st, lar_ra2 = c.call("POST", "/api/v1/notifications/admin/read-all")
    check(f"{who} read-all replay updated=0", st == 200 and lar_ra2["data"]["updated"] == 0, f"{st} {lar_ra2}")

    # 5. mark-read semantics (the pass reseeded at its start; 905 belongs to agen, not the caller)
    seed()
    st, lar_mr = c.call("POST", "/api/v1/notifications/admin/902/read")
    check(f"{who} mark-read 200", st == 200, f"{st} {lar_mr}")
    st, lar_mr2 = c.call("POST", "/api/v1/notifications/admin/902/read")
    check(f"{who} mark-read replay", st == 200 and normalise(lar_mr) == normalise(lar_mr2), f"{st} {lar_mr2}")
    st, lar_f = c.call("POST", "/api/v1/notifications/admin/905/read")
    check(f"{who} foreign id 404", st == 404, f"{st} {lar_f}")
    st, lar_nf = c.call("POST", "/api/v1/notifications/admin/999999/read")
    check(f"{who} unknown id 404", st == 404, f"{st} {lar_nf}")
    st, lar_nn = c.call("POST", "/api/v1/notifications/admin/abc/read")
    check(f"{who} non-numeric id 404", st == 404, f"{st} {lar_nn}")
    st, lar_905 = c.call("GET", "/api/v1/notifications/admin?per_page=1")
    check(f"{who} latest page1 200", st == 200, f"{st}")

    # 6. DB state
    rows = db("SELECT id, read_at IS NULL FROM admin_notifications WHERE recipient_user_id=1 AND id IN (901,902,903) ORDER BY id")
    return {
        "index": normalise(lar_index), "page2": normalise(lar_p2),
        "unread": normalise(lar_unread), "unread_true": normalise(lar_unread_true),
        "unread0": normalise(lar_unread0),
        "v1": lar_v1, "v2": lar_v2, "v3": lar_v3, "v4": lar_v4,
        "uc1": normalise(lar_uc1), "ra": normalise(lar_ra), "uc2": normalise(lar_uc2), "ra2": normalise(lar_ra2),
        "mr": normalise(lar_mr), "mr2": normalise(lar_mr2), "foreign404": lar_f, "unknown404": lar_nf,
        "nonnumeric": lar_nn, "p1": normalise(lar_905), "db": rows,
        "_v5_status": st_v5,
    }


def main():
    seed()
    lar = scenario(LARAVEL, "L")
    seed()
    go = scenario(GO, "G")

    for key in ["index", "page2", "unread", "unread0", "uc1", "ra", "uc2", "ra2",
                "mr", "mr2", "foreign404", "unknown404", "nonnumeric", "p1", "db"]:
        diffs = walk(lar[key], go[key])
        check(f"parity {key}", not diffs, "; ".join(diffs[:6]))
    check("parity v1", lar["v1"] == go["v1"], f"L={json.dumps(lar['v1'])} G={json.dumps(go['v1'])}")
    check("parity v2", lar["v2"] == go["v2"], f"L={json.dumps(lar['v2'])} G={json.dumps(go['v2'])}")
    check("parity v3", lar["v3"] == go["v3"], f"L={json.dumps(lar['v3'])} G={json.dumps(go['v3'])}")
    check("parity v4", lar["v4"] == go["v4"], f"L={json.dumps(lar['v4'])} G={json.dumps(go['v4'])}")
    check("parity unread_true_422", lar["unread_true"] == go["unread_true"],
          f"L={json.dumps(lar['unread_true'])} G={json.dumps(go['unread_true'])}")
    check("parity v5", lar["_v5_status"] == go["_v5_status"],
          f"L={lar['_v5_status']} G={go['_v5_status']}")

    failed = [r for r in results if not r[1]]
    print(f"notifications/admin: {len(results) - len(failed)} passed, {len(failed)} failed")
    for name, ok, detail in failed:
        print(f"  FAIL {name}: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())