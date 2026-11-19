#!/usr/bin/env python3
"""Differential verification of CMS homepage-block multipart requests and disk effects."""
import json, os, re, struct, sys, urllib.error, urllib.request, zlib
import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, SUPER, check, client_for, db, diff, seed_full

ROOTS = {"Laravel": "backend/storage/app/public", "Go": "go-backend/storage/app/public"}

def png1():
    def chunk(kind, data):
        body=kind+data
        return struct.pack(">I",len(data))+body+struct.pack(">I",zlib.crc32(body)&0xffffffff)
    return b"\x89PNG\r\n\x1a\n"+chunk(b"IHDR",struct.pack(">IIBBBBB",1,1,8,2,0,0,0))+chunk(b"IDAT",zlib.compress(b"\0\xff\0\0"))+chunk(b"IEND",b"")

def multipart(client, fields, files):
    boundary="----pc-imp010-upload"
    body=[]
    for key,value in fields.items(): body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n'.encode(),str(value).encode(),b"\r\n"]
    for key,(name,data,mime) in files.items(): body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(),data,b"\r\n"]
    body.append(f"--{boundary}--\r\n".encode())
    headers={"Accept":"application/json","Origin":client.base,"Referer":client.base+"/","X-Requested-With":"XMLHttpRequest","Content-Type":f"multipart/form-data; boundary={boundary}"}
    if client.token(): headers["X-XSRF-TOKEN"]=client.token()
    req=urllib.request.Request(client.base+"/api/v1/cms/homepage-blocks",data=b"".join(body),headers=headers,method="POST")
    try:
        with client.opener.open(req,timeout=50) as res: return res.status,parse_body(res.read())
    except urllib.error.HTTPError as exc: return exc.code,parse_body(exc.read())

def parse_body(raw):
    raw=raw.decode(errors="replace")
    try: return json.loads(raw) if raw else None
    except json.JSONDecodeError: return {"_raw":raw[:300]}

def rows(before):
    return [r for r in db("SELECT id,type,content,sort_order,is_active,starts_at,ends_at FROM cms_homepage_blocks ORDER BY id") if r[0] not in before]

def paths(content):
    found=[]
    if isinstance(content,dict):
        for key,value in content.items():
            if key.endswith("_path") and isinstance(value,str) and value.replace("\\/","/").startswith("cms/homepage-blocks/"): found.append(value.replace("\\/","/"))
            found += paths(value)
    elif isinstance(content,list):
        for value in content: found += paths(value)
    return found

def norm(value):
    if isinstance(value,dict): return {k:norm(v) for k,v in value.items()}
    if isinstance(value,list): return [norm(v) for v in value]
    if isinstance(value,str):
        value=value.replace("\\/","/")
        if value.startswith("cms/homepage-blocks/") or "/storage/cms/homepage-blocks/" in value: return "<upload-path>"
    return value

def disk_files(runtime):
    folder=os.path.join(ROOTS[runtime],"cms/homepage-blocks")
    if not os.path.isdir(folder): return set()
    return {"cms/homepage-blocks/"+name for name in os.listdir(folder) if os.path.isfile(os.path.join(folder,name))}

def cleanup(runtime, created_rows, created_files):
    ids=[]; file_paths=set(created_files)
    for row in created_rows:
        ids.append(row[0])
        try: file_paths.update(paths(json.loads(row[2])))
        except (TypeError,json.JSONDecodeError): pass
    for path in file_paths:
        if not re.fullmatch(r"cms/homepage-blocks/[A-Za-z0-9_-]+\.(jpg|png|webp)",path): raise RuntimeError(f"unsafe test cleanup path {path!r}")
        target=os.path.join(ROOTS[runtime],path)
        if os.path.isfile(target): os.remove(target)
    if ids: db("DELETE FROM cms_homepage_blocks WHERE id IN ("+",".join(ids)+")")

