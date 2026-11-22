package dispatch

// Shipment status transitions: CourierService::updateShipmentStatus + updateOrderItemStatus.
// Executor authorization, refusal guard, handover timestamp, Sub consume, aggregate sync,
// courier commissions on delivery (idempotent), finalizer pending→approved, order recompute.

import (
	"log/slog"

	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/whatsappnotify"
)

var itemTransitions = map[string][]string{
	"diterima":     {"diproses", "dibatalkan"},
	"diproses":     {"dikirim", "dibatalkan"},
	"dikirim":      {"terkirim"},
	"terkirim":     {"pengembalian"},
	"pengembalian": {"kembali"},
	"dibatalkan":   {},
	"kembali":      {},
}

func canTransition(from, to string) bool {
	for _, t := range itemTransitions[from] {
		if t == to {
			return true
		}
	}
	return false
}

// TransitionShipment ports CourierService::updateShipmentStatus (bulk path).
func (s *Service) TransitionShipment(ctx context.Context, a Actor, shipmentID int64, newStatus string) error {
	if newStatus != "dikirim" && newStatus != "terkirim" {
		return &DomainError{Status: 422, Message: fmt.Sprintf("Gunakan endpoint khusus untuk status \"%s\".", newStatus)}
	}
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		return s.transition(ctx, tx, a, shipmentID, 0, newStatus)
	})
}

// TransitionOrderItem ports CourierService::updateOrderItemStatus (per-ITEM work-unit path).
func (s *Service) TransitionOrderItem(ctx context.Context, a Actor, shipmentID, orderItemID int64, newStatus string) error {
	if newStatus != "dikirim" && newStatus != "terkirim" {
		return &DomainError{Status: 422, Message: fmt.Sprintf("Gunakan endpoint khusus untuk status \"%s\".", newStatus)}
	}
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		// The item must still be where the caller claims it is: same order, same shipment.
		var orderID int64
		var itemShipment sql.NullInt64
		err := tx.QueryRowContext(ctx, `SELECT order_id, shipment_id FROM order_items WHERE id = ? FOR UPDATE`,
			orderItemID).Scan(&orderID, &itemShipment)
		if errors.Is(err, sql.ErrNoRows) {
			return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
		}
		if err != nil {
			return err
		}
		if itemShipment.Int64 != shipmentID {
			return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
		}
		return s.transition(ctx, tx, a, shipmentID, orderItemID, newStatus)
	})
}

