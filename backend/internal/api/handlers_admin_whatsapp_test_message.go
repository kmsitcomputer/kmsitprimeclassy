package api

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminWhatsAppTestMessage(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var number string
	value, ok := raw["number"]
	if !ok || json.Unmarshal(value, &number) != nil || number == "" {
		writeError(w, r, httpx.Validation(map[string][]string{"number": {i18n.ValidationMessage(locale, "required", "number")}}))
		return
	}
	if utf8.RuneCountInString(number) > 20 {
		writeError(w, r, httpx.Validation(map[string][]string{"number": {i18n.ValidationMessage(locale, "max.string", "number", "max", "20")}}))
		return
	}
	message := ""
	if value, ok := raw["message"]; ok && strings.TrimSpace(string(value)) != "null" {
		if json.Unmarshal(value, &message) != nil {
			writeError(w, r, httpx.Validation(map[string][]string{"message": {i18n.ValidationMessage(locale, "string", "message")}}))
			return
		}
		if utf8.RuneCountInString(message) > 300 {
			writeError(w, r, httpx.Validation(map[string][]string{"message": {i18n.ValidationMessage(locale, "max.string", "message", "max", "300")}}))
			return
		}
	}
	msisdn := agentadmin.NormalizeWhatsAppNumber(number)
	if msisdn == "" {
		msg := i18n.Translate(locale, "whatsapp.invalid_number")
		writeError(w, r, &httpx.Error{Status: 422, Message: msg, Fields: map[string][]string{"number": {msg}}})
		return
	}
	infra := admininfra.New(s.database, s.cfg.AppKey)
	allowed, err := infra.WhatsAppTestNumbers(ctx)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(allowed) == 0 {
		msg := i18n.Translate(locale, "whatsapp.no_test_numbers")
		writeError(w, r, &httpx.Error{Status: 422, Message: msg, Fields: map[string][]string{"number": {msg}}})
		return
	}
	found := false
	for _, candidate := range allowed {
		if candidate == msisdn {
			found = true
			break
		}
	}
	if !found {
		msg := i18n.Translate(locale, "whatsapp.number_not_allowed")
		writeError(w, r, &httpx.Error{Status: 422, Message: msg, Fields: map[string][]string{"number": {msg}}})
		return
	}
	snapshot, err := infra.Snapshot(ctx, "whatsapp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	if snapshot["configured"] != true {
		writeError(w, r, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "whatsapp.config_incomplete")})
		return
	}
	var tableReady bool
	if err = s.database.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_messages')").Scan(&tableReady); err != nil {
		writeError(w, r, err)
		return
	}
	if !tableReady {
		writeError(w, r, &httpx.Error{Status: 503, Message: i18n.Translate(locale, "whatsapp.migration_pending")})
		return
	}
	text := strings.TrimSpace(message)
	if text == "" {
		text = i18n.Translate(locale, "whatsapp.test_text")
	} else {
		text = agentadmin.CleanWhatsAppMessage(text)
	}
	keyBytes := make([]byte, 10)
	if _, err = rand.Read(keyBytes); err != nil {
		writeError(w, r, err)
		return
	}
	key := "test:" + hex.EncodeToString(keyBytes)
	actor := UserFrom(ctx)
	maxAttempts := 3
	if n, ok := snapshot["max_attempts"].(int); ok && n > 0 {
		maxAttempts = n
	}
	result, err := s.database.ExecContext(ctx, `INSERT INTO whatsapp_messages (idempotency_key,purpose,event,recipient_phone,body,status,attempts,max_attempts,created_by,queued_at,created_at,updated_at) VALUES (?,'test','test',?,?,'queued',0,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, key, msisdn, text, maxAttempts, actor.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	id, _ := result.LastInsertId()
	if _, err = s.database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='sending',attempts=1,last_attempt_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, id); err != nil {
		writeError(w, r, err)
		return
	}
	outcome, err := infra.SendWhatsAppText(ctx, msisdn, text)
	if err != nil {
		writeError(w, r, err)
		return
	}
	status, _ := outcome["outcome"].(string)
	if status == "sent" {
		provider := stringOrNil(anyString(outcome["provider_message_id"]))
		_, err = s.database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='sent',provider_message_id=?,sent_at=UTC_TIMESTAMP(6),error_class=NULL,error_message=NULL,secret_body=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, provider, id)
	} else if status == "failed" || status == "unknown" {
		_, err = s.database.ExecContext(ctx, `UPDATE whatsapp_messages SET status=?,error_class=?,error_message=?,secret_body=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, status, outcome["error_class"], outcome["error_message"], id)
	} else {
		_, err = s.database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='queued',error_class=?,error_message=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 SECOND),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, outcome["error_class"], outcome["error_message"], id)
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	item, err := loadWhatsAppMessage(ctx, s.database.DB, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	item["can_retry"] = (item["status"] == "failed" || item["status"] == "unknown") && item["purpose"] != "otp"
	item["needs_duplicate_risk_confirmation"] = item["status"] == "unknown"
	httpx.OK(w, item, i18n.Translate(locale, "whatsapp.test_done"), nil)
}

func anyString(value any) string {
	if value == nil {
		return ""
	}
	return fmt.Sprint(value)
}
func stringOrNil(value string) any {
	if value == "" {
		return nil
	}
	return value
}
