package api

import (
	"context"
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

func (s *Server) handleAdminRefundAttemptCancel(w http.ResponseWriter, r *http.Request) {
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
	var reason string
	fields := map[string][]string{}
	value, exists := body["reason"]
	if !exists || string(value) == "null" || string(value) == `""` {
		fields["reason"] = []string{i18n.ValidationMessage(locale, "required", "reason")}
	} else if json.Unmarshal(value, &reason) != nil {
		fields["reason"] = []string{i18n.ValidationMessage(locale, "string", "reason")}
	} else {
		reason = strings.TrimSpace(reason)
		if reason == "" {
			fields["reason"] = []string{i18n.ValidationMessage(locale, "required", "reason")}
		} else if utf8.RuneCountInString(reason) > 255 {
			fields["reason"] = []string{i18n.ValidationMessage(locale, "max.string", "reason", "max", "255")}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	_, actorID, accessErr := s.refundAttemptAccess(ctx, attemptID, locale)
	if accessErr != nil {
		writeError(w, r, accessErr)
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	if err := service.CancelRefundAttempt(ctx, attemptID, actorID, reason); err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	s.writeRefundAttempt(w, r, attemptID)
}

func (s *Server) handleAdminRefundAttemptUnknown(w http.ResponseWriter, r *http.Request) {
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
	var note *string
	fields := map[string][]string{}
	if value, exists := body["note"]; exists && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) != nil {
			fields["note"] = []string{i18n.ValidationMessage(locale, "string", "note")}
		} else {
			text = strings.TrimSpace(text)
			if utf8.RuneCountInString(text) > 255 {
				fields["note"] = []string{i18n.ValidationMessage(locale, "max.string", "note", "max", "255")}
			} else if text != "" {
				note = &text
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	_, actorID, accessErr := s.refundAttemptAccess(ctx, attemptID, locale)
	if accessErr != nil {
		writeError(w, r, accessErr)
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	if err := service.MarkRefundAttemptUnknown(ctx, attemptID, actorID, note); err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	s.writeRefundAttempt(w, r, attemptID)
}

func (s *Server) handleAdminRefundAttemptFail(w http.ResponseWriter, r *http.Request) {
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
	fields := map[string][]string{}
	var code, message string
	for _, field := range []string{"code", "message"} {
		value, exists := body[field]
		if !exists || string(value) == "null" || string(value) == `""` {
			fields[field] = []string{i18n.ValidationMessage(locale, "required", field)}
			continue
		}
		var text string
		if json.Unmarshal(value, &text) != nil {
			fields[field] = []string{i18n.ValidationMessage(locale, "string", field)}
			continue
		}
		text = strings.TrimSpace(text)
		if text == "" {
			fields[field] = []string{i18n.ValidationMessage(locale, "required", field)}
		} else if utf8.RuneCountInString(text) > map[string]int{"code": 60, "message": 255}[field] {
			fields[field] = []string{i18n.ValidationMessage(locale, "max.string", field, "max", strconv.Itoa(map[string]int{"code": 60, "message": 255}[field]))}
		} else if field == "code" {
			code = text
		} else {
			message = text
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	_, actorID, accessErr := s.refundAttemptAccess(ctx, attemptID, locale)
	if accessErr != nil {
		writeError(w, r, accessErr)
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	if err := service.MarkRefundAttemptFailed(ctx, attemptID, actorID, code, message); err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	s.writeRefundAttempt(w, r, attemptID)
}

func (s *Server) handleAdminRefundAttemptComplete(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	attemptID, ok := pathID(w, r, "attempt")
	if !ok {
		return
	}
	_, actorID, accessErr := s.refundAttemptAccess(ctx, attemptID, locale)
	if accessErr != nil {
		writeError(w, r, accessErr)
		return
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	var proofID *int64
	var proofType, gatewayReference *string
	if value, exists := body["proof_media_id"]; exists && string(value) != "null" {
		var n int64
		var parsed bool
		var text string
		if json.Unmarshal(value, &text) == nil {
			n, _ = strconv.ParseInt(text, 10, 64)
			parsed = n > 0
		} else if json.Unmarshal(value, &n) == nil {
			parsed = true
		}
		if !parsed {
			fields["proof_media_id"] = []string{i18n.ValidationMessage(locale, "integer", "proof_media_id")}
		} else {
			var count int
			if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM media WHERE id=?`, n).Scan(&count); err != nil {
				writeError(w, r, err)
				return
			}
			if count == 0 {
				fields["proof_media_id"] = []string{i18n.ValidationMessage(locale, "exists", "proof_media_id")}
			} else {
				proofID = &n
			}
		}
	}
	for _, field := range []struct {
		name string
		max  int
		dest **string
	}{{"proof_type", 60, &proofType}, {"gateway_reference", 191, &gatewayReference}} {
		if value, exists := body[field.name]; exists && string(value) != "null" {
			var text string
			if json.Unmarshal(value, &text) != nil {
				fields[field.name] = []string{i18n.ValidationMessage(locale, "string", field.name)}
				continue
			}
			text = strings.TrimSpace(text)
			if utf8.RuneCountInString(text) > field.max {
				fields[field.name] = []string{i18n.ValidationMessage(locale, "max.string", field.name, "max", strconv.Itoa(field.max))}
			} else if text != "" {
				*field.dest = &text
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	payoutID, err := service.CompleteRefundAttempt(ctx, attemptID, actorID, proofID, proofType, gatewayReference)
	if err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	rows, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_payouts WHERE id=?`, payoutID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	httpx.OK(w, rows[0], "Payout tercatat.", nil)
}

func (s *Server) handleAdminRefundAttemptReconcile(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "attempt")
	if !ok {
		return
	}
	if _, _, accessErr := s.refundAttemptAccess(ctx, id, locale); accessErr != nil {
		writeError(w, r, accessErr)
		return
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	var succeeded bool
	rawSucceeded, hasSucceeded := body["succeeded"]
	if !hasSucceeded || len(rawSucceeded) == 0 || string(rawSucceeded) == "null" {
		fields["succeeded"] = []string{i18n.ValidationMessage(locale, "required", "succeeded")}
	} else if json.Unmarshal(rawSucceeded, &succeeded) != nil {
		var n int
		if json.Unmarshal(rawSucceeded, &n) == nil && (n == 0 || n == 1) {
			succeeded = n == 1
		} else {
			var text string
			if json.Unmarshal(rawSucceeded, &text) == nil && (text == "0" || text == "1") {
				succeeded = text == "1"
			} else {
				fields["succeeded"] = []string{i18n.ValidationMessage(locale, "boolean", "succeeded")}
			}
		}
	}
	rawEvidence, hasEvidence := body["evidence"]
	var evidence string
	if !hasEvidence || string(rawEvidence) == "null" || strings.TrimSpace(string(rawEvidence)) == `""` {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "required", "evidence")}
	} else if json.Unmarshal(rawEvidence, &evidence) != nil {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "string", "evidence")}
	} else if strings.TrimSpace(evidence) == "" {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "required", "evidence")}
	} else if n := utf8.RuneCountInString(evidence); n < 3 {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "min.string", "evidence", "min", "3")}
	} else if n > 2000 {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "max.string", "evidence", "max", "2000")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	isPayout, err := service.ReconcileRefundAttempt(ctx, id, UserFrom(ctx).ID, succeeded, evidence)
	if err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	var rows []map[string]any
	if isPayout {
		var payoutID int64
		if err := s.database.QueryRowContext(ctx, `SELECT id FROM refund_payouts WHERE attempt_id=?`, id).Scan(&payoutID); err != nil {
			writeError(w, r, err)
			return
		}
		rows, err = queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_payouts WHERE id=?`, payoutID)
		if err != nil {
			writeError(w, r, err)
			return
		}
	} else {
		rows, err = queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_attempts WHERE id=?`, id)
		if err != nil {
			writeError(w, r, err)
			return
		}
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	httpx.OK(w, rows[0], "OK", nil)
}

func (s *Server) handleAdminRefundNeedsVerificationResolve(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "obligation")
	if !ok {
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=?`, id).Scan(&orderID); err != nil {
		writeError(w, r, err)
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
	var body struct {
		Exit     string  `json:"exit"`
		Evidence string  `json:"evidence"`
		Reason   *string `json:"reason"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.Exit != "A" && body.Exit != "B" {
		fields["exit"] = []string{i18n.ValidationMessage(locale, "in", "exit", "values", "A, B")}
	}
	if strings.TrimSpace(body.Evidence) == "" {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "required", "evidence")}
	} else if n := utf8.RuneCountInString(body.Evidence); n < 3 {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "min.string", "evidence", "min", "3")}
	} else if n > 2000 {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "max.string", "evidence", "max", "2000")}
	}
	if body.Reason != nil && utf8.RuneCountInString(*body.Reason) > 255 {
		fields["reason"] = []string{i18n.ValidationMessage(locale, "max.string", "reason", "max", "255")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }}
	if err := service.ResolveRefundNeedsVerification(ctx, id, actor.ID, body.Exit, body.Evidence, body.Reason); err != nil {
		s.writeRefundProblem(w, r, err, locale)
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

func (s *Server) handleAdminDeferredMaterializationRequest(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "obligation")
	if !ok {
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=?`, id).Scan(&orderID); err != nil {
		writeError(w, r, err)
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
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	raw, exists := body["amount_cents"]
	var amount int64
	if !exists || string(raw) == "null" || strings.TrimSpace(string(raw)) == "\"\"" {
		fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "required", "amount_cents")}
	} else {
		var text string
		if json.Unmarshal(raw, &text) == nil {
			n, err := strconv.ParseInt(strings.TrimSpace(text), 10, 64)
			if err != nil {
				fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "integer", "amount_cents")}
			} else {
				amount = n
			}
		} else if err := json.Unmarshal(raw, &amount); err != nil {
			fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "integer", "amount_cents")}
		}
		if len(fields) == 0 && amount < 1 {
			fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "min.numeric", "amount_cents", "min", "1")}
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
	if err := service.RequestDeferredMaterialization(ctx, id, amount, actor.ID, "deferred-request:"+key); err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	httpx.OK(w, nil, "Permintaan materialisasi dicatat.", nil)
}

func (s *Server) handleAdminDeferredMaterialize(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "obligation")
	if !ok {
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM refund_obligations WHERE id=?`, id).Scan(&orderID); err != nil {
		writeError(w, r, err)
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
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	raw, exists := body["amount_cents"]
	var amount int64
	if !exists || string(raw) == "null" || strings.TrimSpace(string(raw)) == "\"\"" {
		fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "required", "amount_cents")}
	} else {
		var text string
		if json.Unmarshal(raw, &text) == nil {
			n, e := strconv.ParseInt(strings.TrimSpace(text), 10, 64)
			if e != nil {
				fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "integer", "amount_cents")}
			} else {
				amount = n
			}
		} else if e := json.Unmarshal(raw, &amount); e != nil {
			fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "integer", "amount_cents")}
		}
		if len(fields) == 0 && amount < 1 {
			fields["amount_cents"] = []string{i18n.ValidationMessage(locale, "min.numeric", "amount_cents", "min", "1")}
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
	childID, err := service.MaterializeDeferred(ctx, id, amount, actor.ID, "deferred-materialize:"+key)
	if err != nil {
		s.writeRefundProblem(w, r, err, locale)
		return
	}
	rows, err := queryModelMaps(ctx, s.database.DB, `SELECT * FROM refund_obligations WHERE id=?`, childID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	httpx.Created(w, rows[0], "Deferred dimaterialisasi.")
}

func (s *Server) refundAttemptAccess(ctx context.Context, id int64, locale string) (int64, int64, *httpx.Error) {
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT o.order_id FROM refund_attempts a JOIN refund_obligations o ON o.id=a.obligation_id WHERE a.id=?`, id).Scan(&orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return 0, 0, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "system.endpoint_not_found")}
		}
		return 0, 0, &httpx.Error{Status: 500, Message: "Internal Server Error", Err: err}
	}
	actor := UserFrom(ctx)
	var agentID sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
		return 0, 0, &httpx.Error{Status: 500, Message: "Internal Server Error", Err: err}
	}
	if actor.Role != "super_admin" && (actor.AgentID == nil || !agentID.Valid || *actor.AgentID != agentID.Int64) {
		return 0, 0, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "system.unauthorized_action")}
	}
	if actor.Role != "super_admin" && actor.Role != "keuangan" {
		return 0, 0, &httpx.Error{Status: 403, Message: "Hanya Keuangan yang dapat melakukan aksi ini.", ErrorsAny: map[string]any{"code": "refund_role_forbidden"}}
	}
	return orderID, actor.ID, nil
}

func (s *Server) writeRefundProblem(w http.ResponseWriter, r *http.Request, err error, locale string) {
	var problem *orders.Problem
	if errors.As(err, &problem) {
		writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, FieldStrings: problem.Fields, ErrorsAny: problem.Errors})
	} else if errors.Is(err, sql.ErrNoRows) {
		writeError(w, r, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "system.endpoint_not_found")})
	} else {
		writeError(w, r, err)
	}
}

func (s *Server) writeRefundAttempt(w http.ResponseWriter, r *http.Request, id int64) {
	rows, err := queryModelMaps(r.Context(), s.database.DB, `SELECT * FROM refund_attempts WHERE id=?`, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(rows) == 0 {
		writeError(w, r, &httpx.Error{Status: 404, Message: msg(r.Context(), "system.endpoint_not_found")})
		return
	}
	httpx.OK(w, rows[0], "OK", nil)
}
