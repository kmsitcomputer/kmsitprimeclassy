package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminReturnReview(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	returnID, ok := pathID(w, r, "return")
	if !ok {
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM returns WHERE id=?`, returnID).Scan(&orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, notFoundError(ctx))
		} else {
			writeError(w, r, err)
		}
		return
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	locale := LocaleFrom(ctx)
	fields := map[string][]string{}
	approvedRaw, present := body["approved"]
	approved, validApproved := parseLaravelBoolean(approvedRaw)
	if !present || strings.TrimSpace(string(approvedRaw)) == "null" || strings.TrimSpace(string(approvedRaw)) == `""` {
		fields["approved"] = []string{i18n.ValidationMessage(locale, "required", "approved")}
	} else if !validApproved {
		fields["approved"] = []string{i18n.ValidationMessage(locale, "boolean", "approved")}
	}
	var note *string
	if raw, present := body["note"]; present && strings.TrimSpace(string(raw)) != "null" {
		var value string
		if err := json.Unmarshal(raw, &value); err != nil {
			fields["note"] = []string{i18n.ValidationMessage(locale, "string", "note")}
		} else {
			if len([]rune(value)) > 255 {
				fields["note"] = []string{i18n.ValidationMessage(locale, "max.string", "note", "max", "255")}
			}
			note = &value
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
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
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) },
		IP: clientIP(r), UserAgent: userAgent}
	if err := service.ReviewReturn(ctx, orders.ReturnReviewInput{ReturnID: returnID, ActorID: actor.ID,
		ActorRole: actor.Role, Approved: approved, Note: note}); err != nil {
		var problem *orders.Problem
		if errors.As(err, &problem) {
			writeError(w, r, httpx.NewError(problem.Status, problem.Message))
		} else {
			writeError(w, r, err)
		}
		return
	}
	resource, err := orders.LoadReturnRequestResource(ctx, s.database, returnID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, resource, i18n.Translate(locale, "return.reviewed"), nil)
}
