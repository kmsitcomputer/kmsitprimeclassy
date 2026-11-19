#!/usr/bin/env python3
"""Laravel <-> Go differential parity for the PRODUCT ADMIN write surface (Domain E).

    POST   /api/v1/products                          PATCH  /api/v1/products/{product}
    DELETE /api/v1/products/{product}                DELETE /api/v1/products/{id}/permanent
    POST   /api/v1/products/{product}/variations     PATCH/DELETE .../variations/{variation}
    POST   /api/v1/products/{product}/images         PUT    .../images/order
    PATCH/DELETE .../images/{image}                  GET/PUT .../fees (+ variation fees)

Runs each scenario on BOTH runtimes from an identical reset (products + catalog_skus + stock snapshots
purged and restored), comparing status, body (slug uniqid suffix scrubbed) and DB rows.
"""
import json
import re
import sys
from pathlib import Path

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, ADMIN, SUPER, KONSUMEN, SALES, check, client_for, db,
                                 diff, norm_body, reset, rows, seed_full)

ADMIN2 = "admin@imp010.test"
SUB = "sub@imp010.test"
SLUG_RE = re.compile(r"^(.+)-[0-9a-f]{13}$")
_IMG_RE = re.compile(r"/storage/products/[A-Za-z0-9]{27,}\.png")
MULTIPART_BOUNDARY = "----pcproduct"
REPO = Path(__file__).resolve().parents[2]


def scrub(value):
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            if k == "slug" and isinstance(v, str) and SLUG_RE.match(v):
                out[k] = "<slug>"
            elif k == "url" and isinstance(v, str):
                out[k] = _IMG_RE.sub("/storage/products/<file>.png", v)
            else:
                out[k] = scrub(v)
        return out
    if isinstance(value, list):
        return [scrub(v) for v in value]
    return value


STOCK_BACKUP = {}


def purge_products():
    """Both runtimes share ONE database. Wipe every product-adjacent row between runs, restoring the
    seeded stock snapshots (product_stocks/product_variation_stocks) afterwards."""
    for sql in ("DELETE FROM product_stocks", "DELETE FROM product_variation_stocks",
                "DELETE FROM product_images", "DELETE FROM product_fees",
                "DELETE FROM stock_opname_items", "DELETE FROM warehouse_stock_requests",
                "DELETE FROM sub_stock_reservations", "DELETE FROM sub_stock_request_items",
                "DELETE FROM stock_request_items", "DELETE FROM products_translations",
                "DELETE FROM product_attributes", "DELETE FROM product_variation_attributes",
                "DELETE FROM warehouse_migration_markers", "DELETE FROM catalog_skus",
                "DELETE FROM warehouse_stocks", "DELETE FROM product_variations", "DELETE FROM products"):
        db(sql)
    db("DELETE FROM activity_logs WHERE subject_type = 'App\\\\Models\\\\Product'")
    # Fixture products 1-3 (+ variation 1), their SKUs, stocks and the warehouse rows that reference them.
    db("INSERT INTO products (id, category_id, created_by, name, slug, description, short_description, has_variations, "
       "base_price, weight_grams, status, sku, created_at, updated_at) VALUES "
       "(1,2,10,'Kue Lumpur','kue-lumpur','<p>Deskripsi kue lumpur</p>','Ringkas kue lumpur',0,45000.00,300,'active','SKU-LUMPUR',NOW(),NOW())")
    db("INSERT INTO products (id, category_id, created_by, name, slug, description, short_description, has_variations, "
       "base_price, weight_grams, status, sku, created_at, updated_at) VALUES "
       "(2,2,10,'Kue Coklat','kue-coklat','<p>Kue coklat</p>','Coklat',1,NULL,NULL,'active','SKU-COKLAT',NOW(),NOW())")
    db("INSERT INTO products (id, category_id, created_by, name, slug, description, short_description, has_variations, "
       "base_price, weight_grams, status, sku, created_at, updated_at) VALUES "
       "(3,2,10,'Kue Pandan','kue-pandan','<p>Kue pandan</p>','Pandan',0,55000.00,250,'active','SKU-PANDAN',NOW(),NOW())")
    db("INSERT INTO product_variations (id, product_id, sku, price, weight_grams, is_active, sort_order, created_at, updated_at) VALUES "
       "(1,2,'SKU-COKLAT-1',50000.00,250,1,1,NOW(),NOW()),(2,2,'SKU-COKLAT-2',52000.00,250,1,2,NOW(),NOW())")
    db("INSERT INTO product_stocks (id, agent_id, product_id, quantity_on_hand, quantity_reserved) VALUES "
       "(1,10,1,40,5),(2,10,2,0,0),(3,10,3,25,3)")
    db("INSERT INTO product_variation_stocks (id, agent_id, product_variation_id, quantity_on_hand, quantity_reserved) VALUES "
       "(1,10,1,6,2)")
    # Canonical warehouse fixture rows (seed_inventory_fixtures) — NEVER the captured rows, which may
    # reflect mutations earlier scenarios made (a variation id may have moved).
    # target_identity/location_identity are GENERATED columns — never inserted; NULL refs stay NULL.
    db("INSERT INTO warehouse_stocks (id, agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity) VALUES "
       "(2,10,2,NULL,'transit',NULL,12),(3,10,2,NULL,'factory_plan',NULL,4),(4,10,NULL,1,'transit',NULL,9),"
       "(5,10,NULL,1,'sub',1,6),(6,10,1,NULL,'sub',3,8),(7,10,NULL,1,'sub',3,3)")
    db("INSERT INTO catalog_skus (sku, owner_type, owner_id) VALUES ('SKU-LUMPUR','product',1),('SKU-COKLAT','product',2),('SKU-PANDAN','product',3),('SKU-COKLAT-1','variant',1),('SKU-COKLAT-2','variant',2)")


