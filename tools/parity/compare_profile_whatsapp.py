#!/usr/bin/env python3
"""Read-only Laravel ↔ Go differential for authenticated WhatsApp-number state."""
import importlib.util, json, pathlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing": raise RuntimeError("refusing non-testing database")
    results=[]
    for base in (r.LARAVEL,r.GO):
        client=r.Client(base)
        if client.login("agen@imp010.test")!=200: raise RuntimeError(f"Agen login failed at {base}")
        results.append(client.call("GET","/api/v1/profile/whatsapp"))
        results.append(r.Client(base).call("GET","/api/v1/profile/whatsapp"))
    laravel,go=results[0],results[2]
    if laravel!=go:
        print("profile WhatsApp differential: FAIL");print(json.dumps(results,ensure_ascii=False,sort_keys=True,default=str));return 1
    if laravel[0]!=200 or set(laravel[1].get("data",{}))!={"has_phone","phone_masked","verified","channel_available","pending"}:
        raise AssertionError("unexpected WhatsApp state contract: "+repr(laravel))
    if results[1][0]!=401 or results[3][0]!=401: raise AssertionError("unauthenticated access was not denied")
    print("profile WhatsApp differential: 2 authenticated state comparisons + 2 unauthenticated checks passed, 0 failed")
    return 0
if __name__=="__main__": raise SystemExit(main())
