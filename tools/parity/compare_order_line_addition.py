#!/usr/bin/env python3
"""Scoped differential for Admin-only SC-03 order line addition.

Creates one isolated COD order through the Laravel test API. Each runtime then receives the same
line-addition request; line effects are removed between runs and the synthetic order is fully cleaned
up afterward. Only primeclassy_testing is touched.
"""
import datetime, http.cookiejar, json, subprocess, time, urllib.error, urllib.parse, urllib.request, uuid

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"

class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def call(self, method, path, body=None, headers=None):
        h = {"Accept":"application/json", "Origin":self.base, "Referer":self.base+"/", "X-Requested-With":"XMLHttpRequest"}
        if headers: h.update(headers)
        data = None
        if body is not None: data=json.dumps(body).encode(); h["Content-Type"]="application/json"
        token = next((urllib.parse.unquote(c.value) for c in self.jar if c.name=="XSRF-TOKEN"), None)
        if token: h["X-XSRF-TOKEN"] = token
        try:
            with self.opener.open(urllib.request.Request(self.base+path,data=data,headers=h,method=method),timeout=45) as r:
                raw=r.read().decode(); return r.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw=e.read().decode()
            try: return e.code,json.loads(raw)
            except Exception: return e.code,{"raw":raw}
    def login(self,email):
        for _ in range(10):
            try:
                self.call("GET","/sanctum/csrf-cookie")
                return self.call("POST","/api/v1/auth/login",{"identifier":email,"password":"Secret123!"})[0]
            except OSError:
                time.sleep(1)
        return 0

def db(sql):
    sql=sql.replace("`", "\\`")
    cmd=f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    r=subprocess.run(["docker","exec","primeclassy-mariadb","sh","-lc",cmd],capture_output=True,text=True,timeout=90)
    if r.returncode: raise RuntimeError(r.stderr[:600])
    return [line.split("\t") for line in r.stdout.strip().splitlines() if line]

def scalar(sql,default=""):
    rows=db(sql); return rows[0][0] if rows else default

def norm(v):
    if isinstance(v,dict):
        out={}
        for k,x in v.items():
            if k in {"id","order_no","created_at","updated_at","shipment_id","payment_transaction","viewer_can_download_invoice"}: continue
            if k in {"item_ids","shipment_ids"} and isinstance(x,list): out[k]=f"<list of {len(x)}>"
            elif k=="shipment_delivery_dates" and isinstance(x,dict): out[k]=[norm(value) for _,value in sorted(x.items())]
            else: out[k]=norm(x)
        return out
    if isinstance(v,list): return [norm(x) for x in v]
    if isinstance(v,(int,float)) and not isinstance(v,bool): return round(float(v),8)
    if isinstance(v,str) and len(v)>19 and v[4:5]=="-" and "T" in v: return "<timestamp>"
    return v

def diff(a,b,path="$",out=None):
    if out is None: out=[]
    if isinstance(a,dict) and isinstance(b,dict):
        for k in sorted(set(a)|set(b)):
            if k not in a: out.append(f"{path}.{k}: missing Laravel")
            elif k not in b: out.append(f"{path}.{k}: missing Go")
            else: diff(a[k],b[k],path+"."+k,out)
    elif isinstance(a,list) and isinstance(b,list):
        if len(a)!=len(b): out.append(f"{path}: list length {len(a)} vs {len(b)}")
        else:
            for i,(x,y) in enumerate(zip(a,b)): diff(x,y,f"{path}[{i}]",out)
    elif isinstance(a,(int,float)) and isinstance(b,(int,float)) and not isinstance(a,bool) and not isinstance(b,bool):
        if abs(a-b)>1e-9: out.append(f"{path}: {a!r} vs {b!r}")
    elif a!=b: out.append(f"{path}: {a!r} vs {b!r}")
    return out

