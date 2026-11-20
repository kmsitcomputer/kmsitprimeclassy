package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminOrderRefundStatus(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "adjustment")
	if !ok {
		return
	}
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var status string
	field := map[string][]string{}
	v, exists := raw["refund_status"]
	if !exists || string(v) == "null" {
		field["refund_status"] = []string{i18n.ValidationMessage(locale, "required", "refund_status")}
	} else if json.Unmarshal(v, &status) != nil {
		field["refund_status"] = []string{i18n.ValidationMessage(locale, "string", "refund_status")}
	} else if status != "pending" && status != "processed" && status != "failed" {
		field["refund_status"] = []string{i18n.ValidationMessage(locale, "in", "refund_status", "values", "pending, processed, failed")}
	}
	if len(field) > 0 {
		writeError(w, r, httpx.Validation(field))
		return
	}
	var agentID sql.NullInt64
	err := s.database.QueryRowContext(ctx, `SELECT o.agent_id FROM order_item_adjustments a JOIN order_items i ON i.id=a.order_item_id JOIN orders o ON o.id=i.order_id WHERE a.id=?`, id).Scan(&agentID)
	if err == sql.ErrNoRows {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.not_found")})
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	actor := UserFrom(ctx)
	if actor.Role != "super_admin" && (!agentID.Valid || actor.AgentID == nil || agentID.Int64 != *actor.AgentID) {
		writeError(w, r, &httpx.Error{Status: 403, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, params ...string) string { return i18n.Translate(locale, key) }, IP: clientIP(r), UserAgent: r.UserAgent()}
	if err = service.MarkAdjustmentRefundStatus(ctx, id, actor.ID, actor.Role, status); err != nil {
		var problem *orders.Problem
		if errors.As(err, &problem) {
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, ErrorsAny: problem.Errors})
		} else {
			writeError(w, r, err)
		}
		return
	}
	var orderID int64
	var orderNo, item, reason, refundAmount, refundStatus string
	var customer, variation, sku, adjustedBy sql.NullString
	var quantity int
	var created sql.NullTime
	err = s.database.QueryRowContext(ctx, `SELECT o.id,o.order_no,COALESCE(buyer.name,o.buyer_name_snapshot),i.product_name_snapshot,i.variation_label_snapshot,i.sku_snapshot,a.quantity_reduced,CAST(a.refund_amount AS CHAR),a.reason,a.refund_status,actor_user.name,a.created_at FROM order_item_adjustments a JOIN order_items i ON i.id=a.order_item_id JOIN orders o ON o.id=i.order_id LEFT JOIN users buyer ON buyer.id=o.konsumen_id LEFT JOIN users actor_user ON actor_user.id=a.adjusted_by WHERE a.id=?`, id).Scan(&orderID, &orderNo, &customer, &item, &variation, &sku, &quantity, &refundAmount, &reason, &refundStatus, &adjustedBy, &created)
	if err != nil {
		writeError(w, r, err)
		return
	}
	data := map[string]any{"id": id, "order_id": orderID, "order_no": orderNo, "customer_name": nullStringValue(customer), "item": item, "variation_label": nullStringValue(variation), "sku": nullStringValue(sku), "quantity_reduced": quantity, "refund_amount": refundAmount, "reason": reason, "refund_status": refundStatus, "adjusted_by": nullStringValue(adjustedBy), "created_at": additionalPaymentTimestamp(created)}
	httpx.OK(w, data, i18n.Translate(locale, "return.refund_marked"), nil)
}
