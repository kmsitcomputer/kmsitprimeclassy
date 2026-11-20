// Package reporting contains read-only projections whose authority and
// aggregation grain mirror the Laravel ReportService contracts.
package reporting

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

var ErrForbidden = errors.New("role has no reporting scope")

type Actor struct {
	ID      int64
	Role    string
	AgentID *int64
}

type Service struct{ database *db.DB }

func New(database *db.DB) *Service { return &Service{database: database} }

func CommissionRoles(role string) []string {
	switch role {
	case "super_admin", "agen":
		return []string{"agent", "sales", "courier"}
	case "admin", "keuangan":
		return []string{"sales", "courier"}
	case "sales", "korsal":
		return []string{"sales"}
	case "kurir", "koordinator-kurir":
		return []string{"courier"}
	case "sales-kurir-sub":
		return []string{"sales", "courier"}
	default:
		return nil
	}
}

func (s *Service) commissionScope(actor Actor, agentFilter string) (string, []any, []string) {
	roles := CommissionRoles(actor.Role)
	if len(roles) == 0 {
		return "1=0", nil, roles
	}
	placeholders := strings.TrimRight(strings.Repeat("?,", len(roles)), ",")
	predicate := "c.beneficiary_role IN (" + placeholders + ")"
	args := make([]any, 0, len(roles)+3)
	for _, role := range roles {
		args = append(args, role)
	}
	switch actor.Role {
	case "super_admin":
		if id, err := strconv.ParseInt(agentFilter, 10, 64); agentFilter != "" && err == nil {
			predicate += " AND EXISTS (SELECT 1 FROM users bu WHERE bu.id=c.beneficiary_user_id AND bu.agent_id=?)"
			args = append(args, id)
		}
	case "agen", "admin", "keuangan":
		if actor.AgentID == nil {
			return "1=0", nil, roles
		}
		predicate += " AND EXISTS (SELECT 1 FROM users bu WHERE bu.id=c.beneficiary_user_id AND bu.agent_id=?)"
		args = append(args, *actor.AgentID)
	case "korsal":
		predicate += " AND (c.beneficiary_user_id=? OR EXISTS (SELECT 1 FROM users bu WHERE bu.id=c.beneficiary_user_id AND bu.korsal_id=?))"
		args = append(args, actor.ID, actor.ID)
	default:
		predicate += " AND c.beneficiary_user_id=?"
		args = append(args, actor.ID)
	}
	return predicate, args, roles
}