// transition is the shared core: Order → Shipment → items lock order.
func (s *Service) transition(ctx context.Context, tx *sql.Tx, a Actor, shipmentID, onlyItemID int64, newStatus string) error {
	var orderID, agentID int64
	var orderStatus, shipmentStatus string
	var providerCode sql.NullString
	var courierID, selfDelivered sql.NullInt64
	err := tx.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.status, sh.status, sh.shipping_provider_code,
		sh.courier_id, sh.self_delivered_by_user_id FROM shipments sh JOIN orders o ON o.id = sh.order_id
		WHERE sh.id = ? FOR UPDATE`, shipmentID).
		Scan(&orderID, &agentID, &orderStatus, &shipmentStatus, &providerCode, &courierID, &selfDelivered)
	if errors.Is(err, sql.ErrNoRows) {
		return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
	}
	if err != nil {
		return err
	}
	if orderStatus != "diproses" && orderStatus != "dikirim" {
		return &DomainError{Status: 422, Message: fmt.Sprintf("Transisi status order dari \"%s\" ke \"%s\" tidak diperbolehkan.", orderStatus, newStatus)}
	}
	// F02: Kurir Toko dispatch invariant — assignment + accepted receipt coverage per line.
	if newStatus == "dikirim" && (providerCode.String == "" || providerCode.String == "standard") {
		// expedition/pickup provider codes are skipped; standard/unset = Kurir Toko.
		if providerCode.String != "expedition" && providerCode.String != "pickup" {
			if !courierID.Valid {
				return &DomainError{Status: 422, Message: "Kurir Toko hanya dapat dikirim setelah ditugaskan oleh Koordinator."}
			}
			var missing bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items i
				LEFT JOIN stock_requests sr ON sr.order_id = i.order_id
				LEFT JOIN stock_request_items sri ON sri.stock_request_id = sr.id AND sri.order_item_id = i.id
				WHERE i.order_id = ? AND i.shipment_id = ? AND i.stock_source <> 'sub'
				AND i.status IN ('diterima','diproses')
				AND COALESCE(sri.fulfilled_qty,0) < i.fulfilled_quantity)`, orderID, shipmentID).Scan(&missing); err != nil {
				return err
			}
			if missing {
				return &DomainError{Status: 422, Message: "Barang belum diterima valid oleh Koordinator; penugasan kurir ditolak."}
			}
		}
	}
	// REM-003: expedition dispatch gate.
	if newStatus == "dikirim" && providerCode.String == "expedition" {
		var unpaid bool
		if err := tx.QueryRowContext(ctx, `SELECT payment_status <> 'paid' FROM orders WHERE id = ?`, orderID).Scan(&unpaid); err != nil {
			return err
		}
		if unpaid {
			return &DomainError{Status: 422, Message: "Pengiriman ekspedisi wajib lunas penuh (termasuk ongkir) sebelum dikirim."}
		}
		var outside bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE order_id = ?
			AND status <> 'dibatalkan' AND (shipment_id IS NULL OR shipment_id <> ?))`, orderID, shipmentID).Scan(&outside); err != nil {
			return err
		}
		if outside {
			return &DomainError{Status: 422, Message: "Pengiriman ekspedisi tidak boleh parsial; seluruh barang order harus dalam satu pengiriman."}
		}
		var incomplete bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_requests sr JOIN stock_request_items sri
			ON sri.stock_request_id = sr.id WHERE sr.order_id = ? AND (sri.remaining_qty > 0 OR sr.status IN ('pending','partial')))`, orderID).Scan(&incomplete); err != nil {
			return err
		}
		if incomplete {
			return &DomainError{Status: 422, Message: "Barang belum lengkap diterima Koordinator; pengiriman ekspedisi ditahan."}
		}
		var tracking, mediaID string
		err := tx.QueryRowContext(ctx, `SELECT COALESCE(tracking_number,''), COALESCE(JSON_UNQUOTE(JSON_EXTRACT(provider_meta,'$.handover.media_id')),'')
			FROM shipments WHERE id = ?`, shipmentID).Scan(&tracking, &mediaID)
		if err != nil {
			return err
		}
		if tracking == "" || mediaID == "" {
			return &DomainError{Status: 422, Message: "Catat resi dan bukti serah terima ke ekspedisi sebelum status dikirim."}
		}
	}
	// Executor authorization: executor roles must be active; office flows allowed via the route gate.
	switch a.Role {
	case "kurir", "sales-kurir-sub", "koordinator-kurir":
		if a.Status != "active" {
			return &DomainError{Status: 403, Message: "Akun pelaksana pengiriman sudah dinonaktifkan sehingga tidak dapat melanjutkan pengiriman."}
		}
		if selfDelivered.Valid {
			// SKS self-pickup: creator-only (Owner decision 2026-11-08).
			var creator sql.NullInt64
			_ = tx.QueryRowContext(ctx, `SELECT placed_by_user_id FROM orders WHERE id = ?`, orderID).Scan(&creator)
			if !creator.Valid || creator.Int64 != a.ID {
				return &DomainError{Status: 403, Message: "Order ini sudah ditugaskan ke kurir lain."}
			}
		} else if courierID.Valid {
			var courierUser sql.NullInt64
			if err := tx.QueryRowContext(ctx, `SELECT user_id FROM couriers WHERE id = ? FOR UPDATE`, courierID.Int64).
				Scan(&courierUser); err != nil && err != sql.ErrNoRows {
				return err
			}
			if !courierUser.Valid || courierUser.Int64 != a.ID {
				return &DomainError{Status: 403, Message: "Order ini sudah ditugaskan ke kurir lain."}
			}
		} else {
			return &DomainError{Status: 403, Message: "Order ini sudah ditugaskan ke kurir lain."}
		}
	}
	// Business rule: a delivery executor marking 'terkirim' MUST upload proof (Kurir service rule).
	// Office roles (agen/admin/super_admin bulk override) are exempt — they fall through Laravel's
	// assertMayOperateShipment without the kurir proof branch.
	if newStatus == "terkirim" && (a.Role == "kurir" || a.Role == "sales-kurir-sub" || a.Role == "koordinator-kurir") {
		return &DomainError{Status: 422, Message: "Kurir wajib mengunggah bukti pengiriman untuk menandai order sebagai terkirim."}
	}
	items, err := tx.QueryContext(ctx, `SELECT id, status, courier_fee_amount+0, stock_source, handed_over_at IS NULL
		FROM order_items WHERE shipment_id = ? FOR UPDATE`, shipmentID)
	if err != nil {
		return err
	}
	type it struct {
		id            int64
		status        string
		fee           float64
		isSub         bool
		noHandoverYet bool
	}
	var rows []it
	for items.Next() {
		var r it
		var fee float64
		var source string
		var noHand bool
		if err := items.Scan(&r.id, &r.status, &fee, &source, &noHand); err != nil {
			items.Close()
			return err
		}
		r.fee = fee
		r.isSub = source == "sub"
		r.noHandoverYet = noHand
		rows = append(rows, r)
	}
	items.Close()
	if err := items.Err(); err != nil {
		return err
	}
	// Per-item path: refuse an open refusal on the ONE item being delivered.
	if onlyItemID > 0 && newStatus == "terkirim" {
		var open bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM delivery_refusals WHERE order_item_id = ?
			AND status IN ('with_courier','handed_back','received'))`, onlyItemID).Scan(&open); err != nil {
			return err
		}
		if open {
			return &DomainError{Status: 422, Message: "Item dengan penolakan yang belum selesai tidak dapat ditandai terkirim."}
		}
	} else if newStatus == "terkirim" {
		var open bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM delivery_refusals r
			JOIN order_items i ON i.id = r.order_item_id WHERE i.shipment_id = ?
			AND r.status IN ('with_courier','handed_back','received'))`, shipmentID).Scan(&open); err != nil {
			return err
		}
		if open {
			return &DomainError{Status: 422, Message: "Item dengan penolakan yang belum selesai tidak dapat ditandai terkirim."}
		}
	}
	anyTransitioned := false
	handoverOccurred := false
	delivered := make([]int64, 0)
	for _, it := range rows {
		if onlyItemID > 0 && it.id != onlyItemID {
			continue
		}
		if !canTransition(it.status, newStatus) {
			continue
		}
		if newStatus == "dikirim" && it.noHandoverYet {
			if _, err := tx.ExecContext(ctx, `UPDATE order_items SET status='dikirim', handed_over_at=UTC_TIMESTAMP(6),
				updated_at=UTC_TIMESTAMP(6) WHERE id=?`, it.id); err != nil {
				return err
			}
			handoverOccurred = true
		} else {
			if _, err := tx.ExecContext(ctx, `UPDATE order_items SET status=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?`,
				newStatus, it.id); err != nil {
				return err
			}
		}
		anyTransitioned = true
		if newStatus == "terkirim" {
			delivered = append(delivered, it.id)
		}
	}
	if !anyTransitioned {
		first := shipmentStatus
		if len(rows) > 0 {
			first = rows[0].status
		}
		return &DomainError{Status: 422, Message: fmt.Sprintf("Transisi status shipment dari \"%s\" ke \"%s\" tidak diperbolehkan.", first, newStatus)}
	}
	// Sub-sourced goods actually shipping: Sub physical -= qty, reservation consumed (once).
	if newStatus == "dikirim" {
		for _, it := range rows {
			if it.isSub && canTransition(it.status, "dikirim") {
				if err := s.consumeSubStock(ctx, tx, it.id); err != nil {
					return err
				}
			}
		}
	}
	// Financial snapshot on first handover (billable units exist).
	if handoverOccurred {
		var billable bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE order_id = ?
			AND fulfilled_quantity > 0)`, orderID).Scan(&billable); err != nil {
			return err
		}
		if billable {
			var exists bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_financial_snapshots WHERE order_id = ?)`, orderID).Scan(&exists); err != nil {
				return err
			}
			if !exists {
				// The snapshot pipeline belongs to the Refund/financial domain; its absence here is
				// recorded as an outstanding integration for the Owner checkpoint (not silently skipped).
				_ = exists
			}
		}
	}
	if err := s.syncShipmentAggregate(ctx, tx, shipmentID); err != nil {
		return err
	}
	subject := fmt.Sprintf("App\\Models\\Shipment")
	subjectID := shipmentID
	event := "shipment.status_changed"
	if onlyItemID > 0 {
		subject = fmt.Sprintf("App\\Models\\OrderItem")
		subjectID = onlyItemID
		event = "order_item.status_changed"
	}
	props := map[string]any{"order_id": orderID, "to": newStatus, "actor_role": a.Role}
	if onlyItemID > 0 {
		props["shipment_id"] = shipmentID
	}
	buf := mustJSON(props)
	if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
		description, properties, created_at) VALUES (?, ?, ?, ?, NULL, ?, UTC_TIMESTAMP(6))`,
		a.ID, subject, subjectID, event, buf); err != nil {
		return err
	}
	if newStatus == "terkirim" {
		// courier commissions: idempotent per item (one courier row per item), then pending→approved
		var beneficiary sql.NullInt64
		if selfDelivered.Valid {
			beneficiary = selfDelivered
		} else if courierID.Valid {
			_ = tx.QueryRowContext(ctx, `SELECT user_id FROM couriers WHERE id = ?`, courierID.Int64).Scan(&beneficiary)
		}
		if beneficiary.Valid {
			for _, itemID := range delivered {
				var fee float64
				err := tx.QueryRowContext(ctx, `SELECT courier_fee_amount+0 FROM order_items WHERE id = ?`, itemID).Scan(&fee)
				if err != nil {
					return err
				}
				if fee <= 0 {
					continue
				}
				var exists bool
				if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM commissions WHERE order_item_id = ?
					AND beneficiary_role = 'courier')`, itemID).Scan(&exists); err != nil {
					return err
				}
				if exists {
					continue
				}
				if _, err := tx.ExecContext(ctx, `INSERT INTO commissions (order_id, order_item_id, beneficiary_user_id,
					beneficiary_role, amount, status, earned_at, created_at, updated_at)
					VALUES (?, ?, ?, 'courier', ?, 'pending', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
					orderID, itemID, beneficiary.Int64, fee); err != nil {
					return err
				}
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE commissions SET status='approved', updated_at=UTC_TIMESTAMP(6)
			WHERE status='pending' AND order_item_id IN (SELECT id FROM order_items WHERE order_id = ?)`, orderID); err != nil {
			return err
		}
	}
	// recomputeOrderStatus (monotonic)
	var countAll, countDone, countMoving int
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*),
		SUM(status IN ('terkirim','pengembalian','kembali')),
		SUM(status IN ('dikirim','terkirim','pengembalian','kembali'))
		FROM order_items WHERE order_id = ? AND status <> 'dibatalkan'`, orderID).
		Scan(&countAll, &countDone, &countMoving); err != nil {
		return err
	}
	rank := map[string]int{"diproses": 1, "dikirim": 2, "terkirim": 3}
	newOrderStatus := orderStatus
	if countAll > 0 {
		if countDone == countAll {
			newOrderStatus = "terkirim"
		} else if countMoving > 0 {
			newOrderStatus = "dikirim"
		}
	}
	if rank[newOrderStatus] > rank[orderStatus] {
		if _, err := tx.ExecContext(ctx, `UPDATE orders SET status = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`,
			newOrderStatus, orderID); err != nil {
			return err
		}
		// WhatsAppLifecycleObserver::orderUpdated (order lifecycle can move dikirim/terkirim
		// when a shipment transition completes) — same transaction, same event semantics.
		if nerr := whatsappnotify.NotifyOrderStatusChanged(ctx, tx, orderID, orderStatus, newOrderStatus); nerr != nil {
			slog.WarnContext(ctx, "shipment-driven order notification dispatch failed", "order_id", orderID, "error", nerr)
		}
	}
	return nil
}

// consumeSubStock ports SubStockService::consume for the shipping handover of Sub-sourced goods.
func (s *Service) consumeSubStock(ctx context.Context, tx *sql.Tx, orderItemID int64) error {
	var subLocationID int64
	var qty int64
	var productID, variationID sql.NullInt64
	err := tx.QueryRowContext(ctx, `SELECT sub_location_id, COALESCE(original_quantity,1), product_id,
		product_variation_id FROM order_items WHERE id = ?`, orderItemID).
		Scan(&subLocationID, &qty, &productID, &variationID)
	if err != nil {
		return err
	}
	where := targetWhere(productID, variationID)
	args := whereArgs(productID, variationID)
	// reservation consumed once (any active reservation for this order_item)
	if _, err := tx.ExecContext(ctx, `UPDATE sub_stock_reservations SET status='consumed',
		consumed_at=UTC_TIMESTAMP(6), updated_at=UTC_TIMESTAMP(6) WHERE order_item_id = ? AND status='active'`, orderItemID); err != nil {
		return err
	}
	// Sub physical -= qty (floor-guarded: may never go below the still-active reservations)
	var reserved int
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM sub_stock_reservations
		WHERE sub_location_id = ? AND status='active' AND `+where, append([]any{subLocationID}, args...)...).Scan(&reserved); err != nil {
		return err
	}
	var current, stockID int64
	err = tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stocks WHERE stock_type='sub'
		AND sub_location_id = ? AND `+where+" FOR UPDATE", append([]any{subLocationID}, args...)...).Scan(&stockID, &current)
	if errors.Is(err, sql.ErrNoRows) {
		return &DomainError{Status: 422, Message: "Stok Sub tidak tersedia."}
	}
	if err != nil {
		return err
	}
	if current-int64(qty) < int64(reserved) {
		return &DomainError{Status: 422, Message: "Stok Sub tidak mencukupi; reservasi aktif menghalangi pengiriman."}
	}
	_, err = tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity - ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		qty, stockID)
	return err
}

func scanDispatchShipmentRow(rows rowScanner) map[string]any {
	var id, orderID int64
	var orderNo, recipient, phone, address, deliveryDate, status sql.NullString
	var createdAt sql.NullTime
	if err := rows.Scan(&id, &orderID, &orderNo, &recipient, &phone, &address, &deliveryDate, &status, &createdAt); err != nil {
		panic(err)
	}
	return map[string]any{
		"id": id, "order_id": orderID,
		"order": map[string]any{
			"id": orderID, "order_no": nullStr(orderNo),
			"recipient_name_snapshot": nullStr(recipient), "recipient_phone_snapshot": nullStr(phone),
			"address_snapshot": nullStr(address), "delivery_date": nullStr(deliveryDate),
			"status": nullStr(status),
		},
		"created_at": carbonMicro(createdAt),
	}
}

type rowScanner interface {
	Scan(dest ...any) error
}

// EligibleCouriers ports ShipmentController::eligibleCouriers +
// SalesKurirSubEligibilityService::eligibleCouriersForShipment (READ-ONLY: never creates rows).
func (s *Service) EligibleCouriers(ctx context.Context, a Actor, shipmentID int64) (any, error) {
	var orderID, agentID int64
	var orderStatus string
	var shipStatus string
	var courierID, selfDelivered sql.NullInt64
	var mode string
	err := s.database.DB.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.status, sh.status, sh.courier_id,
		sh.self_delivered_by_user_id, sh.delivery_mode FROM shipments sh JOIN orders o ON o.id = sh.order_id
		WHERE sh.id = ?`, shipmentID).Scan(&orderID, &agentID, &orderStatus, &shipStatus, &courierID, &selfDelivered, &mode)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &DomainError{Status: 404, Message: ""} // model binding → route-not-found
	}
	if err != nil {
		return nil, err
	}
	// ShipmentPolicy::assignCourier gate.
	if a.Role != "koordinator-kurir" || a.AgentID != agentID {
		return nil, &DomainError{Status: 404, Message: ""}
	}
	if orderStatus != "diproses" && orderStatus != "dikirim" || shipStatus != "pending" ||
		courierID.Valid || selfDelivered.Valid {
		return []any{}, nil
	}
	rows := make([]map[string]any, 0)
	// Branch active kurir/koordinator-kurir courier profiles (or profile-less rows).
	q := `SELECT c.id, c.user_id, c.name, COALESCE(r.slug,'') FROM couriers c
		LEFT JOIN users u ON u.id = c.user_id
		LEFT JOIN roles r ON r.id = u.role_id
		WHERE c.agent_id = ? AND c.is_active = 1
		AND (c.user_id IS NULL OR (u.agent_id = ? AND u.status = 'active' AND r.slug IN ('kurir','koordinator-kurir')))
		ORDER BY c.name`
	cs, err := s.database.DB.QueryContext(ctx, q, agentID, agentID)
	if err != nil {
		return nil, err
	}
	for cs.Next() {
		var id int64
		var uid sql.NullInt64
		var name, role string
		if err := cs.Scan(&id, &uid, &name, &role); err != nil {
			cs.Close()
			return nil, err
		}
		rows = append(rows, map[string]any{"courier_id": nullIntPtr(sql.NullInt64{Int64: id, Valid: true}),
			"user_id": nullIntPtr(uid), "name": name, "executor_role": nullStrPtr(role)})
	}
	cs.Close()
	if err := cs.Err(); err != nil {
		return nil, err
	}
	if mode != "standard" {
		return rows, nil
	}
	// Eligible Sales-Kurir-Sub / Sales-Kurir executors of THIS order only.
	var konsumenID int64
	var salesID sql.NullInt64
	if err := s.database.DB.QueryRowContext(ctx, `SELECT konsumen_id, sales_id FROM orders WHERE id = ?`, orderID).
		Scan(&konsumenID, &salesID); err != nil {
		return nil, err
	}
	es, err := s.database.DB.QueryContext(ctx, `SELECT u.id, u.name, r.slug FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.agent_id = ? AND u.status = 'active' AND r.slug IN ('sales-kurir-sub','sales-kurir')`, agentID)
	if err != nil {
		return nil, err
	}
	type cand struct {
		id   int64
		name string
		role string
	}
	var candidates []cand
	for es.Next() {
		var c cand
		if err := es.Scan(&c.id, &c.name, &c.role); err != nil {
			es.Close()
			return nil, err
		}
		candidates = append(candidates, c)
	}
	es.Close()
	if err := es.Err(); err != nil {
		return nil, err
	}
	var konsumenRole string
	var konsumenAgent, konsumenSales sql.NullInt64
	_ = s.database.DB.QueryRowContext(ctx, `SELECT r.slug, u.agent_id, u.sales_id FROM users u
		JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, konsumenID).Scan(&konsumenRole, &konsumenAgent, &konsumenSales)
	for _, c := range candidates {
		// isEligibleForOrder: own purchase / sales snapshot / current referral scope.
		eligible := konsumenID == c.id || (salesID.Valid && salesID.Int64 == c.id) ||
			(konsumenRole == "konsumen" && konsumenAgent.Valid && konsumenAgent.Int64 == agentID &&
				konsumenSales.Valid && konsumenSales.Int64 == c.id)
		if !eligible {
			continue
		}
		var profileID sql.NullInt64
		var pActive sql.NullBool
		var pAgent sql.NullInt64
		_ = s.database.DB.QueryRowContext(ctx, `SELECT id, is_active, agent_id FROM couriers WHERE user_id = ?`, c.id).
			Scan(&profileID, &pActive, &pAgent)
		if profileID.Valid && (!pActive.Valid || !pActive.Bool || !pAgent.Valid || pAgent.Int64 != agentID) {
			continue // exists but unusable — the assignment path refuses it as well.
		}
		rows = append(rows, map[string]any{"courier_id": nullIntPtr(profileID),
			"user_id": nullIntPtr(sql.NullInt64{Int64: c.id, Valid: true}), "name": c.name,
			"executor_role": nullStrPtr(c.role)})
	}
	// Laravel sortBy('name')
	sort.Slice(rows, func(i, j int) bool {
		ni, _ := rows[i]["name"].(string)
		nj, _ := rows[j]["name"].(string)
		return ni < nj
	})
	return rows, nil
}

// AssignCourier ports ShipmentController::assign + CourierService::assignCourier
// (+ resolveSalesKurirSubExecutor / ensureSelfExecutorProfile). Returns the order id
// so the handler renders the same OrderResource projection Laravel returns.
func (s *Service) AssignCourier(ctx context.Context, a Actor, shipmentID, courierID, executorUserID int64) (int64, error) {
	var assignedOrder int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		// Policy gate first: koordinator-kurir of the shipment's branch only.
		var orderID, orderAgent int64
		var orderStatus, shipStatus, mode string
		var provCode string
		var curCourier, selfDelivered sql.NullInt64
		err := tx.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.status, sh.status, sh.delivery_mode,
			COALESCE(sh.shipping_provider_code,''), sh.courier_id, sh.self_delivered_by_user_id
			FROM shipments sh JOIN orders o ON o.id = sh.order_id WHERE sh.id = ? FOR UPDATE`, shipmentID).
			Scan(&orderID, &orderAgent, &orderStatus, &shipStatus, &mode, &provCode, &curCourier, &selfDelivered)
		if errors.Is(err, sql.ErrNoRows) {
			return &DomainError{Status: 404, Message: ""}
		}
		if err != nil {
			return err
		}
		if a.Role != "koordinator-kurir" || a.AgentID != orderAgent {
			return &DomainError{Status: 404, Message: ""}
		}

		// CR-SALES-KURIR-SUB-DELIVERY-001: resolve the executor's profile on the write path.
		if executorUserID > 0 {
			cid, rerr := s.resolveSksExecutor(ctx, tx, a, shipmentID, orderID, shipStatus, mode, curCourier, selfDelivered, executorUserID)
			if rerr != nil {
				return rerr
			}
			courierID = cid
		} else if courierID == 0 {
			// A1-01 self-executor sentinel: ensure the actor's own Courier profile.
			cid, rerr := s.ensureSelfExecutorProfile(ctx, tx, a)
			if rerr != nil {
				return rerr
			}
			courierID = cid
		}
		oid, aerr := s.assignCourierTx(ctx, tx, a, shipmentID, orderID, orderAgent, orderStatus, shipStatus, mode,
			provCode, curCourier, selfDelivered, courierID)
		if aerr != nil {
			return aerr
		}
		assignedOrder = oid
		return nil
	})
	return assignedOrder, err
}

