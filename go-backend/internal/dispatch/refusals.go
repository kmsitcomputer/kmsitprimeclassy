package dispatch

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strconv"
)

// RecordItemRefusal ports DeliveryRefusalService::record (the courier's on-the-spot
// refusal of an item out for delivery, incl. partial-refusal unit carving).
func (s *Service) RecordItemRefusal(ctx context.Context, a Actor, shipmentID, orderItemID int64,
	reason string, quantity sql.NullInt64) (map[string]any, error) {
	var refusalID, refusalQty int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderID, agentID int64
		var orderStatus, shipStatus, mode string
		var shipSelf sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.status, sh.status, sh.delivery_mode,
			sh.self_delivered_by_user_id FROM shipments sh JOIN orders o ON o.id = sh.order_id
			WHERE sh.id = ? FOR UPDATE`, shipmentID).Scan(&orderID, &agentID, &orderStatus, &shipStatus, &mode, &shipSelf); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		var itemOrder, itemShipment int64
		var itemStatus, stockSource string
		var fulfilled int64
		if err := tx.QueryRowContext(ctx, `SELECT order_id, shipment_id, status, stock_source, fulfilled_quantity
			FROM order_items WHERE id = ? FOR UPDATE`, orderItemID).Scan(&itemOrder, &itemShipment, &itemStatus, &stockSource, &fulfilled); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &DomainError{Status: 404, Message: ""}
			}
			return err
		}
		if itemShipment != shipmentID || itemOrder != orderID {
			return &DomainError{Status: 404, Message: "Anda tidak memiliki akses untuk melakukan aksi ini."}
		}
		if shipSelf.Valid || stockSource == "sub" {
			return &DomainError{Status: 422, Message: "Penolakan tidak berlaku untuk pengiriman ini."}
		}
		// Executor: the shipment courier's user must be this active actor.
		var courierID sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT courier_id FROM shipments WHERE id = ?`, shipmentID).Scan(&courierID); err != nil {
			return err
		}
		isExecutor := false
		if courierID.Valid {
			var cUser sql.NullInt64
			if err := tx.QueryRowContext(ctx, `SELECT user_id FROM couriers WHERE id = ?`, courierID.Int64).Scan(&cUser); err == nil {
				isExecutor = cUser.Valid && cUser.Int64 == a.ID
			}
		}
		if !isExecutor || a.Status != "active" {
			// The controller authorize() runs first: a policy denial renders the
			// AuthorizationException text; the service executor check (403 not_your_delivery)
			// only fires for roles the policy let through.
			if a.Role == "koordinator-kurir" {
				return &DomainError{Status: 403, Message: "This action is unauthorized."}
			}
			return &DomainError{Status: 403, Message: "Order ini sudah ditugaskan ke kurir lain."}
		}
		if itemStatus != "dikirim" {
			return &DomainError{Status: 422, Message: "Item belum dalam pengiriman."}
		}
		var open bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM delivery_refusals WHERE order_item_id = ?
			AND status IN ('with_courier','handed_back','received') FOR UPDATE)`, orderItemID).Scan(&open); err != nil {
			return err
		}
		if open {
			return &DomainError{Status: 422, Message: "Sudah ada penolakan terbuka untuk item ini."}
		}
		refusedQty := fulfilled
		if quantity.Valid {
			if quantity.Int64 < 1 || quantity.Int64 > refusedQty {
				return &DomainError{Status: 422, Message: "Jumlah pemenuhan tidak valid."}
			}
			if quantity.Int64 < refusedQty {
				newItemID, serr := s.splitOutRefusedUnits(ctx, tx, orderID, orderItemID, quantity.Int64, a.ID)
				if serr != nil {
					return serr
				}
				orderItemID = newItemID
				refusedQty = quantity.Int64
			}
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO delivery_refusals (agent_id, order_id, order_item_id,
			shipment_id, quantity, reason, status, recorded_by, recorded_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 'with_courier', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			agentID, orderID, orderItemID, shipmentID, refusedQty, reason, a.ID)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\\Models\\DeliveryRefusal', ?, 'delivery_refusal.recorded', ?, ?, UTC_TIMESTAMP(6))`,
			a.ID, newID, reason, mustJSON(map[string]any{"order_id": orderID, "order_item_id": orderItemID,
				"quantity": refusedQty})); err != nil {
			return err
		}
		refusalID = newID
		refusalQty = refusedQty
		return nil
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": refusalID, "status": "with_courier", "quantity": refusalQty,
		"order_item_id": orderItemID}, nil
}

