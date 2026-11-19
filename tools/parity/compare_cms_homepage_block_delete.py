#!/usr/bin/env python3
"""Laravel ↔ Go differential verification for homepage-block deletion and media cleanup."""
import json, os, sys, uuid
import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, SUPER, AGEN, check, client_for, db, diff, norm_body, seed_full

ROOTS={"Laravel":"backend/storage/app/public","Go":"go-backend/storage/app/public"}

def make_fixture(runtime, kind):
    marker="__codex_delete_"+uuid.uuid4().hex
    prefix="cms/homepage-blocks/"+marker
    paths=[prefix+"_block.png"]
    if kind=="slider": paths += [prefix+"_desktop.png",prefix+"_mobile.png"]
    for path in paths:
        absolute=os.path.join(ROOTS[runtime],path)
        os.makedirs(os.path.dirname(absolute),exist_ok=True)
        with open(absolute,"wb") as out: out.write(b"codex-delete-fixture")
    if kind=="slider":
        content="JSON_OBJECT('image_path','"+paths[0]+"','slides',JSON_ARRAY(JSON_OBJECT('key','delete01','image_path','"+paths[1]+"','mobile_image_path','"+paths[2]+"')))"
    else:
        content="JSON_OBJECT('image_path','"+paths[0]+"','body','fixture')"
    db("INSERT INTO cms_homepage_blocks(type,content,sort_order,is_active,created_at,updated_at) "
       f"VALUES('text',{content},9000,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    block_id=int(db("SELECT id FROM cms_homepage_blocks WHERE content LIKE '%"+marker+"%' ORDER BY id DESC LIMIT 1")[0][0])
    return block_id,paths

def exists(block_id):
    return bool(db(f"SELECT id FROM cms_homepage_blocks WHERE id={int(block_id)}"))

def files_exist(runtime, paths):
    return {path:os.path.isfile(os.path.join(ROOTS[runtime],path)) for path in paths}

def cleanup(runtime, block_id, paths):
    db(f"DELETE FROM cms_homepage_blocks WHERE id={int(block_id)}")
    for path in paths:
        target=os.path.join(ROOTS[runtime],path)
        if os.path.isfile(target): os.remove(target)

def main():
    seed_full(); clients={"Laravel":client_for(LARAVEL,SUPER),"Go":client_for(GO,SUPER),"AgentLaravel":client_for(LARAVEL,AGEN),"AgentGo":client_for(GO,AGEN)}
    failures=[]; results=[]
    try:
        for label,kind,role in (("block image cleanup","image",SUPER),("slider image cleanup","slider",SUPER),("foreign missing ID",None,SUPER),("role denied without side effects","image",AGEN)):
            runtime_results={}
            for runtime in ("Laravel","Go"):
                fixture=None
                if kind:
                    block_id,paths=make_fixture(runtime,kind); fixture=(block_id,paths)
                else:
                    block_id=999999999; paths=[]
                client=clients[runtime if role==SUPER else "Agent"+runtime]
                response=client.call("DELETE",f"/api/v1/cms/homepage-blocks/{block_id}")
                runtime_results[runtime]={"response":response,"row_exists":exists(block_id),"files":files_exist(runtime,paths)}
                if fixture: cleanup(runtime,block_id,paths)
            left,right=runtime_results["Laravel"],runtime_results["Go"]
            if left["response"][0]!=right["response"][0]: failures.append(f"{label} status {left['response'][0]} vs {right['response'][0]}")
            failures += [f"{label} response {item}" for item in diff(norm_body(left["response"][1]),norm_body(right["response"][1]))[:8]]
            if left["row_exists"]!=right["row_exists"]: failures.append(f"{label} row exists {left['row_exists']} vs {right['row_exists']}")
            if sorted(left["files"].values())!=sorted(right["files"].values()): failures.append(f"{label} files {left['files']} vs {right['files']}")
            if label in ("block image cleanup","slider image cleanup") and (left["row_exists"] or any(left["files"].values())):
                failures.append(f"{label} Laravel did not delete row/files: {left}")
            if label=="role denied without side effects" and (not left["row_exists"] or not all(left["files"].values())):
                failures.append(f"{label} Laravel side effects unexpected: {left}")
            results.append(label)
        check("CMS homepage-block DELETE (response + row/files + RBAC)",not failures,"; ".join(failures[:24]))
        failed=[item for item in f.results if not item[1]]
        print(f"{len(f.results)-len(failed)} passed, {len(failed)} failed")
        return 1 if failed else 0
    finally:
        # Each normal fixture is cleaned after comparison; this catches only process-level leftovers by marker.
        for runtime in ROOTS:
            folder=os.path.join(ROOTS[runtime],"cms/homepage-blocks")
            if os.path.isdir(folder):
                for name in os.listdir(folder):
                    if name.startswith("__codex_delete_"):
                        os.remove(os.path.join(folder,name))
        db("DELETE FROM cms_homepage_blocks WHERE content LIKE '%__codex_delete_%'")

if __name__=="__main__": sys.exit(main())
