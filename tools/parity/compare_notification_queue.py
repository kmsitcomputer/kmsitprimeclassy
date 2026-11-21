#!/usr/bin/env python3
"""Differential for lifecycle notification ledgers and Laravel-compatible queue rows."""
import json
import os
import pathlib
import re
import sys
import subprocess
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

import compare_fulfillment as f
import compare_orders

LARAVEL = os.environ.get("PARITY_LARAVEL_URL", "http://127.0.0.1:8899").rstrip("/")
GO = os.environ.get("PARITY_GO_URL", "http://127.0.0.1:8082").rstrip("/")
SETTING_KEYS = {
    "integration.whatsapp": ["wa.enabled", "wa.event.order_created"],
    "integration.smtp": [
        "smtp.enabled", "smtp.host", "smtp.port", "smtp.from_address", "smtp.from_name",
        "smtp.username", "smtp.password", "smtp.encryption", "smtp.timeout", "smtp.transactional_enabled",
    ],
}


def sql_quote(value):
    if value is None or value == "NULL":
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def setting_snapshot():
    return f.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings")


def setting_literal(value):
    if value is None or value == "NULL":
        return "NULL"
    if str(value).isdigit() and len(str(value)) < 10:
        return str(value)
    return sql_quote(value)


def set_settings():
    for group, keys in SETTING_KEYS.items():
        escaped = ",".join(sql_quote(key) for key in keys)
        f.db(f"DELETE FROM settings WHERE `group`={sql_quote(group)} AND `key` IN ({escaped})")
        values = {
            "integration.whatsapp": {"wa.enabled": "1", "wa.event.order_created": "1"},
            "integration.smtp": {
                "smtp.enabled": "1", "smtp.host": "127.0.0.1", "smtp.port": "2525",
                "smtp.from_address": "noreply@imp010.test", "smtp.from_name": "PrimeClassy Test",
                "smtp.username": "", "smtp.password": "", "smtp.encryption": "none",
                "smtp.timeout": "2", "smtp.transactional_enabled": "1",
            },
        }[group]
        rows = ",".join(
            "(" + ",".join(map(sql_quote, (key, value, group))) + ",0,NOW(),NOW())"
            for key, value in values.items()
        )
        f.db(f"INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) VALUES {rows}")


def restore_settings(snapshot):
    keys = {key for values in SETTING_KEYS.values() for key in values}
    escaped = ",".join(sql_quote(key) for key in keys)
    f.db(f"DELETE FROM settings WHERE `key` IN ({escaped})")
    groups = {group for group, values in SETTING_KEYS.items() if values}
    for row in snapshot:
        if row[1] not in keys or row[3] not in groups:
            continue
        values = [setting_literal(v) for v in row]
        f.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES (" + ",".join(values) + ")")


def normalized(rows, body_index=None, key_index=None):
    result = []
    for source in rows:
        row = list(source)
        if body_index is not None and row[body_index] not in (None, "NULL"):
            body = row[body_index]
            body = re.sub(r"D-\d+", "D-<sequence>", body)
            body = re.sub(r"/orders/\d+", "/orders/<id>", body)
            row[body_index] = body
        if key_index is not None and row[key_index] not in (None, "NULL"):
            row[key_index] = re.sub(r"(?<=:)\d+(?=:|$)", "<id>", row[key_index])
        result.append(row)
    return result


def queue_rows(after_id):
    # Laravel's JSON payload embeds PHP-serialized JSON strings containing newlines. Avoid the
    # tab/newline-delimited db() helper, which cannot represent those fields losslessly.
    query = f"SELECT queue,HEX(payload) FROM jobs WHERE queue='primeclassy-notify' AND id>{after_id} ORDER BY id"
    cmd = ('mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing '
           f'-e "{query}"')
    raw = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=90, check=True).stdout
    rows = [line.split("\t", 1) for line in raw.splitlines() if line]
    out = []
    for queue, raw in rows:
        payload = json.loads(bytes.fromhex(raw).decode())
        data = payload.get("data", {})
        command = re.sub(r'(s:9:"messageId";i:)\d+', r"\1<ID>", data.get("command", ""))
        out.append([queue, payload.get("displayName"), data.get("commandName"), command])
    return out