def state():
    return {
        "products": rows("SELECT name, has_variations, IFNULL(sku,'-'), IFNULL(CAST(base_price AS CHAR),'-'), "
                         "IFNULL(weight_grams,0), status, category_id=2, deleted_at IS NULL FROM products ORDER BY id"),
        "variations": rows("SELECT product_id, IFNULL(sku,'-'), CAST(price AS CHAR), weight_grams, is_active "
                           "FROM product_variations ORDER BY id"),
        "skus": rows("SELECT sku, owner_type FROM catalog_skus ORDER BY sku"),
        "stocks": rows("SELECT product_id, quantity_on_hand, quantity_reserved FROM product_stocks ORDER BY id"),
        "vstocks": rows("SELECT product_variation_id, quantity_on_hand, quantity_reserved FROM product_variation_stocks ORDER BY id"),
        "images": rows("SELECT product_id, product_variation_id IS NOT NULL, is_primary, sort_order "
                       "FROM product_images ORDER BY id"),
        # id/parent-id columns are row-id artefacts across the two runs (shared database, auto-increment);
        # the SHAPE (how many attributes/options/compositions each variation owns) is the assertion.
        "attributes": rows("SELECT name, (SELECT COUNT(*) FROM product_variation_attribute_options o "
                           "WHERE o.product_variation_attribute_id = a.id) FROM product_variation_attributes a ORDER BY name"),
        "options": rows("SELECT value FROM product_variation_attribute_options ORDER BY value"),
        "compositions": rows("SELECT (SELECT v.sku FROM product_variations v WHERE v.id = c.product_variation_id), "
                             "(SELECT a.name FROM product_variation_attributes a WHERE a.id = c.product_variation_attribute_id), "
                             "(SELECT o.value FROM product_variation_attribute_options o "
                             " WHERE o.id = c.product_variation_attribute_option_id) "
                             "FROM product_variation_compositions c ORDER BY c.id"),
        "activity": rows("SELECT event, subject_type, IFNULL(description,'-'), IFNULL(properties,'') FROM activity_logs "
                         "WHERE event LIKE 'product.%' OR event LIKE 'catalog.%' ORDER BY id"),
    }


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        purge_products()
        out[label] = (run(base), state())
    pairs_l, db_l = out["L"]
    pairs_g, db_g = out["G"]
    st_l, bodies_l = [p[0] for p in pairs_l], [p[1] for p in pairs_l]
    st_g, bodies_g = [p[0] for p in pairs_g], [p[1] for p in pairs_g]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(bodies_l)[:280]} || {json.dumps(bodies_g)[:280]}")
        return
    for i, (bl, bg) in enumerate(zip(bodies_l, bodies_g)):
        diffs = diff(norm_body(scrub(bl)), norm_body(scrub(bg)))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:6]))
            return
    diffs = diff(db_l, db_g)
    if diffs:
        check(f"{name} [db]", False, "; ".join(diffs[:6]))
        return
    check(name, True)


