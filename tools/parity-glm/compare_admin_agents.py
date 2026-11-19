#!/usr/bin/env python3
"""GLM differential harness — ADMIN AGENTS / Kontak Agen (5 Laravel-only routes).

Scenarios (per-runtime pass; the test agen user is reseeded per runtime):
  1. RBAC: super_admin 2xx; agen/admin/keuangan/konsumen 403 on all five routes (route group gate)
  2. index: AgentDirectoryResource shape, ordered by name (ties undefined → compared as a set)
  3. store validation: empty 422 accumulated; unknown user_id → exists; store_name 151 → max.string;
     latitude 95 → between; latitude "abc" → numeric
  4. store flow: konsumen target → 422 not_an_agent; valid agen → 201; duplicate → 422 already-exists
  5. update: 200 + resource; latitude 95 → 422; unknown profile id → 404
  6. destroy: 200 localized profile_deleted; unknown id → 404
  7. toggle-status: active→inactive→active; konsumen → 422; unknown user → 404
  8. DB state: agent_profiles + users.status + activity_logs compared

Usage: python3 tools/parity-glm/compare_admin_agents.py
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
            st = self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": password})[0]
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
    db("DELETE FROM agent_profiles")
    db("ALTER TABLE agent_profiles AUTO_INCREMENT = 900")
    db("DELETE FROM users WHERE id >= 900")
    db("DELETE FROM user_closures WHERE descendant_id >= 900 OR ancestor_id >= 900")
    db("DELETE FROM activity_logs WHERE event LIKE 'agent_%'")
    db("UPDATE users SET status='active' WHERE id=10")
    db("INSERT INTO users (id, role_id, parent_id, agent_id, name, email, phone, referral_code, status, password, created_at, updated_at) VALUES "
       "(905, 2, 10, 10, 'Agen Tanpa Profil', 'agen2@imp010.test', '081100000905', 'AG2-IMP010', 'active', "
       "'$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq', NOW(), NOW())")
    db("INSERT INTO user_closures (ancestor_id, descendant_id, depth) VALUES (10,905,1),(1,905,2),(905,905,0)")
    db("INSERT INTO agent_profiles (id, user_id, store_name, address, latitude, longitude, phone, created_at, updated_at) VALUES "
       "(1,10,'Toko Prime Classy Imp010','Jl. Uji 1, Bandung',-6.9000000,107.6000000,'081100000010',NOW(),NOW())")


def normalise(v):
    if isinstance(v, dict):
        return {k: normalise(x) for k, x in v.items()}
    if isinstance(v, list):
        items = [normalise(x) for x in v]
        if items and all(isinstance(i, dict) for i in items):
            items.sort(key=lambda d: json.dumps(d, sort_keys=True))
        return items
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


STORE_OK = {"user_id": 905, "store_name": "Toko Dua", "address": "Jl. Dua No. 2", "phone": "081200000905",
            "latitude": -6.9123456, "longitude": 107.6123456}


def scenario(base, who):
    def login_as(email):
        c = Client(base)
        st = c.login(email)
        check(f"{who} login {email}", st == 200, f"{st}")
        return c

    super_c = login_as("super@imp010.test")
    agen_c = login_as("agen@imp010.test")
    admin_c = login_as("admin@imp010.test")
    keu_c = login_as("keuangan@imp010.test")
    kons_c = login_as("konsumen@imp010.test")

    # 1. RBAC on all five routes
    for c, label in [(agen_c, "agen"), (admin_c, "admin"), (keu_c, "keuangan"), (kons_c, "konsumen")]:
        for method, path, body in [
            ("GET", "/api/v1/admin/agents", None),
            ("POST", "/api/v1/admin/agents", STORE_OK),
            ("PATCH", "/api/v1/admin/agents/1", {"store_name": "X"}),
            ("DELETE", "/api/v1/admin/agents/1", None),
            ("PATCH", "/api/v1/admin/agents/10/toggle-status", None),
        ]:
            st, body_r = c.call(method, path, body)
            check(f"{who} rbac {label} {method} {path}", st == 403, f"{st} {body_r}")

    # 2. index
    st, idx = super_c.call("GET", "/api/v1/admin/agents")
    check(f"{who} index 200", st == 200, f"{st} {idx}")

    # 3. store validation
    st, v_empty = super_c.call("POST", "/api/v1/admin/agents", {})
    check(f"{who} store empty 422", st == 422, f"{st} {v_empty}")
    st, v_exists = super_c.call("POST", "/api/v1/admin/agents", {**STORE_OK, "user_id": 99999})
    check(f"{who} store unknown user 422", st == 422, f"{st} {v_exists}")
    st, v_max = super_c.call("POST", "/api/v1/admin/agents", {**STORE_OK, "store_name": "x" * 151})
    check(f"{who} store max 422", st == 422, f"{st} {v_max}")
    st, v_lat = super_c.call("POST", "/api/v1/admin/agents", {**STORE_OK, "latitude": 95})
    check(f"{who} store latitude range 422", st == 422, f"{st} {v_lat}")
    st, v_num = super_c.call("POST", "/api/v1/admin/agents", {**STORE_OK, "latitude": "abc"})
    check(f"{who} store latitude numeric 422", st == 422, f"{st} {v_num}")

    # 4. store flow
    st, v_role = super_c.call("POST", "/api/v1/admin/agents", {**STORE_OK, "user_id": 13})
    check(f"{who} store konsumen 422", st == 422, f"{st} {v_role}")
    st, store1 = super_c.call("POST", "/api/v1/admin/agents", STORE_OK)
    check(f"{who} store ok 201", st == 201, f"{st} {store1}")
    st, store2 = super_c.call("POST", "/api/v1/admin/agents", STORE_OK)
    check(f"{who} store duplicate 422", st == 422, f"{st} {store2}")

    # 5. update (profile of user 10 = profile id 1)
    st, upd = super_c.call("PATCH", "/api/v1/admin/agents/1",
                           {"store_name": "Toko Prime Classy Imp010 Edited", "phone": "081599999999", "latitude": -7.0000001})
    check(f"{who} update 200", st == 200, f"{st} {upd}")
    st, u_lat = super_c.call("PATCH", "/api/v1/admin/agents/1", {"latitude": 95})
    check(f"{who} update latitude 422", st == 422, f"{st} {u_lat}")
    st, u_404 = super_c.call("PATCH", "/api/v1/admin/agents/499999", {"store_name": "X"})
    check(f"{who} update unknown 404", st == 404, f"{st} {u_404}")

    # 6. destroy (delete the just-created 905 profile)
    pid = db("SELECT id FROM agent_profiles WHERE user_id=905")
    profile_id = int(pid[0][0]) if pid else 0
    st, del_ok = super_c.call("DELETE", f"/api/v1/admin/agents/{profile_id}")
    check(f"{who} delete 200", st == 200, f"{st} {del_ok}")
    st, del_404 = super_c.call("DELETE", "/api/v1/admin/agents/499999")
    check(f"{who} delete unknown 404", st == 404, f"{st} {del_404}")

    # 7. toggle-status (user 905: active → inactive → active)
    st, t1 = super_c.call("PATCH", "/api/v1/admin/agents/905/toggle-status")
    check(f"{who} toggle 1 200", st == 200, f"{st} {t1}")
    st, t2 = super_c.call("PATCH", "/api/v1/admin/agents/905/toggle-status")
    check(f"{who} toggle 2 200", st == 200, f"{st} {t2}")
    st, t_role = super_c.call("PATCH", "/api/v1/admin/agents/13/toggle-status")
    check(f"{who} toggle konsumen 422", st == 422, f"{st} {t_role}")
    st, t_404 = super_c.call("PATCH", "/api/v1/admin/agents/499999/toggle-status")
    check(f"{who} toggle unknown 404", st == 404, f"{st} {t_404}")

    # 8. DB state (activity properties are parsed so JSON key ORDER never matters, only content)
    profiles = db("SELECT user_id,store_name,address,phone,latitude,longitude FROM agent_profiles ORDER BY user_id")
    statuses = db("SELECT id,status FROM users WHERE id IN (905,10,13) ORDER BY id")
    acts = []
    for row in db("SELECT event,subject_type,subject_id,causer_id,properties FROM activity_logs WHERE event LIKE 'agent_%' ORDER BY id"):
        try:
            row[4] = json.loads(row[4]) if row[4] else None
        except json.JSONDecodeError:
            pass
        acts.append(row)
    return {
        "idx": normalise(idx),
        "v_empty": v_empty, "v_exists": v_exists, "v_max": v_max, "v_lat": v_lat, "v_num": v_num,
        "v_role": v_role, "store1": normalise(store1), "store2": store2,
        "upd": normalise(upd), "u_lat": u_lat, "u_404": u_404,
        "del_ok": del_ok, "del_404": del_404,
        "t1": normalise(t1), "t2": normalise(t2), "t_role": t_role, "t_404": t_404,
        "profiles": profiles, "statuses": statuses, "acts": acts,
    }


def main():
    seed()
    lar = scenario(LARAVEL, "L")
    seed()
    go = scenario(GO, "G")

    for key in ["idx", "store1", "upd", "t1", "t2", "profiles", "statuses", "acts"]:
        diffs = walk(lar[key], go[key])
        check(f"parity {key}", not diffs, "; ".join(diffs[:6]))
    for key in ["v_empty", "v_exists", "v_max", "v_lat", "v_num", "v_role", "store2", "u_lat",
                "u_404", "del_ok", "del_404", "t_role", "t_404"]:
        check(f"parity {key}", lar[key] == go[key], f"L={json.dumps(lar[key])} G={json.dumps(go[key])}")

    failed = [r for r in results if not r[1]]
    print(f"admin/agents: {len(results) - len(failed)} passed, {len(failed)} failed")
    for name, ok, detail in failed:
        print(f"  FAIL {name}: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())