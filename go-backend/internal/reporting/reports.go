package reporting

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/url"
	"strings"
	"time"
)

// orderScopeReport applies order-level scoping + the common report filters (from/to/sales_id/korsal_id/
// status/payment_method/product_id) for reports built on the orders table. Mirrors scopedOrders().
func orderScopeReport(actor Actor, q url.Values) (string, []any, error) {
	where := []string{}
	args := []any{}
	switch actor.Role {
	case "super_admin":
		if id := q.Get("agent_id"); id != "" {
			where = append(where, "orders.agent_id = ?")
			args = append(args, id)
		}
	case "agen", "admin", "keuangan":
		if actor.AgentID == nil {
			return "", nil, ErrForbidden
		}
		where = append(where, "orders.agent_id = ?")
		args = append(args, *actor.AgentID)
	case "korsal":
		if actor.AgentID == nil {
			return "", nil, ErrForbidden
		}
		where = append(where, "orders.agent_id = ? AND (orders.korsal_id = ? OR orders.konsumen_id = ?)")
		args = append(args, *actor.AgentID, actor.ID, actor.ID)
	default:
		return "", nil, ErrForbidden
	}
	if len(where) == 0 {
		where = append(where, "1=1")
	}
	if v := q.Get("from"); v != "" {
		where = append(where, "DATE(orders.created_at) >= ?")
		args = append(args, v)
	}
	if v := q.Get("to"); v != "" {
		where = append(where, "DATE(orders.created_at) <= ?")
		args = append(args, v)
	}
	if v := q.Get("sales_id"); v != "" {
		where = append(where, "orders.sales_id = ?")
		args = append(args, v)
	}
	if v := q.Get("korsal_id"); v != "" {
		where = append(where, "orders.korsal_id = ?")
		args = append(args, v)
	}
	if v := q.Get("status"); v != "" {
		where = append(where, "orders.status = ?")
		args = append(args, v)
	}
	if v := q.Get("payment_method"); v != "" {
		where = append(where, "EXISTS (SELECT 1 FROM payment_methods pm WHERE pm.id = orders.payment_method_id AND pm.code = ?)")
		args = append(args, v)
	}
	if v := q.Get("product_id"); v != "" {
		where = append(where, "EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = orders.id AND i.product_id = ?)")
		args = append(args, v)
	}
	return strings.Join(where, " AND "), args, nil
}

