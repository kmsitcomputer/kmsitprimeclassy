#!/usr/bin/env python3
"""Laravel <-> Go differential parity for removing agent WhatsApp test numbers."""
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, SALES, check, client_for, db, diff, norm_body, seed_full

CASES = [
    ("agent owned row", AGEN, "OWNED", None),
    ("admin owned row", ADMIN, "OWNED", None),
    ("missing row", AGEN, "MISSING", None),
    ("foreign agent row", AGEN, "FOREIGN", None),
    ("foreign selector", ADMIN, "OWNED", "agent_id=999"),
    ("unauthorized role", SALES, "OWNED", None),
]


def state():
    return db("SELECT id,agent_id,msisdn,IFNULL(registered_by,'NULL') FROM agent_whatsapp_test_numbers WHERE agent_id IN (10,11) ORDER BY id")


def prepare(base_id):
    db(f"DELETE FROM agent_whatsapp_test_numbers WHERE id IN ({base_id},{base_id + 1})")
    db(f"INSERT INTO agent_whatsapp_test_numbers (id,agent_id,msisdn,registered_by,created_at,updated_at) VALUES "
       f"({base_id},10,'6281112223334',10,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),"
       f"({base_id + 1},11,'6281112223335',11,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")


def audit_after(max_id):
    return db(f"SELECT causer_id,subject_type,subject_id,event,JSON_UNQUOTE(JSON_EXTRACT(properties,'$.masked')),JSON_EXTRACT(properties,'$.actor_id'),JSON_EXTRACT(properties,'$.target_agent_id') FROM activity_logs WHERE id>{max_id} AND event='whatsapp.agent_test_number.removed' ORDER BY id")


def run_case(client_map, case, base_id):
    label, email, target, query = case
    prepare(base_id)
    id_to_delete = base_id if target == "OWNED" else (base_id + 1 if target == "FOREIGN" else base_id + 100)
    before_max = int(db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
    path = f"/api/v1/agent/whatsapp/test-numbers/{id_to_delete}"
    if query:
        path += "?" + query
    response = client_map[email].call("DELETE", path)
    return response, {"state": state(), "audit": audit_after(before_max)}


def main():
    seed_full()
    clients = {tag: {email: client_for(base, email) for email in (AGEN, ADMIN, SALES)}
               for tag, base in (("L", LARAVEL), ("G", GO))}
    base_id = int(db("SELECT COALESCE(MAX(id),0)+1000 FROM agent_whatsapp_test_numbers")[0][0])
    failures = []
    try:
        for case in CASES:
            left, state_l = run_case(clients["L"], case, base_id)
            right, state_g = run_case(clients["G"], case, base_id)
            label = case[0]
            if left[0] != right[0]:
                failures.append(f"{label}: status {left[0]} vs {right[0]}")
                continue
            failures.extend(f"{label}: response {v}" for v in diff(norm_body(left[1]), norm_body(right[1]))[:5])
            failures.extend(f"{label}: DB/audit {v}" for v in diff(state_l, state_g)[:5])
    finally:
        db(f"DELETE FROM agent_whatsapp_test_numbers WHERE id IN ({base_id},{base_id + 1})")
    if failures:
        check("WhatsApp test-number DELETE (ownership + idempotence + scope + audit)", False, "; ".join(failures[:24]))
    else:
        check("WhatsApp test-number DELETE (ownership + idempotence + scope + audit)", True)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
