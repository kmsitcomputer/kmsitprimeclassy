package api

import (
	"database/sql"
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminCancellationFinalize(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	actor := UserFrom(ctx)
	var agentID sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	if actor.Role != "super_admin" && (actor.AgentID == nil || !agentID.Valid || *actor.AgentID != agentID.Int64) {
		writeError(w, r, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "system.unauthorized_action")})
		return
	}
	if actor.Role != "super_admin" && actor.Role != "keuangan" {
		writeError(w, r, &httpx.Error{Status: 403, Message: "Hanya Keuangan yang dapat melakukan aksi ini.", ErrorsAny: map[string]any{"code": "refund_role_forbidden"}})
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }, IP: clientIP(r), UserAgent: r.UserAgent()}
	if err := service.FinalizeCancellation(ctx, orderID, actor.ID); err != nil {
		var problem *orders.Problem
		if errors.As(err, &problem) {
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, FieldStrings: problem.Fields, ErrorsAny: problem.Errors})
		} else if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	rows, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM orders WHERE id=?`, orderID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.not_found")})
		return
	}
	for _, field := range []string{"latitude_snapshot", "longitude_snapshot"} {
		if value, ok := rows[0][field].(float64); ok {
			rows[0][field] = strconv.FormatFloat(value, 'f', 7, 64)
		}
	}
	httpx.OK(w, rows[0], "OK", nil)
}
