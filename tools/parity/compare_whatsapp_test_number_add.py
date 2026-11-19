#!/usr/bin/env python3
"""Laravel <-> Go differential parity for registering agent WhatsApp test numbers."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, SALES, check, client_for, db, diff, norm_body, seed_full

AGENT = 10
NORMALIZED = "6281234567890"
CASES = [
    ("normalize and register", AGEN, {"number": "081234567890", "confirm_authorized": True}, 0, None),
    ("duplicate is idempotent", AGEN, {"number": "+62 812-3456-7890", "confirm_authorized": "yes"}, 1, None),
    ("invalid number", AGEN, {"number": "abc", "confirm_authorized": True}, 0, None),
    ("number max length", AGEN, {"number": "1" * 21, "confirm_authorized": True}, 0, None),
    ("invalid confirmation", AGEN, {"number": "08123456789", "confirm_authorized": "0"}, 0, None),
    ("missing both", AGEN, {}, 0, None),
    ("five-number limit", AGEN, {"number": "08123456789", "confirm_authorized": 1}, 5, None),
    ("foreign query selector", ADMIN, {"number": "08123456789", "confirm_authorized": True}, 0, "agent_id=999"),
    ("foreign body selector", AGEN, {"number": "08123456789", "confirm_authorized": True, "agent_id": 11}, 0, None),
    ("unauthorized role", SALES, {"number": "08123456789", "confirm_authorized": True}, 0, None),
]


def sql_quote(v):
    return "'" + v.replace("'", "''") + "'"


def snapshot_original():
    return db("SELECT id,agent_id,msisdn,IFNULL(registered_by,'NULL'),created_at,updated_at FROM agent_whatsapp_test_numbers WHERE agent_id=10 ORDER BY id")


def restore(rows):
    db("DELETE FROM agent_whatsapp_test_numbers WHERE agent_id=10")
    for rid, agent_id, msisdn, registered_by, created, updated in rows:
        registered_by = "NULL" if registered_by == "NULL" else registered_by
        db("INSERT INTO agent_whatsapp_test_numbers (id,agent_id,msisdn,registered_by,created_at,updated_at) VALUES "
           f"({rid},{agent_id},{sql_quote(msisdn)},{registered_by},{sql_quote(created)},{sql_quote(updated)})")


def prepare(base_id, count, include_duplicate):
    db("DELETE FROM agent_whatsapp_test_numbers WHERE agent_id=10")
    values = []
    if include_duplicate:
        values.append((base_id, NORMALIZED))
    for i in range(count):
        values.append((base_id + i + 1, f"6281111111{i:04d}"))
    for rid, number in values:
        db("INSERT INTO agent_whatsapp_test_numbers (id,agent_id,msisdn,registered_by,created_at,updated_at) "
           f"VALUES ({rid},{AGENT},{sql_quote(number)},{AGENT},UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")


def state():
    rows = db("SELECT id,msisdn,registered_by FROM agent_whatsapp_test_numbers WHERE agent_id=10 ORDER BY id")
    return [{"msisdn": r[1], "registered_by": r[2]} for r in rows]


def response_body(body):
    if isinstance(body, dict) and isinstance(body.get("data"), dict) and isinstance(body["data"].get("numbers"), list):
        body = json.loads(json.dumps(body))
        body["data"]["numbers"] = [{"masked": x["masked"]} for x in body["data"]["numbers"]]
    return norm_body(body)


def audits_after(max_id):
    raw = db(f"SELECT causer_id,subject_type,subject_id,event,JSON_UNQUOTE(JSON_EXTRACT(properties,'$.masked')),JSON_EXTRACT(properties,'$.actor_id'),JSON_EXTRACT(properties,'$.target_agent_id') FROM activity_logs WHERE id>{max_id} AND event='whatsapp.agent_test_number.registered' ORDER BY id")
    current = db("SELECT id,msisdn FROM agent_whatsapp_test_numbers WHERE agent_id=10")
    ids = {r[0]: r[1] for r in current}
    result = []
    for r in raw:
        result.append([r[0], r[1], ids.get(r[2], "<missing-subject>"), r[3],
                       {"masked": r[4], "actor_id": int(r[5]), "target_agent_id": int(r[6])}])
    return result


def run_case(base, client_map, case, base_id):
    label, email, body, count, query = case
    prepare(base_id, count, label == "duplicate is idempotent")
    max_id = int(db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
    path = "/api/v1/agent/whatsapp/test-numbers"
    if query:
        path += "?" + query
    response = client_map[email].call("POST", path, body)
    return response, {"numbers": state(), "audit": audits_after(max_id)}


def main():
    seed_full()
    clients = {tag: {email: client_for(base, email) for email in (AGEN, ADMIN, SALES)}
               for tag, base in (("L", LARAVEL), ("G", GO))}
    original = snapshot_original()
    base_id = int(db("SELECT COALESCE(MAX(id),0)+1000 FROM agent_whatsapp_test_numbers")[0][0])
    failures = []
    try:
        for case in CASES:
            left, state_l = run_case(LARAVEL, clients["L"], case, base_id)
            right, state_g = run_case(GO, clients["G"], case, base_id)
            label = case[0]
            if left[0] != right[0]:
                failures.append(f"{label}: status {left[0]} vs {right[0]}")
                continue
            failures.extend(f"{label}: response {x}" for x in diff(response_body(left[1]), response_body(right[1]))[:5])
            failures.extend(f"{label}: DB/audit {x}" for x in diff(state_l, state_g)[:5])
        if failures:
            check("WhatsApp test-number POST (validation + normalization + cap + audit + RBAC)", False, "; ".join(failures[:24]))
        else:
            check("WhatsApp test-number POST (validation + normalization + cap + audit + RBAC)", True)
    finally:
        restore(original)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
