#!/usr/bin/env python3
"""Laravel <-> Go differential parity for agent WhatsApp event listing."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, ADMIN, SALES, SUPER, KONSUMEN,
                                 check, client_for, db, diff, norm_body, seed_full)


def state():
    return {
        "events": db("SELECT agent_id,event,enabled,template FROM agent_whatsapp_event_settings ORDER BY agent_id,event"),
        "numbers": db("SELECT agent_id,msisdn FROM agent_whatsapp_test_numbers ORDER BY agent_id,msisdn"),
        "activity_logs": db("SELECT COUNT(*) FROM activity_logs"),
    }


def clients(base):
    return {email: client_for(base, email) for email in (AGEN, ADMIN, SALES, SUPER, KONSUMEN)}


def requests(cs):
    return [
        cs[AGEN].call("GET", "/api/v1/agent/whatsapp/events"),
        cs[AGEN].call("GET", "/api/v1/agent/whatsapp/events?agent_id=11"),
        cs[ADMIN].call("GET", "/api/v1/agent/whatsapp/events"),
        cs[ADMIN].call("GET", "/api/v1/agent/whatsapp/events?agent_id=10"),
        cs[ADMIN].call("GET", "/api/v1/agent/whatsapp/events?agent_id=10.0"),
        cs[ADMIN].call("GET", "/api/v1/agent/whatsapp/events?agent_id=999"),
        cs[ADMIN].call("GET", "/api/v1/agent/whatsapp/events?agent_id=10x"),
        cs[SALES].call("GET", "/api/v1/agent/whatsapp/events"),
        cs[SUPER].call("GET", "/api/v1/agent/whatsapp/events"),
        cs[KONSUMEN].call("GET", "/api/v1/agent/whatsapp/events"),
    ]


def main():
    seed_full()
    lc, gc = clients(LARAVEL), clients(GO)
    before = state()
    left = requests(lc)
    mid = state()
    right = requests(gc)
    after = state()
    statuses_l, statuses_g = [r[0] for r in left], [r[0] for r in right]
    failures = []
    if statuses_l != statuses_g:
        failures.append(f"statuses {statuses_l} vs {statuses_g}")
    else:
        for i, (lr, gr) in enumerate(zip(left, right), 1):
            changes = diff(norm_body(lr[1]), norm_body(gr[1]))
            failures.extend(f"case {i}: {change}" for change in changes[:5])
    if before != mid or mid != after:
        failures.append(f"read-only DB changed: before={json.dumps(before)} Laravel={json.dumps(mid)} afterGo={json.dumps(after)}")
    if failures:
        check("WhatsApp events GET (contract + RBAC + selector + DB)", False, "; ".join(failures[:16]))
    else:
        check("WhatsApp events GET (contract + RBAC + selector + DB)", True)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