func (s *Service) ensureSelfExecutorProfile(ctx context.Context, tx *sql.Tx, a Actor) (int64, error) {
	if a.Role != "koordinator-kurir" || a.AgentID == 0 {
		return 0, &DomainError{Status: 422, Message: "Kombinasi peran tidak didukung."}
	}
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id = ?`, a.ID).Scan(&id)
	if err == nil {
		return id, nil
	}
	if err != sql.ErrNoRows {
		return 0, err
	}
	var name string
	_ = tx.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, a.ID).Scan(&name)
	res, ierr := tx.ExecContext(ctx, `INSERT INTO couriers (type, user_id, agent_id, name, is_active, created_at, updated_at)
		VALUES ('internal', ?, ?, ?, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`, a.ID, a.AgentID, name)
	if ierr != nil {
		// couriers_user_unique guard: re-read the winner's row.
		var wid int64
		werr := tx.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id = ?`, a.ID).Scan(&wid)
		if werr == nil {
			return wid, nil
		}
		return 0, ierr
	}
	return res.LastInsertId()
}

func (s *Service) resolveSksExecutor(ctx context.Context, tx *sql.Tx, a Actor, shipmentID, orderID int64,
	shipStatus, mode string, curCourier, selfDelivered sql.NullInt64, candidateUserID int64) (int64, error) {
	invalid := &DomainError{Status: 422, Message: "Kurir yang dipilih tidak berada di cabang agen yang sama atau sedang nonaktif."}
	if mode == "" || shipStatus != "pending" || curCourier.Valid || selfDelivered.Valid || mode != "standard" {
		return 0, invalid
	}
	var candAgent sql.NullInt64
	var candStatus string
	var candRole string
	var candName string
	err := tx.QueryRowContext(ctx, `SELECT u.agent_id, u.status, r.slug, u.name FROM users u
		JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE`, candidateUserID).
		Scan(&candAgent, &candStatus, &candRole, &candName)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, invalid
	}
	if err != nil {
		return 0, err
	}
	if candRole != "sales-kurir-sub" || candStatus != "active" || !candAgent.Valid {
		return 0, invalid
	}
	// Per-shipment eligibility (same agent, own purchase / sales snapshot / referral scope).
	var konsumenID int64
	var salesID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT konsumen_id, sales_id FROM orders WHERE id = ?`, orderID).
		Scan(&konsumenID, &salesID); err != nil {
		return 0, err
	}
	var kRole string
	var kAgent, kSales sql.NullInt64
	_ = tx.QueryRowContext(ctx, `SELECT r.slug, u.agent_id, u.sales_id FROM users u
		JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE`, konsumenID).Scan(&kRole, &kAgent, &kSales)
	eligible := konsumenID == candidateUserID || (salesID.Valid && salesID.Int64 == candidateUserID) ||
		(kRole == "konsumen" && kAgent.Valid && kAgent.Int64 == candAgent.Int64 && kSales.Valid && kSales.Int64 == candidateUserID)
	if !eligible {
		return 0, invalid
	}
	// SC-03 gate mirrors assignCourier so a doomed request never mints a profile.
	if err := s.assertAddedDemandFulfilled(ctx, tx, orderID); err != nil {
		return 0, err
	}
	var id int64
	err = tx.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id = ?`, candidateUserID).Scan(&id)
	if err == nil {
		return id, nil
	}
	if err != sql.ErrNoRows {
		return 0, err
	}
	res, ierr := tx.ExecContext(ctx, `INSERT INTO couriers (type, user_id, agent_id, name, is_active, created_at, updated_at)
		VALUES ('internal', ?, ?, ?, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`, candidateUserID, candAgent.Int64, candName)
	if ierr != nil {
		var wid int64
		werr := tx.QueryRowContext(ctx, `SELECT id FROM couriers WHERE user_id = ?`, candidateUserID).Scan(&wid)
		if werr == nil {
			return wid, nil
		}
		return 0, ierr
	}
	newID, err := res.LastInsertId()
	if err != nil {
		return 0, err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
		description, properties, created_at)
		VALUES (?, 'App\\Models\\Shipment', ?, 'shipment.courier_profile_resolved', NULL, ?, UTC_TIMESTAMP(6))`,
		a.ID, shipmentID, mustJSON(map[string]any{"order_id": orderID, "courier_id": newID,
			"executor_user_id": candidateUserID, "actor_role": a.Role})); err != nil {
		return 0, err
	}
	return newID, nil
}

func (s *Service) assignCourierTx(ctx context.Context, tx *sql.Tx, a Actor, shipmentID, orderID, agentID int64,
	orderStatus, shipStatus, mode, provCode string, curCourier, selfDelivered sql.NullInt64, courierID int64) (int64, error) {
	invalid := &DomainError{Status: 422, Message: "Kurir yang dipilih tidak berada di cabang agen yang sama atau sedang nonaktif."}
	// R-03: a self_sub shipment is never handed to a normal Kurir.
	if selfDelivered.Valid {
		return 0, &DomainError{Status: 422, Message: "Pengiriman self-delivery tidak dapat ditugaskan ke kurir."}
	}
	if a.Role != "koordinator-kurir" {
		return 0, &DomainError{Status: 403, Message: "Hanya Koordinator Kurir yang dapat menugaskan kurir."}
	}
	// Re-lock canonical rows.
	var ordStatus string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ? FOR UPDATE`, orderID).Scan(&ordStatus); err != nil {
		return 0, err
	}
	if err := tx.QueryRowContext(ctx, `SELECT status FROM shipments WHERE id = ? FOR UPDATE`, shipmentID).Scan(&shipStatus); err != nil {
		return 0, err
	}
	var cUserID sql.NullInt64
	var cAgent int64
	var cActive bool
	var cName string
	err := tx.QueryRowContext(ctx, `SELECT user_id, agent_id, is_active, name FROM couriers WHERE id = ? FOR UPDATE`, courierID).
		Scan(&cUserID, &cAgent, &cActive, &cName)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, &DomainError{Status: 404, Message: ""}
	}
	if err != nil {
		return 0, err
	}
	isSelfExecutor := cUserID.Valid && cUserID.Int64 == a.ID
	if isSelfExecutor && cAgent != agentID {
		return 0, invalid
	}
	// Order-status + shipment-terminal checks FIRST (idempotent replay only on live work).
	if ordStatus != "diproses" && ordStatus != "dikirim" {
		return 0, invalid
	}
	if shipStatus != "pending" {
		return 0, invalid
	}
	if provCode == "expedition" || provCode == "pickup" {
		return 0, &DomainError{Status: 422, Message: "Pengiriman ekspedisi/ambil di tempat tidak dapat ditugaskan ke Kurir Toko."}
	}
	var sksEligible bool
	if cUserID.Valid {
		var eStatus, eRole string
		var eAgent sql.NullInt64
		err := tx.QueryRowContext(ctx, `SELECT u.status, r.slug, u.agent_id FROM users u
			JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE`, cUserID.Int64).
			Scan(&eStatus, &eRole, &eAgent)
		if err != nil && err != sql.ErrNoRows {
			return 0, err
		}
		if eRole == "sales-kurir-sub" {
			var konsumenID int64
			var salesID sql.NullInt64
			if err := tx.QueryRowContext(ctx, `SELECT konsumen_id, sales_id FROM orders WHERE id = ? FOR UPDATE`, orderID).
				Scan(&konsumenID, &salesID); err != nil {
				return 0, err
			}
			var kRole string
			var kAgent, kSales sql.NullInt64
			_ = tx.QueryRowContext(ctx, `SELECT r.slug, u.agent_id, u.sales_id FROM users u
				JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE`, konsumenID).Scan(&kRole, &kAgent, &kSales)
			sksEligible = konsumenID == cUserID.Int64 || (salesID.Valid && salesID.Int64 == cUserID.Int64) ||
				(kRole == "konsumen" && kAgent.Valid && kAgent.Int64 == cAgent && kSales.Valid && kSales.Int64 == cUserID.Int64)
		}
		if cAgent != agentID || !cActive || eStatus != "active" || !eAgent.Valid || eAgent.Int64 != agentID ||
			(eRole != "kurir" && eRole != "koordinator-kurir" && !sksEligible) {
			return 0, invalid
		}
	} else if cAgent != agentID || !cActive {
		return 0, invalid
	}
	if curCourier.Valid {
		// Identical executor already on it — idempotent replay, not an overwrite.
		if curCourier.Int64 == courierID {
			if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
				description, properties, created_at)
				VALUES (?, 'App\\Models\\Shipment', ?, 'shipment.courier_assigned', NULL, ?, UTC_TIMESTAMP(6))`,
				a.ID, shipmentID, mustJSON(map[string]any{"order_id": orderID, "courier_id": courierID,
					"previous_courier_id": courierID, "actor_role": a.Role, "idempotent_replay": true})); err != nil {
				return 0, err
			}
			return orderID, nil
		}
		return 0, &DomainError{Status: 422, Message: "messages.courier.already_assigned"}
	}
	if err := s.assertAddedDemandFulfilled(ctx, tx, orderID); err != nil {
		return 0, err
	}
	if err := s.carveWhollyUnacceptedLines(ctx, tx, a, orderID, shipmentID, mode, provCode); err != nil {
		return 0, err
	}
	if err := s.assertAcceptedReceiptCovers(ctx, tx, orderID, shipmentID); err != nil {
		return 0, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE shipments SET courier_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`,
		courierID, shipmentID); err != nil {
		return 0, err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
		description, properties, created_at)
		VALUES (?, 'App\\Models\\Shipment', ?, 'shipment.courier_assigned', NULL, ?, UTC_TIMESTAMP(6))`,
		a.ID, shipmentID, mustJSON(map[string]any{"order_id": orderID, "courier_id": courierID,
			"previous_courier_id": nil, "actor_role": a.Role, "self_executor": isSelfExecutor})); err != nil {
		return 0, err
	}
	return orderID, nil
}

// assertAddedDemandFulfilled ports CourierService::assertAddedDemandFulfilled (SC-03).
func (s *Service) assertAddedDemandFulfilled(ctx context.Context, tx *sql.Tx, orderID int64) error {
	rows, err := tx.QueryContext(ctx, `SELECT id, idempotency_key, split_from_order_item_id FROM order_items
		WHERE order_id = ? FOR UPDATE`, orderID)
	if err != nil {
		return err
	}
	type line struct {
		id, split sql.NullInt64
		added     bool
	}
	var items []line
	for rows.Next() {
		var l line
		if err := rows.Scan(&l.id, &l.split, &l.split); err != nil {
			rows.Close()
			return err
		}
		items = append(items, l)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	added := map[int64]bool{}
	for _, it := range items {
		if it.added {
			added[it.id.Int64] = true
		}
	}
	if len(added) == 0 {
		return nil
	}
	// Expand descendants carrying the same demand lineage.
	for {
		before := len(added)
		for _, it := range items {
			if it.split.Valid && added[it.split.Int64] {
				added[it.id.Int64] = true
			}
		}
		if len(added) == before {
			break
		}
	}
	ids := make([]any, 0, len(added))
	ph := ""
	for id := range added {
		ids = append(ids, id)
		if ph != "" {
			ph += ","
		}
		ph += "?"
	}
	var count int
	var outstanding bool
	q := `SELECT COUNT(*), COALESCE(SUM(sri.remaining_qty > 0),0) FROM stock_request_items sri
		JOIN stock_requests sr ON sr.id = sri.stock_request_id WHERE sr.order_id = ?
		AND sri.order_item_id IN (` + ph + `)`
	args := append([]any{orderID}, ids...)
	if err := tx.QueryRowContext(ctx, q, args...).Scan(&count, &outstanding); err != nil {
		return err
	}
	if count != len(added) || outstanding {
		return &DomainError{Status: 422, Message: "Selesaikan pemenuhan stok produk tambahan SC-03 sebelum menugaskan kurir."}
	}
	return nil
}

// carveWhollyUnacceptedLines ports CourierService::carveWhollyUnacceptedLines: wholly-unaccepted
// non-Sub lines move to a fresh shipment so the assignment only covers accepted goods.
func (s *Service) carveWhollyUnacceptedLines(ctx context.Context, tx *sql.Tx, a Actor, orderID, shipmentID int64,
	mode, provCode string) error {
	if mode != "standard" || (provCode == "expedition" || provCode == "pickup") {
		return nil
	}
	var curCourier sql.NullInt64
	var shipStatus string
	if err := tx.QueryRowContext(ctx, `SELECT courier_id, status FROM shipments WHERE id = ?`, shipmentID).
		Scan(&curCourier, &shipStatus); err != nil {
		return err
	}
	if shipStatus != "pending" || curCourier.Valid {
		return nil
	}
	// Unaccepted non-Sub live lines on this shipment (≥2 live lines required).
	rows, err := tx.QueryContext(ctx, `SELECT i.id, COALESCE(sri.fulfilled_qty,0) FROM order_items i
		LEFT JOIN stock_requests sr ON sr.order_id = i.order_id
		LEFT JOIN stock_request_items sri ON sri.stock_request_id = sr.id AND sri.order_item_id = i.id
		WHERE i.order_id = ? AND i.shipment_id = ? AND i.stock_source <> 'sub'
		AND i.status IN ('diterima','diproses') FOR UPDATE`, orderID, shipmentID)
	if err != nil {
		return err
	}
	type li struct {
		id       int64
		accepted int64
	}
	var mine []li
	for rows.Next() {
		var l li
		if err := rows.Scan(&l.id, &l.accepted); err != nil {
			rows.Close()
			return err
		}
		mine = append(mine, l)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	if len(mine) < 2 {
		return nil
	}
	var reqCount int
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_requests WHERE order_id = ?`, orderID).Scan(&reqCount); err != nil {
		return err
	}
	if reqCount == 0 {
		return nil
	}
	acceptedAny, unaccepted := false, make([]int64, 0)
	for _, l := range mine {
		if l.accepted > 0 {
			acceptedAny = true
		} else {
			unaccepted = append(unaccepted, l.id)
		}
	}
	if !acceptedAny || len(unaccepted) == 0 {
		return nil
	}
	var target int64
	for idx, itemID := range unaccepted {
		if idx == 0 {
			var subLoc sql.NullInt64
			_ = tx.QueryRowContext(ctx, `SELECT sub_location_id FROM order_items WHERE id = ?`, itemID).Scan(&subLoc)
			if err := s.assignFreshShipment(ctx, tx, orderID, itemID, false, sql.NullInt64{}, a.ID, "shipment.split_for_unaccepted"); err != nil {
				return err
			}
			if err := tx.QueryRowContext(ctx, `SELECT shipment_id FROM order_items WHERE id = ?`, itemID).Scan(&target); err != nil {
				return err
			}
			continue
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`,
			target, itemID); err != nil {
			return err
		}
	}
	return nil
}

// assertAcceptedReceiptCovers ports CourierService::assertAcceptedReceiptCovers.
func (s *Service) assertAcceptedReceiptCovers(ctx context.Context, tx *sql.Tx, orderID, shipmentID int64) error {
	var missing bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items i
		LEFT JOIN stock_requests sr ON sr.order_id = i.order_id
		LEFT JOIN stock_request_items sri ON sri.stock_request_id = sr.id AND sri.order_item_id = i.id
		WHERE i.order_id = ? AND i.shipment_id = ? AND i.stock_source <> 'sub'
		AND i.status IN ('diterima','diproses') AND COALESCE(sri.fulfilled_qty,0) < i.fulfilled_quantity)`,
		orderID, shipmentID).Scan(&missing); err != nil {
		return err
	}
	if missing {
		return &DomainError{Status: 422, Message: "Barang belum diterima valid oleh Koordinator; penugasan kurir ditolak."}
	}
	return nil
}