func (s *Service) CommissionIndex(ctx context.Context, actor Actor, q url.Values) ([]map[string]any, map[string]int, error) {
	predicate, args, _ := s.commissionScope(actor, q.Get("agent_id"))
	if role := q.Get("beneficiary_role"); role != "" {
		predicate += " AND c.beneficiary_role=?"
		args = append(args, role)
	}
	if status := q.Get("status"); status != "" {
		predicate += " AND c.status=?"
		args = append(args, status)
	}
	appendDateFilters(&predicate, &args, "c.earned_at", q.Get("from"), q.Get("to"))
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM commissions c WHERE `+predicate, args...).Scan(&total); err != nil {
		return nil, nil, err
	}
	page, perPage := paging(q, 15, 100)
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT c.id,c.order_id,c.order_item_id,c.beneficiary_user_id,
		c.beneficiary_role,c.amount,c.status,c.earned_at,c.paid_at
		FROM commissions c WHERE `+predicate+` ORDER BY c.earned_at DESC LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, orderID, beneficiaryID int64
		var itemID sql.NullInt64
		var role, status string
		var amount float64
		var earned time.Time
		var paid sql.NullTime
		if err := rows.Scan(&id, &orderID, &itemID, &beneficiaryID, &role, &amount, &status, &earned, &paid); err != nil {
			return nil, nil, err
		}
		// Laravel serializes a DECIMAL attribute as a STRING ("5000.00") and timestamps as Carbon
		// microsecond-UTC; verified against the reference (compare_partials_glm.py commissions run).
		data = append(data, map[string]any{"id": id, "order_id": orderID, "order_item_id": nullInt(itemID),
			"beneficiary_user_id": beneficiaryID, "beneficiary_role": role, "amount": decimal2(amount), "status": status,
			"earned_at": carbonMicro(earned), "paid_at": carbonMicroNull(paid)})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

func (s *Service) CommissionSummary(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	predicate, args, roles := s.commissionScope(actor, q.Get("agent_id"))
	appendDateFilters(&predicate, &args, "c.earned_at", q.Get("from"), q.Get("to"))
	rows, err := s.database.QueryContext(ctx, `SELECT c.beneficiary_role,c.status,SUM(c.amount),COUNT(*)
		FROM commissions c WHERE `+predicate+` GROUP BY c.beneficiary_role,c.status`, args...)
	if err != nil {
		return nil, err
	}
	breakdown := make([]map[string]any, 0)
	for rows.Next() {
		var role, status string
		var amount float64
		var count int
		if err := rows.Scan(&role, &status, &amount, &count); err != nil {
			rows.Close()
			return nil, err
		}
		breakdown = append(breakdown, map[string]any{"beneficiary_role": role, "status": status, "total_amount": decimal2(amount), "count": count})
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	out := map[string]any{"breakdown": breakdown}
	keys := map[string]string{"agent": "total_agent_fee", "sales": "total_sales_fee", "courier": "total_courier_fee"}
	for _, role := range roles {
		var amount float64
		queryArgs := append(append([]any(nil), args...), role)
		if err := s.database.QueryRowContext(ctx, `SELECT COALESCE(SUM(c.amount),0) FROM commissions c WHERE `+predicate+` AND c.beneficiary_role=?`, queryArgs...).Scan(&amount); err != nil {
			return nil, err
		}
		// Verified: Laravel's top-level totals are INTEGERS while the breakdown amounts are decimal strings.
		out[keys[role]] = int64(amount + 0.5)
	}
	return out, nil
}

// Transactions returns the item-grained management report. Korsal scope is
// deliberately narrower than branch scope and super-admin's optional agent
// filter is the only request filter that may select another branch.
func (s *Service) Transactions(ctx context.Context, actor Actor, q url.Values) ([]map[string]any, map[string]int, error) {
	scope, args, err := reportOrderScope(actor, q.Get("agent_id"))
	if err != nil {
		return nil, nil, err
	}
	where := []string{scope}
	appendFilter(&where, &args, "o.created_at >= ?", q.Get("from")+" 00:00:00", q.Get("from") != "")
	appendFilter(&where, &args, "o.created_at < DATE_ADD(?, INTERVAL 1 DAY)", q.Get("to"), q.Get("to") != "")
	appendFilter(&where, &args, "i.requested_delivery_date >= ?", q.Get("delivery_date_from"), q.Get("delivery_date_from") != "")
	appendFilter(&where, &args, "i.requested_delivery_date <= ?", q.Get("delivery_date_to"), q.Get("delivery_date_to") != "")
	appendFilter(&where, &args, "i.status=?", q.Get("item_status"), q.Get("item_status") != "")
	if q.Get("item_status") == "" {
		appendFilter(&where, &args, "i.status=?", q.Get("status"), q.Get("status") != "")
	}
	appendFilter(&where, &args, "o.status=?", q.Get("order_status"), q.Get("order_status") != "")
	appendFilter(&where, &args, "sh.courier_id=?", q.Get("courier_id"), q.Get("courier_id") != "")
	appendFilter(&where, &args, "o.korsal_id=?", q.Get("korsal_id"), q.Get("korsal_id") != "")
	if q.Get("sales_id") != "" {
		where = append(where, `CASE WHEN buyer_roles.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir')
			THEN buyers.id ELSE COALESCE(o.sales_id,o.korsal_id,o.agent_id) END=?`)
		args = append(args, q.Get("sales_id"))
	}
	filter := strings.Join(where, " AND ")
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM order_items i JOIN orders o ON o.id=i.order_id
		LEFT JOIN shipments sh ON sh.id=i.shipment_id LEFT JOIN users buyers ON buyers.id=o.konsumen_id
		LEFT JOIN roles buyer_roles ON buyer_roles.id=buyers.role_id WHERE `+filter, args...).Scan(&total); err != nil {
		return nil, nil, err
	}
	page, perPage := paging(q, 15, 100)
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT o.id,i.id,o.order_no,DATE_FORMAT(o.created_at,'%d/%m/%Y'),
		COALESCE(NULLIF(i.sku_snapshot,''),'-'),
		CASE WHEN COALESCE(i.variation_label_snapshot,'')='' THEN i.product_name_snapshot ELSE CONCAT(i.product_name_snapshot,' - ',i.variation_label_snapshot) END,
		i.unit_price_snapshot+0,i.fulfilled_quantity+0,i.status,i.subtotal_snapshot+0,
		COALESCE(NULLIF(o.recipient_name_snapshot,''),buyers.name,'-'),
		COALESCE(DATE_FORMAT(i.requested_delivery_date,'%d/%m/%Y'),'-'),
		COALESCE(NULLIF(CASE WHEN sh.delivery_mode='self_sub' THEN self_user.name ELSE courier.name END,''),'-'),
		o.status,
		COALESCE(CASE WHEN buyer_roles.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') THEN buyers.name ELSE COALESCE(sales.name,korsal.name,agent.name) END,'-'),
		COALESCE(CASE WHEN buyer_roles.slug='agen' THEN NULL WHEN buyer_roles.slug='korsal' THEN buyers.name ELSE korsal.name END,'-'),
		o.agent_id,
		CASE WHEN buyer_roles.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') THEN buyers.id ELSE COALESCE(o.sales_id,o.korsal_id,o.agent_id) END,
		courier.id,
		o.sales_id,
		o.korsal_id
		FROM order_items i JOIN orders o ON o.id=i.order_id
		LEFT JOIN shipments sh ON sh.id=i.shipment_id LEFT JOIN couriers courier ON courier.id=sh.courier_id
		LEFT JOIN users self_user ON self_user.id=sh.self_delivered_by_user_id
		LEFT JOIN users buyers ON buyers.id=o.konsumen_id LEFT JOIN roles buyer_roles ON buyer_roles.id=buyers.role_id
		LEFT JOIN users sales ON sales.id=o.sales_id LEFT JOIN users korsal ON korsal.id=o.korsal_id
		LEFT JOIN users agent ON agent.id=o.agent_id WHERE `+filter+`
		ORDER BY o.created_at DESC,o.order_no,i.id LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		fields := make([]any, 21)
		dests := make([]any, len(fields))
		for i := range fields {
			dests[i] = &fields[i]
		}
		if err := rows.Scan(dests...); err != nil {
			return nil, nil, err
		}
		// The MySQL driver hands TEXT/VARCHAR columns back as []byte; encoding/json would serialize
		// those as base64 (verified defect), so normalize every scanned field to its JSON-native type.
		for i := range fields {
			if b, ok := fields[i].([]byte); ok {
				fields[i] = string(b)
			}
		}
		data = append(data, map[string]any{"order_id": fields[0], "order_item_id": fields[1], "order_no": fields[2],
			"order_date": fields[3], "sku": fields[4], "product": fields[5], "unit_price": fields[6], "quantity": fields[7],
			"item_status": fields[8], "subtotal": fields[9], "customer": fields[10], "delivery_date": fields[11],
			"courier": fields[12], "order_status": fields[13], "sales": fields[14], "korsal": fields[15],
			"agent_id": fields[16], "sales_referrer_id": fields[17],
			"courier_id": fields[18], "sales_id": fields[19], "korsal_id": fields[20]})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

func (s *Service) DashboardSummary(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	scope, args, err := reportOrderScope(actor, q.Get("agent_id"))
	if err != nil {
		return nil, err
	}
	filter := []string{scope}
	appendFilter(&filter, &args, "o.created_at >= ?", q.Get("from")+" 00:00:00", q.Get("from") != "")
	appendFilter(&filter, &args, "o.created_at < DATE_ADD(?, INTERVAL 1 DAY)", q.Get("to"), q.Get("to") != "")
	if actor.Role == "korsal" {
		filter = append(filter, "(o.korsal_id=? OR o.konsumen_id=? OR o.konsumen_id IN (SELECT id FROM users WHERE korsal_id=?))")
		args = append(args, actor.ID, actor.ID, actor.ID)
	} else if actor.Role == "sales" || actor.Role == "sales-kurir-sub" {
		filter = append(filter, `(o.konsumen_id=? OR (o.sales_id=? AND o.konsumen_id NOT IN
			(SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir'))))`)
		args = append(args, actor.ID, actor.ID)
	}
	where := strings.Join(filter, " AND ")
	var orders, customers, korsals, sales, couriers int
	var revenue float64
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*),COALESCE(SUM(CASE WHEN o.payment_status='paid' THEN o.total_amount ELSE 0 END),0),
		COUNT(DISTINCT o.konsumen_id) FROM orders o WHERE `+where, args...).Scan(&orders, &revenue, &customers); err != nil {
		return nil, err
	}
	if actor.Role != "korsal" && actor.Role != "sales" && actor.Role != "sales-kurir-sub" {
		scopeAgent := ""
		roleArgs := []any{}
		if actor.Role != "super_admin" {
			if actor.AgentID == nil {
				return nil, ErrForbidden
			}
			scopeAgent = " AND u.agent_id=?"
			roleArgs = append(roleArgs, *actor.AgentID)
		} else if q.Get("agent_id") != "" {
			scopeAgent = " AND u.agent_id=?"
			roleArgs = append(roleArgs, q.Get("agent_id"))
		}
		for role, dest := range map[string]*int{"korsal": &korsals, "sales,sales-kurir-sub": &sales, "kurir": &couriers} {
			roles := strings.Split(role, ",")
			in := strings.TrimRight(strings.Repeat("?,", len(roles)), ",")
			ra := make([]any, 0, len(roles)+len(roleArgs))
			for _, roleSlug := range roles {
				ra = append(ra, roleSlug)
			}
			for _, arg := range roleArgs {
				ra = append(ra, arg)
			}
			if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
				WHERE r.slug IN (`+in+`) AND u.deleted_at IS NULL`+scopeAgent, ra...).Scan(dest); err != nil {
				return nil, err
			}
		}
	}
	return map[string]any{"total_orders": orders, "total_revenue": revenue, "total_customers": customers,
		"total_korsal": korsals, "total_sales": sales, "total_kurir": couriers}, nil
}

