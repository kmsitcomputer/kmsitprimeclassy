#!/usr/bin/env python3
"""Differential-check Sheets config create/update/delete, index serialization and shared lock behavior."""
import importlib.util
import json
import pathlib
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("parity", ROOT / "tools/parity/compare_return_review.py")
parity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(parity)


def normalize(value):
    if isinstance(value, tuple): return tuple(normalize(item) for item in value)
    if isinstance(value, dict):
        result = {}
        for key, item in value.items():
            if key == "id" or key == "destination_id": result[key] = "ID"
            elif key in ("created_at", "updated_at", "last_tested_at"): result[key] = "TIME" if item else None
            else: result[key] = normalize(item)
        return result
    if isinstance(value, list): return [normalize(item) for item in value]
    return value


def call(client, method, path, payload=None):
    body = None if payload is None else json.dumps(payload).encode()
    headers = {} if payload is None else {"Content-Type": "application/json"}
    return client.call(method, path, body, headers)


def main():
    if parity.db("SELECT DATABASE()")[0][0] != "primeclassy_testing": raise RuntimeError("refusing non-testing database")
    agent = int(parity.db("SELECT id FROM users WHERE email='agen@imp010.test'")[0][0])
    token = uuid.uuid4().hex[:12]
    spreadsheet = "pc-sheets-config-" + token
    parity.db("INSERT INTO sheets_destinations(spreadsheet_id,agent_id,created_at,updated_at) VALUES('%s',%d,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (spreadsheet, agent))
    destination = int(parity.db("SELECT id FROM sheets_destinations WHERE spreadsheet_id='%s'" % spreadsheet)[0][0])
    seed_tab = "Base-" + token
    parity.db("INSERT INTO sheets_configs(destination_id,name,tab,dataset,columns,filters,created_at,updated_at) VALUES(%d,'Base','%s','sales','[{\"field\":\"name\",\"label\":\"Nama\"}]',NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (destination, seed_tab))
    config_id = int(parity.db("SELECT id FROM sheets_configs WHERE destination_id=%d" % destination)[0][0])
    global_sheet = "pc-sheets-global-" + token
    parity.db("INSERT INTO sheets_destinations(spreadsheet_id,agent_id,created_at,updated_at) VALUES('%s',NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % global_sheet)
    global_destination = int(parity.db("SELECT id FROM sheets_destinations WHERE spreadsheet_id='%s'" % global_sheet)[0][0])
    parity.db("INSERT INTO sheets_configs(destination_id,name,tab,dataset,columns,filters,created_at,updated_at) VALUES(%d,'Global','Global','sales','[{\"field\":\"name\",\"label\":\"Nama\"}]',NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % global_destination)
    global_config = int(parity.db("SELECT id FROM sheets_configs WHERE destination_id=%d" % global_destination)[0][0])
    try:
        payload = {"destination_id": destination, "name": "Produk", "tab": "Product Export", "dataset": "products", "columns": [{"field": "sku", "label": "SKU"}], "filters": None}
        validation_cases = []
        case = dict(payload); case["agent_id"] = agent; validation_cases.append(case)
        case = dict(payload); case["dataset"] = "unknown"; validation_cases.append(case)
        case = dict(payload); case["columns"] = [{"field": "missing", "label": "X"}]; validation_cases.append(case)
        case = dict(payload); case["filters"] = {"category_group": "cookies"}; validation_cases.append(case)
        case = dict(payload); case["filters"] = {"category_group": "other"}; validation_cases.append(case)
        case = dict(payload); case["filters"] = {"period": "daily"}; validation_cases.append(case)
        case = dict(payload); case["filters"] = {"period": "custom"}; validation_cases.append(case)
        case = dict(payload); case["dataset"] = "transaction_worksheet"; case["columns"] = [{"field": "no", "label": "No"}]; case["filters"] = {"period": "custom"}; validation_cases.append(case)
        case = dict(payload); case["dataset"] = "transaction_worksheet"; case["columns"] = [{"field": "no", "label": "No"}]; case["filters"] = {"period": "custom", "from": "2026-02-02", "to": "2026-01-01"}; validation_cases.append(case)
        case = dict(payload); case["filters"] = {"unknown": "value"}; validation_cases.append(case)
        validation_clients=[]
        for base in (parity.LARAVEL, parity.GO):
            client=parity.Client(base)
            if client.login("agen@imp010.test")!=200: raise AssertionError(f"Agent login failed for {base}")
            validation_clients.append(client)
        for index,case in enumerate(validation_cases):
            outputs=[call(client,"POST","/api/v1/google-sheets/configs",case) for client in validation_clients]
            if outputs[0]!=outputs[1] or outputs[0][0]!=422:
                raise AssertionError(f"config validation case {index} mismatch: Laravel={outputs[0]!r} Go={outputs[1]!r}")
        create_outputs = []
        for base in (parity.LARAVEL, parity.GO):
            client = parity.Client(base)
            if client.login("agen@imp010.test") != 200: raise AssertionError(f"Agent login failed for {base}")
            out = call(client, "POST", "/api/v1/google-sheets/configs", payload)
            create_outputs.append(normalize(out))
            if out[0] == 201:
                created_id = int(out[1]["data"]["id"])
                if base == parity.LARAVEL: parity.db(f"DELETE FROM sheets_configs WHERE id={created_id}")
                else: parity.db(f"DELETE FROM sheets_configs WHERE id={created_id}")
        if create_outputs[0] != create_outputs[1] or create_outputs[0][0] != 201: raise AssertionError(f"config create mismatch: {create_outputs!r}")

        update_payload = {"destination_id": destination, "name": "Updated", "tab": "Updated-" + token, "dataset": "products", "columns": [{"field": "product_name", "label": "Produk"}], "filters": {"status": "active"}}
        update_outputs = []
        for base in (parity.LARAVEL, parity.GO):
            parity.db(f"UPDATE sheets_configs SET name='Base',tab='{seed_tab}',dataset='sales',columns='[{{\"field\":\"name\",\"label\":\"Nama\"}}]',filters=NULL,updated_at=created_at WHERE id={config_id}")
            client = parity.Client(base)
            if client.login("agen@imp010.test") != 200: raise AssertionError(f"Agent login failed for {base}")
            out = call(client, "PUT", f"/api/v1/google-sheets/configs/{config_id}", update_payload)
            update_outputs.append(normalize(out))
        if update_outputs[0] != update_outputs[1] or update_outputs[0][0] != 200: raise AssertionError(f"config update mismatch: {update_outputs!r}")

        lock_key = f"sheets-sync-{config_id}"
        parity.db("INSERT INTO cache_locks(`key`,owner,expiration) VALUES('%s','probe-owner',UNIX_TIMESTAMP()+120) ON DUPLICATE KEY UPDATE owner='probe-owner',expiration=UNIX_TIMESTAMP()+120" % lock_key)
        lock_outputs = []
        for base in (parity.LARAVEL, parity.GO):
            client = parity.Client(base)
            if client.login("agen@imp010.test") != 200: raise AssertionError(f"Agent login failed for {base}")
            lock_outputs.append(normalize(call(client, "PUT", f"/api/v1/google-sheets/configs/{config_id}", update_payload)))
        parity.db("DELETE FROM cache_locks WHERE `key`='%s' AND owner='probe-owner'" % lock_key)
        if lock_outputs[0] != lock_outputs[1] or lock_outputs[0][0] != 200: raise AssertionError(f"testing array-cache mutation mismatch: {lock_outputs!r}")

        forbidden = []
        admin_payload = dict(update_payload)
        admin_payload["dataset"] = "financial_summary"
        admin_payload["columns"] = [{"field": "transaction_count", "label": "Jumlah"}]
        for base in (parity.LARAVEL, parity.GO):
            client = parity.Client(base)
            if client.login("admin@imp010.test") != 200: raise AssertionError(f"Admin login failed for {base}")
            forbidden.append(call(client, "PUT", f"/api/v1/google-sheets/configs/{config_id}", admin_payload))
        if forbidden[0] != forbidden[1] or forbidden[0][0] != 403: raise AssertionError(f"config branch authorization mismatch: {forbidden!r}")

        foreign_outputs=[]
        for base in (parity.LARAVEL, parity.GO):
            client=parity.Client(base)
            if client.login("agen@imp010.test")!=200: raise AssertionError(f"Agent login failed for {base}")
            foreign_outputs.append((call(client,"PUT",f"/api/v1/google-sheets/configs/{global_config}",update_payload),call(client,"DELETE",f"/api/v1/google-sheets/configs/{global_config}")))
        if foreign_outputs[0]!=foreign_outputs[1] or foreign_outputs[0][0][0]!=403 or foreign_outputs[0][1][0]!=403:
            raise AssertionError(f"foreign config authorization mismatch (equal={foreign_outputs[0]==foreign_outputs[1]}, statuses={[x[0][0] for x in foreign_outputs]}): {foreign_outputs!r}")

        delete_outputs = []
        for base in (parity.LARAVEL, parity.GO):
            parity.db(f"UPDATE sheets_configs SET name='Base',tab='{seed_tab}',dataset='sales',columns='[{{\"field\":\"name\",\"label\":\"Nama\"}}]',filters=NULL WHERE id={config_id}")
            client = parity.Client(base)
            if client.login("agen@imp010.test") != 200: raise AssertionError(f"Agent login failed for {base}")
            delete_outputs.append(call(client, "DELETE", f"/api/v1/google-sheets/configs/{config_id}"))
            if base == parity.LARAVEL:
                parity.db("INSERT INTO sheets_configs(id,destination_id,name,tab,dataset,columns,filters,created_at,updated_at) VALUES(%d,%d,'Base','%s','sales','[{\"field\":\"name\",\"label\":\"Nama\"}]',NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % (config_id,destination,seed_tab))
        if delete_outputs[0] != delete_outputs[1] or delete_outputs[0][0] != 200: raise AssertionError(f"config delete mismatch: {delete_outputs!r}")

        index_outputs = []
        for base in (parity.LARAVEL, parity.GO):
            parity.db(f"INSERT INTO sheets_configs(destination_id,name,tab,dataset,columns,filters,created_at,updated_at) SELECT {destination},'Base','{seed_tab}','sales','[{{\"field\":\"name\",\"label\":\"Nama\"}}]',NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) WHERE NOT EXISTS(SELECT 1 FROM sheets_configs WHERE destination_id={destination} AND tab='{seed_tab}')")
            client=parity.Client(base)
            if client.login("agen@imp010.test")!=200: raise AssertionError(f"Agent login failed for {base}")
            index_outputs.append(normalize(call(client,"GET","/api/v1/google-sheets")))
        if index_outputs[0]!=index_outputs[1]: raise AssertionError("dashboard config JSON casting mismatch")
        print("Google Sheets config differential: create/update/delete, nested destination serialization, JSON casts, Admin commission restriction, and testing array-cache mutation passed, 0 failed")
    finally:
        parity.db(f"DELETE FROM sheets_configs WHERE destination_id={destination}")
        parity.db(f"DELETE FROM sheets_destinations WHERE id={destination}")
        parity.db(f"DELETE FROM sheets_configs WHERE destination_id={global_destination}")
        parity.db(f"DELETE FROM sheets_destinations WHERE id={global_destination}")


if __name__ == "__main__": main()