def state(order_id, key):
    item_id=scalar(f"SELECT id FROM order_items WHERE order_id={order_id} AND idempotency_key='{key}'","0")
    return {
      "order":db(f"SELECT status,payment_status,CAST(subtotal_amount AS CHAR),CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(remaining_amount AS CHAR) FROM orders WHERE id={order_id}"),
      "added_item":db(f"SELECT product_id,product_variation_id,stock_source,sku_snapshot,CAST(unit_price_snapshot AS CHAR),CAST(agent_fee_amount AS CHAR),CAST(sales_fee_amount AS CHAR),CAST(courier_fee_amount AS CHAR),CAST(subtotal_snapshot AS CHAR),original_quantity,fulfilled_quantity,status,requested_delivery_date,idempotency_key,request_fingerprint FROM order_items WHERE id={item_id}"),
      "all_items":db(f"SELECT product_id,product_variation_id,stock_source,CAST(subtotal_snapshot AS CHAR),original_quantity,fulfilled_quantity,status,requested_delivery_date FROM order_items WHERE order_id={order_id} ORDER BY id"),
      "shipments":db(f"SELECT s.status,s.delivery_mode,COUNT(i.id) FROM shipments s LEFT JOIN order_items i ON i.shipment_id=s.id WHERE s.order_id={order_id} GROUP BY s.id,s.status,s.delivery_mode ORDER BY s.id"),
      "stock_request":db(f"SELECT r.status,i.product_id,i.requested_qty,i.fulfilled_qty,i.remaining_qty FROM stock_requests r LEFT JOIN stock_request_items i ON i.stock_request_id=r.id WHERE r.order_id={order_id} ORDER BY i.id"),
      "commissions":db(f"SELECT beneficiary_role,beneficiary_user_id,CAST(amount AS CHAR),status FROM commissions WHERE order_id={order_id} ORDER BY order_item_id,beneficiary_role"),
      "additional_payments":db(f"SELECT method,CAST(amount AS CHAR),reason,status FROM order_additional_payments WHERE order_id={order_id} ORDER BY id"),
      "payment_transactions":db(f"SELECT type,CAST(amount AS CHAR),status FROM payment_transactions WHERE order_id={order_id} ORDER BY id"),
      "reservation":db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3"),
      "movements":db(f"SELECT product_id,type,quantity,stock_type,reference_type FROM stock_movements WHERE reference_type='order' AND reference_id={order_id} ORDER BY id"),
      "audit":db(f"SELECT event,description FROM activity_logs WHERE subject_id IN (SELECT id FROM order_items WHERE order_id={order_id}) OR subject_id IN (SELECT id FROM shipments WHERE order_id={order_id}) ORDER BY id"),
    }

def create_test_order(client, key):
    body={"items":[{"product_id":3,"quantity":1}],"shipping_method":"pickup","payment_method_code":"cod",
          "recipient_name":"Synthetic SC03","recipient_phone":"081200000001","address_line":"Parity test address",
          "village_id":"32730101","latitude":-6.9,"longitude":107.6}
    status,response=client.call("POST","/api/v1/orders",body,{"Idempotency-Key":key})
    if status!=201 or not response or not response.get("data"):
        raise RuntimeError(f"synthetic order create failed: HTTP {status} {json.dumps(response)[:700]}")
    return int(response["data"]["id"])