// splitOutRefusedUnits ports DeliveryRefusalService::splitOutRefusedUnits: carve refused
// units into a sibling item on the SAME shipment, apportioning money/fees and warehouse demand.
func (s *Service) splitOutRefusedUnits(ctx context.Context, tx *sql.Tx, orderID, itemID, qty int64, actorID int64) (int64, error) {
	var srID int64
	var srItemID sql.NullInt64
	var demandFulfilled, demandRequested int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM stock_requests WHERE order_id = ? FOR UPDATE`, orderID).Scan(&srID)
	if err == nil {
		serr := tx.QueryRowContext(ctx, `SELECT id, requested_qty, fulfilled_qty FROM stock_request_items
			WHERE stock_request_id = ? AND order_item_id = ? FOR UPDATE`, srID, itemID).
			Scan(&srItemID, &demandRequested, &demandFulfilled)
		if serr == nil && (demandFulfilled < qty || demandRequested < qty) {
			return 0, &DomainError{Status: 422, Message: "Status stok tidak konsisten dengan pemecahan penolakan."}
		}
	} else if err != sql.ErrNoRows {
		return 0, err
	}
	var orderIDv, shipID int64
	var productID, variationID, subLocID sql.NullInt64
	var stockSource string
	var pname, vlabel, sku sql.NullString
	var unitPrice, subtotal, courierFee string
	var fulfilled int64
	var reqDate sql.NullString
	var status string
	var handedOver sql.NullTime
	if err := tx.QueryRowContext(ctx, `SELECT order_id, shipment_id, product_id, product_variation_id,
		stock_source, sub_location_id, product_name_snapshot, variation_label_snapshot, sku_snapshot,
		CAST(unit_price_snapshot AS CHAR), CAST(subtotal_snapshot AS CHAR), CAST(courier_fee_amount AS CHAR),
		fulfilled_quantity, requested_delivery_date, status, handed_over_at FROM order_items WHERE id = ?`, itemID).
		Scan(&orderIDv, &shipID, &productID, &variationID, &stockSource, &subLocID, &pname, &vlabel,
			&sku, &unitPrice, &subtotal, &courierFee, &fulfilled, &reqDate, &status, &handedOver); err != nil {
		return 0, err
	}
	up, _ := strconv.ParseFloat(unitPrice, 64)
	cf, _ := strconv.ParseFloat(courierFee, 64)
	sub, _ := strconv.ParseFloat(subtotal, 64)
	perUnitFee := 0.0
	if fulfilled > 0 {
		perUnitFee = cf / float64(fulfilled)
	}
	movedFee := math.Round(perUnitFee*float64(qty)*100) / 100
	movedSubtotal := math.Round(up*float64(qty)*100) / 100
	retainedFee := math.Round((cf-movedFee)*100) / 100
	retainedSubtotal := math.Round((sub-movedSubtotal)*100) / 100
	res, ierr := tx.ExecContext(ctx, `INSERT INTO order_items (order_id, shipment_id, split_from_order_item_id,
		product_id, product_variation_id, stock_source, sub_location_id, product_name_snapshot,
		variation_label_snapshot, sku_snapshot, unit_price_snapshot, agent_fee_amount, sales_fee_amount,
		courier_fee_amount, subtotal_snapshot, original_quantity, fulfilled_quantity, requested_delivery_date,
		status, handed_over_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
		orderIDv, shipID, itemID, productID, variationID, stockSource, subLocID, pname, vlabel, sku,
		unitPrice, movedFee, movedSubtotal, qty, qty, nullStrPtr(reqDate.String), status, handedOver)
	if ierr != nil {
		return 0, ierr
	}
	childID, lerr := res.LastInsertId()
	if lerr != nil {
		return 0, lerr
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items SET original_quantity = original_quantity - ?,
		fulfilled_quantity = fulfilled_quantity - ?, subtotal_snapshot = ?, courier_fee_amount = ?,
		updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, qty, qty, retainedSubtotal, retainedFee, itemID); err != nil {
		return 0, err
	}
	if srItemID.Valid {
		if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items SET requested_qty = requested_qty - ?,
			fulfilled_qty = fulfilled_qty - ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, qty, qty, srItemID.Int64); err != nil {
			return 0, err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO stock_request_items (stock_request_id, order_item_id,
			product_id, product_variation_id, sku_snapshot, requested_qty, fulfilled_qty, remaining_qty,
			created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			srID, childID, productID, variationID, sku, qty, qty); err != nil {
			return 0, err
		}
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
		description, properties, created_at)
		VALUES (?, 'App\\Models\\OrderItem', ?, 'order_item.split_for_refusal', NULL, ?, UTC_TIMESTAMP(6))`,
		actorID, childID, mustJSON(map[string]any{"order_id": orderIDv, "from_order_item_id": itemID, "quantity": qty})); err != nil {
		return 0, err
	}
	return childID, nil
}

// HandBack ports DeliveryRefusalService::handBack — courier → koordinator custody transfer.
func (s *Service) HandBack(ctx context.Context, a Actor, refusalID int64) (map[string]any, error) {
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var shipmentID, orderItemID int64
		var status string
		err := tx.QueryRowContext(ctx, `SELECT shipment_id, order_item_id, status FROM delivery_refusals
			WHERE id = ? FOR UPDATE`, refusalID).Scan(&shipmentID, &orderItemID, &status)
		if errors.Is(err, sql.ErrNoRows) {
			return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
		}
		if err != nil {
			return err
		}
		executor, err := s.isExecutor(ctx, tx, shipmentID, a.ID)
		if err != nil {
			return err
		}
		if !executor {
			return &DomainError{Status: 403, Message: "Order ini sudah ditugaskan ke kurir lain."}
		}
		if status == "handed_back" {
			out = refusalRow(ctx, tx, refusalID) // idempotent replay
			return nil
		}
		if status != "with_courier" {
			return &DomainError{Status: 422, Message: "Status penolakan tidak sesuai untuk aksi ini."}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE delivery_refusals SET status='handed_back',
			handed_back_by=?, handed_back_at=UTC_TIMESTAMP(6), updated_at=UTC_TIMESTAMP(6) WHERE id=?`, a.ID, refusalID); err != nil {
			return err
		}
		if err := writeRefusalAudit(ctx, tx, a.ID, refusalID, "delivery_refusal.handed_back",
			map[string]any{"order_item_id": orderItemID}, ""); err != nil {
			return err
		}
		out = refusalRow(ctx, tx, refusalID)
		return nil
	})
	return out, err
}

