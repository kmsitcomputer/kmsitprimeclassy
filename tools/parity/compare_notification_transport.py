#!/usr/bin/env python3
"""Compare Laravel and Go successful GOWA/SMTP delivery using local-only stubs."""
import base64
from email import policy
from email.parser import BytesParser
import json
import pathlib
import socketserver
import subprocess
import sys
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import compare_fulfillment as f
import compare_orders

GO_CONTAINER = "pc_go_imp010"
LARAVEL_CONTAINER = "pc_laravel_imp010"
QUEUE = "primeclassy-notify"
WA_KEYS = ["wa.enabled", "wa.base_url", "wa.username", "wa.password", "wa.device_id", "wa.timeout"]
SMTP_KEYS = ["smtp.enabled", "smtp.host", "smtp.port", "smtp.from_address", "smtp.from_name", "smtp.username", "smtp.password", "smtp.encryption", "smtp.timeout", "smtp.transactional_enabled"]


def q(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


class FakeGowa(BaseHTTPRequestHandler):
    requests = []

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        auth = self.headers.get("Authorization", "")
        device = self.headers.get("X-Device-Id", "")
        payload = json.loads(raw or b"{}")
        self.requests.append((self.path, auth, device, payload))
        if payload.get("message") == "force-failure-auth":
            body = json.dumps({"message": "provider rejected credentials"}).encode()
            self.send_response(401)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if payload.get("message") == "force-ambiguous-503":
            body = json.dumps({"message": "temporary provider failure"}).encode()
            self.send_response(503)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        if payload.get("message") == "force-timeout-after-send":
            time.sleep(3)
            body = json.dumps({"code": "SUCCESS", "results": {"message_id": "late-message"}}).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):
                pass
            return
        body = json.dumps({"code": "SUCCESS", "results": {"message_id": "fake-message-1"}}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


class SMTPHandler(socketserver.StreamRequestHandler):
    messages = []

    def handle(self):
        self.wfile.write(b"220 localhost ESMTP ready\r\n")
        data = False
        message = []
        while True:
            line = self.rfile.readline()
            if not line:
                break
            text = line.decode("utf-8", "replace").rstrip("\r\n")
            upper = text.upper()
            if data:
                if text == ".":
                    self.messages.append("\n".join(message))
                    message = []
                    data = False
                    self.wfile.write(b"250 queued\r\n")
                else:
                    message.append(text[1:] if text.startswith("..") else text)
            elif upper.startswith("EHLO") or upper.startswith("HELO"):
                self.wfile.write(b"250-localhost\r\n250 SIZE 10485760\r\n")
            elif upper.startswith("RCPT TO") and "SMTP-FAILURE@" in upper:
                self.wfile.write(b"550 5.1.1 test recipient rejected\r\n")
            elif upper.startswith("MAIL FROM") or upper.startswith("RCPT TO"):
                self.wfile.write(b"250 ok\r\n")
            elif upper == "DATA":
                data = True
                self.wfile.write(b"354 end with dot\r\n")
            elif upper == "QUIT":
                self.wfile.write(b"221 bye\r\n")
                break
            else:
                self.wfile.write(b"250 ok\r\n")


class SMTPServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


EMAIL_USER_ID = 0
TEMP_ROLE_ID = 0
TEMP_ROLE_CREATED = False


def insert_job(class_name, serialized):
    payload = json.dumps({"uuid": "imp010-transport-" + uuid.uuid4().hex, "displayName": class_name,
        "job": "Illuminate\\Queue\\CallQueuedHandler@call", "maxTries": 1, "maxExceptions": None,
        "failOnTimeout": False, "backoff": None, "timeout": 60, "retryUntil": None,
        "data": {"commandName": class_name, "command": serialized}}, separators=(",", ":"))
    encoded = payload.encode().hex()
    f.db("INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at) VALUES (" + ",".join((q(QUEUE), "CONVERT(UNHEX(" + q(encoded) + ") USING utf8mb4)", "0", "NULL", str(int(time.time())), str(int(time.time())))) + ")")


def run_worker(which):
    if which == "Go":
        command = "/tmp/imp010-notify-worker --once"
        container = GO_CONTAINER
    else:
        command = "cd /app && php artisan queue:work database --queue=primeclassy-notify --once --tries=1 --timeout=60 --no-interaction --env=queue-testing"
        container = LARAVEL_CONTAINER
    result = subprocess.run(["docker", "exec", container, "sh", "-lc", command], capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError(f"{which} worker failed: {result.stderr[-1000:]} {result.stdout[-400:]}")


def encrypt_test_password():
    code = 'require "/app/vendor/autoload.php"; $app=require "/app/bootstrap/app.php"; $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo \\Illuminate\\Support\\Facades\\Crypt::encryptString("worker-secret");'
    result = subprocess.run(["docker", "exec", "-e", "APP_ENV=queue-testing", LARAVEL_CONTAINER, "php", "-r", code], capture_output=True, text=True, timeout=30)
    if result.returncode or not result.stdout.strip():
        raise RuntimeError("unable to produce isolated encrypted test credential: " + result.stderr[-500:])
    return result.stdout.strip()


def set_active_settings(cipher, gowa_port, smtp_port):
    values = {
        "integration.whatsapp": {"wa.enabled": "1", "wa.base_url": f"http://host.docker.internal:{gowa_port}", "wa.username": "worker", "wa.password": cipher, "wa.device_id": "global-test-slot", "wa.timeout": "2"},
        "integration.smtp": {"smtp.enabled": "1", "smtp.host": "host.docker.internal", "smtp.port": str(smtp_port), "smtp.from_address": "noreply@imp010.test", "smtp.from_name": "PrimeClassy Test", "smtp.username": "", "smtp.password": "", "smtp.encryption": "none", "smtp.timeout": "5", "smtp.transactional_enabled": "1"},
    }
    for group, rows in values.items():
        for key, value in rows.items():
            f.db("INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) VALUES (" + ",".join((q(key), q(value), q(group), "0", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ") ON DUPLICATE KEY UPDATE value=VALUES(value),`group`=VALUES(`group`),updated_at=UTC_TIMESTAMP(6)")


def add_whatsapp(tag, outcome=None):
    key = "imp010-transport:" + uuid.uuid4().hex
    body = {"auth": "force-failure-auth", "ambiguous": "force-ambiguous-503", "timeout": "force-timeout-after-send"}.get(outcome, "transport parity body")
    f.db("INSERT INTO whatsapp_messages (idempotency_key,purpose,event,template_class,recipient_phone,body,status,max_attempts,queued_at,created_at,updated_at) VALUES (" + ",".join((q(key), q("test"), q("test"), q("full"), q("628123456789"), q(body), q("queued"), "1", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ")")
    message_id = int(f.db("SELECT id FROM whatsapp_messages WHERE idempotency_key=" + q(key))[0][0])
    cls = "App\\Jobs\\SendWhatsAppMessageJob"
    serialized = f'O:{len(cls)}:"{cls}":2:{{s:9:"messageId";i:{message_id};s:5:"queue";s:18:"primeclassy-notify";}}'
    insert_job(cls, serialized)
    return message_id


def add_email(tag, failure=False):
    key = "imp010-transport:" + uuid.uuid4().hex
    recipient = "smtp-failure@imp010.test" if failure else "konsumen@imp010.test"
    f.db("INSERT INTO email_messages (idempotency_key,event,recipient_role,recipient_user_id,recipient_email,subject,body,status,created_at,updated_at) VALUES (" + ",".join((q(key), q("test"), q("creator"), str(EMAIL_USER_ID), q(recipient), q("Worker transport parity"), q("transport parity body"), q("queued"), "UTC_TIMESTAMP(6)", "UTC_TIMESTAMP(6)")) + ")")
    message_id = int(f.db("SELECT id FROM email_messages WHERE idempotency_key=" + q(key))[0][0])
    cls = "App\\Jobs\\SendTransactionalEmailJob"
    serialized = f'O:{len(cls)}:"{cls}":2:{{s:9:"messageId";i:{message_id};s:5:"queue";s:18:"primeclassy-notify";}}'
    insert_job(cls, serialized)
    return message_id


def main():
    global EMAIL_USER_ID, TEMP_ROLE_ID, TEMP_ROLE_CREATED
    if f.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    settings = None
    ids = {"wa": [], "wa_failure": [], "wa_ambiguous": [], "wa_timeout": [], "email": [], "email_failure": []}
    initial_jobs = 0
    gowa = ThreadingHTTPServer(("0.0.0.0", 0), FakeGowa)
    smtp = SMTPServer(("0.0.0.0", 0), SMTPHandler)
    threading.Thread(target=gowa.serve_forever, daemon=True).start()
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    try:
        with compare_orders.TestDatabaseLock():
            try:
                keys = WA_KEYS + SMTP_KEYS
                settings = f.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `key` IN (" + ",".join(q(k) for k in keys) + ")")
                initial_jobs = int(f.db("SELECT COALESCE(MAX(id),0) FROM jobs")[0][0])
                roles = f.db("SELECT id FROM roles WHERE slug='super_admin' LIMIT 1")
                if roles:
                    TEMP_ROLE_ID = int(roles[0][0])
                else:
                    f.db("INSERT INTO roles (slug,name,created_at,updated_at) VALUES ('super_admin','Super Admin',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
                    TEMP_ROLE_ID = int(f.db("SELECT id FROM roles WHERE slug='super_admin'")[0][0])
                    TEMP_ROLE_CREATED = True
                f.db("INSERT INTO users (role_id,name,email,password,status,created_at,updated_at) VALUES (%s,'Worker parity recipient','worker-%s@imp010.test','unused-test-password','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (TEMP_ROLE_ID, uuid.uuid4().hex))
                EMAIL_USER_ID = int(f.db("SELECT id FROM users WHERE email LIKE 'worker-%%@imp010.test' ORDER BY id DESC LIMIT 1")[0][0])
                cipher = encrypt_test_password()
                set_active_settings(cipher, gowa.server_port, smtp.server_address[1])
                for worker in ("Laravel", "Go"):
                    wa_id = add_whatsapp(worker)
                    ids["wa"].append(wa_id)
                    run_worker(worker)
                    email_id = add_email(worker)
                    ids["email"].append(email_id)
                    run_worker(worker)
                    email_failure_id = add_email(worker, failure=True)
                    ids["email_failure"].append(email_failure_id)
                    run_worker(worker)
                    failure_id = add_whatsapp(worker, outcome="auth")
                    ids["wa_failure"].append(failure_id)
                    run_worker(worker)
                    ambiguous_id = add_whatsapp(worker, outcome="ambiguous")
                    ids["wa_ambiguous"].append(ambiguous_id)
                    run_worker(worker)
                    timeout_id = add_whatsapp(worker, outcome="timeout")
                    ids["wa_timeout"].append(timeout_id)
                    run_worker(worker)
                statuses = {}
                for worker, wa_id, email_id, failure_id, ambiguous_id, timeout_id, email_failure_id in zip(("Laravel", "Go"), ids["wa"], ids["email"], ids["wa_failure"], ids["wa_ambiguous"], ids["wa_timeout"], ids["email_failure"]):
                    statuses[worker] = {
                        "whatsapp": f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),attempts,IFNULL(provider_message_id,'-') FROM whatsapp_messages WHERE id={wa_id}")[0],
                        "email": f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),IFNULL(error_message,'-') FROM email_messages WHERE id={email_id}")[0],
                        "email_failure": f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),IFNULL(error_message,'-') FROM email_messages WHERE id={email_failure_id}")[0],
                        "whatsapp_failure": f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),attempts,IFNULL(error_message,'-') FROM whatsapp_messages WHERE id={failure_id}")[0],
                        "whatsapp_ambiguous": f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),attempts,IFNULL(error_message,'-') FROM whatsapp_messages WHERE id={ambiguous_id}")[0],
                        "whatsapp_timeout": f.db(f"SELECT status,IFNULL(skip_reason,'-'),IFNULL(error_class,'-'),attempts,IFNULL(error_message,'-') FROM whatsapp_messages WHERE id={timeout_id}")[0],
                        "jobs": f.db(f"SELECT COUNT(*) FROM jobs WHERE payload LIKE '%imp010-transport-%'")[0][0],
                        "admin_notices": f.db(f"SELECT type,severity,title,body,subject_type,recipient_user_id,IFNULL(agent_id,'-') FROM admin_notifications WHERE subject_type='email_message' AND subject_id={email_failure_id} OR subject_type='whatsapp_message' AND subject_id IN ({failure_id},{ambiguous_id},{timeout_id}) ORDER BY type,recipient_user_id"),
                    }
                comparable = json.loads(json.dumps(statuses))
                for worker in ("Laravel", "Go"):
                    # The two HTTP stacks report timeout wording differently; compare the
                    # persisted uncertainty state/class, attempt count, queue and alerts.
                    comparable[worker]["whatsapp_timeout"][4] = "<transport-specific timeout detail>"
                if comparable["Laravel"] != comparable["Go"]:
                    raise AssertionError("Laravel/Go delivery status mismatch: " + json.dumps(statuses))
                expected_gowa = [
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "transport parity body"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "transport parity body"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "force-failure-auth"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "force-failure-auth"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "force-ambiguous-503"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "force-ambiguous-503"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "force-timeout-after-send"}),
                    ("/send/message", "Basic " + base64.b64encode(b"worker:worker-secret").decode(), "global-test-slot", {"phone": "628123456789@s.whatsapp.net", "message": "force-timeout-after-send"}),
                ]
                observed_gowa = FakeGowa.requests
                if sorted(json.dumps(x, sort_keys=True) for x in observed_gowa) != sorted(json.dumps(x, sort_keys=True) for x in expected_gowa):
                    raise AssertionError("GOWA request mismatch: " + json.dumps(observed_gowa))
                if len(SMTPHandler.messages) != 2:
                    raise AssertionError(f"SMTP sends={len(SMTPHandler.messages)}, expected 2 successful deliveries")
                smtp_messages = [BytesParser(policy=policy.default).parsebytes(value.encode()) for value in SMTPHandler.messages]
                smtp_contracts = [(m.get("To"), m.get("Subject"), m.get_content_type(), m.get_content().rstrip("\r\n")) for m in smtp_messages]
                if smtp_contracts[0] != smtp_contracts[1] or smtp_contracts[0][0] != "konsumen@imp010.test" or smtp_contracts[0][2] != "text/html":
                    raise AssertionError("SMTP MIME contract mismatch: " + repr(smtp_contracts))
                expected_html = "transport parity body<br><br>Salam,<br><strong>Tim PrimeClassy</strong><br><br><small>Pesan ini dikirim otomatis berdasarkan aktivitas transaksi atau akun Anda. Untuk bantuan, gunakan kanal layanan pelanggan resmi PrimeClassy.</small>"
                if smtp_contracts[0][3] != expected_html:
                    raise AssertionError("SMTP HTML body mismatch: " + repr(smtp_contracts[0][3]))
                if statuses["Laravel"]["whatsapp"][0] != "sent" or statuses["Laravel"]["email"][0] != "sent" or statuses["Laravel"]["jobs"] != "0":
                    raise AssertionError("successful notification delivery not recorded: " + json.dumps(statuses))
                if statuses["Laravel"]["whatsapp_failure"] != ["failed", "-", "auth", "1", "Gateway rejected the credentials."]:
                    raise AssertionError("terminal GOWA auth failure was not recorded as expected: " + json.dumps(statuses))
                if statuses["Laravel"]["whatsapp_ambiguous"] != ["unknown", "-", "provider_error", "1", "HTTP 503: temporary provider failure"]:
                    raise AssertionError("ambiguous GOWA 503 did not remain UNKNOWN without retry: " + json.dumps(statuses))
                if statuses["Laravel"]["whatsapp_timeout"][0:4] != ["unknown", "-", "timeout", "1"]:
                    raise AssertionError("post-send GOWA timeout did not remain UNKNOWN without retry: " + json.dumps(statuses))
                if statuses["Laravel"]["email_failure"][0] != "failed":
                    raise AssertionError("SMTP recipient rejection was not recorded as failed: " + json.dumps(statuses))
                if not statuses["Laravel"]["admin_notices"] or statuses["Laravel"]["admin_notices"][0][0:2] != ["delivery.failed", "critical"]:
                    raise AssertionError("SMTP failure did not create its admin alert: " + json.dumps(statuses))
                if len(statuses["Laravel"]["admin_notices"]) != 1 or statuses["Laravel"]["admin_notices"][0][0:2] != ["delivery.failed", "critical"] or statuses["Laravel"]["admin_notices"][0][4] != "email_message":
                    raise AssertionError("unexpected admin alert for test-purpose GOWA or failed email: " + json.dumps(statuses))
                print("notification transport differential: PASS (GOWA success/auth/503/timeout, SMTP success/failure, matching retry safety, ledger and alerts)")
                print(json.dumps(statuses, ensure_ascii=False, indent=2))
            finally:
                f.db("DELETE FROM jobs WHERE payload LIKE '%imp010-transport-%'")
                f.db("DELETE FROM failed_jobs WHERE uuid LIKE 'imp010-transport-%'")
                f.db("DELETE FROM admin_notifications WHERE (subject_type='whatsapp_message' AND subject_id IN (SELECT id FROM whatsapp_messages WHERE idempotency_key LIKE 'imp010-transport:%')) OR (subject_type='email_message' AND subject_id IN (SELECT id FROM email_messages WHERE idempotency_key LIKE 'imp010-transport:%'))")
                f.db("DELETE FROM whatsapp_messages WHERE idempotency_key LIKE 'imp010-transport:%'")
                f.db("DELETE FROM email_messages WHERE idempotency_key LIKE 'imp010-transport:%'")
                if EMAIL_USER_ID:
                    f.db(f"DELETE FROM users WHERE id={EMAIL_USER_ID}")
                if TEMP_ROLE_CREATED and TEMP_ROLE_ID:
                    f.db(f"DELETE FROM roles WHERE id={TEMP_ROLE_ID}")
                if settings is not None:
                    keys = WA_KEYS + SMTP_KEYS
                    f.db("DELETE FROM settings WHERE `key` IN (" + ",".join(q(k) for k in keys) + ")")
                    for row in settings:
                        values = ["NULL" if x == "NULL" else (str(x) if i in (0, 4) else q(x)) for i, x in enumerate(row)]
                        f.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES (" + ",".join(values) + ")")
    finally:
        gowa.shutdown()
        gowa.server_close()
        smtp.shutdown()
        smtp.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
