package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminGoogleAuthShow(w http.ResponseWriter, r *http.Request) {
	s.googleAuthShow(w, r, nil)
}

func (s *Server) handleAgentGoogleAuthShow(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor.AgentID == nil {
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	s.googleAuthShow(w, r, actor.AgentID)
}

func (s *Server) googleAuthShow(w http.ResponseWriter, r *http.Request, agentID *int64) {
	data, err := admininfra.New(s.database, s.cfg.AppKey).GoogleAuth(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAdminGoogleAuthUpdate(w http.ResponseWriter, r *http.Request) {
	s.googleAuthUpdate(w, r, nil, "google_auth.settings_updated", "App\\Models\\GoogleAuthSetting", 1)
}

func (s *Server) handleAgentGoogleAuthUpdate(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor.AgentID == nil {
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	s.googleAuthUpdate(w, r, actor.AgentID, "google_auth.agent_config_updated", "App\\Models\\AgentGoogleAuthConfig", 0)
}

func (s *Server) googleAuthUpdate(w http.ResponseWriter, r *http.Request, agentID *int64, event, subjectType string, subjectID int64) {
	ctx := r.Context()
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	locale := LocaleFrom(ctx)
	fields := map[string][]string{}
	input := map[string]any{}
	enabled := false
	for _, key := range []string{"is_enabled", "clear_secret"} {
		if value, ok := raw[key]; ok {
			parsed, valid := parseLaravelBoolean(value)
			if !valid {
				fields[key] = []string{i18n.ValidationMessage(locale, "boolean", key)}
			} else {
				input[key] = parsed
				if key == "is_enabled" {
					enabled = parsed
				}
			}
		}
	}
	fieldLimits := map[string]int{"client_id": 191, "client_secret": 255, "redirect_uri": 255, "frontend_url": 255}
	for key, max := range fieldLimits {
		value, ok := raw[key]
		if !ok {
			continue
		}
		if strings.TrimSpace(string(value)) == "null" {
			input[key] = nil
			continue
		}
		var text string
		if err := json.Unmarshal(value, &text); err != nil {
			fields[key] = []string{i18n.ValidationMessage(locale, "string", key)}
			continue
		}
		if utf8.RuneCountInString(text) > max {
			fields[key] = []string{i18n.ValidationMessage(locale, "max.string", key, "max", fmt.Sprint(max))}
			continue
		}
		if (key == "redirect_uri" || key == "frontend_url") && strings.TrimSpace(text) != "" {
			parsed, err := url.ParseRequestURI(text)
			if err != nil || parsed.Scheme == "" || parsed.Host == "" {
				fields[key] = []string{i18n.ValidationMessage(locale, "url", key)}
				continue
			}
		}
		input[key] = text
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	service := admininfra.New(s.database, s.cfg.AppKey)
	before, err := service.GoogleAuth(ctx, agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	updated, err := service.SaveGoogleAuth(ctx, agentID, input)
	if err != nil {
		writeError(w, r, err)
		return
	}
	actor := UserFrom(ctx)
	changed := false
	if clear, ok := input["clear_secret"].(bool); ok && clear {
		changed = before["has_secret"] == true
	} else if secret, ok := input["client_secret"].(string); ok && strings.TrimSpace(secret) != "" {
		changed = true
	}
	fieldNames := make([]string, 0, 3)
	for _, key := range []string{"client_id", "redirect_uri", "frontend_url"} {
		if value, ok := input[key].(string); ok && strings.TrimSpace(value) != "" {
			fieldNames = append(fieldNames, key)
		}
	}
	agentValue := any(nil)
	if agentID != nil {
		agentValue = *agentID
		if err := s.database.QueryRowContext(ctx, `SELECT id FROM agent_google_auth_configs WHERE agent_id=?`, *agentID).Scan(&subjectID); err != nil {
			writeError(w, r, err)
			return
		}
	}
	props, err := json.Marshal(map[string]any{"actor_role": actor.Role, "agent_id": agentValue, "fields": fieldNames, "secret_changed": changed, "is_enabled": enabled, "had_secret_before": before["has_secret"], "has_secret_after": updated["has_secret"]})
	if err != nil {
		writeError(w, r, err)
		return
	}
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,?,?,?,NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, subjectType, subjectID, event, string(props), clientIP(r), userAgent)
	if err != nil {
		writeError(w, r, err)
		return
	}
	message := "Google settings updated"
	if agentID != nil {
		message = i18n.Translate(locale, "google.agent_settings_updated")
	} else {
		message = i18n.Translate(locale, "google.settings_updated")
	}
	httpx.OK(w, updated, message, nil)
}