// Receive ports DeliveryRefusalService::receive — koordinator receipt (koordinator-kurir, same branch).
func (s *Service) Receive(ctx context.Context, a Actor, refusalID int64) (map[string]any, error) {
	if err := s.assertCoordinator(ctx, a, refusalID); err != nil {
		return nil, err
	}
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var shipmentID, orderItemID int64
		var status string
		err := tx.QueryRowContext(ctx, `SELECT shipment_id, order_item_id, status FROM delivery_refusals
			WHERE id = ? FOR UPDATE`, refusalID).Scan(&shipmentID, &orderItemID, &status)
		if errors.Is(err, sql.ErrNoRows) {
			return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
		}
		if err != nil {
			return err
		}
		if status == "received" {
			out = refusalRow(ctx, tx, refusalID) // idempotent replay
			return nil
		}
		if status != "handed_back" {
			return &DomainError{Status: 422, Message: "Status penolakan tidak sesuai untuk aksi ini."}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE delivery_refusals SET status='received',
			received_by=?, received_at=UTC_TIMESTAMP(6), updated_at=UTC_TIMESTAMP(6) WHERE id=?`, a.ID, refusalID); err != nil {
			return err
		}
		if err := writeRefusalAudit(ctx, tx, a.ID, refusalID, "delivery_refusal.received",
			map[string]any{"order_item_id": orderItemID}, ""); err != nil {
			return err
		}
		out = refusalRow(ctx, tx, refusalID)
		return nil
	})
	return out, err
}

// Dispose ports DeliveryRefusalService::dispose — koordinator redelivery / return_to_warehouse.
// return_to_warehouse (non-Sub lines only): Shipping → Transit reversal + reservation restore +
// reserve movement + stock-request demand restore, then the line returns to 'diproses' on a fresh
// pending shipment (assignFreshShipment).
func (s *Service) Dispose(ctx context.Context, a Actor, refusalID int64, action, note string) (map[string]any, error) {
	if action != "redelivery" && action != "return_to_warehouse" {
		return nil, &DomainError{Status: 422, Message: "Status penolakan tidak sesuai untuk aksi ini."}
	}
	if err := s.assertCoordinator(ctx, a, refusalID); err != nil {
		return nil, err
	}
	final := "returned_to_warehouse"
	if action == "redelivery" {
		final = "redelivery"
	}
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderID, shipmentID, orderItemID, agentID, quantity int64
		var status string
		var productID, variationID, subLocationID sql.NullInt64
		err := tx.QueryRowContext(ctx, `SELECT r.order_id, r.shipment_id, r.order_item_id, r.agent_id, r.status,
			r.quantity, i.product_id, i.product_variation_id, i.sub_location_id
			FROM delivery_refusals r JOIN order_items i ON i.id = r.order_item_id
			WHERE r.id = ? FOR UPDATE`, refusalID).
			Scan(&orderID, &shipmentID, &orderItemID, &agentID, &status, &quantity, &productID, &variationID, &subLocationID)
		if errors.Is(err, sql.ErrNoRows) {
			return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
		}
		if err != nil {
			return err
		}
		if status == final {
			out = refusalRow(ctx, tx, refusalID) // idempotent replay
			return nil
		}
		if status != "received" {
			return &DomainError{Status: 422, Message: "Status penolakan tidak sesuai untuk aksi ini."}
		}
		// Lock the aggregate roots in the reference's order.
		if _, err := tx.ExecContext(ctx, "SELECT id FROM orders WHERE id = ? FOR UPDATE", orderID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, "SELECT id FROM shipments WHERE id = ? FOR UPDATE", shipmentID); err != nil {
			return err
		}
		var isSub bool
		if err := tx.QueryRowContext(ctx, "SELECT stock_source = 'sub' FROM order_items WHERE id = ?",
			orderItemID).Scan(&isSub); err != nil {
			return err
		}
		if action == "return_to_warehouse" && !isSub {
			if err := s.returnToWarehouse(ctx, tx, orderID, agentID, orderItemID, productID, variationID, quantity, a.ID); err != nil {
				return err
			}
		}
		// The goods are no longer in transit: the line goes back to 'diproses' on a fresh pending shipment.
		if _, err := tx.ExecContext(ctx, `UPDATE order_items SET status='diproses', handed_over_at=NULL,
			updated_at=UTC_TIMESTAMP(6) WHERE id=?`, orderItemID); err != nil {
			return err
		}
		if err := s.assignFreshShipment(ctx, tx, orderID, orderItemID, isSub, subLocationID, a.ID, "shipment.refusal_"+final); err != nil {
			return err
		}
		// syncShipmentAggregate: the abandoned shipment's aggregate status recomputes from its remaining lines.
		if err := s.syncShipmentAggregate(ctx, tx, shipmentID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE delivery_refusals SET status=?, disposition_by=?,
			disposition_at=UTC_TIMESTAMP(6), disposition_note=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?`,
			final, a.ID, nullableStr(note), refusalID); err != nil {
			return err
		}
		props := map[string]any{"order_item_id": orderItemID, "quantity": quantity}
		if err := writeRefusalAudit(ctx, tx, a.ID, refusalID, "delivery_refusal."+final, props, note); err != nil {
			return err
		}
		out = refusalRow(ctx, tx, refusalID)
		return nil
	})
	return out, err
}

