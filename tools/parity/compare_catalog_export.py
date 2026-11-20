#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for catalogue CSV/XLSX export."""
import csv, importlib.util, io, pathlib, urllib.parse, zipfile, xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

def download(client,path):
    headers={"Accept":"text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet","Origin":client.base,"Referer":client.base+"/","X-Requested-With":"XMLHttpRequest","User-Agent":"IMP010-catalog-export-parity"}
    xsrf=next((urllib.parse.unquote(c.value) for c in client.jar if c.name=="XSRF-TOKEN"),None)
    if xsrf: headers["X-XSRF-TOKEN"]=xsrf
    req=__import__("urllib.request",fromlist=["Request"]).Request(client.base+path,headers=headers,method="GET")
    try:
        with client.opener.open(req,timeout=60) as resp:return resp.status,resp.headers.get("Content-Type"),resp.read()
    except __import__("urllib.error",fromlist=["HTTPError"]).HTTPError as e:return e.code,e.headers.get("Content-Type"),e.read()

def csv_rows(body):
    text=body.decode("utf-8-sig")
    return [row for row in csv.reader(io.StringIO(text))]

def xlsx_rows(body):
    z=zipfile.ZipFile(io.BytesIO(body)); ns={"m":"http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
    shared=[]
    if "xl/sharedStrings.xml" in z.namelist():
        root=ET.fromstring(z.read("xl/sharedStrings.xml"))
        shared=["".join(t.text or "" for t in item.findall(".//m:t",ns)) for item in root.findall("m:si",ns)]
    root=ET.fromstring(z.read("xl/worksheets/sheet1.xml")); rows=[]
    for row in root.findall(".//m:sheetData/m:row",ns):
        cells=[]
        for cell in row.findall("m:c",ns):
            ref=cell.attrib["r"]; col="".join(c for c in ref if c.isalpha()); index=0
            for c in col:index=index*26+ord(c.upper())-64
            while len(cells)<index:cells.append("")
            value=cell.find("m:v",ns); raw="" if value is None else value.text or ""
            if cell.attrib.get("t")=="s" and raw:raw=shared[int(raw)]
            elif cell.attrib.get("t")=="inlineStr":raw="".join(t.text or "" for t in cell.findall(".//m:t",ns))
            cells[index-1]=raw
        rows.append(cells)
    width=len(rows[0]) if rows else 0
    return [row+([""]*(width-len(row))) for row in rows]

def main():
    results=[]
    for base in (r.LARAVEL,r.GO):
        c=r.Client(base)
        if c.login("agen@imp010.test")!=200:raise RuntimeError(f"Agen login failed at {base}")
        csv=download(c,"/api/v1/catalog/export")
        scoped=download(c,"/api/v1/catalog/export?stock_type=transit")
        xlsx=download(c,"/api/v1/catalog/export?format=xlsx&stock_type=transit")
        invalid=download(c,"/api/v1/catalog/export?format=ods")
        results.append((csv,scoped,xlsx,invalid))
    # Compare parsed cell matrices: PHP and Go differ in CSV quoting policy, but the cell contract must match.
    if csv_rows(results[0][0][2])!=csv_rows(results[1][0][2]) or csv_rows(results[0][1][2])!=csv_rows(results[1][1][2]):
        print("catalog export CSV differential: FAIL")
        for ix,res in enumerate(results):print(ix,[(x[0],x[1],len(x[2]),x[2][:220]) for x in res])
        return 1
    for res in results:
        if res[2][0]!=200 or not res[2][1].startswith("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet") or not res[2][2].startswith(b"PK\x03\x04"):
            raise AssertionError("xlsx response contract failed")
        if res[3][0]!=422:raise AssertionError("invalid format must return 422")
    if xlsx_rows(results[0][2][2])!=xlsx_rows(results[1][2][2]):
        print("catalog export XLSX cell differential: FAIL");print(xlsx_rows(results[0][2][2]));print(xlsx_rows(results[1][2][2]));return 1
    print("catalog export differential: 6 response/data comparisons passed, 0 failed")
    return 0
if __name__=="__main__":raise SystemExit(main())