// AuditLogs mirrors AuditLogController::index: paginated activity log, super_admin only (route gate).
func (s *Service) AuditLogs(ctx context.Context, actor Actor, q url.Values, page, perPage int) ([]map[string]any, map[string]int, error) {
	where := []string{"1=1"}
	args := []any{}
	if v := q.Get("event"); v != "" {
		where = append(where, "al.event = ?")
		args = append(args, v)
	}
	if v := q.Get("causer_id"); v != "" {
		where = append(where, "al.causer_id = ?")
		args = append(args, v)
	}
	if v := q.Get("from"); v != "" {
		where = append(where, "DATE(al.created_at) >= ?")
		args = append(args, v)
	}
	if v := q.Get("to"); v != "" {
		where = append(where, "DATE(al.created_at) <= ?")
		args = append(args, v)
	}
	filter := strings.Join(where, " AND ")

	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM activity_logs al WHERE `+filter, args...).Scan(&total); err != nil {
		return nil, nil, err
	}
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx,
		`SELECT al.id, u.id, u.name, u.email, al.event, al.description, al.subject_type, al.subject_id,
				al.properties, al.ip_address, al.user_agent, al.created_at
		 FROM activity_logs al
		 LEFT JOIN users u ON u.id = al.causer_id
		 WHERE `+filter+` ORDER BY al.created_at DESC LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var causerID sql.NullInt64
		var causerName, causerEmail sql.NullString
		var event, description, subjectType sql.NullString
		var subjectID sql.NullInt64
		var properties, ipAddress, userAgent sql.NullString
		var createdAt time.Time
		if err := rows.Scan(&id, &causerID, &causerName, &causerEmail, &event, &description,
			&subjectType, &subjectID, &properties, &ipAddress, &userAgent, &createdAt); err != nil {
			return nil, nil, err
		}
		causer := any(nil)
		if causerID.Valid {
			causer = map[string]any{"id": causerID.Int64, "name": causerName.String, "email": causerEmail.String}
		}
		// ActivityLog model serialization: properties is a JSON-cast array, description nullable,
		// created_at rendered with microseconds (Y-m-d\TH:i:s.uZ).
		propertiesAny := any(nil)
		if properties.Valid && properties.String != "" {
			var decoded any
			if json.Unmarshal([]byte(properties.String), &decoded) == nil {
				propertiesAny = decoded
			} else {
				propertiesAny = properties.String
			}
		}
		var descriptionAny any
		if description.Valid && description.String != "" {
			descriptionAny = description.String
		}
		data = append(data, map[string]any{
			"id":           id,
			"causer":       causer,
			"event":        event.String,
			"description":  descriptionAny,
			"subject_type": subjectType.String,
			"subject_id":   nullInt(subjectID),
			"properties":   propertiesAny,
			"ip_address":   nullString(ipAddress),
			"user_agent":   nullString(userAgent),
			"created_at":   createdAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

// CancellationsRefunds mirrors ReportService::cancellationsRefunds: three buckets (cancellations /
// adjustments / returns), each scoped to the actor's branch and date-filtered.
func (s *Service) CancellationsRefunds(ctx context.Context, actor Actor, q url.Values) (map[string][]map[string]any, error) {
	// Laravel applies ?status to the refund_status of adjustments/returns only — never to orders.status.
	qScope := url.Values{}
	for k, vs := range q {
		if k != "status" {
			qScope[k] = vs
		}
	}
	cancelWhere, cancelArgs, err := orderScopeReport(actor, qScope)
	if err != nil {
		return nil, err
	}
	cancelWhere = "orders.status = 'dibatalkan' AND (" + cancelWhere + ")"
	appendReportDateFilters(&cancelWhere, &cancelArgs, "orders.cancelled_at", q.Get("from"), q.Get("to"))
	if v := q.Get("sales_id"); v != "" {
		cancelWhere += " AND orders.sales_id = ?"
		cancelArgs = append(cancelArgs, v)
	}
	if v := q.Get("korsal_id"); v != "" {
		cancelWhere += " AND orders.korsal_id = ?"
		cancelArgs = append(cancelArgs, v)
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT orders.* FROM orders WHERE `+cancelWhere+` ORDER BY orders.cancelled_at DESC`, cancelArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var cancellations []map[string]any
	cancellations = scanCancellations(ctx, s, rows)

	adjWhere, adjArgs, err := orderScopeReport(actor, qScope)
	if err != nil {
		return nil, err
	}
	adjWhere = "(" + adjWhere + ")"
	appendReportDateFilters(&adjWhere, &adjArgs, "order_item_adjustments.created_at", q.Get("from"), q.Get("to"))
	if v := q.Get("status"); v != "" {
		adjWhere += " AND order_item_adjustments.refund_status = ?"
		adjArgs = append(adjArgs, v)
	}
	rows, err = s.database.QueryContext(ctx,
		`SELECT order_item_adjustments.id, order_item_adjustments.quantity_reduced,
				order_item_adjustments.refund_amount, order_item_adjustments.refund_status,
				order_item_adjustments.reason, order_item_adjustments.created_at,
				orders.id AS order_id, orders.order_no, orders.agent_id,
				order_items.product_name_snapshot, order_items.sku_snapshot AS product_sku
		 FROM order_item_adjustments
		 JOIN order_items ON order_items.id = order_item_adjustments.order_item_id
		 JOIN orders ON orders.id = order_items.order_id
		 WHERE `+adjWhere+` ORDER BY order_item_adjustments.created_at DESC`, adjArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var adjustments []map[string]any
	adjustments = scanAdjustments(rows)

	retWhere, retArgs, err := orderScopeReport(actor, qScope)
	if err != nil {
		return nil, err
	}
	retWhere = "(" + retWhere + ")"
	appendReportDateFilters(&retWhere, &retArgs, "returns.created_at", q.Get("from"), q.Get("to"))
	if v := q.Get("status"); v != "" {
		retWhere += " AND return_items.refund_status = ?"
		retArgs = append(retArgs, v)
	}
	if v := q.Get("courier_id"); v != "" {
		retWhere += " AND couriers.id = ?"
		retArgs = append(retArgs, v)
	}
	rows, err = s.database.QueryContext(ctx,
		`SELECT return_items.id, return_items.quantity_returned, return_items.refund_amount,
				return_items.status AS item_status, return_items.refund_status,
				returns.reason, returns.created_at,
				orders.id AS order_id, orders.order_no, orders.agent_id,
				order_items.product_name_snapshot, order_items.sku_snapshot AS product_sku,
				couriers.id AS courier_id, couriers.name AS courier_name
		 FROM return_items
		 JOIN returns ON returns.id = return_items.return_id
		 JOIN order_items ON order_items.id = return_items.order_item_id
		 JOIN orders ON orders.id = order_items.order_id
		 LEFT JOIN shipments ON shipments.id = order_items.shipment_id
		 LEFT JOIN couriers ON couriers.id = shipments.courier_id
		 WHERE `+retWhere+` ORDER BY returns.created_at DESC`, retArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var returns []map[string]any
	returns = scanReturns(rows)

	return map[string][]map[string]any{
		"cancellations": cancellations,
		"adjustments":   adjustments,
		"returns":       returns,
	}, nil
}

func appendReportDateFilters(where *string, args *[]any, column, from, to string) {
	if from != "" {
		*where += " AND " + column + " >= ?"
		*args = append(*args, from+" 00:00:00")
	}
	if to != "" {
		*where += " AND " + column + " < DATE_ADD(?, INTERVAL 1 DAY)"
		*args = append(*args, to)
	}
}

func nullString(v sql.NullString) any {
	if v.Valid {
		return v.String
	}
	return nil
}

// ---- Laravel Eloquent serialization for cancellationsRefunds ------------------------
// Order/User/OrderItemAdjustment/ReturnItem rows are serialized exactly as the Laravel models do:
// decimal casts become strings, datetime casts render Y-m-d\TH:i:s.uZ, hidden attributes are dropped.

var orderDecimal2 = map[string]bool{
	"subtotal_amount": true, "discount_amount": true, "shipping_fee_amount": true, "admin_fee_amount": true,
	"dp_amount": true, "paid_amount": true, "gross_received_amount": true, "remaining_amount": true, "total_amount": true,
}
var orderDecimal7 = map[string]bool{"latitude_snapshot": true, "longitude_snapshot": true}
var orderDate = map[string]bool{"delivery_date_estimate": true, "due_date": true}
var orderDatetime = map[string]bool{
	"delivery_date_actual": true, "cancelled_at": true, "cancellation_finalized_at": true,
	"invoice_issued_at": true, "created_at": true, "updated_at": true,
}
var userHidden = map[string]bool{"password": true, "remember_token": true}
var userDatetime = map[string]bool{"email_verified_at": true, "created_at": true, "updated_at": true}
var userInt = map[string]bool{"role_id": true, "parent_id": true, "agent_id": true, "korsal_id": true, "sales_id": true}

// laravelValue converts one raw driver value into the JSON value Laravel would emit for the given cast.
func laravelValue(v any, decimal, isDate, isDatetime bool) any {
	switch t := v.(type) {
	case nil:
		return nil
	case time.Time:
		// Laravel date/datetime casts serialise to ISO with microseconds in UTC ("Z").
		return t.UTC().Format("2006-01-02T15:04:05.000000Z")
	case []byte:
		return string(t)
	case int64:
		return t
	default:
		return v
	}
}

func serializeRow(cols []string, vals []any, decimals, dates, datetimes map[string]bool) map[string]any {
	out := make(map[string]any, len(cols))
	for i, c := range cols {
		out[c] = laravelValue(vals[i], decimals[c], dates[c], datetimes[c])
	}
	return out
}

func serializeUserRow(cols []string, vals []any) map[string]any {
	out := make(map[string]any, len(cols))
	for i, c := range cols {
		if userHidden[c] {
			continue
		}
		out[c] = laravelValue(vals[i], false, false, userDatetime[c])
	}
	return out
}

func (s *Service) usersByID(ctx context.Context, ids []int64) (map[int64]map[string]any, []string, error) {
	if len(ids) == 0 {
		return map[int64]map[string]any{}, nil, nil
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT * FROM users WHERE id IN (`+idPlaceholders(len(ids))+`)`, int64Args(ids)...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	cols, _ := rows.Columns()
	out := map[int64]map[string]any{}
	for rows.Next() {
		vals := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil, nil, err
		}
		var id int64
		for i, c := range cols {
			if c == "id" {
				if n, ok := vals[i].(int64); ok {
					id = n
				}
			}
		}
		out[id] = serializeUserRow(cols, vals)
	}
	return out, cols, rows.Err()
}

func scanCancellationsFull(ctx context.Context, s *Service, rows *sql.Rows) []map[string]any {
	cols, err := rows.Columns()
	if err != nil {
		return nil
	}
	type rowHolder struct {
		vals []any
		m    map[string]any
	}
	rowsData := []rowHolder{}
	ids := []int64{}
	seen := map[int64]bool{}

	for rows.Next() {
		vals := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil
		}
		m := serializeRow(cols, vals, orderDecimal2, orderDate, orderDatetime)
		rowsData = append(rowsData, rowHolder{vals: vals, m: m})
		for _, rel := range []string{"konsumen_id", "sales_id", "korsal_id"} {
			idx := -1
			for i, c := range cols {
				if c == rel {
					idx = i
				}
			}
			if idx >= 0 {
				if n, ok := asInt64(vals[idx]); ok && !seen[n] {
					seen[n] = true
					ids = append(ids, n)
				}
			}
		}
	}
	if err := rows.Err(); err != nil {
		return nil
	}
	users, _, err := s.usersByID(ctx, ids)
	if err != nil {
		return nil
	}
	out := make([]map[string]any, 0, len(rowsData))
	for _, rh := range rowsData {
		for _, rel := range []string{"konsumen", "sales", "korsal"} {
			var uid any
			for i, c := range cols {
				if c == rel+"_id" {
					uid = laravelValue(rh.vals[i], false, false, false)
				}
			}
			if n, ok := asInt64(uid); ok {
				if u, found := users[n]; found {
					rh.m[rel] = u
					continue
				}
			}
			rh.m[rel] = nil
		}
		out = append(out, rh.m)
	}
	return out
}

// asInt64 normalises signed/unsigned bigint driver values (konsumen_id is an unsigned bigint).
func asInt64(v any) (int64, bool) {
	switch t := v.(type) {
	case int64:
		return t, true
	case uint64:
		return int64(t), true
	}
	return 0, false
}

func scanCancellations(ctx context.Context, s *Service, rows *sql.Rows) []map[string]any {
	return scanCancellationsFull(ctx, s, rows)
}

func scanAdjustments(rows *sql.Rows) []map[string]any {
	cols, err := rows.Columns()
	if err != nil {
		return nil
	}
	decimals := map[string]bool{"refund_amount": true}
	datetimes := map[string]bool{"created_at": true}
	data := make([]map[string]any, 0)
	for rows.Next() {
		vals := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil
		}
		data = append(data, serializeRow(cols, vals, decimals, nil, datetimes))
	}
	if err := rows.Err(); err != nil {
		return nil
	}
	return data
}

func scanReturns(rows *sql.Rows) []map[string]any {
	cols, err := rows.Columns()
	if err != nil {
		return nil
	}
	decimals := map[string]bool{"refund_amount": true}
	datetimes := map[string]bool{"created_at": true}
	data := make([]map[string]any, 0)
	for rows.Next() {
		vals := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil
		}
		data = append(data, serializeRow(cols, vals, decimals, nil, datetimes))
	}
	if err := rows.Err(); err != nil {
		return nil
	}
	return data
}