// assertCoordinator ports DeliveryRefusalService::assertCoordinator.
func (s *Service) assertCoordinator(ctx context.Context, a Actor, refusalID int64) error {
	if a.Role != "koordinator-kurir" {
		return &DomainError{Status: 403, Message: "Hanya Koordinator Kurir yang dapat menugaskan kurir."}
	}
	var agentID int64
	err := s.database.QueryRowContext(ctx, "SELECT agent_id FROM delivery_refusals WHERE id = ?", refusalID).Scan(&agentID)
	if errors.Is(err, sql.ErrNoRows) {
		return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
	}
	if err != nil {
		return err
	}
	if a.AgentID != agentID {
		return &DomainError{Status: 403, Message: "Hanya Koordinator Kurir yang dapat menugaskan kurir."}
	}
	return nil
}

// returnToWarehouse ports the private returnToWarehouse: Shipping → Transit reversal
// (InventoryCancellationService::reverseAcceptedUnits) + reservation restore + reserve movement
// + stock-request demand restore.
func (s *Service) returnToWarehouse(ctx context.Context, tx *sql.Tx, orderID, agentID, orderItemID int64,
	productID, variationID sql.NullInt64, qty, actorID int64) error {
	var requestID sql.NullInt64
	var fulfilled, remaining int
	err := tx.QueryRowContext(ctx, `SELECT r.id, i.fulfilled_qty, i.remaining_qty
		FROM stock_requests r JOIN stock_request_items i ON i.stock_request_id = r.id
		WHERE r.order_id = ? AND i.order_item_id = ? FOR UPDATE`, orderID, orderItemID).
		Scan(&requestID, &fulfilled, &remaining)
	if errors.Is(err, sql.ErrNoRows) {
		return &DomainError{Status: 422, Message: "Kondisi stok tidak sesuai untuk pengembalian ke Gudang."}
	}
	if err != nil {
		return err
	}
	if !requestID.Valid || int64(fulfilled) < int64(qty) {
		return &DomainError{Status: 422, Message: "Kondisi stok tidak sesuai untuk pengembalian ke Gudang."}
	}
	where := targetWhere(productID, variationID)
	args := whereArgs(productID, variationID)
	shipping := lockStock(ctx, tx, agentID, "shipping", where, args)
	if shipping == nil || shipping.quantity < int64(qty) {
		return &DomainError{Status: 422, Message: "Stok Shipping sudah tidak tersedia untuk pembatalan; gunakan return."}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity - ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		qty, shipping.id); err != nil {
		return err
	}
	var transitID int64
	err = tx.QueryRowContext(ctx, `SELECT id FROM warehouse_stocks WHERE agent_id = ? AND stock_type = 'transit'
		AND sub_location_id IS NULL AND `+where+" FOR UPDATE", append([]any{agentID}, args...)...).Scan(&transitID)
	if errors.Is(err, sql.ErrNoRows) {
		res, ierr := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks (agent_id, product_id, product_variation_id,
			stock_type, quantity, created_at, updated_at) VALUES (?, ?, NULL, 'transit', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			agentID, nullableInt(productID))
		if ierr != nil {
			return ierr
		}
		transitID, ierr = res.LastInsertId()
		if ierr != nil {
			return ierr
		}
	} else if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		qty, transitID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO stock_movements (agent_id, product_id, product_variation_id, type, quantity,
		stock_type, reference_type, reference_id, created_by, note, created_at, updated_at) VALUES
		(?, ?, ?, 'cancellation_release', ?, 'shipping', 'App\Models\Order', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, nullableInt(productID), nullableInt(variationID), -qty, orderID, actorID, fmt.Sprintf("order_item=%d", orderItemID)); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO stock_movements (agent_id, product_id, product_variation_id, type, quantity,
		stock_type, reference_type, reference_id, created_by, note, created_at, updated_at) VALUES
		(?, ?, ?, 'cancellation_release', ?, 'transit', 'App\Models\Order', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, nullableInt(productID), nullableInt(variationID), qty, orderID, actorID, fmt.Sprintf("order_item=%d", orderItemID)); err != nil {
		return err
	}
	// reservation restore + reserve movement
	resTable := "product_stocks"
	resWhere := "product_id = ?"
	resID := productID.Int64
	if variationID.Valid {
		resTable = "product_variation_stocks"
		resWhere = "product_variation_id = ?"
		resID = variationID.Int64
	}
	var reservationID int64
	err = tx.QueryRowContext(ctx, fmt.Sprintf(`SELECT id FROM %s WHERE agent_id = ? AND %s FOR UPDATE`, resTable, resWhere),
		agentID, resID).Scan(&reservationID)
	if errors.Is(err, sql.ErrNoRows) {
		return &DomainError{Status: 422, Message: "Kondisi stok tidak sesuai untuk pengembalian ke Gudang."}
	}
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, fmt.Sprintf(`UPDATE %s SET quantity_reserved = quantity_reserved + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`, resTable),
		qty, reservationID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO stock_movements (agent_id, product_id, product_variation_id, type,
		quantity, reference_type, reference_id, created_by, note, created_at, updated_at) VALUES
		(?, ?, ?, 'reserve', ?, 'App\Models\Order', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, nullableInt(productID), nullableInt(variationID), qty, orderID, actorID,
		fmt.Sprintf("delivery_refusal_return;order_item=%d", orderItemID)); err != nil {
		return err
	}
	// demand restore + stock-request status recompute
	if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items SET fulfilled_qty = fulfilled_qty - ?,
		remaining_qty = remaining_qty + ?, updated_at = UTC_TIMESTAMP() WHERE stock_request_id = ? AND order_item_id = ?`,
		qty, qty, requestID.Int64, orderItemID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE stock_requests r SET
		status = IF((SELECT COALESCE(SUM(remaining_qty),0) FROM stock_request_items WHERE stock_request_id = r.id) = 0, 'fulfilled',
			IF((SELECT COALESCE(SUM(fulfilled_qty),0) FROM stock_request_items WHERE stock_request_id = r.id) > 0, 'partial', 'pending')),
		updated_at = UTC_TIMESTAMP() WHERE r.id = ?`, requestID.Int64); err != nil {
		return err
	}
	return nil
}

