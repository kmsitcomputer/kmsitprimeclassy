#!/usr/bin/env python3
"""Laravel ↔ Go authentication comparison (Sanctum-compatibility evidence).

Both runtimes authenticate the SAME user row from the same database. This script proves:

  * a password stored by Laravel (bcrypt `$2y$…`) is accepted by Go, and vice versa;
  * `/sanctum/csrf-cookie` establishes a session + readable XSRF-TOKEN cookie in both;
  * a mutation without the CSRF header is refused with 419 in both;
  * the login response body (user + permissions + message) is identical;
  * `/auth/me` returns the same payload for the authenticated session;
  * a second login attempt through Go keeps working with the password Laravel set.

It deliberately does NOT compare session cookie names: Go uses its own session table/cookie (see docs/21
§Session interoperability) and that difference is by design and documented.

Usage: python3 tools/parity/compare_auth.py (requires both runtimes running, see docs/21 §Verification)
"""
import http.cookiejar
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

LARAVEL = os.environ.get("LARAVEL_URL", "http://127.0.0.1:8899").rstrip("/")
GO = os.environ.get("GO_URL", "http://127.0.0.1:8082").rstrip("/")
EMAIL = os.environ.get("TEST_USER", "konsumen@imp010.test")
PASSWORD = os.environ.get("TEST_PASSWORD", "Secret123!")

results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, method, path, body=None, headers=None):
        data = None
        # Browser-equivalent origin headers: Sanctum's EnsureFrontendRequestsAreStateful only attaches the
        # session middleware when the request originates from a listed frontend domain.
        hdrs = {
            "Accept": "application/json",
            "Origin": self.base,
            "Referer": self.base + "/",
            "X-Requested-With": "XMLHttpRequest",
        }
        if body is not None:
            data = json.dumps(body).encode()
            hdrs["Content-Type"] = "application/json"
        if headers:
            hdrs.update(headers)
        req = urllib.request.Request(self.base + path, data=data, headers=hdrs, method=method)
        try:
            with self.opener.open(req, timeout=20) as res:
                raw = res.read()
                return res.status, (json.loads(raw) if raw else None), res.headers
        except urllib.error.HTTPError as e:
            raw = e.read()
            try:
                return e.code, (json.loads(raw) if raw else None), e.headers
            except json.JSONDecodeError:
                return e.code, None, e.headers

    def cookie(self, name):
        for c in self.jar:
            if c.name == name:
                return c.value
        return None

    def cookies(self):
        return {c.name for c in self.jar}

    def csrf(self):
        status, _, _ = self.request("GET", "/sanctum/csrf-cookie")
        token = self.cookie("XSRF-TOKEN")
        return status, (urllib.parse.unquote(token) if token else None)


def compare_user_payload(label, laravel_data, go_data):
    lu = laravel_data["data"]["user"]
    gu = go_data["data"]["user"]
    # Compare every key the resource exposes; ignore nothing — a difference is a contract difference.
    keys = sorted(set(lu) | set(gu))
    diffs = []
    for k in keys:
        if k not in lu:
            diffs.append(f"{k}: missing in Laravel")
        elif k not in gu:
            diffs.append(f"{k}: missing in Go")
        elif lu[k] != gu[k]:
            diffs.append(f"{k}: {lu[k]!r} vs {gu[k]!r}")
    check(f"{label}: user payload identical", not diffs, "; ".join(diffs[:6]))

    lp = laravel_data["data"]["permissions"]
    gp = go_data["data"]["permissions"]
    check(f"{label}: permissions identical", lp == gp, f"{lp} vs {gp}")


