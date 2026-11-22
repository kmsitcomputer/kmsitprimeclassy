package api

import (
	"bytes"
	"errors"
	"fmt"
	"image"
	_ "image/jpeg"
	_ "image/png"
	"io"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/crm"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	_ "golang.org/x/image/webp"
)

var assistedReturnItemField = regexp.MustCompile(`^items\[(\d+)\]\[([^]]+)\]$`)

func (s *Server) handleCRMAssistedReturn(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := crmActorFrom(r)
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	var bound int
	if actor.AgentID == nil {
		if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders WHERE id=?`, orderID).Scan(&bound); err != nil {
			writeError(w, r, err)
			return
		}
	} else if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM orders WHERE id=? AND agent_id=?`, orderID, *actor.AgentID).Scan(&bound); err != nil {
		writeError(w, r, err)
		return
	}
	if bound == 0 {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}

	fields, form, evidence, ext := parseAssistedReturnForm(w, r)
	if fields == nil {
		return
	}
	loc := LocaleFrom(ctx)
	consentID, consentPresent := parseInteger(form["consent_event_id"])
	if !consentPresent {
		if _, exists := form["consent_event_id"]; !exists || strings.TrimSpace(form["consent_event_id"]) == "" {
			fields["consent_event_id"] = []string{i18n.ValidationMessage(loc, "required", "consent event id")}
		} else {
			fields["consent_event_id"] = []string{i18n.ValidationMessage(loc, "integer", "consent event id")}
		}
	}
	reason := strings.TrimSpace(form["reason"])
	if reason == "" {
		fields["reason"] = []string{i18n.ValidationMessage(loc, "required", "reason")}
	} else if len([]rune(reason)) > 1000 {
		fields["reason"] = []string{i18n.ValidationMessage(loc, "max.string", "reason", "max", "1000")}
	}
	if evidence == nil {
		fields["evidence"] = []string{i18n.ValidationMessage(loc, "required", "evidence")}
	} else if len(evidence) > 4096*1024 {
		fields["evidence"] = []string{i18n.ValidationMessage(loc, "max.file", "evidence", "max", "4096")}
	} else if guessedExt, valid := assistedReturnImageExtension(evidence); !valid {
		fields["evidence"] = []string{i18n.ValidationMessage(loc, "mimes", "evidence", "values", "jpg,jpeg,png,webp")}
	} else {
		ext = guessedExt
	}
	lines, itemErrors := parseAssistedReturnItems(form, loc)
	for key, errs := range itemErrors {
		fields[key] = append(fields[key], errs...)
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
	var consentExists int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM consent_events WHERE id=?`, consentID).Scan(&consentExists); err != nil {
		writeError(w, r, err)
		return
	}
	if consentExists == 0 {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	ip := r.RemoteAddr
	if host, _, splitErr := net.SplitHostPort(r.RemoteAddr); splitErr == nil {
		ip = host
	}
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	ret, err := s.crmService().InitiateAssistedReturn(ctx, actor, orderID, consentID, lines, reason, evidence, ext, ip, userAgent)
	if err != nil {
		var problem *crm.AssistedReturnProblem
		if errors.As(err, &problem) {
			message := msg(ctx, problem.Code)
			if len(problem.Fields) > 0 {
				fieldErrors := map[string][]string{}
				for field, codes := range problem.Fields {
					for _, code := range codes {
						fieldErrors[field] = append(fieldErrors[field], msg(ctx, code))
					}
				}
				httpx.Failure(w, problem.Status, message, fieldErrors)
				return
			}
			writeError(w, r, httpx.NewError(problem.Status, message))
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.Created(w, ret, msg(ctx, "return.requested"))
}

func parseAssistedReturnForm(w http.ResponseWriter, r *http.Request) (map[string][]string, map[string]string, []byte, string) {
	fields := map[string][]string{}
	if !strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		fields["evidence"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "required", "evidence")}
		return fields, map[string]string{}, nil, ""
	}
	if err := r.ParseMultipartForm(8 << 20); err != nil {
		writeError(w, r, err)
		return nil, nil, nil, ""
	}
	form := map[string]string{}
	for key, values := range r.MultipartForm.Value {
		if len(values) > 0 {
			form[key] = values[0]
		}
	}
	var evidence []byte
	ext := ""
	if files := r.MultipartForm.File["evidence"]; len(files) > 0 {
		file, err := files[0].Open()
		if err != nil {
			writeError(w, r, err)
			return nil, nil, nil, ""
		}
		defer file.Close()
		var readErr error
		evidence, readErr = io.ReadAll(io.LimitReader(file, 5120*1024+1))
		if readErr != nil {
			writeError(w, r, readErr)
			return nil, nil, nil, ""
		}
	}
	return fields, form, evidence, ext
}

func assistedReturnImageExtension(data []byte) (string, bool) {
	var ext string
	switch http.DetectContentType(data) {
	case "image/jpeg":
		ext = ".jpg"
	case "image/png":
		ext = ".png"
	case "image/webp":
		ext = ".webp"
	default:
		return "", false
	}
	if _, _, err := image.DecodeConfig(bytes.NewReader(data)); err != nil {
		return "", false
	}
	return ext, true
}

func parseAssistedReturnItems(form map[string]string, loc string) ([]crm.AssistedReturnLine, map[string][]string) {
	byIndex := map[int]map[string]string{}
	for key, value := range form {
		match := assistedReturnItemField.FindStringSubmatch(key)
		if match == nil {
			continue
		}
		index, _ := strconv.Atoi(match[1])
		if byIndex[index] == nil {
			byIndex[index] = map[string]string{}
		}
		if match[2] == "order_item_id" || match[2] == "quantity" || match[2] == "restock" {
			byIndex[index][match[2]] = value
		}
	}
	errs := map[string][]string{}
	if len(byIndex) == 0 {
		errs["items"] = []string{i18n.ValidationMessage(loc, "required", "items")}
		return nil, errs
	}
	indices := make([]int, 0, len(byIndex))
	for index := range byIndex {
		indices = append(indices, index)
	}
	// Form indices are ordered by their numeric key, including sparse Laravel array keys.
	for i := 0; i < len(indices); i++ {
		for j := i + 1; j < len(indices); j++ {
			if indices[j] < indices[i] {
				indices[i], indices[j] = indices[j], indices[i]
			}
		}
	}
	lines := make([]crm.AssistedReturnLine, 0, len(indices))
	for _, index := range indices {
		item := byIndex[index]
		idText, idPresent := item["order_item_id"]
		id, idErr := strconv.ParseInt(idText, 10, 64)
		validLine := true
		if !idPresent || strings.TrimSpace(idText) == "" {
			key := fmt.Sprintf("items.%d.order_item_id", index)
			errs[key] = []string{nestedItemValidationMessage(loc, "required", key)}
			validLine = false
		}
		if idPresent && strings.TrimSpace(idText) != "" && idErr != nil {
			key := fmt.Sprintf("items.%d.order_item_id", index)
			errs[key] = []string{nestedItemValidationMessage(loc, "integer", key)}
			validLine = false
		}
		qtyText, qtyPresent := item["quantity"]
		quantity, qtyErr := strconv.ParseInt(qtyText, 10, 64)
		if !qtyPresent || strings.TrimSpace(qtyText) == "" {
			key := fmt.Sprintf("items.%d.quantity", index)
			errs[key] = []string{i18n.ValidationMessage(loc, "required", key)}
			validLine = false
		}
		if qtyPresent && strings.TrimSpace(qtyText) != "" && qtyErr != nil {
			key := fmt.Sprintf("items.%d.quantity", index)
			errs[key] = []string{i18n.ValidationMessage(loc, "integer", key)}
			validLine = false
		}
		if qtyErr == nil && quantity < 1 {
			key := fmt.Sprintf("items.%d.quantity", index)
			errs[key] = []string{i18n.ValidationMessage(loc, "min.numeric", key, "min", "1")}
			validLine = false
		}
		restock := true
		if value, ok := item["restock"]; ok {
			switch value {
			case "1", "true":
				restock = true
			case "0", "false":
				restock = false
			default:
				key := fmt.Sprintf("items.%d.restock", index)
				errs[key] = []string{i18n.ValidationMessage(loc, "boolean", "items."+strconv.Itoa(index)+".restock")}
				continue
			}
		}
		if validLine {
			lines = append(lines, crm.AssistedReturnLine{Index: index, OrderItemID: id, Quantity: quantity, Restock: restock})
		}
	}
	return lines, errs
}

func nestedItemValidationMessage(locale, rule, field string) string {
	message := i18n.ValidationMessage(locale, rule, field)
	return strings.ReplaceAll(message, strings.ReplaceAll(field, "_", " "), field)
}

func parseInteger(value string) (int64, bool) {
	n, err := strconv.ParseInt(strings.TrimSpace(value), 10, 64)
	return n, err == nil
}
