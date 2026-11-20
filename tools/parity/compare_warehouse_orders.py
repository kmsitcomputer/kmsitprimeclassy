#!/usr/bin/env python3
"""Differential checks for Gudang's warehouse order queue (read-only scenarios)."""
import http.cookiejar
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

from compare_proposals import db

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"
results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))
    if not ok:
        print(f"FAIL {name}: {detail}")


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def token(self):
        for cookie in self.jar:
            if cookie.name == "XSRF-TOKEN":
                return urllib.parse.unquote(cookie.value)
        return ""

    def call(self, method, path, payload=None):
        headers = {"Accept": "application/json", "Origin": self.base,
                   "Referer": self.base + "/", "X-Requested-With": "XMLHttpRequest"}
        data = None
        if payload is not None:
            data = json.dumps(payload).encode()
            headers["Content-Type"] = "application/json"
        if self.token():
            headers["X-XSRF-TOKEN"] = self.token()
        req = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=30) as response:
                raw = response.read().decode()
                return response.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as error:
            raw = error.read().decode()
            try:
                body = json.loads(raw) if raw else None
            except json.JSONDecodeError:
                body = {"_raw": raw}
            return error.code, body

    def login(self, email):
        self.call("GET", "/sanctum/csrf-cookie")
        status, _ = self.call("POST", "/api/v1/auth/login",
                              {"identifier": email, "password": "Secret123!"})
        return status


def compare(lara, go, path):
    left_status, left = lara.call("GET", path)
    right_status, right = go.call("GET", path)
    check(path, left_status == right_status and left == right,
          f"status {left_status}/{right_status}; Laravel={left!r}; Go={right!r}" if left_status != right_status or left != right else "identical")


def normalise_time(value):
    if isinstance(value, dict):
        return {key: normalise_time(item) for key, item in value.items()}
    if isinstance(value, list):
        return [normalise_time(item) for item in value]
    if isinstance(value, str) and re.fullmatch(r"\d{4}-\d\d-\d\d(?:T| )\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)?", value):
        return "<timestamp>"
    return value


