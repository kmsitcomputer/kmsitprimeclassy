package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminWhatsAppSettingsUpdate(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	before, err := s.loadAdminWhatsAppSnapshot(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	input := map[string]any{}
	fields := map[string][]string{}
	customFields := map[string]string{}
	for _, name := range []string{"enabled"} {
		if value, ok := raw[name]; ok {
			parsed, valid := parseLaravelBoolean(value)
			if !valid {
				fields[name] = []string{i18n.ValidationMessage(locale, "boolean", name)}
			} else {
				input[name] = parsed
			}
		}
	}
	for _, spec := range []struct {
		name string
		max  int
	}{{"base_url", 255}, {"username", 100}, {"password", 200}, {"device_id", 100}} {
		value, ok := raw[spec.name]
		if !ok {
			continue
		}
		if strings.TrimSpace(string(value)) == "null" {
			if spec.name == "base_url" {
				customFields[spec.name] = i18n.Translate(locale, "whatsapp.invalid_base_url")
			} else {
				input[spec.name] = nil
			}
			continue
		}
		var text string
		if json.Unmarshal(value, &text) != nil {
			fields[spec.name] = []string{i18n.ValidationMessage(locale, "string", spec.name)}
			continue
		}
		if utf8.RuneCountInString(text) > spec.max {
			fields[spec.name] = []string{i18n.ValidationMessage(locale, "max.string", spec.name, "max", fmt.Sprint(spec.max))}
			continue
		}
		if spec.name == "base_url" {
			clean, valid := whatsAppBaseURL(text)
			if !valid {
				customFields[spec.name] = i18n.Translate(locale, "whatsapp.invalid_base_url")
				continue
			}
			text = clean
		}
		input[spec.name] = text
	}
	for _, spec := range []struct {
		name     string
		min, max int
	}{{"timeout", 2, 30}, {"max_attempts", 1, 5}, {"backoff_seconds", 10, 3600}} {
		if value, ok := raw[spec.name]; ok {
			n, valid := jsonInteger(value)
			if !valid {
				fields[spec.name] = []string{i18n.ValidationMessage(locale, "integer", spec.name)}
			} else if n < spec.min || n > spec.max {
				fields[spec.name] = []string{i18n.ValidationMessage(locale, "between.numeric", spec.name, "min", fmt.Sprint(spec.min), "max", fmt.Sprint(spec.max))}
			} else {
				input[spec.name] = n
			}
		}
	}
	if value, ok := raw["limits"]; ok {
		input["limits"] = validateWhatsAppIntMap(value, "limits", map[string][2]int{"per_recipient_per_hour": {1, 60}, "global_per_minute": {1, 120}}, locale, fields)
	}
	if value, ok := raw["otp"]; ok {
		input["otp"] = validateWhatsAppIntMap(value, "otp", map[string][2]int{"ttl_minutes": {1, 30}, "max_attempts": {1, 10}, "cooldown_seconds": {0, 600}, "limit": {1, 10}, "limit_window_minutes": {1, 60}}, locale, fields)
	}
	if value, ok := raw["events"]; ok {
		input["events"] = validateWhatsAppEventMap(value, locale, fields)
	}
	if value, ok := raw["test_numbers"]; ok {
		input["test_numbers"] = validateWhatsAppNumbers(value, locale, fields, customFields)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if len(customFields) > 0 {
		message := ""
		fieldErrors := map[string][]string{}
		for _, v := range customFields {
			message = v
		}
		for field, value := range customFields {
			fieldErrors[field] = []string{value}
		}
		writeError(w, r, &httpx.Error{Status: 422, Message: message, Fields: fieldErrors})
		return
	}
	if input["enabled"] == true {
		base, _ := input["base_url"].(string)
		if base == "" {
			base, _ = before["base_url"].(string)
		}
		username, _ := input["username"].(string)
		if username == "" {
			username, _ = before["username"].(string)
		}
		password, hasPassword := input["password"].(string)
		hasPassword = hasPassword && strings.TrimSpace(password) != ""
		if base == "" || username == "" || (!hasPassword && before["has_password"] != true) {
			message := i18n.Translate(locale, "whatsapp.config_incomplete")
			writeError(w, r, &httpx.Error{Status: 422, Message: message, FieldStrings: map[string]string{"enabled": message}})
			return
		}
	}
	_, err = admininfra.New(s.database, s.cfg.AppKey).SaveSettings(ctx, "whatsapp", input)
	if err != nil {
		writeError(w, r, err)
		return
	}
	after, err := s.loadAdminWhatsAppSnapshot(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	oldSnapshot, newSnapshot := cloneMap(before), cloneMap(after)
	delete(oldSnapshot, "table_ready")
	delete(newSnapshot, "table_ready")
	props, err := json.Marshal(map[string]any{"changed_keys": whatsappSettingsChangedKeys(input), "secret_changed": nonblankString(input["password"]), "old": oldSnapshot, "new": newSnapshot})
	if err != nil {
		writeError(w, r, err)
		return
	}
	var anchorID int64
	_, err = s.database.ExecContext(ctx, "INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) VALUES ('wa.enabled','0','integration.whatsapp',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)")
	if err == nil {
		err = s.database.QueryRowContext(ctx, "SELECT id FROM settings WHERE `key`='wa.enabled' AND `group`='integration.whatsapp'").Scan(&anchorID)
	}
	if err == nil {
		actor := UserFrom(ctx)
		userAgent := r.UserAgent()
		if len(userAgent) > 500 {
			userAgent = userAgent[:500]
		}
		_, err = s.database.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\Setting',?,'whatsapp.settings.updated',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, anchorID, string(props), clientIP(r), userAgent)
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, after, i18n.Translate(locale, "whatsapp.saved"), nil)
}

func whatsAppBaseURL(raw string) (string, bool) {
	clean := strings.TrimRight(strings.TrimSpace(raw), "/")
	parsed, err := url.Parse(clean)
	if err != nil || (parsed.Scheme != "http" && parsed.Scheme != "https") || parsed.Host == "" || parsed.User != nil {
		return "", false
	}
	host := strings.ToLower(parsed.Hostname())
	if strings.HasPrefix(host, "169.254.") || host == "metadata.google.internal" {
		return "", false
	}
	return clean, true
}

func jsonInteger(raw json.RawMessage) (int, bool) {
	var value int
	if err := json.Unmarshal(raw, &value); err != nil {
		return 0, false
	}
	return value, true
}

func validateWhatsAppIntMap(raw json.RawMessage, field string, rules map[string][2]int, locale string, errors map[string][]string) map[string]any {
	var values map[string]json.RawMessage
	if json.Unmarshal(raw, &values) != nil {
		errors[field] = []string{i18n.ValidationMessage(locale, "array", field)}
		return nil
	}
	out := map[string]any{}
	for key, bounds := range rules {
		value, ok := values[key]
		if !ok {
			continue
		}
		path := field + "." + key
		n, valid := jsonInteger(value)
		if !valid {
			errors[path] = []string{i18n.ValidationMessage(locale, "integer", path)}
		} else if n < bounds[0] || n > bounds[1] {
			errors[path] = []string{i18n.ValidationMessage(locale, "between.numeric", path, "min", fmt.Sprint(bounds[0]), "max", fmt.Sprint(bounds[1]))}
		} else {
			out[key] = n
		}
	}
	if field == "otp" {
		if value, ok := values["enabled"]; ok {
			b, valid := parseLaravelBoolean(value)
			if !valid {
				errors["otp.enabled"] = []string{i18n.ValidationMessage(locale, "boolean", "otp.enabled")}
			} else {
				out["enabled"] = b
			}
		}
	}
	return out
}

func validateWhatsAppEventMap(raw json.RawMessage, locale string, errors map[string][]string) map[string]any {
	var values map[string]json.RawMessage
	if json.Unmarshal(raw, &values) != nil {
		errors["events"] = []string{i18n.ValidationMessage(locale, "array", "events")}
		return nil
	}
	out := map[string]any{}
	for key, value := range values {
		b, valid := parseLaravelBoolean(value)
		if !valid {
			errors["events."+key] = []string{i18n.ValidationMessage(locale, "boolean", "events."+key)}
		} else {
			out[key] = b
		}
	}
	return out
}

func validateWhatsAppNumbers(raw json.RawMessage, locale string, errors map[string][]string, custom map[string]string) []string {
	var values []json.RawMessage
	if json.Unmarshal(raw, &values) != nil {
		errors["test_numbers"] = []string{i18n.ValidationMessage(locale, "array", "test_numbers")}
		return nil
	}
	if len(values) > 5 {
		errors["test_numbers"] = []string{i18n.ValidationMessage(locale, "max.array", "test_numbers", "max", "5")}
		return nil
	}
	out := make([]string, 0, len(values))
	for i, value := range values {
		path := fmt.Sprintf("test_numbers.%d", i)
		var rawNumber string
		if json.Unmarshal(value, &rawNumber) != nil {
			errors[path] = []string{i18n.ValidationMessage(locale, "string", path)}
			continue
		}
		if utf8.RuneCountInString(rawNumber) > 20 {
			errors[path] = []string{i18n.ValidationMessage(locale, "max.string", path, "max", "20")}
			continue
		}
		normalized := agentadmin.NormalizeWhatsAppNumber(rawNumber)
		if normalized == "" {
			custom["test_numbers"] = i18n.Translate(locale, "whatsapp.invalid_number")
			continue
		}
		duplicate := false
		for _, existing := range out {
			if existing == normalized {
				duplicate = true
				break
			}
		}
		if !duplicate {
			out = append(out, normalized)
		}
	}
	return out
}

func nonblankString(value any) bool {
	text, ok := value.(string)
	return ok && strings.TrimSpace(text) != ""
}

func cloneMap(value map[string]any) map[string]any {
	out := make(map[string]any, len(value))
	for key, item := range value {
		out[key] = item
	}
	return out
}

func whatsappSettingsChangedKeys(input map[string]any) []string {
	keys := []string{}
	for _, field := range []struct{ in, key string }{{"enabled", "wa.enabled"}, {"base_url", "wa.base_url"}, {"username", "wa.username"}, {"device_id", "wa.device_id"}, {"password", "wa.password"}, {"timeout", "wa.timeout"}, {"max_attempts", "wa.max_attempts"}, {"backoff_seconds", "wa.backoff_seconds"}} {
		if _, ok := input[field.in]; ok && (field.in != "password" || nonblankString(input[field.in])) {
			keys = append(keys, field.key)
		}
	}
	if values, ok := input["limits"].(map[string]any); ok {
		for _, key := range []string{"per_recipient_per_hour", "global_per_minute"} {
			if _, exists := values[key]; exists {
				if key == "per_recipient_per_hour" {
					keys = append(keys, "wa.rate.recipient_hour")
				} else {
					keys = append(keys, "wa.rate.global_minute")
				}
			}
		}
	}
	if _, ok := input["test_numbers"]; ok {
		keys = append(keys, "wa.test_numbers")
	}
	if values, ok := input["otp"].(map[string]any); ok {
		for _, key := range []string{"enabled", "ttl_minutes", "max_attempts", "cooldown_seconds", "limit", "limit_window_minutes"} {
			if _, exists := values[key]; exists {
				keys = append(keys, "wa.otp."+key)
			}
		}
	}
	if values, ok := input["events"].(map[string]any); ok {
		for _, key := range agentadmin.WhatsAppEventKeys {
			if _, exists := values[key]; exists {
				keys = append(keys, "wa.event."+key)
			}
		}
	}
	return keys
}
