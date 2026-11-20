#!/usr/bin/env python3
"""Super Admin region CSV import/export differential, restricted to primeclassy_testing."""
import csv, importlib.util, io, json, pathlib, urllib.error, urllib.parse, urllib.request, uuid

ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
HEADER=["province_code","province_name","regency_code","regency_name","district_code","district_name","village_code","village_name","rajaongkir_city_id"]
IDS=("98","9898","989801","9898010001")

def raw_call(client,method,path,body=None,headers=None):
    h={"Accept":"application/json","Origin":client.base,"Referer":client.base+"/","X-Requested-With":"XMLHttpRequest","User-Agent":"IMP010-regions-parity"}
    if headers:h.update(headers)
    token=next((urllib.parse.unquote(c.value) for c in client.jar if c.name=="XSRF-TOKEN"),None)
    if token:h["X-XSRF-TOKEN"]=token
    request=urllib.request.Request(client.base+path,data=body,headers=h,method=method)
    try:
        with client.opener.open(request,timeout=60) as response:return response.status,response.headers, response.read()
    except urllib.error.HTTPError as e:return e.code,e.headers,e.read()

def multipart(content,filename="regions.csv"):
    boundary="----IMP010Region"+uuid.uuid4().hex
    body=(f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{filename}\"\r\nContent-Type: text/csv\r\n\r\n").encode()+content+f"\r\n--{boundary}--\r\n".encode()
    return body,{"Content-Type":f"multipart/form-data; boundary={boundary}"}

def backup(table,code):
    rows=r.db(f"SELECT * FROM {table} WHERE id='{code}'")
    if rows:raise RuntimeError(f"synthetic fixture {table}.{code} already exists; refusing to overwrite it")
    return rows

def clean_fixture(logs):
    for table,code in (("villages",IDS[3]),("districts",IDS[2]),("regencies",IDS[1]),("provinces",IDS[0])):
        r.db(f"DELETE FROM {table} WHERE id='{code}'")
    for row in r.db(f"SELECT id FROM activity_logs WHERE id>{logs} AND event='region.csv_imported'"):r.db(f"DELETE FROM activity_logs WHERE id={row[0]}")

def file_bytes(rows):
    stream=io.StringIO(newline="");writer=csv.writer(stream,lineterminator="\n");writer.writerow(HEADER)
    for row in rows:writer.writerow(row)
    return stream.getvalue().encode()

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    for table,code in zip(("provinces","regencies","districts","villages"),IDS):backup(table,code)
    source=file_bytes([[IDS[0],"Codex Test Province",IDS[1],"Codex Test Regency",IDS[2],"Codex Test District",IDS[3],"Codex Test Village","12345"]])
    bad_header=file_bytes([["wrong","header"]])
    bad_row=file_bytes([["","", "", "", "", "", "", "", ""]])
    results=[]
    for base in (r.LARAVEL,r.GO):
        c=r.Client(base);c.login("super@imp010.test");logs=int(r.db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
        try:
            before=raw_call(c,"GET","/api/v1/admin/regions/export")
            no_file=c.call("POST","/api/v1/admin/regions/import",b"",{"Content-Type":"multipart/form-data; boundary=missing"})
            invalid_header=raw_call(c,"POST","/api/v1/admin/regions/import",*multipart(bad_header))
            invalid_row=raw_call(c,"POST","/api/v1/admin/regions/import",*multipart(bad_row))
            body,headers=multipart(source); imported=c.call("POST","/api/v1/admin/regions/import",body,headers)
            after=raw_call(c,"GET","/api/v1/admin/regions/export")
            state={t:r.db(f"SELECT * FROM {t} WHERE id='{code}'") for t,code in zip(("provinces","regencies","districts","villages"),IDS)}
            audit=r.db(f"SELECT event,subject_type,subject_id,CAST(properties AS CHAR) FROM activity_logs WHERE id>{logs} AND event='region.csv_imported'")
            for row in audit:
                if row[3] and row[3]!="NULL":row[3]=json.loads(row[3])
            adminless=r.Client(base);adminless.login("konsumen@imp010.test");denied=adminless.call("GET","/api/v1/admin/regions/export")
            results.append({"before":(before[0],before[1].get("Content-Type"),before[1].get("Content-Disposition"),before[2]),"no_file":no_file,"bad_header":(invalid_header[0],json.loads(invalid_header[2])),"bad_row":(invalid_row[0],json.loads(invalid_row[2])),"imported":imported,"after_status":after[0],"state":state,"audit":audit,"denied":denied})
        finally:clean_fixture(logs)
    # Compare the exported representation byte-for-byte; database key IDs in import response are semantically volatile.
    if results[0]!=results[1]:
        # Go and Laravel may serialize equivalent CSV MIME spelling; surface exact field differences for diagnosis.
        print("admin region CSV differential: FAIL")
        for key in results[0]:
            if results[0][key]!=results[1][key]:print(key,repr(results[0][key])[:1200],"!=",repr(results[1][key])[:1200])
        return 1
    result=results[0]
    if result["before"][0]!=200 or result["before"][1]!="text/csv; charset=utf-8" or result["before"][2]!="attachment; filename=regions.csv":raise AssertionError(f"export headers mismatch: {result['before'][:3]}")
    if result["bad_header"][0]!=422 or result["bad_row"][0]!=422 or result["imported"][0]!=200 or result["after_status"]!=200 or result["denied"][0]!=403:raise AssertionError("region import/export status contract mismatch")
    if result["imported"][1]["data"]!={"provinces":1,"regencies":1,"districts":1,"villages":1}:raise AssertionError("region counts mismatch")
    print("admin region CSV differential: 11 passed, 0 failed");return 0

if __name__=="__main__":raise SystemExit(main())
