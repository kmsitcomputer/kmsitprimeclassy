#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the profile referral-code routes."""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, SALES, KONSUMEN, check, client_for,
                                 db, diff, norm_body, reset, rows, seed_full)

CODE_RE = re.compile(r"^(AG|KO|SA|SS)-[A-Z0-9]{6}$")


def scrub(value):
    if isinstance(value, dict):
        return {k: ("<generated>" if k == "referral_code" and isinstance(v, str) and CODE_RE.match(v)
                    else scrub(v)) for k, v in value.items()}
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return value


def state():
    return {
        "users": rows("SELECT id,CASE WHEN referral_code REGEXP '^(AG|KO|SA|SS)-[A-Z0-9]{6}$' "
                       "THEN '<generated>' ELSE IFNULL(referral_code,'<NULL>') END "
                       "FROM users WHERE id IN (10,14) ORDER BY id"),
        "audit": rows("SELECT causer_id,subject_type,subject_id,event, "
                      "CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(properties,'$.old')) REGEXP '^(AG|KO|SA|SS)-[A-Z0-9]{6}$' "
                      "THEN '<generated>' ELSE IFNULL(JSON_UNQUOTE(JSON_EXTRACT(properties,'$.old')),'<NULL>') END, "
                      "CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(properties,'$.new')) REGEXP '^(AG|KO|SA|SS)-[A-Z0-9]{6}$' "
                      "THEN '<generated>' ELSE IFNULL(JSON_UNQUOTE(JSON_EXTRACT(properties,'$.new')),'<NULL>') END "
                      "FROM activity_logs WHERE event LIKE 'profile.referral_code_%' ORDER BY id"),
    }


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        db("UPDATE users SET referral_code=CASE id WHEN 10 THEN 'AG-BASE10' WHEN 14 THEN 'SA-BASE14' END "
           "WHERE id IN (10,14)")
        out[label] = (run(base), state())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    if [p[0] for p in pairs_l] != [p[0] for p in pairs_g]:
        check(name, False, f"statuses {[p[0] for p in pairs_l]} vs {[p[0] for p in pairs_g]}")
        return
    for i, (left, right) in enumerate(zip(pairs_l, pairs_g)):
        changes = diff(norm_body(scrub(left[1])), norm_body(scrub(right[1])))
        if changes:
            check(f"{name} [response {i+1}]", False, "; ".join(changes[:8]))
            return
    changes = diff(db_l, db_g)
    if changes:
        check(f"{name} [db]", False, "; ".join(changes[:8]))
        return
    check(name, True)


def main():
    seed_full()

    def update_matrix(base):
        c = client_for(base, AGEN)
        return [
            c.call("PATCH", "/api/v1/profile/referral-code", {}),
            c.call("PATCH", "/api/v1/profile/referral-code", {"referral_code": "lower-case"}),
            c.call("PATCH", "/api/v1/profile/referral-code", {"referral_code": "A" * 31}),
            c.call("PATCH", "/api/v1/profile/referral-code", {"referral_code": "SA-BASE14"}),
            c.call("PATCH", "/api/v1/profile/referral-code", {"referral_code": "AG-PARITY1"}),
            client_for(base, KONSUMEN).call("PATCH", "/api/v1/profile/referral-code", {"referral_code": "AG-DENIED"}),
        ]

    scenario("profile referral-code update (validation + unique + RBAC)", update_matrix)

    def regenerate(base):
        return [client_for(base, SALES).call("POST", "/api/v1/profile/referral-code/regenerate", {})]

    scenario("profile referral-code regenerate (random code + audit)", regenerate)

    def delete(base):
        return [client_for(base, AGEN).call("DELETE", "/api/v1/profile/referral-code")]

    scenario("profile referral-code delete (response + audit)", delete)

    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
