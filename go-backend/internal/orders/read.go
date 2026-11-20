package orders

// Order read surface — index, show, regions.
//
// Ported from OrderController::{index,show,regions} + OrderPolicy::view + OrderFilterService +
// BelongsToAgentScope. The role scope is one nested group so no later filter can widen it; every query is
// derived from the SESSION, never from a client id (except super_admin's optional `agent_id`).
//
// Visibility (R-04 / §C): a kurir may not use the generic list/detail at all — they use the minimized courier
// surface. Every OTHER role sees a role-scoped slice; the RESOURCE still gates the financial fields per viewer.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"strings"
)

// RoleScopes is the caller's role + identity, used by the query builder and the policy.
type RoleScopes struct {
	Role    string
	UserID  int64
	AgentID *int64
}

// isRole reports whether the caller is one of the roles.
func (v RoleScopes) isRole(roles ...string) bool {
	for _, r := range roles {
		if v.Role == r {
			return true
		}
	}
	return false
}

// SeesFinancials mirrors OrderResource::$seesFinancials (exported for the handler).
func (v RoleScopes) SeesFinancials() bool {
	return v.isRole("super_admin", "agen", "admin", "keuangan", "konsumen", "sales", "sales-kurir-sub", "korsal")
}

// OrderFilter carries the list filters OrderFilterService applies.
type OrderFilter struct {
	Status        string
	PaymentStatus string
	Search        string
	ProvinceID    string
	RegencyID     string
	DistrictID    string
	VillageID     string
	DeliveryDate  string
	Paid          *bool // nil = unfiltered
	PerPage       int
	Page          int
}

// scopedWhere renders the role-scoped WHERE clause. Returns (fragment, args).
func scopedWhere(v RoleScopes) (string, []any) {
	switch v.Role {
	case "konsumen":
		return "o.konsumen_id = ?", []any{v.UserID}
	case "sales-kurir-sub", "sales":
		return `(o.sales_id = ? OR o.konsumen_id = ? OR EXISTS (
			SELECT 1 FROM users k WHERE k.id = o.konsumen_id AND k.sales_id = ? AND k.agent_id = ?))`,
			[]any{v.UserID, v.UserID, v.UserID, nullableInt(v.AgentID)}
	case "korsal":
		return `(o.korsal_id = ? OR o.sales_id = ? OR o.konsumen_id = ? OR EXISTS (
			SELECT 1 FROM users k WHERE k.id = o.konsumen_id AND k.korsal_id = ? AND k.agent_id = ?))`,
			[]any{v.UserID, v.UserID, v.UserID, v.UserID, nullableInt(v.AgentID)}
	case "gudang":
		// The warehouse work queue only: diproses AND no courier/self-delivery assigned anywhere.
		return `o.status = 'diproses' AND NOT EXISTS (
			SELECT 1 FROM shipments s WHERE s.order_id = o.id AND (s.courier_id IS NOT NULL OR s.self_delivered_by_user_id IS NOT NULL))`,
			nil
	case "super_admin":
		return "1=1", nil
	default:
		// agen/admin/keuangan/koordinator-kurir: BelongsToAgentScope.
		return "o.agent_id = ?", []any{nullableInt(v.AgentID)}
	}
}

