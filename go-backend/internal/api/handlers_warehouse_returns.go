package api

import (
	"context"
	"database/sql"
	"net/http"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

// handleWarehouseReturns ports ReturnController::warehouseQueue. The queue is item-driven but paginates
// parent returns: a return remains visible while it has at least one approved, uninspected line.
func (s *Server) handleWarehouseReturns(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil || actor.AgentID == nil || actor.Role != "gudang" {
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
	where := `o.agent_id=? AND rr.status='approved' AND EXISTS (
		SELECT 1 FROM return_items ri WHERE ri.return_id=rr.id AND ri.status='approved' AND ri.inspected_at IS NULL
	)`
	args := []any{*actor.AgentID}
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM returns rr JOIN orders o ON o.id=rr.order_id WHERE `+where, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	rows, err := s.database.QueryContext(ctx, `SELECT rr.id,rr.order_id,rr.reason,rr.evidence_path,rr.status,
		CAST(rr.total_refund_amount AS CHAR),rr.reviewed_at,rr.created_at,o.order_no,
		COALESCE(u.name,o.buyer_name_snapshot),rq.name,rv.name
		FROM returns rr JOIN orders o ON o.id=rr.order_id
		LEFT JOIN users u ON u.id=o.konsumen_id LEFT JOIN users rq ON rq.id=rr.requested_by LEFT JOIN users rv ON rv.id=rr.reviewed_by
		WHERE `+where+` ORDER BY rr.created_at DESC LIMIT ? OFFSET ?`, append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	type returnRow struct {
		id, orderID int64
		reason      string
		evidence    sql.NullString
		status      string
		refund      string
		reviewedAt  sql.NullTime
		createdAt   time.Time
		orderNo     string
		customer    sql.NullString
		requested   sql.NullString
		reviewed    sql.NullString
	}
	parents := make([]returnRow, 0, perPage)
	for rows.Next() {
		var item returnRow
		if err := rows.Scan(&item.id, &item.orderID, &item.reason, &item.evidence, &item.status, &item.refund,
			&item.reviewedAt, &item.createdAt, &item.orderNo, &item.customer, &item.requested, &item.reviewed); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		parents = append(parents, item)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		writeError(w, r, err)
		return
	}
	rows.Close()
	data := make([]any, 0, len(parents))
	for _, parent := range parents {
		items, err := s.loadWarehouseReturnItems(ctx, parent.id)
		if err != nil {
			writeError(w, r, err)
			return
		}
		var evidence, reviewedAt, requestedBy, reviewedBy any
		if parent.evidence.Valid && parent.evidence.String != "" {
			evidence = media.URL(parent.evidence.String)
		}
		if parent.reviewedAt.Valid {
			reviewedAt = parent.reviewedAt.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
		}
		if parent.requested.Valid {
			requestedBy = parent.requested.String
		}
		if parent.reviewed.Valid {
			reviewedBy = parent.reviewed.String
		}
		data = append(data, map[string]any{
			"id": parent.id, "order_id": parent.orderID, "order_no": parent.orderNo,
			"customer_name": nullableSQLString(sql.NullString{String: parent.customer.String, Valid: parent.customer.Valid}),
			"reason":        parent.reason, "evidence_url": evidence, "status": parent.status,
			"total_refund_amount": parent.refund, "requested_by": requestedBy, "reviewed_by": reviewedBy,
			"reviewed_at": reviewedAt, "items": items,
			"created_at": parent.createdAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		})
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	httpx.OK(w, data, "OK", map[string]any{"current_page": page, "last_page": lastPage, "total": total})
}

func (s *Server) loadWarehouseReturnItems(ctx context.Context, returnID int64) ([]any, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT ri.id,ri.order_item_id,oi.product_name_snapshot,oi.variation_label_snapshot,
		oi.sku_snapshot,ri.quantity_returned,CAST(ri.refund_amount AS CHAR),ri.restock,ri.status,ri.refund_status,
		ri.condition_note,ri.quantity_received,ri.good_quantity,ri.damaged_quantity,ri.condition_status,
		ri.disposition_status,ri.inspected_at,CAST(ri.pickup_scheduled_for AS CHAR)
		FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id
		WHERE ri.return_id=? AND ri.status='approved' AND ri.inspected_at IS NULL`, returnID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []any{}
	for rows.Next() {
		var id, orderItemID int64
		var productName, variation, sku sql.NullString
		var quantity, received, good, damaged int
		var refund string
		var restock bool
		var status, refundStatus, conditionStatus, disposition string
		var conditionNote, scheduled sql.NullString
		var inspectedAt sql.NullTime
		if err := rows.Scan(&id, &orderItemID, &productName, &variation, &sku, &quantity, &refund, &restock,
			&status, &refundStatus, &conditionNote, &received, &good, &damaged, &conditionStatus,
			&disposition, &inspectedAt, &scheduled); err != nil {
			return nil, err
		}
		var inspected any
		if inspectedAt.Valid {
			inspected = inspectedAt.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
		}
		var scheduledDate any
		if scheduled.Valid {
			scheduledDate = scheduled.String
		}
		out = append(out, map[string]any{
			"id": id, "order_item_id": orderItemID, "product_name": nullableSQLString(productName),
			"variation_label": nullableSQLString(variation), "sku": nullableSQLString(sku),
			"quantity_returned": quantity, "refund_amount": refund, "restock": restock,
			"status": status, "refund_status": refundStatus, "condition_note": nullableSQLString(conditionNote),
			"quantity_received": received, "good_quantity": good, "damaged_quantity": damaged,
			"condition_status": conditionStatus, "disposition_status": disposition,
			"inspected_at": inspected, "pickup_scheduled_for": scheduledDate,
		})
	}
	return out, rows.Err()
}
