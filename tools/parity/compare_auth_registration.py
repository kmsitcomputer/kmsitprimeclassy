#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for POST /api/v1/auth/register.

Compares the HTTP response, created account/referral closure/audit state, restricted
WhatsApp ledger, and queued AccountRegisteredMail under both WA gate settings.
Only the synthetic primeclassy_testing fixtures are reset, under the shared test lock.
"""
import json
import os
import re
import sys
import uuid

import compare_fulfillment as fixtures
import compare_orders as orders
import compare_return_review as http

LARAVEL = os.environ.get("PARITY_LARAVEL_URL", "http://127.0.0.1:8899").rstrip("/")
GO = os.environ.get("PARITY_GO_URL", "http://127.0.0.1:8082").rstrip("/")
ID_KEYS = {"id", "user_id", "agent_id", "parent_id", "korsal_id", "sales_id", "actor_id", "referrer_id"}
TIMESTAMP = re.compile(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|\+00:00)$")


def normalize(value, key=""):
    if isinstance(value, dict):
        return {k: normalize(v, k) for k, v in value.items()}
    if isinstance(value, list):
        return [normalize(v) for v in value]
    if key in ID_KEYS and isinstance(value, int):
        return "<id>"
    if isinstance(value, str) and TIMESTAMP.match(value):
        return "<timestamp>"
    return value


def set_wa(enabled):
    fixtures.db("DELETE FROM settings WHERE `key`='wa.enabled'")
    if enabled:
        fixtures.db("INSERT INTO settings (`group`,`key`,value,created_at,updated_at) "
                    "VALUES ('integration.whatsapp','wa.enabled','1',NOW(),NOW()) "
                    "ON DUPLICATE KEY UPDATE value='1'")


def queue_snapshot(after_id):
    rows = fixtures.db(f"SELECT queue,HEX(payload) FROM jobs WHERE id>{after_id} ORDER BY id")
    result = []
    for queue, raw in rows:
        payload = json.loads(bytes.fromhex(raw).decode("utf-8"))
        data = payload.get("data", {})
        command = data.get("command", "")
        if data.get("commandName") == "Illuminate\\Mail\\SendQueuedMailable":
            address = re.search(r's:7:"address";s:\d+:"([^\"]*)";', command)
            name = re.search(r's:4:"name";s:\d+:"([^\"]*)";', command)
            result.append({
                "queue": queue,
                "display_name": payload.get("displayName"),
                "command_name": data.get("commandName"),
                "mailable": "App\\Mail\\AccountRegisteredMail" in command,
                "recipient": address.group(1) if address else None,
                "name": name.group(1) if name else None,
                "tries": payload.get("maxTries"),
                "backoff": payload.get("backoff"),
            })
        else:
            command = re.sub(r'(s:9:"messageId";i:)\d+', r'\1<ID>', command)
            result.append({
                "queue": queue,
                "display_name": payload.get("displayName"),
                "command_name": data.get("commandName"),
                "command": command,
            })
    return result


def run_scenario(base, email, name, phone, wa_enabled):
    set_wa(wa_enabled)
    start_job_id = int(fixtures.db("SELECT COALESCE(MAX(id),0) FROM jobs")[0][0])
    client = http.Client(base)
    if base == LARAVEL:
        client.call("GET", "/sanctum/csrf-cookie")
    body = {
        "name": name,
        "email": email,
        "phone": phone,
        "password": "Secret123!",
        "password_confirmation": "Secret123!",
    }
    status, response = client.call("POST", "/api/v1/auth/register", json.dumps(body).encode(),
                                   {"Content-Type": "application/json"})
    user_rows = fixtures.db(f"SELECT id,role_id,parent_id,agent_id,email,phone,status,email_verified_at "
                            f"FROM users WHERE email='{email}'")
    if len(user_rows) != 1:
        raise AssertionError(f"{base}: expected exactly one registered user, found {user_rows!r}; HTTP={status} {response!r}")
    user_id = user_rows[0][0]
    closures = fixtures.db(f"SELECT ancestor.email,descendant.email,uc.depth FROM user_closures uc "
                           f"JOIN users ancestor ON ancestor.id=uc.ancestor_id "
                           f"JOIN users descendant ON descendant.id=uc.descendant_id "
                           f"WHERE uc.descendant_id={user_id} ORDER BY uc.depth")
    activity = fixtures.db(f"SELECT event,description,CAST(properties AS CHAR) FROM activity_logs "
                            f"WHERE subject_type LIKE '%User' AND subject_id={user_id} ORDER BY id")
    for row in activity:
        if row[2]:
            props = json.loads(row[2])
            row[2] = json.dumps(normalize(props), sort_keys=True, ensure_ascii=False)
    whatsapp = fixtures.db(f"SELECT purpose,event,template_class,recipient_role,recipient_phone,body,status,max_attempts "
                           f"FROM whatsapp_messages WHERE recipient_user_id={user_id} ORDER BY id")
    return {
        "response": [status, normalize(response)],
        "user": user_rows[0][1:],
        "closures": closures,
        "activity": activity,
        "whatsapp": whatsapp,
        "jobs": queue_snapshot(start_job_id),
    }


def main():
    if fixtures.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    run_id = uuid.uuid4().hex[:8]
    email, name, phone = f"codex-register-{run_id}@imp010.test", f"Codex Register {run_id}", "0818" + run_id[:8]
    failures = []
    with orders.TestDatabaseLock():
        try:
            results = {}
            for enabled in (False, True):
                fixtures.seed_full()
                label = "wa_enabled" if enabled else "wa_disabled"
                results[label] = {}
                for runtime, base in (("Laravel", LARAVEL), ("Go", GO)):
                    results[label][runtime] = run_scenario(base, email, name, phone, enabled)
                    fixtures.seed_full()
                if results[label]["Laravel"] != results[label]["Go"]:
                    failures.append(f"{label}: Laravel-Go mismatch\n" + json.dumps(results[label], ensure_ascii=False, indent=2))
            if failures:
                print("registration differential: FAIL")
                print("\n".join(failures))
                return 1
            print("registration differential: 2 scenarios passed, 0 failed (WA disabled/enabled)")
            print("Each scenario compared response, account/referral/audit state, WhatsApp ledger, and queued mail/WA jobs.")
            return 0
        finally:
            # Keep only the documented synthetic baseline; never touch DEV or production data.
            fixtures.seed_full()


if __name__ == "__main__":
    raise SystemExit(main())
