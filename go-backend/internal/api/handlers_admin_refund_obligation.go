package api

import (
	"database/sql"
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminRefundObligationApprove(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "obligation")
	if !ok {
		return
	}
	actor := UserFrom(ctx)
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=?`, id).Scan(&orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	var agentID sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
		writeError(w, r, err)
		return
	}
	if actor.Role != "super_admin" && (actor.AgentID == nil || !agentID.Valid || *actor.AgentID != agentID.Int64) {
		writeError(w, r, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "system.unauthorized_action")})
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	if err := service.ApproveRefundObligation(ctx, id, actor.ID); err != nil {
		var problem *orders.Problem
		if errors.As(err, &problem) {
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, FieldStrings: problem.Fields, ErrorsAny: problem.Errors})
		} else if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	rows, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_obligations WHERE id=?`, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	httpx.OK(w, rows[0], "OK", nil)
}
