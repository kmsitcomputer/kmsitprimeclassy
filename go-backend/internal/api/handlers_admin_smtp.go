package api

import (
	"encoding/json"
	"net/http"
	"net/mail"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminSMTPSettingsShow(w http.ResponseWriter, r *http.Request) {
	data, err := admininfra.New(s.database, s.cfg.AppKey).Snapshot(r.Context(), "smtp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

var smtpHostPattern = regexp.MustCompile(`^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$`)

func (s *Server) handleAdminSMTPSettingsUpdate(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	service := admininfra.New(s.database, s.cfg.AppKey)
	before, err := service.Snapshot(ctx, "smtp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	input := map[string]any{}
	fields := map[string][]string{}
	for _, name := range []string{"enabled", "transactional_enabled", "clear_password"} {
		if v, ok := raw[name]; ok {
			if b, valid := parseLaravelBoolean(v); valid {
				input[name] = b
			} else {
				fields[name] = []string{i18n.ValidationMessage(locale, "boolean", name)}
			}
		}
	}
	for _, spec := range []struct {
		name     string
		max      int
		nullable bool
	}{{"host", 190, false}, {"encryption", 3, false}, {"username", 190, true}, {"password", 300, true}, {"from_address", 190, false}, {"from_name", 100, true}} {
		v, ok := raw[spec.name]
		if !ok {
			continue
		}
		if strings.TrimSpace(string(v)) == "null" && spec.nullable {
			input[spec.name] = ""
			continue
		}
		var text string
		if json.Unmarshal(v, &text) != nil {
			fields[spec.name] = []string{i18n.ValidationMessage(locale, "string", spec.name)}
			continue
		}
		if utf8.RuneCountInString(text) > spec.max {
			fields[spec.name] = []string{i18n.ValidationMessage(locale, "max.string", spec.name, "max", strconv.Itoa(spec.max))}
			continue
		}
		if spec.name == "host" && !smtpHostPattern.MatchString(text) {
			fields[spec.name] = []string{i18n.ValidationMessage(locale, "regex", spec.name)}
			continue
		}
		if spec.name == "encryption" && text != "ssl" && text != "tls" && text != "none" {
			fields[spec.name] = []string{i18n.ValidationMessage(locale, "in", spec.name, "values", "ssl, tls, none")}
			continue
		}
		if spec.name == "from_address" {
			parsed, e := mail.ParseAddress(text)
			if e != nil || parsed.Address != text || !strings.Contains(text, "@") {
				fields[spec.name] = []string{i18n.ValidationMessage(locale, "email", spec.name)}
				continue
			}
			text = strings.ToLower(strings.TrimSpace(text))
		}
		if spec.name == "host" || spec.name == "username" || spec.name == "from_name" {
			text = strings.TrimSpace(text)
		}
		input[spec.name] = text
	}
	for _, spec := range []struct {
		name     string
		min, max int
	}{{"port", 1, 65535}, {"timeout", 2, 60}} {
		if v, ok := raw[spec.name]; ok {
			n, valid := jsonInteger(v)
			if !valid {
				fields[spec.name] = []string{i18n.ValidationMessage(locale, "integer", spec.name)}
			} else if spec.name == "timeout" && (n < spec.min || n > spec.max) {
				fields[spec.name] = []string{i18n.ValidationMessage(locale, "between.numeric", spec.name, "min", strconv.Itoa(spec.min), "max", strconv.Itoa(spec.max))}
			} else if spec.name == "port" && n != 25 && n != 465 && n != 587 && n != 2525 && n != 1025 && n != 2465 {
				fields[spec.name] = []string{i18n.ValidationMessage(locale, "in", spec.name, "values", "25, 465, 587, 2525, 1025, 2465")}
			} else {
				input[spec.name] = n
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if input["enabled"] == true {
		get := func(key string) string {
			if v, ok := input[key].(string); ok {
				return v
			}
			if v, ok := before[key].(string); ok {
				return v
			}
			if key == "port" {
				if v, ok := before[key].(int); ok && v > 0 {
					return strconv.Itoa(v)
				}
			}
			return ""
		}
		if get("host") == "" || get("from_address") == "" || get("port") == "" {
			message := i18n.Translate(locale, "smtp.config_incomplete")
			writeError(w, r, &httpx.Error{Status: 422, Message: message, Fields: map[string][]string{"enabled": {message}}})
			return
		}
	}
	if _, err = service.SaveSettings(ctx, "smtp", input); err != nil {
		writeError(w, r, err)
		return
	}
	after, err := service.Snapshot(ctx, "smtp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	changed := smtpSettingsChangedKeys(input)
	props, err := json.Marshal(map[string]any{"changed_keys": changed, "secret_changed": smtpSecretChanged(input), "old": before, "new": after})
	if err != nil {
		writeError(w, r, err)
		return
	}
	var anchorID int64
	_, err = s.database.ExecContext(ctx, "INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) VALUES ('smtp.enabled','0','integration.smtp',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)")
	if err == nil {
		err = s.database.QueryRowContext(ctx, "SELECT id FROM settings WHERE `key`='smtp.enabled' AND `group`='integration.smtp'").Scan(&anchorID)
	}
	if err == nil {
		actor := UserFrom(ctx)
		ua := r.UserAgent()
		if len(ua) > 500 {
			ua = ua[:500]
		}
		_, err = s.database.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\Setting',?,'smtp.settings.updated',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, anchorID, string(props), clientIP(r), ua)
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, after, i18n.Translate(locale, "smtp.saved"), nil)
}

func smtpSettingsChangedKeys(input map[string]any) []string {
	keys := make([]string, 0, len(input))
	order := []string{"enabled", "transactional_enabled", "host", "username", "from_name", "from_address", "port", "timeout", "encryption"}
	for _, key := range order {
		if _, ok := input[key]; ok {
			keys = append(keys, "smtp."+key)
		}
	}
	if smtpSecretChanged(input) {
		keys = append(keys, "smtp.password")
	}
	return keys
}

func smtpSecretChanged(input map[string]any) bool {
	password, _ := input["password"].(string)
	return strings.TrimSpace(password) != "" || input["clear_password"] == true
}
