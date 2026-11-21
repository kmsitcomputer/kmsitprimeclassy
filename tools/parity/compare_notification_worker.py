#!/usr/bin/env python3
"""Compare Laravel and Go one-shot notification queue processing on disabled channels.

Build/copy the Linux worker first:
  cd go-backend && GOOS=linux GOARCH=amd64 go build -o /tmp/imp010-notify-worker ./cmd/worker
  docker cp /tmp/imp010-notify-worker pc_go_imp010:/tmp/imp010-notify-worker
"""
import json
import pathlib
import subprocess
import sys
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import compare_fulfillment as f
import compare_orders

LARAVEL_CONTAINER = "pc_laravel_imp010"
GO_CONTAINER = "pc_go_imp010"
QUEUE = "primeclassy-notify"
EMAIL_USER_ID = 0
WA_KEYS = ["wa.enabled", "wa.base_url", "wa.username", "wa.password", "wa.device_id"]
SMTP_KEYS = ["smtp.enabled", "smtp.host", "smtp.port", "smtp.from_address", "smtp.from_name", "smtp.username", "smtp.password", "smtp.encryption", "smtp.timeout", "smtp.transactional_enabled"]


def q(value):
    if value is None:
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def snapshot_settings():
    return f.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `key` IN (" + ",".join(q(k) for k in WA_KEYS + SMTP_KEYS) + ")")


def restore_settings(rows):
    keys = WA_KEYS + SMTP_KEYS
    f.db("DELETE FROM settings WHERE `key` IN (" + ",".join(q(k) for k in keys) + ")")
    for row in rows:
        values = ["NULL" if x == "NULL" else (str(x) if i in (0, 4) else q(x)) for i, x in enumerate(row)]
        f.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES (" + ",".join(values) + ")")


