#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for order invoice authorization, lazy issuance, and PDF delivery."""
import importlib.util, json, pathlib, subprocess, urllib.error, urllib.parse, urllib.request, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec); pickup_spec.loader.exec_module(pickup)


def binary_call(client, path):
    h = {"Accept":"application/pdf", "Origin":client.base, "Referer":client.base+"/", "X-Requested-With":"XMLHttpRequest", "User-Agent":"IMP010-invoice-parity"}
    token = next((urllib.parse.unquote(c.value) for c in client.jar if c.name=="XSRF-TOKEN"),None)
    if token: h["X-XSRF-TOKEN"] = token
    req=urllib.request.Request(client.base+path,headers=h,method="GET")
    try:
        with client.opener.open(req,timeout=60) as response: return response.status,response.headers, response.read()
    except urllib.error.HTTPError as e: return e.code,e.headers,e.read()


def issuance_state(order_id):
    issued=r.db(f"SELECT invoice_issued_at FROM orders WHERE id={order_id}")
    snapshots=r.db(f"SELECT version,`trigger`,invoice_number,CAST(total_amount AS CHAR),CAST(verified_paid_amount AS CHAR),CAST(outstanding_amount AS CHAR),payment_status FROM order_invoice_snapshots WHERE order_id={order_id} ORDER BY version")
    logs=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE subject_type LIKE '%Order' AND subject_id={order_id} AND event='invoice.issued' ORDER BY id")
    for row in logs:
        if row[1]: row[1]=json.loads(row[1])
    return {"issued":issued,"snapshots":snapshots,"logs":logs}


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing": raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:10]; reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'"); seq_exists,old_seq=bool(seq),seq[0][0] if seq else "0"
    outputs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[]
            consumer,order_id,_,_,_=r.create_case(base,token,base[-4:])
            path=f"/api/v1/orders/{order_id}/invoice"
            unavailable=binary_call(consumer,path)
            # Existing transfer order is not eligible until verified money exists.
            r.db(f"UPDATE orders SET invoice_issued_at=NULL,paid_amount=0,gross_received_amount=0,remaining_amount=total_amount,payment_status='unpaid' WHERE id={order_id}")
            r.db(f"DELETE FROM order_invoice_snapshots WHERE order_id={order_id}")
            r.db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%Order' AND subject_id={order_id} AND event='invoice.issued'")
            unpaid=binary_call(consumer,path)
            r.db(f"UPDATE orders SET paid_amount=total_amount,gross_received_amount=total_amount,remaining_amount=0,payment_status='paid' WHERE id={order_id}")
            success=binary_call(consumer,path)
            state=issuance_state(order_id)
            admin=r.Client(base); admin.login("admin@imp010.test"); admin_result=binary_call(admin,path)
            coord=r.Client(base); coord.login("koordinator@imp010.test"); denied=binary_call(coord,path)
            outputs.append({"unpaid_status":unpaid[0],"unpaid_body":json.loads(unpaid[2]),
                "success_status":success[0],"content_type":success[1].get("Content-Type"),
                "content_disposition":success[1].get("Content-Disposition"),"pdf_prefix":success[2][:8],"state":state,
                "admin_pdf":(admin_result[0],admin_result[1].get("Content-Type"),admin_result[2][:8]),
                "coord_denied":(denied[0],json.loads(denied[2]))})
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
            r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]): pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    # IDs, order numbers, timestamps, and binary encodings differ between independent test orders/renders;
    # compare the observable status/body and persisted issuance contract separately.
    for item in outputs:
        if item["unpaid_status"] != 409 or item["success_status"] != 200 or not str(item["content_type"]).startswith("application/pdf") or not item["pdf_prefix"].startswith(b"%PDF"):
            raise AssertionError("invoice response contract failed: "+repr(item))
        if len(item["state"]["snapshots"]) != 1 or len(item["state"]["logs"]) != 1 or not item["state"]["issued"][0][0]:
            raise AssertionError("lazy invoice issuance state failed: "+repr(item["state"]))
        if item["admin_pdf"][0] != 200 or not str(item["admin_pdf"][1]).startswith("application/pdf") or item["coord_denied"][0] != 403:
            raise AssertionError("invoice audience authorization failed")
    # Error envelope and immutable issuance side effects are directly comparable across runtimes.
    canon=[]
    for item in outputs:
        state=item["state"]
        canon.append({"unpaid_status":item["unpaid_status"],"unpaid_body":item["unpaid_body"],
                      "success_status":item["success_status"],"content_type":item["content_type"],
                      "content_disposition_name":"invoice-" in (item["content_disposition"] or ""),
                      "snapshot_count":len(state["snapshots"]),"snapshot_trigger":state["snapshots"][0][1],
                      "snapshot_amounts":state["snapshots"][0][3:7],"audit_event":state["logs"][0][0],
                      "admin_pdf_status":item["admin_pdf"][0],"coord_denied_status":item["coord_denied"][0]})
    if canon[0] != canon[1]:
        print("order invoice differential: FAIL");print(json.dumps(canon,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("order invoice differential: 7 passed, 0 failed")
    return 0


if __name__=="__main__": raise SystemExit(main())
