#!/usr/bin/env python3
"""Differential-check Google Sheets destination creation, normalization, and scope."""
import importlib.util
import json
import pathlib
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("parity", ROOT / "tools/parity/compare_return_review.py")
parity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(parity)


def normalize(response):
    code, body = response
    data = body.get("data") if isinstance(body, dict) else None
    if isinstance(data, dict):
        data["id"] = "ID"
        data["created_at"] = "TIME"
        data["updated_at"] = "TIME"
    return code, body


def main():
    if parity.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    agent_id = int(parity.db("SELECT id FROM users WHERE email='agen@imp010.test'")[0][0])
    for actor_email, payload in (
        ("super@imp010.test", {"spreadsheet_id": "https://docs.google.com/spreadsheets/d/pc-destination-{}/edit?usp=sharing", "agent_id": agent_id}),
        ("agen@imp010.test", {"spreadsheet_id": "pc-agent-destination-{}", "agent_id": agent_id}),
    ):
        token = uuid.uuid4().hex[:12]
        raw = {key: (value.format(token) if isinstance(value, str) else value) for key, value in payload.items()}
        outputs = []
        states = []
        for base in (parity.LARAVEL, parity.GO):
            client = parity.Client(base)
            if client.login(actor_email) != 200:
                raise AssertionError(f"fixture login failed for {actor_email} on {base}")
            response = client.call("POST", "/api/v1/google-sheets/destinations", json.dumps(raw).encode(), {"Content-Type": "application/json"})
            outputs.append(normalize(response))
            spreadsheet = raw["spreadsheet_id"].split("/d/")[-1].split("/")[0]
            state = parity.db("SELECT spreadsheet_id,agent_id,connection_status FROM sheets_destinations WHERE spreadsheet_id='" + spreadsheet.replace("'", "''") + "'")
            states.append(state)
            parity.db("DELETE FROM sheets_destinations WHERE spreadsheet_id='" + spreadsheet.replace("'", "''") + "'")
        if outputs[0] != outputs[1] or states[0] != states[1]:
            raise AssertionError(f"destination mismatch for {actor_email}: Laravel={outputs[0]!r}/{states[0]!r} Go={outputs[1]!r}/{states[1]!r}")
        if outputs[0][0] != 201 or states[0][0][1] != str(agent_id):
            raise AssertionError(f"unexpected creation/scope: {outputs[0]!r} {states[0]!r}")
    invalid_cases = ({}, {"spreadsheet_id": "bad id"}, {"spreadsheet_id": "x" * 151})
    clients = []
    for base in (parity.LARAVEL, parity.GO):
        client = parity.Client(base)
        if client.login("super@imp010.test") != 200:
            raise AssertionError(f"Super Admin fixture login failed for {base}")
        clients.append(client)
    for payload in invalid_cases:
        outputs = [client.call("POST", "/api/v1/google-sheets/destinations", json.dumps(payload).encode(), {"Content-Type": "application/json"}) for client in clients]
        if outputs[0] != outputs[1] or outputs[0][0] != 422:
            raise AssertionError(f"destination validation mismatch: Laravel={outputs[0]!r} Go={outputs[1]!r}")
    numeric_outputs = []
    for client in clients:
        numeric_outputs.append(normalize(client.call("POST", "/api/v1/google-sheets/destinations", b'{"spreadsheet_id":123}', {"Content-Type": "application/json"})))
        parity.db("DELETE FROM sheets_destinations WHERE spreadsheet_id='123'")
    if numeric_outputs[0] != numeric_outputs[1] or numeric_outputs[0][0] != 201:
        raise AssertionError(f"Laravel string coercion mismatch for numeric spreadsheet_id: {numeric_outputs!r}")
    print("Google Sheets destination differential: URL normalization, uniqueness/format validation, scalar coercion, Super Admin assignment, Agent scope, response and persisted rows passed, 0 failed")


if __name__ == "__main__":
    main()
