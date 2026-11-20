package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminOrderSalesReductionReinstate(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "reduction")
	if !ok {
		return
	}
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	var evidence string
	value, exists := raw["evidence"]
	if !exists || string(value) == "null" {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "required", "evidence")}
	} else if json.Unmarshal(value, &evidence) != nil {
		fields["evidence"] = []string{i18n.ValidationMessage(locale, "string", "evidence")}
	} else {
		evidence = strings.TrimSpace(evidence)
		switch {
		case evidence == "":
			fields["evidence"] = []string{i18n.ValidationMessage(locale, "required", "evidence")}
		case utf8.RuneCountInString(evidence) < 3:
			fields["evidence"] = []string{i18n.ValidationMessage(locale, "min.string", "evidence", "min", "3")}
		case utf8.RuneCountInString(evidence) > 2000:
			fields["evidence"] = []string{i18n.ValidationMessage(locale, "max.string", "evidence", "max", "2000")}
		}
	}
	var reason *string
	if value, exists := raw["reason"]; exists && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) != nil {
			fields["reason"] = []string{i18n.ValidationMessage(locale, "string", "reason")}
		} else {
			text = strings.TrimSpace(text)
			if utf8.RuneCountInString(text) > 255 {
				fields["reason"] = []string{i18n.ValidationMessage(locale, "max.string", "reason", "max", "255")}
			} else if text != "" {
				reason = &text
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	actor := UserFrom(ctx)
	service := &orders.Service{Database: s.database, Messages: func(key string, _ ...string) string { return i18n.Translate(locale, key) }, IP: clientIP(r), UserAgent: r.UserAgent()}
	if err := service.ReinstateReduction(ctx, id, actor.ID, evidence, reason); err != nil {
		var problem *orders.Problem
		if errors.As(err, &problem) {
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, ErrorsAny: problem.Errors})
		} else if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	var orderID int64
	var sourceType, amount string
	var sourceID, reinstatedBy sql.NullInt64
	var applied bool
	var reinstatedAt sql.NullTime
	var reinstatementReason, reconciliationRef, provenance sql.NullString
	var created, updated sql.NullTime
	err := s.database.QueryRowContext(ctx, `SELECT order_id,source_type,source_id,CAST(amount AS CHAR),applied_to_order_total,reinstated_at,reinstated_by,reinstatement_reason,reinstatement_reconciliation_ref,provenance,created_at,updated_at FROM order_sales_reductions WHERE id=?`, id).Scan(&orderID, &sourceType, &sourceID, &amount, &applied, &reinstatedAt, &reinstatedBy, &reinstatementReason, &reconciliationRef, &provenance, &created, &updated)
	if err != nil {
		writeError(w, r, err)
		return
	}
	data := map[string]any{
		"id": id, "order_id": orderID, "source_type": sourceType, "source_id": nullInt64Value(sourceID),
		"amount": amount, "applied_to_order_total": applied,
		"reinstated_at": additionalPaymentTimestamp(reinstatedAt), "reinstated_by": nullInt64Value(reinstatedBy),
		"reinstatement_reason":             nullStringValue(reinstatementReason),
		"reinstatement_reconciliation_ref": nullStringValue(reconciliationRef),
		"provenance":                       nullStringValue(provenance), "created_at": additionalPaymentTimestamp(created),
		"updated_at": additionalPaymentTimestamp(updated),
	}
	httpx.OK(w, data, "OK", nil)
}
