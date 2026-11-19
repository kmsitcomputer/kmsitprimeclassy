#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the agent-admin SHIPPING surface (Domain E).

    PATCH /api/v1/agent/shipping-providers/{provider}/toggle
    PUT   /api/v1/agent/shipping-providers/{provider}/config
    GET   /api/v1/agent/shipping-providers
    GET   /api/v1/agent/shipping-providers/{provider}/couriers
    PUT   /api/v1/agent/shipping-providers/{provider}/couriers
    GET   /api/v1/agent/shipping-tariff | PUT
"""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, ADMIN, SUPER, SALES, check, client_for, db,
                                 diff, norm_body, reset, rows, seed_full)


def state():
    return {
        "settings": rows("SELECT agent_id = 10, shipping_provider_id, is_active "
                         "FROM agent_shipping_provider_settings ORDER BY shipping_provider_id"),
        "configs": rows("SELECT agent_id = 10, shipping_provider_id, config <> '' "
                        "FROM agent_shipping_provider_configs ORDER BY shipping_provider_id"),
        "tariff": rows("SELECT agent_id = 10, IFNULL(CAST(price_per_km AS CHAR),'-'), "
                       "IFNULL(CAST(minimum_distance_km AS CHAR),'-'), IFNULL(CAST(minimum_charge AS CHAR),'-'), "
                       "free_shipping_enabled, IFNULL(CAST(free_shipping_min_amount AS CHAR),'-') "
                       "FROM shipping_configurations ORDER BY id"),
    }


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        # reset() clears order-scoped tables only; agent-admin rows are not in its CLEAR list.
        for t in ("agent_shipping_provider_configs", "agent_shipping_provider_settings",
                  "shipping_configurations"):
            try:
                db(f"DELETE FROM {t}")
            except RuntimeError as exc:
                if "1146" not in str(exc) and "1451" not in str(exc):
                    raise
        out[label] = (run(base), state())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    st_l, bodies_l = [p[0] for p in pairs_l], [p[1] for p in pairs_l]
    st_g, bodies_g = [p[0] for p in pairs_g], [p[1] for p in pairs_g]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(bodies_l)[:300]} || {json.dumps(bodies_g)[:300]}")
        return
    for i, (bl, bg) in enumerate(zip(bodies_l, bodies_g)):
        diffs = diff(norm_body(bl), norm_body(bg))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:6]))
            return
    diffs = diff(db_l, db_g)
    if diffs:
        check(f"{name} [db]", False, "; ".join(diffs[:6]))
        return
    check(name, True)


def main():
    seed_full()

    def shipping_matrix(base):
        c = client_for(base, AGEN)
        res = [c.call("GET", "/api/v1/agent/shipping-providers"),
               c.call("PATCH", "/api/v1/agent/shipping-providers/1/toggle", {}),
               c.call("PATCH", "/api/v1/agent/shipping-providers/1/toggle", {}),
               c.call("PUT", "/api/v1/agent/shipping-providers/2/config",
                      {"config": {"api_key": "KEY123", "api_version": "komerce_v2",
                                  "origin_destination_id": "1", "origin_label": "Label",
                                  "origin_search": "Jakarta", "couriers": ["jne"]}}),
               c.call("PUT", "/api/v1/agent/shipping-providers/3/config",
                      {"config": {"base_url": "https://kmsit.test", "profile": "car", "token": "T"}}),
               c.call("PUT", "/api/v1/agent/shipping-providers/1/config", {"config": {}}),
               c.call("GET", "/api/v1/agent/shipping-providers/2/couriers"),
               c.call("PUT", "/api/v1/agent/shipping-providers/2/couriers", {"couriers": ["JNE", "pos"]}),
               c.call("GET", "/api/v1/agent/shipping-providers/2/couriers"),
               c.call("PUT", "/api/v1/agent/shipping-providers/2/couriers", {"couriers": ["tak-ada"]}),
               c.call("GET", "/api/v1/agent/shipping-tariff"),
               c.call("PUT", "/api/v1/agent/shipping-tariff", {"price_per_km": 2500, "minimum_distance_km": 2,
                                                                "minimum_charge": 15000,
                                                                "free_shipping_enabled": False}),
               c.call("GET", "/api/v1/agent/shipping-tariff"),
               client_for(base, SALES).call("GET", "/api/v1/agent/shipping-providers"),
               client_for(base, ADMIN).call("GET", "/api/v1/agent/shipping-providers")]
        return res

    scenario("agent shipping matrix (RBAC + config + couriers + tariff)", shipping_matrix)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