def clean_line(order_id,key,old_reservation,old_order,old_request,activity_marker,movement_marker,payment_marker,additional_marker):
    item= db(f"SELECT id,shipment_id FROM order_items WHERE order_id={order_id} AND idempotency_key='{key}'")
    if item:
        item_id=int(item[0][0]); shipment_id=int(item[0][1] or 0)
        db(f"DELETE FROM stock_request_items WHERE order_item_id={item_id}")
        db(f"DELETE FROM commissions WHERE order_item_id={item_id}")
        db(f"DELETE FROM stock_movements WHERE id>{movement_marker} AND reference_type='order' AND reference_id={order_id}")
        db(f"DELETE FROM activity_logs WHERE id>{activity_marker} AND (subject_id={item_id} OR subject_id={shipment_id} OR (event='shipment.added_for_line' AND properties LIKE '%\"order_id\":{order_id}%'))")
        db(f"DELETE FROM order_items WHERE id={item_id}")
        if shipment_id:
            db(f"DELETE FROM shipments WHERE id={shipment_id} AND order_id={order_id} AND NOT EXISTS(SELECT 1 FROM order_items WHERE shipment_id={shipment_id})")
    db(f"DELETE FROM order_additional_payments WHERE order_id={order_id} AND id>{additional_marker}")
    db(f"DELETE FROM payment_transactions WHERE order_id={order_id} AND id>{payment_marker}")
    db(f"UPDATE stock_request_items sri JOIN stock_requests sr ON sr.id=sri.stock_request_id JOIN order_items oi ON oi.id=sri.order_item_id SET sri.fulfilled_qty=0,sri.remaining_qty=sri.requested_qty WHERE sr.order_id={order_id} AND oi.idempotency_key IS NULL")
    db(f"UPDATE product_stocks SET quantity_reserved={old_reservation} WHERE agent_id=10 AND product_id=3")
    db(f"UPDATE orders SET subtotal_amount='{old_order[0]}',total_amount='{old_order[1]}',paid_amount='{old_order[2]}',gross_received_amount='{old_order[3]}',remaining_amount='{old_order[4]}',payment_status='{old_order[5]}',updated_at=UTC_TIMESTAMP() WHERE id={order_id}")
    db(f"UPDATE stock_requests SET status='{old_request[0]}',fulfilled_at={('NULL' if old_request[1]=='__NULL__' else chr(39)+old_request[1]+chr(39))},updated_at=UTC_TIMESTAMP() WHERE order_id={order_id}")

def clean_order(order_id,base_reservation,sequence,sequence_existed):
    item_ids=db(f"SELECT id FROM order_items WHERE order_id={order_id}"); ids=",".join(x[0] for x in item_ids) or "0"
    for sql in [
      f"DELETE FROM activity_logs WHERE subject_id={order_id} OR subject_id IN ({ids}) OR subject_id IN (SELECT id FROM shipments WHERE order_id={order_id})",
      f"DELETE FROM order_initial_report_items WHERE order_id={order_id}",
      f"DELETE FROM stock_request_items WHERE stock_request_id IN (SELECT id FROM stock_requests WHERE order_id={order_id})",
      f"DELETE FROM stock_requests WHERE order_id={order_id}",
      f"DELETE FROM commissions WHERE order_id={order_id}",
      f"DELETE FROM order_additional_payments WHERE order_id={order_id}",
      f"DELETE FROM payment_transactions WHERE order_id={order_id}",
      f"DELETE FROM stock_movements WHERE reference_type='order' AND reference_id={order_id}",
      f"DELETE FROM shipments WHERE order_id={order_id}",
      f"DELETE FROM order_items WHERE order_id={order_id}",
      f"DELETE FROM orders WHERE id={order_id}",
    ]: db(sql)
    db(f"UPDATE product_stocks SET quantity_reserved={base_reservation} WHERE agent_id=10 AND product_id=3")
    if sequence_existed: db(f"UPDATE settings SET `value`='{sequence}' WHERE `key`='orders.invoice_sequence'")
    else: db("DELETE FROM settings WHERE `key`='orders.invoice_sequence'")

def compare_error(label,path,body,headers,laravel,go):
    ls,lb=laravel.call("POST",path,body,headers); gs,gb=go.call("POST",path,body,headers)
    if ls!=gs or norm(lb)!=norm(gb): return False,f"{label}: Laravel HTTP {ls} {lb}; Go HTTP {gs} {gb}"
    return True,""