// assignFreshShipment ports OrderFulfillmentService::assignFreshShipment: a fresh pending shipment
// cloning the reference shipment's routing, wired to the item (Sub-sourced lines stay self_sub).
func (s *Service) assignFreshShipment(ctx context.Context, tx *sql.Tx, orderID, orderItemID int64,
	isSub bool, subLocationID sql.NullInt64, actorID int64, event string) error {
	var refProviderID sql.NullInt64
	var refProviderCode, refMeta sql.NullString
	var refOriginLat, refOriginLon, refDestLat, refDestLon, refDistance sql.NullFloat64
	var refSelf sql.NullInt64
	err := tx.QueryRowContext(ctx, `SELECT shipping_provider_id, shipping_provider_code, origin_latitude,
		origin_longitude, destination_latitude, destination_longitude, distance_km, provider_meta,
		self_delivered_by_user_id FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1`, orderID).
		Scan(&refProviderID, &refProviderCode, &refOriginLat, &refOriginLon, &refDestLat, &refDestLon,
			&refDistance, &refMeta, &refSelf)
	if err != nil && err != sql.ErrNoRows {
		return err
	}
	var selfDelivered any
	if isSub {
		if refSelf.Valid {
			selfDelivered = refSelf.Int64
		} else if subLocationID.Valid {
			var owner sql.NullInt64
			if err := tx.QueryRowContext(ctx, `SELECT owner_user_id FROM warehouse_sub_locations WHERE id = ?`,
				subLocationID.Int64).Scan(&owner); err != nil {
				return err
			}
			selfDelivered = nullIntPtr(owner)
		}
	}
	mode := "standard"
	if isSub {
		mode = "self_sub"
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO shipments (order_id, shipping_provider_id, shipping_provider_code,
		origin_latitude, origin_longitude, destination_latitude, destination_longitude, distance_km, provider_meta,
		status, delivery_mode, self_delivered_by_user_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
		orderID, nullIntPtr(refProviderID), nullStr(refProviderCode), refOriginLat, refOriginLon, refDestLat,
		refDestLon, refDistance, nullStr(refMeta), mode, selfDelivered)
	if err != nil {
		return err
	}
	shipmentID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`,
		shipmentID, orderItemID); err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
		description, properties, created_at)
		VALUES (?, 'App\Models
Shipment', ?, ?, NULL, ?, UTC_TIMESTAMP(6))`,
		actorID, shipmentID, event, mustJSON(map[string]any{"order_id": orderID, "order_item_id": orderItemID}))
	return err
}

