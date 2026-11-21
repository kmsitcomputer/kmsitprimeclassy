#!/usr/bin/env python3
"""GLM differential harness — PROFILE VERIFICATION (4 Laravel-only routes).

  POST /profile/email/verification                      issue email code
  POST /profile/email/verification/{challenge}/confirm  confirm (mark verified)
  POST /profile/whatsapp/verification                   issue WA code (channel gate)
  POST /profile/whatsapp/verification/{challenge}/confirm

Email codes are brute-forced from the HMAC (shared APP_KEY). The WA gateway is not
configured in the isolated env, so those routes stop at the 409 channel gate.

Usage: python3 tools/parity/compare_profile_verification.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_users import SUB
from compare_recovery import APP_KEY, recover_code, scrub, TS


def seed():
    db("DELETE FROM identity_otp_challenges WHERE purpose IN ('email_verification','whatsapp_number')")
    db("DELETE FROM activity_logs WHERE event IN ('auth.email_verified','whatsapp.number.verified')")
    db("UPDATE users SET email_verified_at=NULL WHERE email='sub@imp010.test'")


def scenario(base):
    out = {}
    c = f.client_for(base, SUB)

    out["show_before"] = c.call("GET", "/api/v1/profile/email/verification")
    # request: issue
    out["email_request"] = c.call("POST", "/api/v1/profile/email/verification")
    body = out["email_request"][1]
    ch_id = body["data"]["challenge_id"] if body.get("data") else None
    uid = int(db("SELECT id FROM users WHERE email='sub@imp010.test'")[0][0])
    if ch_id is not None:
        ch_id = int(ch_id)
        ch = db("SELECT code_hash FROM identity_otp_challenges WHERE id=%d" % ch_id)[0]
        code = recover_code(ch_id, uid, ch[0])
        # validation / wrong code / replay semantics
        out["confirm_validation"] = c.call("POST", "/api/v1/profile/email/verification/%d/confirm" % ch_id, {"code": "12345"})
        wrong = "000000" if code != "000000" else "000001"
        out["confirm_wrong"] = c.call("POST", "/api/v1/profile/email/verification/%d/confirm" % ch_id, {"code": wrong})
        out["attempts_after_wrong"] = db("SELECT attempts FROM identity_otp_challenges WHERE id=%d" % ch_id)
        out["confirm_missing_challenge"] = c.call("POST", "/api/v1/profile/email/verification/999999/confirm", {"code": "123456"})
        out["confirm_ok"] = c.call("POST", "/api/v1/profile/email/verification/%d/confirm" % ch_id, {"code": code})
        out["show_after"] = c.call("GET", "/api/v1/profile/email/verification")
        out["verified_at_set"] = db("SELECT email_verified_at IS NOT NULL FROM users WHERE id=%d" % uid)
        out["challenge_consumed"] = db("SELECT consumed_at IS NOT NULL FROM identity_otp_challenges WHERE id=%d" % ch_id)
        out["audit"] = db("SELECT event FROM activity_logs WHERE event='auth.email_verified' ORDER BY id DESC LIMIT 1")
        out["confirm_replay"] = c.call("POST", "/api/v1/profile/email/verification/%d/confirm" % ch_id, {"code": code})
    # request again after verified → already verified answer
    out["request_after_verified"] = c.call("POST", "/api/v1/profile/email/verification")

    # WhatsApp: channel gate (gateway not configured in the isolated env)
    out["wa_request"] = c.call("POST", "/api/v1/profile/whatsapp/verification", {})
    out["wa_request_new_number"] = c.call("POST", "/api/v1/profile/whatsapp/verification", {"phone": "081234567890"})
    out["wa_request_invalid_number"] = c.call("POST", "/api/v1/profile/whatsapp/verification", {"phone": "123"})
    out["wa_confirm_missing"] = c.call("POST", "/api/v1/profile/whatsapp/verification/999999/confirm", {"code": "123456"})
    return out


def scrub_ids(v):
    """challenge_id is a sequence value that legitimately differs between runtimes."""
    if isinstance(v, dict):
        return {k: ("<id>" if k == "challenge_id" else scrub_ids(x)) for k, x in v.items()}
    if isinstance(v, list):
        return [scrub_ids(x) for x in v]
    return v


def main():
    seed()
    lar = scenario(LARAVEL)
    seed()
    go = scenario(GO)
    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        lv, gv = scrub_ids(scrub(norm_body(lar[key]))), scrub_ids(scrub(norm_body(go[key])))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv, default=str)[:500])
            print("  G=" + json.dumps(gv, default=str)[:500])
    print(f"profile_verification: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
