package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleAdminOrderRefundLedger(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	actor := UserFrom(ctx)
	var agentID int64
	var cancellationStatus sql.NullString
	var gross, paid, total string
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id,cancellation_financial_status,
		CAST(gross_received_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(total_amount AS CHAR)
		FROM orders WHERE id=?`, orderID).Scan(&agentID, &cancellationStatus, &gross, &paid, &total); err != nil {
		if err == sql.ErrNoRows {
			writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	if actor.Role != "super_admin" && (actor.AgentID == nil || *actor.AgentID != agentID) {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		return
	}

	obligations, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_obligations WHERE order_id=? ORDER BY id`, orderID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	reductions, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM order_sales_reductions WHERE order_id=? ORDER BY id`, orderID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	for _, obligation := range obligations {
		id, _ := obligation["id"].(int64)
		attempts, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_attempts WHERE obligation_id=? ORDER BY id DESC`, id)
		if err != nil {
			writeError(w, r, err)
			return
		}
		payouts, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_payouts WHERE obligation_id=? ORDER BY id`, id)
		if err != nil {
			writeError(w, r, err)
			return
		}
		children, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_obligations WHERE parent_obligation_id=? ORDER BY id`, id)
		if err != nil {
			writeError(w, r, err)
			return
		}
		obligation["attempts"], obligation["payouts"], obligation["children"] = attempts, payouts, children
	}

	metrics, err := refundMetricsForRead(ctx, s.database.DB, orderID, gross, paid, total)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{
		"order_id":                      orderID,
		"cancellation_financial_status": nullStringValue(cancellationStatus),
		"metrics_cents":                 metrics,
		"obligations":                   obligations,
		"reductions":                    reductions,
	}, "OK", nil)
}

func queryModelMaps(ctx context.Context, db *sql.DB, query string, args ...any) ([]map[string]any, error) {
	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	columns, err := rows.Columns()
	if err != nil {
		return nil, err
	}
	result := make([]map[string]any, 0)
	for rows.Next() {
		values := make([]any, len(columns))
		dest := make([]any, len(columns))
		for i := range values {
			dest[i] = &values[i]
		}
		if err := rows.Scan(dest...); err != nil {
			return nil, err
		}
		item := make(map[string]any, len(columns))
		for i, name := range columns {
			item[name] = normalizeModelValue(name, values[i])
		}
		result = append(result, item)
	}
	return result, rows.Err()
}

func normalizeModelValue(name string, value any) any {
	switch v := value.(type) {
	case int64:
		switch name {
		case "applied_to_order_total", "affects_paid_amount", "recorded_on_cancelled_obligation":
			return v != 0
		}
		return v
	case []byte:
		text := string(v)
		if strings.HasSuffix(name, "_snapshot") || name == "metadata" || name == "destination_snapshot" || name == "request_payload" || name == "response_payload" {
			var decoded any
			if json.Unmarshal(v, &decoded) == nil {
				return decoded
			}
		}
		return text
	case time.Time:
		return v.UTC().Format("2006-01-02T15:04:05.000000Z")
	default:
		return value
	}
}

func refundMetricsForRead(ctx context.Context, db *sql.DB, orderID int64, gross, paid, total string) (map[string]int64, error) {
	toCents := func(raw string) int64 {
		var whole, fraction int64
		neg := strings.HasPrefix(raw, "-")
		raw = strings.TrimPrefix(raw, "-")
		parts := strings.SplitN(raw, ".", 2)
		_, _ = fmt.Sscan(parts[0], &whole)
		if len(parts) > 1 {
			fractionText := (parts[1] + "00")[:2]
			_, _ = fmt.Sscan(fractionText, &fraction)
		}
		value := whole*100 + fraction
		if neg {
			return -value
		}
		return value
	}
	scalar := func(query string, args ...any) (int64, error) {
		var raw string
		if err := db.QueryRowContext(ctx, query, args...).Scan(&raw); err != nil {
			return 0, err
		}
		return toCents(raw), nil
	}
	applied, err := scalar(`SELECT COALESCE(SUM(amount),0) FROM refund_payouts WHERE order_id=? AND affects_paid_amount=1`, orderID)
	if err != nil {
		return nil, err
	}
	proven, err := scalar(`SELECT COALESCE(SUM(amount),0) FROM refund_payouts WHERE order_id=? AND affects_paid_amount=1 AND verification_status='proven'`, orderID)
	if err != nil {
		return nil, err
	}
	reservedRaw, err := scalar(`SELECT COALESCE(SUM(GREATEST(CAST(o.amount AS DECIMAL(14,2))-COALESCE(p.paid_total,0),0)),0)
		FROM refund_obligations o LEFT JOIN (SELECT obligation_id,SUM(amount) paid_total FROM refund_payouts GROUP BY obligation_id) p ON p.obligation_id=o.id
		WHERE o.order_id=? AND o.status IN ('pending','approved','partially_paid','needs_verification')`, orderID)
	if err != nil {
		return nil, err
	}
	reductions, err := scalar(`SELECT COALESCE(SUM(amount),0) FROM order_sales_reductions WHERE order_id=? AND applied_to_order_total=0 AND reinstated_at IS NULL`, orderID)
	if err != nil {
		return nil, err
	}
	g, p, billable := toCents(gross), toCents(paid), toCents(total)-reductions
	if billable < 0 {
		billable = 0
	}
	max := func(a, b int64) int64 {
		if a > b {
			return a
		}
		return b
	}
	return map[string]int64{"gross_cents": g, "paid_cents": p, "applied_cents": applied, "proven_cents": proven, "unverified_cents": max(0, applied-proven),
		"reserved_cents": reservedRaw, "billable_cents": billable, "outstanding_cents": max(0, billable-p), "overpayment_cents": max(0, p-billable), "available_cents": max(0, p-billable-reservedRaw)}, nil
}