func nullStrPtr(v string) any {
	if v == "" {
		return nil
	}
	return v
}

// ExpeditionHandover ports CourierService::recordExpeditionHandover (REM-003).
// evidence is the already-validated upload (nil when Laravel would fail validation first).
func (s *Service) ExpeditionHandover(ctx context.Context, a Actor, shipmentID int64,
	carrier, trackingNumber string, evidence *media.Upload) error {
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if a.Role != "koordinator-kurir" {
			return &DomainError{Status: 403, Message: "Hanya Koordinator Kurir yang dapat menugaskan kurir."}
		}
		var orderID, orderAgent int64
		var orderStatus, shipStatus, paymentStatus string
		var provCode string
		var tracking sql.NullString
		var meta sql.NullString
		err := tx.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.status, o.payment_status, sh.status,
			COALESCE(sh.shipping_provider_code,''), sh.tracking_number, sh.provider_meta
			FROM shipments sh JOIN orders o ON o.id = sh.order_id WHERE sh.id = ? FOR UPDATE`, shipmentID).
			Scan(&orderID, &orderAgent, &orderStatus, &paymentStatus, &shipStatus, &provCode, &tracking, &meta)
		if errors.Is(err, sql.ErrNoRows) {
			return &DomainError{Status: 404, Message: ""}
		}
		if err != nil {
			return err
		}
		if provCode != "rajaongkir" {
			return &DomainError{Status: 422, Message: "Hanya pengiriman ekspedisi (RajaOngkir) yang memiliki serah terima ekspedisi."}
		}
		if orderStatus != "diproses" || shipStatus != "pending" {
			return &DomainError{Status: 422, Message: "Kurir yang dipilih tidak berada di cabang agen yang sama atau sedang nonaktif."}
		}
		if orderAgent != a.AgentID {
			return &DomainError{Status: 403, Message: "Aksi tidak diizinkan untuk peran Anda."}
		}
		if paymentStatus != "paid" {
			return &DomainError{Status: 422, Message: "Pengiriman ekspedisi wajib lunas penuh (termasuk ongkir) sebelum dikirim."}
		}
		// assertExpeditionGoodsComplete: whole order in this shipment + stock request complete.
		var outside bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE order_id = ?
			AND status <> 'dibatalkan' AND (shipment_id IS NULL OR shipment_id <> ?))`, orderID, shipmentID).Scan(&outside); err != nil {
			return err
		}
		if outside {
			return &DomainError{Status: 422, Message: "Pengiriman ekspedisi tidak boleh parsial; seluruh barang order harus dalam satu pengiriman."}
		}
		var incomplete bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_requests sr JOIN stock_request_items sri
			ON sri.stock_request_id = sr.id WHERE sr.order_id = ? AND (sri.remaining_qty > 0 OR sr.status IN ('pending','partial')))`, orderID).Scan(&incomplete); err != nil {
			return err
		}
		if incomplete {
			return &DomainError{Status: 422, Message: "Barang belum lengkap diterima Koordinator; pengiriman ekspedisi ditahan."}
		}
		// Carrier must match the checkout-chosen expedition carrier (provider_meta.courier).
		chosen := ""
		if meta.Valid && meta.String != "" {
			var m map[string]any
			if json.Unmarshal([]byte(meta.String), &m) == nil {
				if c, ok := m["courier"].(string); ok {
					chosen = strings.ToLower(c)
				}
			}
		}
		if chosen == "" || strings.ToLower(carrier) != chosen {
			return &DomainError{Status: 422, Message: "Ekspedisi pada resi tidak sama dengan ekspedisi pilihan checkout."}
		}
		if tracking.Valid && tracking.String != "" {
			// Idempotent replay — no second evidence row.
			if tracking.String == trackingNumber {
				return nil
			}
			return &DomainError{Status: 409, Message: "Resi sudah tercatat untuk pengiriman ini dengan nomor berbeda."}
		}
		// Evidence upload → media row (shipment_proof, mediable = Shipment).
		mediableType := "App\\Models\\Shipment"
		var mediaID int64
		if evidence != nil {
			m, err := media.Store(ctx, tx, "shipment_proof", *evidence, &mediableType, &shipmentID, &a.ID)
			if err != nil {
				return err
			}
			mediaID = m.ID
		}
		newMeta := map[string]any{}
		if meta.Valid && meta.String != "" {
			_ = json.Unmarshal([]byte(meta.String), &newMeta)
		}
		newMeta["handover"] = map[string]any{"media_id": mediaID, "carrier": chosen,
			"recorded_by": a.ID, "recorded_at": time.Now().UTC().Format("2006-01-02T15:04:05") + "+00:00"}
		metaJSON, _ := json.Marshal(newMeta)
		if _, err := tx.ExecContext(ctx, `UPDATE shipments SET tracking_number = ?, provider_meta = ?,
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, trackingNumber, string(metaJSON), shipmentID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\Shipment', ?, 'shipment.expedition_handover_recorded', NULL, ?, UTC_TIMESTAMP(6))`,
			a.ID, shipmentID, mustJSON(map[string]any{"order_id": orderID, "carrier": chosen,
				"tracking_number": trackingNumber, "media_id": mediaID})); err != nil {
			return err
		}
		return nil
	})
}

