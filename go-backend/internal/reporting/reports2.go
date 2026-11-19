package reporting

import (
	"context"
	"database/sql"
	"errors"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// sellerSlugsSQL is Role::sellerSlugs() for SQL IN lists ('sales','sales-kurir-sub','sales-kurir').
const sellerSlugsSQL = "'sales','sales-kurir-sub','sales-kurir'"

// collectibleSQL is CollectibleReceivable::sql($alias): 0 for cancelled orders, otherwise
// GREATEST(total - paid, 0) — never the stored remaining_amount.
func collectibleSQL(alias string) string {
	return "(CASE WHEN " + alias + ".status = 'dibatalkan' THEN 0 ELSE GREATEST(" + alias + ".total_amount - COALESCE(" + alias + ".paid_amount, 0), 0) END)"
}

// dateFilterRaw renders a literal-substituted inclusive day-range filter (input validated upstream).
func dateFilterRaw(column, from, to string) string {
	out := ""
	if from != "" {
		out += " AND " + column + " >= '" + sanitizeDate(from) + " 00:00:00'"
	}
	if to != "" {
		out += " AND " + column + " < DATE_ADD('" + sanitizeDate(to) + "', INTERVAL 1 DAY)"
	}
	return out
}

// sanitizeDate keeps only characters legal in a Y-m-d literal, so raw filters can never inject SQL.
func sanitizeDate(v string) string {
	out := make([]rune, 0, len(v))
	for _, r := range v {
		if (r >= '0' && r <= '9') || r == '-' {
			out = append(out, r)
		}
	}
	return string(out)
}

func joinWhere(parts []string) string { return strings.Join(parts, " AND ") }

// filterArgs returns the positional args for the shared from/to filters.
func filterArgs(q url.Values) []any {
	args := []any{}
	if v := q.Get("from"); v != "" {
		args = append(args, sanitizeDate(v)+" 00:00:00")
	}
	if v := q.Get("to"); v != "" {
		args = append(args, sanitizeDate(v))
	}
	return args
}

// attributedOrdersSQL mirrors ReportService::attributedOrders: one row per order with canonical
// referrer attribution (self-purchase when the buyer holds a seller role, else sales→korsal→agent).
func attributedOrdersSQL(q url.Values) (string, []any) {
	dateFilter := dateFilterRaw("ao.created_at", q.Get("from"), q.Get("to"))
	sql := `SELECT ao.id AS order_id, ao.konsumen_id, ao.total_amount,
			CASE WHEN abr.slug IN (` + sellerSlugsSQL + `) THEN ao.konsumen_id
			     ELSE COALESCE(ao.sales_id, ao.korsal_id, ao.agent_id) END AS referrer_id
		 FROM orders ao
		 LEFT JOIN users ab ON ab.id = ao.konsumen_id
		 LEFT JOIN roles abr ON abr.id = ab.role_id
		 WHERE 1=1` + dateFilter
	return sql, filterArgs(q)
}

// attributedToSellerSQL mirrors ReportService::attributedToSeller: the seller's own purchases plus
// orders credited to them via sales_id whose buyer is NOT itself a seller.
func attributedToSellerSQL(ordersAlias string, sellerID int64) (string, []any) {
	clause := "(" + ordersAlias + ".konsumen_id = ? OR (" + ordersAlias + ".sales_id = ? AND " +
		ordersAlias + ".konsumen_id NOT IN (SELECT sb.id FROM users sb JOIN roles sbr ON sbr.id = sb.role_id WHERE sbr.slug IN (" + sellerSlugsSQL + "))))"
	return clause, []any{sellerID, sellerID}
}

// scopeUsersAgentKorsal is scopeToActor(users.agent_id, users.korsal_id): korsal actors see only
// their own downline.
func (s *Service) scopeUsersAgentKorsal(actor Actor, q url.Values) ([]string, []any) {
	where, args := s.scopeUsersAgent(actor, q)
	if actor.Role == "korsal" && actor.AgentID != nil {
		where = append(where, "users.korsal_id = ?")
		args = append(args, actor.ID)
	}
	return where, args
}

// KorsalRoster mirrors ReportService::korsalReport: every korsal in the branch with order-attribution
// based transaction/active-sales counts and ledger-derived total sales fee. Returns a full ROSTER —
// a korsal with zero activity still appears.
func (s *Service) KorsalRoster(ctx context.Context, actor Actor, q url.Values, page, perPage int) ([]map[string]any, map[string]int, error) {
	orderStats, orderArgs := attributedOrdersSQL(q)
	orderStatsSub := `SELECT CASE WHEN sr.slug = 'korsal' THEN su.id ELSE su.korsal_id END AS korsal_id,
			COUNT(DISTINCT CASE WHEN sr.slug <> 'korsal' THEN att.referrer_id END) AS active_sales_count,
			COUNT(DISTINCT att.order_id) AS transaction_count
		 FROM (` + orderStats + `) att
		 JOIN users su ON su.id = att.referrer_id
		 JOIN roles sr ON sr.id = su.role_id
		 GROUP BY CASE WHEN sr.slug = 'korsal' THEN su.id ELSE su.korsal_id END
		 HAVING korsal_id IS NOT NULL`
	feeStatsSub := `SELECT su.korsal_id, SUM(commissions.amount) AS total_sales_fee
		 FROM commissions
		 JOIN users su ON su.id = commissions.beneficiary_user_id
		 WHERE commissions.beneficiary_role = 'sales'` + dateFilterRaw("commissions.earned_at", q.Get("from"), q.Get("to")) + `
		 GROUP BY su.korsal_id`

	scope, sargs := s.scopeUsersAgent(actor, q)
	where := []string{"r.slug = 'korsal'", "users.deleted_at IS NULL"}
	where = append(where, scope...)
	args := append([]any{}, sargs...)
	if v := q.Get("search"); v != "" {
		where = append(where, "(users.name LIKE ? OR users.phone LIKE ?)")
		term := "%" + v + "%"
		args = append(args, term, term)
	}
	if v := q.Get("status"); v != "" {
		where = append(where, "users.status = ?")
		args = append(args, v)
	}
	filter := joinWhere(where)

	var total int
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM users JOIN roles r ON r.id = users.role_id WHERE `+filter, args...).Scan(&total); err != nil {
		return nil, nil, err
	}
	// Placeholder order follows the SQL text: orderStats subquery, feeStats subquery, WHERE args, LIMIT/OFFSET.
	feeArgs := filterArgs(q)
	whereArgs := append([]any{}, args...)
	args = append(append(append(append([]any{}, orderArgs...), feeArgs...), whereArgs...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx,
		`SELECT users.id, users.name, users.phone, users.status,
				COALESCE(stats.active_sales_count, 0), COALESCE(stats.transaction_count, 0), COALESCE(fee_stats.total_sales_fee, 0)
		 FROM users
		 JOIN roles r ON r.id = users.role_id
		 LEFT JOIN (`+orderStatsSub+`) stats ON stats.korsal_id = users.id
		 LEFT JOIN (`+feeStatsSub+`) fee_stats ON fee_stats.korsal_id = users.id
		 WHERE `+filter+` ORDER BY users.name LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name, status string
		var phone sql.NullString
		var activeSales, txCount int
		var totalFee sql.NullString
		if err := rows.Scan(&id, &name, &phone, &status, &activeSales, &txCount, &totalFee); err != nil {
			return nil, nil, err
		}
		data = append(data, map[string]any{
			"korsal_id":          id,
			"korsal_name":        name,
			"korsal_phone":       nullString(phone),
			"status":             status,
			"active_sales_count": activeSales,
			"transaction_count":  txCount,
			"total_sales_fee":    nullString(totalFee),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

// SalesRoster mirrors ReportService::salesReport: full sales roster with order-attribution counts and
// commission-ledger fee. Every sales under the branch appears, active or not.
func (s *Service) SalesRoster(ctx context.Context, actor Actor, q url.Values, page, perPage int) ([]map[string]any, map[string]int, error) {
	orderStats, orderArgs := attributedOrdersSQL(q)
	orderStatsSub := `SELECT att.referrer_id,
			COUNT(DISTINCT att.order_id) AS transaction_count,
			COUNT(DISTINCT att.konsumen_id) AS customer_count,
			SUM(att.total_amount) AS transaction_value
		 FROM (` + orderStats + `) att
		 GROUP BY att.referrer_id`
	feeStatsSub := `SELECT beneficiary_user_id, SUM(amount) AS total_fee
		 FROM commissions
		 WHERE beneficiary_role = 'sales'` + dateFilterRaw("commissions.earned_at", q.Get("from"), q.Get("to")) + `
		 GROUP BY beneficiary_user_id`

	scope, sargs := s.scopeUsersAgentKorsal(actor, q)
	where := []string{"r.slug IN (" + sellerSlugsSQL + ")", "users.deleted_at IS NULL"}
	where = append(where, scope...)
	whereArgs := append([]any{}, sargs...)
	if v := q.Get("korsal_id"); v != "" {
		where = append(where, "users.korsal_id = ?")
		whereArgs = append(whereArgs, v)
	}
	if v := q.Get("search"); v != "" {
		where = append(where, "(users.name LIKE ? OR users.phone LIKE ?)")
		term := "%" + v + "%"
		whereArgs = append(whereArgs, term, term)
	}
	if v := q.Get("status"); v != "" {
		where = append(where, "users.status = ?")
		whereArgs = append(whereArgs, v)
	}
	filter := joinWhere(where)

	var total int
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM users JOIN roles r ON r.id = users.role_id WHERE `+filter, whereArgs...).Scan(&total); err != nil {
		return nil, nil, err
	}
	feeArgs := filterArgs(q)
	queryArgs := append(append(append(append([]any{}, orderArgs...), feeArgs...), whereArgs...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx,
		`SELECT users.id, users.name, users.phone, users.status, COALESCE(korsal_users.name, ''),
				COALESCE(cs.transaction_count, 0), COALESCE(fs.total_fee, 0), COALESCE(cs.customer_count, 0), COALESCE(cs.transaction_value, 0)
		 FROM users
		 JOIN roles r ON r.id = users.role_id
		 LEFT JOIN users korsal_users ON korsal_users.id = users.korsal_id
		 LEFT JOIN (`+orderStatsSub+`) cs ON cs.referrer_id = users.id
		 LEFT JOIN (`+feeStatsSub+`) fs ON fs.beneficiary_user_id = users.id
		 WHERE `+filter+` ORDER BY users.name LIMIT ? OFFSET ?`, queryArgs...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name, status string
		var phone, korsalName sql.NullString
		var txCount, customerCount int
		var totalFee, txValue sql.NullString
		if err := rows.Scan(&id, &name, &phone, &status, &korsalName, &txCount, &totalFee, &customerCount, &txValue); err != nil {
			return nil, nil, err
		}
		data = append(data, map[string]any{
			"sales_id":          id,
			"sales_name":        name,
			"sales_phone":       nullString(phone),
			"status":            status,
			"korsal_name":       nullString(korsalName),
			"transaction_count": txCount,
			"total_fee":         nullString(totalFee),
			"customer_count":    customerCount,
			"transaction_value": nullString(txValue),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}
	return data, meta(page, perPage, total), nil
}

// SalesCustomersReport mirrors ReportService::salesCustomersReport + salesCustomersFeeTotal.
func (s *Service) SalesCustomersReport(ctx context.Context, actor Actor, q url.Values, page, perPage int) ([]map[string]any, map[string]int, float64, error) {
	attrClause, attrArgs := attributedToSellerSQL("orders", actor.ID)
	dateFilter := dateFilterRaw("orders.created_at", q.Get("from"), q.Get("to"))
	where := "(" + attrClause + ")" + dateFilter
	args := append([]any{}, attrArgs...)
	if v := q.Get("search"); v != "" {
		where += " AND (konsumen_users.name LIKE ? OR konsumen_users.phone LIKE ?)"
		term := "%" + v + "%"
		args = append(args, term, term)
	}
	groupBy := "konsumen_users.id, konsumen_users.name, konsumen_users.phone"

	var total int
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM (SELECT 1 FROM orders
		 JOIN users konsumen_users ON konsumen_users.id = orders.konsumen_id
		 LEFT JOIN commissions c2 ON c2.order_id = orders.id AND c2.beneficiary_user_id = ? AND c2.beneficiary_role = 'sales'
		 WHERE `+where+` GROUP BY `+groupBy+`) t`,
		append([]any{actor.ID}, args...)...).Scan(&total); err != nil {
		return nil, nil, 0, err
	}
	pagedArgs := append(append([]any{actor.ID}, args...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx,
		`SELECT konsumen_users.id, konsumen_users.name, konsumen_users.phone,
				COUNT(DISTINCT orders.id) AS order_count, COALESCE(SUM(commissions.amount), 0) AS total_fee
		 FROM orders
		 JOIN users konsumen_users ON konsumen_users.id = orders.konsumen_id
		 LEFT JOIN commissions ON commissions.order_id = orders.id
			AND commissions.beneficiary_user_id = ? AND commissions.beneficiary_role = 'sales'
		 WHERE `+where+` GROUP BY `+groupBy+` ORDER BY order_count DESC LIMIT ? OFFSET ?`, pagedArgs...)
	if err != nil {
		return nil, nil, 0, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name, phone string
		var orderCount int
		var totalFee any
		if err := rows.Scan(&id, &name, &phone, &orderCount, &totalFee); err != nil {
			return nil, nil, 0, err
		}
		data = append(data, map[string]any{
			"konsumen_id":    id,
			"konsumen_name":  name,
			"konsumen_phone": phone,
			"order_count":    orderCount,
			"total_fee":      wire(totalFee),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, 0, err
	}
	var feeTotal float64
	feeFilter := dateFilterRaw("earned_at", q.Get("from"), q.Get("to"))
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(amount), 0) FROM commissions WHERE beneficiary_user_id = ? AND beneficiary_role = 'sales'`+feeFilter,
		append([]any{actor.ID}, filterArgs(q)...)...).Scan(&feeTotal); err != nil {
		return nil, nil, 0, err
	}
	return data, meta(page, perPage, total), feeTotal, nil
}

// FinanceSummary mirrors ReportService::financeSummary (legacy refund reporting source): platform-wide
// or branch summary of paid value, collected cash, refunds from both paths and collectible receivables.
func (s *Service) FinanceSummary(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	scope, sargs := s.scopeUsersAgentQualified(actor, q, "orders.agent_id")
	ordersWhere := append([]string{}, scope...)
	ordersArgs := append([]any{}, sargs...)
	if tail := dateFilterTail("created_at", q.Get("from"), q.Get("to")); tail != "" {
		ordersWhere = append(ordersWhere, tail)
	}
	ordersFilter := joinWhere(ordersWhere)

	var totalOrders int
	var totalTransactions, totalReceived, totalOutstanding, totalDpOutstanding float64
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*),
				COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0),
				COALESCE(SUM(paid_amount), 0),
				COALESCE(SUM(`+collectibleSQL("orders")+`), 0),
				COALESCE(SUM(CASE WHEN payment_status = 'partially_paid' THEN `+collectibleSQL("orders")+` ELSE 0 END), 0)
		 FROM orders WHERE `+ordersFilter, ordersArgs...).Scan(
		&totalOrders, &totalTransactions, &totalReceived, &totalOutstanding, &totalDpOutstanding); err != nil {
		return nil, err
	}

	adjScope, adjArgs := s.scopeUsersAgentQualified(actor, q, "orders.agent_id")
	adjWhere := append([]string{}, adjScope...)
	if tail := dateFilterTail("order_item_adjustments.created_at", q.Get("from"), q.Get("to")); tail != "" {
		adjWhere = append(adjWhere, tail)
	}
	var totalAdjustmentRefunds float64
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(order_item_adjustments.refund_amount), 0)
		 FROM order_item_adjustments
		 JOIN order_items ON order_items.id = order_item_adjustments.order_item_id
		 JOIN orders ON orders.id = order_items.order_id
		 WHERE `+joinWhere(adjWhere), adjArgs...).Scan(&totalAdjustmentRefunds); err != nil {
		return nil, err
	}

	retScope, retArgs := s.scopeUsersAgentQualified(actor, q, "orders.agent_id")
	retWhere := append([]string{}, retScope...)
	if tail := dateFilterTail("returns.created_at", q.Get("from"), q.Get("to")); tail != "" {
		retWhere = append(retWhere, tail)
	}
	var totalReturnRefunds float64
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(return_items.refund_amount), 0)
		 FROM return_items
		 JOIN returns ON returns.id = return_items.return_id
		 JOIN order_items ON order_items.id = return_items.order_item_id
		 JOIN orders ON orders.id = order_items.order_id
		 WHERE `+joinWhere(retWhere), retArgs...).Scan(&totalReturnRefunds); err != nil {
		return nil, err
	}

	return map[string]any{
		"total_orders":         totalOrders,
		"total_transactions":   round2(totalTransactions),
		"total_received":       round2(totalReceived),
		"total_refunds":        totalAdjustmentRefunds + totalReturnRefunds,
		"total_outstanding":    round2(totalOutstanding),
		"total_dp_outstanding": round2(totalDpOutstanding),
	}, nil
}

// scopeUsersAgentQualified is scopeUsersAgent with a qualified agent column (orders.agent_id).
func (s *Service) scopeUsersAgentQualified(actor Actor, q url.Values, agentColumn string) ([]string, []any) {
	col := strings.TrimPrefix(strings.ReplaceAll(agentColumn, "orders.", ""), "")
	if agentColumn == "orders.agent_id" {
		col = "orders.agent_id"
	}
	_ = col
	where := []string{}
	args := []any{}
	if actor.Role == "super_admin" {
		if id := q.Get("agent_id"); id != "" {
			where = append(where, agentColumn+" = ?")
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
	where = append(where, agentColumn+" = ?")
	args = append(args, *actor.AgentID)
	return where, args
}

// dateFilterTail is dateFilterRaw without the leading " AND " (safe on empty input).
func dateFilterTail(column, from, to string) string {
	f := dateFilterRaw(column, from, to)
	if f == "" {
		return ""
	}
	return f[5:]
}

func round2(v float64) float64 { return float64(int(v*100+0.5)) / 100 }

// FinanceOrders mirrors ReportService::financeOrders: one canonical Order row per order with figures
// straight from PaymentSummaryService (Order-level payment truth, never re-derived per item).
func (s *Service) FinanceOrders(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	scope, sargs := s.scopeUsersAgentQualified(actor, q, "orders.agent_id")
	where := append([]string{}, scope...)
	args := append([]any{}, sargs...)
	if v := q.Get("from"); v != "" {
		where = append(where, "DATE(orders.created_at) >= ?")
		args = append(args, v)
	}
	if v := q.Get("to"); v != "" {
		where = append(where, "DATE(orders.created_at) <= ?")
		args = append(args, v)
	}
	if v := q.Get("order_status"); v != "" {
		where = append(where, "orders.status = ?")
		args = append(args, v)
	}
	if v := q.Get("search"); v != "" {
		where = append(where, "(orders.order_no LIKE ? OR orders.recipient_name_snapshot LIKE ? OR EXISTS (SELECT 1 FROM users ku WHERE ku.id = orders.konsumen_id AND ku.name LIKE ?))")
		term := "%" + v + "%"
		args = append(args, term, term, term)
	}
	receivableExpr := collectibleSQL("orders")
	if q.Get("receivable") != "" {
		where = append(where, receivableExpr+" > 0")
	}
	jakarta := jakartaToday()
	switch q.Get("due_filter") {
	case "due":
		where = append(where, "orders.due_date = ?")
		args = append(args, jakarta)
	case "overdue":
		where = append(where, "orders.due_date < ?")
		args = append(args, jakarta)
	}
	if v := q.Get("due_date_from"); v != "" {
		where = append(where, "orders.due_date >= ?")
		args = append(args, v)
	}
	if v := q.Get("due_date_to"); v != "" {
		where = append(where, "orders.due_date <= ?")
		args = append(args, v)
	}
	filter := joinWhere(where)

	var count int
	var outstanding float64
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*), COALESCE(SUM(`+receivableExpr+`), 0) FROM orders WHERE `+filter, args...).Scan(&count, &outstanding); err != nil {
		return nil, err
	}
	perPage, _ := strconvAtoi(q.Get("per_page"), 15)
	page, _ := strconvAtoi(q.Get("page"), 1)
	if page < 1 {
		page = 1
	}
	lastPage := (count + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	allowedFeeRoles := []string{"sales", "courier"}
	if actor.Role == "super_admin" || actor.Role == "agen" {
		allowedFeeRoles = []string{"agent", "sales", "courier"}
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT orders.id, orders.order_no, DATE_FORMAT(orders.created_at, '%Y-%m-%d') AS order_date, orders.recipient_name_snapshot,
				orders.buyer_name_snapshot, ku.name, pm.name, pm.type,
				orders.total_amount, orders.dp_amount, orders.paid_amount, orders.payment_status,
				DATE_FORMAT(orders.due_date, '%Y-%m-%d') AS due_date,
				`+receivableExpr+`,
				COALESCE((SELECT SUM(ap.amount) FROM order_additional_payments ap WHERE ap.order_id = orders.id AND ap.status = 'pending'), 0),
				COALESCE((SELECT SUM(a.refund_amount) FROM order_item_adjustments a JOIN order_items i ON i.id = a.order_item_id WHERE i.order_id = orders.id AND a.refund_status = 'pending'), 0)
		 FROM orders
		 LEFT JOIN users ku ON ku.id = orders.konsumen_id
		 LEFT JOIN payment_methods pm ON pm.id = orders.payment_method_id
		 WHERE `+filter+` ORDER BY orders.created_at DESC, orders.id DESC LIMIT ? OFFSET ?`,
		append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	orders := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var orderNo, recipientSnapshot string
		var orderDate string
		var buyerSnapshot, konsumenName, pmName, pmType sql.NullString
		var grandTotal, dpAmount, paidAmount float64
		var paymentStatus string
		var dueDate sql.NullString
		var remaining, pendingAdditional, pendingRefund float64
		if err := rows.Scan(&id, &orderNo, &orderDate, &recipientSnapshot, &buyerSnapshot, &konsumenName,
			&pmName, &pmType, &grandTotal, &dpAmount, &paidAmount, &paymentStatus, &dueDate, &remaining,
			&pendingAdditional, &pendingRefund); err != nil {
			return nil, err
		}
		customer := konsumenName.String
		if customer == "" {
			customer = buyerSnapshot.String
		}
		submittedDp, pendingStatus, hasPendingProof, err := s.pendingProofFor(ctx, id)
		if err != nil {
			return nil, err
		}
		verifiedDp := 0.0
		if dpAmount > 0 {
			verifiedDp = dpAmount
			if paidAmount < verifiedDp {
				verifiedDp = paidAmount
			}
		}
		fees := map[string]float64{}
		for _, role := range allowedFeeRoles {
			fees[role] = 0
		}
		feeRows, err := s.database.QueryContext(ctx,
			`SELECT beneficiary_role, SUM(amount) FROM commissions WHERE order_id = ? GROUP BY beneficiary_role`, id)
		if err != nil {
			return nil, err
		}
		for feeRows.Next() {
			var role string
			var amount float64
			if err := feeRows.Scan(&role, &amount); err != nil {
				feeRows.Close()
				return nil, err
			}
			if _, ok := fees[role]; ok {
				fees[role] = amount
			}
		}
		feeRows.Close()
		isOverdue := false
		if dueDate.Valid && dueDate.String != "" && dueDate.String < jakarta {
			isOverdue = true
		}
		orders = append(orders, map[string]any{
			"order_id":            id,
			"order_no":            orderNo,
			"order_date":          orderDate,
			"customer":            customer,
			"payment_method":      pmName.String,
			"payment_method_type": pmType.String,
			"grand_total":         grandTotal,
			"dp_paid":             verifiedDp,
			"dp_submitted":        submittedDp,
			"verification_status": pendingStatus,
			"has_pending_proof":   hasPendingProof,
			"total_paid":          paidAmount,
			"remaining":           remaining,
			"payment_status":      paymentStatus,
			"due_date":            dueDateString(dueDate),
			"is_overdue":          isOverdue,
			"refund":              pendingRefund,
			"additional_payment":  pendingAdditional,
			"fees":                fees,
		})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return map[string]any{
		"orders": orders,
		"meta": map[string]any{
			"current_page": page, "last_page": lastPage, "total": count, "per_page": perPage,
			"summary": map[string]any{"count": count, "outstanding": outstanding},
		},
	}, nil
}

// pendingProofFor ports PaymentSummaryService pending-verification triage: the pending manual-transfer
// verification on the order's CURRENT manual transaction (latest by id) or a pending COD proof.
func (s *Service) pendingProofFor(ctx context.Context, orderID int64) (float64, any, bool, error) {
	var submittedDp float64
	pendingStatus := any(nil)
	hasPendingProof := false
	var proofPath sql.NullString
	var amount float64
	err := s.database.QueryRowContext(ctx,
		`SELECT btv.proof_image_path, pt.amount
		 FROM bank_transfer_verifications btv
		 JOIN payment_transactions pt ON pt.id = btv.payment_transaction_id
		 JOIN payment_methods pm ON pm.id = pt.payment_method_id
		 WHERE pt.order_id = ? AND btv.status = 'pending' AND pm.type = 'manual'
		 ORDER BY pt.id DESC, btv.id DESC LIMIT 1`, orderID).Scan(&proofPath, &amount)
	switch {
	case err == nil:
		pendingStatus = "pending"
		hasPendingProof = proofPath.Valid && proofPath.String != ""
		submittedDp = amount
	case errors.Is(err, sql.ErrNoRows):
		// fall through to the COD check
	default:
		return 0, nil, false, err
	}
	if pendingStatus == nil {
		var pendingCod bool
		if err := s.database.QueryRowContext(ctx,
			`SELECT EXISTS(SELECT 1 FROM cod_payment_proofs cp
			  JOIN payment_transactions pt ON pt.id = cp.payment_transaction_id
			  JOIN payment_methods pm ON pm.id = pt.payment_method_id
			  WHERE pt.order_id = ? AND cp.status = 'pending' AND pm.type = 'cod')`, orderID).Scan(&pendingCod); err != nil {
			return 0, nil, false, err
		}
		if pendingCod {
			pendingStatus = "pending"
			hasPendingProof = true
		}
	}
	return submittedDp, pendingStatus, hasPendingProof, nil
}

func dueDateString(v sql.NullString) any {
	if v.Valid && v.String != "" {
		return v.String
	}
	return nil
}

// jakartaToday renders the business date in Asia/Jakarta (Laravel Carbon::now('Asia/Jakarta')->toDateString()).
func jakartaToday() string {
	loc := time.FixedZone("WIB", 7*3600)
	return time.Now().In(loc).Format("2006-01-02")
}

func strconvAtoi(v string, def int) (int, bool) {
	n, err := strconv.Atoi(v)
	if err != nil || v == "" {
		return def, false
	}
	return n, true
}

var crmBuckets = []string{"0", "N", "1", "2", "3", "4", "5", "6", "7"}

var crmBucketLabels = map[string]string{
	"0": "0%", "1": "BS", "2": "MS", "3": "IP", "4": "IH",
	"5": "MK", "6": "MKK", "7": "Deal", "N": "Nolak",
}

// CrmVisits ports CrmVisitReportService::funnel: per-owner customer/visit funnel at each customer's
// LATEST visit status (SimpleV2 semantics), plus totals and a per-bucket breakdown.
func (s *Service) CrmVisits(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	custScope, custArgs := []string{"c.archived_at IS NULL"}, []any{}
	switch actor.Role {
	case "sales", "sales-kurir-sub":
		custScope = append(custScope, "c.owner_user_id = ?")
		custArgs = append(custArgs, actor.ID)
	case "korsal":
		if actor.AgentID == nil {
			return nil, ErrForbidden
		}
		custScope = append(custScope, "(c.owner_user_id = ? OR owners.korsal_id = ?)", "c.agent_id = ?")
		custArgs = append(custArgs, actor.ID, actor.ID, *actor.AgentID)
	case "agen", "admin":
		if actor.AgentID == nil {
			return nil, ErrForbidden
		}
		custScope = append(custScope, "c.agent_id = ?")
		custArgs = append(custArgs, *actor.AgentID)
	case "super_admin":
		if id := q.Get("agent_id"); id != "" {
			custScope = append(custScope, "c.agent_id = ?")
			custArgs = append(custArgs, id)
		}
	default:
		return nil, ErrForbidden
	}
	rows, err := s.database.QueryContext(ctx,
		`SELECT c.id, c.owner_user_id, COALESCE(owners.name, '')
		 FROM crm_customers c
		 JOIN users owners ON owners.id = c.owner_user_id
		 WHERE `+joinWhere(custScope), custArgs...)
	if err != nil {
		return nil, err
	}
	type crmCustomer struct {
		id        int64
		ownerID   int64
		ownerName string
	}
	customers := []crmCustomer{}
	for rows.Next() {
		var c crmCustomer
		if err := rows.Scan(&c.id, &c.ownerID, &c.ownerName); err != nil {
			rows.Close()
			return nil, err
		}
		customers = append(customers, c)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	latest := map[int64]string{}
	visitsCount := map[int64]int{}
	planned := map[int64]bool{}
	if len(customers) > 0 {
		ids := make([]string, 0, len(customers))
		idArgs := []any{}
		for _, c := range customers {
			ids = append(ids, "?")
			idArgs = append(idArgs, c.id)
		}
		vq := `SELECT v.crm_customer_id, v.status, v.plan_next_date FROM crm_visits v
			 WHERE v.crm_customer_id IN (` + strings.Join(ids, ",") + ")"
		vargs := append([]any{}, idArgs...)
		if v := q.Get("from"); v != "" {
			vq += " AND DATE(v.visit_date) >= ?"
			vargs = append(vargs, v)
		}
		if v := q.Get("to"); v != "" {
			vq += " AND DATE(v.visit_date) <= ?"
			vargs = append(vargs, v)
		}
		vq += " ORDER BY v.visit_date ASC, v.id ASC"
		vrows, err := s.database.QueryContext(ctx, vq, vargs...)
		if err != nil {
			return nil, err
		}
		defer vrows.Close()
		today := jakartaToday()
		for vrows.Next() {
			var cid int64
			var status string
			var planNext sql.NullString
			if err := vrows.Scan(&cid, &status, &planNext); err != nil {
				return nil, err
			}
			latest[cid] = status // ascending order: last write wins = newest visit
			visitsCount[cid]++
			if planNext.Valid && planNext.String != "" && planNext.String >= today {
				planned[cid] = true
			}
		}
		if err := vrows.Err(); err != nil {
			return nil, err
		}
	}

	type ownerRow struct {
		OwnerID   int64          `json:"owner_id"`
		OwnerName string         `json:"owner_name"`
		Customers int            `json:"customers"`
		Visits    int            `json:"visits"`
		RK        int            `json:"rk"`
		Buckets   map[string]int `json:"buckets"`
	}
	perOwner := map[int64]*ownerRow{}
	bucketTotals := map[string]int{}
	for _, b := range crmBuckets {
		bucketTotals[b] = 0
	}
	var visitTotal int
	for _, c := range customers {
		bucket, ok := latest[c.id]
		if !ok {
			bucket = "0"
		}
		if _, known := bucketTotals[bucket]; !known {
			bucket = "0"
		}
		bucketTotals[bucket]++
		o, ok := perOwner[c.ownerID]
		if !ok {
			o = &ownerRow{OwnerID: c.ownerID, OwnerName: c.ownerName, Buckets: map[string]int{}}
			for _, b := range crmBuckets {
				o.Buckets[b] = 0
			}
			perOwner[c.ownerID] = o
		}
		o.Customers++
		o.Buckets[bucket]++
		n := visitsCount[c.id]
		o.Visits += n
		visitTotal += n
		if planned[c.id] {
			o.RK++
		}
	}
	ownerRows := make([]*ownerRow, 0, len(perOwner))
	for _, o := range perOwner {
		ownerRows = append(ownerRows, o)
	}
	// sortByDesc('customers')
	for i := 0; i < len(ownerRows); i++ {
		for j := i + 1; j < len(ownerRows); j++ {
			if ownerRows[j].Customers > ownerRows[i].Customers {
				ownerRows[i], ownerRows[j] = ownerRows[j], ownerRows[i]
			}
		}
	}
	conversion := 0.0
	if len(customers) > 0 {
		conversion = float64(int(float64(bucketTotals["7"])/float64(len(customers))*1000+0.5)) / 10
	}
	return map[string]any{
		"bucket_labels": crmBucketLabels,
		"totals": map[string]any{
			"customers":          len(customers),
			"visits":             visitTotal,
			"rk":                 len(planned),
			"buckets":            bucketTotals,
			"conversion_percent": conversion,
		},
		"rows": ownerRows,
	}, nil
}

const networkSellerRoles = "'agen','korsal','sales','sales-kurir-sub','sales-kurir'"

// NetworkTransactions ports NetworkTransactionReportService::report: ORDER-grained network totals with
// TANPA SALES/KORSAL groups kept visible, grouped over distinct order ids (Owner 2026-10-29 §7).
func (s *Service) NetworkTransactions(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	where := []string{"1=1"}
	args := []any{}
	switch actor.Role {
	case "sales", "sales-kurir-sub":
		where = append(where, "CASE WHEN buyer_roles.slug IN ("+networkSellerRoles+") THEN buyers.id ELSE o.sales_id END = ?")
		args = append(args, actor.ID)
	case "korsal":
		if actor.AgentID == nil {
			return nil, ErrForbidden
		}
		where = append(where, "o.agent_id = ?", "CASE WHEN buyer_roles.slug = 'korsal' THEN buyers.id ELSE o.korsal_id END = ?")
		args = append(args, *actor.AgentID, actor.ID)
	case "agen", "admin":
		if actor.AgentID == nil {
			return nil, ErrForbidden
		}
		where = append(where, "o.agent_id = ?")
		args = append(args, *actor.AgentID)
	case "super_admin":
		if id := q.Get("agent_id"); id != "" {
			where = append(where, "o.agent_id = ?")
			args = append(args, id)
		}
	default:
		return nil, ErrForbidden
	}
	if v := q.Get("from"); v != "" {
		where = append(where, "DATE(o.created_at) >= ?")
		args = append(args, v)
	}
	if v := q.Get("to"); v != "" {
		where = append(where, "DATE(o.created_at) <= ?")
		args = append(args, v)
	}
	if v := q.Get("status"); v != "" {
		where = append(where, "o.status = ?")
		args = append(args, v)
	}
	filter := joinWhere(where)
	base := `SELECT o.id, o.status, o.total_amount, o.paid_amount,
			CASE WHEN buyer_roles.slug IN (` + networkSellerRoles + `) THEN buyers.id ELSE o.sales_id END AS sales_attr,
			CASE WHEN buyer_roles.slug = 'korsal' THEN buyers.id ELSE o.korsal_id END AS korsal_attr,
			` + collectibleSQL("o") + ` AS collectible
		 FROM orders o
		 LEFT JOIN users buyers ON buyers.id = o.konsumen_id
		 LEFT JOIN roles buyer_roles ON buyer_roles.id = buyers.role_id
		 WHERE ` + filter

	type netOrder struct {
		id                       int64
		status                   string
		total, paid, collectible float64
		salesAttr, korsalAttr    sql.NullInt64
	}
	rows, err := s.database.QueryContext(ctx, base, args...)
	if err != nil {
		return nil, err
	}
	orders := []netOrder{}
	for rows.Next() {
		var o netOrder
		if err := rows.Scan(&o.id, &o.status, &o.total, &o.paid, &o.salesAttr, &o.korsalAttr, &o.collectible); err != nil {
			rows.Close()
			return nil, err
		}
		orders = append(orders, o)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if len(orders) > 20000 {
		return nil, httpxValidation("Periode terlalu luas untuk agregasi jaringan; persempit filter tanggal.")
	}

	var totalAmount, paidAmount, outstanding float64
	statusOrder := []string{}
	statusCount := map[string]int{}
	statusAmount := map[string]float64{}
	groupKey := func(v sql.NullInt64) string {
		if v.Valid {
			return "i" + strconvItoa(v.Int64)
		}
		return "none"
	}
	bySales := map[string]*group{}
	byKorsal := map[string]*group{}
	for _, o := range orders {
		totalAmount += o.total
		paidAmount += o.paid
		outstanding += o.collectible
		statusCount[o.status]++
		statusAmount[o.status] += o.total
		if statusCount[o.status] == 1 {
			statusOrder = append(statusOrder, o.status)
		}
		gk := groupKey(o.salesAttr)
		g, ok := bySales[gk]
		if !ok {
			var idCopy *int64
			if o.salesAttr.Valid {
				v := o.salesAttr.Int64
				idCopy = &v
			}
			g = &group{id: idCopy}
			bySales[gk] = g
		}
		g.orderCount++
		g.total += o.total
		g.paid += o.paid
		g.outstanding += o.collectible
		kk := groupKey(o.korsalAttr)
		kg, ok := byKorsal[kk]
		if !ok {
			var idCopy *int64
			if o.korsalAttr.Valid {
				v := o.korsalAttr.Int64
				idCopy = &v
			}
			kg = &group{id: idCopy}
			byKorsal[kk] = kg
		}
		kg.orderCount++
		kg.total += o.total
		kg.paid += o.paid
		kg.outstanding += o.collectible
	}
	byStatus := make([]map[string]any, 0, len(statusOrder))
	for _, st := range statusOrder {
		byStatus = append(byStatus, map[string]any{
			"status": st, "order_count": statusCount[st], "total_amount": round2(statusAmount[st]),
		})
	}

	seeSales := actor.Role == "super_admin" || actor.Role == "agen" || actor.Role == "admin" || actor.Role == "korsal"
	seeKorsal := actor.Role == "super_admin" || actor.Role == "agen" || actor.Role == "admin"
	nameOf := func(ids map[int64]string, id *int64, fallback string) string {
		if id == nil {
			return fallback
		}
		if n, ok := ids[*id]; ok && n != "" {
			return n
		}
		return "#" + strconvItoa(*id)
	}
	salesNames, _ := s.userNames(ctx)
	korsalNames := salesNames

	var bySalesOut, byKorsalOut any
	if seeSales {
		bySalesOut = sortedGroups(bySales, func(g *group) map[string]any {
			return map[string]any{
				"id": g.id, "order_count": g.orderCount, "total_amount": round2(g.total),
				"paid_amount": round2(g.paid), "outstanding_amount": round2(g.outstanding),
				"name": nameOf(salesNames, g.id, "TANPA SALES (langsung/agen)"),
			}
		})
	}
	if seeKorsal {
		byKorsalOut = sortedGroups(byKorsal, func(g *group) map[string]any {
			return map[string]any{
				"id": g.id, "order_count": g.orderCount, "total_amount": round2(g.total),
				"paid_amount": round2(g.paid), "outstanding_amount": round2(g.outstanding),
				"name": nameOf(korsalNames, g.id, "TANPA KORSAL"),
			}
		})
	}
	scope := "jaringan sales sendiri"
	switch actor.Role {
	case "super_admin":
		scope = "semua cabang"
	case "agen", "admin":
		scope = "cabang sendiri"
	case "korsal":
		scope = "jaringan korsal sendiri"
	}
	return map[string]any{
		"scope": scope,
		"totals": map[string]any{
			"order_count":        len(orders),
			"total_amount":       round2(totalAmount),
			"paid_amount":        round2(paidAmount),
			"outstanding_amount": round2(outstanding),
		},
		"by_status": byStatus,
		"by_korsal": byKorsalOut,
		"by_sales":  bySalesOut,
	}, nil
}

// userNames loads users.name keyed by id (used for network breakdown name resolution).
func (s *Service) userNames(ctx context.Context) (map[int64]string, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT id, name FROM users`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[int64]string{}
	for rows.Next() {
		var id int64
		var name string
		if err := rows.Scan(&id, &name); err != nil {
			return nil, err
		}
		out[id] = name
	}
	return out, rows.Err()
}

type group struct {
	id                       *int64
	orderCount               int
	total, paid, outstanding float64
}

func strconvItoa(v int64) string { return strconv.FormatInt(v, 10) }

// sortedGroups renders the network breakdown groups sorted by order_count desc (Laravel sortByDesc).
func sortedGroups(byKey map[string]*group, render func(*group) map[string]any) []map[string]any {
	items := make([]*group, 0, len(byKey))
	for _, g := range byKey {
		items = append(items, g)
	}
	for i := 0; i < len(items); i++ {
		for j := i + 1; j < len(items); j++ {
			if items[j].orderCount > items[i].orderCount {
				items[i], items[j] = items[j], items[i]
			}
		}
	}
	out := make([]map[string]any, 0, len(items))
	for _, g := range items {
		out = append(out, render(g))
	}
	return out
}

// httpxValidation builds a 422 with a literal message (NetworkTransactionReportService cap).
func httpxValidation(message string) error {
	return &validationError{message: message}
}

type validationError struct{ message string }

func (e *validationError) Error() string { return e.message }

// NetworkSummary ports ReportService::networkSummary: headcounts, per-agen rollup, product breakdown,
// payment-method split, paid vs outstanding money, refund/additional-payment totals.
func (s *Service) NetworkSummary(ctx context.Context, actor Actor, q url.Values) (map[string]any, error) {
	scope, sargs, err := s.networkOrdersScope(actor, q)
	if err != nil {
		return nil, err
	}
	ordersFilter := joinWhere(scope)
	ordersArgs := sargs

	var orderCount int
	var totalAmount, paidAmount, outstanding float64
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*), COALESCE(SUM(orders.total_amount), 0), COALESCE(SUM(orders.paid_amount), 0),
				COALESCE(SUM(`+collectibleSQL("orders")+`), 0)
		 FROM orders WHERE `+ordersFilter, ordersArgs...).Scan(&orderCount, &totalAmount, &paidAmount, &outstanding); err != nil {
		return nil, err
	}

	idsScope, idsArgs, _ := s.networkOrdersScope(actor, q)
	idsFilter := joinWhere(idsScope)
	var itemCount int
	var refundTotal, returnRefundTotal, additionalTotal float64
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(i.fulfilled_quantity), 0) FROM order_items i
		 WHERE i.order_id IN (SELECT orders.id FROM orders WHERE `+idsFilter+`)`, idsArgs...).Scan(&itemCount); err != nil {
		return nil, err
	}
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(a.refund_amount), 0) FROM order_item_adjustments a
		 JOIN order_items i ON i.id = a.order_item_id
		 WHERE i.order_id IN (SELECT orders.id FROM orders WHERE `+idsFilter+`)`, idsArgs...).Scan(&refundTotal); err != nil {
		return nil, err
	}
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(ri.refund_amount), 0) FROM return_items ri
		 WHERE ri.order_item_id IN (SELECT i.id FROM order_items i WHERE i.order_id IN (SELECT orders.id FROM orders WHERE `+idsFilter+`))`, idsArgs...).Scan(&returnRefundTotal); err != nil {
		return nil, err
	}
	if err := s.database.QueryRowContext(ctx,
		`SELECT COALESCE(SUM(ap.amount), 0) FROM order_additional_payments ap
		 WHERE ap.order_id IN (SELECT orders.id FROM orders WHERE `+idsFilter+`)`, idsArgs...).Scan(&additionalTotal); err != nil {
		return nil, err
	}

	scopeAgentID := ""
	if actor.Role == "super_admin" {
		scopeAgentID = q.Get("agent_id")
	} else if actor.AgentID != nil {
		scopeAgentID = strconvItoa(*actor.AgentID)
	}
	sellerSlugs := sellerSlugsSQL
	countRole := func(roleList string) int {
		q2 := `SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
			   WHERE r.slug IN (` + roleList + `) AND u.deleted_at IS NULL`
		args2 := []any{}
		if scopeAgentID != "" {
			q2 += " AND u.agent_id = ?"
			args2 = append(args2, scopeAgentID)
		}
		var n int
		_ = s.database.QueryRowContext(ctx, q2, args2...).Scan(&n)
		return n
	}
	var salesWithSales int
	_ = s.database.QueryRowContext(ctx,
		`SELECT COUNT(DISTINCT orders.sales_id) FROM orders
		 WHERE orders.sales_id IS NOT NULL AND `+idsFilter, idsArgs...).Scan(&salesWithSales)

	agentWhere := "r.slug = 'agen' AND users.deleted_at IS NULL"
	agentArgs := []any{}
	if scopeAgentID != "" {
		agentWhere += " AND users.id = ?"
		agentArgs = append(agentArgs, scopeAgentID)
	}
	arows, err := s.database.QueryContext(ctx,
		`SELECT users.id, users.name FROM users JOIN roles r ON r.id = users.role_id WHERE `+agentWhere, agentArgs...)
	if err != nil {
		return nil, err
	}
	agentIDs := []int64{}
	agentNames := map[int64]string{}
	for arows.Next() {
		var id int64
		var name string
		if err := arows.Scan(&id, &name); err != nil {
			arows.Close()
			return nil, err
		}
		agentIDs = append(agentIDs, id)
		agentNames[id] = name
	}
	arows.Close()
	if err := arows.Err(); err != nil {
		return nil, err
	}

	countBy := func(roleList string) map[int64]int {
		out := map[int64]int{}
		rows, err := s.database.QueryContext(ctx,
			`SELECT u.agent_id, COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
			 WHERE r.slug IN (`+roleList+") AND u.agent_id IN ("+idPlaceholders(len(agentIDs))+") GROUP BY u.agent_id",
			int64Args(agentIDs)...)
		if err != nil {
			return out
		}
		defer rows.Close()
		for rows.Next() {
			var aid sql.NullInt64
			var n int
			if rows.Scan(&aid, &n) == nil && aid.Valid {
				out[aid.Int64] = n
			}
		}
		return out
	}
	korsalCounts := countBy("'korsal'")
	salesCounts := countBy(sellerSlugs)
	stockByAgent := s.stockQuantityByAgent(ctx, agentIDs)
	feeByAgent := map[int64]float64{}
	frows, err := s.database.QueryContext(ctx,
		`SELECT beneficiaries.agent_id, SUM(commissions.amount) FROM commissions
		 JOIN users beneficiaries ON beneficiaries.id = commissions.beneficiary_user_id
		 WHERE beneficiaries.agent_id IN (`+idPlaceholders(len(agentIDs))+") GROUP BY beneficiaries.agent_id",
		int64Args(agentIDs)...)
	if err == nil {
		for frows.Next() {
			var aid sql.NullInt64
			var f float64
			if frows.Scan(&aid, &f) == nil && aid.Valid {
				feeByAgent[aid.Int64] = round2(f)
			}
		}
		frows.Close()
	}

	type agenRow struct {
		m map[string]any
	}
	perAgenOrder := map[int64]*agenRow{}
	perAgenSeq := []*agenRow{}
	var perAgenSeen int
	_ = perAgenSeen
	parows, err := s.database.QueryContext(ctx,
		`SELECT orders.agent_id, COUNT(*), COUNT(DISTINCT orders.sales_id),
				SUM(CASE WHEN orders.sales_id IS NOT NULL THEN 1 ELSE 0 END),
				SUM(CASE WHEN orders.korsal_id IS NOT NULL THEN 1 ELSE 0 END),
				COALESCE(SUM(orders.total_amount), 0),
				COALESCE(SUM(CASE WHEN orders.payment_status = 'paid' THEN orders.total_amount ELSE 0 END), 0),
				COALESCE(SUM(`+collectibleSQL("orders")+`), 0)
		 FROM orders WHERE `+ordersFilter+` GROUP BY orders.agent_id`, ordersArgs...)
	if err != nil {
		return nil, err
	}
	defer parows.Close()
	for parows.Next() {
		var aid int64
		var oc, asc, stc, ktc int
		var ta, pa, oa float64
		if err := parows.Scan(&aid, &oc, &asc, &stc, &ktc, &ta, &pa, &oa); err != nil {
			return nil, err
		}
		m := map[string]any{
			"agent_id": aid, "agent_name": agentNames[aid],
			"korsal_count": korsalCounts[aid], "sales_count": salesCounts[aid],
			"active_sales_count": asc, "sales_transaction_count": stc, "korsal_transaction_count": ktc,
			"order_count": oc, "total_amount": round2(ta), "paid_amount": round2(pa),
			"outstanding_amount": round2(oa), "stock_quantity": stockByAgent[aid], "total_fee": feeByAgent[aid],
		}
		r := &agenRow{m: m}
		perAgenOrder[aid] = r
		perAgenSeq = append(perAgenSeq, r)
	}
	if err := parows.Err(); err != nil {
		return nil, err
	}
	perAgen := make([]map[string]any, 0, len(agentIDs))
	for _, aid := range agentIDs {
		if r, ok := perAgenOrder[aid]; ok {
			perAgen = append(perAgen, r.m)
			continue
		}
		perAgen = append(perAgen, map[string]any{
			"agent_id": aid, "agent_name": agentNames[aid],
			"korsal_count": korsalCounts[aid], "sales_count": salesCounts[aid],
			"active_sales_count": 0, "sales_transaction_count": 0, "korsal_transaction_count": 0,
			"order_count": 0, "total_amount": 0.0, "paid_amount": 0.0, "outstanding_amount": 0.0,
			"stock_quantity": stockByAgent[aid], "total_fee": feeByAgent[aid],
		})
	}

	products := []map[string]any{}
	if orderCount > 0 {
		rows, err := s.database.QueryContext(ctx,
			`SELECT i.product_name_snapshot, COALESCE(i.sku_snapshot, ''), SUM(i.fulfilled_quantity),
					SUM(i.subtotal_snapshot), COUNT(DISTINCT i.order_id)
			 FROM order_items i
			 WHERE i.order_id IN (SELECT orders.id FROM orders WHERE `+idsFilter+`)
			 GROUP BY i.product_name_snapshot, i.sku_snapshot ORDER BY SUM(i.fulfilled_quantity) DESC LIMIT 50`, idsArgs...)
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var name, sku string
			var qty any
			var amtStr any
			var oc int
			if err := rows.Scan(&name, &sku, &qty, &amtStr, &oc); err != nil {
				rows.Close()
				return nil, err
			}
			products = append(products, map[string]any{
				"product_name_snapshot": name, "sku_snapshot": sku,
				"quantity": wire(qty), "total_amount": wire(amtStr), "order_count": oc,
			})
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			return nil, err
		}
	}

	paymentMethods := []map[string]any{}
	rows, err := s.database.QueryContext(ctx,
		`SELECT pm.code, pm.name, pm.type, COUNT(*), COALESCE(SUM(orders.total_amount), 0)
		 FROM orders LEFT JOIN payment_methods pm ON pm.id = orders.payment_method_id
		 WHERE `+ordersFilter+` GROUP BY pm.code, pm.name, pm.type`, ordersArgs...)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var code, name, typ sql.NullString
		var oc int
		var totalAmountStr sql.NullString
		if err := rows.Scan(&code, &name, &typ, &oc, &totalAmountStr); err != nil {
			rows.Close()
			return nil, err
		}
		paymentMethods = append(paymentMethods, map[string]any{
			"method_code": code.String, "method_name": name.String, "method_type": typ.String,
			"order_count": oc, "total_amount": nullString(totalAmountStr),
		})
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	paymentStatusRows := []map[string]any{}
	rows, err = s.database.QueryContext(ctx,
		`SELECT orders.payment_status, COUNT(*), COALESCE(SUM(orders.total_amount), 0)
		 FROM orders WHERE `+ordersFilter+` GROUP BY orders.payment_status`, ordersArgs...)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var ps string
		var oc int
		var amtStr sql.NullString
		if err := rows.Scan(&ps, &oc, &amtStr); err != nil {
			rows.Close()
			return nil, err
		}
		paymentStatusRows = append(paymentStatusRows, map[string]any{
			"payment_status": ps, "order_count": oc, "total_amount": nullString(amtStr),
		})
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	return map[string]any{
		"korsal_count":           countRole("'korsal'"),
		"sales_count":            countRole(sellerSlugs),
		"courier_count":          countRole("'kurir'"),
		"sales_with_sales_count": salesWithSales,
		"totals": map[string]any{
			"order_count": orderCount, "item_count": itemCount,
			"total_amount": round2(totalAmount), "paid_amount": round2(paidAmount),
			"outstanding_amount": round2(outstanding), "refund_total": round2(refundTotal),
			"return_refund_total": round2(returnRefundTotal), "additional_payment_total": round2(additionalTotal),
		},
		"per_agen":        perAgen,
		"products":        products,
		"payment_methods": paymentMethods,
		"payment_status":  paymentStatusRows,
	}, nil
}

// networkOrdersScope is scopedOrders(): role-confined orders with the qualified report filters.
func (s *Service) networkOrdersScope(actor Actor, q url.Values) ([]string, []any, error) {
	where := []string{}
	args := []any{}
	if actor.Role == "super_admin" {
		if id := q.Get("agent_id"); id != "" {
			where = append(where, "orders.agent_id = ?")
			args = append(args, id)
		}
	} else {
		if actor.AgentID == nil {
			where = append(where, "1=0")
		} else {
			where = append(where, "orders.agent_id = ?")
			args = append(args, *actor.AgentID)
		}
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
	if len(where) == 0 {
		where = append(where, "1=1")
	}
	return where, args, nil
}

func idPlaceholders(n int) string {
	if n == 0 {
		return "NULL"
	}
	return strings.TrimRight(strings.Repeat("?,", n), ",")
}

func int64Args(ids []int64) []any {
	out := make([]any, 0, len(ids))
	for _, id := range ids {
		out = append(out, id)
	}
	return out
}

// stockQuantityByAgent ports SellableStockService::projection aggregated to SUM(quantity) per agent:
// warehouse rows use transit (+ factory_plan when enabled), legacy rows use quantity_on_hand, minus
// reserved, floored at 0.
func (s *Service) stockQuantityByAgent(ctx context.Context, agentIDs []int64) map[int64]int {
	out := map[int64]int{}
	if len(agentIDs) == 0 {
		return out
	}
	in := idPlaceholders(len(agentIDs))
	args := int64Args(agentIDs)
	// product targets
	q := `SELECT t.agent_id, SUM(GREATEST(0, (CASE WHEN w.transit_qty IS NOT NULL
			THEN COALESCE(w.transit_qty, 0) + CASE WHEN COALESCE(ws.factory_plan_enabled, 0) = 1 THEN COALESCE(w.plan_qty, 0) ELSE 0 END
			ELSE COALESCE(l.quantity_on_hand, 0) END) - COALESCE(l.quantity_reserved, 0)))
		 FROM (SELECT agent_id, product_id AS target_id FROM product_stocks WHERE agent_id IN (` + in + `)
			   UNION
			   SELECT agent_id, product_id FROM warehouse_stocks WHERE product_id IS NOT NULL AND agent_id IN (` + in + `)) t
		 JOIN products p ON p.id = t.target_id AND p.deleted_at IS NULL
		 LEFT JOIN (SELECT agent_id, product_id AS target_id,
				SUM(CASE WHEN stock_type = 'transit' THEN quantity ELSE 0 END) AS transit_qty,
				SUM(CASE WHEN stock_type = 'factory_plan' THEN quantity ELSE 0 END) AS plan_qty
			FROM warehouse_stocks WHERE product_id IS NOT NULL GROUP BY agent_id, product_id) w
			ON w.agent_id = t.agent_id AND w.target_id = t.target_id
		 LEFT JOIN product_stocks l ON l.agent_id = t.agent_id AND l.product_id = t.target_id
		 LEFT JOIN warehouse_settings ws ON ws.agent_id = t.agent_id
		 GROUP BY t.agent_id`
	s.aggregateStock(ctx, q, append(append([]any{}, args...), args...), out)
	// variation targets
	qv := `SELECT t.agent_id, SUM(GREATEST(0, (CASE WHEN w.transit_qty IS NOT NULL
			THEN COALESCE(w.transit_qty, 0) + CASE WHEN COALESCE(ws.factory_plan_enabled, 0) = 1 THEN COALESCE(w.plan_qty, 0) ELSE 0 END
			ELSE COALESCE(l.quantity_on_hand, 0) END) - COALESCE(l.quantity_reserved, 0)))
		 FROM (SELECT agent_id, product_variation_id AS target_id FROM product_variation_stocks WHERE agent_id IN (` + in + `)
			   UNION
			   SELECT agent_id, product_variation_id FROM warehouse_stocks WHERE product_variation_id IS NOT NULL AND agent_id IN (` + in + `)) t
		 LEFT JOIN (SELECT agent_id, product_variation_id AS target_id,
				SUM(CASE WHEN stock_type = 'transit' THEN quantity ELSE 0 END) AS transit_qty,
				SUM(CASE WHEN stock_type = 'factory_plan' THEN quantity ELSE 0 END) AS plan_qty
			FROM warehouse_stocks WHERE product_variation_id IS NOT NULL GROUP BY agent_id, product_variation_id) w
			ON w.agent_id = t.agent_id AND w.target_id = t.target_id
		 LEFT JOIN product_variation_stocks l ON l.agent_id = t.agent_id AND l.product_variation_id = t.target_id
		 LEFT JOIN warehouse_settings ws ON ws.agent_id = t.agent_id
		 GROUP BY t.agent_id`
	s.aggregateStock(ctx, qv, append(append([]any{}, args...), args...), out)
	return out
}

func (s *Service) aggregateStock(ctx context.Context, query string, args []any, out map[int64]int) {
	rows, err := s.database.QueryContext(ctx, query, args...)
	if err != nil {
		return
	}
	defer rows.Close()
	for rows.Next() {
		var aid sql.NullInt64
		var total int
		if rows.Scan(&aid, &total) == nil && aid.Valid {
			out[aid.Int64] += total
		}
	}
}

// wire converts a raw driver value to its JSON value (nil-safe, bytes → string).
func wire(v any) any {
	switch t := v.(type) {
	case nil:
		return nil
	case []byte:
		return string(t)
	case time.Time:
		return t.UTC().Format("2006-01-02T15:04:05.000000Z")
	default:
		return v
	}
}