// applyFilters appends the dispatch + status filters to the WHERE fragment.
func applyFilters(v RoleScopes, f OrderFilter) (string, []any) {
	where, args := scopedWhere(v)

	// Dispatch-equivalent region filters (canonical columns on orders).
	for _, pair := range []struct{ column, value string }{
		{"o.province_id", f.ProvinceID},
		{"o.regency_id", f.RegencyID},
		{"o.district_id", f.DistrictID},
		{"o.village_id", f.VillageID},
	} {
		if pair.value != "" {
			where += " AND " + pair.column + " = ?"
			args = append(args, pair.value)
		}
	}
	if f.DeliveryDate != "" {
		where += " AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.requested_delivery_date = ?)"
		args = append(args, f.DeliveryDate)
	}
	if f.Paid != nil {
		if *f.Paid {
			where += " AND o.payment_status = 'paid'"
		} else {
			where += " AND o.payment_status IN ('unpaid','pending_verification','partially_paid')"
		}
	}

	// Status filters. payment_status is only honored for the financial audience (the reference REMOVES the
	// `paid` param for operational roles rather than apply it).
	if f.Status != "" && validOrderStatus(f.Status) {
		where += " AND o.status = ?"
		args = append(args, f.Status)
	}
	if f.PaymentStatus != "" && v.SeesFinancials() && validSettlementStatus(f.PaymentStatus) {
		where += " AND o.payment_status = ?"
		args = append(args, f.PaymentStatus)
	}
	if f.Search != "" {
		term := "%" + escapeLike(f.Search) + "%"
		where += " AND (o.order_no LIKE ? OR o.recipient_name_snapshot LIKE ?)"
		args = append(args, term, term)
	}
	return where, args
}

func validOrderStatus(s string) bool {
	_, ok := orderTransitions[s]
	return ok
}

func validSettlementStatus(s string) bool {
	switch s {
	case "unpaid", "pending_verification", "partially_paid", "paid", "failed", "partially_refunded", "refunded":
		return true
	}
	return false
}

func escapeLike(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, "%", `\%`)
	s = strings.ReplaceAll(s, "_", `\_`)
	return s
}

// ListOrders returns the caller's scoped order ids, newest first, paginated.
func (s *Service) ListOrders(ctx context.Context, v RoleScopes, f OrderFilter) ([]int64, int, error) {
	where, args := applyFilters(v, f)
	if v.Role == "kurir" {
		return nil, 0, &Problem{Status: 403, Message: s.Messages("system.unauthorized_action")}
	}
	// super_admin may optionally scope to one branch.
	if v.Role == "super_admin" && f.ProvinceID == "" {
		// agent_id comes through the filter struct only via a dedicated field — handled in the handler.
	}

	var total int
	if err := s.Database.QueryRowContext(ctx, "SELECT COUNT(*) FROM orders o WHERE "+where, args...).
		Scan(&total); err != nil {
		return nil, 0, err
	}
	perPage := f.PerPage
	if perPage < 1 {
		perPage = 15
	}
	page := f.Page
	if page < 1 {
		page = 1
	}
	rows, err := s.Database.QueryContext(ctx, "SELECT o.id FROM orders o WHERE "+where+
		" ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?",
		append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, 0, err
	}
	defer rows.Close()
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, 0, err
		}
		ids = append(ids, id)
	}
	return ids, total, rows.Err()
}

// ListOrdersScopedToAgent is the super_admin `?agent_id=` variant.
func (s *Service) ListOrdersScopedToAgent(ctx context.Context, v RoleScopes, f OrderFilter, agentID int64) ([]int64, int, error) {
	where, args := applyFilters(v, f)
	where = "o.agent_id = ? AND " + where
	args = append([]any{agentID}, args...)
	var total int
	if err := s.Database.QueryRowContext(ctx, "SELECT COUNT(*) FROM orders o WHERE "+where, args...).
		Scan(&total); err != nil {
		return nil, 0, err
	}
	perPage := f.PerPage
	if perPage < 1 {
		perPage = 15
	}
	page := f.Page
	if page < 1 {
		page = 1
	}
	rows, err := s.Database.QueryContext(ctx, "SELECT o.id FROM orders o WHERE "+where+
		" ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?",
		append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, 0, err
	}
	defer rows.Close()
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, 0, err
		}
		ids = append(ids, id)
	}
	return ids, total, rows.Err()
}

