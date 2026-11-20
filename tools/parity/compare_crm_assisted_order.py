#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for CRM assisted order creation.

Uses only a uniquely named synthetic CRM customer and a testing order. Every order side effect,
stock reservation, invoice counter change, and synthetic customer is restored in finally.
"""
import http.cookiejar, json, subprocess, urllib.error, urllib.parse, urllib.request, uuid

LARAVEL="http://127.0.0.1:8899"; GO="http://127.0.0.1:8082"

class Client:
    def __init__(self,base):
        self.base=base; self.jar=http.cookiejar.CookieJar(); self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def call(self,method,path,body=None,headers=None):
        h={"Accept":"application/json","Origin":self.base,"Referer":self.base+"/","X-Requested-With":"XMLHttpRequest"}
        if headers:h.update(headers)
        data=None
        if body is not None:data=json.dumps(body).encode();h["Content-Type"]="application/json"
        token=next((urllib.parse.unquote(c.value) for c in self.jar if c.name=="XSRF-TOKEN"),None)
        if token:h["X-XSRF-TOKEN"]=token
        try:
            with self.opener.open(urllib.request.Request(self.base+path,data=data,headers=h,method=method),timeout=45) as r:
                raw=r.read().decode();return r.status,(json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw=e.read().decode()
            try:return e.code,json.loads(raw)
            except Exception:return e.code,{"raw":raw}
    def login(self,email):
        self.call("GET","/sanctum/csrf-cookie")
        return self.call("POST","/api/v1/auth/login",{"identifier":email,"password":"Secret123!"})[0]

def db(sql):
    sql=sql.replace("`", "\\`")
    cmd=f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    r=subprocess.run(["docker","exec","primeclassy-mariadb","sh","-lc",cmd],capture_output=True,text=True,timeout=90)
    if r.returncode:raise RuntimeError(r.stderr[:500])
    return [line.split("\t") for line in r.stdout.strip().splitlines() if line]

def norm(v):
    if isinstance(v,dict):
        out={}
        for k,x in v.items():
            if k in {"id","order_no","created_at","payment_transaction","viewer_can_download_invoice","shipment_id"}:continue
            if k in {"item_ids","shipment_ids"} and isinstance(x,list):out[k]=f"<list of {len(x)}>"
            elif k=="shipment_delivery_dates" and isinstance(x,dict):out[k]=[norm(y) for _,y in sorted(x.items())]
            else:out[k]=norm(x)
        return out
    if isinstance(v,list):return [norm(x) for x in v]
    if isinstance(v,(int,float)) and not isinstance(v,bool):return round(float(v),8)
    if isinstance(v,str) and len(v)>=20 and v[4:5]=="-" and "T" in v:return "<timestamp>"
    return v

def diff(a,b,path="$"):
    if isinstance(a,dict) and isinstance(b,dict):
        out=[]
        for k in sorted(set(a)|set(b)):
            if k not in a or k not in b:out.append(f"{path}.{k}: missing on {'Laravel' if k not in a else 'Go'}")
            else:out.extend(diff(a[k],b[k],path+"."+k))
        return out
    if isinstance(a,list) and isinstance(b,list):
        out=[]
        if len(a)!=len(b):return [f"{path}: list length {len(a)} vs {len(b)}"]
        for i,(x,y) in enumerate(zip(a,b)):out.extend(diff(x,y,f"{path}[{i}]"))
        return out
    if isinstance(a,(int,float)) and isinstance(b,(int,float)) and not isinstance(a,bool) and not isinstance(b,bool):return [] if abs(a-b)<1e-9 else [f"{path}: {a!r} vs {b!r}"]
    return [] if a==b else [f"{path}: {a!r} vs {b!r}"]

def order_state(order_id):
    return {
      "order":db(f"SELECT status,payment_status,CAST(subtotal_amount AS CHAR),CAST(shipping_fee_amount AS CHAR),CAST(admin_fee_amount AS CHAR),CAST(discount_amount AS CHAR),CAST(total_amount AS CHAR),konsumen_id,crm_customer_id,placed_by_user_id,buyer_name_snapshot,buyer_phone_snapshot,sales_id,korsal_id,recipient_name_snapshot,recipient_phone_snapshot,address_snapshot,village_snapshot FROM orders WHERE id={order_id}"),
      "items":db(f"SELECT product_id,product_variation_id,stock_source,product_name_snapshot,sku_snapshot,CAST(unit_price_snapshot AS CHAR),CAST(agent_fee_amount AS CHAR),CAST(sales_fee_amount AS CHAR),CAST(courier_fee_amount AS CHAR),CAST(subtotal_snapshot AS CHAR),original_quantity,fulfilled_quantity,status FROM order_items WHERE order_id={order_id} ORDER BY id"),
      "shipments":db(f"SELECT shipping_provider_code,status,delivery_mode,CAST(shipping_fee_snapshot AS CHAR) FROM shipments WHERE order_id={order_id} ORDER BY id"),
      "commissions":db(f"SELECT beneficiary_role,beneficiary_user_id,CAST(amount AS CHAR),status FROM commissions WHERE order_id={order_id} ORDER BY id"),
      "payments":db(f"SELECT type,CAST(amount AS CHAR),status FROM payment_transactions WHERE order_id={order_id} ORDER BY id"),
      "snapshots":db(f"SELECT initial_quantity,CAST(unit_price_snapshot AS CHAR),CAST(invoice_total_snapshot AS CHAR),initial_payment_status,payment_method_code_snapshot,item_position FROM order_initial_report_items WHERE order_id={order_id} ORDER BY id"),
      "reservations":db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3"),
    }

def cleanup(order_id,customer_id,old_reserved,old_seq,seq_existed,name):
    if order_id:
        item_ids=db(f"SELECT id FROM order_items WHERE order_id={order_id}")
        ids=",".join(x[0] for x in item_ids) or "0"
        # Only the rows created for this synthetic order are removed.
        for sql in [
          f"DELETE FROM activity_logs WHERE (subject_id={order_id} AND subject_type LIKE '%Order') OR (subject_id IN ({ids}) AND subject_type LIKE '%OrderItem')",
          f"DELETE FROM order_initial_report_items WHERE order_id={order_id}",
          f"DELETE FROM stock_request_items WHERE stock_request_id IN (SELECT id FROM stock_requests WHERE order_id={order_id})",
          f"DELETE FROM stock_requests WHERE order_id={order_id}",
          f"DELETE FROM commissions WHERE order_id={order_id}",
          f"DELETE FROM payment_transactions WHERE order_id={order_id}",
          f"DELETE FROM stock_movements WHERE reference_type='order' AND reference_id={order_id} AND type='reserve'",
          f"DELETE FROM shipments WHERE order_id={order_id}",
          f"DELETE FROM order_items WHERE order_id={order_id}",
          f"DELETE FROM orders WHERE id={order_id}",
        ]:db(sql)
    db(f"UPDATE product_stocks SET quantity_reserved={old_reserved} WHERE agent_id=10 AND product_id=3")
    if seq_existed:db(f"UPDATE settings SET `value`='{old_seq}' WHERE `key`='orders.invoice_sequence'")
    else:db("DELETE FROM settings WHERE `key`='orders.invoice_sequence'")
    db(f"DELETE FROM crm_customers WHERE id={customer_id} AND name='{name}'")

def run(base,customer_id,name,key):
    client=Client(base)
    if client.login("agen@imp010.test")!=200:raise RuntimeError(f"login failed: {base}")
    body={"items":[{"product_id":3,"quantity":1}],"payment_method_code":"bank_transfer","shipping_method":"pickup",
          "recipient_name":"Synthetic CRM Recipient","recipient_phone":"081200000001","address_line":"Parity test address",
          "village_id":"32730101","latitude":-6.9,"longitude":107.6}
    return client.call("POST",f"/api/v1/crm/customers/{customer_id}/orders",body,{"Idempotency-Key":key})

def main():
    suffix=uuid.uuid4().hex[:12]; name="Codex CRM parity "+suffix; key="codex-crm-"+suffix
    prior=db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0]
    seq=db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'"); seq_existed=bool(seq); old_seq=seq[0][0] if seq else "0"
    db(f"INSERT INTO crm_customers(agent_id,owner_user_id,name,phone,created_at,updated_at) VALUES(10,14,'{name}','081200000099',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
    cid=int(db(f"SELECT id FROM crm_customers WHERE name='{name}' ORDER BY id DESC LIMIT 1")[0][0])
    captured=[]; failures=[]; checks=0
    try:
        for runtime in (LARAVEL,GO):
            runtime_key=key+runtime[-4:]
            status,body=run(runtime,cid,name,runtime_key)
            if status!=201 or not body.get("data"):
                failures.append(f"{runtime}: HTTP {status} {json.dumps(body)[:600]}");captured.append(None);continue
            oid=int(body["data"]["id"])
            replay_status,replay=run(runtime,cid,name,runtime_key)
            if replay_status!=201 or not replay.get("data") or replay["data"].get("id")!=oid:failures.append(f"{runtime}: idempotent replay diverged")
            else:checks+=1
            captured.append((norm(body["data"]),order_state(oid)))
            cleanup(oid,cid,prior,old_seq,seq_existed,name)
            # The customer is required for the second runtime; recreate with the same numeric id is impossible,
            # so retain it between runs and defer its deletion until finally.
            db(f"INSERT INTO crm_customers(id,agent_id,owner_user_id,name,phone,created_at,updated_at) VALUES({cid},10,14,'{name}','081200000099',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
        if len(captured)==2 and all(captured):
            checks+=1
            if captured[0][0]!=captured[1][0]:failures.append("response mismatch: "+"; ".join(diff(captured[0][0],captured[1][0])[:20]))
            checks+=1
            if captured[0][1]!=captured[1][1]:failures.append("database state mismatch: "+json.dumps(captured[0][1],sort_keys=True)+" vs "+json.dumps(captured[1][1],sort_keys=True))
        # At minimum compare role denial on both runtimes.
        denied=[]
        for runtime in (LARAVEL,GO):
            c=Client(runtime);c.login("admin@imp010.test")
            denied.append(c.call("POST",f"/api/v1/crm/customers/{cid}/orders",{"items":[],"payment_method_code":"bank_transfer"},{"Idempotency-Key":key+"deny"})[0])
        checks+=1
        if denied!=[403,403]:failures.append(f"role denial differs: {denied}")
        db(f"UPDATE crm_customers SET archived_at=UTC_TIMESTAMP(6) WHERE id={cid}")
        archived=[]
        for runtime in (LARAVEL,GO):archived.append(run(runtime,cid,name,key+"archived"))
        checks+=1
        if [x[0] for x in archived]!=[422,422] or archived[0][1].get("message")!=archived[1][1].get("message"):
            failures.append(f"archived customer behavior differs: {archived}")
        db(f"UPDATE crm_customers SET archived_at=NULL WHERE id={cid}")
        missing=[]
        for runtime in (LARAVEL,GO):missing.append(run(runtime,cid+999999,name,key+"missing"))
        checks+=1
        if [x[0] for x in missing]!=[404,404]:failures.append(f"binding-miss behavior differs: {[x[0] for x in missing]}")
    finally:
        # Discover and clean any fixture order left by an interrupted or failed assertion.
        rows=db(f"SELECT id FROM orders WHERE crm_customer_id={cid} AND idempotency_key LIKE '{key}%'")
        for row in rows:cleanup(int(row[0]),cid,prior,old_seq,seq_existed,name)
        db(f"DELETE FROM crm_customers WHERE id={cid} AND name='{name}'")
    if failures:
        print(f"crm assisted order differential: {checks-len(failures)} passed, {len(failures)} failed")
        for f in failures:print("FAIL "+f)
        return 1
    print(f"crm assisted order differential: {checks} passed, 0 failed")
    return 0

if __name__=="__main__":raise SystemExit(main())
