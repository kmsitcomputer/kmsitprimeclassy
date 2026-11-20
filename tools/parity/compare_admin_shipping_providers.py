#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for the global Admin shipping-provider list and toggle."""
import importlib.util, json, pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    outputs = []
    for base in (r.LARAVEL, r.GO):
        client = r.Client(base)
        if client.login("super@imp010.test") != 200:
            raise RuntimeError("Super Admin login failed")
        before = r.db("SELECT id,code,name,is_active FROM shipping_providers WHERE code IN ('rajaongkir','openroute','kmsit') ORDER BY id")
        listing = client.call("GET", "/api/v1/admin/shipping-providers")
        provider_id, code, _, old_active = before[0]
        audit_id = int(r.db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
        first = client.call("PATCH", f"/api/v1/admin/shipping-providers/{provider_id}/toggle")
        changed = r.db(f"SELECT id,code,name,is_active FROM shipping_providers WHERE id={provider_id}")
        audit = r.db(f"SELECT causer_id,subject_type,subject_id,event,description,CAST(properties AS CHAR) FROM activity_logs WHERE id>{audit_id} AND subject_type LIKE '%ShippingProvider' AND subject_id={provider_id} ORDER BY id")
        for row in audit:
            if row[5]: row[5] = json.loads(row[5])
        second = client.call("PATCH", f"/api/v1/admin/shipping-providers/{provider_id}/toggle")
        restored = r.db(f"SELECT id,code,name,is_active FROM shipping_providers WHERE id={provider_id}")
        consumer = r.Client(base)
        consumer.login("konsumen@imp010.test")
        denied = consumer.call("GET", "/api/v1/admin/shipping-providers")
        outputs.append({"listing":listing,"initial":before,"first":first,"changed":changed,"audit":audit,
                        "second":second,"restored":restored,"denied":denied,"original_active":old_active})
    if outputs[0] != outputs[1]:
        print("admin shipping providers differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    value = outputs[0]
    if value["listing"][0] != 200 or value["first"][0] != 200 or value["second"][0] != 200 or value["denied"][0] != 403:
        raise AssertionError("shipping-provider response or authorization contract failed")
    if len(value["listing"][1]["data"]) != len(value["initial"]) or value["changed"][0][3] == value["original_active"] or value["restored"][0] != value["initial"][0]:
        raise AssertionError("provider filtering, toggle, or restore contract failed")
    if len(value["audit"]) != 1 or value["audit"][0][3] != "shipping_provider.toggled":
        raise AssertionError("provider toggle audit contract failed")
    print("admin shipping providers differential: 6 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
