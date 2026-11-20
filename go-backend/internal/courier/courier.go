// Package courier ports CourierDashboardController + its ReturnService actions:
// the kurir's own delivery queue, return queue, pickup/confirm actions, delivered
// report and pickup-today KPI. Everything is scoped to the actor's own branch and
// (assignment-only) to their own Courier profile.
package courier

import (
	"context"
	"database/sql"
	"encoding/json"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Actor is the authenticated caller.
type Actor struct {
	ID      int64
	Role    string
	AgentID int64
}

// DomainError mirrors the other domain error shapes.
type DomainError struct {
	Status  int
	Message string
}

func (e *DomainError) Error() string { return e.Message }

func errStr(status int, message string) error { return &DomainError{Status: status, Message: message} }

// Service is the courier dashboard domain service.
type Service struct {
	database *db.DB
}

// New builds a courier service.
func New(database *db.DB) *Service { return &Service{database: database} }

func (s *Service) courierProfileID(ctx context.Context, a Actor) int64 {
	var id int64
	_ = s.database.DB.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id = ?`, a.ID).Scan(&id)
	return id
}

func clampPerPage(v string, def int) int {
	n, err := strconv.Atoi(v)
	if err != nil || n < 1 {
		return def
	}
	if n > 100 {
		return 100
	}
	return n
}

func pageOf(v string) int {
	n, err := strconv.Atoi(v)
	if err != nil || n < 1 {
		return 1
	}
	return n
}

func firstOf(q map[string][]string, key string) string {
	if v, ok := q[key]; ok && len(v) > 0 {
		return v[0]
	}
	return ""
}

func fmtTime(t sql.NullTime) any {
	if !t.Valid {
		return nil
	}
	return t.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

func fmtDate(t sql.NullTime) any {
	if !t.Valid {
		return nil
	}
	return t.Time.UTC().Format("2006-01-02")
}

func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func nullInt(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}

func mustJSON(v map[string]any) []byte {
	b, _ := json.Marshal(v)
	return b
}

// Orders ports CourierDashboardController::orders — the assignment-only delivery queue.
func (s *Service) Orders(ctx context.Context, a Actor, q map[string][]string) (map[string]any, error) {
	courierID := s.courierProfileID(ctx, a)
	status := firstOf(q, "status")
	valid := map[string]bool{"diproses": true, "dikirim": true, "terkirim": true}
	statuses := []string{"diproses", "dikirim"}
	if valid[status] {
		statuses = []string{status}
	}
	perPage := clampPerPage(firstOf(q, "per_page"), 20)
	page := pageOf(firstOf(q, "page"))

	ph := ""
	for i := range statuses {
		if i > 0 {
			ph += ","
		}
		ph += "?"
	}
	args := []any{}
	var itemCond string
	if a.Role == "sales-kurir-sub" {
		itemCond = `(SELECT 1 FROM order_items i JOIN shipments sh ON sh.id = i.shipment_id
			WHERE i.order_id = o.id AND i.status IN (` + ph + `)
			AND ((sh.delivery_mode = 'self_sub' AND sh.self_delivered_by_user_id = ?
			AND o.placed_by_user_id = ?) OR (sh.delivery_mode = 'standard' AND sh.courier_id = ?)))`
		for _, st := range statuses {
			args = append(args, st)
		}
		args = append(args, a.ID, a.ID, courierID)
	} else {
		itemCond = `SELECT 1 FROM order_items i JOIN shipments sh ON sh.id = i.shipment_id
			WHERE i.order_id = o.id AND i.status IN (` + ph + `) AND sh.delivery_mode = 'standard' AND sh.courier_id = ?`
		for _, st := range statuses {
			args = append(args, st)
		}
		args = append(args, courierID)
	}
	where := `o.agent_id = ? AND EXISTS(` + itemCond + `)`
	allArgs := append([]any{a.AgentID}, args...)

	var total int
	if err := s.database.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders o WHERE `+where, allArgs...).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	listArgs := append(append([]any(nil), allArgs...), perPage, (page-1)*perPage)
	rows, err := s.database.DB.QueryContext(ctx, `SELECT o.id, o.order_no, o.status, o.village_snapshot,
		o.district_snapshot, o.regency_snapshot, o.province_snapshot, o.recipient_name_snapshot,
		o.recipient_phone_snapshot, o.address_snapshot, o.latitude_snapshot, o.longitude_snapshot,
		o.created_at FROM orders o WHERE `+where+`
		ORDER BY o.delivery_date_estimate ASC, o.id DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var orderNo, status string
		var village, district, regency, province, rname, rphone, addr, lat, lng sql.NullString
		var createdAt sql.NullTime
		if err := rows.Scan(&id, &orderNo, &status, &village, &district, &regency, &province,
			&rname, &rphone, &addr, &lat, &lng, &createdAt); err != nil {
			return nil, err
		}
		items, ierr := s.visibleItems(ctx, a, id, courierID)
		if ierr != nil {
			return nil, ierr
		}
		data = append(data, map[string]any{
			"id": id, "order_no": orderNo, "status": status, "detail_available": true,
			"village": nullStr(village), "district": nullStr(district), "regency": nullStr(regency),
			"province": nullStr(province), "items": items, "created_at": fmtTime(createdAt),
			"recipient_name": nullStr(rname), "recipient_phone": nullStr(rphone),
			"address": nullStr(addr), "latitude": nullStr(lat), "longitude": nullStr(lng),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return map[string]any{"data": data, "meta": map[string]int{"current_page": page, "last_page": lastPage, "total": total}}, nil
}

// visibleItems renders the per-item projection with the viewer filter applied.
func (s *Service) visibleItems(ctx context.Context, a Actor, orderID, courierID int64) ([]map[string]any, error) {
	rows, err := s.database.DB.QueryContext(ctx, `SELECT i.id, i.shipment_id, i.product_name_snapshot,
		i.variation_label_snapshot, i.sku_snapshot, i.fulfilled_quantity, i.status, i.requested_delivery_date
		FROM order_items i WHERE i.order_id = ? ORDER BY i.id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var shipID sql.NullInt64
		var pname, vlabel, sku sql.NullString
		var qty int64
		var status string
		var reqDate sql.NullString
		if err := rows.Scan(&id, &shipID, &pname, &vlabel, &sku, &qty, &status, &reqDate); err != nil {
			return nil, err
		}
		visible := false
		mode := ""
		if shipID.Valid {
			var selfBy sql.NullInt64
			var shCourier sql.NullInt64
			if err := s.database.DB.QueryRowContext(ctx, `SELECT delivery_mode, self_delivered_by_user_id,
				courier_id FROM shipments WHERE id = ?`, shipID.Int64).Scan(&mode, &selfBy, &shCourier); err == nil {
				if a.Role == "sales-kurir-sub" {
					visible = (mode == "self_sub" && selfBy.Valid && selfBy.Int64 == a.ID) ||
						(mode == "standard" && courierID != 0 && shCourier.Valid && shCourier.Int64 == courierID)
				} else {
					visible = mode == "standard" && courierID != 0 && shCourier.Valid && shCourier.Int64 == courierID
				}
			}
		}
		if !visible {
			continue
		}
		var refusal any
		var rID int64
		var rStatus string
		if err := s.database.DB.QueryRowContext(ctx, `SELECT id, status FROM delivery_refusals
			WHERE order_item_id = ? AND status IN ('with_courier','handed_back','received')
			ORDER BY id DESC LIMIT 1`, id).Scan(&rID, &rStatus); err == nil {
			refusal = map[string]any{"id": rID, "status": rStatus}
		}
		items = append(items, map[string]any{
			"id": id, "shipment_id": nullInt(shipID), "product_name": nullStr(pname),
			"variation_label": nullStr(vlabel), "sku": nullStr(sku), "quantity": qty, "status": status,
			"requested_delivery_date": nullStr(reqDate), "delivery_mode": mode, "open_refusal": refusal,
		})
	}
	return items, rows.Err()
}

