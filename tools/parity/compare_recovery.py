#!/usr/bin/env python3
"""GLM differential harness — ACCOUNT RECOVERY (2 Laravel-only routes).

  POST /auth/forgot-password  enumeration-resistant code issuance
  POST /auth/reset-password   one code = one reset + session revocation

The code is never returned; the harness brute-forces the 6-digit HMAC (same APP_KEY
in both runtimes) to exercise the verify/reset path.

Usage: python3 tools/parity/compare_recovery.py
"""
import hashlib
import hmac as hmac_mod
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body

APP_KEY = "base64:zm5WTO0eNS5nc+bh5npzKXxjYGHHHSX5pD8bwynKImY="

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: scrub(x) for k, x in v.items()}
    return v


def recover_code(challenge_id, subject_id, code_hash):
    for n in range(1000000):
        code = "%06d" % n
        msg = f"{challenge_id}|{subject_id}|password_recovery|{code}".encode()
        if hmac_mod.new(APP_KEY.encode(), msg, hashlib.sha256).hexdigest() == code_hash:
            return code
    return None


def seed_recovery():
    db("DELETE FROM identity_otp_challenges WHERE purpose='password_recovery'")
    db("DELETE FROM activity_logs WHERE event='auth.password_reset'")
    db("UPDATE users SET email_verified_at=NOW(), password='x' WHERE email='konsumen@imp010.test'")
    db("DELETE FROM pc_go_sessions WHERE user_id=(SELECT id FROM users WHERE email='konsumen@imp010.test')")


def scenario(base):
    out = {}
    c = f.Client(base)
    c.call("GET", "/sanctum/csrf-cookie")

    # validation
    out["forgot_missing"] = c.call("POST", "/api/v1/auth/forgot-password", {})
    out["forgot_bad_channel"] = c.call("POST", "/api/v1/auth/forgot-password",
                                       {"identifier": "konsumen@imp010.test", "channel": "sms"})
    # enumeration resistance — unknown account answer identical
    out["forgot_unknown"] = c.call("POST", "/api/v1/auth/forgot-password",
                                   {"identifier": "nobody@imp010.test"})
    out["forgot_known"] = c.call("POST", "/api/v1/auth/forgot-password",
                                 {"identifier": "konsumen@imp010.test"})
    uid = int(db("SELECT id FROM users WHERE email='konsumen@imp010.test'")[0][0])
    ch = db("SELECT id, code_hash FROM identity_otp_challenges WHERE purpose='password_recovery' AND subject_user_id=%d ORDER BY id DESC LIMIT 1" % uid)[0]
    code = recover_code(int(ch[0]), uid, ch[1])

    # reset validation
    out["reset_missing"] = c.call("POST", "/api/v1/auth/reset-password", {})
    out["reset_short_password"] = c.call("POST", "/api/v1/auth/reset-password",
                                         {"identifier": "konsumen@imp010.test", "code": code,
                                          "password": "short", "password_confirmation": "short"})
    # wrong code → invalid + attempt persisted
    wrong = "000000" if code != "000000" else "000001"
    out["reset_wrong_code"] = c.call("POST", "/api/v1/auth/reset-password",
                                     {"identifier": "konsumen@imp010.test", "code": wrong,
                                      "password": "Secret123!", "password_confirmation": "Secret123!"})
    out["attempts_after_wrong"] = db("SELECT attempts FROM identity_otp_challenges WHERE id=%s" % ch[0])
    # unknown identifier → same generic invalid
    out["reset_unknown"] = c.call("POST", "/api/v1/auth/reset-password",
                                  {"identifier": "nobody@imp010.test", "code": code,
                                   "password": "Secret123!", "password_confirmation": "Secret123!"})
    # correct code → success + password changed + all challenges consumed + audit
    out["reset_ok"] = c.call("POST", "/api/v1/auth/reset-password",
                             {"identifier": "konsumen@imp010.test", "code": code,
                              "password": "Secret123!", "password_confirmation": "Secret123!"})
    out["challenges_consumed"] = db("SELECT COUNT(*) FROM identity_otp_challenges WHERE purpose='password_recovery' AND subject_user_id=%d AND consumed_at IS NULL" % uid)
    out["password_changed"] = db("SELECT password <> 'x' FROM users WHERE id=%d" % uid)
    out["audit"] = db("SELECT event FROM activity_logs WHERE event='auth.password_reset' ORDER BY id DESC LIMIT 1")
    # replay with the same code → invalid (single use)
    out["reset_replay"] = c.call("POST", "/api/v1/auth/reset-password",
                                 {"identifier": "konsumen@imp010.test", "code": code,
                                  "password": "Secret123!", "password_confirmation": "Secret123!"})
    # old sessions revoked
    out["sessions_revoked"] = db("SELECT COUNT(*) FROM pc_go_sessions WHERE user_id=%d" % uid)
    return out


def main():
    seed_recovery()
    lar = scenario(LARAVEL)
    seed_recovery()
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
    print(f"recovery: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
