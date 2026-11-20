#!/usr/bin/env python3
"""Differential-check Google Sheets dashboard payload and role-scoped reads."""
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
    cases = ("super@imp010.test", "agen@imp010.test", "admin@imp010.test")
    for email in cases:
        outputs = []
        for base in (parity.LARAVEL, parity.GO):
            client = parity.Client(base)
            if client.login(email) != 200:
                raise AssertionError(f"fixture login failed for {email} on {base}")
            outputs.append(client.call("GET", "/api/v1/google-sheets"))
        if outputs[0] != outputs[1]:
            left = json.dumps(outputs[0], sort_keys=True, default=str, ensure_ascii=False)
            right = json.dumps(outputs[1], sort_keys=True, default=str, ensure_ascii=False)
            raise AssertionError(f"Google Sheets dashboard mismatch for {email}: Laravel={left[:1000]} Go={right[:1000]}")
    unauth = parity.Client(parity.LARAVEL).call("GET", "/api/v1/google-sheets")
    no_auth_go = parity.Client(parity.GO).call("GET", "/api/v1/google-sheets")
    if unauth != no_auth_go or unauth[0] != 401:
        raise AssertionError("Google Sheets authentication contract mismatch")
    print("Google Sheets dashboard differential: 3 role-scoped payloads and unauthenticated response passed, 0 failed")


if __name__ == "__main__":
    main()