func reportOrderScope(actor Actor, agentFilter string) (string, []any, error) {
	switch actor.Role {
	case "super_admin":
		if agentFilter == "" {
			return "1=1", nil, nil
		}
		return "o.agent_id=?", []any{agentFilter}, nil
	case "agen", "admin", "sales", "sales-kurir-sub":
		if actor.AgentID == nil {
			return "", nil, ErrForbidden
		}
		return "o.agent_id=?", []any{*actor.AgentID}, nil
	case "korsal":
		if actor.AgentID == nil {
			return "", nil, ErrForbidden
		}
		return "o.agent_id=? AND (o.korsal_id=? OR o.konsumen_id=?)", []any{*actor.AgentID, actor.ID, actor.ID}, nil
	default:
		return "", nil, ErrForbidden
	}
}

func appendDateFilters(where *string, args *[]any, column, from, to string) {
	appendFilterSlice(where, args, column+" >= ?", from+" 00:00:00", from != "")
	appendFilterSlice(where, args, column+" < DATE_ADD(?, INTERVAL 1 DAY)", to, to != "")
}

func appendFilterSlice(where *string, args *[]any, clause string, value any, include bool) {
	if include {
		*where += " AND " + clause
		*args = append(*args, value)
	}
}

func appendFilter(where *[]string, args *[]any, clause string, value any, include bool) {
	if include {
		*where = append(*where, clause)
		*args = append(*args, value)
	}
}