def main():
    suffix=uuid.uuid4().hex[:10]; order_key="codex-sc03-order-"+suffix; line_key="codex-sc03-line-"+suffix
    base_res=int(scalar("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3","0"))
    seq_rows=db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'"); seq_exists=bool(seq_rows); sequence=seq_rows[0][0] if seq_rows else "0"
    creator=Client(LARAVEL); laravel=Client(LARAVEL); go=Client(GO)
    if creator.login("agen@imp010.test")!=200 or laravel.login("admin@imp010.test")!=200 or go.login("admin@imp010.test")!=200:
        raise RuntimeError("test account login failed")
    order_id=None; failures=[]; checks=0; captures=[]
    try:
        order_id=create_test_order(creator,order_key)
        old_order=db(f"SELECT CAST(subtotal_amount AS CHAR),CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(gross_received_amount AS CHAR),CAST(remaining_amount AS CHAR),payment_status FROM orders WHERE id={order_id}")[0]
        old_request=db(f"SELECT status,COALESCE(DATE_FORMAT(fulfilled_at,'%Y-%m-%d %H:%i:%s'),'__NULL__') FROM stock_requests WHERE order_id={order_id}")[0]
        if not db(f"SELECT id FROM stock_requests WHERE order_id={order_id}"):
            raise RuntimeError("synthetic COD order has no Stock Request")
        body={"product_id":3,"quantity":1,"reason":"SC-03 parity differential","additional_payment_method":"transfer"}
        for runtime,client in (("Laravel",laravel),("Go",go)):
            reserve=int(scalar("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3","0"))
            act=int(scalar("SELECT COALESCE(MAX(id),0) FROM activity_logs","0")); move=int(scalar("SELECT COALESCE(MAX(id),0) FROM stock_movements","0"))
            pay=int(scalar("SELECT COALESCE(MAX(id),0) FROM payment_transactions","0")); additional=int(scalar("SELECT COALESCE(MAX(id),0) FROM order_additional_payments","0"))
            st,res=client.call("POST",f"/api/v1/orders/{order_id}/items",body,{"Idempotency-Key":line_key})
            if st!=201 or not res or not res.get("data"):
                failures.append(f"{runtime} create HTTP {st}: {json.dumps(res)[:500]}"); continue
            captures.append((norm(res["data"]),state(order_id,line_key)))
            replay_st,replay=client.call("POST",f"/api/v1/orders/{order_id}/items",body,{"Idempotency-Key":line_key})
            checks+=1
            if replay_st!=200 or not replay or replay.get("data",{}).get("id")!=res.get("data",{}).get("id"):
                failures.append(f"{runtime} idempotent replay mismatch: HTTP {replay_st}")
            conflict=dict(body); conflict["quantity"]=2
            conflict_st,_=client.call("POST",f"/api/v1/orders/{order_id}/items",conflict,{"Idempotency-Key":line_key})
            checks+=1
            if conflict_st!=409: failures.append(f"{runtime} same-key conflict returned {conflict_st}, expected 409")
            clean_line(order_id,line_key,reserve,old_order,old_request,act,move,pay,additional)
        # A fully settled order must create the same additional-payment obligation and payment transaction.
        db(f"UPDATE orders SET paid_amount=total_amount,gross_received_amount=total_amount,remaining_amount=0,payment_status='paid' WHERE id={order_id}")
        settled_order=db(f"SELECT CAST(subtotal_amount AS CHAR),CAST(total_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(gross_received_amount AS CHAR),CAST(remaining_amount AS CHAR),payment_status FROM orders WHERE id={order_id}")[0]
        settled_key=line_key+"-settled"; settled_body={"product_id":3,"quantity":1,"reason":"SC-03 settled order parity","additional_payment_method":"transfer"}; settled=[]
        for runtime,client in (("Laravel",laravel),("Go",go)):
            reserve=int(scalar("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3","0"))
            act=int(scalar("SELECT COALESCE(MAX(id),0) FROM activity_logs","0")); move=int(scalar("SELECT COALESCE(MAX(id),0) FROM stock_movements","0"))
            pay=int(scalar("SELECT COALESCE(MAX(id),0) FROM payment_transactions","0")); additional=int(scalar("SELECT COALESCE(MAX(id),0) FROM order_additional_payments","0"))
            st,res=client.call("POST",f"/api/v1/orders/{order_id}/items",settled_body,{"Idempotency-Key":settled_key})
            checks+=1
            if st!=201 or not res or not res.get("data"):
                failures.append(f"settled order {runtime} create HTTP {st}: {json.dumps(res)[:500]}")
                clean_line(order_id,settled_key,reserve,settled_order,old_request,act,move,pay,additional)
                continue
            settled.append((norm(res["data"]),state(order_id,settled_key)))
            if not db(f"SELECT id FROM order_additional_payments WHERE order_id={order_id} AND status='pending' AND reason='SC-03 settled order parity'"):
                failures.append(f"settled order {runtime} did not create its additional-payment obligation")
            clean_line(order_id,settled_key,reserve,settled_order,old_request,act,move,pay,additional)
        checks+=1
        if len(settled)==2 and settled[0][0]!=settled[1][0]: failures.append("settled order response mismatch: "+"; ".join(diff(settled[0][0],settled[1][0])[:20]))
        checks+=1
        if len(settled)==2 and settled[0][1]!=settled[1][1]: failures.append("settled order database mismatch: "+json.dumps(settled[0][1],sort_keys=True)+" vs "+json.dumps(settled[1][1],sort_keys=True))
        # A previously fulfilled Stock Request remains eligible and is recalculated from locking demand rows.
        reopened_key=line_key+"-reopened"; reopened_body={"product_id":3,"quantity":1,"reason":"SC-03 fulfilled request parity"}; reopened=[]
        for runtime,client in (("Laravel",laravel),("Go",go)):
            db(f"UPDATE orders SET subtotal_amount='{old_order[0]}',total_amount='{old_order[1]}',paid_amount='{old_order[2]}',gross_received_amount='{old_order[3]}',remaining_amount='{old_order[4]}',payment_status='{old_order[5]}' WHERE id={order_id}")
            db(f"UPDATE stock_request_items SET fulfilled_qty=requested_qty,remaining_qty=0 WHERE stock_request_id=(SELECT id FROM stock_requests WHERE order_id={order_id})")
            db(f"UPDATE stock_requests SET status='fulfilled',fulfilled_at=UTC_TIMESTAMP() WHERE order_id={order_id}")
            reserve=int(scalar("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3","0"))
            act=int(scalar("SELECT COALESCE(MAX(id),0) FROM activity_logs","0")); move=int(scalar("SELECT COALESCE(MAX(id),0) FROM stock_movements","0"))
            pay=int(scalar("SELECT COALESCE(MAX(id),0) FROM payment_transactions","0")); additional=int(scalar("SELECT COALESCE(MAX(id),0) FROM order_additional_payments","0"))
            st,res=client.call("POST",f"/api/v1/orders/{order_id}/items",reopened_body,{"Idempotency-Key":reopened_key})
            checks+=1
            if st!=201 or not res or not res.get("data"):
                failures.append(f"fulfilled request {runtime} add HTTP {st}: {json.dumps(res)[:500]}")
                clean_line(order_id,reopened_key,reserve,old_order,old_request,act,move,pay,additional)
                continue
            snapshot=(norm(res["data"]),state(order_id,reopened_key))
            reopened.append(snapshot)
            if snapshot[1]["stock_request"][0][0]!="partial": failures.append(f"fulfilled request {runtime} did not reopen as partial")
            clean_line(order_id,reopened_key,reserve,old_order,old_request,act,move,pay,additional)
        checks+=1
        if len(reopened)==2 and reopened[0][0]!=reopened[1][0]: failures.append("fulfilled request response mismatch: "+"; ".join(diff(reopened[0][0],reopened[1][0])[:20]))
        checks+=1
        if len(reopened)==2 and reopened[0][1]!=reopened[1][1]: failures.append("fulfilled request database mismatch: "+json.dumps(reopened[0][1],sort_keys=True)+" vs "+json.dumps(reopened[1][1],sort_keys=True))
        path=f"/api/v1/orders/{order_id}/items"
        body={"product_id":3,"quantity":1,"reason":"SC-03 validation parity"}
        for label,headers in (("missing idempotency key",{}),("oversized idempotency key",{"Idempotency-Key":"k"*101})):
            checks+=1; ok,detail=compare_error(label,path,body,headers,laravel,go)
            if not ok: failures.append(detail)
        numeric_strings={"product_id":"3","quantity":"1","reason":"SC-03 integer coercion parity"}
        checks+=1; ok,detail=compare_error("integer string input",path,numeric_strings,{},laravel,go)
        if not ok: failures.append(detail)
        prohibited={"product_id":3,"quantity":1,"reason":"SC-03 validation parity","stock_source":"sub"}
        checks+=1; ok,detail=compare_error("prohibited stock source",path,prohibited,{"Idempotency-Key":line_key+"p"},laravel,go)
        if not ok: failures.append(detail)
        missing_reason={"product_id":3,"quantity":1}
        checks+=1; ok,detail=compare_error("required reason",path,missing_reason,{"Idempotency-Key":line_key+"r"},laravel,go)
        if not ok: failures.append(detail)
        db(f"UPDATE orders SET status='terkirim' WHERE id={order_id}")
        checks+=1; ok,detail=compare_error("closed order window",path,body,{"Idempotency-Key":line_key+"closed"},laravel,go)
        if not ok: failures.append(detail)
        db(f"UPDATE orders SET status='diproses' WHERE id={order_id}")
        courier=scalar("SELECT id FROM couriers WHERE agent_id=10 AND is_active=1 ORDER BY id LIMIT 1","0")
        shipment=scalar(f"SELECT id FROM shipments WHERE order_id={order_id} ORDER BY id LIMIT 1","0")
        if courier!="0" and shipment!="0":
            db(f"UPDATE shipments SET courier_id={courier} WHERE id={shipment}")
            checks+=1; ok,detail=compare_error("executor assigned",path,body,{"Idempotency-Key":line_key+"assigned"},laravel,go)
            if not ok: failures.append(detail)
            db(f"UPDATE shipments SET courier_id=NULL WHERE id={shipment}")
        sub_location=scalar("SELECT id FROM warehouse_sub_locations WHERE agent_id=10 AND is_active=1 ORDER BY id LIMIT 1","1")
        db(f"UPDATE order_items SET stock_source='sub',sub_location_id={sub_location} WHERE order_id={order_id}")
        checks+=1; ok,detail=compare_error("Sub-sourced order",path,body,{"Idempotency-Key":line_key+"sub"},laravel,go)
        if not ok: failures.append(detail)
        db(f"UPDATE order_items SET stock_source='agent',sub_location_id=NULL WHERE order_id={order_id}")
        db(f"UPDATE stock_requests SET status='cancelled' WHERE order_id={order_id}")
        checks+=1; ok,detail=compare_error("cancelled Stock Request",path,body,{"Idempotency-Key":line_key+"cancelled"},laravel,go)
        if not ok: failures.append(detail)
        db(f"UPDATE stock_requests SET status='{old_request[0]}' WHERE order_id={order_id}")
        past=(datetime.date.today()-datetime.timedelta(days=2)).isoformat()
        past_body={"product_id":3,"quantity":1,"reason":"SC-03 past date parity","requested_delivery_date":past}
        checks+=1; ok,detail=compare_error("past requested date",path,past_body,{"Idempotency-Key":line_key+"past"},laravel,go)
        if not ok: failures.append(detail)
        checks+=1
        if len(captures)==2 and captures[0][0]!=captures[1][0]:
            failures.append("response mismatch: "+"; ".join(diff(captures[0][0],captures[1][0])[:20]))
        checks+=1
        if len(captures)==2 and captures[0][1]!=captures[1][1]:
            failures.append("database mismatch: "+json.dumps(captures[0][1],sort_keys=True)+" vs "+json.dumps(captures[1][1],sort_keys=True))
        # Route policy must reject Agent despite a same-branch order.
        agent=Client(LARAVEL); goagent=Client(GO); agent.login("agen@imp010.test"); goagent.login("agen@imp010.test")
        denied=[agent.call("POST",f"/api/v1/orders/{order_id}/items",body,{"Idempotency-Key":line_key+"x"})[0],goagent.call("POST",f"/api/v1/orders/{order_id}/items",body,{"Idempotency-Key":line_key+"y"})[0]]
        checks+=1
        if denied!=[403,403]: failures.append(f"Agent role denial differs: {denied}")
    finally:
        if order_id: clean_order(order_id,base_res,sequence,seq_exists)
    if failures:
        print(f"order line addition differential: {checks-len(failures)} passed, {len(failures)} failed")
        for failure in failures: print("FAIL "+failure)
        return 1
    print(f"order line addition differential: {checks} passed, 0 failed")
    return 0

if __name__=="__main__": raise SystemExit(main())
