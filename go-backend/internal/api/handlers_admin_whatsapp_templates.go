package api

import (
	"encoding/json"
	"errors"
	"net/http"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminWhatsAppTemplateUpdate(w http.ResponseWriter, r *http.Request) {
	ctx, locale, event := r.Context(), LocaleFrom(r.Context()), r.PathValue("event")
	if !adminWhatsAppEvent(event) {
		writeError(w, r, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "whatsapp.unknown_event")})
		return
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	raw, ok := body["template"]
	var template string
	if !ok || json.Unmarshal(raw, &template) != nil || template == "" {
		writeError(w, r, httpx.Validation(map[string][]string{"template": {i18n.ValidationMessage(locale, "required", "template")}}))
		return
	}
	if utf8.RuneCountInString(template) > 2000 {
		writeError(w, r, httpx.Validation(map[string][]string{"template": {i18n.ValidationMessage(locale, "max.string", "template", "max", "2000")}}))
		return
	}
	if err := agentadmin.ValidateWhatsAppTemplate(event, template); err != nil {
		var validation *agentadmin.Error
		if errors.As(err, &validation) {
			writeError(w, r, &httpx.Error{Status: validation.Status, Message: validation.Message, Fields: validation.Fields})
		} else {
			writeError(w, r, err)
		}
		return
	}
	before, err := s.loadAdminWhatsAppSnapshot(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	old := whatsAppTemplate(before, event)
	key := "wa.template." + event
	if _, err = s.database.ExecContext(ctx, `INSERT INTO settings (`+"`key`"+`,value,`+"`group`"+`,is_public,created_at,updated_at) VALUES (?,?,'integration.whatsapp',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE value=VALUES(value),`+"`group`"+`='integration.whatsapp',is_public=0,updated_at=UTC_TIMESTAMP(6)`, key, template); err != nil {
		writeError(w, r, err)
		return
	}
	after, err := s.loadAdminWhatsAppSnapshot(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if err := s.auditAdminWhatsAppTemplate(r, "whatsapp.template.updated", key, event, old, template); err != nil {
		writeError(w, r, err)
		return
	}
	delete(after, "table_ready")
	httpx.OK(w, after, i18n.Translate(locale, "whatsapp.saved"), nil)
}

func (s *Server) handleAdminWhatsAppTemplateReset(w http.ResponseWriter, r *http.Request) {
	ctx, locale, event := r.Context(), LocaleFrom(r.Context()), r.PathValue("event")
	if !adminWhatsAppEvent(event) {
		writeError(w, r, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "whatsapp.unknown_event")})
		return
	}
	before, err := s.loadAdminWhatsAppSnapshot(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	old := whatsAppTemplate(before, event)
	key := "wa.template." + event
	if _, err = s.database.ExecContext(ctx, "DELETE FROM settings WHERE `key`=? AND `group`='integration.whatsapp'", key); err != nil {
		writeError(w, r, err)
		return
	}
	after, err := s.loadAdminWhatsAppSnapshot(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	newValue := whatsAppTemplate(after, event)
	if err := s.auditAdminWhatsAppTemplate(r, "whatsapp.template.reset", key, event, old, newValue); err != nil {
		writeError(w, r, err)
		return
	}
	delete(after, "table_ready")
	httpx.OK(w, after, i18n.Translate(locale, "whatsapp.saved"), nil)
}

func (s *Server) handleAdminWhatsAppTemplatePreview(w http.ResponseWriter, r *http.Request) {
	locale, event := LocaleFrom(r.Context()), r.PathValue("event")
	if !adminWhatsAppEvent(event) {
		writeError(w, r, &httpx.Error{Status: 404, Message: i18n.Translate(locale, "whatsapp.unknown_event")})
		return
	}
	template, ok := readWhatsAppPreviewTemplate(w, r)
	if !ok {
		return
	}
	preview, err := agentadmin.PreviewWhatsAppTemplate(event, template)
	if err != nil {
		writeAdminWhatsAppTemplateError(w, r, err)
		return
	}
	httpx.OK(w, map[string]string{"preview": preview}, "OK", nil)
}

func (s *Server) handleAgentWhatsAppTemplatePreview(w http.ResponseWriter, r *http.Request) {
	if _, _, ok := s.agentAdmin(w, r, true); !ok {
		return
	}
	template, ok := readWhatsAppPreviewTemplate(w, r)
	if !ok {
		return
	}
	preview, err := agentadmin.PreviewWhatsAppTemplate(r.PathValue("event"), template)
	if err != nil {
		writeAdminWhatsAppTemplateError(w, r, err)
		return
	}
	httpx.OK(w, map[string]string{"preview": preview}, "OK", nil)
}

func readWhatsAppPreviewTemplate(w http.ResponseWriter, r *http.Request) (string, bool) {
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return "", false
	}
	raw, ok := body["template"]
	var template string
	if !ok || json.Unmarshal(raw, &template) != nil || template == "" {
		locale := LocaleFrom(r.Context())
		writeError(w, r, httpx.Validation(map[string][]string{"template": {i18n.ValidationMessage(locale, "required", "template")}}))
		return "", false
	}
	if utf8.RuneCountInString(template) > 2000 {
		locale := LocaleFrom(r.Context())
		writeError(w, r, httpx.Validation(map[string][]string{"template": {i18n.ValidationMessage(locale, "max.string", "template", "max", "2000")}}))
		return "", false
	}
	return template, true
}

func writeAdminWhatsAppTemplateError(w http.ResponseWriter, r *http.Request, err error) {
	var validation *agentadmin.Error
	if errors.As(err, &validation) {
		writeError(w, r, &httpx.Error{Status: validation.Status, Message: validation.Message, Fields: validation.Fields})
		return
	}
	writeError(w, r, err)
}

func whatsAppTemplate(snapshot map[string]any, event string) string {
	events, _ := snapshot["events"].([]map[string]any)
	for _, item := range events {
		if item["key"] == event {
			value, _ := item["template"].(string)
			return value
		}
	}
	if generic, ok := snapshot["events"].([]any); ok {
		for _, value := range generic {
			if item, ok := value.(map[string]any); ok && item["key"] == event {
				text, _ := item["template"].(string)
				return text
			}
		}
	}
	return ""
}

func adminWhatsAppEvent(event string) bool {
	for _, key := range agentadmin.WhatsAppEventKeys {
		if key == event {
			return true
		}
	}
	return false
}

func (s *Server) auditAdminWhatsAppTemplate(r *http.Request, event, key, templateEvent, oldValue, newValue string) error {
	props, err := json.Marshal(map[string]any{"changed_keys": []string{key}, "secret_changed": false, "old": map[string]any{"template": oldValue, "event": templateEvent}, "new": map[string]any{"template": newValue, "event": templateEvent}})
	if err != nil {
		return err
	}
	_, err = s.database.ExecContext(r.Context(), `INSERT INTO settings (`+"`key`"+`,value,`+"`group`"+`,is_public,created_at,updated_at) VALUES ('wa.enabled','0','integration.whatsapp',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE `+"`key`"+`=VALUES(`+"`key`"+`)`)
	if err != nil {
		return err
	}
	var anchorID int64
	if err = s.database.QueryRowContext(r.Context(), "SELECT id FROM settings WHERE `key`='wa.enabled' AND `group`='integration.whatsapp'").Scan(&anchorID); err != nil {
		return err
	}
	actor := UserFrom(r.Context())
	ua := r.UserAgent()
	if len(ua) > 500 {
		ua = ua[:500]
	}
	_, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\Setting',?, ?,NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, anchorID, event, string(props), clientIP(r), ua)
	return err
}
