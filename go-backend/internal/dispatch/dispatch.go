// Package dispatch ports the Koordinator-Kurir workspace reads
// (DispatchController::index/couriers/regions/expeditions/refusals) plus the
// eligible-order scope shared by every surface (OrderFilterService::applyDispatchFilters).
package dispatch

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/url"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Actor is the calling user's dispatch-relevant projection.
type Actor struct {
	ID      int64
	Role    string
	AgentID int64
	IsSuper bool
}

// DomainError maps abort(...) / ApiException to an HTTP status + message.
type DomainError struct {
	Status  int
	Message string
}

func (e *DomainError) Error() string { return e.Message }

type Service struct{ database *db.DB }

func New(database *db.DB) *Service { return &Service{database: database} }

// resolveAgentID ports resolveSuperAdminAgentId: super_admin MUST pass an explicit ?agent_id=
// pointing at an existing agen (422 otherwise — never an accidental agent-0 query); ordinary
// staff always resolve to their own branch.
func resolveAgentID(ctx context.Context, d *db.DB, a Actor, q url.Values) (int64, error) {
	if !a.IsSuper {
		return a.AgentID, nil
	}
	id, err := strconv.ParseInt(q.Get("agent_id"), 10, 64)
	if err != nil || id <= 0 {
		return 0, &DomainError{Status: 422, Message: "agent_id wajib diisi untuk super_admin."}
	}
	var ok bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id
		WHERE u.id = ? AND r.slug = 'agen' AND u.deleted_at IS NULL)`, id).Scan(&ok); err != nil {
		return 0, err
	}
	if !ok {
		return 0, &DomainError{Status: 422, Message: "Agen tidak ditemukan."}
	}
	return id, nil
}

// dispatchEligibleOrders is eligibleOrderQuery + applyDispatchFilters: 'diproses'/'dikirim' orders of
// the branch, composable with the same delivery-date, paid and region filters as the Admin list.
func dispatchEligibleOrders(q url.Values, agentID int64) (string, []any) {
	where := []string{"o.status IN ('diproses','dikirim')", "o.agent_id = ?", "1=1"}
	args := []any{agentID}
	for _, col := range []string{"province_id", "regency_id", "district_id", "village_id"} {
		if v := q.Get(col); v != "" {
			where = append(where, "o."+col+" = ?")
			args = append(args, v)
		}
	}
	if v := q.Get("delivery_date"); v != "" {
		where = append(where, "EXISTS(SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND DATE(oi.requested_delivery_date) = ?)")
		args = append(args, v)
	}
	if v := q.Get("paid"); v != "" {
		if v == "paid" {
			where = append(where, "o.payment_status = 'paid'")
		} else {
			where = append(where, "o.payment_status IN ('unpaid','pending_verification','partially_paid')")
		}
	}
	return strings.Join(where, " AND "), args
}

// Couriers is DispatchController::couriers — the branch's active couriers the Koordinator may assign.
// for_returns narrows to a plain kurir (Owner decision 2026-11-08: pickups are assigned, never claimed).
func (s *Service) Couriers(ctx context.Context, a Actor, q url.Values) ([]map[string]any, error) {
	agentID, err := resolveAgentID(ctx, s.database, a, q)
	if err != nil {
		return nil, err
	}
	query := `SELECT c.id, c.name FROM couriers c
		WHERE c.agent_id = ? AND c.is_active = 1
		AND (c.user_id IS NULL OR EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
			WHERE u.id = c.user_id AND u.agent_id = ? AND u.status = 'active' AND u.deleted_at IS NULL
			AND r.slug IN ('kurir','koordinator-kurir')))`
	args := []any{agentID, agentID}
	if q.Get("for_returns") == "1" || q.Get("for_returns") == "true" {
		query += ` AND EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
			WHERE u.id = c.user_id AND u.status = 'active' AND u.agent_id = ? AND u.deleted_at IS NULL AND r.slug = 'kurir')`
		args = append(args, agentID)
	}
	query += " ORDER BY c.name"
	rows, err := s.database.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var name string
		if err := rows.Scan(&id, &name); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": id, "name": name})
	}
	return out, rows.Err()
}

// Expeditions is DispatchController::expeditions — RajaOngkir shipments awaiting handover/dispatch
// with the four readiness gates (operational booleans only — never amounts).
func (s *Service) Expeditions(ctx context.Context, a Actor, q url.Values) (map[string]any, error) {
	agentID, err := resolveAgentID(ctx, s.database, a, q)
	if err != nil {
		return nil, err
	}
	perPage := clampInt(q.Get("per_page"), 15, 1, 100)
	page := clampInt(q.Get("page"), 1, 1, 1<<30)
	const filter = `sh.shipping_provider_code = 'expedition' AND sh.status = 'pending'
		AND o.agent_id = ? AND o.status IN ('diproses','dikirim')`
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM shipments sh JOIN orders o ON o.id = sh.order_id
		WHERE `+filter, agentID).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	rows, err := s.database.QueryContext(ctx, `SELECT sh.id, o.id, o.order_no, o.recipient_name_snapshot,
		sh.tracking_number, CAST(sh.provider_meta AS CHAR)
		FROM shipments sh JOIN orders o ON o.id = sh.order_id WHERE `+filter+`
		ORDER BY sh.id DESC LIMIT ? OFFSET ?`, agentID, perPage, (page-1)*perPage)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var shipmentID, orderID int64
		var orderNo, recipient, tracking sql.NullString
		var providerMeta sql.NullString
		if err := rows.Scan(&shipmentID, &orderID, &orderNo, &recipient, &tracking, &providerMeta); err != nil {
			return nil, err
		}
		carrier, service := metaCarrierService(providerMeta.String)
		handoverRecorded := strings.Contains(providerMeta.String, `"handover"`)
		var outstanding int
		var stockID sql.NullInt64
		var stockStatus, coordinatorRequested sql.NullString
		err := s.database.QueryRowContext(ctx, `SELECT id, status, coordinator_requested_at FROM stock_requests
			WHERE order_id = ? ORDER BY id DESC LIMIT 1`, orderID).Scan(&stockID, &stockStatus, &coordinatorRequested)
		if err == nil {
			_ = s.database.QueryRowContext(ctx, `SELECT COALESCE(SUM(remaining_qty),0) FROM stock_request_items
				WHERE stock_request_id = ?`, stockID.Int64).Scan(&outstanding)
		} else if err != sql.ErrNoRows {
			return nil, err
		}
		var outside bool
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items i WHERE i.order_id = ?
			AND i.status <> 'dibatalkan' AND (i.shipment_id IS NULL OR i.shipment_id <> ?))`,
			orderID, shipmentID).Scan(&outside); err != nil {
			return nil, err
		}
		var paidInFull bool
		if err := s.database.QueryRowContext(ctx, `SELECT payment_status = 'paid' FROM orders WHERE id = ?`, orderID).Scan(&paidInFull); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{
			"shipment_id": shipmentID, "order_id": orderID, "order_no": orderNo.String,
			"recipient_name": nullStr(recipient), "carrier": carrier, "service": service,
			"tracking_number":   nullStr(tracking),
			"handover_recorded": handoverRecorded,
			"ready": map[string]any{
				"paid_in_full":    paidInFull,
				"goods_complete":  outstanding == 0 && (!stockID.Valid || !inSet(stockStatus.String, "pending", "partial")),
				"single_shipment": !outside,
			},
			"fulfillment_outstanding_qty": outstanding,
			"coordinator_requested_at":    mysqlToIso(coordinatorRequested),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return map[string]any{"data": data, "meta": map[string]int{"current_page": page, "last_page": lastPage, "total": total}}, nil
}

// Refusals is DispatchController::refusals — refusals awaiting the Koordinator (branch scope).
func (s *Service) Refusals(ctx context.Context, a Actor, q url.Values) (map[string]any, error) {
	agentID, err := resolveAgentID(ctx, s.database, a, q)
	if err != nil {
		return nil, err
	}
	perPage := clampInt(q.Get("per_page"), 15, 1, 100)
	page := clampInt(q.Get("page"), 1, 1, 1<<30)
	where := []string{"r.agent_id = ?"}
	args := []any{agentID}
	if v := q.Get("status"); v != "" {
		where = append(where, "r.status = ?")
		args = append(args, v)
	} else {
		// DeliveryRefusal::OPEN_STATUSES — verified open set.
		where = append(where, "r.status IN ('with_courier','handed_back','received')")
	}
	filter := strings.Join(where, " AND ")
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM delivery_refusals r WHERE `+filter, args...).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT r.id, r.status, r.quantity, r.reason, r.order_id,
		o.order_no, i.product_name_snapshot, r.shipment_id, r.recorded_at, r.handed_back_at,
		r.received_at, r.disposition_note
		FROM delivery_refusals r
		LEFT JOIN shipments sh ON sh.id = r.shipment_id
		LEFT JOIN orders o ON o.id = sh.order_id
		LEFT JOIN order_items i ON i.id = r.order_item_id
		WHERE `+filter+` ORDER BY r.id DESC LIMIT ? OFFSET ?`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, quantity, orderID int64
		var status string
		var reason, orderNo, productName sql.NullString
		var shipmentID sql.NullInt64
		var recorded, handedBack, received sql.NullTime
		var dispositionNote sql.NullString
		if err := rows.Scan(&id, &status, &quantity, &reason, &orderID, &orderNo, &productName, &shipmentID,
			&recorded, &handedBack, &received, &dispositionNote); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{
			"id": id, "status": status, "quantity": quantity, "reason": nullStr(reason),
			"order_id": orderID, "order_no": nullStr(orderNo), "product_name": nullStr(productName),
			"shipment_id":      nullIntPtr(shipmentID),
			"recorded_at":      carbonMicro(recorded),
			"handed_back_at":   carbonMicro(handedBack),
			"received_at":      carbonMicro(received),
			"disposition_note": nullStr(dispositionNote),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return map[string]any{"data": data, "meta": map[string]int{"current_page": page, "last_page": lastPage, "total": total}}, nil
} // Regions is DispatchController::regions — cascading region options sourced ONLY from orders currently
// eligible for THIS actor's dispatch queue. Levels compose downward; a level without its parent returns [].
func (s *Service) Regions(ctx context.Context, a Actor, q url.Values) (map[string]any, error) {
	agentID, err := resolveAgentID(ctx, s.database, a, q)
	if err != nil {
		return nil, err
	}
	eligible, args := dispatchEligibleOrders(q, agentID)
	options := func(idColumn, nameColumn, parentCol, parentVal string) ([]map[string]any, error) {
		query := fmt.Sprintf(`SELECT %s, MAX(%s) FROM orders o JOIN shipments sh ON sh.order_id = o.id
			WHERE sh.delivery_mode = 'standard' AND sh.status = 'pending' AND sh.courier_id IS NULL
			AND sh.self_delivered_by_user_id IS NULL AND `, idColumn, nameColumn) +
			eligible + ` AND ` + idColumn + ` IS NOT NULL GROUP BY ` + idColumn + ` ORDER BY 2`
		qArgs := append([]any(nil), args...)
		if parentCol != "" {
			query = strings.Replace(query, "AND "+eligible, "AND "+eligible+" AND o."+parentCol+" = ?", 1)
			qArgs = append(qArgs, parentVal)
		}
		rows, err := s.database.QueryContext(ctx, query, qArgs...)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		out := make([]map[string]any, 0)
		for rows.Next() {
			var id int64
			var name sql.NullString
			if err := rows.Scan(&id, &name); err != nil {
				return nil, err
			}
			if name.String == "" {
				continue
			}
			out = append(out, map[string]any{"id": strconv.FormatInt(id, 10), "name": name.String})
		}
		return out, rows.Err()
	}
	provinces, err := options("o.province_id", "o.province_snapshot", "", "")
	if err != nil {
		return nil, err
	}
	regencies := []map[string]any{}
	if v := q.Get("province_id"); v != "" {
		if regencies, err = options("o.regency_id", "o.regency_snapshot", "province_id", v); err != nil {
			return nil, err
		}
	}
	districts := []map[string]any{}
	if v := q.Get("regency_id"); v != "" {
		if districts, err = options("o.district_id", "o.district_snapshot", "regency_id", v); err != nil {
			return nil, err
		}
	}
	villages := []map[string]any{}
	if v := q.Get("district_id"); v != "" {
		if villages, err = options("o.village_id", "o.village_snapshot", "district_id", v); err != nil {
			return nil, err
		}
	}
	return map[string]any{"provinces": provinces, "regencies": regencies, "districts": districts, "villages": villages}, nil
}

// ---- helpers --------------------------------------------------------------------------------------

func clampInt(raw string, def, min, max int) int {
	n, err := strconv.Atoi(raw)
	if err != nil {
		return def
	}
	if n < min {
		return min
	}
	if n > max {
		return max
	}
	return n
}

func nullStr(v sql.NullString) any {
	if v.Valid {
		return v.String
	}
	return nil
}

func nullIntPtr(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

func carbonMicro(v sql.NullTime) any {
	if v.Valid {
		return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
	}
	return nil
}

func mysqlToIso(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	return strings.Replace(v.String, " ", "T", 1) + "Z"
}

func inSet(v string, set ...string) bool {
	for _, s := range set {
		if v == s {
			return true
		}
	}
	return false
}

// metaCarrierService reads provider_meta's courier/service keys; a non-JSON column degrades to nulls.
func metaCarrierService(raw string) (any, any) {
	if raw == "" {
		return nil, nil
	}
	var m map[string]any
	if err := json.Unmarshal([]byte(raw), &m); err != nil {
		return nil, nil
	}
	return m["courier"], m["service"]
}
