#!/usr/bin/env python3
"""Laravel <-> Go differential parity for GET /api/v1/cms/homepage-blocks."""
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, SUPER, AGEN, ADMIN, check, client_for, db, diff, norm_body, seed_full


def state():
    return db("SELECT id,type,content,sort_order,is_active,starts_at,ends_at,updated_at FROM cms_homepage_blocks ORDER BY sort_order")


def run(base):
    super_client = client_for(base, SUPER)
    return [super_client.call("GET", "/api/v1/cms/homepage-blocks"),
            client_for(base, AGEN).call("GET", "/api/v1/cms/homepage-blocks"),
            client_for(base, ADMIN).call("GET", "/api/v1/cms/homepage-blocks")]


def main():
    seed_full()
    lc = {email: client_for(LARAVEL, email) for email in (SUPER, AGEN, ADMIN)}
    gc = {email: client_for(GO, email) for email in (SUPER, AGEN, ADMIN)}
    before = state()
    left = [lc[SUPER].call("GET", "/api/v1/cms/homepage-blocks"),
            lc[AGEN].call("GET", "/api/v1/cms/homepage-blocks"),
            lc[ADMIN].call("GET", "/api/v1/cms/homepage-blocks")]
    middle = state()
    right = [gc[SUPER].call("GET", "/api/v1/cms/homepage-blocks"),
             gc[AGEN].call("GET", "/api/v1/cms/homepage-blocks"),
             gc[ADMIN].call("GET", "/api/v1/cms/homepage-blocks")]
    after = state()
    failures = []
    if [r[0] for r in left] != [r[0] for r in right]:
        failures.append(f"statuses {[r[0] for r in left]} vs {[r[0] for r in right]}")
    else:
        for i, (lr, gr) in enumerate(zip(left, right), 1):
            failures.extend(f"case {i}: {v}" for v in diff(norm_body(lr[1]), norm_body(gr[1]))[:5])
    if before != middle or middle != after:
        failures.append(f"read-only CMS state changed: before={before} Laravel={middle} Go={after}")
    if failures:
        check("CMS homepage-block GET (resource + super-admin gate + read-only DB)", False, "; ".join(failures[:16]))
    else:
        check("CMS homepage-block GET (resource + super-admin gate + read-only DB)", True)
    failed = [result for result in f.results if not result[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
