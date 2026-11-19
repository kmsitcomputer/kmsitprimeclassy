#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the agent WhatsApp test-number listing."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, SALES, SUPER, KONSUMEN, check, client_for, db, diff, norm_body, seed_full


def state():
    return db("SELECT id,agent_id,msisdn FROM agent_whatsapp_test_numbers ORDER BY id")


def clients(base):
    return {email: client_for(base, email) for email in (AGEN, ADMIN, SALES, SUPER, KONSUMEN)}


def requests(cs):
    cases = [
        (AGEN, "/api/v1/agent/whatsapp/test-numbers"),
        (AGEN, "/api/v1/agent/whatsapp/test-numbers?agent_id=11"),
        (ADMIN, "/api/v1/agent/whatsapp/test-numbers"),
        (ADMIN, "/api/v1/agent/whatsapp/test-numbers?agent_id=10"),
        (ADMIN, "/api/v1/agent/whatsapp/test-numbers?agent_id=999"),
        (SALES, "/api/v1/agent/whatsapp/test-numbers"),
        (SUPER, "/api/v1/agent/whatsapp/test-numbers"),
        (KONSUMEN, "/api/v1/agent/whatsapp/test-numbers"),
    ]
    return [cs[email].call("GET", path) for email, path in cases]


def main():
    seed_full()
    laravel_clients, go_clients = clients(LARAVEL), clients(GO)
    existing = db("SELECT id FROM agent_whatsapp_test_numbers WHERE agent_id=10 AND msisdn='6281234567890'")
    inserted_id = None
    if not existing:
        db("INSERT INTO agent_whatsapp_test_numbers (agent_id,msisdn,registered_by,created_at,updated_at) VALUES (10,'6281234567890',10,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
        inserted_id = db("SELECT id FROM agent_whatsapp_test_numbers WHERE agent_id=10 AND msisdn='6281234567890'")[0][0]
    try:
        before = state()
        left = requests(laravel_clients)
        middle = state()
        right = requests(go_clients)
        after = state()
    finally:
        if inserted_id is not None:
            db(f"DELETE FROM agent_whatsapp_test_numbers WHERE id={inserted_id}")
    failures = []
    status_l, status_g = [x[0] for x in left], [x[0] for x in right]
    if status_l != status_g:
        failures.append(f"statuses {status_l} vs {status_g}")
    else:
        for i, (lr, gr) in enumerate(zip(left, right), 1):
            failures.extend(f"case {i}: {v}" for v in diff(norm_body(lr[1]), norm_body(gr[1]))[:5])
    if before != middle or middle != after:
        failures.append(f"read-only DB state changed: before={before} afterLaravel={middle} afterGo={after}")
    if failures:
        check("WhatsApp test-number GET (masking + RBAC + selector + read-only DB)", False, "; ".join(failures[:16]))
    else:
        check("WhatsApp test-number GET (masking + RBAC + selector + read-only DB)", True)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
