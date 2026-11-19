#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the USER MANAGEMENT write surface.

    POST   /api/v1/users
    PATCH  /api/v1/users/{user}
    DELETE /api/v1/users/{user}
    PATCH  /api/v1/users/{user}/reassign-referral
    PATCH  /api/v1/users/{user}/convert-to-sales-kurir-sub[|-legacy]

Each scenario runs on BOTH runtimes from identical reset state and compares HTTP status, the response
body (ids/timestamps normalised) and the resulting DATABASE rows (users, user_closures, couriers,
activity_logs). Response-body comparison is shape-normalised: generated referral codes, timestamps and
ids differ by construction.
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, SUPER, AGEN, KONSUMEN, check, client_for, db, diff,
                                 norm_body, reset, rows, seed_full)

ADMIN = "admin@imp010.test"
KEUANGAN = "keuangan@imp010.test"
KORSAL = "korsal@imp010.test"
SALES = "sales@imp010.test"
KURIR = "kurir@imp010.test"
COORD = "koordinator@imp010.test"
SUB = "sub@imp010.test"

CODE_RE = re.compile(r"^(AG|KO|SA|SS)-[A-Z0-9]{6}$")


def scrub(value):
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            if k in ("referral_code",) and isinstance(v, str) and CODE_RE.match(v):
                out[k] = "<code>"
            else:
                out[k] = scrub(v)
        return out
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return value


STATE = {
    "users": """SELECT r.slug, u.status, u.agent_id IS NOT NULL, u.agent_id = (SELECT MIN(id) FROM users),
            u.parent_id IS NOT NULL, u.korsal_id IS NOT NULL, u.sales_id IS NOT NULL,
            u.referral_code LIKE '%-%', u.deleted_at IS NOT NULL
        FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.id""",
    "closures": """SELECT COUNT(*) FROM user_closures""",
    "couriers": """SELECT type, agent_id IS NOT NULL, is_active FROM couriers ORDER BY id""",
    "activity": """SELECT event, subject_type, IFNULL(properties, '') FROM activity_logs
        WHERE event LIKE 'user.%' OR event LIKE 'referral.%' ORDER BY id""",
}


def state():
    out = {}
    for name, sql in STATE.items():
        out[name] = rows(sql)
    return out


def seed_korsal():
    """The fixture set has no korsal account; insert ONE identically for both runtimes after reset()."""
    role = db("SELECT id FROM roles WHERE slug = 'korsal'")[0][0]
    db(f"""INSERT INTO users (id, role_id, parent_id, agent_id, korsal_id, sales_id, name, email, phone,
            password, referral_code, status, created_at, updated_at)
        SELECT 20, {role}, 10, 10, NULL, NULL, 'Korsal Satu', 'korsal@imp010.test', '081100000020',
            (SELECT password FROM users WHERE email = 'agen@imp010.test'), 'KO-IMP020', 'active', NOW(), NOW()
        WHERE NOT EXISTS (SELECT 1 FROM users WHERE id = 20)
          ON DUPLICATE KEY UPDATE role_id = {role}, deleted_at = NULL""")
    db("UPDATE users SET referral_code = 'KO-IMP020' WHERE id = 20")
    db("INSERT INTO user_closures (ancestor_id, descendant_id, depth) VALUES (20, 20, 0), (10, 20, 1), (20, 10, 2) "
       "ON DUPLICATE KEY UPDATE depth = VALUES(depth)")


def purge_test_users():
    # BOTH runtimes share ONE database; every scenario-created account (emails under @x.test) must be
    # removed between the two runs or the second run collides with the first's unique email/phone.
    db("DELETE FROM couriers WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@x.test')")
    db("DELETE FROM user_closures WHERE descendant_id IN (SELECT id FROM users WHERE email LIKE '%@x.test')")
    db("DELETE FROM user_closures WHERE ancestor_id IN (SELECT id FROM users WHERE email LIKE '%@x.test')")
    db("DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@x.test')")
    db("DELETE FROM activity_logs WHERE subject_type = 'App\\\\Models\\\\User' AND subject_id IN "
       "(SELECT id FROM users WHERE email LIKE '%@x.test')")
    db("DELETE FROM users WHERE email LIKE '%@x.test'")


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        seed_korsal()
        purge_test_users()
        out[label] = (run(base), state())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    st_l, bodies_l = [p[0] for p in pairs_l], [p[1] for p in pairs_l]
    st_g, bodies_g = [p[0] for p in pairs_g], [p[1] for p in pairs_g]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(bodies_l)[:300]} || {json.dumps(bodies_g)[:300]}")
        return
    for i, (bl, bg) in enumerate(zip(bodies_l, bodies_g)):
        diffs = diff(norm_body(scrub(bl)), norm_body(scrub(bg)))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:6]))
            return
    diffs = diff(db_l, db_g)
    if diffs:
        check(f"{name} [db]", False, "; ".join(diffs[:6]))
        return
    check(name, True)


