#!/usr/bin/env python3
"""One-shot parity probe for the category admin mutations (store/update/delete) — response + DB state."""
import re
import sys
import uuid

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, AGEN, SUPER, KONSUMEN, check, client_for, db, diff, norm_body, reset, rows, seed_full

_SLUG_RE = re.compile(r"^paritas-uji-[0-9a-f]{13}$")


def scrub(value):
    """The slug suffix is PHP uniqid() — different on every runtime by construction."""
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            if k == "slug" and isinstance(v, str) and _SLUG_RE.match(v):
                out[k] = "<slug>"
            else:
                out[k] = scrub(v)
        return out
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return value


def make(base, who, name, parent_id=None):
    c = client_for(base, who)
    body = {"name": name}
    if parent_id is not None:
        body["parent_id"] = parent_id
    return c.call("POST", "/api/v1/categories", body)


_orig_state = f.state


def category_state():
    out = _orig_state()
    out["cats"] = rows("SELECT parent_id IS NULL, name, IFNULL(root_name_key,'-'), is_active, sort_order "
                       "FROM product_categories ORDER BY id")
    return out


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        reset_categories()
        out[label] = (run(base), f.state())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    st_l, bodies_l = [p[0] for p in pairs_l], [p[1] for p in pairs_l]
    st_g, bodies_g = [p[0] for p in pairs_g], [p[1] for p in pairs_g]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g}")
        return
    for i, (bl, bg) in enumerate(zip(bodies_l, bodies_g)):
        diffs = diff(norm_body(scrub(bl)), norm_body(scrub(bg)))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:5]))
            return
    diffs = diff(db_l, db_g)
    if diffs:
        check(f"{name} [db]", False, "; ".join(diffs[:6]))
        return
    check(name, True)


_CATEGORY_RESET = """
DELETE FROM product_categories;
INSERT INTO product_categories (id, parent_id, name, name_key, slug, is_active, sort_order, created_at, updated_at) VALUES
 (1,NULL,'Kue','kue','kue',1,1,NOW(),NOW()),
 (2,1,'Kue Basah','kue-basah','kue-basah',1,1,NOW(),NOW());
"""


def reset_categories():
    # The taxonomy is global and NOT part of the order-scoped reset(): the promotion side effect of a
    # successful delete (a child becomes a root) would otherwise poison the other runtime's run.
    for statement in _CATEGORY_RESET.strip().split(";"):
        if statement.strip():
            db(statement)


def main():
    seed_full()
    f.state = category_state
    reset_categories()

    def store_roundtrip(base):
        c = client_for(base, AGEN)
        out = [make(base, AGEN, "Paritas Uji")]
        cid = db("SELECT id FROM product_categories ORDER BY id DESC LIMIT 1")[0][0]
        out.append(c.call("PATCH", f"/api/v1/categories/{cid}", {"name": "Paritas Uji Ganti"}))
        out.append(client_for(base, SUPER).call("DELETE", f"/api/v1/categories/{cid}"))
        return out

    scenario("category store -> update -> delete (RBAC + DB)", store_roundtrip)

    def store_rules(base):
        out = [make(base, AGEN, "")]
        out.append(make(base, AGEN, " " * 5))
        out.append(client_for(base, SUPER).call("PATCH", "/api/v1/categories/999999", {"name": "x"}))
        out.append(client_for(base, SUPER).call("DELETE", "/api/v1/categories/999999"))
        out.append(client_for(base, KONSUMEN).call("POST", "/api/v1/categories", {"name": "Nope"}))
        return out

    scenario("category validation + RBAC denials", store_rules)

    def delete_with_child(base):
        make(base, SUPER, "Induk")
        parent = db("SELECT id FROM product_categories ORDER BY id DESC LIMIT 1")[0][0]
        make(base, SUPER, "Anak", parent_id=parent)
        return [client_for(base, SUPER).call("DELETE", f"/api/v1/categories/{parent}")]

    scenario("delete refuses a parent with children", delete_with_child)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