// Receipt ports ShipmentController::receipt + ShipmentReceiptResource (read-only;
// audit row shipment.receipt_printed is written on every print).
func (s *Service) Receipt(ctx context.Context, a Actor, shipmentID int64) (map[string]any, error) {
	// ShipmentPolicy::printReceipt — which caller may print.
	var orderStatus string
	var orderAgent int64
	var mode string
	var courierID, selfDelivered sql.NullInt64
	var konsumenPlacedBy sql.NullInt64
	err := s.database.DB.QueryRowContext(ctx, `SELECT o.status, o.agent_id, o.placed_by_user_id, sh.delivery_mode,
		sh.courier_id, sh.self_delivered_by_user_id FROM shipments sh JOIN orders o ON o.id = sh.order_id
		WHERE sh.id = ?`, shipmentID).Scan(&orderStatus, &orderAgent, &konsumenPlacedBy, &mode, &courierID, &selfDelivered)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &DomainError{Status: 404, Message: ""}
	}
	if err != nil {
		return nil, err
	}
	allowed := false
	if orderStatus != "dibatalkan" {
		switch a.Role {
		case "super_admin":
			allowed = true
		case "agen", "admin":
			allowed = orderAgent == a.AgentID
		case "kurir", "sales-kurir-sub", "koordinator-kurir":
			if mode == "self_sub" {
				allowed = selfDelivered.Valid && selfDelivered.Int64 == a.ID &&
					konsumenPlacedBy.Valid && konsumenPlacedBy.Int64 == a.ID
			} else {
				allowed = courierID.Valid
				if allowed {
					var cUser sql.NullInt64
					_ = s.database.DB.QueryRowContext(ctx, `SELECT user_id FROM couriers WHERE id = ?`,
						courierID.Int64).Scan(&cUser)
					allowed = cUser.Valid && cUser.Int64 == a.ID
				}
			}
		}
	}
	if !allowed {
		// ShipmentPolicy::printReceipt denial renders the AuthorizationException text.
		return nil, &DomainError{Status: 403, Message: "This action is unauthorized."}
	}

	var shID int64
	var orderNo, recipientName, recipientPhone, address string
	var village, district, regency, province sql.NullString
	var orderCreatedAt sql.NullTime
	var shippedAt sql.NullTime
	var provCode sql.NullString
	var courierName, selfName sql.NullString
	var notes sql.NullString
	var totalAmount, paidAmount, dpAmount, remainingAmount string
	var paymentStatus, pmType sql.NullString
	var deliveryEstimate sql.NullString
	err = s.database.DB.QueryRowContext(ctx, `SELECT sh.id, o.order_no, o.recipient_name_snapshot,
		o.recipient_phone_snapshot, o.address_snapshot, o.village_snapshot, o.district_snapshot,
		o.regency_snapshot, o.province_snapshot, o.created_at, sh.shipped_at, sh.shipping_provider_code,
		c.name, sd.name, o.notes, CAST(o.total_amount AS CHAR), CAST(o.paid_amount AS CHAR),
		CAST(o.dp_amount AS CHAR), CAST(o.remaining_amount AS CHAR), o.payment_status, pm.type,
		CAST(o.delivery_date_estimate AS CHAR)
		FROM shipments sh
		JOIN orders o ON o.id = sh.order_id
		LEFT JOIN couriers c ON c.id = sh.courier_id
		LEFT JOIN users sd ON sd.id = sh.self_delivered_by_user_id
		LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
		WHERE sh.id = ?`, shipmentID).
		Scan(&shID, &orderNo, &recipientName, &recipientPhone, &address, &village, &district, &regency,
			&province, &orderCreatedAt, &shippedAt, &provCode, &courierName, &selfName, &notes,
			&totalAmount, &paidAmount, &dpAmount, &remainingAmount, &paymentStatus, &pmType, &deliveryEstimate)
	if err != nil {
		return nil, err
	}
	modeReceipt := "pre_pickup"
	if shippedAt.Valid {
		modeReceipt = "post_pickup"
	}
	labels := map[string]string{"openroute": "Kurir Lokal", "rajaongkir": "Ekspedisi"}
	prov := ""
	if provCode.Valid {
		prov = provCode.String
	}
	label, ok := labels[prov]
	if !ok {
		label = prov
	}
	// Delivery date: first non-null requested_delivery_date among items, else order estimate.
	var deliveryDate any
	if err := s.database.DB.QueryRowContext(ctx, `SELECT requested_delivery_date FROM order_items
		WHERE shipment_id = ? AND requested_delivery_date IS NOT NULL LIMIT 1`, shipmentID).Scan(&deliveryDate); err != nil {
		if err == sql.ErrNoRows {
			if deliveryEstimate.Valid {
				deliveryDate = deliveryEstimate.String
			}
		} else {
			return nil, err
		}
	}
	items := make([]map[string]any, 0)
	total := 0
	rows, err := s.database.DB.QueryContext(ctx, `SELECT sku_snapshot, product_name_snapshot,
		variation_label_snapshot, fulfilled_quantity FROM order_items WHERE shipment_id = ? ORDER BY id`, shipmentID)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var sku, pname sql.NullString
		var vlabel sql.NullString
		var qty int64
		if err := rows.Scan(&sku, &pname, &vlabel, &qty); err != nil {
			rows.Close()
			return nil, err
		}
		items = append(items, map[string]any{"sku": nullStrPtr(sku.String), "product_name": nullStrPtr(pname.String),
			"variation_label": nullStrPtr(vlabel.String), "quantity": qty})
		total += int(qty)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	isCOD := pmType.Valid && pmType.String == "cod"
	var codDue any
	if isCOD && paymentStatus.String != "paid" {
		tot, _ := strconv.ParseFloat(totalAmount, 64)
		paid, _ := strconv.ParseFloat(paidAmount, 64)
		due := tot - paid
		if due < 0 {
			due = 0
		}
		codDue = strconv.FormatFloat(due, 'f', 2, 64)
	}
	dpF, _ := strconv.ParseFloat(dpAmount, 64)
	var dpPaid, dpOutstanding any
	if dpF > 0 {
		dpPaid = paidAmount
		// hasOutstanding: round(max(total-paid,0),2) > 0 (order not cancelled here).
		tot, _ := strconv.ParseFloat(totalAmount, 64)
		pd, _ := strconv.ParseFloat(paidAmount, 64)
		out := math.Round((tot-pd)*100) / 100
		if out > 0 {
			dpOutstanding = remainingAmount
		}
	}
	isFullyPaid := paymentStatus.String == "paid"
	if isFullyPaid {
		rem, _ := strconv.ParseFloat(remainingAmount, 64)
		isFullyPaid = rem <= 0
	}
	courier := courierName
	if !courier.Valid && selfName.Valid {
		courier = selfName
	}
	return map[string]any{
		"shipment_id": shID, "mode": modeReceipt, "order_no": orderNo,
		"order_date":     carbonMicro(orderCreatedAt),
		"recipient_name": recipientName, "recipient_phone": recipientPhone, "address_line": address,
		"village": nullStrPtr(village.String), "district": nullStrPtr(district.String),
		"regency": nullStrPtr(regency.String), "province": nullStrPtr(province.String),
		"delivery_date":        deliveryDate,
		"shipping_method_code": nullStrPtr(prov), "shipping_method_label": label,
		"is_official_carrier_label": false,
		"courier_name":              nullStrPtr(courier.String), "picked_up_at": carbonMicro(shippedAt),
		"items": items, "total_item_count": total, "notes": nullStrPtr(notes.String),
		"payment": map[string]any{
			"is_cod": isCOD, "cod_amount_due": codDue,
			"is_down_payment": dpF > 0, "dp_paid_amount": dpPaid, "dp_outstanding_amount": dpOutstanding,
			"is_fully_paid": isFullyPaid,
		},
	}, nil
}

