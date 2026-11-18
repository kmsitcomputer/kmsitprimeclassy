#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the PROMO domain (8 routes: vouchers + product discounts).

Drives create → list → update → delete for both collections and compares responses AND the resulting rows.

Usage: python3 tools/parity/compare_promo.py
"""
import http.cookiejar, json, re, subprocess, sys, time, urllib.error, urllib.parse, urllib.request

LARAVEL, GO = "http://127.0.0.1:8899", "http://127.0.0.1:8082"
TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")
results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))


class C:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def tok(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def call(self, m, p, body=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest"}
        d = None
        if body is not None:
            d = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
        t = self.tok()
        if t:
            h["X-XSRF-TOKEN"] = t
        r = urllib.request.Request(self.base + p, data=d, headers=h, method=m)
        try:
            with self.op.open(r, timeout=25) as res:
                raw = res.read().decode()
                return res.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, (json.loads(raw) if raw else None)
            except json.JSONDecodeError:
                return e.code, {"_raw": raw}

    def login(self, email, pw="Secret123!"):
        self.call("GET", "/sanctum/csrf-cookie")
        for _ in range(8):
            st, _b = self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": pw})
            if st != 429:
                return st
            time.sleep(10)
        return 429


def db(sql):
    sql = sql.replace(chr(96), chr(92) + chr(96))
    cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    o = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                       capture_output=True, text=True, timeout=60)
    if o.returncode != 0:
        raise RuntimeError(o.stderr[:300])
    return [l.split("\t") for l in o.stdout.strip().split("\n") if l]


def reset():
    db("DELETE FROM vouchers"); db("ALTER TABLE vouchers AUTO_INCREMENT = 1")
    db("DELETE FROM product_discounts"); db("ALTER TABLE product_discounts AUTO_INCREMENT = 1")


def norm(v):
    if isinstance(v, dict):
        return {k: norm(x) for k, x in v.items()}
    if isinstance(v, list):
        return [norm(x) for x in v]
    if isinstance(v, str) and TS.match(v):
        return "<timestamp>"
    return v


def walk(a, b, p="", out=None):
    out = [] if out is None else out
    if type(a) is not type(b) and not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
        out.append(f"{p or '$'}: type {type(a).__name__} vs {type(b).__name__}")
        return out
    if isinstance(a, dict):
        for k in sorted(set(a) | set(b)):
            c = f"{p}.{k}" if p else k
            if k not in a:
                out.append(f"{c}: missing in Laravel")
            elif k not in b:
                out.append(f"{c}: missing in Go")
            else:
                walk(a[k], b[k], c, out)
        return out
    if isinstance(a, list):
        if len(a) != len(b):
            out.append(f"{p or '$'}: length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            walk(x, y, f"{p}[{i}]", out)
        return out
    if a != b:
        out.append(f"{p or '$'}: {a!r} vs {b!r}")
    return out


def run(base):
    c = C(base)
    c.login("agen@imp010.test")
    steps = {}
    steps["v_empty"] = c.call("GET", "/api/v1/promo/vouchers")
    v = c.call("POST", "/api/v1/promo/vouchers", {
        "code": " hemat10 ", "name": "Hemat 10", "type": "percentage", "value": 10,
        "valid_from": "2026-11-01", "valid_until": "2026-12-31", "max_uses": 5})
    steps["v_create"] = v
    vid = v[1]["data"]["id"] if v[0] == 201 else None
    steps["v_list"] = c.call("GET", "/api/v1/promo/vouchers")
    if vid:
        steps["v_update"] = c.call("PATCH", f"/api/v1/promo/vouchers/{vid}", {"name": "Hemat 15", "value": 15})
        steps["v_delete"] = c.call("DELETE", f"/api/v1/promo/vouchers/{vid}")
        steps["v_delete_again"] = c.call("DELETE", f"/api/v1/promo/vouchers/{vid}")
    steps["v_bad_type"] = c.call("POST", "/api/v1/promo/vouchers", {
        "code": "X1", "name": "X", "type": "bogus", "value": 5})
    steps["v_low_value"] = c.call("POST", "/api/v1/promo/vouchers", {
        "code": "X2", "name": "X", "type": "fixed", "value": 0})
    steps["v_missing_name"] = c.call("POST", "/api/v1/promo/vouchers", {
        "code": "X3", "type": "fixed", "value": 5})

    steps["d_empty"] = c.call("GET", "/api/v1/promo/discounts")
    d = c.call("POST", "/api/v1/promo/discounts", {"name": "Diskon 20", "product_id": 1, "percentage": 20})
    steps["d_create"] = d
    did = d[1]["data"]["id"] if d[0] == 201 else None
    steps["d_list"] = c.call("GET", "/api/v1/promo/discounts")
    if did:
        steps["d_update"] = c.call("PATCH", f"/api/v1/promo/discounts/{did}",
                                   {"name": "Diskon 25", "percentage": 25})
        steps["d_delete"] = c.call("DELETE", f"/api/v1/promo/discounts/{did}")
    steps["d_no_target"] = c.call("POST", "/api/v1/promo/discounts", {"name": "X", "percentage": 10})
    steps["d_bad_percentage"] = c.call("POST", "/api/v1/promo/discounts",
                                       {"name": "X", "product_id": 1, "percentage": 0})
    steps["d_mismatch_variation"] = c.call("POST", "/api/v1/promo/discounts",
                                           {"name": "X", "product_id": 1, "product_variation_id": 1,
                                            "percentage": 10})
    return steps


def main():
    reset()
    ls = run(LARAVEL)
    lv = db("SELECT agent_id, code, name, type, value, is_active, valid_from, valid_until, max_uses, "
            "used_count FROM vouchers ORDER BY id")
    ld = db("SELECT agent_id, name, product_id, product_variation_id, percentage, is_active, starts_at, "
            "ends_at FROM product_discounts ORDER BY id")

    reset()
    gs = run(GO)
    gv = db("SELECT agent_id, code, name, type, value, is_active, valid_from, valid_until, max_uses, "
            "used_count FROM vouchers ORDER BY id")
    gd = db("SELECT agent_id, name, product_id, product_variation_id, percentage, is_active, starts_at, "
            "ends_at FROM product_discounts ORDER BY id")

    for k in ls:
        if k not in gs:
            check(k, False, "missing in Go run")
            continue
        a, ab = ls[k]
        b, bb = gs[k]
        if a != b:
            check(f"{k}: status", False, f"{a} vs {b} ({(ab or {}).get('message')!r} vs {(bb or {}).get('message')!r})")
            continue
        diffs = walk(norm(ab), norm(bb))
        if diffs:
            check(k, False, " | ".join(diffs[:5]))
        else:
            check(f"{k}: status {a}", True)

    check("voucher rows identical", lv == gv, f"{lv} vs {gv}")
    check("discount rows identical", ld == gd, f"{ld} vs {gd}")

    # RBAC: konsumen must be refused on both runtimes.
    for base, label in ((LARAVEL, "laravel"), (GO, "go")):
        c = C(base); c.login("konsumen@imp010.test")
        st, _ = c.call("GET", "/api/v1/promo/vouchers")
        check(f"{label}: konsumen refused on /promo/vouchers", st == 403, f"status {st}")

    passed = sum(1 for _, ok, _ in results if ok)
    for n, ok, d in results:
        if not ok:
            print(f"FAIL  {n} — {d}")
    print(f"\n{passed} passed, {len(results) - passed} failed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(main())
