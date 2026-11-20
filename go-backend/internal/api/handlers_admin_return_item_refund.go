package api

import (
	"database/sql"
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminReturnItemRefund(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}
	var returnID, orderID int64
	var agentID sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT ri.return_id,r.order_id,o.agent_id FROM return_items ri
		JOIN returns r ON r.id=ri.return_id JOIN orders o ON o.id=r.order_id WHERE ri.id=?`, itemID).
		Scan(&returnID, &orderID, &agentID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, notFoundError(ctx))
		} else {
			writeError(w, r, err)
		}
		return
	}
	actor := UserFrom(ctx)
	if actor.Role != "super_admin" && (!agentID.Valid || actor.AgentID == nil || agentID.Int64 != *actor.AgentID) {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	locale := LocaleFrom(ctx)
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) },
		IP: clientIP(r), UserAgent: userAgent}
	if err := service.MarkReturnItemRefunded(ctx, itemID, actor.ID, actor.Role); err != nil {
		var problem *orders.Problem
		if errors.As(err, &problem) {
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, ErrorsAny: problem.Errors})
		} else {
			writeError(w, r, err)
		}
		return
	}
	resource, err := s.loadWarehouseReturnItem(ctx, itemID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, resource, i18n.Translate(locale, "return.refund_marked"), nil)
}
