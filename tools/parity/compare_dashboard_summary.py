#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for dashboard summary scopes."""
import importlib.util,json,pathlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def main():
    out=[]
    try:
        for base in (r.LARAVEL,r.GO):
            scenarios=[]
            for email,suffix in [("super@imp010.test","?agent_id=10"),("super@imp010.test","?from=2026-01-01&to=2026-12-31"),("agen@imp010.test",""),("admin@imp010.test","?agent_id=999"),("sales@imp010.test","?from=2026-01-01"),("sub@imp010.test","?to=2026-12-31")]:
                c=r.Client(base);status=c.login(email)
                if status!=200:raise RuntimeError(f"fixture login failed for {email} at {base}: {status}")
                scenarios.append(c.call("GET","/api/v1/dashboard/summary"+suffix))
            finance=r.Client(base);finance.login("keuangan@imp010.test");denied=finance.call("GET","/api/v1/dashboard/summary")
            out.append({"scenarios":scenarios,"denied":denied})
    except Exception as exc:
        print(f"dashboard summary differential harness error: {exc}");return 2
    if out[0]!=out[1]:print("dashboard summary differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("dashboard summary differential: 7 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
