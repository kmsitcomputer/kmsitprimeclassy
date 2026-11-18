#!/usr/bin/env python3
"""Laravel ↔ Go comparison for POST /api/v1/checkout/quote — the Review-step preview.

This endpoint is the arithmetic the customer SEES before paying, so the comparison is strict: the whole body
must match, and the error envelopes must match in SHAPE as well as text (Laravel distinguishes a
ValidationException — `{"field": ["msg"]}` — from an ApiException — `{"field": "msg"}`).

Covered:
  * the pickup path (no provider needed, Rp0 shipping) and the address-book vs inline destination paths;
  * fixed / percentage / product-targeted vouchers, an inapplicable voucher and an unknown code;
  * the "provider attempted but failed" 422 (shipping sentinel) and the no-choice path;
  * validation: empty items, quantity bounds, missing destination fields, variation required/not allowed,
    an unknown village and a region hierarchy mismatch;
  * authorization: guest 401, non-ordering role 403, staff ordering for a konsumen.

Nothing is created by the endpoint, so the fixture database is only READ. The harness still re-seeds nothing —
run tools/parity/seed_test_fixtures.sql first (docs/21c §4b).

Usage: python3 tools/parity/compare_order_quote.py
"""
import http.cookiejar
import json
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
            with self.opener.open(req, timeout=30) as res:
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
        return self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": password})[0]


DEST = {"recipient_name": "Budi", "recipient_phone": "081200000000", "address_line": "Jl. Tes 1",
        "village_id": "32730101", "latitude": -6.9, "longitude": 107.6}


def quote_body(**overrides):
    body = {"items": [{"product_id": 1, "quantity": 2}], "shipping_method": "pickup"}
    body.update(DEST)
    body.update(overrides)
    return body


SCENARIOS = [
    # name, payload, login-as
    ("pickup happy path", quote_body(), "konsumen@imp010.test"),
    ("pickup with address book", {"items": [{"product_id": 1, "quantity": 1}], "address_id": 1,
                                  "shipping_method": "pickup"}, "konsumen@imp010.test"),
    ("fixed voucher", quote_body(voucher_code="HEMAT10K"), "konsumen@imp010.test"),
    ("percentage voucher", quote_body(voucher_code="PERSEN10"), "konsumen@imp010.test"),
    ("product-targeted voucher (applicable)", quote_body(voucher_code="HANYA-LUMPUR"), "konsumen@imp010.test"),
    ("product-targeted voucher (inapplicable)",
     quote_body(items=[{"product_id": 2, "product_variation_id": 1, "quantity": 1}], voucher_code="HANYA-LUMPUR"),
     "konsumen@imp010.test"),
    ("unknown voucher", quote_body(voucher_code="NOPE"), "konsumen@imp010.test"),
    ("variation product priced by variation",
     quote_body(items=[{"product_id": 2, "product_variation_id": 1, "quantity": 2}]), "konsumen@imp010.test"),
    ("no shipping method chosen", {k: v for k, v in quote_body().items() if k != "shipping_method"},
     "konsumen@imp010.test"),
    ("explicit rajaongkir (unconfigured provider)", quote_body(shipping_method="rajaongkir"),
     "konsumen@imp010.test"),
    ("explicit openroute (unconfigured provider)", quote_body(shipping_method="openroute"),
     "konsumen@imp010.test"),
    ("empty items", {"items": [], "shipping_method": "pickup", **DEST}, "konsumen@imp010.test"),
    ("quantity zero", quote_body(items=[{"product_id": 1, "quantity": 0}]), "konsumen@imp010.test"),
    ("quantity above 1000", quote_body(items=[{"product_id": 1, "quantity": 1001}]), "konsumen@imp010.test"),
    ("missing destination", {"items": [{"product_id": 1, "quantity": 1}], "shipping_method": "pickup"},
     "konsumen@imp010.test"),
    ("variation required", quote_body(items=[{"product_id": 2, "quantity": 1}]), "konsumen@imp010.test"),
    ("variation not allowed", quote_body(items=[{"product_id": 1, "product_variation_id": 1, "quantity": 1}]),
     "konsumen@imp010.test"),
    ("unknown village", quote_body(village_id="99999999"), "konsumen@imp010.test"),
    ("region hierarchy mismatch", quote_body(regency_id="9999"), "konsumen@imp010.test"),
    ("invalid shipping method", quote_body(shipping_method="drone"), "konsumen@imp010.test"),
    ("courier without service", quote_body(courier="jne"), "konsumen@imp010.test"),
    # Authorization surface.
    ("sales ordering for their own konsumen", quote_body(konsumen_id=17), "sales@imp010.test"),
    ("sales ordering for a konsumen outside their book", quote_body(konsumen_id=13), "sales@imp010.test"),
    ("admin may not order", quote_body(), "admin@imp010.test"),
    ("guest", quote_body(), None),
]


_clients = {}


def client_for(base, email):
    """One session per (runtime, account): the login endpoint is throttled at 10/min on BOTH runtimes, so a
    fresh login per scenario would measure the limiter instead of the quote endpoint."""
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if email and c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


def compare(name, payload, email):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        c = client_for(base, email)
        out[label] = c.call("POST", "/api/v1/checkout/quote", payload)

    status_l, body_l = out["L"]
    status_g, body_g = out["G"]
    if status_l != status_g:
        check(name, False, f"status {status_l} vs {status_g}: {json.dumps(body_l)[:200]} | {json.dumps(body_g)[:200]}")
        return
    # Byte-level comparison of the JSON body: this endpoint's contract is the numbers AND the envelope shape,
    # so a float/int spelling difference is normalised but a string-vs-array `errors` difference is not.
    if normalise(body_l) == normalise(body_g):
        check(name, True)
        return
    check(name, False, f"L={json.dumps(normalise(body_l))[:400]} G={json.dumps(normalise(body_g))[:400]}")


def normalise(value):
    if isinstance(value, dict):
        return {k: normalise(v) for k, v in sorted(value.items())}
    if isinstance(value, list):
        return [normalise(v) for v in value]
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return float(value)
    return value


def main():
    for name, payload, email in SCENARIOS:
        compare(name, payload, email)

    failed = [r for r in results if not r[1]]
    for name, ok, detail in results:
        if not ok:
            print(f"FAIL {name}: {detail}")
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