def public_stock_availability(base):
    agent = client_for(base, AGEN)
    sub = client_for(base, SUB)
    anonymous = f.Client(base)
    return [
        anonymous.call("GET", "/api/v1/products?per_page=100&sort=price_asc"),
        anonymous.call("GET", "/api/v1/products/kue-lumpur"),
        anonymous.call("GET", "/api/v1/products/kue-coklat"),
        agent.call("GET", "/api/v1/products?per_page=100&sort=price_asc"),
        agent.call("GET", "/api/v1/products/kue-lumpur"),
        agent.call("GET", "/api/v1/products/kue-coklat"),
        sub.call("GET", "/api/v1/products?per_page=100&stock_source=agent&sort=price_asc"),
        sub.call("GET", "/api/v1/products?per_page=100&stock_source=sub&sort=price_asc"),
        sub.call("GET", "/api/v1/products/kue-lumpur?stock_source=sub&sub_location_id=1"),
        agent.call("GET", "/api/v1/products?stock_source=sub"),
        sub.call("GET", "/api/v1/products?stock_source=sub&sub_location_id=9999"),
        sub.call("GET", "/api/v1/products?stock_source=agent&sub_location_id=1"),
        sub.call("GET", "/api/v1/products?stock_source=invalid"),
        anonymous.call("GET", "/api/v1/products?stock_source=sub"),
        anonymous.call("GET", "/api/v1/products/tidak-ada?stock_source=invalid"),
    ]


def verify_image_file(base, response, expected):
    body = response[1] or {}
    url = (((body.get("data") or {}).get("url")) if isinstance(body, dict) else None)
    if not url or "/storage/" not in url:
        raise RuntimeError(f"image response did not include a storage URL: {response}")
    relative = url.split("/storage/", 1)[1]
    app = "backend" if base == LARAVEL else "go-backend"
    exists = (REPO / app / "storage" / "app" / "public" / relative).is_file()
    if exists != expected:
        raise RuntimeError(f"{app} image file {relative} exists={exists}, expected={expected}")
    return (200, {"file_exists": exists})


SIMPLE = {"name": "Produk Uji", "sku": "SKU-UJI-1", "has_variations": False, "base_price": 45000,
          "weight_grams": 300, "category_id": 2, "status": "active"}


