#!/usr/bin/env python3
"""GLM differential harness — GOOGLE SHEETS SYNC (1 Laravel-only route).

  POST /google-sheets/configs/{config}/sync

Seeds a destination + config, runs the sync as SUPER. The isolated env has no
Google service-account credential, so the reference SheetsClient fails with
CREDENTIAL_NOT_FOUND after the dataset query has run — the observable contract
is the sync log (rows_processed from the real dataset query, failed status, the
credential error summary, masked spreadsheet id).

Usage: python3 tools/parity/compare_sheets_sync.py
"""
import json
import os
import re
import subprocess
import sys
import uuid

import compare_fulfillment as f
import compare_orders
from compare_fulfillment import LARAVEL as DEFAULT_LARAVEL, GO as DEFAULT_GO, norm_body

SUPER = "super@imp010.test"
LARAVEL = os.environ.get("PARITY_LARAVEL_URL", DEFAULT_LARAVEL).rstrip("/")
GO = os.environ.get("PARITY_GO_URL", DEFAULT_GO).rstrip("/")
TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def seed():
    token = uuid.uuid4().hex[:12]
    spreadsheet = "imp010-sync-" + token
    f.db("INSERT INTO sheets_destinations (agent_id,spreadsheet_id,created_at,updated_at) VALUES (NULL,'%s',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % spreadsheet)
    destination = int(f.db("SELECT id FROM sheets_destinations WHERE spreadsheet_id='%s'" % spreadsheet)[0][0])
    definitions = [
        ("Products", "TabA-" + token, "products", [{"field": "id", "label": "ID"}, {"field": "sku", "label": "SKU"}]),
        ("BadMap", "TabB-" + token, "products", [{"field": "no_such_field", "label": "X"}]),
        ("Tx", "TabC-" + token, "transactions", [{"field": "order_no", "label": "Order"}, {"field": "sku", "label": "SKU"}]),
    ]
    config_ids = []
    for name, tab, dataset, columns in definitions:
        raw = json.dumps(columns, separators=(",", ":")).encode().hex()
        f.db("INSERT INTO sheets_configs (destination_id,name,tab,dataset,columns,created_at,updated_at) VALUES (%d,'%s','%s','%s',CONVERT(UNHEX('%s') USING utf8mb4),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (destination, name, tab, dataset, raw))
        config_ids.append(int(f.db("SELECT id FROM sheets_configs WHERE destination_id=%d AND tab='%s'" % (destination, tab))[0][0]))
    return destination, config_ids


def ensure_super_admin():
    existing = f.db("SELECT id FROM users WHERE email='super@imp010.test'")
    if existing:
        return int(existing[0][0]), None, False
    role = f.db("SELECT id FROM roles WHERE slug='super_admin'")
    role_created = None
    if role:
        role_id = int(role[0][0])
    else:
        f.db("INSERT INTO roles (slug,name,created_at,updated_at) VALUES ('super_admin','Super Admin',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
        role_id = int(f.db("SELECT id FROM roles WHERE slug='super_admin'")[0][0])
        role_created = role_id
    result = subprocess.run(["docker", "exec", "pc_laravel_imp010", "php", "-r", "echo password_hash($argv[1], PASSWORD_BCRYPT);", "Secret123!"], capture_output=True, text=True, timeout=30)
    if result.returncode or not result.stdout.startswith("$2y$"):
        raise RuntimeError("could not create an isolated test login hash: " + result.stderr[:300])
    password = result.stdout.strip()
    password_hex = password.encode().hex()
    f.db("INSERT INTO users (role_id,name,email,status,password,created_at,updated_at) VALUES (%d,'Super Admin','super@imp010.test','active',CONVERT(UNHEX('%s') USING utf8mb4),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (role_id, password_hex))
    return int(f.db("SELECT id FROM users WHERE email='super@imp010.test'")[0][0]), role_created, True


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "config_id") else scrub(x)) for k, x in v.items()}
    return v


def scenario(base, config_ids):
    out = {}
    c = f.Client(base)
    c.call("GET", "/sanctum/csrf-cookie")
    status, response = c.call("POST", "/api/v1/auth/login", {"identifier": SUPER, "password": "Secret123!"}, {"X-Locale": "id"})
    if status != 200:
        raise RuntimeError(f"login failed for {SUPER} on {base}: status={status}, response={response}")
    call = lambda method, path: c.call(method, path, extra={"X-Locale": "id"})
    products, invalid, transactions = config_ids
    out["sync_missing_config"] = call("POST", "/api/v1/google-sheets/configs/999999999/sync")
    out["sync_products"] = call("POST", f"/api/v1/google-sheets/configs/{products}/sync")
    out["log_products"] = f.db(f"SELECT status, rows_processed, rows_success, rows_failed, error_summary FROM sheets_sync_logs WHERE config_id={products} ORDER BY id DESC LIMIT 1")
    out["sync_invalid_mapping"] = call("POST", f"/api/v1/google-sheets/configs/{invalid}/sync")
    out["log_invalid_mapping"] = f.db(f"SELECT status, rows_processed, error_summary FROM sheets_sync_logs WHERE config_id={invalid} ORDER BY id DESC LIMIT 1")
    out["sync_transactions"] = call("POST", f"/api/v1/google-sheets/configs/{transactions}/sync")
    out["log_transactions"] = f.db(f"SELECT status, rows_processed, error_summary FROM sheets_sync_logs WHERE config_id={transactions} ORDER BY id DESC LIMIT 1")
    return out


def main():
    if f.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    destination = None
    config_ids = []
    actor_id = None
    created_role = None
    actor_created = False
    with compare_orders.TestDatabaseLock():
        try:
            actor_id, created_role, actor_created = ensure_super_admin()
            destination, config_ids = seed()
            lar = scenario(LARAVEL, config_ids)
            go = scenario(GO, config_ids)
            failed = 0
            total = 0
            for key in sorted(lar):
                total += 1
                lv, gv = scrub(norm_body(lar[key])), scrub(norm_body(go[key]))
                ok = lv == gv
                if not ok:
                    failed += 1
                print(("PASS " if ok else "FAIL ") + key)
                if not ok:
                    print("  L=" + json.dumps(lv, default=str)[:500])
                    print("  G=" + json.dumps(gv, default=str)[:500])
            print(f"sheets_sync: {total - failed} passed, {failed} failed")
            return 1 if failed else 0
        finally:
            if config_ids:
                ids = ",".join(str(int(i)) for i in config_ids)
                f.db(f"DELETE FROM sheets_sync_logs WHERE config_id IN ({ids})")
                f.db(f"DELETE FROM sheets_configs WHERE id IN ({ids})")
            if destination:
                f.db(f"DELETE FROM sheets_destinations WHERE id={int(destination)}")
            if actor_created and actor_id:
                f.db(f"DELETE FROM users WHERE id={actor_id}")
            if created_role:
                f.db(f"DELETE FROM roles WHERE id={created_role}")


if __name__ == "__main__":
    sys.exit(main())
