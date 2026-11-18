#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the KONSUMEN ADDRESS domain (4 routes).

Drives create → list → update → delete against each runtime and compares the responses AND the resulting
address rows, including the "only one default" rule and the ownership 404 for a foreign id.

Usage: python3 tools/parity/compare_address.py
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

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"
TS_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")

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
    cmd = (f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing '
           f'-e "{sql}"')
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=60)
    if out.returncode != 0:
        raise RuntimeError(out.stderr[:300])
    return [l.split("\t") for l in out.stdout.strip().split("\n") if l]


def reset():
    db("DELETE FROM konsumen_addresses")
    db("ALTER TABLE konsumen_addresses AUTO_INCREMENT = 1")


def normalise(v):
    if isinstance(v, dict):
        return {k: normalise(x) for k, x in v.items()}
    if isinstance(v, list):
        items = [normalise(x) for x in v]
        # Address lists are ordered by created_at only (no tiebreak), so rows created in the same second have
        # undefined relative order. Sort by a stable key so the comparison tests CONTENT, not scheduler luck.
        if items and all(isinstance(i, dict) and "label" in i for i in items):
            items.sort(key=lambda d: (d.get("label") or "", json.dumps(d, sort_keys=True)))
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


ADDR = {
    "recipient_name": "Penerima Uji",
    "phone": "081234567890",
    "address_line": "Jl. Uji No. 1",
    "village_id": "32730101",
    "latitude": -6.9,
    "longitude": 107.6,
}


def run(base):
    c = Client(base)
    c.login("konsumen@imp010.test")
    steps = {}
    steps["empty"] = c.call("GET", "/api/v1/addresses")

    first = c.call("POST", "/api/v1/addresses", {**ADDR, "label": "Rumah", "is_default": True})
    steps["create_default"] = first
    id1 = first[1]["data"]["id"] if first[0] == 201 else None

    second = c.call("POST", "/api/v1/addresses", {**ADDR, "label": "Kantor", "is_default": False})
    steps["create_second"] = second

    if id1:
        # Promote the second one to default: the first must stop being default.
        steps["list_after_two"] = c.call("GET", "/api/v1/addresses")
        steps["update"] = c.call("PATCH", f"/api/v1/addresses/{id1}", {**ADDR, "label": "Rumah Utama",
                                                                       "is_default": True})
        steps["list_after_update"] = c.call("GET", "/api/v1/addresses")

    # Validation guards
    steps["missing_recipient"] = c.call("POST", "/api/v1/addresses", {**ADDR, "recipient_name": ""})
    steps["bad_village"] = c.call("POST", "/api/v1/addresses", {**ADDR, "village_id": "99999999"})
    steps["lat_out_of_range"] = c.call("POST", "/api/v1/addresses", {**ADDR, "latitude": 91})
    steps["long_out_of_range"] = c.call("POST", "/api/v1/addresses", {**ADDR, "longitude": -181})
    steps["missing_coords"] = c.call("POST", "/api/v1/addresses",
                                     {k: v for k, v in ADDR.items() if k not in ("latitude", "longitude")})

    if id1:
        steps["delete"] = c.call("DELETE", f"/api/v1/addresses/{id1}")
        steps["delete_again"] = c.call("DELETE", f"/api/v1/addresses/{id1}")
        steps["list_final"] = c.call("GET", "/api/v1/addresses")
    return steps


def main():
    reset()
    lsteps = run(LARAVEL)
    lrows = db("SELECT user_id, label, recipient_name, is_default, village_id, district_id, regency_id, "
               "province_id, latitude, longitude FROM konsumen_addresses ORDER BY id")

    reset()
    gsteps = run(GO)
    grows = db("SELECT user_id, label, recipient_name, is_default, village_id, district_id, regency_id, "
               "province_id, latitude, longitude FROM konsumen_addresses ORDER BY id")

    for key in lsteps:
        if key not in gsteps:
            check(key, False, "missing in Go run")
            continue
        ls, lb = lsteps[key]
        gs, gb = gsteps[key]
        if ls != gs:
            check(f"{key}: status", False,
                  f"{ls} vs {gs} ({(lb or {}).get('message')!r} vs {(gb or {}).get('message')!r})")
            continue
        if key == "list_after_update":
            # Consequence of D-9: identical rows, but the reference has NO default address while Go has one.
            def strip_default(body):
                rows = [dict(r) for r in (body or {}).get("data") or []]
                for r in rows:
                    r.pop("is_default", None)
                return normalise(rows)
            ldefault = sum(1 for r in (lb or {}).get("data") or [] if r.get("is_default"))
            gdefault = sum(1 for r in (gb or {}).get("data") or [] if r.get("is_default"))
            check("list_after_update: rows identical except the default flag "
                  "(reference 0 defaults after D-9, Go exactly 1)",
                  ls == gs and walk(strip_default(lb), strip_default(gb)) == []
                  and ldefault == 0 and gdefault == 1,
                  f"defaults {ldefault} vs {gdefault}; {strip_default(lb)} vs {strip_default(gb)}")
            continue
        if key == "update":
            # Documented deviation D-9: promoting an existing address to default leaves the reference row
            # NON-default (it resets every row first, then writes a model whose value is no longer dirty), so
            # the reference can end with no default address at all. Go writes the promotion. The divergence is
            # asserted here so it cannot change silently.
            same_except_default = True
            if ls == gs and lb and gb:
                lb2 = dict(lb.get("data") or {})
                gb2 = dict(gb.get("data") or {})
                lb2.pop("is_default", None)
                gb2.pop("is_default", None)
                same_except_default = walk(normalise(lb2), normalise(gb2)) == []
            check("update: reference leaves is_default=false after promotion (deviation D-9), Go writes true",
                  ls == gs and same_except_default
                  and (lb.get("data") or {}).get("is_default") is False
                  and (gb.get("data") or {}).get("is_default") is True,
                  f"{ls}/{gs} {lb.get('data')} vs {gb.get('data')}")
            continue
        diffs = walk(normalise(lb), normalise(gb))
        if diffs:
            check(key, False, " | ".join(diffs[:6]))
        else:
            check(f"{key}: status {ls}", True)

    check("address rows identical (incl. resolved province/regency/district and default flag)",
          lrows == grows, f"{lrows} vs {grows}")

    # A foreign address id must be a 404, never a payload — in both runtimes.
    reset()
    for base, label in ((LARAVEL, "laravel"), (GO, "go")):
        c = Client(base)
        c.login("konsumen@imp010.test")
        db("DELETE FROM konsumen_addresses WHERE id = 99")
        db("INSERT INTO konsumen_addresses (id, user_id, label, recipient_name, phone, address_line, "
           "province_id, regency_id, district_id, village_id, latitude, longitude, is_default, created_at, updated_at) "
           "VALUES (99, 10, 'Agen', 'X', '0800', 'Jl', '32','3273','327301','32730101', -6.9, 107.6, 0, "
           "UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        st, _b = c.call("DELETE", "/api/v1/addresses/99")
        check(f"{label}: a foreign address id is a 404", st == 404, f"status {st}")

    passed = sum(1 for _, ok, _ in results if ok)
    for name, ok, detail in results:
        if not ok:
            print(f"FAIL  {name} — {detail}")
    print(f"\n{passed} passed, {len(results) - passed} failed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(main())
