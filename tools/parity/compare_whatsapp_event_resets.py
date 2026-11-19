#!/usr/bin/env python3
"""Laravel <-> Go differential parity for agent WhatsApp template reset."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, SALES, check, client_for, db, diff, norm_body, seed_full

AGENT_ID = 10
EVENT = "order_created"
TEMPLATE = "Custom {nomor_pesanan}"
CASES = [
    ("agent override reset", AGEN, EVENT, "override", None),
    ("admin no-op reset", ADMIN, EVENT, "none", None),
    ("missing row reset", AGEN, EVENT, "missing", None),
    ("invalid event", AGEN, "not_an_event", "none", None),
    ("foreign selector", ADMIN, EVENT, "override", "agent_id=999"),
    ("unauthorized role", SALES, EVENT, "override", None),
]


def row():
    return db(f"SELECT id,enabled,template,updated_by FROM agent_whatsapp_event_settings WHERE agent_id={AGENT_ID} AND event='{EVENT}'")


def prepare(mode):
    if mode == "missing":
        db(f"DELETE FROM agent_whatsapp_event_settings WHERE agent_id={AGENT_ID} AND event='{EVENT}'")
        return
    template = "'" + TEMPLATE + "'" if mode == "override" else "NULL"
    db(f"INSERT INTO agent_whatsapp_event_settings (agent_id,event,enabled,template,updated_by,created_at,updated_at) VALUES ({AGENT_ID},'{EVENT}',NULL,{template},NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=NULL,template=VALUES(template),updated_by=NULL")


def restore(original):
    db(f"DELETE FROM agent_whatsapp_event_settings WHERE agent_id={AGENT_ID} AND event='{EVENT}'")
    if original:
        rid, enabled, template, updated = original[0]
        enabled = "NULL" if enabled == "NULL" else enabled
        template = "NULL" if template == "NULL" else "'" + template.replace("'", "''") + "'"
        updated = "NULL" if updated == "NULL" else updated
        db(f"INSERT INTO agent_whatsapp_event_settings (id,agent_id,event,enabled,template,updated_by,created_at,updated_at) VALUES ({rid},{AGENT_ID},'{EVENT}',{enabled},{template},{updated},UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")


def audit_after(max_id):
    rows = db(f"SELECT causer_id,subject_type,subject_id,event,properties,IFNULL(ip_address,'<NULL>'),IFNULL(user_agent,'<NULL>') FROM activity_logs WHERE id>{max_id} AND event='whatsapp.agent_event.template_reset' ORDER BY id")
    return [[*v[:4], json.loads(v[4]) if v[4] not in ("", "NULL") else None, *v[5:]] for v in rows]


def run(base, clients, stable):
    responses, states = [], []
    for label, email, event, mode, query in CASES:
        prepare(mode)
        max_id = int(db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
        path = f"/api/v1/agent/whatsapp/events/{event}/template"
        if query:
            path += "?" + query
        responses.append(clients[email].call("DELETE", path))
        states.append({"row": row(), "audit": audit_after(max_id)})
        if mode == "missing":
            restore(stable)
    return responses, states


def main():
    seed_full()
    clients = {tag: {email: client_for(base, email) for email in (AGEN, ADMIN, SALES)}
               for tag, base in (("L", LARAVEL), ("G", GO))}
    original = row()
    try:
        prepare("none")
        stable = row()
        left, state_l = run(LARAVEL, clients["L"], stable)
        right, state_g = run(GO, clients["G"], stable)
        failures = []
        for case, lr, gr, sl, sg in zip(CASES, left, right, state_l, state_g):
            label = case[0]
            if lr[0] != gr[0]:
                failures.append(f"{label}: status {lr[0]} vs {gr[0]}")
                continue
            failures.extend(f"{label}: response {v}" for v in diff(norm_body(lr[1]), norm_body(gr[1]))[:5])
            for key in ("row", "audit"):
                failures.extend(f"{label}: {key} {v}" for v in diff(sl[key], sg[key])[:5])
        if failures:
            check("WhatsApp template DELETE (reset + idempotence + RBAC + audit)", False, "; ".join(failures[:20]))
        else:
            check("WhatsApp template DELETE (reset + idempotence + RBAC + audit)", True)
    finally:
        restore(original)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
