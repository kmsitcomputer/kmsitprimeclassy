#!/usr/bin/env python3
"""Laravel ↔ Go differential verification for homepage-block JSON updates."""
import json, os, sys, uuid, urllib.error, urllib.request
import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, SUPER, AGEN, check, client_for, db, diff, norm_body, seed_full
import compare_cms_homepage_block_uploads as u

ROOTS={"Laravel":"backend/storage/app/public","Go":"go-backend/storage/app/public"}

def multipart_update(client,path,fields,files):
    boundary="----pc-imp010-update"
    body=[]
    fields={"_method":"PATCH",**fields}
    for key,value in fields.items(): body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n'.encode(),str(value).encode(),b"\r\n"]
    for key,(name,data,mime) in files.items(): body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(),data,b"\r\n"]
    body.append(f"--{boundary}--\r\n".encode())
    headers={"Accept":"application/json","Origin":client.base,"Referer":client.base+"/","X-Requested-With":"XMLHttpRequest","Content-Type":f"multipart/form-data; boundary={boundary}"}
    if client.token(): headers["X-XSRF-TOKEN"]=client.token()
    req=urllib.request.Request(client.base+path,data=b"".join(body),headers=headers,method="POST")
    try:
        with client.opener.open(req,timeout=50) as response: return response.status,json.loads(response.read().decode())
    except urllib.error.HTTPError as error:
        raw=error.read().decode()
        return error.code,json.loads(raw) if raw else None

