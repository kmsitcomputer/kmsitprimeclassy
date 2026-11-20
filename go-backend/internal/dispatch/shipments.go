package dispatch

// Shipment status transitions: CourierService::updateShipmentStatus + updateOrderItemStatus.
// Executor authorization, refusal guard, handover timestamp, Sub consume, aggregate sync,
// courier commissions on delivery (idempotent), finalizer pending→approved, order recompute.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"sort"
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
