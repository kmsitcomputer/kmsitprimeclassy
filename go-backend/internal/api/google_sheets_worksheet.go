package api

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
	"time"
)

func (s *Server) sheetsWorksheetQuery(ctx context.Context, agentID sql.NullInt64, filters map[string]any, maxRows int) ([]map[string]any, error) {
	first := "ROW_NUMBER() OVER (PARTITION BY i.order_id ORDER BY i.id)=1"
	salesName := "CASE WHEN customer_roles.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') THEN customers.name ELSE COALESCE(sales_users.name,korsal_users.name,agent_users.name) END"
	korsalName := "CASE WHEN customer_roles.slug='agen' THEN NULL WHEN customer_roles.slug='korsal' THEN customers.name ELSE korsal_users.name END"
	receivable := "CASE WHEN o.status='dibatalkan' THEN 0 ELSE GREATEST(o.total_amount-COALESCE(o.paid_amount,0),0) END"
	query := `SELECT i.id AS no,o.order_no AS invoice_no,DATE_FORMAT(o.created_at,'%d/%m/%Y') AS order_date,
		agent_users.name AS agent_name,` + korsalName + ` AS korsal_name,` + salesName + ` AS sales_name,
		sales_users.phone AS sales_phone,COALESCE(NULLIF(o.recipient_name_snapshot,''),customers.name,'-') AS customer_name,
		customers.phone AS customer_phone,o.address_snapshot AS customer_address,COALESCE(NULLIF(i.sku_snapshot,''),'-') AS sku,
		CASE WHEN i.variation_label_snapshot IS NULL OR i.variation_label_snapshot='' THEN i.product_name_snapshot ELSE CONCAT(i.product_name_snapshot,' - ',i.variation_label_snapshot) END AS product_name,
		pc.name AS category_name,i.unit_price_snapshot+0 AS unit_price,i.fulfilled_quantity+0 AS quantity,i.subtotal_snapshot+0 AS line_total,
		CASE WHEN ` + first + ` THEN o.shipping_fee_amount+0 ELSE NULL END AS shipping_fee,
		CASE WHEN ` + first + ` THEN o.discount_amount+0 ELSE NULL END AS order_discount,
		CASE WHEN ` + first + ` THEN o.total_amount+0 ELSE NULL END AS invoice_total,
		CASE WHEN ` + first + ` THEN o.paid_amount+0 ELSE NULL END AS invoice_paid,
		CASE WHEN ` + first + ` THEN (` + receivable + `) ELSE NULL END AS invoice_outstanding,
		pm.name AS payment_method,o.payment_status AS payment_status,
		CASE WHEN pm.type='cod' THEN 'konsumen' WHEN pm.code IN ('bank_transfer','down_payment') THEN 'keuangan' ELSE NULL END AS money_position,
		o.payment_status AS update_payment_status,'' AS update_money_position,
		CASE WHEN ` + first + ` THEN (SELECT SUM(pt.amount) FROM payment_transactions pt WHERE pt.order_id=o.id AND pt.type='payment' AND pt.status='paid' AND JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'$.note'))='down_payment') ELSE NULL END AS dp,
		CASE WHEN ` + first + ` THEN (SELECT pt.amount FROM payment_transactions pt WHERE pt.order_id=o.id AND pt.type='payment' AND pt.status='paid' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'$.note')),'') NOT IN ('down_payment','dp_settlement','cash_on_delivery') ORDER BY pt.paid_at,pt.id LIMIT 1) ELSE NULL END AS installment_1,
		CASE WHEN ` + first + ` THEN (SELECT DATE_FORMAT(pt.paid_at,'%d/%m/%Y') FROM payment_transactions pt WHERE pt.order_id=o.id AND pt.type='payment' AND pt.status='paid' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'$.note')),'') NOT IN ('down_payment','dp_settlement','cash_on_delivery') ORDER BY pt.paid_at,pt.id LIMIT 1) ELSE NULL END AS installment_1_date,
		CASE WHEN ` + first + ` THEN (SELECT SUM(pt.amount) FROM payment_transactions pt WHERE pt.order_id=o.id AND pt.type='payment' AND pt.status='paid' AND JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'$.note'))='dp_settlement') ELSE NULL END AS settlement,
		CASE WHEN ` + first + ` THEN (SELECT DATE_FORMAT(MAX(pt.paid_at),'%d/%m/%Y') FROM payment_transactions pt WHERE pt.order_id=o.id AND pt.type='payment' AND pt.status='paid' AND JSON_UNQUOTE(JSON_EXTRACT(pt.raw_payload,'$.note'))='dp_settlement') ELSE NULL END AS settlement_date,
		'' AS settlement_note,'' AS settlement_total,COALESCE(DATE_FORMAT(i.requested_delivery_date,'%d/%m/%Y'),'-') AS delivery_date,
		o.address_snapshot AS shipping_address,CASE WHEN ` + first + ` THEN CONCAT('https://maps.google.com/?q=',o.latitude_snapshot,',',o.longitude_snapshot) ELSE NULL END AS maps_link,
		o.recipient_name_snapshot AS recipient_name,o.recipient_phone_snapshot AS recipient_phone,o.notes AS order_notes,sh.status AS shipping_status,
		CASE WHEN sh.delivery_mode='self_sub' THEN self_delivered_users.name ELSE c.name END AS driver_name,
		CASE WHEN sh.delivery_mode='self_sub' THEN self_delivered_users.phone ELSE courier_users.phone END AS driver_phone,
		CASE WHEN LOWER(TRIM(pc.name))='hampers' THEN 'ya' ELSE 'tidak' END AS hampers,
		COALESCE(o.province_snapshot,'-') AS province,COALESCE(o.regency_snapshot,'-') AS regency,
		COALESCE(o.district_snapshot,'-') AS district,COALESCE(o.village_snapshot,'-') AS village,o.status AS order_status
		FROM order_items i JOIN orders o ON o.id=i.order_id
		LEFT JOIN users customers ON customers.id=o.konsumen_id LEFT JOIN roles customer_roles ON customer_roles.id=customers.role_id
		LEFT JOIN users sales_users ON sales_users.id=o.sales_id LEFT JOIN users korsal_users ON korsal_users.id=o.korsal_id
		LEFT JOIN users agent_users ON agent_users.id=o.agent_id LEFT JOIN payment_methods pm ON pm.id=o.payment_method_id
		LEFT JOIN products pr ON pr.id=i.product_id LEFT JOIN product_categories pc ON pc.id=pr.category_id
		LEFT JOIN shipments sh ON sh.id=i.shipment_id LEFT JOIN couriers c ON c.id=sh.courier_id
		LEFT JOIN users courier_users ON courier_users.id=c.user_id
		LEFT JOIN users self_delivered_users ON self_delivered_users.id=sh.self_delivered_by_user_id WHERE 1=1`
	args := []any{}
	if agentID.Valid {
		query += ` AND o.agent_id=?`
		args = append(args, agentID.Int64)
	}
	from, to := sheetsPeriodBounds(filters, time.Now())
	if from != nil {
		query += ` AND o.created_at>=?`
		args = append(args, *from)
	}
	if to != nil {
		query += ` AND o.created_at<?`
		args = append(args, *to)
	}
	if category, _ := filters["category_group"].(string); category == "cake" {
		query += ` AND LOWER(COALESCE(pc.name,'')) LIKE '%cake%'`
	} else if category == "cookies" {
		query += ` AND LOWER(COALESCE(pc.name,'')) NOT LIKE '%cake%'`
	}
	query += fmt.Sprintf(` ORDER BY o.created_at DESC,o.order_no,i.id LIMIT %d`, maxRows+1)
	rows, err := s.database.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	columns, err := rows.Columns()
	if err != nil {
		return nil, err
	}
	if len(columns) != 48 {
		return nil, fmt.Errorf("transaction worksheet projection has %d columns; Laravel contract requires 48", len(columns))
	}
	out := []map[string]any{}
	for rows.Next() {
		values := make([]any, len(columns))
		destinations := make([]any, len(columns))
		for i := range values {
			destinations[i] = &values[i]
		}
		if err := rows.Scan(destinations...); err != nil {
			return nil, err
		}
		item := make(map[string]any, len(columns))
		for i, name := range columns {
			item[strings.ToLower(name)] = values[i]
		}
		out = append(out, item)
	}
	return out, rows.Err()
}