def main():
    seed_full()

    if "--public-stock-only" in sys.argv:
        scenario("public product reads + Agent/Sub stock and source authorization", public_stock_availability)
        failed = [r for r in f.results if not r[1]]
        print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
        return 0 if not failed else 1

    def store_validation(base):
        c = client_for(base, AGEN)
        out = [c.call("POST", "/api/v1/products", {}),
               c.call("POST", "/api/v1/products", {"name": "P1", "has_variations": False}),
               c.call("POST", "/api/v1/products", {"name": "P2", "has_variations": True, "sku": "SKU-P2",
                                                   "base_price": 100, "weight_grams": 10}),
               c.call("POST", "/api/v1/products", {**SIMPLE, "name": ""}),
               c.call("POST", "/api/v1/products", {**SIMPLE, "sku": "SKU-LUMPUR"}),
               c.call("POST", "/api/v1/products", {**SIMPLE, "category_id": 999}),
               c.call("POST", "/api/v1/products", {**SIMPLE, "status": "parkir"}),
               c.call("POST", "/api/v1/products", {**SIMPLE, "weight_grams": 0}),
               client_for(base, KONSUMEN).call("POST", "/api/v1/products", SIMPLE)]
        return out

    scenario("product store validation + RBAC", store_validation)

    def store_simple(base):
        c = client_for(base, AGEN)
        return [c.call("POST", "/api/v1/products", SIMPLE),
                c.call("POST", "/api/v1/products", {**SIMPLE, "sku": "SKU-UJI-2", "name": "Produk Kedua",
                                                    "description": "<p>Paragraf aman</p><script>alert(1)</script>"})]

    scenario("product store simple (+ description sanitised)", store_simple)

    def store_variation_product(base):
        c = client_for(base, AGEN)
        st, resp = c.call("POST", "/api/v1/products", {"name": "Produk Varian", "has_variations": True,
                                                       "category_id": 2, "status": "active"})
        pid = (resp.get("data") or {}).get("id")
        return [(st, resp),
                c.call("POST", f"/api/v1/products/{pid}/variations", {"sku": "VAR-1", "price": 50000, "weight_grams": 250}),
                c.call("POST", "/api/v1/products/999999/variations", {"sku": "VAR-X", "price": 1, "weight_grams": 1})]

    scenario("variation product + first variation", store_variation_product)

    def update_matrix(base):
        c = client_for(base, AGEN)
        return [c.call("PATCH", "/api/v1/products/1", {"name": "Kue Lumpur Baru"}),
                c.call("PATCH", "/api/v1/products/1", {"description": "<b>Tebal</b><script>x()</script>"}),
                c.call("PATCH", "/api/v1/products/1", {"sku": "SKU-COKLAT"}),
                c.call("PATCH", "/api/v1/products/1", {"status": "parkir"}),
                c.call("PATCH", "/api/v1/products/1", {}),
                c.call("PATCH", "/api/v1/products/999", {"name": "X"}),
                c.call("PATCH", "/api/v1/products/1", {"has_variations": True}),
                client_for(base, SALES).call("PATCH", "/api/v1/products/1", {"name": "Dilarang"})]

    scenario("product update matrix", update_matrix)

    def delete_matrix(base):
        out = [client_for(base, ADMIN2).call("DELETE", "/api/v1/products/1"),
               client_for(base, SUPER).call("DELETE", "/api/v1/products/1"),
               client_for(base, SUPER).call("DELETE", "/api/v1/products/999"),
               client_for(base, SUPER).call("DELETE", "/api/v1/products/2"),
               client_for(base, SUPER).call("DELETE", "/api/v1/products/2/permanent", {"confirm": False}),
               client_for(base, SUPER).call("DELETE", "/api/v1/products/2/permanent", {"confirm": True}),
               client_for(base, ADMIN2).call("DELETE", "/api/v1/products/3/permanent", {"confirm": True})]
        return out

    scenario("delete + permanent delete matrix", delete_matrix)

    def fees_matrix(base):
        # {variation} is bound by ID alone in Laravel: a mismatched product/variation pair still resolves,
        # and SetFeeRequest requires ALL THREE fee fields (the 422 below).
        out = [client_for(base, AGEN).call("GET", "/api/v1/products/1/fees"),
               client_for(base, AGEN).call("PUT", "/api/v1/products/1/fees", {"agent_fee": 1500, "sales_fee": 500}),
               client_for(base, AGEN).call("GET", "/api/v1/products/1/fees"),
               client_for(base, SALES).call("PUT", "/api/v1/products/1/fees", {"agent_fee": 1}),
               client_for(base, ADMIN2).call("PUT", "/api/v1/products/1/fees", {"agent_fee": 1}),
               client_for(base, AGEN).call("PUT", "/api/v1/products/1/variations/1/fees",
                                           {"agent_fee": 10, "sales_fee": 20, "courier_fee": 30}),
               client_for(base, AGEN).call("GET", "/api/v1/products/1/variations/1/fees")]
        return out

    scenario("product + variation fees", fees_matrix)


    def variations_and_images(base):
        from compare_payments import multipart
        import struct, zlib
        raw = b"".join(b"\x00" + b"\xff\x00\x00" * 8 for _ in range(8))

        def chunk(kind, data):
            body = kind + data
            return struct.pack(">I", len(data)) + body + struct.pack(">I", zlib.crc32(body) & 0xFFFFFFFF)
        png = (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 8, 8, 8, 2, 0, 0, 0))
               + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))
        c = client_for(base, AGEN)
        out = [
            c.call("POST", "/api/v1/products/2/variations", {"sku": "VAR-A1", "price": 50000,
                                                             "weight_grams": 250, "attributes": ["500 g"]}),
            c.call("POST", "/api/v1/products/2/variations", {"sku": "VAR-A1", "price": 1, "weight_grams": 1,
                                                             "attributes": ["x"]}),
            c.call("POST", "/api/v1/products/2/variations", {"sku": "SKU-LUMPUR", "price": 1, "weight_grams": 1,
                                                             "attributes": ["x"]}),
            c.call("POST", "/api/v1/products/2/variations", {"sku": "VAR-B", "price": 1, "weight_grams": 1}),
            c.call("POST", "/api/v1/products/1/variations", {"sku": "VAR-C", "price": 1, "weight_grams": 1,
                                                             "attributes": ["x"]}),
            multipart(c, "POST", "/api/v1/products/1/images", {"is_primary": "1", "sort_order": "2"},
                      {"image": ("u.png", png)}),
            multipart(c, "POST", "/api/v1/products/1/images", {"product_variation_id": "999"},
                      {"image": ("u.png", png)}),
            c.call("PATCH", "/api/v1/products/1/images/999", {"is_primary": True}),
            c.call("DELETE", "/api/v1/products/1/images/999"),
        ]
        out.append(c.call("PATCH", "/api/v1/products/2/variations/1",
                          {"price": 51000, "weight_grams": 260, "is_active": True}))
        out.append(c.call("DELETE", "/api/v1/products/2/variations/2"))

        image_one = multipart(c, "POST", "/api/v1/products/1/images", {"is_primary": "1", "sort_order": "2"},
                              {"image": ("one.png", png)})
        image_two = multipart(c, "POST", "/api/v1/products/1/images", {"sort_order": "1"},
                              {"image": ("two.png", png)})
        out.extend([image_one, image_two])
        one_id = (image_one[1].get("data") or {}).get("id")
        two_id = (image_two[1].get("data") or {}).get("id")
        if image_one[0] == 201 and image_two[0] == 201 and one_id and two_id:
            out.append(verify_image_file(base, image_two, True))
            out.append(c.call("PATCH", f"/api/v1/products/1/images/{two_id}", {"is_primary": True}))
            out.append(c.call("PUT", "/api/v1/products/1/images/order", {"ids": [two_id, one_id]}))
            out.append(c.call("DELETE", f"/api/v1/products/1/images/{two_id}"))
            out.append(verify_image_file(base, image_two, False))
            out.append(c.call("DELETE", f"/api/v1/products/1/images/{one_id}"))
            out.append(verify_image_file(base, image_one, False))
        return out

    scenario("variations with attributes + image gallery rules", variations_and_images)

    def manage_list(base):
        c = client_for(base, AGEN)
        return [c.call("GET", "/api/v1/manage/products"),
                c.call("GET", "/api/v1/manage/products?status=active&per_page=1&page=2"),
                c.call("GET", "/api/v1/manage/products?status=all&category_id=2&search=SKU-LUMPUR"),
                c.call("GET", "/api/v1/manage/products?category_id=999"),
                c.call("GET", "/api/v1/manage/products?status=invalid"),
                c.call("GET", "/api/v1/manage/products/1"),
                c.call("GET", "/api/v1/manage/products/2"),
                c.call("GET", "/api/v1/manage/products/999999"),
                client_for(base, SALES).call("GET", "/api/v1/manage/products")]

    scenario("manage/products list + detail reads", manage_list)

    scenario("public product reads + Agent/Sub stock and source authorization", public_stock_availability)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