// CanView is OrderPolicy::view.
func (s *Service) CanView(ctx context.Context, v RoleScopes, orderID int64) (bool, error) {
	if v.Role == "kurir" {
		return false, nil
	}
	var (
		konsumenID        sql.NullInt64
		agentID           int64
		korsalID, salesID sql.NullInt64
		status            string
	)
	err := s.Database.QueryRowContext(ctx, `SELECT konsumen_id, agent_id, korsal_id, sales_id, status
		FROM orders WHERE id = ?`, orderID).
		Scan(&konsumenID, &agentID, &korsalID, &salesID, &status)
	if errors.Is(err, sql.ErrNoRows) {
		return false, ErrOrderNotFound
	}
	if err != nil {
		return false, err
	}

	switch {
	case v.Role == "super_admin":
		return true, nil
	case konsumenID.Valid && konsumenID.Int64 == v.UserID:
		return true, nil
	case v.isRole("agen", "admin", "keuangan", "koordinator-kurir"):
		return v.AgentID != nil && *v.AgentID == agentID, nil
	case v.Role == "korsal":
		if (korsalID.Valid && korsalID.Int64 == v.UserID) || (salesID.Valid && salesID.Int64 == v.UserID) {
			return true, nil
		}
		// IMP-004 symmetric korsal leg: the konsumen's CURRENT korsal_id points at this user, same Agent.
		return konsumenID.Valid && s.konsumenCurrentlyInChain(ctx, konsumenID.Int64, "korsal_id", v.UserID, v.AgentID), nil
	case v.isRole("sales", "sales-kurir-sub"):
		if salesID.Valid && salesID.Int64 == v.UserID {
			return true, nil
		}
		return konsumenID.Valid && s.konsumenCurrentlyInChain(ctx, konsumenID.Int64, "sales_id", v.UserID, v.AgentID), nil
	case v.Role == "konsumen":
		return konsumenID.Valid && konsumenID.Int64 == v.UserID, nil
	case v.Role == "gudang":
		if (status != "diproses" && status != "dikirim") || v.AgentID == nil || *v.AgentID != agentID {
			return false, nil
		}
		return s.isInWarehouseScope(ctx, orderID), nil
	}
	return false, nil
}