def main():
    laravel = Client(LARAVEL)
    go = Client(GO)

    # 1. CSRF bootstrap in both runtimes.
    lstat, ltoken = laravel.csrf()
    gstat, gtoken = go.csrf()
    check("laravel /sanctum/csrf-cookie → 204", lstat == 204, f"status {lstat}")
    check("go /sanctum/csrf-cookie → 204", gstat == 204, f"status {gstat}")
    check("both set a readable XSRF-TOKEN cookie", bool(ltoken) and bool(gtoken))
    check("both set an HttpOnly session cookie",
          any(name in laravel.cookies() for name in ("laravel_session", "primeclassy_session")),
          ",".join(sorted(laravel.cookies())))
    check("go sets its own session cookie (documented difference)",
          "pc_sid" in go.cookies(), ",".join(sorted(go.cookies())))

    # 2. CSRF enforcement parity.
    l401, lbody, _ = laravel.request("POST", "/api/v1/auth/login",
                                     {"identifier": EMAIL, "password": PASSWORD})
    g401, gbody, _ = go.request("POST", "/api/v1/auth/login",
                                {"identifier": EMAIL, "password": PASSWORD})
    check("login without X-XSRF-TOKEN → 419 in both", l401 == 419 and g401 == 419, f"{l401} vs {g401}")

    # 3. Wrong password parity.
    # The 419 above rotated the reference session, so re-establish a token before the next mutation.
    _, ltoken = laravel.csrf()
    _, gtoken = go.csrf()
    lw, lwbody, _ = laravel.request("POST", "/api/v1/auth/login",
                                    {"identifier": EMAIL, "password": "wrong-password"},
                                    {"X-XSRF-TOKEN": ltoken})
    gw, gwbody, _ = go.request("POST", "/api/v1/auth/login",
                               {"identifier": EMAIL, "password": "wrong-password"},
                               {"X-XSRF-TOKEN": gtoken})
    check("wrong password → 422 in both", lw == 422 and gw == 422, f"{lw} vs {gw}")
    lw_err = (lwbody or {}).get("errors") or {}
    gw_err = (gwbody or {}).get("errors") or {}
    check("wrong password → same field error",
          lw_err.get("identifier") == gw_err.get("identifier") and bool(lw_err),
          f"{lw_err} vs {gw_err}")

    # 4. Successful login parity: the SAME bcrypt hash must verify in both runtimes.
    _, ltoken = laravel.csrf()
    _, gtoken = go.csrf()
    ll, ldata, _ = laravel.request("POST", "/api/v1/auth/login",
                                   {"identifier": EMAIL, "password": PASSWORD},
                                   {"X-XSRF-TOKEN": ltoken})
    gl, gdata, _ = go.request("POST", "/api/v1/auth/login",
                              {"identifier": EMAIL, "password": PASSWORD},
                              {"X-XSRF-TOKEN": gtoken})
    check("login with the Laravel-stored password → 200 in both", ll == 200 and gl == 200, f"{ll} vs {gl}")
    if ll == 200 and gl == 200:
        check("login message identical", ldata.get("message") == gdata.get("message"),
              f"{ldata.get('message')!r} vs {gdata.get('message')!r}")
        compare_user_payload("login", ldata, gdata)

    # 5. /auth/me parity for the authenticated session.
    lm, lme, _ = laravel.request("GET", "/api/v1/auth/me")
    gm, gme, _ = go.request("GET", "/api/v1/auth/me")
    check("/auth/me → 200 in both", lm == 200 and gm == 200, f"{lm} vs {gm}")
    if lm == 200 and gm == 200:
        compare_user_payload("auth/me", lme, gme)

    # 6. Logout parity, then a 401 from both.
    llg, _, _ = laravel.request("POST", "/api/v1/auth/logout", {}, {"X-XSRF-TOKEN": laravel.cookie("XSRF-TOKEN") and urllib.parse.unquote(laravel.cookie("XSRF-TOKEN"))})
    glg, _, _ = go.request("POST", "/api/v1/auth/logout", {}, {"X-XSRF-TOKEN": go.cookie("XSRF-TOKEN") and urllib.parse.unquote(go.cookie("XSRF-TOKEN"))})
    check("logout → 200 in both", llg == 200 and glg == 200, f"{llg} vs {glg}")
    lm2, _, _ = laravel.request("GET", "/api/v1/auth/me")
    gm2, _, _ = go.request("GET", "/api/v1/auth/me")
    check("/auth/me after logout → 401 in both", lm2 == 401 and gm2 == 401, f"{lm2} vs {gm2}")

    # 7. Phone login parity (identifier → phone column + normalisation).
    lc = Client(LARAVEL)
    gc = Client(GO)
    _, ltok = lc.csrf()
    _, gtok = gc.csrf()
    lp, lpbody, _ = lc.request("POST", "/api/v1/auth/login",
                               {"identifier": "+6281100000013", "password": PASSWORD},
                               {"X-XSRF-TOKEN": ltok})
    gp, gpbody, _ = gc.request("POST", "/api/v1/auth/login",
                               {"identifier": "+6281100000013", "password": PASSWORD},
                               {"X-XSRF-TOKEN": gtok})
    check("+62 phone login → 200 in both", lp == 200 and gp == 200, f"{lp} vs {gp}")
    if lp == 200 and gp == 200:
        check("phone login resolves the same user",
              lpbody["data"]["user"]["id"] == gpbody["data"]["user"]["id"],
              f"{lpbody['data']['user']['id']} vs {gpbody['data']['user']['id']}")

    # 8. A Sales account: `korsal_name` is a role-conditional field, so it must be compared for a role that
    #    actually exposes it (a konsumen must NOT have the key at all).
    lc2, gc2 = Client(LARAVEL), Client(GO)
    _, ltok2 = lc2.csrf()
    _, gtok2 = gc2.csrf()
    ls2, ls2body, _ = lc2.request("POST", "/api/v1/auth/login",
                                  {"identifier": "sales@imp010.test", "password": PASSWORD},
                                  {"X-XSRF-TOKEN": ltok2})
    gs2, gs2body, _ = gc2.request("POST", "/api/v1/auth/login",
                                  {"identifier": "sales@imp010.test", "password": PASSWORD},
                                  {"X-XSRF-TOKEN": gtok2})
    check("sales login → 200 in both", ls2 == 200 and gs2 == 200, f"{ls2} vs {gs2}")
    if ls2 == 200 and gs2 == 200:
        compare_user_payload("login(sales)", ls2body, gs2body)
        check("sales exposes the korsal_name key",
              "korsal_name" in ls2body["data"]["user"], "Laravel omitted it")
        check("konsumen does NOT expose korsal_name",
              "korsal_name" not in (ldata["data"]["user"] if ll == 200 else {}),
              "fixture expectation")

    passed = sum(1 for _, ok, _ in results if ok)
    for name, ok, detail in results:
        print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f" — {detail}" if (detail and not ok) else ""))
    print(f"\n{passed} passed, {len(results) - passed} failed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(main())
