#!/usr/bin/env python3
"""Read-only Laravel ↔ Go differential for the branch-scoped admin returns index."""
import http.cookiejar, json, re, sys, urllib.error, urllib.parse, urllib.request

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"
TS = re.compile(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|\+00:00)?$")


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def call(self, method, path, body=None, headers=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest", "User-Agent": "IMP010-admin-returns-parity"}
        if headers:
            h.update(headers)
        token = next((urllib.parse.unquote(c.value) for c in self.jar if c.name == "XSRF-TOKEN"), None)
        if token:
            h["X-XSRF-TOKEN"] = token
        try:
            with self.opener.open(urllib.request.Request(self.base + path, data=body, headers=h, method=method), timeout=45) as r:
                raw = r.read().decode()
                return r.status, json.loads(raw) if raw else None
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, json.loads(raw)
            except Exception:
                return e.code, {"raw": raw}

    def login(self, email):
        self.call("GET", "/sanctum/csrf-cookie")
        return self.call("POST", "/api/v1/auth/login", json.dumps({"identifier": email, "password": "Secret123!"}).encode(),
                         {"Content-Type": "application/json"})[0]


def normalize(value):
    if isinstance(value, dict):
        return {k: normalize(v) for k, v in value.items()}
    if isinstance(value, list):
        return [normalize(v) for v in value]
    if isinstance(value, str) and TS.match(value):
        return "<timestamp>"
    return value


def scenarios(base):
    out = {}
    for label, email, query in [
        ("admin_default", "admin@imp010.test", ""),
        ("admin_page", "admin@imp010.test", "?per_page=1&page=2"),
        ("admin_status_multi", "admin@imp010.test", "?status=requested%2Cunder_review&per_page=15"),
        ("admin_status_none", "admin@imp010.test", "?status=not-a-return-status"),
        ("admin_ignores_agent_filter", "admin@imp010.test", "?agent_id=999999"),
        ("admin_invalid_per_page", "admin@imp010.test", "?per_page=bad"),
        ("agen_default", "agen@imp010.test", ""),
        ("super_filter", "super@imp010.test", "?agent_id=10&per_page=2"),
        ("super_all", "super@imp010.test", "?per_page=2"),
        ("consumer_denied", "konsumen@imp010.test", ""),
    ]:
        client = Client(base)
        if client.login(email) != 200:
            out[label] = ("login-failed", email)
            continue
        out[label] = client.call("GET", "/api/v1/admin/returns" + query)
    return out


def main():
    laravel = scenarios(LARAVEL)
    go = scenarios(GO)
    failed = 0
    for name in sorted(laravel):
        left, right = normalize(laravel[name]), normalize(go[name])
        if left != right:
            failed += 1
            print(f"FAIL {name}\n  Laravel={json.dumps(left, sort_keys=True)[:2500]}\n  Go={json.dumps(right, sort_keys=True)[:2500]}")
        else:
            print("PASS " + name)
    print(f"admin returns index: {len(laravel)-failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
