#!/usr/bin/env python3
"""GLM differential harness — CATALOG IMPORTS (2 Laravel-only routes).

  POST /catalog/imports/preview  pure per-row validation
  POST /catalog/imports          per-row transactional apply (+ stock staging)

Runs as AGEN (own branch). Covers CSV parsing, header/row validation, planner
error codes, unstaged create/update outcomes, and the staged stock path.

Usage: python3 tools/parity/compare_imports.py
"""
import io
import json
import sys
import urllib.request

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body

AGEN = "agen@imp010.test"
BOUND = "pcimpboundary"
HEADER = "type,sku,product_slug,product_name,category_id,status,price,weight_grams,attributes,stock_target\n"


def multipart(fields, file_bytes):
    out = io.BytesIO()
    for k, v in fields.items():
        out.write(f"--{BOUND}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode())
    out.write(f"--{BOUND}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"katalog.csv\"\r\n"
              "Content-Type: text/csv\r\n\r\n".encode())
    out.write(file_bytes)
    out.write(f"\r\n--{BOUND}--\r\n".encode())
    return out.getvalue()


def post_multipart(client, path, body, batch=None):
    data = multipart({"idempotency_key": batch} if batch else {}, body)
    req = urllib.request.Request(client.base + path, data=data, method="POST")
    req.add_header("Content-Type", f"multipart/form-data; boundary={BOUND}")
    req.add_header("Accept", "application/json")
    req.add_header("Origin", client.base)
    req.add_header("Referer", client.base + "/")
    req.add_header("X-Requested-With", "XMLHttpRequest")
    tok = client.token()
    if tok:
        req.add_header("X-XSRF-TOKEN", tok)
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(client.jar))
    try:
        with opener.open(req, timeout=90) as res:
            return res.status, json.loads(res.read() or b"{}")
    except urllib.error.HTTPError as exc:
        return exc.code, json.loads(exc.read() or b"{}")


def csv_bytes(rows):
    return (HEADER + "\n".join(rows) + "\n").encode()


def seed():
    db("DELETE oi FROM stock_opname_items oi JOIN stock_opnames o ON o.id=oi.stock_opname_id WHERE oi.import_row_key LIKE 'imp:%'")
    db("DELETE FROM stock_opnames WHERE notes LIKE 'Import katalog%'")
    db("DELETE FROM products WHERE sku LIKE 'IMPTEST%'")
    db("DELETE FROM catalog_skus WHERE sku LIKE 'IMPTEST%'")


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: scrub(x) for k, x in v.items()}
    return v

def scenario(base, batch):
    out = {}
    c = f.client_for(base, AGEN)

    out["missing_file"] = post_multipart(c, "/api/v1/catalog/imports/preview", b"")
    out["no_data_rows"] = post_multipart(c, "/api/v1/catalog/imports/preview", HEADER.encode())
    out["unknown_column"] = post_multipart(c, "/api/v1/catalog/imports/preview",
                                           (HEADER.replace("attributes", "bogus_col") + "simple,X,,,,,,,,\n").encode())
    out["missing_required_col"] = post_multipart(c, "/api/v1/catalog/imports/preview",
                                                 (HEADER.replace("sku,", "") + "simple,,,,,,,,,\n").encode())
    out["preview_mixed"] = post_multipart(c, "/api/v1/catalog/imports/preview", csv_bytes([
        "simple,BADSKU1,,,Name,,,1000,,,",
        "simple,IMPTEST1,,Name,,,5000,100,,",
        "simple,IMPTEST1,,Other,,,6000,200,,",
        "bogus,IMPTEST2,,Name,,,5000,100,,",
        "simple,IMPTEST3,,Name,,,abc,100,,",
        "simple,IMPTEST4,,Name,,,5000,0,,",
    ]))
    out["apply_create"] = post_multipart(c, "/api/v1/catalog/imports",
                                         csv_bytes(["simple,IMPTEST10,,Product Ten,,draft,5000,100,,"]),
                                         batch=batch + "aa")
    out["created_row"] = db("SELECT sku, status, base_price, weight_grams FROM products WHERE sku='IMPTEST10'")
    out["apply_idem_missing"] = post_multipart(c, "/api/v1/catalog/imports",
                                               csv_bytes(["simple,IMPTEST11,,Product Eleven,,draft,5000,100,,"]))
    out["apply_update"] = post_multipart(c, "/api/v1/catalog/imports",
                                         csv_bytes(["simple,IMPTEST10,,,,,,7000,,active,"]),
                                         batch=batch + "bb")
    out["updated_row"] = db("SELECT base_price, status FROM products WHERE sku='IMPTEST10'")
    out["apply_staged_stock"] = post_multipart(c, "/api/v1/catalog/imports",
                                               csv_bytes(["simple,IMPTEST20,,Staged Product,,active,9000,120,,50"]),
                                               batch=batch + "cc")
    out["staged_product_draft"] = db("SELECT status FROM products WHERE sku='IMPTEST20'")
    out["staged_opname_item"] = db("SELECT import_target_quantity FROM stock_opname_items "
                                   "WHERE import_target_quantity=50 ORDER BY id DESC LIMIT 1")
    out["apply_staged_replay"] = post_multipart(c, "/api/v1/catalog/imports",
                                                csv_bytes(["simple,IMPTEST20,,Staged Product,,active,9000,120,,50"]),
                                                batch=batch + "cc")
    return out


def main():
    seed()
    lar = scenario(LARAVEL, "laravel-batch-key-001")
    seed()
    go = scenario(GO, "gobatch-key-0001-aa")
    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        lv, gv = scrub(norm_body(lar[key])), scrub(norm_body(go[key]))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv, default=str)[:600])
            print("  G=" + json.dumps(gv, default=str)[:600])
    print(f"catalog_imports: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
