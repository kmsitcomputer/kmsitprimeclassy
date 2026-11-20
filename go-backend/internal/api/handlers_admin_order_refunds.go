package api

import (
	"database/sql"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleAdminOrderRefundsIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	where := "1=1"
	args := []any{}
	if actor.Role != "super_admin" {
		where = "o.agent_id=?"
		args = append(args, derefInt(actor.AgentID))
	} else if raw := r.URL.Query().Get("agent_id"); raw != "" {
		if id, err := strconv.Atoi(raw); err == nil && id != 0 {
			where = "o.agent_id=?"
			args = append(args, id)
		}
	}
	perPage := httpx.QueryInt(r, "per_page", 15, 1)
	page := httpx.QueryInt(r, "page", 1, 1)
	base := ` FROM order_item_adjustments a JOIN order_items i ON i.id=a.order_item_id
		JOIN orders o ON o.id=i.order_id LEFT JOIN users buyer ON buyer.id=o.konsumen_id
		LEFT JOIN users actor_user ON actor_user.id=a.adjusted_by WHERE ` + where
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*)`+base, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	listArgs := append(append([]any{}, args...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT a.id,i.order_id,o.order_no,COALESCE(buyer.name,o.buyer_name_snapshot),
		i.product_name_snapshot,i.variation_label_snapshot,i.sku_snapshot,a.quantity_reduced,
		CAST(a.refund_amount AS CHAR),a.reason,a.refund_status,actor_user.name,a.created_at`+base+
		` ORDER BY a.created_at DESC,a.id DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, orderID int64
		var orderNo, item, reason, refundStatus, refundAmount string
		var customer, variation, sku, adjustedBy sql.NullString
		var quantity int
		var created sql.NullTime
		if err := rows.Scan(&id, &orderID, &orderNo, &customer, &item, &variation, &sku, &quantity,
			&refundAmount, &reason, &refundStatus, &adjustedBy, &created); err != nil {
			writeError(w, r, err)
			return
		}
		data = append(data, map[string]any{
			"id": id, "order_id": orderID, "order_no": orderNo, "customer_name": nullStringValue(customer),
			"item": item, "variation_label": nullStringValue(variation), "sku": nullStringValue(sku),
			"quantity_reduced": quantity, "refund_amount": refundAmount, "reason": reason,
			"refund_status": refundStatus, "adjusted_by": nullStringValue(adjustedBy),
			"created_at": additionalPaymentTimestamp(created),
		})
	}
	if err := rows.Err(); err != nil {
		writeError(w, r, err)
		return
	}
	last := (total + perPage - 1) / perPage
	if last < 1 {
		last = 1
	}
	httpx.OK(w, data, "OK", map[string]int{"current_page": page, "last_page": last, "total": total})
}
