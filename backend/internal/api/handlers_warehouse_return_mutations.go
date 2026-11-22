package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

type warehouseReturnInspectInput struct {
	Received int64
	Good     int64
	Damaged  int64
	Note     *string
}

// handleWarehouseReturnInspect mirrors ReturnController::inspect and ReturnService::inspectReturn.
func (s *Server) handleWarehouseReturnInspect(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil || actor.AgentID == nil || actor.Role != "gudang" {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}
	branchID, branchErr := s.returnItemBranch(ctx, itemID)
	if errors.Is(branchErr, sql.ErrNoRows) {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	if branchErr != nil {
		writeError(w, r, branchErr)
		return
	}
	if branchID != *actor.AgentID {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	input, fields, err := decodeWarehouseReturnInspect(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}
	result, err := s.inspectWarehouseReturn(ctx, itemID, actor.ID, *actor.AgentID, input)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func decodeWarehouseReturnInspect(r *http.Request) (warehouseReturnInspectInput, map[string][]string, error) {
	var raw map[string]json.RawMessage
	if err := json.NewDecoder(r.Body).Decode(&raw); err != nil && !errors.Is(err, io.EOF) {
		return warehouseReturnInspectInput{}, nil, &httpx.Error{Status: http.StatusBadRequest, Message: "Invalid JSON."}
	}
	if raw == nil {
		raw = map[string]json.RawMessage{}
	}
	ctx := r.Context()
	input := warehouseReturnInspectInput{}
	fields := map[string][]string{}
	values := []struct {
		name string
		dst  *int64
	}{{"received_quantity", &input.Received}, {"good_quantity", &input.Good}, {"damaged_quantity", &input.Damaged}}
	for _, field := range values {
		value, exists := raw[field.name]
		if !exists || string(value) == "null" {
			fields[field.name] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required", field.name)}
			continue
		}
		number := strings.TrimSpace(string(value))
		parsed, err := strconv.ParseInt(number, 10, 64)
		if err != nil {
			var numericString string
			if json.Unmarshal(value, &numericString) == nil {
				parsed, err = strconv.ParseInt(strings.TrimSpace(numericString), 10, 64)
			}
		}
		if err != nil {
			fields[field.name] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", field.name)}
			continue
		}
		*field.dst = parsed
		if parsed < 0 {
			fields[field.name] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", field.name, "min", "0")}
		}
	}
	if noteRaw, exists := raw["note"]; exists && string(noteRaw) != "null" {
		var note string
		if err := json.Unmarshal(noteRaw, &note); err != nil {
			fields["note"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "string", "note")}
		} else if utf8.RuneCountInString(note) > 255 {
			fields["note"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "255")}
		} else {
			input.Note = &note
		}
	}
	return input, fields, nil
}

func (s *Server) returnItemBranch(ctx context.Context, itemID int64) (int64, error) {
	var orderAgentID int64
	err := s.database.QueryRowContext(ctx, `SELECT o.agent_id FROM return_items ri
		JOIN order_items oi ON oi.id=ri.order_item_id JOIN orders o ON o.id=oi.order_id WHERE ri.id=?`, itemID).Scan(&orderAgentID)
	return orderAgentID, err
}

func (s *Server) inspectWarehouseReturn(ctx context.Context, itemID, actorID, agentID int64, in warehouseReturnInspectInput) (map[string]any, error) {
	tx, err := s.database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var returnID, orderItemID int64
	var quantity int64
	var status string
	var inspected sql.NullTime
	err = tx.QueryRowContext(ctx, `SELECT ri.return_id,ri.order_item_id,ri.quantity_returned,ri.status,ri.inspected_at
		FROM return_items ri WHERE ri.id=? FOR UPDATE`, itemID).Scan(&returnID, &orderItemID, &quantity, &status, &inspected)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")}
	}
	if err != nil {
		return nil, err
	}
	var orderID, orderAgentID int64
	if err = tx.QueryRowContext(ctx, `SELECT order_id FROM order_items WHERE id=? FOR UPDATE`, orderItemID).Scan(&orderID); err != nil {
		return nil, err
	}
	if err = tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&orderAgentID); err != nil {
		return nil, err
	}
	if orderAgentID != agentID {
		return nil, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")}
	}
	if status != "approved" || inspected.Valid {
		return nil, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: "Return belum siap diinspeksi atau sudah diproses."}
	}
	if in.Received < 0 || in.Good < 0 || in.Damaged < 0 || in.Good > in.Received || in.Damaged != in.Received-in.Good || in.Received > quantity {
		return nil, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: "Jumlah inspeksi return tidak valid."}
	}
	if _, err = tx.ExecContext(ctx, `UPDATE return_items SET quantity_received=?,good_quantity=?,damaged_quantity=?,
		condition_status=?,condition_note=?,inspected_by=?,inspected_at=UTC_TIMESTAMP(),disposition_status='pending_disposition',updated_at=UTC_TIMESTAMP()
		WHERE id=?`, in.Received, in.Good, in.Damaged, conditionFor(in.Good, in.Damaged), nullableString(in.Note), actorID, itemID); err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return s.loadWarehouseReturnItem(ctx, itemID)
}

