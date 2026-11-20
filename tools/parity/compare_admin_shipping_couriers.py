#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for the Admin shipping-courier master list."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    outputs = []
    marker = uuid.uuid4().hex[:10]
    for base in (r.LARAVEL, r.GO):
        admin = r.Client(base)
        if admin.login("super@imp010.test") != 200: raise RuntimeError("Super Admin login failed")
        consumer = r.Client(base); consumer.login("konsumen@imp010.test")
        missing = admin.call("POST", "/api/v1/admin/shipping-couriers", json.dumps({"name":"Missing Code"}).encode(), {"Content-Type":"application/json"})
        regex = admin.call("POST", "/api/v1/admin/shipping-couriers", json.dumps({"code":"Bad Code","name":"Invalid Code"}).encode(), {"Content-Type":"application/json"})
        duplicate = admin.call("POST", "/api/v1/admin/shipping-couriers", json.dumps({"code":"jne","name":"Duplicate"}).encode(), {"Content-Type":"application/json"})
        audit_before = int(r.db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
        created = admin.call("POST", "/api/v1/admin/shipping-couriers", json.dumps({"code":"codex-"+marker,"name":"Codex Parity Courier"}).encode(), {"Content-Type":"application/json"})
        cid = created[1]["data"]["id"] if created[0] == 201 else 0
        toggled = admin.call("PATCH", f"/api/v1/admin/shipping-couriers/{cid}/toggle")
        state = r.db(f"SELECT code,name,is_active FROM shipping_couriers WHERE id={cid}") if cid else []
        audit = r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{audit_before} AND subject_type LIKE '%ShippingCourier' AND subject_id={cid} ORDER BY id") if cid else []
        for row in audit:
            if row[1]: row[1] = json.loads(row[1])
        listing = admin.call("GET","/api/v1/admin/shipping-couriers")
        denied = consumer.call("GET","/api/v1/admin/shipping-couriers")
        outputs.append({"list":listing,"denied":denied,"missing":missing,"regex":regex,"duplicate":duplicate,
                        "created":created,"toggled":toggled,"state":state,"audit":audit})
        if cid:
            r.db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ShippingCourier' AND subject_id={cid}")
            r.db(f"DELETE FROM shipping_couriers WHERE id={cid}")

    def normalize(value):
        if isinstance(value, dict): return {k:("<timestamp>" if k in {"created_at","updated_at"} else "<id>" if k == "id" else normalize(v)) for k,v in value.items()}
        if isinstance(value, (list, tuple)): return [normalize(v) for v in value]
        return value
    outputs = [normalize(value) for value in outputs]
    if outputs[0] != outputs[1]:
        print("admin shipping couriers differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    value=outputs[0]
    if value["list"][0] != 200 or value["denied"][0] != 403 or value["missing"][0] != 422 or value["regex"][0] != 422 or value["duplicate"][0] != 422:
        raise AssertionError("courier list, validation, or role authorization contract failed")
    if value["created"][0] != 201 or value["toggled"][0] != 200 or not value["state"] or value["state"][0][2] != "0":
        raise AssertionError("courier create or toggle contract failed")
    if len(value["audit"]) != 2 or [row[0] for row in value["audit"]] != ["shipping_courier.created","shipping_courier.toggled"]:
        raise AssertionError("courier audit contract failed")
    print("admin shipping couriers differential: 10 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
