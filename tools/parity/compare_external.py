#!/usr/bin/env python3
"""GLM differential harness — EXTERNAL-INTEGRATION ROUTES (4 Laravel-only).

  POST /checkout/courier-options                         RajaOngkir courier picker
  POST /checkout/geocode                                 GPS → regional master
  POST /agent/shipping-providers/{id}/destinations       RajaOngkir destination search
  POST /agent/shipping-providers/{id}/test               provider connectivity test

In the isolated env every external provider is unreachable, so the reference answers
are deterministic: shared 422s for the RajaOngkir paths, matched:false for geocode.
The Go runtime mirrors that (GEOCODE_PROVIDER=noop matches the reference container's
no-external-network reality; Nominatim itself is ported for production use).

Usage: python3 tools/parity/compare_external.py
"""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_users import SUB, AGEN
from compare_profile_verification import scrub_ids


def scrub(v):
    return scrub_ids(f.norm(v)) if hasattr(f, "norm") else scrub_ids(v)


def scenario(base):
    out = {}
    sub = f.client_for(base, SUB)
    agen = f.client_for(base, AGEN)
    pid = int(db("SELECT id FROM products LIMIT 1")[0][0])
    vid = db("SELECT id FROM villages LIMIT 1")[0][0]

    # geocode
    out["geo_ok"] = sub.call("POST", "/api/v1/checkout/geocode", {"latitude": -6.9, "longitude": 107.6})
    out["geo_missing"] = sub.call("POST", "/api/v1/checkout/geocode", {})
    out["geo_out_of_range"] = sub.call("POST", "/api/v1/checkout/geocode", {"latitude": 95, "longitude": 107.6})

    # courier options
    dest = {"recipient_name": "T", "recipient_phone": "0811", "address_line": "Jl X",
            "village_id": vid, "latitude": -6.9, "longitude": 107.6}
    out["co_ok"] = sub.call("POST", "/api/v1/checkout/courier-options",
                            dict(items=[{"product_id": pid, "quantity": 1}], **dest))
    out["co_bad_product"] = sub.call("POST", "/api/v1/checkout/courier-options",
                                     dict(items=[{"product_id": 99999, "quantity": 1}], **dest))
    out["co_missing_items"] = sub.call("POST", "/api/v1/checkout/courier-options", dest)
    out["co_missing_dest"] = sub.call("POST", "/api/v1/checkout/courier-options",
                                      {"items": [{"product_id": pid, "quantity": 1}]})
    out["co_bad_quantity"] = sub.call("POST", "/api/v1/checkout/courier-options",
                                      dict(items=[{"product_id": pid, "quantity": 0}], **dest))

    # agent provider destinations / test (providers resolved BY ID like the reference binding)
    out["dest_ok"] = agen.call("POST", "/api/v1/agent/shipping-providers/1/destinations", {"search": "Bandung"})
    out["dest_short"] = agen.call("POST", "/api/v1/agent/shipping-providers/1/destinations", {"search": "Ba"})
    out["dest_missing"] = agen.call("POST", "/api/v1/agent/shipping-providers/1/destinations", {})
    out["dest_not_rajaongkir"] = agen.call("POST", "/api/v1/agent/shipping-providers/3/destinations", {"search": "Bandung"})
    out["dest_by_code"] = agen.call("POST", "/api/v1/agent/shipping-providers/rajaongkir/destinations", {"search": "Bandung"})

    out["test_rajaongkir"] = agen.call("POST", "/api/v1/agent/shipping-providers/1/test")
    out["test_ors"] = agen.call("POST", "/api/v1/agent/shipping-providers/2/test",
                                {"destination_latitude": -6.9, "destination_longitude": 107.6})
    out["test_ors_missing"] = agen.call("POST", "/api/v1/agent/shipping-providers/2/test", {})
    out["test_ors_out_of_range"] = agen.call("POST", "/api/v1/agent/shipping-providers/2/test",
                                             {"destination_latitude": 95, "destination_longitude": 107.6})
    out["test_unknown_id"] = agen.call("POST", "/api/v1/agent/shipping-providers/999/test")
    return out


def main():
    lar = scenario(LARAVEL)
    go = scenario(GO)
    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        lv, gv = scrub_ids(norm_body(lar[key])), scrub_ids(norm_body(go[key]))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv, default=str)[:500])
            print("  G=" + json.dumps(gv, default=str)[:500])
    print(f"external: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