def fixture(runtime, image=False, block_type="text"):
    marker="__codex_update_"+uuid.uuid4().hex
    path="cms/homepage-blocks/"+marker+".png"
    if image:
        absolute=os.path.join(ROOTS[runtime],path); os.makedirs(os.path.dirname(absolute),exist_ok=True)
        with open(absolute,"wb") as out: out.write(b"update-fixture-image")
    content="JSON_OBJECT('body','Original','heading','Before'"+(",'image_path','"+path+"'" if image else "")+")"
    db("INSERT INTO cms_homepage_blocks(type,content,sort_order,is_active,starts_at,ends_at,created_at,updated_at) "
       f"VALUES('{block_type}',{content},7,1,'2026-11-18 00:00:00','2026-11-28 00:00:00',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    block_id=int(db("SELECT id FROM cms_homepage_blocks WHERE content LIKE '%"+marker+"%' ORDER BY id DESC LIMIT 1")[0][0]) if image else int(db("SELECT id FROM cms_homepage_blocks WHERE content LIKE '%Original%' ORDER BY id DESC LIMIT 1")[0][0])
    return marker,block_id,path

def run_multipart(runtime,client,fields,files,block_type):
    _,block_id,old_path=fixture(runtime,True,block_type)
    old_file=os.path.join(ROOTS[runtime],old_path)
    os.makedirs(os.path.dirname(old_file),exist_ok=True)
    with open(old_file,"wb") as out: out.write(b"old-update-image")
    before=u.disk_files(runtime)
    response=multipart_update(client,f"/api/v1/cms/homepage-blocks/{block_id}",fields,files)
    rows=db(f"SELECT id,type,content,sort_order,is_active,starts_at,ends_at FROM cms_homepage_blocks WHERE id={block_id}")
    state=None
    if rows:
        row=rows[0]; state={"type":row[1],"content":json.loads(row[2]),"sort_order":row[3],"is_active":row[4],"starts_at":row[5],"ends_at":row[6]}
    new_files=u.disk_files(runtime)-before
    old_exists=os.path.isfile(old_file)
    cleanup_row=rows[0] if rows else [str(block_id),block_type,"{}"]
    u.cleanup(runtime,[cleanup_row],new_files)
    if os.path.isfile(old_file): os.remove(old_file)
    return response,state,old_exists,len(new_files),u.norm

def run_slider_multipart(runtime,client):
    marker="__codex_slider_update_"+uuid.uuid4().hex
    prefix="cms/homepage-blocks/"+marker
    old_desktop=prefix+"_desktop.png"; old_mobile=prefix+"_mobile.png"
    for path in (old_desktop,old_mobile):
        target=os.path.join(ROOTS[runtime],path); os.makedirs(os.path.dirname(target),exist_ok=True)
        with open(target,"wb") as out: out.write(b"old-slide-image")
    content=("JSON_OBJECT('layout','contained','autoplay',true,'interval_seconds',5,'show_arrows',true,'show_dots',true,"
             "'slides',JSON_ARRAY(JSON_OBJECT('key','slide01','title','Before','image_path','"+old_desktop+"','mobile_image_path','"+old_mobile+"')))" )
    db("INSERT INTO cms_homepage_blocks(type,content,sort_order,is_active,created_at,updated_at) "
       f"VALUES('banner_slider',{content},8,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    block_id=int(db("SELECT id FROM cms_homepage_blocks WHERE content LIKE '%"+marker+"%' ORDER BY id DESC LIMIT 1")[0][0])
    fields={"content[layout]":"contained","content[autoplay]":"1","content[interval_seconds]":"5",
            "content[show_arrows]":"1","content[show_dots]":"1","content[slides][0][key]":"slide01",
            "content[slides][0][title]":"After","content[slides][0][description]":"","content[slides][0][alt]":"",
            "content[slides][0][button_label]":"","content[slides][0][button_url]":"",
            "content[slides][0][overlay]":"dark","content[slides][0][content_position]":"left",
            "content[slides][0][remove_mobile]":"1"}
    files={"slide_images[slide01][desktop]":("desktop.png",u.png1(),"image/png")}
    before=u.disk_files(runtime)
    response=multipart_update(client,f"/api/v1/cms/homepage-blocks/{block_id}",fields,files)
    rows=db(f"SELECT id,type,content,sort_order,is_active,starts_at,ends_at FROM cms_homepage_blocks WHERE id={block_id}")
    state=None; current_paths=[]
    if rows:
        row=rows[0]; content_value=json.loads(row[2]); state={"type":row[1],"content":content_value,"sort_order":row[3],"is_active":row[4],"starts_at":row[5],"ends_at":row[6]}
        current_paths=u.paths(content_value)
    new_files=u.disk_files(runtime)-before
    outcomes={"old_desktop":os.path.isfile(os.path.join(ROOTS[runtime],old_desktop)),
              "old_mobile":os.path.isfile(os.path.join(ROOTS[runtime],old_mobile)),
              "new_count":len(new_files),"current_paths_exist":all(os.path.isfile(os.path.join(ROOTS[runtime],path)) for path in current_paths)}
    cleanup_row=rows[0] if rows else [str(block_id),"banner_slider","{}"]
    u.cleanup(runtime,[cleanup_row],new_files)
    for path in (old_desktop,old_mobile):
        target=os.path.join(ROOTS[runtime],path)
        if os.path.isfile(target): os.remove(target)
    return response,state,outcomes,u.norm

def run(runtime,client,body,image_fixture=False):
    marker,block_id,path=fixture(runtime,image_fixture)
    try:
        response=client.call("PATCH",f"/api/v1/cms/homepage-blocks/{block_id}",body)
        rows=db(f"SELECT type,content,sort_order,is_active,starts_at,ends_at FROM cms_homepage_blocks WHERE id={block_id}")
        state=None
        if rows:
            row=rows[0]; state={"type":row[0],"content":json.loads(row[1]),"sort_order":row[2],"is_active":row[3],"starts_at":row[4],"ends_at":row[5]}
        file=os.path.isfile(os.path.join(ROOTS[runtime],path))
        return response,state,file
    finally:
        db(f"DELETE FROM cms_homepage_blocks WHERE id={block_id}")
        target=os.path.join(ROOTS[runtime],path)
        if os.path.isfile(target): os.remove(target)

def main():
    seed_full(); clients={base:{email:client_for(host,email) for email in (SUPER,AGEN)} for base,host in (("Laravel",LARAVEL),("Go",GO))}; failures=[]
    cases=[
        ("partial content merge",{"content":{"body":"Changed"}},False,SUPER),
        ("nullable content",{"content":None},False,SUPER),
        ("metadata update",{"content":{},"sort_order":3,"is_active":False,"starts_at":"2026-11-20T10:00:00Z","ends_at":"2026-11-21T10:00:00Z"},False,SUPER),
        ("clear nullable dates",{"starts_at":None,"ends_at":None},False,SUPER),
        ("boolean string coercion",{"is_active":"1"},False,SUPER),
        ("null sort order",{"sort_order":None},False,SUPER),
        ("invalid sort order",{"sort_order":"many"},False,SUPER),
        ("invalid date",{"starts_at":"not-a-date"},False,SUPER),
        ("date ordering",{"starts_at":"2026-11-22","ends_at":"2026-11-21"},False,SUPER),
        ("null active validation",{"is_active":None},False,SUPER),
        ("scalar content validation",{"content":"invalid"},False,SUPER),
        ("non-string body validation",{"content":{"body":12}},False,SUPER),
        ("body size validation",{"content":{"body":"x"*5001}},False,SUPER),
        ("immutable type input",{"type":"hero","content":{"body":"Still text"}},False,SUPER),
        ("remove image",{"remove_image":True},True,SUPER),
        ("remove image integer one",{"remove_image":1},True,SUPER),
        ("remove image integer zero",{"remove_image":0},True,SUPER),
        ("role denied",{"content":{"body":"Denied"}},False,AGEN),
    ]
    for label,body,image,role in cases:
        results={}; states={}; files={}
        for runtime in ("Laravel","Go"):
            response,state,file=run(runtime,clients[runtime][role],body,image)
            results[runtime]=response; states[runtime]=state; files[runtime]=file
        left,right=results["Laravel"],results["Go"]
        if left[0]!=right[0]: failures.append(f"{label} status {left[0]} vs {right[0]}")
        if label == "nullable content":
            if left[0] != 500 or right[0] != 500:
                failures.append(f"{label} expected Laravel's typed-service 500, got {left[0]} and {right[0]}")
            if right[1].get("success") is not False or right[1].get("errors") is not None:
                failures.append(f"{label} Go must return a generic non-debug server error: {right[1]}")
        else:
            failures += [f"{label} response {item}" for item in diff(u.norm(norm_body(left[1])),u.norm(norm_body(right[1])))[:8]]
        failures += [f"{label} DB {item}" for item in diff(u.norm(norm_body(states["Laravel"])),u.norm(norm_body(states["Go"])))[:8]]
        if files["Laravel"]!=files["Go"]: failures.append(f"{label} file exists {files['Laravel']} vs {files['Go']}")
        if label in {"remove image","remove image integer one"} and any(files.values()): failures.append(f"{label} did not delete the file: {files}")
        if label=="remove image integer zero" and not all(files.values()): failures.append(f"{label} unexpectedly deleted the file: {files}")
        if label=="role denied" and (states["Laravel"] is None or states["Go"] is None): failures.append(f"{label} changed row state")
    for label,block_type,fields,files in (
        ("replace hero image","hero",{"content[heading]":"After"},{"image":("new.png",u.png1(),"image/png")}),
        ("remove existing image","text",{"remove_image":"1"},{}),
        ("reject invalid replacement image","hero",{"content[heading]":"After"},{"image":("bad.txt",b"not an image","text/plain")}),
    ):
        results={}; states={}; old_files={}; new_counts={}
        for runtime in ("Laravel","Go"):
            response,state,old_exists,new_count,normalize=run_multipart(runtime,clients[runtime][SUPER],fields,files,block_type)
            results[runtime]=(response[0],normalize(norm_body(response[1])))
            states[runtime]=normalize(norm_body(state)) if state else None
            old_files[runtime]=old_exists; new_counts[runtime]=new_count
        if results["Laravel"][0]!=results["Go"][0]: failures.append(f"{label} status {results['Laravel'][0]} vs {results['Go'][0]}")
        failures += [f"{label} response {item}" for item in diff(results["Laravel"][1],results["Go"][1])[:8]]
        failures += [f"{label} DB {item}" for item in diff(states["Laravel"],states["Go"])[:8]]
        if old_files["Laravel"]!=old_files["Go"]: failures.append(f"{label} old file exists {old_files}")
        if new_counts["Laravel"]!=new_counts["Go"]: failures.append(f"{label} stored file count {new_counts}")
        if label=="replace hero image" and (any(old_files.values()) or any(count!=1 for count in new_counts.values())):
            failures.append(f"{label} filesystem side effects differ: old={old_files}, new={new_counts}")
        if label=="remove existing image" and (any(old_files.values()) or any(new_counts.values())):
            failures.append(f"{label} filesystem side effects differ: old={old_files}, new={new_counts}")
        if label=="reject invalid replacement image" and (not all(old_files.values()) or any(new_counts.values())):
            failures.append(f"{label} must retain old file and store no replacement: old={old_files}, new={new_counts}")
    results={}; states={}; outcomes={}
    for runtime in ("Laravel","Go"):
        response,state,filesystem,normalize=run_slider_multipart(runtime,clients[runtime][SUPER])
        results[runtime]=(response[0],normalize(norm_body(response[1])))
        states[runtime]=normalize(norm_body(state)) if state else None
        outcomes[runtime]=filesystem
    if results["Laravel"][0]!=results["Go"][0]: failures.append(f"slider update status {results['Laravel'][0]} vs {results['Go'][0]}")
    failures += [f"slider update response {item}" for item in diff(results["Laravel"][1],results["Go"][1])[:8]]
    failures += [f"slider update DB {item}" for item in diff(states["Laravel"],states["Go"])[:8]]
    if outcomes["Laravel"]!=outcomes["Go"]: failures.append(f"slider update files {outcomes['Laravel']} vs {outcomes['Go']}")
    if outcomes["Laravel"]!={"old_desktop":False,"old_mobile":False,"new_count":1,"current_paths_exist":True}:
        failures.append(f"slider update expected replaced desktop + removed mobile: {outcomes['Laravel']}")
    missing={runtime:clients[runtime][SUPER].call("PATCH","/api/v1/cms/homepage-blocks/9223372036854775807",{"content":{"body":"no row"}})
             for runtime in ("Laravel","Go")}
    if missing["Laravel"][0]!=missing["Go"][0]: failures.append(f"missing block status {missing['Laravel'][0]} vs {missing['Go'][0]}")
    failures += [f"missing block response {item}" for item in diff(norm_body(missing["Laravel"][1]),norm_body(missing["Go"][1]))[:8]]
    check("CMS homepage-block PATCH JSON (merge + validation + image + RBAC)",not failures,"; ".join(failures[:32]))
    failed=[item for item in f.results if not item[1]]
    print(f"{len(f.results)-len(failed)} passed, {len(failed)} failed"); return 1 if failed else 0

if __name__=="__main__": sys.exit(main())
