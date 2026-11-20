#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for the Admin shipping-courier master list."""
import importlib.util, json, pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    outputs = []
    for base in (r.LARAVEL, r.GO):
        admin = r.Client(base)
        if admin.login("super@imp010.test") != 200: raise RuntimeError("Super Admin login failed")
        consumer = r.Client(base); consumer.login("konsumen@imp010.test")
        outputs.append({"list":admin.call("GET","/api/v1/admin/shipping-couriers"),
                        "denied":consumer.call("GET","/api/v1/admin/shipping-couriers")})
    if outputs[0] != outputs[1]:
        print("admin shipping couriers differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    if outputs[0]["list"][0] != 200 or outputs[0]["denied"][0] != 403:
        raise AssertionError("courier list or role authorization contract failed")
    print("admin shipping couriers differential: 2 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