def scenario_return_inspect(lara, go):
    order = db("SELECT o.id,oi.id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.agent_id=10 ORDER BY o.id,oi.id LIMIT 1")
    if not order:
        check("return inspect fixture", False, "no test order/item in primeclassy_testing")
        return
    order_id, order_item_id = order[0]
    db("INSERT INTO `returns` (order_id,requested_by,reason,status,reviewed_by,reviewed_at,total_refund_amount,created_at,updated_at) "
       f"VALUES ({order_id},13,'inspect differential fixture','approved',11,UTC_TIMESTAMP(),100.00,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    return_id = db(f"SELECT id FROM `returns` WHERE order_id={order_id} ORDER BY id DESC LIMIT 1")[0][0]
    db("INSERT INTO return_items (return_id,order_item_id,quantity_returned,refund_amount,restock,status,refund_status,created_at,updated_at) "
       f"VALUES ({return_id},{order_item_id},2,100.00,1,'approved','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    item_id = db(f"SELECT id FROM return_items WHERE return_id={return_id} ORDER BY id DESC LIMIT 1")[0][0]
    path = f"/api/v1/warehouse/returns/{item_id}/inspect"
    try:
        db(f"UPDATE orders SET agent_id=11 WHERE id={order_id}")
        ls, lb = lara.call("POST", path, {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 1})
        gs, gb = go.call("POST", path, {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 1})
        db(f"UPDATE orders SET agent_id=10 WHERE id={order_id}")
        check("inspect foreign branch", ls == gs == 403 and lb == gb, f"{ls}/{gs}, {lb!r}/{gb!r}")
        denied_lara, denied_go = Client(LARAVEL), Client(GO)
        als, ags = denied_lara.login("admin@imp010.test"), denied_go.login("admin@imp010.test")
        check("Admin login for inspect RBAC", als == 200 and ags == 200, f"{als}/{ags}")
        if als == 200 and ags == 200:
            ls, lb = denied_lara.call("POST", path, {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 1})
            gs, gb = denied_go.call("POST", path, {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 1})
            check("inspect RBAC", ls == gs == 403 and lb == gb, f"{ls}/{gs}, {lb!r}/{gb!r}")
        ls, lb = lara.call("POST", path, {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 0})
        gs, gb = go.call("POST", path, {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 0})
        check("inspect invalid sum", ls == gs == 422 and lb == gb, f"{ls}/{gs}, {lb!r}/{gb!r}")
        ls, lb = lara.call("POST", path, {})
        gs, gb = go.call("POST", path, {})
        check("inspect required validation", ls == gs == 422 and lb == gb, f"{ls}/{gs}, {lb!r}/{gb!r}")

        base = db(f"SELECT status,quantity_received,good_quantity,damaged_quantity,condition_status,condition_note,inspected_by,inspected_at,disposition_status,updated_at FROM return_items WHERE id={item_id}")[0]
        valid = {"received_quantity": 2, "good_quantity": 1, "damaged_quantity": 1, "note": "checked"}
        ls, lb = lara.call("POST", path, valid)
        lstate = db(f"SELECT status,quantity_received,good_quantity,damaged_quantity,condition_status,condition_note,inspected_by,inspected_at,disposition_status,updated_at FROM return_items WHERE id={item_id}")[0]
        db(f"UPDATE return_items SET status='{base[0]}',quantity_received={base[1]},good_quantity={base[2]},damaged_quantity={base[3]},condition_status='{base[4]}',condition_note=NULL,inspected_by=NULL,inspected_at=NULL,disposition_status='{base[8]}',updated_at='{base[9]}' WHERE id={item_id}")
        gs, gb = go.call("POST", path, valid)
        gstate = db(f"SELECT status,quantity_received,good_quantity,damaged_quantity,condition_status,condition_note,inspected_by,inspected_at,disposition_status,updated_at FROM return_items WHERE id={item_id}")[0]
        check("inspect response", ls == gs == 200 and normalise_time(lb) == normalise_time(gb), f"{ls}/{gs}, {lb!r}/{gb!r}")
        check("inspect database effects", normalise_time(lstate) == normalise_time(gstate), f"{lstate!r}/{gstate!r}")
    finally:
        db(f"DELETE FROM return_items WHERE id={item_id}")
        db(f"DELETE FROM `returns` WHERE id={return_id}")


def scenario_return_finalize(lara, go, order_id, order_item_id, stock_source="agent"):
    location = None
    if stock_source == "sub":
        locations = db("SELECT id FROM warehouse_sub_locations WHERE agent_id=10 AND is_active=1 ORDER BY id LIMIT 1")
        if not locations:
            check("finalize Sub fixture", False, "no active branch Sub Location in testing fixtures")
            return
        location = locations[0][0]
    db("INSERT INTO `returns` (order_id,requested_by,reason,status,total_refund_amount,created_at,updated_at) "
       f"VALUES ({order_id},13,'finalize differential fixture','approved',100.00,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    return_id = db(f"SELECT id FROM `returns` WHERE order_id={order_id} ORDER BY id DESC LIMIT 1")[0][0]
    db("INSERT INTO return_items (return_id,order_item_id,quantity_returned,quantity_received,good_quantity,damaged_quantity,condition_status,inspected_by,inspected_at,refund_amount,restock,status,refund_status,disposition_status,created_at,updated_at) "
       f"VALUES ({return_id},{order_item_id},1,1,1,0,'good',15,UTC_TIMESTAMP(),100.00,1,'approved','pending','pending_disposition',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    item_id = db(f"SELECT id FROM return_items WHERE return_id={return_id} ORDER BY id DESC LIMIT 1")[0][0]
    original_source = db(f"SELECT stock_source,sub_location_id,product_id,product_variation_id FROM order_items WHERE id={order_item_id}")[0]
    if stock_source == "sub":
        db(f"UPDATE order_items SET stock_source='sub',sub_location_id={location} WHERE id={order_item_id}")
    product_id, variation_id = original_source[2], original_source[3]
    target_clause = (f"product_variation_id={variation_id} AND product_id IS NULL" if variation_id != "NULL"
                     else f"product_id={product_id} AND product_variation_id IS NULL")
    bucket = "sub" if stock_source == "sub" else "transit"
    sub_clause = f"sub_location_id={location}" if location else "sub_location_id IS NULL"
    stock_rows = db(f"SELECT id,quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
    stock_before = stock_rows[0] if stock_rows else None
    path = f"/api/v1/warehouse/returns/{item_id}/finalize"

    def restore_baseline():
        db(f"DELETE FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")
        if stock_before:
            db(f"UPDATE warehouse_stocks SET quantity={stock_before[1]} WHERE id={stock_before[0]}")
        else:
            db(f"DELETE FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        db(f"UPDATE return_items SET disposition_status='pending_disposition',restock_processed_at=NULL WHERE id={item_id}")

    try:
        if stock_source == "sub":
            db(f"UPDATE order_items SET stock_source='sub',sub_location_id={location} WHERE id={order_item_id}")
        db(f"UPDATE orders SET agent_id=11 WHERE id={order_id}")
        foreign_lara_status, foreign_lara = lara.call("POST", path, {})
        foreign_go_status, foreign_go = go.call("POST", path, {})
        db(f"UPDATE orders SET agent_id=10 WHERE id={order_id}")
        check(f"finalize {stock_source} foreign branch", foreign_lara_status == foreign_go_status == 403
              and foreign_lara == foreign_go, f"{foreign_lara_status}/{foreign_go_status}, {foreign_lara!r}/{foreign_go!r}")
        baseline = db(f"SELECT disposition_status,restock_processed_at FROM return_items WHERE id={item_id}")[0]
        # The row is not eligible until an inspection timestamp exists; this refusal must not touch stock.
        db(f"UPDATE return_items SET inspected_at=NULL,disposition_status='pending_disposition' WHERE id={item_id}")
        lara_not_ready_status, lara_not_ready = lara.call("POST", path, {})
        go_not_ready_status, go_not_ready = go.call("POST", path, {})
        check(f"finalize {stock_source} requires inspection", lara_not_ready_status == go_not_ready_status == 422
              and lara_not_ready == go_not_ready, f"{lara_not_ready_status}/{go_not_ready_status}, {lara_not_ready!r}/{go_not_ready!r}")
        db(f"UPDATE return_items SET inspected_at=UTC_TIMESTAMP(),disposition_status='pending_disposition',restock_processed_at=NULL WHERE id={item_id}")
        wrong_role_lara, wrong_role_go = Client(LARAVEL), Client(GO)
        wls, wgs = wrong_role_lara.login("gudang@imp010.test"), wrong_role_go.login("gudang@imp010.test")
        if wls == wgs == 200:
            wls, wlb = wrong_role_lara.call("POST", path, {})
            wgs, wgb = wrong_role_go.call("POST", path, {})
            check(f"finalize {stock_source} role gate", wls == wgs == 403 and wlb == wgb,
                  f"{wls}/{wgs}, {wlb!r}/{wgb!r}")
        if stock_source == "sub":
            old_active = db(f"SELECT is_active FROM warehouse_sub_locations WHERE id={location}")[0][0]
            db(f"UPDATE warehouse_sub_locations SET is_active=0 WHERE id={location}")
            try:
                lara_blocked_status, lara_blocked = lara.call("POST", path, {})
                blocked_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='sub' AND {sub_clause} AND {target_clause}")
                blocked_moves = db(f"SELECT COUNT(*) FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")[0][0]
                go_blocked_status, go_blocked = go.call("POST", path, {})
                after_blocked_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='sub' AND {sub_clause} AND {target_clause}")
            finally:
                db(f"UPDATE warehouse_sub_locations SET is_active={old_active} WHERE id={location}")
            check("finalize inactive Sub Location fails closed", lara_blocked_status == go_blocked_status == 422
                  and lara_blocked == go_blocked and blocked_stock == after_blocked_stock
                  and blocked_moves == "0", f"{lara_blocked_status}/{go_blocked_status}, {lara_blocked!r}/{go_blocked!r}")
        ls, lb = lara.call("POST", path, {})
        laravel_state = db(f"SELECT disposition_status,restock_processed_at FROM return_items WHERE id={item_id}")[0]
        laravel_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        laravel_moves = db(f"SELECT type,stock_type,quantity,sub_location_id,HEX(reference_type),note FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem' ORDER BY id")
        lara_retry_status, lara_retry_body = lara.call("POST", path, {})
        lara_retry_moves = db(f"SELECT COUNT(*) FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")[0][0]
        check(f"finalize {stock_source} Laravel retry", lara_retry_status == 200 and normalise_time(lara_retry_body) == normalise_time(lb)
              and lara_retry_moves == "1", f"status={lara_retry_status}, movements={lara_retry_moves}")
        restore_baseline()
        gs, gb = go.call("POST", path, {})
        go_state = db(f"SELECT disposition_status,restock_processed_at FROM return_items WHERE id={item_id}")[0]
        go_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        go_moves = db(f"SELECT type,stock_type,quantity,sub_location_id,HEX(reference_type),note FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem' ORDER BY id")
        check(f"finalize {stock_source} response", ls == gs == 200 and normalise_time(lb) == normalise_time(gb), f"{ls}/{gs}, {lb!r}/{gb!r}")
        check(f"finalize {stock_source} item state", normalise_time(laravel_state) == normalise_time(go_state)
              and go_state[0] == "restocked", f"{laravel_state!r}/{go_state!r}")
        expected_qty = int(stock_before[1]) + 1 if stock_before else 1
        check(f"finalize {stock_source} stock effect", len(laravel_stock) == len(go_stock) == 1
              and int(laravel_stock[0][0]) == int(go_stock[0][0]) == expected_qty,
              f"before={stock_before!r}, Laravel={laravel_stock!r}, Go={go_stock!r}")
        check(f"finalize {stock_source} movement", laravel_moves == go_moves and len(go_moves) == 1
              and go_moves[0][0:3] == ["return_restock", stock_source if stock_source == "sub" else "transit", "1"]
              and go_moves[0][4] == "4170705C4D6F64656C735C52657475726E4974656D",
              f"Laravel={laravel_moves!r}, Go={go_moves!r}")

        # Retry must be a no-op: row and movement count remain unchanged.
        before_retry_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        before_retry_moves = db(f"SELECT COUNT(*) FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")[0][0]
        retry_status, retry_body = go.call("POST", path, {})
        after_retry_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        after_retry_moves = db(f"SELECT COUNT(*) FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")[0][0]
        check(f"finalize {stock_source} idempotent retry", retry_status == 200 and retry_body == gb
              and before_retry_stock == after_retry_stock and before_retry_moves == after_retry_moves,
              f"status={retry_status}, stock={before_retry_stock!r}/{after_retry_stock!r}, movements={before_retry_moves}/{after_retry_moves}")

        # A fully damaged inspection finalizes without adding stock or an audit movement.
        restore_baseline()
        db(f"UPDATE return_items SET quantity_received=1,good_quantity=0,damaged_quantity=1,condition_status='damaged' WHERE id={item_id}")
        lara.call("POST", f"/api/v1/warehouse/returns/{item_id}/inspect", {"received_quantity": 1, "good_quantity": 0, "damaged_quantity": 1})
        db(f"UPDATE return_items SET quantity_received=1,good_quantity=0,damaged_quantity=1,condition_status='damaged',inspected_by=15,inspected_at=UTC_TIMESTAMP(),disposition_status='pending_disposition' WHERE id={item_id}")
        before_damaged_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        lara_status, lara_body = lara.call("POST", path, {})
        lara_damaged_state = db(f"SELECT disposition_status,restock_processed_at FROM return_items WHERE id={item_id}")[0]
        lara_damaged_moves = db(f"SELECT COUNT(*) FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")[0][0]
        restore_baseline()
        db(f"UPDATE return_items SET quantity_received=1,good_quantity=0,damaged_quantity=1,condition_status='damaged',inspected_by=15,inspected_at=UTC_TIMESTAMP(),disposition_status='pending_disposition' WHERE id={item_id}")
        go_status, go_body = go.call("POST", path, {})
        go_damaged_state = db(f"SELECT disposition_status,restock_processed_at FROM return_items WHERE id={item_id}")[0]
        go_damaged_moves = db(f"SELECT COUNT(*) FROM stock_movements WHERE reference_id={item_id} AND reference_type LIKE '%ReturnItem'")[0][0]
        after_damaged_stock = db(f"SELECT quantity FROM warehouse_stocks WHERE stock_type='{bucket}' AND {sub_clause} AND {target_clause}")
        check(f"finalize {stock_source} damaged only", lara_status == go_status == 200
              and normalise_time(lara_body) == normalise_time(go_body)
              and lara_damaged_state == go_damaged_state == ["damaged_confirmed", "NULL"]
              and lara_damaged_moves == go_damaged_moves == "0"
              and before_damaged_stock == after_damaged_stock,
              f"state={lara_damaged_state!r}/{go_damaged_state!r}, moves={lara_damaged_moves}/{go_damaged_moves}, stock={before_damaged_stock!r}/{after_damaged_stock!r}")
    finally:
        restore_baseline()
        if original_source[0] != stock_source or original_source[1] != (str(location) if location else "NULL"):
            source_sql = "'" + original_source[0].replace("'", "''") + "'"
            location_sql = "NULL" if original_source[1] == "NULL" else original_source[1]
            db(f"UPDATE order_items SET stock_source={source_sql},sub_location_id={location_sql} WHERE id={order_item_id}")
        db(f"DELETE FROM return_items WHERE id={item_id}")
        db(f"DELETE FROM `returns` WHERE id={return_id}")


def main():
    lara, go = Client(LARAVEL), Client(GO)
    ls, gs = lara.login("gudang@imp010.test"), go.login("gudang@imp010.test")
    check("Gudang login", ls == 200 and gs == 200, f"{ls}/{gs}")
    if ls == 200 and gs == 200:
        for path in ("/api/v1/warehouse/orders/diproses",
                     "/api/v1/warehouse/orders/diproses?requested=1",
                     "/api/v1/warehouse/orders/diproses?requested=true&per_page=1&page=1",
                     "/api/v1/warehouse/orders/diproses?requested=0&per_page=2&page=2"):
            compare(lara, go, path)
        scenario_return_inspect(lara, go)
        # Add an isolated approved return request to the existing test order; remove both inserted rows
        # in finally so the shared differential database is restored exactly.
        order = db("SELECT id FROM orders WHERE agent_id=10 ORDER BY id LIMIT 1")
        order_item = db("SELECT id FROM order_items ORDER BY id LIMIT 1")
        if order and order_item:
            order_id, order_item_id = order[0][0], order_item[0][0]
            db("INSERT INTO `returns` (order_id,requested_by,reason,status,reviewed_by,reviewed_at,total_refund_amount,created_at,updated_at) "
               f"VALUES ({order_id},13,'warehouse queue fixture','approved',11,UTC_TIMESTAMP(),100.00,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
            return_id = db(f"SELECT id FROM `returns` WHERE order_id={order_id} ORDER BY id DESC LIMIT 1")[0][0]
            try:
                db("INSERT INTO return_items (return_id,order_item_id,quantity_returned,refund_amount,restock,status,refund_status,created_at,updated_at) "
                   f"VALUES ({return_id},{order_item_id},1,100.00,1,'approved','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                compare(lara, go, "/api/v1/warehouse/returns")
                compare(lara, go, "/api/v1/warehouse/returns?per_page=1&page=1")
                body_status, body = lara.call("GET", "/api/v1/warehouse/returns?per_page=1&page=1")
                returned = body.get("data", []) if isinstance(body, dict) else []
                check("approved return queue fixture", body_status == 200 and len(returned) == 1
                      and returned[0].get("items", [{}])[0].get("status") == "approved",
                      f"status={body_status}, return_count={len(returned)}")
                db(f"UPDATE return_items SET inspected_at=UTC_TIMESTAMP() WHERE return_id={return_id}")
                compare(lara, go, "/api/v1/warehouse/returns")
                inspected_status, inspected_body = lara.call("GET", "/api/v1/warehouse/returns")
                inspected_rows = inspected_body.get("data", []) if isinstance(inspected_body, dict) else []
                check("inspected return excluded from queue", inspected_status == 200
                      and all(row.get("id") != int(return_id) for row in inspected_rows),
                      f"status={inspected_status}, queued_ids={[row.get('id') for row in inspected_rows]}")
                db(f"UPDATE return_items SET inspected_at=NULL WHERE return_id={return_id}")
                db(f"UPDATE orders SET agent_id=11 WHERE id={order_id}")
                compare(lara, go, "/api/v1/warehouse/returns")
                foreign_status, foreign_body = lara.call("GET", "/api/v1/warehouse/returns")
                foreign_rows = foreign_body.get("data", []) if isinstance(foreign_body, dict) else []
                check("foreign branch return excluded", foreign_status == 200
                      and all(row.get("id") != int(return_id) for row in foreign_rows),
                      f"status={foreign_status}, queued_ids={[row.get('id') for row in foreign_rows]}")
                db(f"UPDATE orders SET agent_id=10 WHERE id={order_id}")
            finally:
                db(f"UPDATE orders SET agent_id=10 WHERE id={order_id}")
                db(f"DELETE FROM return_items WHERE return_id={return_id}")
                db(f"DELETE FROM `returns` WHERE id={return_id}")
        # Exercise a non-empty queue without leaving test data behind. Snapshot and restore the
        # existing test order's visibility fields even if an assertion or HTTP request fails.
        fixture = db("SELECT o.id,o.status FROM orders o WHERE o.agent_id=10 AND NOT EXISTS(SELECT 1 FROM stock_requests r WHERE r.order_id=o.id) ORDER BY o.id LIMIT 1")
        if fixture:
            order_id, old_status = fixture[0]
            shipments = db(f"SELECT courier_id,self_delivered_by_user_id,delivery_mode FROM shipments WHERE order_id={order_id}")
            request_id = None
            try:
                db(f"UPDATE orders SET status='diproses' WHERE id={order_id}")
                db(f"UPDATE shipments SET courier_id=NULL,self_delivered_by_user_id=NULL WHERE order_id={order_id}")
                request_number = f"WQ-HARNESS-{time.time_ns()}"
                db("INSERT INTO stock_requests (agent_id,order_id,request_number,status,created_by,coordinator_requested_by,coordinator_requested_at,coordinator_request_note,created_at,updated_at) "
                   f"VALUES (10,{order_id},'{request_number}','pending',15,18,UTC_TIMESTAMP(),'harness',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                request_id = db(f"SELECT id FROM stock_requests WHERE request_number='{request_number}'")[0][0]
                order_items = db(f"SELECT oi.id,oi.product_id,oi.product_variation_id,oi.sku_snapshot FROM order_items oi "
                                 f"WHERE oi.order_id={order_id} AND NOT EXISTS(SELECT 1 FROM stock_request_items sri WHERE sri.order_item_id=oi.id) ORDER BY oi.id LIMIT 1")
                stock_item_id = None
                if order_items:
                    order_item_id, product_id, variation_id, sku = order_items[0]
                    variation_sql = "NULL" if variation_id == "NULL" else variation_id
                    product_sql = "NULL" if product_id == "NULL" else product_id
                    sku_sql = "NULL" if sku == "NULL" else "'" + sku.replace("'", "''") + "'"
                    db("INSERT INTO stock_request_items (stock_request_id,order_item_id,product_id,product_variation_id,sku_snapshot,requested_qty,fulfilled_qty,remaining_qty,created_at,updated_at) "
                       f"VALUES ({request_id},{order_item_id},{product_sql},{variation_sql},{sku_sql},3,0,3,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                    stock_item_id = db(f"SELECT id FROM stock_request_items WHERE stock_request_id={request_id}")[0][0]
                before = len(results)
                compare(lara, go, "/api/v1/warehouse/orders/diproses?per_page=1&page=1")
                if len(results) > before:
                    body_status, body = lara.call("GET", "/api/v1/warehouse/orders/diproses?per_page=1&page=1")
                    rows = body.get("data", []) if isinstance(body, dict) else []
                    check("non-empty queue fixture", body_status == 200 and len(rows) == 1 and rows[0].get("id") == int(order_id),
                          f"status={body_status}, rows={len(rows)}")
                compare(lara, go, "/api/v1/warehouse/orders/diproses?requested=1")
                compare(lara, go, "/api/v1/warehouse/orders/diproses?requested=0")
                db(f"UPDATE shipments SET courier_id=902 WHERE order_id={order_id}")
                compare(lara, go, "/api/v1/warehouse/orders/diproses")  # open Agent demand keeps later batches visible
                db(f"UPDATE shipments SET courier_id=NULL,self_delivered_by_user_id=16,delivery_mode='self_sub' WHERE order_id={order_id}")
                compare(lara, go, "/api/v1/warehouse/orders/diproses")
                hidden_status, hidden = lara.call("GET", "/api/v1/warehouse/orders/diproses")
                check("self-delivery excludes queue order", hidden_status == 200 and hidden.get("meta", {}).get("total") == 0,
                      f"status={hidden_status}, meta={hidden.get('meta') if isinstance(hidden, dict) else None}")
                db(f"UPDATE shipments SET self_delivered_by_user_id=NULL,delivery_mode='standard' WHERE order_id={order_id}")
                db(f"UPDATE orders SET agent_id=11 WHERE id={order_id}")
                compare(lara, go, "/api/v1/warehouse/orders/diproses")
                hidden_status, hidden = lara.call("GET", "/api/v1/warehouse/orders/diproses")
                check("foreign branch excludes queue order", hidden_status == 200 and hidden.get("meta", {}).get("total") == 0,
                      f"status={hidden_status}, meta={hidden.get('meta') if isinstance(hidden, dict) else None}")
                db(f"UPDATE orders SET agent_id=10 WHERE id={order_id}")
                db(f"UPDATE shipments SET courier_id=NULL,self_delivered_by_user_id=NULL WHERE order_id={order_id}")
                compare(lara, go, f"/api/v1/warehouse/orders/{order_id}/stock-request")
                body_status, body = lara.call("GET", f"/api/v1/warehouse/orders/{order_id}/stock-request")
                data = body.get("data", {}) if isinstance(body, dict) else {}
                lines = data.get("items", []) if isinstance(data, dict) else []
                check("stock-request resource fixture", body_status == 200 and data.get("order", {}).get("id") == int(order_id)
                      and (not stock_item_id or (len(lines) == 1 and lines[0].get("current_stock", {}).keys() == {"transit", "shipping", "reserved"}
                                                  and isinstance(lines[0].get("product"), dict))),
                      f"status={body_status}, item_count={len(lines)}")
            finally:
                if request_id:
                    db(f"DELETE FROM stock_request_items WHERE stock_request_id={request_id}")
                    db(f"DELETE FROM stock_requests WHERE id={request_id}")
                db(f"UPDATE orders SET status='{old_status}' WHERE id={order_id}")
                if shipments:
                    courier_id, self_delivery_id, delivery_mode = shipments[0]
                    db(f"UPDATE shipments SET courier_id={courier_id},self_delivered_by_user_id={self_delivery_id},delivery_mode='{delivery_mode}' WHERE order_id={order_id}")
    denied_lara, denied_go = Client(LARAVEL), Client(GO)
    als, ags = denied_lara.login("admin@imp010.test"), denied_go.login("admin@imp010.test")
    check("Admin login", als == 200 and ags == 200, f"{als}/{ags}")
    if als == 200 and ags == 200:
        compare(denied_lara, denied_go, "/api/v1/warehouse/orders/diproses")
        compare(denied_lara, denied_go, "/api/v1/warehouse/returns")
        fixture = db("SELECT o.id,oi.id FROM orders o JOIN order_items oi ON oi.order_id=o.id "
                     "WHERE o.agent_id=10 AND oi.stock_source='agent' ORDER BY o.id,oi.id LIMIT 1")
        if fixture:
            scenario_return_finalize(denied_lara, denied_go, fixture[0][0], fixture[0][1], "agent")
            scenario_return_finalize(denied_lara, denied_go, fixture[0][0], fixture[0][1], "sub")
    passed = sum(ok for _, ok, _ in results)
    failed = len(results) - passed
    print(f"warehouse orders differential: {passed} passed, {failed} failed")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
