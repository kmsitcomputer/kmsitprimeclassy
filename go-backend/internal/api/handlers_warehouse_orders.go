package api

import (
	"context"
	"database/sql"
	"net/http"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// handleWarehouseOrdersDiproses ports WarehouseOrderController::diproses. Keep the branch, status,
// courier/self-delivery visibility predicates in SQL so direct client filters cannot widen the queue.
func (s *Server) handleWarehouseOrdersDiproses(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}

	perPage, _ := strconv.Atoi(httpx.QueryString(r, "per_page"))
	if perPage < 1 {
		perPage = 15
	}
	page, _ := strconv.Atoi(httpx.QueryString(r, "page"))
	if page < 1 {
		page = 1
	}
	requested := false
	switch httpx.QueryString(r, "requested") {
	case "1", "true", "on", "yes":
		requested = true
	}

	// The reference widens the legacy diproses queue only for outstanding Agent demand. A
	// self-delivering Sub shipment excludes the order from both branches of that predicate.
	base := `o.agent_id = ? AND NOT EXISTS (
		SELECT 1 FROM shipments sx WHERE sx.order_id = o.id AND sx.self_delivered_by_user_id IS NOT NULL
	) AND (
		(o.status = 'diproses' AND NOT EXISTS (SELECT 1 FROM shipments sc WHERE sc.order_id = o.id AND sc.courier_id IS NOT NULL))
		OR (o.status IN ('diproses','dikirim') AND EXISTS (
			SELECT 1 FROM stock_requests sr WHERE sr.order_id = o.id AND sr.status IN ('pending','partial')
		))
	)`
	args := []any{*actor.AgentID}
	if requested {
		base += ` AND EXISTS (SELECT 1 FROM stock_requests rq WHERE rq.order_id = o.id
			AND rq.coordinator_requested_at IS NOT NULL AND rq.status IN ('pending','partial'))`
	}
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders o WHERE `+base, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	var requestedTotal int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM stock_requests WHERE agent_id = ?
		AND coordinator_requested_at IS NOT NULL AND status IN ('pending','partial')`, *actor.AgentID).Scan(&requestedTotal); err != nil {
		writeError(w, r, err)
		return
	}

	rows, err := s.database.QueryContext(ctx, `SELECT o.id,
		(SELECT MAX(sr.coordinator_requested_at) FROM stock_requests sr WHERE sr.order_id = o.id AND sr.status IN ('pending','partial')) AS requested_at
		FROM orders o WHERE `+base+`
		ORDER BY (requested_at IS NULL) ASC, requested_at DESC, o.created_at DESC
		LIMIT ? OFFSET ?`, append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	type queueOrder struct {
		id int64
	}
	ids := make([]queueOrder, 0, perPage)
	for rows.Next() {
		var item queueOrder
		var requestedAt sql.NullTime
		if err = rows.Scan(&item.id, &requestedAt); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		ids = append(ids, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeError(w, r, err)
		return
	}
	rows.Close()

	service := s.orderService()
	viewer := viewerOf(actor)
	data := make([]any, 0, len(ids))
	for _, queued := range ids {
		order, loadErr := service.LoadOrderForRead(ctx, queued.id, false)
		if loadErr != nil {
			writeError(w, r, orderError(ctx, loadErr))
			return
		}
		rendered, renderErr := orders.Render(ctx, service, order, viewer)
		if renderErr != nil {
			writeError(w, r, renderErr)
			return
		}
		if enrichErr := s.enrichWarehouseOrderItems(ctx, queued.id, rendered); enrichErr != nil {
			writeError(w, r, enrichErr)
			return
		}
		fulfillment, loadErr := s.loadWarehouseFulfillmentRequest(ctx, queued.id)
		if loadErr != nil {
			writeError(w, r, loadErr)
			return
		}
		rendered["fulfillment_request"] = fulfillment
		data = append(data, rendered)
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	httpx.OK(w, data, "OK", map[string]any{
		"current_page":    page,
		"last_page":       lastPage,
		"total":           total,
		"requested_total": requestedTotal,
	})
}

// WarehouseOrderController eager-loads each item's own shipment.courier.user and shipment.proof.
// The generic order renderer intentionally omits those relation keys on other list endpoints.
func (s *Server) enrichWarehouseOrderItems(ctx context.Context, orderID int64, rendered map[string]any) error {
	rows, err := s.database.QueryContext(ctx, `SELECT sh.id, c.id, c.name, c.user_id, u.phone, m.path
		FROM shipments sh LEFT JOIN couriers c ON c.id=sh.courier_id LEFT JOIN users u ON u.id=c.user_id
		LEFT JOIN media m ON m.id=sh.proof_media_id WHERE sh.order_id=?`, orderID)
	if err != nil {
		return err
	}
	type shipmentProjection struct{ courier, proof any }
	projections := map[int64]shipmentProjection{}
	for rows.Next() {
		var id int64
		var courierID, userID sql.NullInt64
		var courierName, phone, proofPath sql.NullString
		if err := rows.Scan(&id, &courierID, &courierName, &userID, &phone, &proofPath); err != nil {
			rows.Close()
			return err
		}
		projection := shipmentProjection{}
		if courierID.Valid {
			var userValue, phoneValue any
			if userID.Valid {
				userValue = userID.Int64
			}
			if phone.Valid {
				phoneValue = phone.String
			}
			projection.courier = map[string]any{"name": courierName.String, "phone": phoneValue, "user_id": userValue}
		}
		if proofPath.Valid && proofPath.String != "" {
			projection.proof = media.URL(proofPath.String)
		}
		projections[id] = projection
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	items, ok := rendered["items"].([]any)
	if !ok {
		return nil
	}
	for _, raw := range items {
		item, ok := raw.(map[string]any)
		if !ok || item["shipment_id"] == nil {
			continue
		}
		var shipmentID int64
		switch value := item["shipment_id"].(type) {
		case int64:
			shipmentID = value
		case int:
			shipmentID = int64(value)
		case float64:
			shipmentID = int64(value)
		}
		projection, exists := projections[shipmentID]
		if !exists {
			continue
		}
		if projection.courier != nil {
			item["courier"] = projection.courier
		}
		if projection.proof != nil {
			item["delivery_proof_url"] = projection.proof
		}
	}
	return nil
}

func (s *Server) loadWarehouseFulfillmentRequest(ctx context.Context, orderID int64) (any, error) {
	var id int64
	var number, status string
	var coordinatorID sql.NullInt64
	var requestedAt sql.NullTime
	var note sql.NullString
	err := s.database.QueryRowContext(ctx, `SELECT id, request_number, status, coordinator_requested_by, coordinator_requested_at, coordinator_request_note
		FROM stock_requests WHERE order_id = ? LIMIT 1`, orderID).Scan(&id, &number, &status, &coordinatorID, &requestedAt, &note)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var requestedQty, handedOverQty, outstandingQty int
	if err = s.database.QueryRowContext(ctx, `SELECT COALESCE(SUM(requested_qty),0), COALESCE(SUM(fulfilled_qty),0), COALESCE(SUM(remaining_qty),0)
		FROM stock_request_items WHERE stock_request_id = ?`, id).Scan(&requestedQty, &handedOverQty, &outstandingQty); err != nil {
		return nil, err
	}
	var awaitingQty int
	if err = s.database.QueryRowContext(ctx, `SELECT COALESCE(SUM(i.quantity),0)
		FROM stock_request_proposal_items i JOIN stock_request_proposals p ON p.id = i.stock_request_proposal_id
		WHERE p.stock_request_id = ? AND p.status = 'pending'`, id).Scan(&awaitingQty); err != nil {
		return nil, err
	}
	var noteValue any
	if note.Valid {
		noteValue = note.String
	}
	out := map[string]any{
		"stock_request_id": id, "request_number": number, "status": status,
		"requested_by_coordinator": requestedAt.Valid, "requested_at": nil, "note": noteValue,
		"requested_qty": requestedQty, "handed_over_qty": handedOverQty,
		"awaiting_receipt_qty": awaitingQty, "outstanding_qty": outstandingQty,
	}
	if requestedAt.Valid {
		out["requested_at"] = requestedAt.Time.UTC().Format("2006-01-02T15:04:05-07:00")
	}
	if coordinatorID.Valid {
		var name string
		if err = s.database.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, coordinatorID.Int64).Scan(&name); err != nil && err != sql.ErrNoRows {
			return nil, err
		}
		var nameValue any
		if err == nil {
			nameValue = name
		}
		out["coordinator"] = map[string]any{"id": coordinatorID.Int64, "name": nameValue}
	} else {
		out["coordinator"] = nil
	}
	return out, nil
}

// handleWarehouseOrderStockRequest is the Gudang-only StockRequest overlay for an order that is still
// in the same branch's warehouse scope. A direct URL cannot bypass that queue predicate.
func (s *Server) handleWarehouseOrderStockRequest(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	var status string
	err := s.database.QueryRowContext(ctx, `SELECT o.status FROM orders o WHERE o.id = ? AND o.agent_id = ?
		AND NOT EXISTS (SELECT 1 FROM shipments sx WHERE sx.order_id = o.id AND sx.self_delivered_by_user_id IS NOT NULL)
		AND ((o.status = 'diproses' AND NOT EXISTS (SELECT 1 FROM shipments sc WHERE sc.order_id = o.id AND sc.courier_id IS NOT NULL))
			OR (o.status IN ('diproses','dikirim') AND EXISTS (SELECT 1 FROM stock_requests sr WHERE sr.order_id = o.id AND sr.status IN ('pending','partial'))))`,
		orderID, *actor.AgentID).Scan(&status)
	if err == sql.ErrNoRows {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	var (
		requestID, requestAgentID, requestOrderID int64
		requestNumber, requestStatus              string
		coordinatorID                             sql.NullInt64
		coordinatorAt                             sql.NullTime
		coordinatorNote                           sql.NullString
		createdAt, updatedAt                      time.Time
		orderNo                                   string
	)
	err = s.database.QueryRowContext(ctx, `SELECT sr.id, sr.agent_id, sr.order_id, sr.request_number, sr.status,
		sr.coordinator_requested_by, sr.coordinator_requested_at, sr.coordinator_request_note,
		sr.created_at, sr.updated_at, o.order_no
		FROM stock_requests sr JOIN orders o ON o.id=sr.order_id
		WHERE sr.order_id=? AND sr.agent_id=? LIMIT 1`, orderID, *actor.AgentID).Scan(
		&requestID, &requestAgentID, &requestOrderID, &requestNumber, &requestStatus,
		&coordinatorID, &coordinatorAt, &coordinatorNote, &createdAt, &updatedAt, &orderNo)
	if err == sql.ErrNoRows {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	_ = status // the guarded read above intentionally establishes same-branch warehouse visibility first.
	items, err := s.loadWarehouseStockRequestItems(ctx, requestID, requestAgentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	data := map[string]any{
		"id": requestID, "agent_id": requestAgentID, "order_id": requestOrderID,
		"request_number": requestNumber, "status": requestStatus,
		"coordinator_requested_by": nullableInt64Value(coordinatorID),
		"coordinator_requested_at": nullableTimeValue(coordinatorAt),
		"coordinator_request_note": nullableSQLString(coordinatorNote),
		"items":                    items,
		"order":                    map[string]any{"id": requestOrderID, "order_no": orderNo, "status": status},
		"created_at":               createdAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"updated_at":               updatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) loadWarehouseStockRequestItems(ctx context.Context, requestID, agentID int64) ([]any, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT id FROM stock_request_items WHERE stock_request_id=? ORDER BY id`, requestID)
	if err != nil {
		return nil, err
	}
	ids := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return nil, err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	out := make([]any, 0, len(ids))
	for _, id := range ids {
		item, err := inventory.LoadRequestItemForWarehouse(ctx, s.database, id)
		if err != nil {
			return nil, err
		}
		rendered := renderRequestItem(*item)
		var transit, shipping, reserved int
		if item.ProductVariationID != nil {
			err = s.database.QueryRowContext(ctx, `SELECT COALESCE((SELECT quantity FROM warehouse_stocks WHERE agent_id=? AND stock_type='transit' AND sub_location_id IS NULL AND product_variation_id=? AND product_id IS NULL LIMIT 1),0),
				COALESCE((SELECT quantity FROM warehouse_stocks WHERE agent_id=? AND stock_type='shipping' AND sub_location_id IS NULL AND product_variation_id=? AND product_id IS NULL LIMIT 1),0),
				COALESCE((SELECT quantity_reserved FROM product_variation_stocks WHERE agent_id=? AND product_variation_id=? LIMIT 1),0)`,
				agentID, *item.ProductVariationID, agentID, *item.ProductVariationID, agentID, *item.ProductVariationID).
				Scan(&transit, &shipping, &reserved)
		} else if item.ProductID != nil {
			err = s.database.QueryRowContext(ctx, `SELECT COALESCE((SELECT quantity FROM warehouse_stocks WHERE agent_id=? AND stock_type='transit' AND sub_location_id IS NULL AND product_id=? AND product_variation_id IS NULL LIMIT 1),0),
				COALESCE((SELECT quantity FROM warehouse_stocks WHERE agent_id=? AND stock_type='shipping' AND sub_location_id IS NULL AND product_id=? AND product_variation_id IS NULL LIMIT 1),0),
				COALESCE((SELECT quantity_reserved FROM product_stocks WHERE agent_id=? AND product_id=? LIMIT 1),0)`,
				agentID, *item.ProductID, agentID, *item.ProductID, agentID, *item.ProductID).
				Scan(&transit, &shipping, &reserved)
		}
		if err != nil {
			return nil, err
		}
		rendered["current_stock"] = map[string]int{"transit": transit, "shipping": shipping, "reserved": reserved}
		out = append(out, rendered)
	}
	return out, nil
}

func nullableInt64Value(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

func nullableTimeValue(v sql.NullTime) any {
	if v.Valid {
		return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
	}
	return nil
}

func nullableSQLString(v sql.NullString) any {
	if v.Valid {
		return v.String
	}
	return nil
}
