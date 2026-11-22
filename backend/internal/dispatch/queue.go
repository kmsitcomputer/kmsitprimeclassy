package dispatch

import (
	"context"
	"database/sql"
	"net/url"
)

// Queue is DispatchController::index + DispatchShipmentResource. It projects one row per
// unassigned standard pending shipment, with shipment-owned items and operational readiness only.
func (s *Service) Queue(ctx context.Context, a Actor, q url.Values) (map[string]any, error) {
	agentID, err := resolveAgentID(ctx, s.database, a, q)
	if err != nil {
		return nil, err
	}
	orderFilter, orderArgs := dispatchEligibleOrders(q, agentID)
	where := `sh.order_id IN (SELECT o.id FROM orders o WHERE ` + orderFilter + `)
		AND sh.delivery_mode='standard' AND sh.status='pending' AND sh.courier_id IS NULL
		AND sh.self_delivered_by_user_id IS NULL
		AND EXISTS(SELECT 1 FROM order_items i WHERE i.shipment_id=sh.id AND i.status<>'dibatalkan')
		AND (sh.shipping_provider_code IS NULL OR sh.shipping_provider_code NOT IN ('expedition','pickup'))`
	args := append([]any(nil), orderArgs...)
	if date := q.Get("delivery_date"); date != "" {
		where += ` AND EXISTS(SELECT 1 FROM order_items i WHERE i.shipment_id=sh.id AND DATE(i.requested_delivery_date)=?)`
		args = append(args, date)
	}
	perPage := clampInt(q.Get("per_page"), 15, 1, 1<<30)
	page := clampInt(q.Get("page"), 1, 1, 1<<30)
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM shipments sh WHERE `+where, args...).Scan(&total); err != nil {
		return nil, err
	}
	listArgs := append(append([]any{}, args...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT sh.id, sh.order_id, sh.delivery_mode, sh.self_delivered_by_user_id,
		sh.courier_id, sh.created_at, o.order_no, o.status, o.recipient_name_snapshot, o.recipient_phone_snapshot,
		o.address_snapshot, o.village_snapshot, o.district_snapshot, o.regency_snapshot, o.province_snapshot,
		o.province_id, o.regency_id, o.district_id, o.village_id,
		(o.payment_status='paid' AND o.remaining_amount<=0)
		FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE `+where+` ORDER BY sh.id DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var shipmentID, orderID int64
		var mode string
		var selfID, courierID sql.NullInt64
		var provinceID, regencyID, districtID, villageID sql.NullString
		var created sql.NullTime
		var orderNo, status, recipient, phone, address, village, district, regency, province sql.NullString
		var paid bool
		if err := rows.Scan(&shipmentID, &orderID, &mode, &selfID, &courierID, &created, &orderNo, &status,
			&recipient, &phone, &address, &village, &district, &regency, &province, &provinceID, &regencyID,
			&districtID, &villageID, &paid); err != nil {
			return nil, err
		}
		items, dates, err := s.queueItems(ctx, orderID, shipmentID)
		if err != nil {
			return nil, err
		}
		fulfillment, err := s.queueFulfillment(ctx, orderID)
		if err != nil {
			return nil, err
		}
		var courier any
		if courierID.Valid {
			var name string
			if err := s.database.QueryRowContext(ctx, `SELECT id,name FROM couriers WHERE id=?`, courierID.Int64).Scan(&courierID.Int64, &name); err != nil {
				return nil, err
			}
			courier = map[string]any{"id": courierID.Int64, "name": name}
		}
		data = append(data, map[string]any{
			"id": shipmentID, "shipment_id": shipmentID, "order_id": orderID, "order_no": nullStr(orderNo),
			"status": nullStr(status), "delivery_mode": mode, "self_delivered_by_user_id": nullIntPtr(selfID),
			"courier_id": nullIntPtr(courierID), "self_assignable": mode != "self_sub", "courier": courier,
			"recipient_name": nullStr(recipient), "recipient_phone": nullStr(phone), "address_line": nullStr(address),
			"village_name": nullStr(village), "district_name": nullStr(district), "regency_name": nullStr(regency), "province_name": nullStr(province),
			"province_id": nullStr(provinceID), "regency_id": nullStr(regencyID), "district_id": nullStr(districtID), "village_id": nullStr(villageID),
			"delivery_date": dates, "fulfillment": fulfillment, "items": items, "paid_in_full": paid,
			"created_at": carbonMicro(created),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	last := (total + perPage - 1) / perPage
	if last < 1 {
		last = 1
	}
	return map[string]any{"data": data, "meta": map[string]int{"current_page": page, "last_page": last, "total": total}}, nil
}

func (s *Service) queueItems(ctx context.Context, orderID, shipmentID int64) ([]map[string]any, []string, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT id, product_name_snapshot, variation_label_snapshot,
		sku_snapshot, fulfilled_quantity, status, DATE_FORMAT(requested_delivery_date,'%Y-%m-%d')
		FROM order_items WHERE order_id=? AND shipment_id=? ORDER BY id`, orderID, shipmentID)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	items := make([]map[string]any, 0)
	dateSet := map[string]bool{}
	dates := make([]string, 0)
	for rows.Next() {
		var id, qty int64
		var name, status string
		var variation, sku, date sql.NullString
		if err := rows.Scan(&id, &name, &variation, &sku, &qty, &status, &date); err != nil {
			return nil, nil, err
		}
		items = append(items, map[string]any{"id": id, "product_name": name, "variation_label": nullStr(variation),
			"sku": nullStr(sku), "quantity": qty, "status": status, "requested_delivery_date": nullStr(date)})
		if date.Valid && !dateSet[date.String] {
			dateSet[date.String] = true
			dates = append(dates, date.String)
		}
	}
	return items, dates, rows.Err()
}

func (s *Service) queueFulfillment(ctx context.Context, orderID int64) (any, error) {
	var id int64
	var status string
	var requested sql.NullTime
	err := s.database.QueryRowContext(ctx, `SELECT id,status,coordinator_requested_at FROM stock_requests WHERE order_id=? ORDER BY id LIMIT 1`, orderID).Scan(&id, &status, &requested)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var outstanding, accepted int64
	if err := s.database.QueryRowContext(ctx, `SELECT COALESCE(SUM(remaining_qty),0),COALESCE(SUM(fulfilled_qty),0) FROM stock_request_items WHERE stock_request_id=?`, id).Scan(&outstanding, &accepted); err != nil {
		return nil, err
	}
	return map[string]any{"status": status, "outstanding_qty": outstanding, "accepted_qty": accepted,
		"coordinator_requested_at": queueIso8601(requested)}, nil
}

func queueIso8601(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05") + "+00:00"
}