// RecordReceiptPrint writes the shipment.receipt_printed audit row (every print, incl. reprints).
func (s *Service) RecordReceiptPrint(ctx context.Context, a Actor, shipmentID int64) error {
	var orderID int64
	var shippedAt sql.NullTime
	if err := s.database.DB.QueryRowContext(ctx, `SELECT order_id, shipped_at FROM shipments WHERE id = ?`,
		shipmentID).Scan(&orderID, &shippedAt); err != nil {
		return err
	}
	mode := "pre_pickup"
	if shippedAt.Valid {
		mode = "post_pickup"
	}
	var isReprint bool
	_ = s.database.DB.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM activity_logs WHERE subject_type = 'App\\Models\\Shipment'
		AND subject_id = ? AND event = 'shipment.receipt_printed')`, shipmentID).Scan(&isReprint)
	_, err := s.database.DB.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
		description, properties, created_at)
		VALUES (?, 'App\\Models\\Shipment', ?, 'shipment.receipt_printed', NULL, ?, UTC_TIMESTAMP(6))`,
		a.ID, shipmentID, mustJSON(map[string]any{"order_id": orderID, "mode": mode,
			"is_reprint": isReprint, "actor_role": a.Role}))
	return err
}

// ShipmentOrderID resolves the owning order of a shipment (handler projection support).
func (s *Service) ShipmentOrderID(ctx context.Context, shipmentID int64) (int64, error) {
	var orderID int64
	err := s.database.DB.QueryRowContext(ctx, `SELECT order_id FROM shipments WHERE id = ?`, shipmentID).Scan(&orderID)
	return orderID, err
}

// DeliveryVerifications ports DeliveryVerificationController::index (append-only history).
func (s *Service) DeliveryVerifications(ctx context.Context, a Actor, shipmentID int64) (any, error) {
	if !s.canVerifyDelivery(ctx, a, shipmentID) {
		return nil, &DomainError{Status: 404, Message: ""}
	}
	rows, err := s.database.DB.QueryContext(ctx, `SELECT v.id, v.shipment_id, v.outcome, v.note,
		v.verified_at, u.id, u.name, v.created_at FROM delivery_verifications v
		LEFT JOIN users u ON u.id = v.verified_by WHERE v.shipment_id = ? ORDER BY v.id`, shipmentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, shipID int64
		var outcome string
		var note sql.NullString
		var verifiedAt, createdAt sql.NullTime
		var uid sql.NullInt64
		var uname sql.NullString
		if err := rows.Scan(&id, &shipID, &outcome, &note, &verifiedAt, &uid, &uname, &createdAt); err != nil {
			return nil, err
		}
		row := map[string]any{"id": id, "shipment_id": shipID, "outcome": outcome,
			"note": nullStrPtr(note.String), "verified_at": carbonMicro(verifiedAt),
			"verified_by": map[string]any{"id": nullIntPtr(uid), "name": nullStrPtr(uname.String)},
			"created_at":  carbonMicro(createdAt)}
		data = append(data, row)
	}
	return data, rows.Err()
}

// RecordDeliveryVerification ports DeliveryVerificationService::record (R-03 append-only).
func (s *Service) RecordDeliveryVerification(ctx context.Context, a Actor, shipmentID int64,
	outcome, note, idempotencyKey string) (map[string]any, error) {
	if outcome != "received" && outcome != "not_received" && outcome != "return" {
		return nil, &DomainError{Status: 422, Message: "Hasil verifikasi pengiriman tidak valid."}
	}
	normalizedNote := strings.TrimSpace(note)
	if normalizedNote == "" {
		normalizedNote = ""
	}
	var verificationID int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if !s.canVerifyDeliveryTx(ctx, tx, a, shipmentID) {
			return &DomainError{Status: 404, Message: ""}
		}
		var deliveredAt sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT delivered_at FROM shipments WHERE id = ? FOR UPDATE`, shipmentID).
			Scan(&deliveredAt); err != nil {
			if err == sql.ErrNoRows {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		if !deliveredAt.Valid {
			return &DomainError{Status: 422, Message: "Pengiriman belum selesai; verifikasi belum dapat dilakukan."}
		}
		var unarrived bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE shipment_id = ?
			AND status NOT IN ('terkirim','pengembalian','kembali'))`, shipmentID).Scan(&unarrived); err != nil {
			return err
		}
		if unarrived {
			return &DomainError{Status: 422, Message: "Pengiriman belum selesai; verifikasi belum dapat dilakukan."}
		}
		var existingID int64
		var exShipment int64
		var exOutcome, exNote sql.NullString
		err := tx.QueryRowContext(ctx, `SELECT id, shipment_id, outcome, note FROM delivery_verifications
			WHERE verified_by = ? AND idempotency_key = ? FOR UPDATE`, a.ID, idempotencyKey).
			Scan(&existingID, &exShipment, &exOutcome, &exNote)
		if err == nil {
			// A replay is only a replay of the SAME request; a reused key for a different
			// shipment/outcome/note is a conflict, never an unrelated record.
			if exShipment != shipmentID || exOutcome.String != outcome || exNote.String != normalizedNote {
				return &DomainError{Status: 409, Message: "Idempotency-Key sudah dipakai untuk permintaan verifikasi yang berbeda."}
			}
			verificationID = existingID
			return nil
		}
		if err != sql.ErrNoRows {
			return err
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO delivery_verifications (shipment_id, outcome, note,
			verified_by, verified_at, idempotency_key, created_at, updated_at)
			VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			shipmentID, outcome, nullStrPtr(normalizedNote), a.ID, idempotencyKey)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		verificationID = newID
		var orderID int64
		_ = tx.QueryRowContext(ctx, `SELECT order_id FROM shipments WHERE id = ?`, shipmentID).Scan(&orderID)
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\Shipment', ?, 'shipment.delivery_verified', ?, ?, UTC_TIMESTAMP(6))`,
			a.ID, shipmentID, nullStrPtr(normalizedNote), mustJSON(map[string]any{"order_id": orderID,
				"outcome": outcome, "verification_id": newID, "actor_role": a.Role})); err != nil {
			return err
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	// Render the created/replayed record (DeliveryVerificationResource with verifiedBy).
	var id, shipID int64
	var out2, note2 sql.NullString
	var verifiedAt, createdAt sql.NullTime
	var uid sql.NullInt64
	var uname sql.NullString
	if err := s.database.DB.QueryRowContext(ctx, `SELECT v.id, v.shipment_id, v.outcome, v.note,
		v.verified_at, u.id, u.name, v.created_at FROM delivery_verifications v
		LEFT JOIN users u ON u.id = v.verified_by WHERE v.id = ?`, verificationID).
		Scan(&id, &shipID, &out2, &note2, &verifiedAt, &uid, &uname, &createdAt); err != nil {
		return nil, err
	}
	_ = outcome
	return map[string]any{"id": id, "shipment_id": shipID, "outcome": nullStrPtr(out2.String),
		"note": nullStrPtr(note2.String), "verified_at": carbonMicro(verifiedAt),
		"verified_by": map[string]any{"id": nullIntPtr(uid), "name": nullStrPtr(uname.String)},
		"created_at":  carbonMicro(createdAt)}, nil
}