def disable_channels():
    for group, values in (
        ("integration.whatsapp", {"wa.enabled": "0", "wa.base_url": "", "wa.username": "", "wa.password": "", "wa.device_id": ""}),
        ("integration.smtp", {"smtp.enabled": "0", "smtp.host": "", "smtp.port": "", "smtp.from_address": "", "smtp.from_name": "", "smtp.username": "", "smtp.password": "", "smtp.encryption": "none", "smtp.timeout": "5", "smtp.transactional_enabled": "0"}),
    ):
        for key, value in values.items():
            f.db("INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) VALUES (" + ",".join((q(key), q(value), q(group), "0", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ") ON DUPLICATE KEY UPDATE value=VALUES(value),`group`=VALUES(`group`),updated_at=UTC_TIMESTAMP(6)")


def docker(container, command):
    result = subprocess.run(["docker", "exec", container, "sh", "-lc", command], capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError(f"{container}: {result.stderr[-1200:]} {result.stdout[-500:]}")
    return result.stdout.strip()


def enqueue_message(channel, worker, tag, initial_status="queued"):
    token = uuid.uuid4().hex
    now = int(time.time())
    if channel == "whatsapp":
        f.db("INSERT INTO whatsapp_messages (idempotency_key,purpose,event,template_class,recipient_phone,body,status,attempts,max_attempts,queued_at,created_at,updated_at) VALUES (" + ",".join((q("imp010-worker-diff:" + token), q("test"), q("test"), q("full"), q("628123456789"), q("worker parity " + tag), q(initial_status), "1" if initial_status == "sending" else "0", "1", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ")")
        message_id = int(f.db("SELECT id FROM whatsapp_messages WHERE idempotency_key=" + q("imp010-worker-diff:" + token))[0][0])
        class_name = "App\\Jobs\\SendWhatsAppMessageJob"
        serialized = f'O:{len(class_name)}:"{class_name}":2:{{s:9:"messageId";i:{message_id};s:5:"queue";s:18:"primeclassy-notify";}}'
    else:
        f.db("INSERT INTO email_messages (idempotency_key,event,recipient_role,recipient_user_id,recipient_email,subject,body,status,created_at,updated_at) VALUES (" + ",".join((q("imp010-worker-diff:" + token), q("test"), q("creator"), str(EMAIL_USER_ID), q("konsumen@imp010.test"), q("Worker parity"), q("worker parity " + tag), q("queued"), "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ")")
        message_id = int(f.db("SELECT id FROM email_messages WHERE idempotency_key=" + q("imp010-worker-diff:" + token))[0][0])
        class_name = "App\\Jobs\\SendTransactionalEmailJob"
        serialized = f'O:{len(class_name)}:"{class_name}":2:{{s:9:"messageId";i:{message_id};s:5:"queue";s:18:"primeclassy-notify";}}'
    payload = json.dumps({"uuid": "imp010-worker-diff-" + token, "displayName": class_name, "job": "Illuminate\\Queue\\CallQueuedHandler@call", "maxTries": 1, "maxExceptions": None, "failOnTimeout": False, "backoff": None, "timeout": 60, "retryUntil": None, "data": {"commandName": class_name, "command": serialized}}, separators=(",", ":"))
    payload_hex = payload.encode().hex()
    f.db("INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at) VALUES (" + ",".join((q(QUEUE), "CONVERT(UNHEX(" + q(payload_hex) + ") USING utf8mb4)", "0", "NULL", str(now), str(now))) + ")")
    job_id = int(f.db("SELECT MAX(id) FROM jobs WHERE queue=" + q(QUEUE))[0][0])
    if worker == "Go":
        docker(GO_CONTAINER, "/tmp/imp010-notify-worker --once")
    else:
        docker(LARAVEL_CONTAINER, "cd /app && php artisan queue:work database --queue=primeclassy-notify --once --tries=1 --timeout=60 --no-interaction --env=queue-testing")
    if channel == "whatsapp":
        row = f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),attempts FROM whatsapp_messages WHERE id={message_id}")[0]
        subject_type = "whatsapp_message"
    else:
        row = f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-') FROM email_messages WHERE id={message_id}")[0]
        subject_type = "email_message"
    remaining_jobs = f.db(f"SELECT COUNT(*) FROM jobs WHERE id={job_id}")[0][0]
    notices = f.db(f"SELECT COUNT(*) FROM admin_notifications WHERE subject_type={q(subject_type)} AND subject_id={message_id}")[0][0]
    return {"result": row, "job_remaining": int(remaining_jobs), "admin_notices": int(notices)}


def enqueue_sending_replay(worker):
    token = uuid.uuid4().hex
    key = "imp010-worker-diff:" + token
    f.db("INSERT INTO whatsapp_messages (idempotency_key,purpose,event,template_class,recipient_phone,body,status,attempts,max_attempts,queued_at,created_at,updated_at) VALUES (" + ",".join((q(key), q("test"), q("test"), q("full"), q("628123456789"), q("already claimed " + token), q("sending"), "1", "1", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ")")
    message_id = int(f.db("SELECT id FROM whatsapp_messages WHERE idempotency_key=" + q(key))[0][0])
    class_name = "App\\Jobs\\SendWhatsAppMessageJob"
    serialized = f'O:{len(class_name)}:"{class_name}":2:{{s:9:"messageId";i:{message_id};s:5:"queue";s:18:"primeclassy-notify";}}'
    payload = json.dumps({"uuid": "imp010-worker-diff-" + token, "displayName": class_name, "job": "Illuminate\\Queue\\CallQueuedHandler@call", "maxTries": 1, "data": {"commandName": class_name, "command": serialized}}, separators=(",", ":"))
    payload_hex = payload.encode().hex()
    f.db("INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at) VALUES (" + ",".join((q(QUEUE), "CONVERT(UNHEX(" + q(payload_hex) + ") USING utf8mb4)", "0", "NULL", str(int(time.time())), str(int(time.time())))) + ")")
    job_id = int(f.db("SELECT MAX(id) FROM jobs WHERE queue=" + q(QUEUE))[0][0])
    if worker == "Go":
        docker(GO_CONTAINER, "/tmp/imp010-notify-worker --once")
    else:
        docker(LARAVEL_CONTAINER, "cd /app && php artisan queue:work database --queue=primeclassy-notify --once --tries=1 --timeout=60 --no-interaction --env=queue-testing")
    state = f.db(f"SELECT status,attempts FROM whatsapp_messages WHERE id={message_id}")[0]
    remaining = int(f.db(f"SELECT COUNT(*) FROM jobs WHERE id={job_id}")[0][0])
    f.db(f"DELETE FROM whatsapp_messages WHERE id={message_id}")
    return {"status": state[0], "attempts": int(state[1]), "job_remaining": remaining}


def main():
    global EMAIL_USER_ID
    if f.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    expected = {}
    outputs = {"Laravel": {}, "Go": {}}
    state = {"settings": None, "initial_job_id": 0, "wa_ids": [], "email_ids": []}
    with compare_orders.TestDatabaseLock():
        try:
            state["settings"] = snapshot_settings()
            state["initial_job_id"] = int(f.db("SELECT COALESCE(MAX(id),0) FROM jobs")[0][0])
            roles = f.db("SELECT id FROM roles ORDER BY id LIMIT 1")
            if not roles:
                raise RuntimeError("test DB must have a role row before worker differential")
            user_email = "worker-diff-" + uuid.uuid4().hex + "@imp010.test"
            f.db("INSERT INTO users (role_id,name,email,password,status,created_at,updated_at) VALUES (%s,'Worker Parity Recipient',%s,'unused-test-password','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (roles[0][0], q(user_email)))
            EMAIL_USER_ID = int(f.db("SELECT id FROM users WHERE email=" + q(user_email))[0][0])
            disable_channels()
            for channel in ("whatsapp", "email"):
                for worker in ("Laravel", "Go"):
                    outputs[worker][channel] = enqueue_message(channel, worker, worker + " " + channel)
                    rows = f.db("SELECT id FROM " + ("whatsapp_messages" if channel == "whatsapp" else "email_messages") + " WHERE idempotency_key LIKE 'imp010-worker-diff:%' ORDER BY id DESC LIMIT 1")
                    if rows:
                        state["wa_ids" if channel == "whatsapp" else "email_ids"].append(int(rows[0][0]))
            replay = {worker: enqueue_sending_replay(worker) for worker in ("Laravel", "Go")}
            expected = {"whatsapp": {"result": ["skipped", "gateway_disabled", "-", "1"], "job_remaining": 0, "admin_notices": 0}, "email": {"result": ["skipped", "mail_disabled", "-"], "job_remaining": 0, "admin_notices": 0}}
            if outputs["Laravel"] != expected or outputs["Go"] != expected:
                raise AssertionError("worker differential mismatch: " + json.dumps(outputs, ensure_ascii=False))
            if replay["Laravel"] != replay["Go"] or replay["Laravel"] != {"status": "sending", "attempts": 1, "job_remaining": 0}:
                raise AssertionError("sending-ledger replay mismatch: " + json.dumps(replay, ensure_ascii=False))
            print("notification worker differential: PASS (Laravel and Go; disabled WhatsApp/email gates)")
            print(json.dumps(outputs, ensure_ascii=False, indent=2))
            print("sending-ledger crash/replay differential: PASS " + json.dumps(replay, ensure_ascii=False))
        finally:
            f.db("DELETE FROM jobs WHERE payload LIKE '%imp010-worker-diff-%'")
            f.db("DELETE FROM failed_jobs WHERE uuid LIKE 'imp010-worker-diff-%'")
            f.db("DELETE FROM admin_notifications WHERE (subject_type='whatsapp_message' AND subject_id IN (SELECT id FROM whatsapp_messages WHERE idempotency_key LIKE 'imp010-worker-diff:%')) OR (subject_type='email_message' AND subject_id IN (SELECT id FROM email_messages WHERE idempotency_key LIKE 'imp010-worker-diff:%'))")
            for message_id in state["wa_ids"]:
                f.db(f"DELETE FROM whatsapp_messages WHERE id={message_id}")
            for message_id in state["email_ids"]:
                f.db(f"DELETE FROM email_messages WHERE id={message_id}")
            f.db("DELETE FROM whatsapp_messages WHERE idempotency_key LIKE 'imp010-worker-diff:%'")
            f.db("DELETE FROM email_messages WHERE idempotency_key LIKE 'imp010-worker-diff:%'")
            if EMAIL_USER_ID:
                f.db(f"DELETE FROM users WHERE id={EMAIL_USER_ID}")
            if state["settings"] is not None:
                restore_settings(state["settings"])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
