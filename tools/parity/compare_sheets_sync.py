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
import re
import subprocess
import sys
import uuid

import compare_fulfillment as f
import compare_orders
from compare_fulfillment import LARAVEL, GO, norm_body

SUPER = "super@imp010.test"
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
    c = f.client_for(base, SUPER)
    products, invalid, transactions = config_ids
    out["sync_missing_config"] = c.call("POST", "/api/v1/google-sheets/configs/999999999/sync")
    out["sync_products"] = c.call("POST", f"/api/v1/google-sheets/configs/{products}/sync")
    out["log_products"] = f.db(f"SELECT status, rows_processed, rows_success, rows_failed, error_summary FROM sheets_sync_logs WHERE config_id={products} ORDER BY id DESC LIMIT 1")
    out["sync_invalid_mapping"] = c.call("POST", f"/api/v1/google-sheets/configs/{invalid}/sync")
    out["log_invalid_mapping"] = f.db(f"SELECT status, rows_processed, error_summary FROM sheets_sync_logs WHERE config_id={invalid} ORDER BY id DESC LIMIT 1")
    out["sync_transactions"] = c.call("POST", f"/api/v1/google-sheets/configs/{transactions}/sync")
    out["log_transactions"] = f.db(f"SELECT status, rows_processed, error_summary FROM sheets_sync_logs WHERE config_id={transactions} ORDER BY id DESC LIMIT 1")
    return out


def main():
    if f.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    destination = None
    config_ids = []
    with compare_orders.TestDatabaseLock():
        try:
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


if __name__ == "__main__":
    sys.exit(main())