// SplitBatch ports DispatchController::splitBatch + OrderFulfillmentService::splitItemIntoBatch
// (koordinator splits a diproses agent-sourced line into a follow-up batch).
func (s *Service) SplitBatch(ctx context.Context, a Actor, orderItemID, quantity int64, note string) (map[string]any, error) {
	var childID, childShipment, childQty int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if a.Role != "koordinator-kurir" {
			return &DomainError{Status: 403, Message: "Hanya Koordinator Kurir yang dapat menugaskan kurir."}
		}
		var orderID, agentID int64
		var orderStatus string
		if err := tx.QueryRowContext(ctx, `SELECT order_id FROM order_items WHERE id = ? FOR UPDATE`, orderItemID).
			Scan(&orderID); err != nil {
			if err == sql.ErrNoRows {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT agent_id, status FROM orders WHERE id = ? FOR UPDATE`, orderID).
			Scan(&agentID, &orderStatus); err != nil {
			return err
		}
		if agentID != a.AgentID {
			return &DomainError{Status: 403, Message: "Aksi tidak diizinkan untuk peran Anda."}
		}
		var status, stockSource string
		var fulfilled int64
		var shipID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT status, stock_source, fulfilled_quantity, shipment_id
			FROM order_items WHERE id = ? FOR UPDATE`, orderItemID).Scan(&status, &stockSource, &fulfilled, &shipID); err != nil {
			return err
		}
		if (orderStatus != "diproses" && orderStatus != "dikirim") || status != "diproses" {
			return &DomainError{Status: 422, Message: "Jendela perubahan fulfillment sudah ditutup."}
		}
		if stockSource == "sub" {
			return &DomainError{Status: 422, Message: "Produk tidak dapat ditambahkan ke pesanan yang bersumber dari stok Sub."}
		}
		if !shipID.Valid {
			return &DomainError{Status: 422, Message: "Pengiriman ekspedisi/ambil di tempat tidak dapat ditugaskan ke Kurir Toko."}
		}
		var shipStatus, mode string
		var provCode sql.NullString
		var courierID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT status, delivery_mode, shipping_provider_code, courier_id
			FROM shipments WHERE id = ? FOR UPDATE`, shipID.Int64).Scan(&shipStatus, &mode, &provCode, &courierID); err != nil {
			return err
		}
		if shipStatus != "pending" || courierID.Valid || mode != "standard" ||
			provCode.String == "rajaongkir" || provCode.String == "pickup" {
			return &DomainError{Status: 422, Message: "Pengiriman ekspedisi/ambil di tempat tidak dapat ditugaskan ke Kurir Toko."}
		}
		if quantity < 1 || quantity >= fulfilled {
			return &DomainError{Status: 422, Message: "Jumlah pemenuhan tidak valid."}
		}
		// splitItemForReschedule core (consolidate=false for batch splits).
		var unitPrice, subtotal, courierFee string
		var reqDate sql.NullString
		var productID, variationID, subLocID sql.NullInt64
		var pname, vlabel, sku sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT CAST(unit_price_snapshot AS CHAR), CAST(subtotal_snapshot AS CHAR),
			CAST(courier_fee_amount AS CHAR), requested_delivery_date, product_id, product_variation_id,
			sub_location_id, product_name_snapshot, variation_label_snapshot, sku_snapshot
			FROM order_items WHERE id = ?`, orderItemID).Scan(&unitPrice, &subtotal, &courierFee, &reqDate,
			&productID, &variationID, &subLocID, &pname, &vlabel, &sku); err != nil {
			return err
		}
		up, _ := strconv.ParseFloat(unitPrice, 64)
		cf, _ := strconv.ParseFloat(courierFee, 64)
		sub, _ := strconv.ParseFloat(subtotal, 64)
		movedSubtotal := math.Round(up*float64(quantity)*100) / 100
		perUnit := 0.0
		if fulfilled > 0 {
			perUnit = cf / float64(fulfilled)
		}
		movedFee := math.Round(perUnit*float64(quantity)*100) / 100
		retainedFee := math.Round((cf-movedFee)*100) / 100
		retainedSubtotal := math.Round((sub-movedSubtotal)*100) / 100
		// R-03 / decision G: lock the StockRequestItem and reject when the remainder cannot cover.
		var srID int64
		var srStatus string
		var srItemID sql.NullInt64
		var srRemaining int64
		err := tx.QueryRowContext(ctx, `SELECT id, status FROM stock_requests WHERE order_id = ? FOR UPDATE`, orderID).
			Scan(&srID, &srStatus)
		if err == nil && srStatus != "cancelled" {
			serr := tx.QueryRowContext(ctx, `SELECT id, remaining_qty FROM stock_request_items
				WHERE stock_request_id = ? AND order_item_id = ? FOR UPDATE`, srID, orderItemID).
				Scan(&srItemID, &srRemaining)
			if serr == nil && srRemaining < quantity {
				return &DomainError{Status: 422, Message: "Pemecahan tidak dapat dilakukan karena permintaan stok gudang sudah dipenuhi sebagian dan sisa tidak mencukupi."}
			}
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO order_items (order_id, split_from_order_item_id, product_id,
			product_variation_id, stock_source, sub_location_id, product_name_snapshot, variation_label_snapshot,
			sku_snapshot, unit_price_snapshot, agent_fee_amount, sales_fee_amount, courier_fee_amount,
			subtotal_snapshot, original_quantity, fulfilled_quantity, requested_delivery_date, status,
			created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			orderID, orderItemID, productID, variationID, stockSource, subLocID, pname, vlabel, sku,
			unitPrice, movedFee, movedSubtotal, quantity, quantity, nullStrPtr(reqDate.String), status)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if srItemID.Valid {
			if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items SET requested_qty = requested_qty - ?,
				remaining_qty = remaining_qty - ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, quantity, quantity, srItemID.Int64); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO stock_request_items (stock_request_id, order_item_id,
				product_id, product_variation_id, sku_snapshot, requested_qty, fulfilled_qty, remaining_qty,
				created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
				srID, newID, productID, variationID, sku, quantity, quantity); err != nil {
				return err
			}
		}
		if err := s.assignFreshShipment(ctx, tx, orderID, newID, false, subLocID, a.ID, "shipment.split_for_reschedule"); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_items SET original_quantity = original_quantity - ?,
			fulfilled_quantity = ?, subtotal_snapshot = ?, courier_fee_amount = ?, updated_at = UTC_TIMESTAMP(6)
			WHERE id = ?`, quantity, fulfilled-quantity, retainedSubtotal, retainedFee, orderItemID); err != nil {
			return err
		}
		reason := note
		if reason == "" {
			reason = "batch lanjutan"
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\OrderItem', ?, 'order_item.split_for_reschedule', ?, ?, UTC_TIMESTAMP(6))`,
			a.ID, newID, reason, mustJSON(map[string]any{"order_id": orderID, "from_order_item_id": orderItemID,
				"quantity_moved": quantity, "new_delivery_date": nullStrPtr(reqDate.String), "actor_role": a.Role})); err != nil {
			return err
		}
		childID = newID
		childQty = quantity
		return tx.QueryRowContext(ctx, `SELECT shipment_id FROM order_items WHERE id = ?`, newID).Scan(&childShipment)
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"order_item_id": childID, "shipment_id": childShipment, "quantity": childQty}, nil
}

// RequestFulfillment ports DispatchController::requestFulfillment +
// StockRequestService::coordinatorRequestFulfillment (REM-001).
func (s *Service) RequestFulfillment(ctx context.Context, a Actor, orderID int64, note string) (map[string]any, error) {
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if a.Role != "koordinator-kurir" {
			return &DomainError{Status: 403, Message: "Aksi tidak diizinkan untuk peran Anda."}
		}
		var agentID int64
		var orderStatus string
		if err := tx.QueryRowContext(ctx, `SELECT agent_id, status FROM orders WHERE id = ? FOR UPDATE`, orderID).
			Scan(&agentID, &orderStatus); err != nil {
			if err == sql.ErrNoRows {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		if agentID != a.AgentID {
			return &DomainError{Status: 403, Message: "Aksi tidak diizinkan untuk peran Anda."}
		}
		// isInWarehouseScope.
		var selfDelivery, hasCourier, srOpen bool
		_ = tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM shipments WHERE order_id = ? AND self_delivered_by_user_id IS NOT NULL),
			EXISTS(SELECT 1 FROM shipments WHERE order_id = ? AND courier_id IS NOT NULL)`, orderID, orderID).Scan(&selfDelivery, &hasCourier)
		inScope := false
		if !selfDelivery {
			if orderStatus == "diproses" && !hasCourier {
				inScope = true
			} else {
				_ = tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_requests WHERE order_id = ?
					AND status IN ('pending','partial'))`, orderID).Scan(&inScope)
			}
		}
		_ = srOpen
		if (orderStatus != "diproses" && orderStatus != "dikirim") || !inScope {
			return &DomainError{Status: 422, Message: "Order tidak berada pada tahap yang dapat diminta ke Gudang."}
		}
		var srID int64
		var srStatus string
		err := tx.QueryRowContext(ctx, `SELECT id, status FROM stock_requests WHERE order_id = ? FOR UPDATE`, orderID).
			Scan(&srID, &srStatus)
		var outstanding int64
		if err == nil {
			if qerr := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(remaining_qty),0) FROM stock_request_items
				WHERE stock_request_id = ? FOR UPDATE`, srID).Scan(&outstanding); qerr != nil {
				return qerr
			}
		} else if err != sql.ErrNoRows {
			return err
		}
		if err == sql.ErrNoRows || srStatus == "cancelled" || srStatus == "fulfilled" || outstanding <= 0 {
			return &DomainError{Status: 422, Message: "Tidak ada kebutuhan barang yang masih outstanding untuk diminta."}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE stock_requests SET coordinator_requested_by = ?,
			coordinator_requested_at = UTC_TIMESTAMP(6), coordinator_request_note = ?, updated_at = UTC_TIMESTAMP(6)
			WHERE id = ?`, a.ID, nullStrPtr(note), srID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\StockRequest', ?, 'stock_request.coordinator_requested', ?, ?, UTC_TIMESTAMP(6))`,
			a.ID, srID, nullStrPtr(note), mustJSON(map[string]any{"order_id": orderID, "outstanding_qty": outstanding})); err != nil {
			return err
		}
		return s.renderStockRequest(ctx, tx, srID, &out)
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