def last_user(base):
    return db("SELECT id FROM users ORDER BY id DESC LIMIT 1")[0][0]


def main():
    seed_full()

    # --- 1. agen creates a sales under its own korsal -------------------------------------------
    def agen_creates_sales(base):
        c = client_for(base, AGEN)
        out = [c.call("POST", "/api/v1/users", {"role": "sales", "name": "Sales Baru", "email": "salesbaru@x.test",
                                                "phone": "081200000001", "password": "Secret123!",
                                                "password_confirmation": "Secret123!", "korsal_id": 15})]
        return out

    scenario("agen creates a sales under its korsal", agen_creates_sales)

    # --- 2. validation matrix --------------------------------------------------------------------
    def store_validation(base):
        c = client_for(base, AGEN)
        base_body = {"role": "sales", "name": "X", "email": "v1@x.test", "phone": "081200000002",
                     "password": "Secret123!", "password_confirmation": "Secret123!", "korsal_id": 15}
        out = [c.call("POST", "/api/v1/users", {})]
        out.append(c.call("POST", "/api/v1/users", {**base_body, "name": ""}))
        out.append(c.call("POST", "/api/v1/users", {**base_body, "email": "bukan-email"}))
        out.append(c.call("POST", "/api/v1/users", {**base_body, "phone": "081200000003", "password": "short"}))
        out.append(c.call("POST", "/api/v1/users", {**base_body, "phone": "081200000004", "password": "Secret123!",
                                                    "password_confirmation": "beda"}))
        out.append(c.call("POST", "/api/v1/users", {**base_body, "email": "sales@imp010.test", "phone": "081200000005"}))
        out.append(c.call("POST", "/api/v1/users", {**base_body, "phone": "081100000014", "email": "v2@x.test"}))
        out.append(c.call("POST", "/api/v1/users", {**base_body, "role": "super_admin", "email": "v3@x.test",
                                                    "phone": "081200000006"}))
        return out

    scenario("store validation matrix", store_validation)

    # --- 3. RBAC creation matrix ------------------------------------------------------------------
    def store_rbac(base):
        out = [client_for(base, AGEN).call("POST", "/api/v1/users", {"role": "agen", "name": "A", "email": "a1@x.test",
                                                                     "phone": "081200000007", "password": "Secret123!",
                                                                     "password_confirmation": "Secret123!"})]
        out.append(client_for(base, KONSUMEN).call("POST", "/api/v1/users", {"role": "sales", "name": "B",
                                                                             "email": "b1@x.test", "phone": "081200000008",
                                                                             "password": "Secret123!",
                                                                             "password_confirmation": "Secret123!"}))
        out.append(client_for(base, ADMIN).call("POST", "/api/v1/users", {"role": "sales", "name": "C",
                                                                          "email": "c1@x.test", "phone": "081200000009",
                                                                          "password": "Secret123!",
                                                                          "password_confirmation": "Secret123!"}))
        out.append(client_for(base, SALES).call("POST", "/api/v1/users", {"role": "sales", "name": "D",
                                                                          "email": "d1@x.test", "phone": "081200000010",
                                                                          "password": "Secret123!",
                                                                          "password_confirmation": "Secret123!"}))
        # korsal creates its own sales (korsal_id pinned to self)
        out.append(client_for(base, KORSAL).call("POST", "/api/v1/users", {"role": "sales", "name": "E",
                                                                           "email": "e1@x.test", "phone": "081200000011",
                                                                           "password": "Secret123!",
                                                                           "password_confirmation": "Secret123!",
                                                                           "korsal_id": 15}))
        return out

    scenario("store RBAC matrix", store_rbac)

    # --- 4. super_admin creates an agen ------------------------------------------------------------
    def super_creates_agen(base):
        c = client_for(base, SUPER)
        return [c.call("POST", "/api/v1/users", {"role": "agen", "name": "Agen Baru", "email": "agenbaru@x.test",
                                                 "phone": "081200000012", "password": "Secret123!",
                                                 "password_confirmation": "Secret123!"})]

    scenario("super_admin creates an agen", super_creates_agen)

    # --- 5. koordinator-kurir creates kurir under itself ---------------------------------------------
    def coord_creates_kurir(base):
        c = client_for(base, COORD)
        return [c.call("POST", "/api/v1/users", {"role": "kurir", "name": "Kurir Dua", "email": "kurir2@x.test",
                                                 "phone": "081200000013", "password": "Secret123!",
                                                 "password_confirmation": "Secret123!"}),
                c.call("POST", "/api/v1/users", {"role": "admin", "name": "N", "email": "n1@x.test",
                                                 "phone": "081200000014", "password": "Secret123!",
                                                 "password_confirmation": "Secret123!"})]

    scenario("koordinator creates kurir (+ admin refused)", coord_creates_kurir)

    # --- 6. update ------------------------------------------------------------------------------------
    def update_matrix(base):
        oid = make_user(base, AGEN)
        c = client_for(base, AGEN)
        out = [c.call("PATCH", f"/api/v1/users/{oid}", {"name": "Nama Ganti"}),
               c.call("PATCH", f"/api/v1/users/{oid}", {"status": "suspended"}),
               c.call("PATCH", f"/api/v1/users/{oid}", {"status": "hilang"}),
               c.call("PATCH", f"/api/v1/users/{oid}", {}),
               c.call("PATCH", "/api/v1/users/999999", {"name": "Tak Ada"}),
               client_for(base, SALES).call("PATCH", f"/api/v1/users/{oid}", {"name": "Dilarang"}),
               client_for(base, KEUANGAN).call("PATCH", f"/api/v1/users/{oid}", {"name": "Dilarang"})]
        return out

    scenario("update matrix (RBAC + validation)", update_matrix)

    # --- 7. delete --------------------------------------------------------------------------------------
    def delete_matrix(base):
        oid = make_user(base, AGEN)
        out = [client_for(base, SALES).call("DELETE", f"/api/v1/users/{oid}"),
               client_for(base, SUPER).call("DELETE", f"/api/v1/users/1"),
               client_for(base, AGEN).call("DELETE", f"/api/v1/users/{oid}"),
               client_for(base, AGEN).call("DELETE", f"/api/v1/users/{oid}"),
               client_for(base, AGEN).call("DELETE", "/api/v1/users/999999")]
        return out

    scenario("delete matrix (RBAC + soft delete + replay)", delete_matrix)

    # --- 8. reassign referral ----------------------------------------------------------------------------
    def reassign_matrix(base):
        out = [client_for(base, SUPER).call("PATCH", "/api/v1/users/14/reassign-referral", {"korsal_id": 15}),
               client_for(base, SUPER).call("PATCH", "/api/v1/users/14/reassign-referral", {}),
               client_for(base, SUPER).call("PATCH", "/api/v1/users/14/reassign-referral", {"sales_id": 999999}),
               client_for(base, KONSUMEN).call("PATCH", "/api/v1/users/13/reassign-referral", {"sales_id": 14})]
        return out

    scenario("reassign referral matrix", reassign_matrix)

    # --- 9. convert sales -> sales-kurir-sub ---------------------------------------------------------------
    def convert_matrix(base):
        out = [client_for(base, AGEN).call("PATCH", "/api/v1/users/14/convert-to-sales-kurir-sub", {}),
               client_for(base, AGEN).call("PATCH", "/api/v1/users/14/convert-to-sales-kurir-sub", {}),
               client_for(base, KONSUMEN).call("PATCH", "/api/v1/users/13/convert-to-sales-kurir-sub", {}),
               client_for(base, AGEN).call("PATCH", "/api/v1/users/13/convert-to-sales-kurir", {})]
        return out

    scenario("convert sales -> sales-kurir-sub (+ replay, legacy alias)", convert_matrix)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


def make_user(base, who):
    c = client_for(base, who)
    import hashlib
    tag = hashlib.md5(who.encode()).hexdigest()[:6]
    body = {"role": "sales", "name": "Sementara", "email": f"tmp.{tag}@x.test",
            "phone": f"0812{int(tag, 16) % 100000000:08d}", "password": "Secret123!", "password_confirmation": "Secret123!",
            "korsal_id": 20}
    st, resp = c.call("POST", "/api/v1/users", body)
    if st != 201:
        raise SystemExit(f"fixture user creation failed on {base}: {st} {json.dumps(resp)[:200]}")
    return last_user(base)


if __name__ == "__main__":
    sys.exit(main())