// konsumenCurrentlyInChain checks the konsumen's CURRENT chain column (post-reassignment) with a same-Agent
// guard — the IMP-004 symmetric leg.
func (s *Service) konsumenCurrentlyInChain(ctx context.Context, konsumenID int64, column string, userID int64, actorAgent *int64) bool {
	if actorAgent == nil {
		return false
	}
	var (
		role       string
		chainValue sql.NullInt64
		agentID    sql.NullInt64
	)
	err := s.Database.QueryRowContext(ctx, `SELECT r.slug, u.`+column+`, u.agent_id
		FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, konsumenID).
		Scan(&role, &chainValue, &agentID)
	if err != nil {
		return false
	}
	return role == "konsumen" && chainValue.Valid && chainValue.Int64 == userID &&
		agentID.Valid && agentID.Int64 == *actorAgent
}

// isInWarehouseScope is Order::isInWarehouseScope.
func (s *Service) isInWarehouseScope(ctx context.Context, orderID int64) bool {
	var selfDelivery, courierAssigned bool
	err := s.Database.QueryRowContext(ctx, `SELECT
		EXISTS(SELECT 1 FROM shipments WHERE order_id = ? AND self_delivered_by_user_id IS NOT NULL),
		EXISTS(SELECT 1 FROM shipments WHERE order_id = ? AND courier_id IS NOT NULL)`, orderID, orderID).
		Scan(&selfDelivery, &courierAssigned)
	if err != nil {
		return false
	}
	if selfDelivery {
		return false
	}
	var status string
	if err := s.Database.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ?`, orderID).Scan(&status); err != nil {
		return false
	}
	if status == "diproses" && !courierAssigned {
		return true
	}
	if status == "diproses" || status == "dikirim" {
		var outstanding bool
		if err := s.Database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_requests
			WHERE order_id = ? AND status IN ('pending','partial'))`, orderID).Scan(&outstanding); err != nil {
			return false
		}
		return outstanding
	}
	return false
}

// RegionOptions is one derived region facet from the caller's own scoped orders.
type RegionOptions struct {
	Provinces []RegionOption
	Regencies []RegionOption
	Districts []RegionOption
	Villages  []RegionOption
}

// RegionOption is one {id, name} facet.
type RegionOption struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// OrderRegions is OrderController::regions — each level is derived only from the caller's scoped orders, and a
// level is empty until the parent is supplied.
func (s *Service) OrderRegions(ctx context.Context, v RoleScopes, provinceID, regencyID, districtID string) (*RegionOptions, error) {
	if v.Role == "kurir" {
		return nil, &Problem{Status: 403, Message: s.Messages("system.unauthorized_action")}
	}
	where, args := scopedWhere(v)
	if v.Role == "super_admin" {
		// no additional agent scope here (the handler adds it via the scoped-query path when provided)
	}

	level := func(idColumn, nameColumn string, parentColumn, parentValue string) ([]RegionOption, error) {
		clause := where
		a := append([]any{}, args...)
		if parentValue != "" {
			clause += " AND " + parentColumn + " = ?"
			a = append(a, parentValue)
		}
		rows, err := s.Database.QueryContext(ctx, `SELECT `+idColumn+` AS id, MAX(`+nameColumn+`) AS name
			FROM orders o WHERE `+clause+` AND `+idColumn+` IS NOT NULL
			GROUP BY `+idColumn+` ORDER BY name`, a...)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		var out []RegionOption
		for rows.Next() {
			var id, name sql.NullString
			if err := rows.Scan(&id, &name); err != nil {
				return nil, err
			}
			if !id.Valid || !name.Valid || name.String == "" {
				continue
			}
			out = append(out, RegionOption{ID: id.String, Name: name.String})
		}
		return out, rows.Err()
	}

	out := &RegionOptions{}
	var err error
	if out.Provinces, err = level("o.province_id", "o.province_snapshot", "", ""); err != nil {
		return nil, err
	}
	if provinceID != "" {
		if out.Regencies, err = level("o.regency_id", "o.regency_snapshot", "o.province_id", provinceID); err != nil {
			return nil, err
		}
	}
	if regencyID != "" {
		if out.Districts, err = level("o.district_id", "o.district_snapshot", "o.regency_id", regencyID); err != nil {
			return nil, err
		}
	}
	if districtID != "" {
		if out.Villages, err = level("o.village_id", "o.village_snapshot", "o.district_id", districtID); err != nil {
			return nil, err
		}
	}
	return out, nil
}

// LoadOrderForRead loads one order with the full actor + shipment context the show/index resource renders.
// `full` distinguishes the SHOW controller (loads paymentTransactions + returnRequests, so the
// payment_transaction and returns keys render) from the INDEX (does not load them, so those keys are absent).
func (s *Service) LoadOrderForRead(ctx context.Context, orderID int64, full bool) (*Order, error) {
	order, err := s.loadOrder(ctx, orderID, 0)
	if err != nil {
		return nil, err
	}
	order.LoadActors = true
	order.LoadItemShipment = true

	if !full {
		return order, nil
	}
	order.LoadPaymentMethod = true
	order.LoadPaymentTransactions = true
	order.LoadTransactionVerification = true
	order.LoadTransactionCodProof = true
	order.LoadReturns = true
	order.LoadDeliveryVerifications = true

	// The most recent payment transaction (by id), if any.
	var paymentID int64
	err = s.Database.QueryRowContext(ctx, `SELECT id FROM payment_transactions
		WHERE order_id = ? ORDER BY id DESC LIMIT 1`, orderID).Scan(&paymentID)
	if err == nil {
		var (
			amount    string
			reference sql.NullString
			p         PaymentTransaction
		)
		var rawPayload sql.NullString
		if serr := s.Database.QueryRowContext(ctx, `SELECT id, type, CAST(amount AS CHAR), status, gateway_reference, raw_payload
			FROM payment_transactions WHERE id = ?`, paymentID).
			Scan(&p.ID, &p.Type, &amount, &p.Status, &reference, &rawPayload); serr == nil {
			p.Amount = parseString(amount)
			p.GatewayReference = nullStringPtr(reference)
			if rawPayload.Valid && strings.TrimSpace(rawPayload.String) != "" {
				var decoded map[string]any
				if json.Unmarshal([]byte(rawPayload.String), &decoded) == nil {
					p.Instructions = decoded
				}
			}
			order.Payment = &p
		}
	} else if !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	return order, nil
}
