#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the CHECKOUT domain — GET /api/v1/checkout/steps.

The endpoint is the entrance to the whole order chain and is PUBLIC, so the comparison covers the guest path
and every role-dependent branch of CheckoutStepResolver:

  * a guest sees `account` active, `on_behalf_of_konsumen` false, and no agent-scoped data at all;
  * a konsumen checking out for themselves sees ONLY bank_transfer (Owner rule, Improvement 04) and is not
    "on behalf of" anyone;
  * a staff role (sales/agen/super_admin) sees `on_behalf_of_konsumen` true and the agent's full method list;
  * agent-level provider switches (`agent_shipping_provider_settings.is_active = 0`) remove a method AND flip
    `map_picker_enabled`;
  * the KMSIT fallback keeps "Kurir Toko" offerable when the openroute row itself is inactive but the agent has
    a kmsit config — the branch the naive port of `activeProviders` gets wrong;
  * an agent with no `agent_profiles` row gets no pickup, and with every provider off the shipping step turns
    inactive entirely;
  * `?shipping_method=rajaongkir` narrows payment_methods to bank_transfer, while an unrecognised value is
    treated as "not chosen yet" (unrestricted), mirroring the controller's in_array guard.

Every scenario mutates the SHARED isolated test database, so both runtimes are asked the same question about
the same rows — never a mock.

Usage: python3 tools/parity/compare_checkout.py
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
        raise RuntimeError(out.stderr[:400])
    return [l.split("\t") for l in out.stdout.strip().split("\n") if l]


