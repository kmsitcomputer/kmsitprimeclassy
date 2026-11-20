package reporting

import (
	"context"
	"database/sql"
	"fmt"
	"math"
	"net/url"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// DashboardKPIs ports DashboardKpiService's role-shaped projection. Each key is
// emitted only for the roles to which Laravel exposes it.
func (s *Service) DashboardKPIs(ctx context.Context, actor Actor, filters url.Values) (map[string]any, error) {
	out := map[string]any{}
	if actor.Role == "agen" {
		ledger, err := s.kpiLedger(ctx, actor.ID, "", filters)
		if err != nil {
			return nil, err
		}
		byRole := map[string]float64{}
		where, args := kpiCommissionWhere(actor.ID, "", filters)
		rows, err := s.database.QueryContext(ctx, `SELECT beneficiary_role,SUM(amount) FROM commissions WHERE `+where+` AND status<>'reversed' GROUP BY beneficiary_role`, args...)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var role string
			var amount float64
			if err := rows.Scan(&role, &amount); err != nil {
				rows.Close()
				return nil, err
			}
			byRole[role] = roundKPI(amount)
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
		var byRoleValue any = byRole
		if len(byRole) == 0 {
			byRoleValue = []any{}
		}
		ledger["by_role"] = byRoleValue
		out["own_fee"] = ledger
	}
	if actor.Role == "korsal" {
		team, ids, err := s.kpiTeam(ctx, actor, filters)
		if err != nil {
			return nil, err
		}
		out["team_commissions"] = team
		deals, err := s.kpiDeals(ctx, ids, filters)
		if err != nil {
			return nil, err
		}
		out["deals"] = deals
	}
	if actor.Role == "sales" || actor.Role == "sales-kurir-sub" {
		deals, err := s.kpiDeals(ctx, []int64{actor.ID}, filters)
		if err != nil {
			return nil, err
		}
		out["deals"] = deals
		ledger, err := s.kpiLedger(ctx, actor.ID, "sales", filters)
		if err != nil {
			return nil, err
		}
		out["commission"] = ledger
	}
	if actor.Role == "sales" || actor.Role == "sales-kurir-sub" || actor.Role == "korsal" {
		followups, err := s.kpiFollowUps(ctx, actor)
		if err != nil {
			return nil, err
		}
		out["follow_ups"] = followups
	}
	if actor.Role == "gudang" {
		stock, err := s.kpiAvailableStock(ctx, actor.AgentID)
		if err != nil {
			return nil, err
		}
		out["available_stock"] = stock
	}
	if actor.Role == "kurir" {
		tasks, err := s.kpiCourierTasks(ctx, actor)
		if err != nil {
			return nil, err
		}
		out["tasks_today"] = tasks
	}
	return out, nil
}

func kpiCommissionWhere(userID int64, role string, filters url.Values) (string, []any) {
	where := "beneficiary_user_id=?"
	args := []any{userID}
	if role != "" {
		where += " AND beneficiary_role=?"
		args = append(args, role)
	}
	if from := filters.Get("from"); from != "" {
		where += " AND earned_at>=?"
		args = append(args, from+" 00:00:00")
	}
	if to := filters.Get("to"); to != "" {
		where += " AND earned_at<DATE_ADD(?,INTERVAL 1 DAY)"
		args = append(args, to)
	}
	return where, args
}

func (s *Service) kpiLedger(ctx context.Context, userID int64, role string, filters url.Values) (map[string]any, error) {
	where, args := kpiCommissionWhere(userID, role, filters)
	rows, err := s.database.QueryContext(ctx, `SELECT status,SUM(amount),COUNT(*) FROM commissions WHERE `+where+` GROUP BY status`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	amounts := map[string]float64{}
	counts := map[string]int{}
	for rows.Next() {
		var status string
		var amount sql.NullFloat64
		var n int
		if err := rows.Scan(&status, &amount, &n); err != nil {
			return nil, err
		}
		amounts[status] = amount.Float64
		counts[status] = n
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	pending, approved, paid, reversed := roundKPI(amounts["pending"]), roundKPI(amounts["approved"]), roundKPI(amounts["paid"]), roundKPI(amounts["reversed"])
	return map[string]any{"total": roundKPI(pending + approved + paid), "pending": pending, "approved": approved, "paid": paid, "reversed": reversed, "count": counts["pending"] + counts["approved"] + counts["paid"]}, nil
}

func (s *Service) kpiTeam(ctx context.Context, actor Actor, filters url.Values) (map[string]any, []int64, error) {
	if actor.AgentID == nil {
		return nil, nil, ErrForbidden
	}
	rows, err := s.database.QueryContext(ctx, `SELECT u.id,u.name,r.slug,u.status FROM users u JOIN roles r ON r.id=u.role_id WHERE u.agent_id=? AND (u.id=? OR (u.korsal_id=? AND r.slug IN ('sales','sales-kurir-sub','sales-kurir'))) ORDER BY (u.id=?) DESC,u.name`, *actor.AgentID, actor.ID, actor.ID, actor.ID)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	type member struct {
		id                 int64
		name, role, status string
	}
	members := []member{}
	for rows.Next() {
		var m member
		if err := rows.Scan(&m.id, &m.name, &m.role, &m.status); err != nil {
			return nil, nil, err
		}
		members = append(members, m)
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	ids := make([]int64, 0, len(members))
	out := make([]map[string]any, 0, len(members))
	totals := map[string]float64{"total": 0, "pending": 0, "approved": 0, "paid": 0, "reversed": 0}
	for _, m := range members {
		ids = append(ids, m.id)
		ledger, err := s.kpiLedger(ctx, m.id, "sales", filters)
		if err != nil {
			return nil, nil, err
		}
		var orders int
		var value float64
		if err := s.database.QueryRowContext(ctx, `SELECT COUNT(DISTINCT a.id),COALESCE(SUM(a.total_amount),0) FROM orders a LEFT JOIN users b ON b.id=a.konsumen_id LEFT JOIN roles br ON br.id=b.role_id WHERE `+reportPeriodSQL("a", filters)+` AND (CASE WHEN br.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') THEN a.konsumen_id ELSE COALESCE(a.sales_id,a.korsal_id,a.agent_id) END)=?`, append(reportPeriodArgs(filters), m.id)...).Scan(&orders, &value); err != nil {
			return nil, nil, err
		}
		row := map[string]any{"user_id": m.id, "name": m.name, "role": m.role, "is_self": m.id == actor.ID, "account_status": m.status, "pending": ledger["pending"], "approved": ledger["approved"], "paid": ledger["paid"], "reversed": ledger["reversed"], "total": ledger["total"], "order_count": orders, "order_value": roundKPI(value)}
		for k := range totals {
			totals[k] = roundKPI(totals[k] + ledger[k].(float64))
		}
		out = append(out, row)
	}
	return map[string]any{"members": out, "totals": totals}, ids, nil
}

func reportPeriodSQL(alias string, q url.Values) string {
	where := "1=1"
	if q.Get("from") != "" {
		where += fmt.Sprintf(" AND %s.created_at>=?", alias)
	}
	if q.Get("to") != "" {
		where += fmt.Sprintf(" AND %s.created_at<DATE_ADD(?,INTERVAL 1 DAY)", alias)
	}
	return where
}
func reportPeriodArgs(q url.Values) []any {
	a := []any{}
	if q.Get("from") != "" {
		a = append(a, q.Get("from")+" 00:00:00")
	}
	if q.Get("to") != "" {
		a = append(a, q.Get("to"))
	}
	return a
}

func (s *Service) kpiDeals(ctx context.Context, ids []int64, filters url.Values) (map[string]any, error) {
	if len(ids) == 0 {
		return map[string]any{"count": 0, "value": 0.0}, nil
	}
	marks := ""
	args := reportPeriodArgs(filters)
	for i, id := range ids {
		if i > 0 {
			marks += ","
		}
		marks += "?"
		args = append(args, id)
	}
	var n int
	var v float64
	err := s.database.QueryRowContext(ctx, `SELECT COUNT(DISTINCT a.id),COALESCE(SUM(a.total_amount),0) FROM orders a LEFT JOIN users b ON b.id=a.konsumen_id LEFT JOIN roles br ON br.id=b.role_id WHERE `+reportPeriodSQL("a", filters)+` AND (CASE WHEN br.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') THEN a.konsumen_id ELSE COALESCE(a.sales_id,a.korsal_id,a.agent_id) END) IN (`+marks+")", args...).Scan(&n, &v)
	return map[string]any{"count": n, "value": roundKPI(v)}, err
}

func (s *Service) kpiFollowUps(ctx context.Context, actor Actor) (map[string]any, error) {
	where, args, err := kpiCRMVisible(actor)
	if err != nil {
		return nil, err
	}
	var due, over int
	today := time.Now().In(time.FixedZone("WIB", 7*3600)).Format("2006-01-02")
	base := `FROM crm_visits v JOIN crm_customers c ON c.id=v.crm_customer_id WHERE ` + where + ` AND c.archived_at IS NULL AND v.id=(SELECT MAX(v2.id) FROM crm_visits v2 WHERE v2.crm_customer_id=c.id) AND v.plan_next_date IS NOT NULL`
	if err = s.database.QueryRowContext(ctx, `SELECT COUNT(*) `+base+` AND DATE(v.plan_next_date)=?`, append(args, today)...).Scan(&due); err != nil {
		return nil, err
	}
	if err = s.database.QueryRowContext(ctx, `SELECT COUNT(*) `+base+` AND DATE(v.plan_next_date)<?`, append(args, today)...).Scan(&over); err != nil {
		return nil, err
	}
	return map[string]any{"due_today": due, "overdue": over}, nil
}
func kpiCRMVisible(a Actor) (string, []any, error) {
	switch a.Role {
	case "sales", "sales-kurir-sub":
		return "c.owner_user_id=?", []any{a.ID}, nil
	case "korsal":
		if a.AgentID == nil {
			return "1=0", nil, nil
		}
		return "c.agent_id=? AND (c.owner_user_id=? OR c.owner_user_id IN (SELECT id FROM users WHERE korsal_id=? AND deleted_at IS NULL))", []any{*a.AgentID, a.ID, a.ID}, nil
	default:
		return "", nil, ErrForbidden
	}
}

func (s *Service) kpiAvailableStock(ctx context.Context, agentID *int64) (map[string]any, error) {
	if agentID == nil {
		return map[string]any{"units": 0, "targets": 0}, nil
	}
	rows, err := s.database.QueryContext(ctx, `SELECT product_id,CAST(NULL AS SIGNED) FROM product_stocks WHERE agent_id=? UNION SELECT product_id,CAST(NULL AS SIGNED) FROM warehouse_stocks WHERE agent_id=? AND product_id IS NOT NULL UNION SELECT CAST(NULL AS SIGNED),product_variation_id FROM product_variation_stocks WHERE agent_id=? UNION SELECT CAST(NULL AS SIGNED),product_variation_id FROM warehouse_stocks WHERE agent_id=? AND product_variation_id IS NOT NULL`, *agentID, *agentID, *agentID, *agentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	units, targets := 0, 0
	for rows.Next() {
		var p, v sql.NullInt64
		if err := rows.Scan(&p, &v); err != nil {
			return nil, err
		}
		var stock inventory.Sellable
		if p.Valid {
			var ok int
			if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM products WHERE id=? AND deleted_at IS NULL`, p.Int64).Scan(&ok); err != nil {
				return nil, err
			}
			if ok == 0 {
				continue
			}
			stock, err = inventory.ForProduct(ctx, s.database, *agentID, p.Int64)
		} else if v.Valid {
			var ok int
			if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM product_variations v JOIN products p ON p.id=v.product_id WHERE v.id=? AND v.deleted_at IS NULL AND p.deleted_at IS NULL`, v.Int64).Scan(&ok); err != nil {
				return nil, err
			}
			if ok == 0 {
				continue
			}
			stock, err = inventory.ForVariation(ctx, s.database, *agentID, v.Int64)
		}
		if err != nil {
			return nil, err
		}
		units += stock.Available
		targets++
	}
	return map[string]any{"units": units, "targets": targets}, rows.Err()
}

func (s *Service) kpiCourierTasks(ctx context.Context, actor Actor) (map[string]any, error) {
	today := time.Now().In(time.FixedZone("WIB", 7*3600)).Format("2006-01-02")
	var courier sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id=? LIMIT 1`, actor.ID).Scan(&courier); err != nil && err != sql.ErrNoRows {
		return nil, err
	}
	deliveries, pickups := 0, 0
	if courier.Valid {
		if err := s.database.QueryRowContext(ctx, `SELECT COUNT(DISTINCT i.shipment_id) FROM order_items i JOIN shipments sh ON sh.id=i.shipment_id JOIN orders o ON o.id=i.order_id WHERE DATE(i.requested_delivery_date)=? AND i.status IN ('diproses','dikirim') AND sh.courier_id=? AND sh.delivery_mode='standard' AND o.agent_id=?`, today, courier.Int64, nullableAgent(actor.AgentID)).Scan(&deliveries); err != nil {
			return nil, err
		}
		if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id JOIN orders o ON o.id=oi.order_id WHERE ri.status IN ('pending','approved') AND ri.pickup_scheduled_for=? AND oi.status='pengembalian' AND o.agent_id=? AND ri.courier_id=?`, today, nullableAgent(actor.AgentID), courier.Int64).Scan(&pickups); err != nil {
			return nil, err
		}
	}
	return map[string]any{"date": today, "deliveries": deliveries, "return_pickups": pickups}, nil
}
func nullableAgent(id *int64) any {
	if id == nil {
		return nil
	}
	return *id
}
func roundKPI(v float64) float64 {
	v = math.Round(v*100) / 100
	if v == 0 {
		return 0
	}
	return v
}
