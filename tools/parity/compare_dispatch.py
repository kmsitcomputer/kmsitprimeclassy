#!/usr/bin/env python3
"""GLM differential harness — DISPATCH workspace reads (4 Laravel-only routes).

  GET /api/v1/dispatch/couriers     koordinator-kurir (+ super_admin/agen/admin): branch couriers,
                                    for_returns narrowing, super_admin agent_id gate (422 w/o, 422 unknown)
  GET /api/v1/dispatch/regions      cascading options from ELIGIBLE dispatch orders only; level composition
  GET /api/v1/dispatch/expeditions  koordinator-kurir ONLY; expedition shipments + readiness gates
  GET /api/v1/dispatch/refusals     koordinator-kurir ONLY; open refusals + status filter

RBAC matrix, per-route super_admin agent_id gate, DB state (couriers/delivery_refusals).

Usage: python3 tools/parity/compare_dispatch.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body, seed_full, rows

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6}Z|\+00:00)$")
OPN = re.compile(r"^OPN-\d{14}-[A-Z0-9]{5}$")

results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: scrub(x) for k, x in v.items()}
    if isinstance(v, str):
        if TS.match(v):
            return "<ts>"
        if OPN.match(v):
            return "<opname>"
    return v


KOORD = "koordinator@imp010.test"


def seed_dispatch():
    # Ensure a courier row + an open refusal exist for data-backed comparison.
    db("DELETE FROM delivery_refusals")
    db("DELETE FROM couriers WHERE external_code LIKE 'GLMD%'")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("INSERT INTO couriers (id, type, user_id, agent_id, external_code, name, is_active, created_at, updated_at) "
       "SELECT 901, 'internal', NULL, 10, 'GLMD1', 'Kurir GLM A', 1, NOW(), NOW()")
    db("INSERT INTO couriers (id, type, user_id, agent_id, external_code, name, is_active, created_at, updated_at) "
       "SELECT 902, 'internal', 19, 10, 'GLMD2', 'Kurir GLM B', 1, NOW(), NOW()")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO delivery_refusals (agent_id, order_id, order_item_id, shipment_id, quantity, reason, status, "
       "recorded_by, recorded_at, created_at, updated_at) "
       "SELECT 10, 1, 1, 1, 2, 'alamat salah', 'refused', 15, NOW(), NOW(), NOW() "
       "WHERE EXISTS(SELECT 1 FROM orders WHERE id=1)")
    db("INSERT INTO delivery_refusals (agent_id, order_id, order_item_id, shipment_id, quantity, reason, status, "
       "recorded_by, recorded_at, handed_back_at, handed_back_by, created_at, updated_at) "
       "SELECT 10, 1, 1, 1, 1, 'ditolak penerima', 'handed_back', 15, NOW(), NOW(), 19, NOW(), NOW() "
       "WHERE EXISTS(SELECT 1 FROM orders WHERE id=1)")


def scenario(base):
    out = {}
    koord = f.client_for(base, KOORD)
    agen = f.client_for(base, f.AGEN)
    admin_c = f.client_for(base, f.ADMIN)
    super_c = f.client_for(base, f.SUPER)
    sales = f.client_for(base, f.SALES)
    kurir = f.client_for(base, f.KURIR)

    # RBAC matrix (verified Laravel split)
    for who, c in [("agen", agen), ("admin", admin_c), ("sales", sales), ("kurir", kurir)]:
        out[f"rbac_{who}_couriers"] = c.call("GET", "/api/v1/dispatch/couriers")
        out[f"rbac_{who}_regions"] = c.call("GET", "/api/v1/dispatch/regions")
        out[f"rbac_{who}_expeditions"] = c.call("GET", "/api/v1/dispatch/expeditions")
        out[f"rbac_{who}_refusals"] = c.call("GET", "/api/v1/dispatch/refusals")
    # koordinator CAN read couriers/regions but is the only expeditions/refusals role
    out["koord_couriers"] = koord.call("GET", "/api/v1/dispatch/couriers")
    out["koord_regions"] = koord.call("GET", "/api/v1/dispatch/regions")
    out["koord_expeditions"] = koord.call("GET", "/api/v1/dispatch/expeditions")
    out["koord_refusals"] = koord.call("GET", "/api/v1/dispatch/refusals")

    # super_admin agent_id gate
    out["super_no_agent"] = super_c.call("GET", "/api/v1/dispatch/couriers")
    out["super_bad_agent"] = super_c.call("GET", "/api/v1/dispatch/couriers?agent_id=999999")
    out["super_ok_agent"] = super_c.call("GET", "/api/v1/dispatch/couriers?agent_id=10")

    # data-backed
    out["agen_couriers"] = agen.call("GET", "/api/v1/dispatch/couriers")
    out["agen_couriers_returns"] = agen.call("GET", "/api/v1/dispatch/couriers?for_returns=1")
    out["agen_regions"] = agen.call("GET", "/api/v1/dispatch/regions")
    out["agen_refusals"] = koord.call("GET", "/api/v1/dispatch/refusals")
    out["refusals_status"] = koord.call("GET", "/api/v1/dispatch/refusals?status=handed_back")
    out["agen_expeditions"] = koord.call("GET", "/api/v1/dispatch/expeditions")
    out["db_refusals"] = db("SELECT agent_id, quantity, status FROM delivery_refusals ORDER BY id")
    out["db_couriers"] = db("SELECT id, name, is_active FROM couriers ORDER BY id")
    return out


def main():
    seed_full()
    seed_dispatch()
    lar = scenario(LARAVEL)
    seed_full()
    seed_dispatch()
    go = scenario(GO)

    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        lv, gv = scrub(norm_body(lar[key])), scrub(norm_body(go[key]))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv)[:800])
            print("  G=" + json.dumps(gv)[:800])
    print(f"dispatch: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())