def run_case(clients,label,fields,files,status=201,orphan_count=0):
    results={}; snapshots={}; failures=[]
    for runtime,client in clients.items():
        before={r[0] for r in db("SELECT id FROM cms_homepage_blocks")}; old_files=disk_files(runtime)
        result=multipart(client,fields,files); created=rows(before); new_files=disk_files(runtime)-old_files
        results[runtime]=result
        snapshots[runtime]=[{"type":r[1],"content":norm(json.loads(r[2])),"sort_order":r[3],"is_active":r[4],"starts_at":r[5],"ends_at":r[6]} for r in created]
        if result[0]==201:
            if len(created)!=1: failures.append(f"{runtime} rows {len(created)}, expected 1")
            refs=paths(json.loads(created[0][2])) if created else []
            if len(refs)!=len(files): failures.append(f"{runtime} stored {len(refs)} paths, expected {len(files)}")
            if len(new_files)!=len(files): failures.append(f"{runtime} created {len(new_files)} files, expected {len(files)}")
            for path in refs:
                file=os.path.join(ROOTS[runtime],path)
                if not os.path.isfile(file): failures.append(f"{runtime} missing {path}")
                elif not re.fullmatch(r"cms/homepage-blocks/[A-Za-z0-9_-]{40}\.(jpg|png|webp)",path): failures.append(f"{runtime} bad storage name {path}")
                elif open(file,"rb").read() not in [value[1] for value in files.values()]: failures.append(f"{runtime} bytes differ for {path}")
        elif len(new_files)!=orphan_count: failures.append(f"{runtime} filesystem side effects {len(new_files)}, expected {orphan_count}")
        cleanup(runtime,created,new_files)
    if results["Laravel"][0]!=results["Go"][0]: failures.append(f"status {results['Laravel'][0]} vs {results['Go'][0]}")
    if results["Laravel"][0]!=status: failures.append(f"expected status {status}, Laravel gave {results['Laravel'][0]}")
    failures += ["response "+x for x in diff(f.norm_body(norm(results["Laravel"][1])),f.norm_body(norm(results["Go"][1])))[:10]]
    failures += ["database "+x for x in diff(snapshots["Laravel"],snapshots["Go"])[:10]]
    check("CMS block multipart — "+label,not failures,"; ".join(failures))

def main():
    seed_full(); clients={"Laravel":client_for(LARAVEL,SUPER),"Go":client_for(GO,SUPER)}; png=png1(); good=("image.png",png,"image/png")
    run_case(clients,"image + metadata",{"type":"hero","content[heading]":"Uploaded","sort_order":"4","is_active":"0","starts_at":"2026-11-20T00:00:00Z","ends_at":"2026-11-21T00:00:00Z"},{"image":good})
    slider={"type":"banner_slider","content[layout]":"wide","content[autoplay]":"1","content[interval_seconds]":"5","content[show_arrows]":"1","content[show_dots]":"1","content[slides][0][key]":"slide-abcdef","content[slides][0][title]":"Slide"}
    run_case(clients,"slider desktop + mobile",slider,{"slide_images[slide-abcdef][desktop]":good,"slide_images[slide-abcdef][mobile]":("mobile.png",png,"image/png")})
    run_case(clients,"desktop required",slider,{},422)
    run_case(clients,"mobile-only source side effect",slider,{"slide_images[slide-abcdef][mobile]":("mobile.png",png,"image/png")},422,1)
    run_case(clients,"slider rejects block image after processing slide files",slider,{
        "slide_images[slide-abcdef][desktop]":good,"image":("stray.png",png,"image/png")
    },422,1)
    run_case(clients,"invalid image",{"type":"hero","content[heading]":"Bad"},{"image":("note.txt",b"plain text","text/plain")},422)
    run_case(clients,"invalid slide image",slider,{"slide_images[slide-abcdef][desktop]":("note.txt",b"plain text","text/plain")},422)
    for label, overrides in [
        ("slider interval minimum", {"content[interval_seconds]":"2"}),
        ("slider invalid layout", {"content[layout]":"fullscreen"}),
        ("slider invalid overlay", {"content[slides][0][overlay]":"neon"}),
        ("slider invalid position", {"content[slides][0][content_position]":"top"}),
        ("slider unsafe link", {"content[slides][0][button_url]":"javascript:alert(1)"}),
    ]:
        changed={**slider,**overrides}
        run_case(clients,label,changed,{"slide_images[slide-abcdef][desktop]":good},422)
    bad_key={**slider,"content[slides][0][key]":"x y"}
    run_case(clients,"slider invalid key",bad_key,{"slide_images[x y][desktop]":good},422)
    duplicate={**slider,"content[slides][1][key]":"slide-abcdef"}
    run_case(clients,"slider duplicate keys",duplicate,{
        "slide_images[slide-abcdef][desktop]":good,
    },422)
    run_case(clients,"image rejected by type",{"type":"text","content[body]":"Body"},{"image":good},422)
    run_case(clients,"bad sort order",{"type":"hero","content[heading]":"Meta","sort_order":"abc"},{},422)
    run_case(clients,"bad active flag",{"type":"hero","content[heading]":"Meta","is_active":"maybe"},{},422)
    run_case(clients,"date order",{"type":"hero","content[heading]":"Meta","starts_at":"2026-11-21","ends_at":"2026-11-20"},{},422)
    failed=[item for item in f.results if not item[1]]; print(f"{len(f.results)-len(failed)} passed, {len(failed)} failed"); return 1 if failed else 0

if __name__=="__main__": sys.exit(main())