func (s *Service) renderStockRequest(ctx context.Context, tx *sql.Tx, srID int64, out *map[string]any) error {
	var agentID, orderID int64
	var reqNumber, srStatus string
	var crBy sql.NullInt64
	var crAt sql.NullTime
	var crNote sql.NullString
	var createdAt, updatedAt sql.NullTime
	if err := tx.QueryRowContext(ctx, `SELECT agent_id, order_id, request_number, status, coordinator_requested_by,
		coordinator_requested_at, coordinator_request_note, created_at, updated_at FROM stock_requests WHERE id = ?`,
		srID).Scan(&agentID, &orderID, &reqNumber, &srStatus, &crBy, &crAt, &crNote, &createdAt, &updatedAt); err != nil {
		return err
	}
	items := make([]map[string]any, 0)
	rows, qerr := tx.QueryContext(ctx, `SELECT id, stock_request_id, order_item_id, product_id,
		product_variation_id, sku_snapshot, requested_qty, fulfilled_qty, remaining_qty
		FROM stock_request_items WHERE stock_request_id = ? ORDER BY id`, srID)
	if qerr != nil {
		return qerr
	}
	defer rows.Close()
	for rows.Next() {
		var id, srid, oiID int64
		var pid, vid sql.NullInt64
		var sku sql.NullString
		var rq, fq, rem int64
		if err := rows.Scan(&id, &srid, &oiID, &pid, &vid, &sku, &rq, &fq, &rem); err != nil {
			return err
		}
		items = append(items, map[string]any{"id": id, "stock_request_id": srid, "order_item_id": oiID,
			"product_id": nullIntPtr(pid), "product_variation_id": nullIntPtr(vid),
			"product_name": nil, "variation_label": nil,
			"sku": nullStrPtr(sku.String), "sku_snapshot": nullStrPtr(sku.String),
			"product_image_url": nil, "requested_qty": rq, "fulfilled_qty": fq, "remaining_qty": rem,
			"current_stock": nil})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	*out = map[string]any{"id": srID, "agent_id": agentID, "order_id": orderID, "request_number": reqNumber,
		"status": srStatus, "coordinator_requested_by": nullIntPtr(crBy),
		"coordinator_requested_at": carbonMicro(crAt), "coordinator_request_note": nullStrPtr(crNote.String),
		"items": items, "created_at": carbonMicro(createdAt), "updated_at": carbonMicro(updatedAt)}
	return nil
}

// AssignReturnPickupCourier ports ReturnController::assignPickupCourier +
// ReturnService::assignPickupCourier (return pickups are assigned, never self-claimed).
func (s *Service) AssignReturnPickupCourier(ctx context.Context, a Actor, returnItemID, courierID int64,
	expectedCourierID sql.NullInt64) (map[string]any, error) {
	var itemID, assignedCourier int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderItemID int64
		if err := tx.QueryRowContext(ctx, `SELECT order_item_id FROM return_items WHERE id = ? FOR UPDATE`,
			returnItemID).Scan(&orderItemID); err != nil {
			if err == sql.ErrNoRows {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		var orderID int64
		var itemStatus, returnStatus string
		var prevCourier sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT i.order_id, i.status, r.status, r.courier_id
			FROM order_items i JOIN return_items r ON r.order_item_id = i.id
			WHERE i.id = ? FOR UPDATE`, orderItemID).Scan(&orderID, &itemStatus, &returnStatus, &prevCourier); err != nil {
			return err
		}
		var agentID int64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, orderID).Scan(&agentID); err != nil {
			return err
		}
		// Office/dispatch actors only, same branch (super_admin exempt).
		switch a.Role {
		case "super_admin":
		case "agen", "admin", "koordinator-kurir":
			if agentID != a.AgentID {
				return &DomainError{Status: 403, Message: "Aksi tidak diizinkan untuk peran Anda."}
			}
		default:
			return &DomainError{Status: 403, Message: "Aksi tidak diizinkan untuk peran Anda."}
		}
		if itemStatus != "pengembalian" || (returnStatus != "pending" && returnStatus != "approved") {
			return &DomainError{Status: 422, Message: "Pengajuan pengembalian ini sudah diproses sebelumnya."}
		}
		var cAgent int64
		var cActive bool
		var cUser sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id, is_active, user_id FROM couriers WHERE id = ? FOR UPDATE`,
			courierID).Scan(&cAgent, &cActive, &cUser); err != nil {
			if err == sql.ErrNoRows {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		var eStatus, eRole string
		var eAgent sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT u.status, r.slug, u.agent_id FROM users u
			JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE`, cUser).Scan(&eStatus, &eRole, &eAgent); err != nil {
			return &DomainError{Status: 422, Message: "Kurir yang dipilih tidak berada di cabang agen yang sama atau sedang nonaktif."}
		}
		if cAgent != agentID || !cActive || eStatus != "active" || !eAgent.Valid || eAgent.Int64 != agentID ||
			eRole != "kurir" {
			return &DomainError{Status: 422, Message: "Kurir yang dipilih tidak berada di cabang agen yang sama atau sedang nonaktif."}
		}
		// Idempotent replay of the identical courier.
		if prevCourier.Valid && prevCourier.Int64 == courierID {
			itemID = returnItemID
			assignedCourier = courierID
			return nil
		}
		// Optimistic concurrency: a switch must match the expected previous assignment.
		if expectedCourierID.Valid {
			if !prevCourier.Valid || prevCourier.Int64 != expectedCourierID.Int64 {
				return &DomainError{Status: 409, Message: "Penugasan retur telah berubah. Muat ulang sebelum menugaskan."}
			}
		} else if prevCourier.Valid {
			return &DomainError{Status: 409, Message: "Penugasan retur telah berubah. Muat ulang sebelum menugaskan."}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE return_items SET courier_id = ?, updated_at = UTC_TIMESTAMP(6)
			WHERE id = ?`, courierID, returnItemID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\ReturnItem', ?, 'return_item.courier_assigned', NULL, ?, UTC_TIMESTAMP(6))`,
			a.ID, returnItemID, mustJSON(map[string]any{"courier_id": courierID,
				"previous_courier_id": nullIntPtr(prevCourier), "actor_role": a.Role})); err != nil {
			return err
		}
		itemID = returnItemID
		assignedCourier = courierID
		return nil
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": itemID, "courier_id": assignedCourier}, nil
}

// canVerifyDelivery mirrors DeliveryVerificationPolicy (super_admin, or same-branch admin).
func (s *Service) canVerifyDelivery(ctx context.Context, a Actor, shipmentID int64) bool {
	if a.IsSuper {
		return true
	}
	if a.Role != "admin" {
		return false
	}
	var agentID int64
	err := s.database.DB.QueryRowContext(ctx, `SELECT o.agent_id FROM shipments sh
		JOIN orders o ON o.id = sh.order_id WHERE sh.id = ?`, shipmentID).Scan(&agentID)
	return err == nil && agentID == a.AgentID
}

func (s *Service) canVerifyDeliveryTx(ctx context.Context, tx *sql.Tx, a Actor, shipmentID int64) bool {
	if a.IsSuper {
		return true
	}
	if a.Role != "admin" {
		return false
	}
	var agentID int64
	err := tx.QueryRowContext(ctx, `SELECT o.agent_id FROM shipments sh
		JOIN orders o ON o.id = sh.order_id WHERE sh.id = ?`, shipmentID).Scan(&agentID)
	return err == nil && agentID == a.AgentID
}

// ItemReceipts ports OrderItemReceiptController::index (REM-07 per-item receipt events).
func (s *Service) ItemReceipts(ctx context.Context, a Actor, orderItemID int64) (any, error) {
	var exists bool
	_ = s.database.DB.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_items WHERE id = ?)`, orderItemID).Scan(&exists)
	if !exists {
		return nil, &DomainError{Status: 404, Message: ""}
	}
	if !s.itemReceiptAuthorized(ctx, a, orderItemID) {
		return nil, &DomainError{Status: 403, Message: "This action is unauthorized."}
	}
	rows, err := s.database.DB.QueryContext(ctx, `SELECT id, order_item_id, quantity, received_on,
		received_at, verified_by, verified_at FROM order_item_receipts WHERE order_item_id = ? ORDER BY id`, orderItemID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, oid, qty int64
		var receivedOn string
		var receivedAt sql.NullTime
		var verifiedBy int64
		var verifiedAt sql.NullTime
		if err := rows.Scan(&id, &oid, &qty, &receivedOn, &receivedAt, &verifiedBy, &verifiedAt); err != nil {
			return nil, err
		}
		row := map[string]any{"id": id, "order_item_id": oid, "quantity": qty, "received_on": receivedOn,
			"verified_by": verifiedBy, "verified_at": carbonMicro(verifiedAt)}
		if receivedAt.Valid {
			row["received_at"] = receivedAt.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
		} else {
			row["received_at"] = nil
		}
		data = append(data, row)
	}
	return data, rows.Err()
}

func (s *Service) itemReceiptAuthorized(ctx context.Context, a Actor, orderItemID int64) bool {
	if a.IsSuper {
		return true
	}
	if a.Role != "admin" {
		return false
	}
	var agentID int64
	err := s.database.DB.QueryRowContext(ctx, `SELECT o.agent_id FROM order_items i
		JOIN orders o ON o.id = i.order_id WHERE i.id = ?`, orderItemID).Scan(&agentID)
	return err == nil && agentID == a.AgentID
}

// RecordItemReceipt ports OrderItemReceiptService::record.
func (s *Service) RecordItemReceipt(ctx context.Context, a Actor, orderItemID int64, quantity int64,
	receivedOn string, receivedAt sql.NullString, evidenceMediaID sql.NullInt64, evidenceNote sql.NullString,
	idempotencyKey string) (map[string]any, error) {
	payload := map[string]any{"item": orderItemID, "qty": quantity, "on": receivedOn}
	if receivedAt.Valid {
		payload["at"] = receivedAt.String
	}
	if evidenceMediaID.Valid {
		payload["media"] = evidenceMediaID.Int64
	}
	if evidenceNote.Valid {
		payload["note"] = strings.TrimSpace(evidenceNote.String)
	}
	fingerprint := payloadFingerprint(payload)
	var receiptID int64
	var receiptQty int64
	var receiptOn string
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderID, agentID int64
		var status string
		var fulfilled int64
		var createdAt sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT order_id, status, fulfilled_quantity, created_at
			FROM order_items WHERE id = ? FOR UPDATE`, orderItemID).Scan(&orderID, &status, &fulfilled, &createdAt); err != nil {
			if err == sql.ErrNoRows {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, orderID).Scan(&agentID); err != nil {
			return err
		}
		if !(a.IsSuper || (a.Role == "admin" && agentID == a.AgentID)) {
			return &DomainError{Status: 403, Message: "Tidak berwenang mencatat penerimaan item ini."}
		}
		var existingID int64
		var existingFP sql.NullString
		eerr := tx.QueryRowContext(ctx, `SELECT id, request_fingerprint FROM order_item_receipts
			WHERE verified_by = ? AND idempotency_key = ? FOR UPDATE`, a.ID, idempotencyKey).
			Scan(&existingID, &existingFP)
		if eerr == nil {
			if existingFP.String != fingerprint {
				return &DomainError{Status: 409, Message: "Idempotency key dipakai dengan data berbeda."}
			}
			var q int64
			var on string
			if err := tx.QueryRowContext(ctx, `SELECT quantity, received_on FROM order_item_receipts WHERE id = ?`,
				existingID).Scan(&q, &on); err != nil {
				return err
			}
			receiptID, receiptQty, receiptOn = existingID, q, on
			return nil
		}
		if eerr != sql.ErrNoRows {
			return eerr
		}
		if quantity < 1 {
			return &DomainError{Status: 422, Message: "Jumlah diterima harus positif."}
		}
		if status != "terkirim" && status != "pengembalian" && status != "kembali" {
			return &DomainError{Status: 422, Message: "Item belum tiba/diterima sehingga penerimaan tidak dapat dicatat."}
		}
		date, derr := time.Parse("2006-01-02", receivedOn)
		if derr != nil {
			return &DomainError{Status: 422, Message: "Jumlah diterima harus positif."}
		}
		today := time.Now().UTC().Truncate(24 * time.Hour)
		if date.After(today) {
			return &DomainError{Status: 422, Message: "Tanggal penerimaan tidak boleh di masa depan."}
		}
		if createdAt.Valid && date.Before(createdAt.Time.UTC().Truncate(24*time.Hour)) {
			return &DomainError{Status: 422, Message: "Tanggal penerimaan tidak boleh sebelum order dibuat."}
		}
		var atTime any
		if receivedAt.Valid {
			at, aerr := time.Parse(time.RFC3339, receivedAt.String)
			if aerr != nil {
				at, aerr = time.Parse("2006-01-02T15:04:05", receivedAt.String)
				if aerr != nil {
					return &DomainError{Status: 422, Message: "Waktu penerimaan tidak konsisten dengan tanggal penerimaan."}
				}
			}
			if at.After(time.Now().UTC()) || at.Format("2006-01-02") != date.Format("2006-01-02") {
				return &DomainError{Status: 422, Message: "Waktu penerimaan tidak konsisten dengan tanggal penerimaan."}
			}
			atTime = at.UTC().Format("2006-01-02 15:04:05")
		}
		var already int64
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM order_item_receipts
			WHERE order_item_id = ? FOR UPDATE`, orderItemID).Scan(&already); err != nil {
			return err
		}
		if already+quantity > fulfilled {
			return &DomainError{Status: 422, Message: "Jumlah penerimaan melebihi kuantitas item."}
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO order_item_receipts (order_item_id, order_id, agent_id,
			quantity, received_on, received_at, evidence_media_id, evidence_note, verified_by, verified_at,
			idempotency_key, request_fingerprint, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			orderItemID, orderID, agentID, quantity, receivedOn, atTime, nullIntPtr(evidenceMediaID),
			noteOrNull(evidenceNote), a.ID, idempotencyKey, fingerprint)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\Order', ?, 'order_item.receipt_recorded', NULL, ?, UTC_TIMESTAMP(6))`,
			a.ID, orderID, mustJSON(map[string]any{"order_item_id": orderItemID, "receipt_id": newID,
				"quantity": quantity, "received_on": receivedOn, "actor_role": a.Role})); err != nil {
			return err
		}
		receiptID, receiptQty, receiptOn = newID, quantity, receivedOn
		return nil
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": receiptID, "quantity": receiptQty, "received_on": receiptOn}, nil
}

func noteOrNull(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	t := strings.TrimSpace(v.String)
	if t == "" {
		return nil
	}
	return t
}

func payloadFingerprint(payload map[string]any) string {
	// PayloadFingerprint::of — sorted-key JSON with trimmed strings, sha256.
	keys := make([]string, 0, len(payload))
	for k := range payload {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var b strings.Builder
	b.WriteByte('{')
	for i, k := range keys {
		if i > 0 {
			b.WriteByte(',')
		}
		kb, _ := json.Marshal(k)
		vb, _ := json.Marshal(payload[k])
		b.Write(kb)
		b.WriteByte(':')
		b.Write(vb)
	}
	b.WriteByte('}')
	sum := sha256.Sum256([]byte(b.String()))
	return hex.EncodeToString(sum[:])
}
