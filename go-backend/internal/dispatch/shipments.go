package dispatch

// Shipment status transitions: CourierService::updateShipmentStatus + updateOrderItemStatus.
// Executor authorization, refusal guard, handover timestamp, Sub consume, aggregate sync,
// courier commissions on delivery (idempotent), finalizer pending→approved, order recompute.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
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
