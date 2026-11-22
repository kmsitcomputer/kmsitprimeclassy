package api

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleAdminAdditionalPaymentsIndex(w http.ResponseWriter, r *http.Request) {
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
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM order_additional_payments ap
		JOIN orders o ON o.id=ap.order_id WHERE `+where, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	listArgs := append(append([]any{}, args...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT ap.id,ap.order_id,o.order_no,COALESCE(buyer.name,o.buyer_name_snapshot),
		ap.method,CAST(ap.amount AS CHAR),ap.reason,ap.status,requester.name,CAST(pt.raw_payload AS CHAR),ap.created_at
		FROM order_additional_payments ap JOIN orders o ON o.id=ap.order_id
		LEFT JOIN users buyer ON buyer.id=o.konsumen_id
		LEFT JOIN users requester ON requester.id=ap.requested_by
		LEFT JOIN payment_transactions pt ON pt.id=ap.payment_transaction_id
		WHERE `+where+` ORDER BY ap.created_at DESC,ap.id DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, orderID int64
		var orderNo, method, amount, status string
		var customer, reason, requester, payload sql.NullString
		var created sql.NullTime
		if err := rows.Scan(&id, &orderID, &orderNo, &customer, &method, &amount, &reason, &status, &requester, &payload, &created); err != nil {
			writeError(w, r, err)
			return
		}
		var instructions any
		if payload.Valid && payload.String != "" {
			if err := json.Unmarshal([]byte(payload.String), &instructions); err != nil {
				writeError(w, r, err)
				return
			}
		}
		data = append(data, map[string]any{
			"id": id, "order_id": orderID, "order_no": orderNo, "customer_name": nullStringValue(customer),
			"method": method, "amount": amount, "reason": nullStringValue(reason), "status": status,
			"requested_by": nullStringValue(requester), "payment_instructions": instructions,
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

func nullStringValue(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func additionalPaymentTimestamp(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}