def run_one(base):
    client = f.Client(base)
    if client.login("konsumen@imp010.test") != 200:
        raise RuntimeError(f"login failed: {base}")
    body = {
        "items": [{"product_id": 3, "quantity": 2}], "payment_method_code": "bank_transfer",
        "shipping_method": "pickup", "recipient_name": "Queue parity recipient",
        "recipient_phone": "081200000001", "address_line": "Notification queue parity",
        "village_id": "32730101", "latitude": -6.9, "longitude": 107.6,
    }
    start_job_id = int(f.db("SELECT COALESCE(MAX(id),0) FROM jobs")[0][0])
    status, response = client.call("POST", "/api/v1/orders", body,
                                   {"Idempotency-Key": "notify-queue-" + uuid.uuid4().hex})
    if status != 201:
        raise RuntimeError(f"order create failed on {base}: {status} {response}")
    order_id = int(response["data"]["id"])
    order_no = f.db(f"SELECT order_no FROM orders WHERE id={order_id}")[0][0]
    wa = f.db(f"SELECT event,recipient_role,template_class,recipient_phone,body,status,IFNULL(skip_reason,'-'),max_attempts FROM whatsapp_messages WHERE order_id={order_id} ORDER BY event,recipient_role")
    mail = f.db(f"SELECT event,recipient_role,recipient_email,subject,body,status,IFNULL(skip_reason,'-') FROM email_messages WHERE order_id={order_id} ORDER BY event,recipient_role,recipient_user_id")
    inbox = f.db(f"SELECT type,severity,title,body,subject_type FROM admin_notifications WHERE order_id={order_id} ORDER BY type,recipient_user_id")
    jobs = queue_rows(start_job_id)
    if not wa:
        raise AssertionError(f"{base}: event gate produced no WhatsApp ledger row")
    if not mail:
        raise AssertionError(f"{base}: SMTP gate produced no email ledger row")
    if not jobs:
        all_new_jobs = f.db(f"SELECT queue,payload FROM jobs WHERE id>{start_job_id} ORDER BY id")
        raise AssertionError(f"{base}: no durable notify queue rows; wa={wa!r} email={mail!r} other_jobs={all_new_jobs!r}")
    for job_queue, display_name, command_name, _ in jobs:
        if job_queue != "primeclassy-notify" or not display_name or display_name != command_name:
            raise AssertionError(f"{base}: incompatible queue payload {jobs}")
    return {
        "order_no": order_no,
        "wa": normalized(wa, body_index=4, key_index=None),
        "mail": normalized(mail, body_index=4),
        "inbox": normalized(inbox, body_index=3),
        "jobs": jobs,
        "job_ids": [row[0] for row in f.db(f"SELECT id FROM jobs WHERE queue='primeclassy-notify' AND id>{start_job_id} ORDER BY id")],
    }


def main():
    if f.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    results = {}
    added_jobs = []
    original_settings = None
    original_verified = None
    initial_job_id = 0
    with compare_orders.TestDatabaseLock():
        try:
            f.seed_full()
            original_settings = setting_snapshot()
            original_verified = f.db("SELECT id,email_verified_at FROM users WHERE id IN (11,13) ORDER BY id")
            initial_job_id = int(f.db("SELECT COALESCE(MAX(id),0) FROM jobs")[0][0])
            for label, base in (("Laravel", LARAVEL), ("Go", GO)):
                f.reset()
                set_settings()
                f.db("UPDATE users SET email_verified_at=UTC_TIMESTAMP(6) WHERE id IN (11,13)")
                results[label] = run_one(base)
                added_jobs.extend(results[label]["job_ids"])
            comparable = {}
            for label, result in results.items():
                comparable[label] = {key: value for key, value in result.items() if key != "job_ids"}
            if comparable["Laravel"] != comparable["Go"]:
                print(json.dumps(comparable, ensure_ascii=False, indent=2))
                raise AssertionError("Laravel-Go enabled lifecycle ledger/queue mismatch")
            print("enabled notification queue differential: PASS")
            print(f"WhatsApp rows={len(results['Laravel']['wa'])}; email rows={len(results['Laravel']['mail'])}; jobs={len(results['Laravel']['jobs'])}")
        finally:
            # The named test DB lock makes all newly-created notify jobs ours, including jobs created
            # by a runtime that fails before returning its result.
            f.db(f"DELETE FROM jobs WHERE id>{initial_job_id} AND queue='primeclassy-notify'")
            f.reset()
            if original_settings is not None:
                restore_settings(original_settings)
            if original_verified is not None:
                for user_id, verified_at in original_verified:
                    f.db(f"UPDATE users SET email_verified_at={sql_quote(verified_at)} WHERE id={int(user_id)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