func conditionFor(good, damaged int64) string {
	if good > 0 && damaged > 0 {
		return "mixed"
	}
	if damaged > 0 {
		return "damaged"
	}
	return "good"
}

func (s *Server) handleWarehouseReturnFinalize(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil || actor.AgentID == nil || actor.Role != "admin" {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}
	branchID, branchErr := s.returnItemBranch(ctx, itemID)
	if errors.Is(branchErr, sql.ErrNoRows) {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	if branchErr != nil {
		writeError(w, r, branchErr)
		return
	}
	if branchID != *actor.AgentID {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	result, err := s.finalizeWarehouseReturn(ctx, itemID, actor.ID, *actor.AgentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) finalizeWarehouseReturn(ctx context.Context, itemID, actorID, agentID int64) (map[string]any, error) {
	tx, err := s.database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var returnID, orderItemID int64
	var status, disposition, stockSource string
	var inspected sql.NullTime
	var good int64
	err = tx.QueryRowContext(ctx, `SELECT ri.return_id,ri.order_item_id,ri.status,ri.disposition_status,ri.inspected_at,ri.good_quantity
		FROM return_items ri WHERE ri.id=? FOR UPDATE`, itemID).Scan(&returnID, &orderItemID, &status, &disposition, &inspected, &good)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")}
	}
	if err != nil {
		return nil, err
	}
	if disposition == "restocked" || disposition == "damaged_confirmed" {
		if err := tx.Commit(); err != nil {
			return nil, err
		}
		return s.loadWarehouseReturnItem(ctx, itemID)
	}
	if status != "approved" || disposition != "pending_disposition" || !inspected.Valid {
		return nil, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: "Return belum siap difinalisasi."}
	}
	var orderID, orderAgentID int64
	var productID, variationID, subLocationID sql.NullInt64
	if err = tx.QueryRowContext(ctx, `SELECT order_id,stock_source,product_id,product_variation_id,sub_location_id
		FROM order_items WHERE id=? FOR UPDATE`, orderItemID).
		Scan(&orderID, &stockSource, &productID, &variationID, &subLocationID); err != nil {
		return nil, err
	}
	if err = tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=? FOR UPDATE`, orderID).Scan(&orderAgentID); err != nil {
		return nil, err
	}
	if orderAgentID != agentID {
		return nil, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.unauthorized_action")}
	}
	if good > 0 {
		if stockSource == "sub" {
			if err := restockWarehouseSubReturn(ctx, tx, itemID, actorID, orderAgentID, productID, variationID, subLocationID, good); err != nil {
				return nil, err
			}
		} else {
			if err := restockWarehouseAgentReturn(ctx, tx, itemID, actorID, orderAgentID, productID, variationID, good); err != nil {
				return nil, err
			}
		}
	}
	newDisposition := "damaged_confirmed"
	if good > 0 {
		newDisposition = "restocked"
	}
	if good > 0 {
		_, err = tx.ExecContext(ctx, `UPDATE return_items SET disposition_status=?,restock_processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?`, newDisposition, itemID)
	} else {
		_, err = tx.ExecContext(ctx, `UPDATE return_items SET disposition_status=?,restock_processed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?`, newDisposition, itemID)
	}
	if err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return s.loadWarehouseReturnItem(ctx, itemID)
}

func restockWarehouseAgentReturn(ctx context.Context, tx *sql.Tx, returnItemID, actorID, agentID int64, productID, variationID sql.NullInt64, quantity int64) error {
	var stockID int64
	var before int64
	err := tx.QueryRowContext(ctx, `SELECT id,quantity FROM warehouse_stocks WHERE agent_id=? AND stock_type='transit' AND sub_location_id IS NULL
		AND product_id <=> ? AND product_variation_id <=> ? FOR UPDATE`, agentID, nullableInt64Value(productID), nullableInt64Value(variationID)).Scan(&stockID, &before)
	if errors.Is(err, sql.ErrNoRows) {
		var p, v any
		if productID.Valid && !variationID.Valid {
			p = productID.Int64
		}
		if variationID.Valid {
			v = variationID.Int64
		}
		result, insertErr := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks (agent_id,product_id,product_variation_id,stock_type,sub_location_id,quantity,created_at,updated_at)
			VALUES (?,?,?,'transit',NULL,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())`, agentID, p, v)
		if insertErr != nil {
			return insertErr
		}
		stockID, err = result.LastInsertId()
		before = 0
	} else if err != nil {
		return err
	}
	if _, err = tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity=quantity+?,updated_at=UTC_TIMESTAMP() WHERE id=?`, quantity, stockID); err != nil {
		return err
	}
	var p, v any
	if productID.Valid && !variationID.Valid {
		p = productID.Int64
	}
	if variationID.Valid {
		v = variationID.Int64
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO stock_movements (agent_id,product_id,product_variation_id,type,stock_type,quantity,reference_type,reference_id,created_by,note,created_at)
		VALUES (?,?,?,'return_restock','transit',?,'App\\Models\\ReturnItem',?,?,?,UTC_TIMESTAMP())`, agentID, p, v, quantity,
		returnItemID, actorID, "before="+strconv.FormatInt(before, 10)+";after="+strconv.FormatInt(before+quantity, 10))
	return err
}

