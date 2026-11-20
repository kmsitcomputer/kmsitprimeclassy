#!/usr/bin/env python3
"""Read-only differential for the Super Admin SMTP settings snapshot."""
import importlib.util,pathlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing": raise RuntimeError("refusing non-testing database")
    out=[]
    for base in (r.LARAVEL,r.GO):
        client=r.Client(base)
        if client.login("super@imp010.test")!=200: raise RuntimeError("Super Admin login failed")
        out.append(client.call("GET","/api/v1/admin/integrations/smtp"))
    if out[0]!=out[1]:
        print("admin SMTP settings differential: FAIL",out)
        return 1
    if out[0][0]!=200 or "password" in out[0][1].get("data",{}): raise AssertionError("SMTP snapshot exposed secret or denied Admin")
    print("admin SMTP settings differential: 3 passed, 0 failed")
    return 0
if __name__=="__main__": raise SystemExit(main())
