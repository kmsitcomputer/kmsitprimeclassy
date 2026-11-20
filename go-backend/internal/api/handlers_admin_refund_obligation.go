package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"unicode/utf8"

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

func (s *Server) handleAdminRefundObligationCreateAttempt(w http.ResponseWriter, r *http.Request) {
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
	if actor.Role != "super_admin" && actor.Role != "keuangan" {
		writeError(w, r, &httpx.Error{Status: 403, Message: "Hanya Keuangan yang dapat melakukan aksi ini.", ErrorsAny: map[string]any{"code": "refund_role_forbidden"}})
		return
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	var amount int64
	rawAmount, exists := body["amount_cents"]
	if !exists || string(rawAmount) == "null" {
		fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "required", "amount_cents")}
	} else {
		var amountText string
		if json.Unmarshal(rawAmount, &amountText) == nil {
			amount, _ = strconv.ParseInt(amountText, 10, 64)
		} else {
			_ = json.Unmarshal(rawAmount, &amount)
		}
		if amount <= 0 {
			if amount == 0 && string(rawAmount) != "0" && string(rawAmount) != `"0"` {
				fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "integer", "amount_cents")}
			} else {
				fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "min.numeric", "amount_cents", "min", "1")}
			}
		}
	}
	var method string
	methodRaw, methodExists := body["method"]
	if !methodExists || string(methodRaw) == "null" || string(methodRaw) == `""` {
		fields["method"] = []string{i18n.ValidationMessage(locale, "required", "method")}
	} else if json.Unmarshal(methodRaw, &method) != nil {
		fields["method"] = []string{i18n.ValidationMessage(locale, "string", "method")}
	} else if method != "bank_transfer" && method != "gateway" && method != "cash" {
		fields["method"] = []string{i18n.ValidationMessage(locale, "in", "method")}
	}
	var external *string
	if value, exists := body["external_reference"]; exists && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) != nil {
			fields["external_reference"] = []string{i18n.ValidationMessage(locale, "string", "external_reference")}
		} else {
			text = strings.TrimSpace(text)
			if utf8.RuneCountInString(text) > 191 {
				fields["external_reference"] = []string{i18n.ValidationMessage(locale, "max.string", "external_reference", "max", "191")}
			} else if text != "" {
				external = &text
			}
		}
	}
	var destination any
	if value, exists := body["destination_snapshot"]; exists && string(value) != "null" {
		if json.Unmarshal(value, &destination) != nil {
			fields["destination_snapshot"] = []string{i18n.ValidationMessage(locale, "array", "destination_snapshot")}
		} else {
			switch destination.(type) {
			case map[string]any, []any:
			default:
				fields["destination_snapshot"] = []string{i18n.ValidationMessage(locale, "array", "destination_snapshot")}
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	key := strings.TrimSpace(r.Header.Get("Idempotency-Key"))
	if key == "" {
		writeError(w, r, &httpx.Error{Status: 422, Message: "Header Idempotency-Key wajib untuk aksi ini.", ErrorsAny: map[string]any{"code": "idempotency_key_required"}})
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	attemptID, err := service.CreateRefundAttempt(ctx, id, actor.ID, orders.CreateRefundAttemptInput{AmountCents: amount, Method: method, ExternalReference: external, DestinationSnapshot: destination, IdempotencyKey: "attempt:" + key})
	if err != nil {
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
	rows, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_attempts WHERE id=?`, attemptID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	httpx.Created(w, rows[0], "Attempt dibuat.")
}

func (s *Server) handleAdminRefundAttemptInitiate(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	attemptID, ok := pathID(w, r, "attempt")
	if !ok {
		return
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	var external *string
	fields := map[string][]string{}
	if value, exists := body["external_reference"]; exists && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) != nil {
			fields["external_reference"] = []string{i18n.ValidationMessage(locale, "string", "external_reference")}
		} else {
			text = strings.TrimSpace(text)
			if utf8.RuneCountInString(text) > 191 {
				fields["external_reference"] = []string{i18n.ValidationMessage(locale, "max.string", "external_reference", "max", "191")}
			} else if text != "" {
				external = &text
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT o.order_id FROM refund_attempts a JOIN refund_obligations o ON o.id=a.obligation_id WHERE a.id=?`, attemptID).Scan(&orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	actor := UserFrom(ctx)
	var agentID sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
		writeError(w, r, err)
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
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	if err := service.InitiateRefundAttempt(ctx, attemptID, actor.ID, external); err != nil {
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
	rows, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_attempts WHERE id=?`, attemptID)
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