func restockWarehouseSubReturn(ctx context.Context, tx *sql.Tx, returnItemID, actorID, agentID int64, productID, variationID, subLocationID sql.NullInt64, quantity int64) error {
	if !subLocationID.Valid {
		return &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "return.sub_location_unavailable")}
	}
	var active bool
	err := tx.QueryRowContext(ctx, `SELECT is_active FROM warehouse_sub_locations WHERE id=? FOR UPDATE`, subLocationID.Int64).Scan(&active)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && !active) {
		return &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "return.sub_location_unavailable")}
	}
	if err != nil {
		return err
	}
	var stockID, before int64
	err = tx.QueryRowContext(ctx, `SELECT id,quantity FROM warehouse_stocks WHERE stock_type='sub' AND sub_location_id=?
		AND product_id <=> ? AND product_variation_id <=> ? FOR UPDATE`, subLocationID.Int64,
		nullableInt64Value(productID), nullableInt64Value(variationID)).Scan(&stockID, &before)
	if errors.Is(err, sql.ErrNoRows) {
		var p, v any
		if productID.Valid && !variationID.Valid {
			p = productID.Int64
		}
		if variationID.Valid {
			v = variationID.Int64
		}
		result, insertErr := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks (agent_id,product_id,product_variation_id,stock_type,sub_location_id,quantity,created_at,updated_at)
			VALUES (?,?,?,'sub',?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())`, agentID, p, v, subLocationID.Int64)
		if insertErr != nil {
			return insertErr
		}
		stockID, err = result.LastInsertId()
		before = 0
	} else if err != nil {
		return err
	}
	if _, err = tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity=quantity+?,updated_at=UTC_TIMESTAMP() WHERE id=?`, quantity, stockID); err != nil {
		return err
	}
	var p, v any
	if productID.Valid && !variationID.Valid {
		p = productID.Int64
	}
	if variationID.Valid {
		v = variationID.Int64
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO stock_movements (agent_id,product_id,product_variation_id,type,stock_type,sub_location_id,quantity,reference_type,reference_id,created_by,note,created_at)
		VALUES (?,?,?,'return_restock','sub',?,?, 'App\\Models\\ReturnItem',?,?,?,UTC_TIMESTAMP())`, agentID, p, v, subLocationID.Int64, quantity,
		returnItemID, actorID, "before="+strconv.FormatInt(before, 10)+";after="+strconv.FormatInt(before+quantity, 10))
	return err
}

func (s *Server) loadWarehouseReturnItem(ctx context.Context, itemID int64) (map[string]any, error) {
	var id, returnID, orderItemID, received, good, damaged int64
	var courierID, inspectedBy sql.NullInt64
	var scheduled, conditionNote sql.NullString
	var quantity int64
	var refund string
	var restock bool
	var status, refundStatus, condition, disposition string
	var inspectedAt, processedAt, createdAt, updatedAt sql.NullTime
	err := s.database.QueryRowContext(ctx, `SELECT id,return_id,order_item_id,courier_id,CAST(pickup_scheduled_for AS CHAR),quantity_returned,
		quantity_received,good_quantity,damaged_quantity,condition_status,inspected_by,inspected_at,restock_processed_at,refund_amount,
		restock,condition_note,status,refund_status,disposition_status,created_at,updated_at
		FROM return_items WHERE id=?`, itemID).Scan(&id, &returnID, &orderItemID, &courierID, &scheduled, &quantity,
		&received, &good, &damaged, &condition, &inspectedBy, &inspectedAt, &processedAt, &refund, &restock,
		&conditionNote, &status, &refundStatus, &disposition, &createdAt, &updatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")}
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "return_id": returnID, "order_item_id": orderItemID, "courier_id": nullableInt64Value(courierID),
		"pickup_scheduled_for": nullableSQLString(scheduled), "quantity_returned": quantity, "quantity_received": received,
		"good_quantity": good, "damaged_quantity": damaged, "condition_status": condition, "inspected_by": nullableInt64Value(inspectedBy),
		"inspected_at": nullableTimeValue(inspectedAt), "restock_processed_at": nullableTimeValue(processedAt),
		"refund_amount": refund, "restock": restock, "condition_note": nullableSQLString(conditionNote), "status": status,
		"refund_status": refundStatus, "disposition_status": disposition,
		"created_at": nullableTimeValue(createdAt), "updated_at": nullableTimeValue(updatedAt),
	}, nil
}
