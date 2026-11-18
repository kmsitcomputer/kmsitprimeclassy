#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the ADMIN system-configuration domain (languages + website settings).

Both runtimes are driven as super_admin. Mutations are compared by DRIVING THE SAME SEQUENCE (create → update →
set-default → toggle) and diffing the resulting payloads and the language table state, so a handler that
returns the right shape but writes the wrong rows still fails.

Usage: python3 tools/parity/compare_admin_config.py
"""
import http.cookiejar
import json
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"

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
        headers = {
            "Accept": "application/json",
            "Origin": self.base,
            "Referer": self.base + "/",
            "X-Requested-With": "XMLHttpRequest",
        }
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
            import time
            time.sleep(10)
        return 429


def db_rows(sql):
    sql = sql.replace(chr(96), chr(92) + chr(96))
    cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=60)
    if out.returncode != 0:
        raise RuntimeError(out.stderr[:300])
    return [l.split("\t") for l in out.stdout.strip().split("\n") if l]


def db_exec(sql):
    sql = sql.replace(chr(96), chr(92) + chr(96))
    cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing -e "{sql}"'
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=60)
    if out.returncode != 0:
        raise RuntimeError(out.stderr[:300])


def reset_languages():
    """Restore the language registry to its fixture baseline (and reset the id sequence) so two runs produce
    byte-identical payloads — otherwise the created row's id/timestamps differ between runs for reasons that
    have nothing to do with either implementation."""
    db_exec("DELETE FROM languages WHERE code IN ('es','fr')")
    db_exec("UPDATE languages SET is_default = (code = 'id'), is_active = 1")
    db_exec("UPDATE languages SET sort_order = (id) WHERE code IN ('id','en')")
    db_exec("ALTER TABLE languages AUTO_INCREMENT = 1")
    db_exec("UPDATE languages SET created_at = '2026-11-10 09:00:00', updated_at = '2026-11-10 09:00:00'")
    db_exec("UPDATE settings SET value = 'Kue untuk setiap momen' WHERE `key` = 'site_slogan'")
    db_exec("UPDATE settings SET value = '' WHERE `key` = 'site_contact_email'")


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


def run_sequence(base):
    c = Client(base)
    c.login("super@imp010.test")
    steps = {}
    steps["list_before"] = c.call("GET", "/api/v1/admin/languages")
    steps["settings_get"] = c.call("GET", "/api/v1/admin/settings")
    steps["settings_put"] = c.call("PUT", "/api/v1/admin/settings",
                                   {"site_slogan": "Slogan uji", "site_contact_email": "uji@example.test"})
    steps["settings_get_after"] = c.call("GET", "/api/v1/admin/settings")

    created = c.call("POST", "/api/v1/admin/languages", {"code": "es", "name": "Español", "sort_order": 5})
    steps["lang_create"] = created
    new_id = created[1].get("data", {}).get("id") if created[0] == 201 else None
    if new_id:
        steps["lang_update"] = c.call("PATCH", f"/api/v1/admin/languages/{new_id}", {"name": "Español (ES)"})
        steps["lang_toggle"] = c.call("PATCH", f"/api/v1/admin/languages/{new_id}/toggle-active")
        steps["lang_set_default_inactive"] = c.call("PATCH", f"/api/v1/admin/languages/{new_id}/set-default")
        steps["lang_toggle_back"] = c.call("PATCH", f"/api/v1/admin/languages/{new_id}/toggle-active")
        steps["lang_set_default"] = c.call("PATCH", f"/api/v1/admin/languages/{new_id}/set-default")
        steps["lang_toggle_default"] = c.call("PATCH", f"/api/v1/admin/languages/{new_id}/toggle-active")
    # Duplicate code must fail.
    steps["lang_dup"] = c.call("POST", "/api/v1/admin/languages", {"code": "es", "name": "Duplicado"})
    # Invalid code length.
    steps["lang_invalid"] = c.call("POST", "/api/v1/admin/languages", {"code": "toolong", "name": "X"})
    steps["list_after"] = c.call("GET", "/api/v1/admin/languages")
    return steps, new_id


import re as _re

TS_RE = _re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")


def normalise(body):
    """Make two sequential runs comparable.

    Two things legitimately differ between run A (Laravel) and run B (Go) even for identical behaviour:
      * wall-clock timestamps, and
      * list order where the reference has no ORDER BY tiebreak.
    So timestamps are replaced by a marker ONLY after their FORMAT is checked against Eloquent's
    `...T..:..:..000000Z` shape (a format difference still fails), and lists are sorted by a stable key.
    Nothing else is normalised.
    """
    def walk(v):
        if isinstance(v, dict):
            return {k: walk(x) for k, x in v.items()}
        if isinstance(v, list):
            items = [walk(x) for x in v]
            if items and all(isinstance(i, dict) for i in items):
                items.sort(key=lambda d: json.dumps(d, sort_keys=True))
            return items
        if isinstance(v, str) and TS_RE.match(v):
            return "<timestamp>"
        return v
    return walk(body)


def main():
    # Guard: a non-super_admin must be refused by both.
    reset_languages()
    lsteps, lid = run_sequence(LARAVEL)
    lstate = db_rows("SELECT code, is_default, is_active, sort_order FROM languages ORDER BY code")

    reset_languages()
    gsteps, gid = run_sequence(GO)
    gstate = db_rows("SELECT code, is_default, is_active, sort_order FROM languages ORDER BY code")

    for key in lsteps:
        if key not in gsteps:
            check(f"{key}", False, "missing in Go run")
            continue
        ls, lb = lsteps[key]
        gs, gb = gsteps[key]
        if ls != gs:
            check(f"{key}: status", False, f"{ls} vs {gs} ({(lb or {}).get('message')!r} vs {(gb or {}).get('message')!r})")
            continue
        lb2, gb2 = normalise(lb), normalise(gb)
        diffs = walk(lb2, gb2)
        if diffs:
            check(f"{key}", False, " | ".join(diffs[:5]))
        else:
            check(f"{key}: status {ls}", True)

    check("language table state identical", lstate == gstate, f"{lstate} vs {gstate}")

    # RBAC: a konsumen must not reach the config surface.
    for base, label in ((LARAVEL, "laravel"), (GO, "go")):
        c = Client(base)
        c.login("konsumen@imp010.test")
        st, _ = c.call("GET", "/api/v1/admin/languages")
        check(f"{label}: konsumen refused on /admin/languages", st == 403, f"status {st}")

    passed = sum(1 for _, ok, _ in results if ok)
    for name, ok, detail in results:
        if not ok:
            print(f"FAIL  {name} — {detail}")
    print(f"\n{passed} passed, {len(results) - passed} failed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(main())
