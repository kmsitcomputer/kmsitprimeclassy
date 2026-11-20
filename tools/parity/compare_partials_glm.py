#!/usr/bin/env python3
"""GLM takeover harness — data-backed differential verification of the remaining PARTIAL routes.

  GET  /api/v1/referral/{code}          contract probe (valid, lowercase, invalid 422)
  GET  /api/v1/homepage                 AUTHENTICATED bestseller ranking (konsumen)
  GET  /api/v1/crm/customers/{customer}         data-backed (seeded CRM customer + visit)
  GET  /api/v1/crm/customers/{customer}/visits  data-backed
  GET  /api/v1/crm/follow-ups           data-backed
  GET  /api/v1/commissions              data-backed (two real orders -> commission rows)
  GET  /api/v1/commissions/summary      data-backed
  GET  /api/v1/reports/transactions     data-backed
  PATCH /api/v1/cms/homepage-blocks/{block}        HTML sanitization parity on update
  PATCH /api/v1/cms/homepage-blocks/{block}/toggle HTML sanitization parity on toggle

Usage: python3 tools/parity/compare_partials_glm.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, SUPER, AGEN, KONSUMEN, check, client_for, db, diff,
                                 norm_body, seed_full)
from compare_payments import make_order

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}")


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]  # a (status, body) response pair is a tuple, not a list
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        out = {}
        for k, x in v.items():
            if k in ("id", "order_id", "order_item_id", "crm_customer_id") and isinstance(x, int):
                out[k] = "<id>"  # per-runtime AUTO_INCREMENT values never line up; content is what matters
            else:
                out[k] = scrub(x)
        return out
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    return v


def scenario(base):
    out = {}
    # 1. referral preview (public)
    c = client_for(base, SUPER)
    out["ref_valid"] = c.call("GET", "/api/v1/referral/AG-IMP010")
    out["ref_lower"] = c.call("GET", "/api/v1/referral/ag-imp010")
    out["ref_invalid"] = c.call("GET", "/api/v1/referral/NOPE-123")

    # 2. homepage authenticated
    ck = client_for(base, KONSUMEN)
    out["home_auth"] = ck.call("GET", "/api/v1/homepage")

    # 3. CRM reads, data-backed
    db("DELETE FROM crm_visits WHERE crm_customer_id IN (SELECT id FROM crm_customers WHERE name='GLMPART')")
    db("DELETE FROM crm_customers WHERE name='GLMPART'")
    db("INSERT INTO crm_customers (agent_id, owner_user_id, name, phone, prospect_percent, created_at, updated_at) "
       "VALUES (10, 10, 'GLMPART', '081100009888', 40, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
    cid = db("SELECT id FROM crm_customers WHERE name='GLMPART' ORDER BY id DESC LIMIT 1")[0][0]
    db("INSERT INTO crm_visits (agent_id, crm_customer_id, user_id, visit_date, status, prospect_percent, note, created_at, updated_at) "
       f"VALUES (10, {cid}, 10, '2026-11-20', 'FU', 60, 'follow up GLMPART', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
    ca = client_for(base, AGEN)
    out["crm_show"] = ca.call("GET", f"/api/v1/crm/customers/{cid}")
    out["crm_visits"] = ca.call("GET", f"/api/v1/crm/customers/{cid}/visits")
    out["crm_followups"] = ca.call("GET", "/api/v1/crm/follow-ups")

    # 4. commissions data-backed: two real orders (full chain -> commission rows)
    oid1 = make_order(base, payment="bank_transfer", items=2)
    oid2 = make_order(base, payment="bank_transfer", items=1)
    n = db("SELECT COUNT(*) FROM commissions")[0][0]
    check(f"commissions rows exist ({base})", int(n) > 0, f"{n} rows after 2 orders")
    out["commissions"] = ca.call("GET", "/api/v1/commissions")
    out["commissions_summary"] = ca.call("GET", "/api/v1/commissions/summary")
    cs = client_for(base, SUPER)
    out["reports_transactions"] = cs.call("GET", "/api/v1/reports/transactions")

    # 5. homepage-block update + toggle with HTML content (sanitizer parity)
    st, blk = cs.call("POST", "/api/v1/cms/homepage-blocks",
                      {"type": "custom", "title": "GLMPART", "content": {"html": "<p>Isi <script>x()</script></p>"},
                       "is_active": True})
    out["block_store"] = (st, blk)
    if st == 201:
        bid = blk["data"]["id"]
        out["block_update"] = cs.call("PATCH", f"/api/v1/cms/homepage-blocks/{bid}",
                                      {"content": {"html": "<p>Dua <b>tebal</b><script>alert(1)</script></p>"}})
        out["block_toggle"] = cs.call("PATCH", f"/api/v1/cms/homepage-blocks/{bid}/toggle")
    else:
        out["block_update"] = out["block_toggle"] = None
    return out


def main():
    f.reset()
    seed_full()
    lar = scenario(LARAVEL)
    f.reset()
    seed_full()
    go = scenario(GO)

    failed = 0
    for key in sorted(lar):
        lv = scrub(norm_body(lar[key]))
        gv = scrub(norm_body(go[key]))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv)[:1600])
            print("  G=" + json.dumps(gv)[:1600])
            # row-count hint for paginated list endpoints
            try:
                print("  len L=%s len G=%s" % (len(lv[1]["data"]) if isinstance(lv[1], dict) and lv[1].get("data") is not None else "?",
                                               len(go[key][1]["data"]) if isinstance(go[key][1], dict) and go[key][1].get("data") is not None else "?"))
            except Exception:
                pass
    print(f"partials-glm: {len(lar) - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())