// CourierRoster mirrors ReportService::courierReport: full courier roster with delivery count/fee from
// commissions and return count from return_items→shipments.
func (s *Service) CourierRoster(ctx context.Context, actor Actor, q url.Values, page, perPage int) ([]map[string]any, map[string]int, error) {
	scope, args, err := s.scopeCouriers(actor, q)
	if err != nil {
		return nil, nil, err
	}
	if v := q.Get("search"); v != "" {
		scope += " AND couriers.name LIKE ?"
		args = append(args, "%"+v+"%")
	}

	feeSub := `(SELECT COUNT(*) FROM commissions cm
		JOIN couriers fc ON fc.user_id = cm.beneficiary_user_id
		WHERE cm.beneficiary_role = 'courier' AND fc.id = couriers.id` + feeDateFilter(q) + `)`
	feeAmtSub := `(SELECT COALESCE(SUM(cm.amount), 0) FROM commissions cm
		JOIN couriers fc ON fc.user_id = cm.beneficiary_user_id
		WHERE cm.beneficiary_role = 'courier' AND fc.id = couriers.id` + feeDateFilter(q) + `)`
	retSub := `(SELECT COUNT(*) FROM return_items ri
		JOIN returns rt ON rt.id = ri.return_id
		JOIN order_items oi ON oi.id = ri.order_item_id
		JOIN shipments sh ON sh.id = oi.shipment_id
		WHERE sh.courier_id = couriers.id` + retDateFilter(q) + `)`

	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM couriers WHERE `+scope, args...).Scan(&total); err != nil {
		return nil, nil, err
	}
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx,
		`SELECT couriers.id, couriers.name, couriers.is_active,
				COALESCE(`+feeSub+`, 0), COALESCE(`+feeAmtSub+`, 0), COALESCE(`+retSub+`, 0)
		 FROM couriers
		 WHERE `+scope+` ORDER BY couriers.name LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name string
		var isActive int
		var deliveryCount, returnCount int
		var totalFee sql.NullString
		if err := rows.Scan(&id, &name, &isActive, &deliveryCount, &totalFee, &returnCount); err != nil {
			return nil, nil, err
		}
		data = append(data, map[string]any{
			"courier_id":     id,
			"courier_name":   name,
			"is_active":      isActive == 1,
			"delivery_count": deliveryCount,
			"total_fee":      nullString(totalFee),
			"return_count":   returnCount,
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

func (s *Service) scopeCouriers(actor Actor, q url.Values) (string, []any, error) {
	where := []string{}
	args := []any{}
	switch actor.Role {
	case "super_admin":
		if id := q.Get("agent_id"); id != "" {
			where = append(where, "couriers.agent_id = ?")
			args = append(args, id)
		}
	case "agen", "admin", "keuangan":
		if actor.AgentID == nil {
			return "", nil, ErrForbidden
		}
		where = append(where, "couriers.agent_id = ?")
		args = append(args, *actor.AgentID)
	default:
		return "", nil, ErrForbidden
	}
	if len(where) == 0 {
		where = append(where, "1=1")
	}
	return strings.Join(where, " AND "), args, nil
}

func feeDateFilter(q url.Values) string {
	out := ""
	if v := q.Get("from"); v != "" {
		out += " AND cm.earned_at >= '" + v + " 00:00:00'"
	}
	if v := q.Get("to"); v != "" {
		out += " AND cm.earned_at < DATE_ADD('" + v + "', INTERVAL 1 DAY)"
	}
	return out
}

func retDateFilter(q url.Values) string {
	out := ""
	if v := q.Get("from"); v != "" {
		out += " AND rt.created_at >= '" + v + " 00:00:00'"
	}
	if v := q.Get("to"); v != "" {
		out += " AND rt.created_at < DATE_ADD('" + v + "', INTERVAL 1 DAY)"
	}
	return out
}

// CouriersPerAgent mirrors ReportService::couriersPerAgent: count of couriers per agent.
func (s *Service) CouriersPerAgent(ctx context.Context, actor Actor, q url.Values) ([]map[string]any, error) {
	scope, args, err := s.scopeCouriers(actor, q)
	if err != nil {
		return nil, err
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT couriers.agent_id, agent_users.name, COUNT(*)
		 FROM couriers
		 JOIN users agent_users ON agent_users.id = couriers.agent_id
		 WHERE `+scope+` GROUP BY couriers.agent_id, agent_users.name
		 ORDER BY agent_users.name`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var agentID int64
		var agentName string
		var courierCount int
		if err := rows.Scan(&agentID, &agentName, &courierCount); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{
			"agent_id":      agentID,
			"agent_name":    agentName,
			"courier_count": courierCount,
		})
	}
	return data, nil
}

// CourierFees mirrors ReportService::courierFees: courier fee totals from commissions.
func (s *Service) CourierFees(ctx context.Context, actor Actor, q url.Values) ([]map[string]any, error) {
	scope, args, err := s.scopeCouriers(actor, q)
	if err != nil {
		return nil, err
	}
	if v := q.Get("courier_id"); v != "" {
		scope += " AND couriers.id = ?"
		args = append(args, v)
	}
	dateFilter := feeDateFilter(q)
	rows, err := s.database.QueryContext(ctx,
		`SELECT couriers.id, couriers.name, couriers.agent_id,
				SUM(commissions.amount), COUNT(*)
		 FROM commissions
		 JOIN couriers ON couriers.user_id = commissions.beneficiary_user_id
		 WHERE commissions.beneficiary_role = 'courier' AND `+scope+dateFilter+`
		 GROUP BY couriers.id, couriers.name, couriers.agent_id`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name string
		var agentID sql.NullInt64
		var totalFee sql.NullString
		var deliveryCount int
		if err := rows.Scan(&id, &name, &agentID, &totalFee, &deliveryCount); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{
			"courier_id":     id,
			"courier_name":   name,
			"agent_id":       agentID,
			"total_fee":      nullString(totalFee),
			"delivery_count": deliveryCount,
		})
	}
	return data, nil
}

// AgentFees mirrors ReportService::agentFees: agent fee totals (+ distinct active sales count per agent).
// super_admin/agen only; admin is explicitly excluded (defense in depth).
func (s *Service) AgentFees(ctx context.Context, actor Actor, q url.Values) ([]map[string]any, error) {
	if actor.Role != "super_admin" && actor.Role != "agen" {
		return nil, ErrForbidden
	}
	where := []string{"commissions.beneficiary_role = 'agent'"}
	args := []any{}
	if actor.Role == "agen" {
		where = append(where, "users.id = ?")
		args = append(args, actor.ID)
	} else if v := q.Get("agent_id"); v != "" {
		where = append(where, "users.id = ?")
		args = append(args, v)
	}
	dateFilter := ""
	if v := q.Get("from"); v != "" {
		dateFilter += " AND commissions.earned_at >= '" + v + " 00:00:00'"
	}
	if v := q.Get("to"); v != "" {
		dateFilter += " AND commissions.earned_at < DATE_ADD('" + v + "', INTERVAL 1 DAY)"
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT users.id, users.name, SUM(commissions.amount), COUNT(*)
		 FROM commissions
		 JOIN users ON users.id = commissions.beneficiary_user_id
		 WHERE `+strings.Join(where, " AND ")+dateFilter+`
		 GROUP BY users.id, users.name`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	type feeRow struct {
		agentID          int64
		agentName        string
		totalFee         float64
		transactionCount int
	}
	rows2, err := s.database.QueryContext(ctx,
		`SELECT sales_users.agent_id, COUNT(DISTINCT sales_users.id)
		 FROM commissions
		 JOIN users sales_users ON sales_users.id = commissions.beneficiary_user_id
		 WHERE commissions.beneficiary_role = 'sales'`+dateFilter+`
		 GROUP BY sales_users.agent_id`)
	if err != nil {
		return nil, err
	}
	defer rows2.Close()
	activeSales := map[int64]int{}
	for rows2.Next() {
		var aid int64
		var cnt int
		if err := rows2.Scan(&aid, &cnt); err != nil {
			return nil, err
		}
		activeSales[aid] = cnt
	}
	data := make([]map[string]any, 0)
	for rows.Next() {
		var agentID int64
		var agentName string
		var totalFee sql.NullString
		var txCount int
		if err := rows.Scan(&agentID, &agentName, &totalFee, &txCount); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{
			"agent_id":           agentID,
			"agent_name":         agentName,
			"total_fee":          nullString(totalFee),
			"transaction_count":  txCount,
			"active_sales_count": activeSales[agentID],
		})
	}
	return data, nil
}

// PaymentStatus mirrors ReportService::paymentStatus: one row per agent x payment_status combination.
func (s *Service) PaymentStatus(ctx context.Context, actor Actor, q url.Values) ([]map[string]any, error) {
	orderScope, args, err := orderScopeReport(actor, q)
	if err != nil {
		return nil, err
	}
	orderScope = "(" + orderScope + ")"
	appendReportDateFilters(&orderScope, &args, "orders.created_at", q.Get("from"), q.Get("to"))
	if v := q.Get("sales_id"); v != "" {
		orderScope += " AND orders.sales_id = ?"
		args = append(args, v)
	}
	if v := q.Get("korsal_id"); v != "" {
		orderScope += " AND orders.korsal_id = ?"
		args = append(args, v)
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT orders.agent_id, agent_users.name, orders.payment_status,
				COUNT(*), SUM(orders.total_amount)
		 FROM orders
		 LEFT JOIN users agent_users ON agent_users.id = orders.agent_id
		 WHERE `+orderScope+`
		 GROUP BY orders.agent_id, agent_users.name, orders.payment_status
		 ORDER BY agent_users.name`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var agentID int64
		var agentName, totalAmount sql.NullString
		var paymentStatus string
		var orderCount int
		if err := rows.Scan(&agentID, &agentName, &paymentStatus, &orderCount, &totalAmount); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{
			"agent_id":       agentID,
			"agent_name":     nullString(agentName),
			"payment_status": paymentStatus,
			"order_count":    orderCount,
			"total_amount":   nullString(totalAmount),
		})
	}
	return data, nil
}

// SalesKorsalFees mirrors ReportService::salesKorsalFees: sales fees + korsal hierarchy roll-up.
func (s *Service) SalesKorsalFees(ctx context.Context, actor Actor, q url.Values) (map[string][]map[string]any, error) {
	dateFilter := ""
	if v := q.Get("from"); v != "" {
		dateFilter += " AND commissions.earned_at >= '" + v + " 00:00:00'"
	}
	if v := q.Get("to"); v != "" {
		dateFilter += " AND commissions.earned_at < DATE_ADD('" + v + "', INTERVAL 1 DAY)"
	}

	// --- sales fees ---
	userScope, userArgs := s.scopeUsersAgent(actor, q)
	salesWhere := []string{"commissions.beneficiary_role = 'sales'"}
	salesArgs := append([]any{}, userArgs...)
	salesWhere = append(salesWhere, userScope...)
	if v := q.Get("sales_id"); v != "" {
		salesWhere = append(salesWhere, "users.id = ?")
		salesArgs = append(salesArgs, v)
	}
	if v := q.Get("korsal_id"); v != "" {
		salesWhere = append(salesWhere, "users.korsal_id = ?")
		salesArgs = append(salesArgs, v)
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT users.id, users.name, users.korsal_id, users.agent_id,
				SUM(commissions.amount), COUNT(*)
		 FROM commissions
		 JOIN users ON users.id = commissions.beneficiary_user_id
		 WHERE `+strings.Join(salesWhere, " AND ")+dateFilter+`
		 GROUP BY users.id, users.name, users.korsal_id, users.agent_id`, salesArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	sales := scanFeeRows(rows)

	// --- korsal hierarchy roll-up (scope applies to korsal_users, mirroring scopeToActor) ---
	korsalWhere := []string{"commissions.beneficiary_role = 'sales'"}
	korsalArgs := []any{}
	if actor.Role == "super_admin" {
		if id := q.Get("agent_id"); id != "" {
			korsalWhere = append(korsalWhere, "korsal_users.agent_id = ?")
			korsalArgs = append(korsalArgs, id)
		}
	} else if actor.AgentID == nil {
		korsalWhere = append(korsalWhere, "1=0")
	} else {
		korsalWhere = append(korsalWhere, "korsal_users.agent_id = ?")
		korsalArgs = append(korsalArgs, *actor.AgentID)
	}
	if v := q.Get("korsal_id"); v != "" {
		korsalWhere = append(korsalWhere, "korsal_users.id = ?")
		korsalArgs = append(korsalArgs, v)
	}
	rows, err = s.database.QueryContext(ctx,
		`SELECT korsal_users.id, korsal_users.name, korsal_users.agent_id,
				SUM(commissions.amount), COUNT(*), COUNT(DISTINCT sales_users.id)
		 FROM commissions
		 JOIN users sales_users ON sales_users.id = commissions.beneficiary_user_id
		 JOIN users korsal_users ON korsal_users.id = sales_users.korsal_id
		 WHERE `+strings.Join(korsalWhere, " AND ")+dateFilter+`
		 GROUP BY korsal_users.id, korsal_users.name, korsal_users.agent_id`, korsalArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	korsal := scanKorsalFeeRows(rows)

	return map[string][]map[string]any{
		"sales":  sales,
		"korsal": korsal,
	}, nil
}

// CustomersReport mirrors ReportService::customersReport: konsumen roster with order counts and spend.
func (s *Service) CustomersReport(ctx context.Context, actor Actor, q url.Values, page, perPage int) ([]map[string]any, map[string]int, error) {
	orderScope, args, err := orderScopeReport(actor, q)
	if err != nil {
		return nil, nil, err
	}
	orderScope = "(" + orderScope + ")"
	appendReportDateFilters(&orderScope, &args, "orders.created_at", q.Get("from"), q.Get("to"))
	if v := q.Get("search"); v != "" {
		orderScope += " AND (konsumen_users.name LIKE ? OR konsumen_users.phone LIKE ?)"
		term := "%" + v + "%"
		args = append(args, term, term)
	}
	groupBy := "konsumen_users.id, konsumen_users.name, konsumen_users.phone"
	var total int
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM (SELECT 1 FROM orders
		 JOIN users konsumen_users ON konsumen_users.id = orders.konsumen_id
		 WHERE `+orderScope+` GROUP BY `+groupBy+`) t`, args...).Scan(&total); err != nil {
		return nil, nil, err
	}
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx,
		`SELECT konsumen_users.id, konsumen_users.name, konsumen_users.phone,
				COUNT(*), SUM(CASE WHEN orders.payment_status = 'paid' THEN orders.total_amount ELSE 0 END),
				SUM(CASE WHEN orders.status <> 'dibatalkan' THEN orders.total_amount ELSE 0 END),
				COALESCE(SUM(CASE WHEN orders.status <> 'dibatalkan' THEN orders.paid_amount ELSE 0 END), 0),
				DATE_FORMAT(MAX(orders.created_at), '%Y-%m-%d %H:%i:%s') AS last_order_at
		 FROM orders
		 JOIN users konsumen_users ON konsumen_users.id = orders.konsumen_id
		 WHERE `+orderScope+` GROUP BY `+groupBy+` ORDER BY last_order_at DESC LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		// Query-builder rows are raw PDO values: decimal aggregates arrive as strings, datetimes as
		// 'Y-m-d H:i:s' strings, NULLs as null — reproduce that exactly.
		var id int64
		var name string
		var phone, totalSpent, totalOrdered, totalPaid, lastOrderAt sql.NullString
		var orderCount int
		if err := rows.Scan(&id, &name, &phone, &orderCount, &totalSpent, &totalOrdered, &totalPaid, &lastOrderAt); err != nil {
			return nil, nil, err
		}
		data = append(data, map[string]any{
			"konsumen_id":    id,
			"konsumen_name":  name,
			"konsumen_phone": nullString(phone),
			"order_count":    orderCount,
			"total_spent":    nullString(totalSpent),
			"total_ordered":  nullString(totalOrdered),
			"total_paid":     nullString(totalPaid),
			"last_order_at":  nullString(lastOrderAt),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

func (s *Service) scopeUsersAgent(actor Actor, q url.Values) ([]string, []any) {
	where := []string{}
	args := []any{}
	if actor.Role == "super_admin" {
		if id := q.Get("agent_id"); id != "" {
			where = append(where, "users.agent_id = ?")
			args = append(args, id)
		}
		if len(where) == 0 {
			where = append(where, "1=1")
		}
		return where, args
	}
	if actor.AgentID == nil {
		where = append(where, "1=0")
		return where, args
	}
	where = append(where, "users.agent_id = ?")
	args = append(args, *actor.AgentID)
	if actor.Role == "korsal" {
		where = append(where, "users.korsal_id = ?")
		args = append(args, actor.ID)
	}
	return where, args
}

func scanFeeRows(rows *sql.Rows) []map[string]any {
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name string
		var korsalID sql.NullInt64
		var agentID sql.NullInt64
		var totalFee sql.NullString
		var txCount int
		if err := rows.Scan(&id, &name, &korsalID, &agentID, &totalFee, &txCount); err != nil {
			return nil
		}
		data = append(data, map[string]any{
			"sales_id":          id,
			"sales_name":        name,
			"korsal_id":         nullInt(korsalID),
			"agent_id":          nullInt(agentID),
			"total_fee":         nullString(totalFee),
			"transaction_count": txCount,
		})
	}
	return data
}

func scanKorsalFeeRows(rows *sql.Rows) []map[string]any {
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name string
		var agentID sql.NullInt64
		var totalFee sql.NullString
		var txCount, activeSales int
		if err := rows.Scan(&id, &name, &agentID, &totalFee, &txCount, &activeSales); err != nil {
			return nil
		}
		data = append(data, map[string]any{
			"korsal_id":          id,
			"korsal_name":        name,
			"agent_id":           nullInt(agentID),
			"total_fee":          nullString(totalFee),
			"transaction_count":  txCount,
			"active_sales_count": activeSales,
		})
	}
	return data
}

func fprintfDebug(n int, m int, err error, info string) {}
