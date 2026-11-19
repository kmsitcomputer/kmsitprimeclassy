#!/usr/bin/env python3
"""Laravel <-> Go differential parity for PATCH /api/v1/profile."""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, ADMIN, KONSUMEN, check, client_for, db, diff, norm_body, seed_full

USER_ID = 10
CASES = [
    ("normalize phone + ignore protected fields", AGEN, {"name": "  Profile Parity  ", "phone": "+62 812-3456-7890", "role": "super_admin", "agent_id": 11, "parent_id": 999}),
    ("changed email invalidates verification", AGEN, {"email": "profile-parity@example.test"}),
    ("email case-only update preserves verification", AGEN, {"email": "AGEN@IMP010.TEST"}),
    ("accumulated invalid fields", AGEN, {"name": [], "phone": "1" * 21, "email": "bad", "avatar_media_id": 99999999}),
    ("duplicate email and phone", AGEN, {"email": KONSUMEN, "phone": "081100000011"}),
    ("clear avatar", AGEN, {"avatar_media_id": None}),
    ("admin self-update", ADMIN, {"name": "Admin Profile Parity"}),
    ("consumer self-update", KONSUMEN, {"name": "Consumer Profile Parity"}),
]


def quote(v):
    if v is None or v == "NULL":
        return "NULL"
    return "'" + str(v).replace("'", "''") + "'"


def read_user(user_id):
    rows = db(f"SELECT name,email,phone,avatar_media_id,email_verified_at,agent_id,parent_id,korsal_id,sales_id,status,updated_at FROM users WHERE id={user_id}")
    return rows[0]


def restore_user(user_id, baseline):
    name,email,phone,avatar,verified,agent,parent,korsal,sales,status,updated_at = baseline
    db(f"UPDATE users SET name={quote(name)},email={quote(email)},phone={quote(phone)},avatar_media_id={quote(avatar)},email_verified_at={quote(verified)},agent_id={quote(agent)},parent_id={quote(parent)},korsal_id={quote(korsal)},sales_id={quote(sales)},status={quote(status)},updated_at={quote(updated_at)} WHERE id={user_id}")


def state(user_id, max_audit):
    profile = db(f"SELECT name,email,phone,avatar_media_id,email_verified_at,agent_id,parent_id,korsal_id,sales_id,status FROM users WHERE id={user_id}")
    audits = db(f"SELECT causer_id,subject_type,subject_id,event,properties,IFNULL(ip_address,'<NULL>'),IFNULL(user_agent,'<NULL>') FROM activity_logs WHERE id>{max_audit} AND event='profile.updated' AND subject_id={user_id} ORDER BY id")
    decoded = [[*row[:4], json.loads(row[4]) if row[4] not in ("", "NULL") else None, *row[5:]] for row in audits]
    return {"user": profile, "audit": decoded}


def run_case(label, email, client, body, baseline):
    uid = {AGEN: 10, ADMIN: 11, KONSUMEN: 13}[email]
    restore_user(uid, baseline)
    if label in ("changed email invalidates verification", "email case-only update preserves verification"):
        db(f"UPDATE users SET email_verified_at='2025-01-02 03:04:05' WHERE id={uid}")
    max_audit = int(db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
    response = client.call("PATCH", "/api/v1/profile", body)
    return response, state(uid, max_audit)


def main():
    seed_full()
    clients = {tag: {email: client_for(base, email) for email in (AGEN, ADMIN, KONSUMEN)}
               for tag, base in (("L", LARAVEL), ("G", GO))}
    ids = {AGEN: 10, ADMIN: 11, KONSUMEN: 13}
    baselines = {email: read_user(uid) for email, uid in ids.items()}
    failures = []
    try:
        for label, email, body in CASES:
            left, state_l = run_case(label, email, clients["L"][email], body, baselines[email])
            right, state_g = run_case(label, email, clients["G"][email], body, baselines[email])
            if left[0] != right[0]:
                failures.append(f"{label}: status {left[0]} vs {right[0]}")
                continue
            failures.extend(f"{label}: response {v}" for v in diff(norm_body(left[1]), norm_body(right[1]))[:6])
            failures.extend(f"{label}: DB/audit {v}" for v in diff(state_l, state_g)[:6])
    finally:
        for email, uid in ids.items():
            restore_user(uid, baselines[email])
    if failures:
        check("PATCH profile (validation + self-scope + identity + audit)", False, "; ".join(failures[:24]))
    else:
        check("PATCH profile (validation + self-scope + identity + audit)", True)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
