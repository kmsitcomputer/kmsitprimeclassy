package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleReturnRequestStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	var bound int
	if actor.AgentID != nil {
		if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders WHERE id=? AND agent_id=?`, orderID, *actor.AgentID).Scan(&bound); err != nil {
			writeError(w, r, err)
			return
		}
	} else if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders WHERE id=?`, orderID).Scan(&bound); err != nil {
		writeError(w, r, err)
		return
	}
	if bound == 0 {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}

	fields, form, evidence := parseReturnRequestForm(w, r)
	if fields == nil {
		return
	}
	loc := LocaleFrom(ctx)
	reason := strings.TrimSpace(form["reason"])
	if reason == "" {
		fields["reason"] = []string{i18n.ValidationMessage(loc, "required", "reason")}
	} else if len([]rune(reason)) > 1000 {
		fields["reason"] = []string{i18n.ValidationMessage(loc, "max.string", "reason", "max", "1000")}
	}
	if evidence == nil {
		fields["evidence"] = []string{i18n.ValidationMessage(loc, "required", "evidence")}
	} else if len(evidence) > 5120*1024 {
		fields["evidence"] = []string{i18n.ValidationMessage(loc, "max.file", "evidence", "max", "5120")}
	} else if _, valid := assistedReturnImageExtension(evidence); !valid {
		fields["evidence"] = []string{i18n.ValidationMessage(loc, "mimes", "evidence", "values", "jpg,jpeg,png,webp")}
	}
	lines, itemErrors := parseAssistedReturnItems(form, loc)
	if form["items"] == "__invalid_array__" {
		itemErrors["items"] = []string{i18n.ValidationMessage(loc, "array", "items")}
	} else if form["items"] == "__empty_array__" {
		itemErrors["items"] = []string{i18n.ValidationMessage(loc, "required", "items")}
	}
	for field, messages := range itemErrors {
		fields[field] = append(fields[field], messages...)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	existsErrors := map[string][]string{}
	for _, line := range lines {
		var exists int
		if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM order_items WHERE id=?`, line.OrderItemID).Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if exists == 0 {
			field := fmt.Sprintf("items.%d.order_item_id", line.Index)
			existsErrors[field] = []string{i18n.ValidationMessage(loc, "exists", field)}
		}
	}
	if len(existsErrors) > 0 {
		writeError(w, r, httpx.Validation(existsErrors))
		return
	}
	var owner sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT konsumen_id FROM orders WHERE id=?`, orderID).Scan(&owner); err != nil {
		writeError(w, r, err)
		return
	}
	if !owner.Valid || owner.Int64 != actor.ID {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	returnLines := make([]orders.ReturnLine, 0, len(lines))
	for _, line := range lines {
		returnLines = append(returnLines, orders.ReturnLine{Index: line.Index, OrderItemID: line.OrderItemID, Quantity: line.Quantity, Restock: line.Restock})
	}
	extension, valid := assistedReturnImageExtension(evidence)
	if !valid {
		writeError(w, r, httpx.Validation(map[string][]string{"evidence": {i18n.ValidationMessage(loc, "mimes", "evidence", "values", "jpg,jpeg,png,webp")}}))
		return
	}
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	result, err := (&orders.ReturnRequestService{Database: s.database}).Request(ctx, actor.ID, actor.Role,
		orderID, returnLines, reason, evidence, extension, clientIP(r), userAgent)
	if err != nil {
		var problem *orders.ReturnRequestProblem
		if errors.As(err, &problem) {
			writeError(w, r, httpx.NewError(problem.Status, msg(ctx, problem.Code)))
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, msg(ctx, "return.requested"))
}

func parseReturnRequestForm(w http.ResponseWriter, r *http.Request) (map[string][]string, map[string]string, []byte) {
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		fields, form, evidence, _ := parseAssistedReturnForm(w, r)
		return fields, form, evidence
	}
	form := map[string]string{}
	if strings.HasPrefix(r.Header.Get("Content-Type"), "application/x-www-form-urlencoded") {
		if err := r.ParseForm(); err != nil {
			writeError(w, r, err)
			return nil, nil, nil
		}
		for key, values := range r.PostForm {
			if len(values) > 0 {
				form[key] = values[0]
			}
		}
		return map[string][]string{}, form, nil
	}
	if r.Body != nil {
		var body map[string]json.RawMessage
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil && err.Error() != "EOF" {
			writeError(w, r, err)
			return nil, nil, nil
		}
		for key, raw := range body {
			if key == "items" {
				var items []map[string]any
				if err := json.Unmarshal(raw, &items); err != nil {
					form["items"] = "__invalid_array__"
					continue
				}
				if len(items) == 0 {
					form["items"] = "__empty_array__"
				}
				for index, item := range items {
					form[fmt.Sprintf("items[%d][__present]", index)] = "1"
					for field, value := range item {
						form[fmt.Sprintf("items[%d][%s]", index, field)] = fmt.Sprint(value)
					}
				}
				continue
			}
			var value any
			if err := json.Unmarshal(raw, &value); err == nil && value != nil {
				form[key] = fmt.Sprint(value)
			}
		}
	}
	return map[string][]string{}, form, nil
}