def reset_config():
    """Restore the seeded checkout configuration (both providers active, kmsit inactive, no overrides)."""
    db("DELETE FROM agent_shipping_provider_settings")
    db("DELETE FROM agent_shipping_provider_configs")
    db("DELETE FROM agent_payment_method_settings")
    db("UPDATE shipping_providers SET is_active = CASE code "
       "WHEN 'rajaongkir' THEN 1 WHEN 'openroute' THEN 1 ELSE 0 END")


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
            out.append(f"{path}: length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            walk(x, y, f"{path}[{i}]", out)
        return out
    if isinstance(a, float) and isinstance(b, float):
        if abs(a - b) > 1e-9:
            out.append(f"{path}: {a} vs {b}")
        return out
    if a != b:
        out.append(f"{path}: {a!r} vs {b!r}")
    return out


def get_steps(base, query="", email=None):
    c = Client(base)
    if email:
        st = c.login(email)
        if st != 200:
            return st, {"_login_failed": st}
    return c.call("GET", "/api/v1/checkout/steps" + query)


def compare(name, query="", email=None):
    st_l, body_l = get_steps(LARAVEL, query, email)
    st_g, body_g = get_steps(GO, query, email)
    if st_l != st_g:
        check(name, False, f"status {st_l} vs {st_g}: {json.dumps(body_l)[:200]} | {json.dumps(body_g)[:200]}")
        return None
    diffs = walk(normalise(body_l), normalise(body_g))
    check(name, not diffs, "; ".join(diffs[:6]))
    return body_l


def summarise(body):
    if not body or "data" not in body:
        return ""
    d = body["data"]
    return (f"enabled={d['shipping_enabled']} map={d['map_picker_enabled']} "
            f"methods={[m['code'] for m in d['shipping_methods']]} "
            f"pay={[m['code'] for m in d['payment_methods']]} on_behalf={d['on_behalf_of_konsumen']}")


def main():
    reset_config()

    # --- role-independent contract ---------------------------------------------------------------
    anon = compare("guest /checkout/steps")
    if anon:
        active = {s["key"]: s["active"] for s in anon["data"]["steps"]}
        check("guest: account step active", active.get("account") is True, str(active))
        check("guest: referral step never active", active.get("referral") is False)
        check("guest: no agent-scoped pickup", anon["data"]["pickup_location"] is None)
        check("guest: not on-behalf-of", anon["data"]["on_behalf_of_konsumen"] is False)
        # A guest has no branch, so pickup is not offered — but GLOBALLY active delivery providers still are.
        check("guest: delivery providers only, no pickup",
              [m["code"] for m in anon["data"]["shipping_methods"]] == ["openroute", "rajaongkir"],
              summarise(anon))
        check("guest: step order is canonical",
              [s["key"] for s in anon["data"]["steps"]] ==
              ["account", "address", "referral", "products", "shipping", "delivery_date",
               "payment", "review", "confirmation"])

    kons = compare("konsumen /checkout/steps", email="konsumen@imp010.test")
    if kons:
        check("konsumen: only bank_transfer",
              [m["code"] for m in kons["data"]["payment_methods"]] == ["bank_transfer"], summarise(kons))
        check("konsumen: not on-behalf-of", kons["data"]["on_behalf_of_konsumen"] is False)
        check("konsumen: account step inactive", kons["data"]["steps"][0]["active"] is False)
        check("konsumen: pickup location from own branch", kons["data"]["pickup_location"] is not None,
              json.dumps(kons["data"]["pickup_location"]))
        check("konsumen: shipping methods are openroute,rajaongkir,pickup (code order)",
              [m["code"] for m in kons["data"]["shipping_methods"]] == ["openroute", "rajaongkir", "pickup"],
              summarise(kons))

    compare("konsumen ?shipping_method=rajaongkir", "?shipping_method=rajaongkir",
            email="konsumen@imp010.test")
    compare("konsumen ?shipping_method=openroute", "?shipping_method=openroute",
            email="konsumen@imp010.test")
    # Unrecognised value must behave like "not chosen yet" (unrestricted), not like an empty list.
    compare("konsumen ?shipping_method=pickup (unrecognised)", "?shipping_method=pickup",
            email="konsumen@imp010.test")

    sales = compare("sales /checkout/steps", email="sales@imp010.test")
    if sales:
        check("sales: staff sees cod + dp + bank_transfer",
              [m["code"] for m in sales["data"]["payment_methods"]] == ["bank_transfer", "cod", "down_payment"],
              summarise(sales))
        check("sales: on-behalf-of true", sales["data"]["on_behalf_of_konsumen"] is True)
        check("sales: gateway never listed",
              all(m["type"] != "gateway" for m in sales["data"]["payment_methods"]))

    compare("sales ?shipping_method=rajaongkir", "?shipping_method=rajaongkir", email="sales@imp010.test")
    compare("agen /checkout/steps", email="agen@imp010.test")
    compare("super_admin /checkout/steps", email="super@imp010.test")

    # --- agent-level provider switches -----------------------------------------------------------
    db("INSERT INTO agent_shipping_provider_settings (agent_id, shipping_provider_id, is_active, created_at, updated_at) "
       "VALUES (10, 2, 0, NOW(), NOW())")  # openroute off for this branch
    off = compare("agen: openroute switched off for the branch", email="agen@imp010.test")
    if off:
        check("branch switch: openroute removed",
              [m["code"] for m in off["data"]["shipping_methods"]] == ["rajaongkir", "pickup"], summarise(off))
        check("branch switch: map picker disabled", off["data"]["map_picker_enabled"] is False)

    db("INSERT INTO agent_shipping_provider_settings (agent_id, shipping_provider_id, is_active, created_at, updated_at) "
       "VALUES (10, 1, 0, NOW(), NOW())")  # rajaongkir off too
    onlypickup = compare("agen: both providers switched off (pickup only)", email="agen@imp010.test")
    if onlypickup:
        check("pickup only: methods == [pickup]",
              [m["code"] for m in onlypickup["data"]["shipping_methods"]] == ["pickup"], summarise(onlypickup))
        check("pickup only: shipping step still active", onlypickup["data"]["shipping_enabled"] is True)

    # --- KMSIT fallback keeps Kurir Toko offerable ------------------------------------------------
    reset_config()
    db("UPDATE shipping_providers SET is_active = 1 WHERE code = 'kmsit'")
    db("UPDATE shipping_providers SET is_active = 0 WHERE code = 'openroute'")
    db("INSERT INTO agent_shipping_provider_configs (agent_id, shipping_provider_id, config, created_at, updated_at) "
       "VALUES (10, 3, '{\"base_url\":\"http://kmsit.invalid\",\"profile\":\"driving\"}', NOW(), NOW())")
    kmsit = compare("agen: openroute row inactive but KMSIT fallback eligible", email="agen@imp010.test")
    if kmsit:
        check("kmsit fallback: Kurir Toko still offered",
              [m["code"] for m in kmsit["data"]["shipping_methods"]] == ["openroute", "rajaongkir", "pickup"],
              summarise(kmsit))
        check("kmsit fallback: map picker enabled", kmsit["data"]["map_picker_enabled"] is True)

    # --- nothing to offer at all ------------------------------------------------------------------
    reset_config()
    db("UPDATE shipping_providers SET is_active = 0")
    db("DELETE FROM agent_profiles")
    nothing = compare("agen: no providers and no store profile", email="agen@imp010.test")
    if nothing:
        check("nothing offered: shipping step inactive", nothing["data"]["shipping_enabled"] is False)
        check("nothing offered: pickup null", nothing["data"]["pickup_location"] is None)
        check("nothing offered: methods empty", nothing["data"]["shipping_methods"] == [])
    db("INSERT INTO agent_profiles (id, user_id, store_name, address, latitude, longitude, phone, created_at, updated_at) "
       "VALUES (1,10,'Toko Prime Classy Imp010','Jl. Uji 1, Bandung',-6.9000000,107.6000000,'081100000010',NOW(),NOW())")

    # --- agent payment-method switch ---------------------------------------------------------------
    reset_config()
    db("INSERT INTO agent_payment_method_settings (agent_id, payment_method_id, is_active, active_environment, created_at, updated_at) "
       "VALUES (10, 2, 0, 'sandbox', NOW(), NOW())")  # cod off
    payoff = compare("agen: COD switched off for the branch", email="agen@imp010.test")
    if payoff:
        check("payment switch: cod removed",
              [m["code"] for m in payoff["data"]["payment_methods"]] == ["bank_transfer", "down_payment"],
              summarise(payoff))

    reset_config()

    failed = [r for r in results if not r[1]]
    for name, ok, detail in results:
        if not ok:
            print(f"FAIL {name}: {detail}")
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