// syncShipmentAggregate ports CourierService::syncShipmentAggregate: the shipment's status recomputes
// from its remaining (non-cancelled) item statuses.
func (s *Service) syncShipmentAggregate(ctx context.Context, tx *sql.Tx, shipmentID int64) error {
	_, err := tx.ExecContext(ctx, `UPDATE shipments sh SET
		status = CASE
			WHEN NOT EXISTS(SELECT 1 FROM order_items i WHERE i.shipment_id = sh.id AND i.status <> 'dibatalkan') THEN sh.status
			WHEN NOT EXISTS(SELECT 1 FROM order_items i WHERE i.shipment_id = sh.id AND i.status NOT IN ('dibatalkan','diterima')) THEN 'delivered'
			WHEN EXISTS(SELECT 1 FROM order_items i WHERE i.shipment_id = sh.id AND i.status IN ('dikirim','diterima')) THEN 'shipped'
			ELSE 'pending' END,
		delivered_at = IF(NOT EXISTS(SELECT 1 FROM order_items i WHERE i.shipment_id = sh.id AND i.status NOT IN ('dibatalkan','diterima')) AND sh.delivered_at IS NULL, UTC_TIMESTAMP(6), sh.delivered_at),
		updated_at = UTC_TIMESTAMP(6) WHERE sh.id = ?`, shipmentID)
	return err
}

// ---- shared helpers -------------------------------------------------------------------------------

func nullableStr(v string) any {
	if v == "" {
		return nil
	}
	return v
}

func mustJSON(v any) string {
	buf, err := json.Marshal(v)
	if err != nil {
		return "{}"
	}
	return string(buf)
}

func targetWhere(productID, variationID sql.NullInt64) string {
	if variationID.Valid {
		return "product_variation_id = ? AND product_id IS NULL"
	}
	return "product_id = ? AND product_variation_id IS NULL"
}

func whereArgs(productID, variationID sql.NullInt64) []any {
	if variationID.Valid {
		return []any{variationID.Int64}
	}
	return []any{productID.Int64}
}

type stockRow struct {
	id       int64
	quantity int64
}

func lockStock(ctx context.Context, tx *sql.Tx, agentID int64, stockType, where string, args []any) *stockRow {
	var r stockRow
	full := append([]any{agentID, stockType}, args...)
	err := tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stocks WHERE agent_id = ? AND stock_type = ?
		AND sub_location_id IS NULL AND `+where+" FOR UPDATE", full...).Scan(&r.id, &r.quantity)
	if err != nil {
		return nil
	}
	return &r
}

func nullableInt(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}
