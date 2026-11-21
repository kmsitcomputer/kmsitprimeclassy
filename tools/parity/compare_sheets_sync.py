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

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, norm_body

SUPER = "super@imp010.test"
TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def seed():
    sql = [
        "DELETE FROM sheets_sync_logs;",
        "DELETE FROM sheets_configs;",
        "DELETE FROM sheets_destinations;",
        "INSERT INTO sheets_destinations (id, agent_id, spreadsheet_id, created_at, updated_at) VALUES (1, NULL, 'sheet123456789', NOW(), NOW());",
        "INSERT INTO sheets_destinations (id, agent_id, spreadsheet_id, created_at, updated_at) VALUES (2, 10, 'agent9999sheet', NOW(), NOW());",
    ]
    cols = json.dumps([{"field": "id", "label": "ID"}, {"field": "sku", "label": "SKU"}])
    sql.append("INSERT INTO sheets_configs (id, destination_id, name, tab, dataset, columns, created_at, updated_at) VALUES (1, 1, 'Products', 'TabA', 'products', '%s', NOW(), NOW());" % cols)
    bad = json.dumps([{"field": "no_such_field", "label": "X"}])
    sql.append("INSERT INTO sheets_configs (id, destination_id, name, tab, dataset, columns, created_at, updated_at) VALUES (2, 1, 'BadMap', 'TabB', 'products', '%s', NOW(), NOW());" % bad)
    # transactions dataset config (row count from the canonical item projection)
    tcols = json.dumps([{"field": "order_no", "label": "Order"}, {"field": "sku", "label": "SKU"}])
    sql.append("INSERT INTO sheets_configs (id, destination_id, name, tab, dataset, columns, created_at, updated_at) VALUES (3, 1, 'Tx', 'TabC', 'transactions', '%s', NOW(), NOW());" % tcols)
    payload = "\n".join(sql)
    p = subprocess.run(["docker", "exec", "-i", "primeclassy-mariadb", "sh", "-lc",
                        'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing'],
                       input=payload, capture_output=True, text=True)
    assert p.returncode == 0, p.stderr[:300]


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


def scenario(base):
    out = {}
    c = f.client_for(base, SUPER)
    out["sync_missing_config"] = c.call("POST", "/api/v1/google-sheets/configs/999/sync")
    out["sync_products"] = c.call("POST", "/api/v1/google-sheets/configs/1/sync")
    out["log_products"] = f.db("SELECT status, rows_processed, rows_success, rows_failed, error_summary FROM sheets_sync_logs WHERE config_id=1 ORDER BY id DESC LIMIT 1")
    out["sync_invalid_mapping"] = c.call("POST", "/api/v1/google-sheets/configs/2/sync")
    out["log_invalid_mapping"] = f.db("SELECT status, rows_processed, error_summary FROM sheets_sync_logs WHERE config_id=2 ORDER BY id DESC LIMIT 1")
    out["sync_transactions"] = c.call("POST", "/api/v1/google-sheets/configs/3/sync")
    out["log_transactions"] = f.db("SELECT status, rows_processed, error_summary FROM sheets_sync_logs WHERE config_id=3 ORDER BY id DESC LIMIT 1")
    return out


def main():
    seed()
    lar = scenario(LARAVEL)
    seed()
    go = scenario(GO)
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


if __name__ == "__main__":
    sys.exit(main())