// Returns ports CourierDashboardController::returns — assignment-only return queue.
func (s *Service) Returns(ctx context.Context, a Actor, q map[string][]string) (map[string]any, error) {
	courierID := s.courierProfileID(ctx, a)
	perPage := clampPerPage(firstOf(q, "per_page"), 20)
	page := pageOf(firstOf(q, "page"))
	where := `EXISTS(SELECT 1 FROM orders o WHERE o.id = r.order_id AND o.agent_id = ?)
		AND EXISTS(SELECT 1 FROM return_items ri WHERE ri.return_id = r.id
		AND ri.status IN ('pending','approved') AND ri.courier_id = ?
		AND EXISTS(SELECT 1 FROM order_items oi WHERE oi.id = ri.order_item_id AND oi.status = 'pengembalian'))`
	args := []any{a.AgentID, courierID}
	var total int
	if err := s.database.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM returns r WHERE `+where, args...).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	rows, err := s.database.DB.QueryContext(ctx, `SELECT r.id, r.order_id, r.reason, r.created_at
		FROM returns r WHERE `+where+` ORDER BY r.id DESC LIMIT ? OFFSET ?`,
		append(append([]any(nil), args...), perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, orderID int64
		var reason sql.NullString
		var createdAt sql.NullTime
		if err := rows.Scan(&id, &orderID, &reason, &createdAt); err != nil {
			return nil, err
		}
		var orderNo string
		_ = s.database.DB.QueryRowContext(ctx, `SELECT order_no FROM orders WHERE id = ?`, orderID).Scan(&orderNo)
		items, ierr := s.returnItems(ctx, id, courierID)
		if ierr != nil {
			return nil, ierr
		}
		data = append(data, map[string]any{"id": id, "order_id": orderID, "order_no": orderNo,
			"reason": nullStr(reason), "items": items, "created_at": fmtTime(createdAt)})
	}
	return map[string]any{"data": data, "meta": map[string]int{"current_page": page, "last_page": lastPage, "total": total}}, nil
}

func (s *Service) returnItems(ctx context.Context, returnID, courierID int64) ([]map[string]any, error) {
	rows, err := s.database.DB.QueryContext(ctx, `SELECT ri.id, ri.order_item_id, ri.quantity_returned,
		ri.condition_note, ri.courier_id, ri.pickup_scheduled_for, oi.product_name_snapshot,
		oi.variation_label_snapshot, oi.sku_snapshot, oi.status FROM return_items ri
		JOIN order_items oi ON oi.id = ri.order_item_id
		WHERE ri.return_id = ? AND ri.status IN ('pending','approved') AND ri.courier_id = ?
		AND oi.status = 'pengembalian' ORDER BY ri.id`, returnID, courierID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]map[string]any, 0)
	for rows.Next() {
		var id, orderItemID, qty int64
		var note sql.NullString
		var cID sql.NullInt64
		var sched sql.NullTime
		var pname, vlabel, sku sql.NullString
		var status string
		if err := rows.Scan(&id, &orderItemID, &qty, &note, &cID, &sched, &pname, &vlabel, &sku, &status); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"id": id, "order_item_id": orderItemID,
			"product_name": nullStr(pname), "variation_label": nullStr(vlabel), "sku": nullStr(sku),
			"quantity_returned": qty, "status": status, "condition_note": nullStr(note),
			"pickup_scheduled_for": fmtDate(sched), "picked_up_by_me": cID.Valid && cID.Int64 == courierID})
	}
	return items, rows.Err()
}

// PickupReturn ports ReturnService::pickupReturn (physical claim on a scheduled pickup).
func (s *Service) PickupReturn(ctx context.Context, a Actor, returnItemID int64, note string) (map[string]any, error) {
	return s.itemAction(ctx, a, returnItemID, note, -1)
}

// ConfirmReturn ports ReturnService::courierConfirmReturn.
func (s *Service) ConfirmReturn(ctx context.Context, a Actor, returnItemID int64, received bool) (map[string]any, error) {
	r := 0
	if received {
		r = 1
	}
	return s.itemAction(ctx, a, returnItemID, "", r)
}

func (s *Service) itemAction(ctx context.Context, a Actor, returnItemID int64, note string, received int) (map[string]any, error) {
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderItemID int64
		if err := tx.QueryRowContext(ctx, `SELECT order_item_id FROM return_items WHERE id = ? FOR UPDATE`,
			returnItemID).Scan(&orderItemID); err != nil {
			if err == sql.ErrNoRows {
				return errStr(404, "")
			}
			return err
		}
		var agentID int64
		if err := tx.QueryRowContext(ctx, `SELECT o.agent_id FROM order_items i JOIN orders o ON o.id = i.order_id
			WHERE i.id = ?`, orderItemID).Scan(&agentID); err != nil {
			return err
		}
		if agentID != a.AgentID {
			return errStr(403, "Anda tidak memiliki akses untuk melakukan aksi ini.")
		}
		var itemStatus string
		if err := tx.QueryRowContext(ctx, `SELECT status FROM order_items WHERE id = ? FOR UPDATE`,
			orderItemID).Scan(&itemStatus); err != nil {
			return err
		}
		if itemStatus != "pengembalian" {
			return errStr(422, "Pengajuan pengembalian ini sudah diproses sebelumnya.")
		}
		var courierID int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id = ?`, a.ID).Scan(&courierID); err != nil {
			return errStr(422, "Order ini sudah ditugaskan ke kurir lain.")
		}
		var assignedCourier sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT courier_id FROM return_items WHERE id = ?`,
			returnItemID).Scan(&assignedCourier); err != nil {
			return err
		}
		if !assignedCourier.Valid || assignedCourier.Int64 != courierID {
			return errStr(403, "Order ini sudah ditugaskan ke kurir lain.")
		}
		if received >= 0 {
			if received == 1 {
				if _, err := tx.ExecContext(ctx, `UPDATE order_items SET status='kembali',
					updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, orderItemID); err != nil {
					return err
				}
			} else {
				if _, err := tx.ExecContext(ctx, `UPDATE return_items SET status='rejected',
					refund_status='not_required', updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, returnItemID); err != nil {
					return err
				}
				if _, err := tx.ExecContext(ctx, `UPDATE order_items SET returned_quantity = GREATEST(0,
					returned_quantity - (SELECT quantity_returned FROM return_items WHERE id = ?)),
					status='terkirim', updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, returnItemID, orderItemID); err != nil {
					return err
				}
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
				description, properties, created_at)
				VALUES (?, 'App\Models\ReturnItem', ?, 'return_item.courier_confirmed', NULL, ?, UTC_TIMESTAMP(6))`,
				a.ID, returnItemID, mustJSON(map[string]any{"received": received == 1, "actor_role": a.Role})); err != nil {
				return err
			}
		} else {
			if note != "" {
				if _, err := tx.ExecContext(ctx, `UPDATE return_items SET condition_note = ?,
					updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, note, returnItemID); err != nil {
					return err
				}
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
				description, properties, created_at)
				VALUES (?, 'App\Models\ReturnItem', ?, 'return_item.picked_up', ?, ?, UTC_TIMESTAMP(6))`,
				a.ID, returnItemID, nullStr(sql.NullString{String: note, Valid: note != ""}),
				mustJSON(map[string]any{"actor_role": a.Role})); err != nil {
				return err
			}
		}
		var cID sql.NullInt64
		var condNote sql.NullString
		var sched sql.NullTime
		var status sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT courier_id, condition_note, pickup_scheduled_for, status
			FROM return_items WHERE id = ?`, returnItemID).Scan(&cID, &condNote, &sched, &status); err != nil {
			return err
		}
		out = map[string]any{"id": returnItemID, "courier_id": nullInt(cID),
			"condition_note": nullStr(condNote), "pickup_scheduled_for": fmtTime(sched)}
		if received >= 0 {
			// confirm response: id, courier_id, status, condition_note only.
			delete(out, "pickup_scheduled_for")
			out["status"] = nullStr(status)
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

// DeliveredReport ports CourierDashboardController::deliveredReport.
func (s *Service) DeliveredReport(ctx context.Context, a Actor, q map[string][]string) (map[string]any, error) {
	courierID := s.courierProfileID(ctx, a)
	if courierID == 0 {
		return map[string]any{"count": 0, "orders": []any{}}, nil
	}
	perPage := clampPerPage(firstOf(q, "per_page"), 20)
	page := pageOf(firstOf(q, "page"))
	where := `o.agent_id = ? AND EXISTS(SELECT 1 FROM order_items i JOIN shipments sh ON sh.id = i.shipment_id
		WHERE i.order_id = o.id AND i.status = 'terkirim' AND sh.courier_id = ?)`
	args := []any{a.AgentID, courierID}
	if v := firstOf(q, "from"); v != "" {
		where += ` AND DATE(o.updated_at) >= ?`
		args = append(args, v)
	}
	if v := firstOf(q, "to"); v != "" {
		where += ` AND DATE(o.updated_at) <= ?`
		args = append(args, v)
	}
	var total int
	if err := s.database.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders o WHERE `+where, args...).Scan(&total); err != nil {
		return nil, err
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	rows, err := s.database.DB.QueryContext(ctx, `SELECT o.id, o.order_no, o.status, o.village_snapshot,
		o.district_snapshot, o.regency_snapshot, o.province_snapshot, o.recipient_name_snapshot,
		o.recipient_phone_snapshot, o.address_snapshot, o.latitude_snapshot, o.longitude_snapshot,
		o.created_at FROM orders o WHERE `+where+`
		ORDER BY o.updated_at DESC, o.id DESC LIMIT ? OFFSET ?`,
		append(append([]any(nil), args...), perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	orders := make([]map[string]any, 0)
	for rows.Next() {
		var id int64
		var orderNo, status string
		var village, district, regency, province, rname, rphone, addr, lat, lng sql.NullString
		var createdAt sql.NullTime
		if err := rows.Scan(&id, &orderNo, &status, &village, &district, &regency, &province,
			&rname, &rphone, &addr, &lat, &lng, &createdAt); err != nil {
			return nil, err
		}
		items, ierr := s.visibleItems(ctx, a, id, courierID)
		if ierr != nil {
			return nil, ierr
		}
		orders = append(orders, map[string]any{
			"id": id, "order_no": orderNo, "status": status, "detail_available": true,
			"village": nullStr(village), "district": nullStr(district), "regency": nullStr(regency),
			"province": nullStr(province), "items": items, "created_at": fmtTime(createdAt),
			"recipient_name": nullStr(rname), "recipient_phone": nullStr(rphone), "address": nullStr(addr),
			"latitude": nullStr(lat), "longitude": nullStr(lng),
		})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	for _, row := range orders {
		var fee sql.NullFloat64
		if err := s.database.DB.QueryRowContext(ctx, `SELECT SUM(amount) FROM commissions
			WHERE beneficiary_user_id = ? AND beneficiary_role = 'courier' AND order_id = ?`,
			a.ID, row["id"]).Scan(&fee); err == nil && fee.Valid {
			row["fee_amount"] = fee.Float64
		} else {
			row["fee_amount"] = float64(0)
		}
	}
	return map[string]any{"data": orders, "meta": map[string]int{"current_page": page, "last_page": lastPage, "total": total}}, nil
}

// PickupToday ports CourierDashboardController::pickupToday (WIB business date).
func (s *Service) PickupToday(ctx context.Context, a Actor) (map[string]any, error) {
	courierID := s.courierProfileID(ctx, a)
	jakarta, _ := time.LoadLocation("Asia/Jakarta")
	today := time.Now().In(jakarta).Format("2006-01-02")
	var count int
	if err := s.database.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM return_items ri
		JOIN order_items oi ON oi.id = ri.order_item_id
		JOIN orders o ON o.id = oi.order_id
		WHERE ri.status IN ('pending','approved') AND DATE(ri.pickup_scheduled_for) = ?
		AND oi.status = 'pengembalian' AND o.agent_id = ? AND ri.courier_id = ?`,
		today, a.AgentID, courierID).Scan(&count); err != nil {
		return nil, err
	}
	return map[string]any{"date": today, "count": count}, nil
}
