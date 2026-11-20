#!/usr/bin/env python3
"""Read-only Laravel ↔ Go differential for the global Admin WhatsApp settings snapshot."""
import importlib.util, json, pathlib

ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec); spec.loader.exec_module(r)


def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing": raise RuntimeError("refusing non-testing database")
    outputs=[]
    for base in (r.LARAVEL,r.GO):
        client=r.Client(base)
        if client.login("super@imp010.test")!=200: raise RuntimeError("Super Admin login failed")
        response=client.call("GET","/api/v1/admin/integrations/whatsapp")
        consumer=r.Client(base); consumer.login("konsumen@imp010.test")
        denied=consumer.call("GET","/api/v1/admin/integrations/whatsapp")
        outputs.append({"snapshot":response,"denied":denied})
    if outputs[0]!=outputs[1]:
        print("admin WhatsApp settings differential: FAIL")
        print(json.dumps(outputs,ensure_ascii=False,sort_keys=True,default=str))
        return 1
    response=outputs[0]["snapshot"]
    if response[0]!=200 or outputs[0]["denied"][0]!=403: raise AssertionError("WhatsApp snapshot authorization failed")
    data=response[1]["data"]
    if "password" in data or not isinstance(data.get("has_password"),bool) or not isinstance(data.get("events"),list):
        raise AssertionError("WhatsApp snapshot exposed a secret or omitted event settings")
    print(f"admin WhatsApp settings differential: 3 passed, 0 failed ({len(data['events'])} events)")
    return 0


if __name__=="__main__": raise SystemExit(main())