func paging(q url.Values, defaultPerPage, maximum int) (int, int) {
	page, _ := strconv.Atoi(q.Get("page"))
	perPage, _ := strconv.Atoi(q.Get("per_page"))
	if page < 1 {
		page = 1
	}
	if perPage < 1 {
		perPage = defaultPerPage
	}
	if perPage > maximum {
		perPage = maximum
	}
	return page, perPage
}
func meta(page, perPage, total int) map[string]int {
	last := (total + perPage - 1) / perPage
	if last < 1 {
		last = 1
	}
	return map[string]int{"current_page": page, "last_page": last, "total": total}
}
func nullInt(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}
func nullTime(v sql.NullTime) any {
	if v.Valid {
		return v.Time
	}
	return nil
}

// decimal2 renders a DECIMAL value the way Laravel's Eloquent string cast does ("5000.00").
func decimal2(v float64) string {
	return strconv.FormatFloat(v, 'f', 2, 64)
}

// carbonMicro formats a timestamp the way Laravel timestamps serialize: UTC microseconds + Z.
func carbonMicro(t time.Time) string {
	return t.UTC().Format("2006-01-02T15:04:05.000000Z")
}

func carbonMicroNull(v sql.NullTime) any {
	if v.Valid {
		return carbonMicro(v.Time)
	}
	return nil
}

// ValidateAlias is a tiny guard used by tests to pin SQL construction to
// constant column references rather than accepting request-supplied SQL.
func ValidateAlias(alias string) error {
	if alias == "" || strings.ContainsAny(alias, " ;\t\n") {
		return fmt.Errorf("invalid SQL alias")
	}
	return nil
}
