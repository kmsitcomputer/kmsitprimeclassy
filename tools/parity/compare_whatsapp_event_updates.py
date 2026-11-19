#!/usr/bin/env python3
"""Laravel <-> Go differential parity for agent WhatsApp event updates."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, SALES, check, client_for, db, diff, norm_body, seed_full

EVENT = "order_created"
AGENT_ID = 10


def current_row():
    return db(f"SELECT id,enabled,template,updated_by FROM agent_whatsapp_event_settings WHERE agent_id={AGENT_ID} AND event='{EVENT}'")


def restore_row(original):
    db(f"DELETE FROM agent_whatsapp_event_settings WHERE agent_id={AGENT_ID} AND event='{EVENT}'")
    if original:
        row_id, enabled, template, updated_by = original[0]
        enabled_sql = "NULL" if enabled == "NULL" else enabled
        template_sql = "NULL" if template == "NULL" else "'" + template.replace("'", "''") + "'"
        updater_sql = "NULL" if updated_by == "NULL" else updated_by
        db(f"INSERT INTO agent_whatsapp_event_settings (id,agent_id,event,enabled,template,updated_by,created_at,updated_at) VALUES ({row_id},{AGENT_ID},'{EVENT}',{enabled_sql},{template_sql},{updater_sql},UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")


def prepare_row():
    db(f"INSERT INTO agent_whatsapp_event_settings (agent_id,event,enabled,template,updated_by,created_at,updated_at) VALUES ({AGENT_ID},'{EVENT}',NULL,NULL,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=NULL,template=NULL,updated_by=NULL")


CASES = [
    ("agent valid update", AGEN, "PUT", {"enabled": False, "template": "Order {nomor_pesanan}"}),
    ("Laravel string boolean", AGEN, "PUT", {"enabled": "0"}),
    ("invalid boolean", AGEN, "PUT", {"enabled": 2}),
    ("null template type", AGEN, "PUT", {"template": None}),
    ("admin branch update", ADMIN, "PUT", {"enabled": True}),
    ("admin body selector", ADMIN, "PUT", {"enabled": True, "agent_id": 10}),
    ("agent body foreign selector", AGEN, "PUT", {"enabled": True, "agent_id": 11}),
    ("admin foreign selector", ADMIN, "PUT", {"enabled": True}, "agent_id=999"),
    ("agent foreign selector", AGEN, "PUT", {"enabled": True}, "agent_id=11"),
    ("no change", AGEN, "PUT", {}),
    ("invalid event", AGEN, "PUT", {"enabled": True}, "not_an_event"),
    ("unknown template variable", AGEN, "PUT", {"template": "Order {secret}"}),
    ("malformed template braces", AGEN, "PUT", {"template": "Order {nomor_pesanan"}),
    ("too-long template", AGEN, "PUT", {"template": "x" * 1001}),
    ("unauthorized role", SALES, "PUT", {"enabled": True}),
]


def audit_after(max_id):
    rows = db(f"SELECT causer_id,subject_type,subject_id,event,properties,IFNULL(ip_address,'<NULL>'),IFNULL(user_agent,'<NULL>') FROM activity_logs WHERE id>{max_id} AND event='whatsapp.agent_event.updated' ORDER BY id")
    return [[*row[:4], json.loads(row[4]) if row[4] not in ("", "NULL") else None, *row[5:]] for row in rows]


def run_runtime(base, clients):
    output = []
    states = []
    for case in CASES:
        label, email, method, body, *custom = case
        event = "not_an_event" if label == "invalid event" else EVENT
        prepare_row()
        max_id = int(db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
        path = f"/api/v1/agent/whatsapp/events/{event}"
        if custom and label != "invalid event":
            path += "?" + custom[0]
        if label == "unauthorized role":
            path += "?agent_id=999"
        response = clients[email].call(method, path, body)
        output.append(response)
        states.append({"config": current_row(), "audit": audit_after(max_id)})
    return output, states


def main():
    seed_full()
    clients = {}
    for base, tag in ((LARAVEL, "L"), (GO, "G")):
        clients[tag] = {email: client_for(base, email) for email in (AGEN, ADMIN, SALES)}
    original = current_row()
    try:
        prepare_row()
        left, state_l = run_runtime(LARAVEL, clients["L"])
        right, state_g = run_runtime(GO, clients["G"])
        failures = []
        for i, (case, lr, gr, sl, sg) in enumerate(zip(CASES, left, right, state_l, state_g)):
            label = case[0]
            if lr[0] != gr[0]:
                failures.append(f"{label}: status {lr[0]} vs {gr[0]}")
                continue
            changes = diff(norm_body(lr[1]), norm_body(gr[1]))
            failures.extend(f"{label}: response {change}" for change in changes[:5])
            for key in ("config", "audit"):
                changes = diff(sl[key], sg[key])
                failures.extend(f"{label}: {key} {change}" for change in changes[:5])
        if failures:
            check("WhatsApp event PUT (validation + RBAC + audit + persisted config)", False, "; ".join(failures[:24]))
        else:
            check("WhatsApp event PUT (validation + RBAC + audit + persisted config)", True)
    finally:
        restore_row(original)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