func sheetsPeriodBounds(filters map[string]any, now time.Time) (*string, *string) {
	location, err := time.LoadLocation("Asia/Jakarta")
	if err != nil {
		location = time.FixedZone("WIB", 7*60*60)
	}
	today := time.Date(now.In(location).Year(), now.In(location).Month(), now.In(location).Day(), 0, 0, 0, 0, location)
	period, _ := filters["period"].(string)
	var start, end time.Time
	switch period {
	case "daily":
		start, end = today, today
	case "weekly":
		start, end = today.AddDate(0, 0, -((int(today.Weekday())+6)%7)), today
	case "monthly":
		start, end = time.Date(today.Year(), today.Month(), 1, 0, 0, 0, 0, location), today
	default:
		start = sheetsFilterDate(filters["from"], location)
		end = sheetsFilterDate(filters["to"], location)
	}
	var from, to *string
	if !start.IsZero() {
		value := start.UTC().Format("2006-01-02 15:04:05")
		from = &value
	}
	if !end.IsZero() {
		value := end.AddDate(0, 0, 1).UTC().Format("2006-01-02 15:04:05")
		to = &value
	}
	return from, to
}

func sheetsFilterDate(value any, location *time.Location) time.Time {
	text, ok := value.(string)
	if !ok || text == "" {
		return time.Time{}
	}
	parsed, err := time.ParseInLocation("2006-01-02", text, location)
	if err != nil {
		return time.Time{}
	}
	return parsed
}
