package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminAdditionalPaymentStatus(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "payment")
	if !ok {
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM order_additional_payments WHERE id=?`, id).Scan(&orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, notFoundError(ctx))
		} else {
			writeError(w, r, err)
		}
		return
	}
	actor := UserFrom(ctx)
	if actor.Role != "super_admin" {
		var agentID sql.NullInt64
		if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				writeError(w, r, notFoundError(ctx))
			} else {
				writeError(w, r, err)
			}
			return
		}
		if !agentID.Valid || actor.AgentID == nil || agentID.Int64 != *actor.AgentID {
			writeError(w, r, forbiddenError(ctx))
			return
		}
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	locale := LocaleFrom(ctx)
	fields := map[string][]string{}
	raw, present := body["paid"]
	paid, valid := parseLaravelBoolean(raw)
	trimmed := strings.TrimSpace(string(raw))
	if !present || trimmed == "null" || trimmed == `""` {
		fields["paid"] = []string{i18n.ValidationMessage(locale, "required", "paid")}
	} else if !valid {
		fields["paid"] = []string{i18n.ValidationMessage(locale, "boolean", "paid")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	service := s.orderService()
	service.IP, service.UserAgent = clientIP(r), userAgent
	service.Messages = func(key string, _ ...string) string { return i18n.Translate(locale, key) }
	if err := service.MarkAdditionalPaymentPaid(ctx, id, actor.ID, actor.Role, paid); err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	resource, err := s.loadAdminAdditionalPayment(ctx, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, resource, i18n.Translate(locale, "payment.cod_status_updated"), nil)
}

func (s *Server) loadAdminAdditionalPayment(ctx context.Context, id int64) (map[string]any, error) {
	var orderID int64
	var orderNo, method, amount, status string
	var customer, reason, requester, raw sql.NullString
	var created sql.NullTime
	err := s.database.QueryRowContext(ctx, `SELECT ap.order_id,o.order_no,COALESCE(buyer.name,o.buyer_name_snapshot),
		ap.method,CAST(ap.amount AS CHAR),ap.reason,ap.status,requester.name,CAST(pt.raw_payload AS CHAR),ap.created_at
		FROM order_additional_payments ap JOIN orders o ON o.id=ap.order_id
		LEFT JOIN users buyer ON buyer.id=o.konsumen_id
		LEFT JOIN users requester ON requester.id=ap.requested_by
		LEFT JOIN payment_transactions pt ON pt.id=ap.payment_transaction_id WHERE ap.id=?`, id).
		Scan(&orderID, &orderNo, &customer, &method, &amount, &reason, &status, &requester, &raw, &created)
	if err != nil {
		return nil, err
	}
	var instructions any
	if raw.Valid && raw.String != "" {
		if err := json.Unmarshal([]byte(raw.String), &instructions); err != nil {
			return nil, err
		}
	}
	return map[string]any{"id": id, "order_id": orderID, "order_no": orderNo,
		"customer_name": nullStringValue(customer), "method": method, "amount": amount,
		"reason": nullStringValue(reason), "status": status, "requested_by": nullStringValue(requester),
		"payment_instructions": instructions, "created_at": additionalPaymentTimestamp(created)}, nil
}
