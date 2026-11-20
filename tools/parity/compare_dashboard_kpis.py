#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for role-shaped dashboard KPIs."""
import importlib.util, json, pathlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

def main():
    out=[]
    token=__import__("uuid").uuid4().hex[:12]
    kemail=f"imp010-kpi-korsal-{token}@test.invalid"; semail=f"imp010-kpi-sales-{token}@test.invalid"
    order_id=int(r.db("SELECT id FROM orders WHERE agent_id=10 ORDER BY id LIMIT 1")[0][0])
    commission_ids=[]
    r.db(f"INSERT INTO users(role_id,agent_id,name,email,password,status,created_at,updated_at) SELECT (SELECT id FROM roles WHERE slug='korsal'),10,'Parity KPI Korsal {token}','{kemail}',password,'active',NOW(),NOW() FROM users WHERE email='sales@imp010.test'")
    korsal_id=int(r.db(f"SELECT id FROM users WHERE email='{kemail}'")[0][0])
    r.db(f"INSERT INTO users(role_id,agent_id,korsal_id,name,email,password,status,created_at,updated_at) SELECT (SELECT id FROM roles WHERE slug='sales'),10,{korsal_id},'Parity KPI Sales {token}','{semail}',password,'active',NOW(),NOW() FROM users WHERE email='sales@imp010.test'")
    downline_id=int(r.db(f"SELECT id FROM users WHERE email='{semail}'")[0][0])
    sales_id=int(r.db("SELECT id FROM users WHERE email='sales@imp010.test'")[0][0])
    for user_id,role,amount in [(10,"agent",123.45),(sales_id,"sales",27.50),(korsal_id,"sales",5.25),(downline_id,"sales",4.75)]:
        r.db(f"INSERT INTO commissions(order_id,beneficiary_user_id,beneficiary_role,amount,status,earned_at,created_at,updated_at) VALUES ({order_id},{user_id},'{role}',{amount},'pending',NOW(),NOW(),NOW())")
        commission_ids.append(int(r.db(f"SELECT id FROM commissions WHERE order_id={order_id} AND beneficiary_user_id={user_id} AND amount={amount} ORDER BY id DESC LIMIT 1")[0][0]))
    try:
        for base in (r.LARAVEL,r.GO):
            cases=[]
            for email,query in [("agen@imp010.test",""),("sales@imp010.test","?from=2026-01-01&to=2026-12-31"),("sub@imp010.test",""),("gudang@imp010.test",""),("kurir@imp010.test",""),(kemail,"")]:
                c=r.Client(base);status=c.login(email)
                if status!=200:raise RuntimeError(f"fixture login failed for {email} at {base}: {status}")
                cases.append(c.call("GET","/api/v1/dashboard/kpis"+query))
            denied=r.Client(base);denied.login("keuangan@imp010.test");forbidden=denied.call("GET","/api/v1/dashboard/kpis")
            out.append({"cases":cases,"forbidden":forbidden})
    except Exception as exc:
        print(f"dashboard KPI differential harness error: {exc}");return 2
    finally:
        if commission_ids:
            r.db("DELETE FROM commissions WHERE id IN ("+",".join(map(str,commission_ids))+")")
        r.db(f"DELETE FROM users WHERE email='{semail}'")
        r.db(f"DELETE FROM users WHERE email='{kemail}'")
    if out[0]!=out[1]:
        print("dashboard KPI differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    if any(status!=200 for status,_ in out[0]["cases"]) or out[0]["forbidden"][0]!=403:
        print("dashboard KPI status contract: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    agent_data=out[0]["cases"][0][1]["data"]["own_fee"]
    sales_data=out[0]["cases"][1][1]["data"]["commission"]
    korsal_data=out[0]["cases"][5][1]["data"]["team_commissions"]
    if agent_data["total"]!=123.45 or agent_data["by_role"].get("agent")!=123.45 or sales_data["total"]!=27.5:
        raise AssertionError("non-zero commission KPI fixture was not included")
    if len(korsal_data["members"])!=2 or korsal_data["totals"]["total"]!=10.0:
        raise AssertionError("Korsal team commission scope fixture mismatch: "+repr(korsal_data))
    print("dashboard KPI differential: 8 comparisons passed, 0 failed")
    return 0
if __name__=="__main__":raise SystemExit(main())
