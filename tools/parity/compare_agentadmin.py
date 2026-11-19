#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the agent-admin write surface (Domain E).

    PATCH /api/v1/agent/payment-methods/{method}/toggle
    PUT   /api/v1/agent/payment-methods/{method}/config
    GET   /api/v1/agent/payment-methods
"""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, ADMIN, SUPER, SALES, check, client_for, db,
                                 diff, norm_body, reset, rows, seed_full)


def state():
    return {
        # Rows are keyed (agent_id, payment_method_id) via updateOrCreate — never row-id based.
        "settings": rows("SELECT agent_id = 10, payment_method_id, is_active, IFNULL(active_environment,'-') "
                         "FROM agent_payment_method_settings ORDER BY payment_method_id"),
        "configs": rows("SELECT agent_id = 10, payment_method_id, environment, config <> '' "
                        "FROM agent_payment_gateway_configs ORDER BY payment_method_id"),
    }


def scrub(value):
    if isinstance(value, dict):
        # Laravel's toggle response shape has NO `configured` key (AgentPaymentMethodController::toggle).
        return {k: scrub(v) for k, v in value.items() if k != "configured"}
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return value


def scenario(name, run, state_reader=state):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        # reset() clears order-scoped tables only; the agent-admin rows below are NOT in its CLEAR list.
        db("DELETE FROM agent_payment_gateway_configs")
        db("DELETE FROM agent_payment_method_settings")
        out[label] = (run(base), state_reader())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    st_l, bodies_l = [p[0] for p in pairs_l], [p[1] for p in pairs_l]
    st_g, bodies_g = [p[0] for p in pairs_g], [p[1] for p in pairs_g]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(bodies_l)[:300]} || {json.dumps(bodies_g)[:300]}")
        return
    for i, (bl, bg) in enumerate(zip(bodies_l, bodies_g)):
        diffs = diff(norm_body(scrub(bl)), norm_body(scrub(bg)))
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

    def payment_method_matrix(base):
        c = client_for(base, AGEN)
        res = [c.call("GET", "/api/v1/agent/payment-methods"),
               c.call("GET", "/api/v1/agent/payment-methods"),
               c.call("PATCH", "/api/v1/agent/payment-methods/1/toggle", {}),
               c.call("PATCH", "/api/v1/agent/payment-methods/1/toggle", {}),
               c.call("PATCH", "/api/v1/agent/payment-methods/2/toggle", {}),
               c.call("PATCH", "/api/v1/agent/payment-methods/4/toggle", {}),
               c.call("PATCH", "/api/v1/agent/payment-methods/999/toggle", {}),
               c.call("PUT", "/api/v1/agent/payment-methods/1/config", {}),
               c.call("PUT", "/api/v1/agent/payment-methods/1/config", {"config": {}}),
               c.call("PUT", "/api/v1/agent/payment-methods/1/config", {"config": {"bank_name": "B"}}),
               c.call("PUT", "/api/v1/agent/payment-methods/1/config",
                      {"config": {"bank_name": "Bank Uji", "account_name": "Toko Prime", "account_number": "1234567890"},
                       "environment": "salah"}),
               c.call("PUT", "/api/v1/agent/payment-methods/1/config",
                      {"config": {"bank_name": "Bank Uji", "account_name": "Toko Prime", "account_number": "1234567890"},
                       "environment": "production"}),
               c.call("PUT", "/api/v1/agent/payment-methods/2/config",
                      {"config": {"bank_name": "B", "account_name": "A", "account_number": "1"}}),
               client_for(base, SALES).call("PATCH", "/api/v1/agent/payment-methods/1/toggle", {}),
               client_for(base, SALES).call("PUT", "/api/v1/agent/payment-methods/1/config", {"config": {}}),
               client_for(base, ADMIN).call("GET", "/api/v1/agent/payment-methods")]
        return res

    scenario("agent payment-method matrix (RBAC + validation + config)", payment_method_matrix)

    def profile_state():
        return {
            "profile": rows("SELECT store_name,address,IFNULL(phone,'-'),CAST(latitude AS CHAR),CAST(longitude AS CHAR) "
                             "FROM agent_profiles WHERE user_id=10"),
            "audit": rows("SELECT causer_id,subject_type,subject_id,event, "
                          "JSON_UNQUOTE(JSON_EXTRACT(properties,'$.old.store_name')), "
                          "JSON_UNQUOTE(JSON_EXTRACT(properties,'$.old.address')), "
                          "JSON_UNQUOTE(JSON_EXTRACT(properties,'$.old.phone')), "
                          "JSON_UNQUOTE(JSON_EXTRACT(properties,'$.new.store_name')), "
                          "JSON_UNQUOTE(JSON_EXTRACT(properties,'$.new.address')), "
                          "JSON_UNQUOTE(JSON_EXTRACT(properties,'$.new.phone')) "
                          "FROM activity_logs WHERE event='agent_profile.self_updated' ORDER BY id"),
        }

    def store_profile_matrix(base):
        # Restore the exact starting profile before each runtime so the audit's `old` projection is comparable.
        db("UPDATE agent_profiles SET store_name='Toko Prime Classy Imp010',address='Jl. Uji 1, Bandung', "
           "phone='081100000010',latitude=-6.9,longitude=107.6 WHERE user_id=10")
        c = client_for(base, AGEN)
        return [
            c.call("GET", "/api/v1/agent/store-profile"),
            c.call("PUT", "/api/v1/agent/store-profile", {
                "store_name": "", "address": "", "phone": "1" * 21,
                "latitude": 91, "longitude": None,
            }),
            c.call("PUT", "/api/v1/agent/store-profile", {
                "store_name": "Toko Parity", "address": "Jl. Parity 10",
                "phone": "081234567890", "latitude": -6.91, "longitude": 107.61,
                "user_id": 11, "agent_id": 11,
            }),
            c.call("GET", "/api/v1/agent/store-profile"),
            client_for(base, ADMIN).call("PUT", "/api/v1/agent/store-profile", {
                "store_name": "Toko Admin", "address": "Jl. lain", "latitude": -6.9, "longitude": 107.6,
            }),
        ]

    scenario("agent store-profile matrix (validation + audit + owner scope)", store_profile_matrix, profile_state)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
