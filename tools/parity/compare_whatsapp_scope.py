#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the agent WhatsApp scope selector."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, ADMIN, SALES, SUPER, KONSUMEN,
                                 check, client_for, db, diff, norm_body, seed_full)


def state():
    return {
        "profiles": db("SELECT COUNT(*) FROM agent_profiles"),
        "activity_logs": db("SELECT COUNT(*) FROM activity_logs"),
        "whatsapp_events": db("SELECT COUNT(*) FROM agent_whatsapp_event_settings"),
        "test_numbers": db("SELECT COUNT(*) FROM agent_whatsapp_test_numbers"),
    }


def clients(base):
    emails = (AGEN, ADMIN, SALES, SUPER, KONSUMEN)
    return {email: client_for(base, email) for email in emails}


def run(session_clients):
    cases = [
        (AGEN, "/api/v1/agent/whatsapp/scope"),
        (AGEN, "/api/v1/agent/whatsapp/scope?agent_id=11"),
        (ADMIN, "/api/v1/agent/whatsapp/scope"),
        (ADMIN, "/api/v1/agent/whatsapp/scope?agent_id=999"),
        (SALES, "/api/v1/agent/whatsapp/scope"),
        (SUPER, "/api/v1/agent/whatsapp/scope"),
        (KONSUMEN, "/api/v1/agent/whatsapp/scope"),
    ]
    responses = []
    for email, path in cases:
        responses.append(session_clients[email].call("GET", path))
    return responses


def main():
    seed_full()
    laravel_clients = clients(LARAVEL)
    go_clients = clients(GO)
    before = state()
    left = run(laravel_clients)
    after_laravel = state()
    right = run(go_clients)
    after_go = state()

    if [r[0] for r in left] != [r[0] for r in right]:
        check("WhatsApp scope (actor roles + branch + ignored client selector)", False,
              f"statuses {[r[0] for r in left]} vs {[r[0] for r in right]}")
    else:
        mismatches = []
        for index, (laravel, go) in enumerate(zip(left, right), 1):
            changes = diff(norm_body(laravel[1]), norm_body(go[1]))
            if changes:
                mismatches.extend(f"case {index}: {change}" for change in changes[:5])
        if mismatches:
            check("WhatsApp scope (actor roles + branch + ignored client selector)", False,
                  "; ".join(mismatches[:12]))
        elif before != after_laravel or before != after_go:
            check("WhatsApp scope read-only DB state", False,
                  f"before={json.dumps(before)} Laravel={json.dumps(after_laravel)} Go={json.dumps(after_go)}")
        elif after_laravel != after_go:
            check("WhatsApp scope read-only DB state", False,
                  f"Laravel={json.dumps(after_laravel)} Go={json.dumps(after_go)}")
        else:
            check("WhatsApp scope (actor roles + branch + ignored client selector)", True)

    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
