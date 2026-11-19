#!/usr/bin/env python3
"""Laravel ↔ Go differential verification for homepage-block ordering."""
import json, sys, uuid
import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, SUPER, AGEN, check, client_for, db, diff, norm_body, seed_full

def snapshot():
    return db("SELECT id,sort_order,updated_at FROM cms_homepage_blocks ORDER BY id")

def comparable_state():
    return [row[:2] for row in snapshot()]

def restore(rows):
    for block_id, sort_order, updated_at in rows:
        db(f"UPDATE cms_homepage_blocks SET sort_order={int(sort_order)},updated_at='{updated_at}' WHERE id={int(block_id)}")

def main():
    seed_full()
    clients={base:{email:client_for(host,email) for email in (SUPER,AGEN)} for base,host in (("Laravel",LARAVEL),("Go",GO))}
    before=snapshot(); marker="__codex_reorder_"+uuid.uuid4().hex
    ids=[]; failures=[]
    try:
        for i in range(3):
            db("INSERT INTO cms_homepage_blocks(type,content,sort_order,is_active,created_at,updated_at) "
               f"VALUES('text',JSON_OBJECT('body','{marker}_{i}'),{10000+i},1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ids=[int(row[0]) for row in db("SELECT id FROM cms_homepage_blocks WHERE content LIKE '%"+marker+"%' ORDER BY id")]
        if len(ids)!=3: raise RuntimeError(f"created {len(ids)} fixture blocks")
        fixtures=[(int(row[0]),int(row[1]),row[2]) for row in db("SELECT id,sort_order,updated_at FROM cms_homepage_blocks WHERE id IN ("+",".join(map(str,ids))+")")]
        cases=[
            ("normal ordering", {"block_ids":[ids[2],ids[0],ids[1]]}, SUPER),
            ("partial ordering", {"block_ids":[ids[1],ids[2]]}, SUPER),
            ("duplicate IDs", {"block_ids":[ids[0],ids[0]]}, SUPER),
            ("missing ID", {"block_ids":[ids[0],999999999]}, SUPER),
            ("missing block_ids", {}, SUPER),
            ("null block_ids", {"block_ids":None}, SUPER),
            ("empty block_ids", {"block_ids":[]}, SUPER),
            ("scalar block_ids", {"block_ids":"invalid"}, SUPER),
            ("object block_ids", {"block_ids":{"0":ids[0]}}, SUPER),
            ("string existing IDs", {"block_ids":[str(ids[1]),str(ids[0])]}, SUPER),
            ("integral float ID", {"block_ids":[float(ids[0])]}, SUPER),
            ("decimal numeric string ID", {"block_ids":[str(ids[1])+".0"]}, SUPER),
            ("boolean existing ID", {"block_ids":[True]}, SUPER),
            ("false nonexisting ID", {"block_ids":[False]}, SUPER),
            ("null item ID", {"block_ids":[None]}, SUPER),
            ("nonexistent string ID", {"block_ids":["999999999"]}, SUPER),
            ("fractional ID", {"block_ids":[1.5]}, SUPER),
            ("unauthorized role", {"block_ids":[ids[0]]}, AGEN),
        ]
        for label,body,role in cases:
            results={}; states={}
            for runtime in ("Laravel","Go"):
                restore(before + fixtures)
                results[runtime]=clients[runtime][role].call("PATCH","/api/v1/cms/homepage-blocks/reorder",body)
                states[runtime]=comparable_state()
            left,right=results["Laravel"],results["Go"]
            if left[0]!=right[0]: failures.append(f"{label} status {left[0]} vs {right[0]}")
            failures += [f"{label} response {item}" for item in diff(norm_body(left[1]),norm_body(right[1]))[:8]]
            failures += [f"{label} DB {item}" for item in diff(states["Laravel"],states["Go"])[:8]]
        check("CMS homepage-block reorder (response + state + validation + RBAC)",not failures,"; ".join(failures[:32]))
        failed=[item for item in f.results if not item[1]]
        print(f"{len(f.results)-len(failed)} passed, {len(failed)} failed")
        return 1 if failed else 0
    finally:
        restore(before)
        db("DELETE FROM cms_homepage_blocks WHERE content LIKE '%"+marker+"%'")

if __name__=="__main__": sys.exit(main())
