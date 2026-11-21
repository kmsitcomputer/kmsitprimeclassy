#!/usr/bin/env python3
"""GLM differential harness — GOOGLE OAUTH redirects (2 Laravel-only routes).

  GET /auth/google/redirect  begin authorization (config resolution agent→global→env)
  GET /auth/google/callback  complete authorization

In the isolated env Google is not configured, so redirect lands on the SPA error
path (mode-aware) and callback without a flow is invalid_state. Locations are
compared as path?query (the reference emits a relative Location; the Go runtime
emits an absolute same-origin one — identical target).

Usage: python3 tools/parity/compare_google.py
"""
import json
import sys
import urllib.parse
import urllib.request

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO


def location(base, path):
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    opener = urllib.request.build_opener(NoRedirect)
    req = urllib.request.Request(base + path, headers={"Accept": "application/json"})
    try:
        with opener.open(req, timeout=30) as res:
            return res.status, res.headers.get("Location", "")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.headers.get("Location", "")


def loc_target(loc):
    p = urllib.parse.urlparse(loc)
    return p.path + ("?" + p.query if p.query else "")


def scenario(base):
    out = {}
    out["redirect_login"] = loc_target(location(base, "/api/v1/auth/google/redirect?mode=login")[1])
    out["redirect_register"] = loc_target(location(base, "/api/v1/auth/google/redirect?mode=register")[1])
    out["redirect_link"] = loc_target(location(base, "/api/v1/auth/google/redirect?mode=link")[1])
    out["redirect_default"] = loc_target(location(base, "/api/v1/auth/google/redirect")[1])
    out["redirect_bad_mode"] = loc_target(location(base, "/api/v1/auth/google/redirect?mode=bogus")[1])
    out["redirect_with_referral"] = loc_target(location(base, "/api/v1/auth/google/redirect?mode=register&referral_code=ABC123")[1])
    out["callback"] = loc_target(location(base, "/api/v1/auth/google/callback")[1])
    out["callback_error"] = loc_target(location(base, "/api/v1/auth/google/callback?error=access_denied")[1])
    return out


def main():
    lar = scenario(LARAVEL)
    go = scenario(GO)
    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        ok = lar[key] == go[key]
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lar[key]))
            print("  G=" + json.dumps(go[key]))
    print(f"google_oauth: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
