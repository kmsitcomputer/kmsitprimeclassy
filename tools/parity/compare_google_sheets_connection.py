#!/usr/bin/env python3
"""Differential-check Google Sheets credential failure, scope, and persisted status."""
import importlib.util
import json
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("parity", ROOT / "tools/parity/compare_return_review.py")
parity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(parity)


def main():
    if parity.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    seed = parity.Client(parity.LARAVEL)
    if seed.login("super@imp010.test") != 200:
        raise AssertionError("Super Admin fixture login failed")
    dashboard = seed.call("GET", "/api/v1/google-sheets")
    if dashboard[1]["data"]["service_account_email"] is not None:
        raise RuntimeError("credential is configured; refusing to call external Google APIs from this differential")
    agent_id = int(parity.db("SELECT id FROM users WHERE email='agen@imp010.test'")[0][0])
    parity.db("INSERT INTO sheets_destinations(spreadsheet_id,agent_id,created_at,updated_at) VALUES('pc-sheets-connection-differential',%d,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" % agent_id)
    destination_id = int(parity.db("SELECT id FROM sheets_destinations WHERE spreadsheet_id='pc-sheets-connection-differential'")[0][0])
    try:
        snapshots = []
        for base in (parity.LARAVEL, parity.GO):
            parity.db(f"UPDATE sheets_destinations SET connection_status='not_checked',last_tested_at=NULL,last_error_code=NULL WHERE id={destination_id}")
            client = parity.Client(base)
            if client.login("agen@imp010.test") != 200:
                raise AssertionError(f"Agent fixture login failed for {base}")
            response = client.call("POST", "/api/v1/google-sheets/connection", json.dumps({"destination_id": destination_id}).encode(), {"Content-Type": "application/json"})
            state = parity.db(f"SELECT connection_status,last_error_code,last_tested_at IS NOT NULL FROM sheets_destinations WHERE id={destination_id}")
            snapshots.append((response, state))
        if snapshots[0] != snapshots[1]:
            raise AssertionError(f"connection response/state mismatch: Laravel={snapshots[0]!r} Go={snapshots[1]!r}")
        response, state = snapshots[0]
        if response[0] != 422 or response[1]["errors"]["code"] != ["CREDENTIAL_NOT_FOUND"] or state != [["failed", "CREDENTIAL_NOT_FOUND", "1"]]:
            raise AssertionError(f"unexpected missing-credential contract: {response!r}, {state!r}")
        print("Google Sheets connection differential: missing-credential response, Agent scope, and persisted error state passed, 0 failed")
    finally:
        parity.db(f"DELETE FROM sheets_destinations WHERE id={destination_id}")


if __name__ == "__main__":
    main()
