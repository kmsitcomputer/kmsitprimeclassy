#!/usr/bin/env python3
"""Laravel <-> Go differential parity for GET /api/v1/profile/email/verification."""
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, SALES, SUPER, KONSUMEN, check, client_for, db, diff, norm_body, seed_full


def state():
    return db("SELECT id,agent_id,subject_user_id,purpose,expires_at,verified_at,consumed_at,invalidated_at FROM identity_otp_challenges ORDER BY id")


def clients(base):
    return {email: client_for(base, email) for email in (AGEN, ADMIN, SALES, SUPER, KONSUMEN)}


def requests(cs):
    return [cs[email].call("GET", "/api/v1/profile/email/verification")
            for email in (AGEN, ADMIN, SALES, SUPER, KONSUMEN)]


def main():
    seed_full()
    lc, gc = clients(LARAVEL), clients(GO)
    base_id = int(db("SELECT COALESCE(MAX(id),0)+1000 FROM identity_otp_challenges")[0][0])
    try:
        db(f"INSERT INTO identity_otp_challenges (id,agent_id,subject_user_id,created_by,purpose,code_hash,sent_to_email,attempts,max_attempts,expires_at,created_at,updated_at) VALUES ({base_id},10,10,10,'email_verification','{'0'*64}','agen@imp010.test',0,5,UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        db(f"INSERT INTO identity_otp_challenges (id,agent_id,subject_user_id,created_by,purpose,code_hash,sent_to_email,attempts,max_attempts,expires_at,created_at,updated_at) VALUES ({base_id+1},10,11,10,'email_verification','{'1'*64}','admin@imp010.test',0,5,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        before = state()
        left = requests(lc)
        middle = state()
        right = requests(gc)
        after = state()
        failures = []
        sl, sg = [r[0] for r in left], [r[0] for r in right]
        if sl != sg:
            failures.append(f"statuses {sl} vs {sg}")
        else:
            for i, (lr, gr) in enumerate(zip(left, right), 1):
                failures.extend(f"case {i}: {v}" for v in diff(norm_body(lr[1]), norm_body(gr[1]))[:5])
        if before != middle or middle != after:
            failures.append(f"read-only OTP DB state changed: before={before} Laravel={middle} Go={after}")
        if failures:
            check("GET email verification state (mask + pending + RBAC + DB)", False, "; ".join(failures[:16]))
        else:
            check("GET email verification state (mask + pending + RBAC + DB)", True)
    finally:
        db(f"DELETE FROM identity_otp_challenges WHERE id IN ({base_id},{base_id+1})")
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
