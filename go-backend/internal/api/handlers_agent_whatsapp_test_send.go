package api

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAgentWhatsAppTestSend(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentWhatsAppTarget(w, r)
	if !ok {
		return
	}
	var body struct {
		Number  json.RawMessage `json:"number"`
		Message json.RawMessage `json:"message"`
	}
	if r.ContentLength > 0 {
		if err := httpx.DecodeJSON(r, &body); err != nil {
			writeError(w, r, err)
			return
		}
	}
	number, fields := decodeAgentTestString(r.Context(), body.Number, "number", true, 20)
	message, messageFields := decodeAgentTestString(r.Context(), body.Message, "message", false, 300)
	for k, v := range messageFields {
		fields[k] = v
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	settings, password, err := s.agentWhatsAppCredentials(r.Context())
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !truthy(settings["wa.enabled"]) || settings["wa.base_url"] == "" || settings["wa.username"] == "" || password == "" {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.test_gateway_off", "whatsapp_unavailable")
		return
	}
	msisdn := agentadmin.NormalizeWhatsAppNumber(number)
	allowed, total, err := s.agentWhatsAppTestNumberAllowed(r.Context(), agentID, msisdn)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !allowed {
		key := "whatsapp.number_not_allowed"
		if total == 0 {
			key = "whatsapp.no_test_numbers"
		}
		text := msg(r.Context(), key)
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: text, Fields: map[string][]string{"number": {text}}})
		return
	}
	d, err := s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if d == nil || !d.Paired.Valid {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.test_device_not_ready", "whatsapp_device_not_ready")
		return
	}
	minuteOK, _ := s.limiter.allow("wa-agent-test-min:"+strconv.FormatInt(agentID, 10), 3, time.Minute)
	dayOK, _ := s.limiter.allow("wa-agent-test-day:"+strconv.FormatInt(agentID, 10), 20, 24*time.Hour)
	if !minuteOK || !dayOK {
		httpx.Failure(w, http.StatusTooManyRequests, msg(r.Context(), "whatsapp.test_send_limited"), nil)
		return
	}
	text := cleanAgentWhatsAppTestMessage(message)
	if text == "" {
		text = msg(r.Context(), "whatsapp.test_text")
	}
	var ready, ledgerReady bool
	if err = s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_messages')`).Scan(&ready); err != nil {
		writeError(w, r, err)
		return
	}
	if !ready {
		httpx.Failure(w, http.StatusServiceUnavailable, msg(r.Context(), "whatsapp.migration_pending"), nil)
		return
	}
	if err = s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='whatsapp_messages' AND column_name='sender_device_id')`).Scan(&ledgerReady); err != nil {
		writeError(w, r, err)
		return
	}
	keyBytes := make([]byte, 10)
	if _, err = s.randomReaderRead(keyBytes); err != nil {
		writeError(w, r, err)
		return
	}
	key := "test:" + fmt.Sprintf("%x", keyBytes)
	maxAttempts := 3
	if v, e := strconv.Atoi(settings["wa.max_attempts"]); e == nil && v > 0 {
		maxAttempts = v
	}
	if maxAttempts > 5 {
		maxAttempts = 5
	}
	res, err := s.database.ExecContext(r.Context(), `INSERT INTO whatsapp_messages (idempotency_key,purpose,event,agent_id,recipient_phone,body,status,attempts,max_attempts,created_by,queued_at,created_at,updated_at) VALUES (?,'test','test',?,?,?,'queued',0,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, key, agentID, msisdn, text, maxAttempts, actor.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	id, _ := res.LastInsertId()
	err = s.database.Tx(r.Context(), func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, `UPDATE whatsapp_messages SET status='sending',attempts=attempts+1,last_attempt_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, id); err != nil {
			return err
		}
		if ledgerReady {
			_, err := tx.ExecContext(ctx, `UPDATE whatsapp_messages SET sender_device_id=? WHERE id=?`, d.DeviceID, id)
			return err
		}
		return nil
	})
	if err != nil {
		writeError(w, r, err)
		return
	}
	var status string
	var providerID, errorClass, errorMessage, skipReason sql.NullString
	var sentAt sql.NullTime
	endpoint := strings.TrimRight(settings["wa.base_url"], "/") + "/send/message"
	payload, _ := json.Marshal(map[string]string{"phone": msisdn + "@s.whatsapp.net", "message": text})
	code, response, sendErr := agentWhatsAppGatewayPost(r.Context(), endpoint, settings["wa.username"], password, whatsAppTimeout(settings["wa.timeout"]), d.DeviceID, payload)
	now := time.Now().UTC()
	status = "unknown"
	if sendErr != nil {
		detail := sendErr.Error()
		lower := strings.ToLower(detail)
		if strings.Contains(lower, "connection refused") || strings.Contains(lower, "could not resolve") || strings.Contains(lower, "failed to connect") {
			status = "queued"
			errorClass = sql.NullString{String: "unreachable", Valid: true}
			errorMessage = sql.NullString{String: "Connection to WhatsApp gateway failed.", Valid: true}
			retry := backoffSeconds(settings["wa.backoff_seconds"])
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='queued',error_class=?,error_message=?,next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL ? SECOND,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, errorClass.String, errorMessage.String, retry, id)
		} else {
			errorClass = sql.NullString{String: "timeout", Valid: true}
			errorMessage = sql.NullString{String: "Gateway request outcome is unknown.", Valid: true}
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='unknown',error_class=?,error_message=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, errorClass.String, errorMessage.String, id)
		}
	} else {
		var provider struct {
			Code    string `json:"code"`
			Message string `json:"message"`
			Results struct {
				MessageID string `json:"message_id"`
			} `json:"results"`
		}
		_ = json.Unmarshal(response, &provider)
		switch {
		case code >= 200 && code < 300 && (provider.Code == "" || strings.EqualFold(provider.Code, "SUCCESS")):
			status = "sent"
			if provider.Results.MessageID != "" {
				providerID = sql.NullString{String: provider.Results.MessageID, Valid: true}
			}
			sentAt = sql.NullTime{Time: now, Valid: true}
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='sent',provider_message_id=?,sent_at=UTC_TIMESTAMP(6),error_class=NULL,error_message=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, nullableSQL(providerID), id)
		case code == http.StatusUnauthorized || code == http.StatusForbidden:
			status = "failed"
			errorClass = sql.NullString{String: "auth", Valid: true}
			errorMessage = sql.NullString{String: "Gateway rejected the credentials.", Valid: true}
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='failed',error_class=?,error_message=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, errorClass.String, errorMessage.String, id)
		case code >= 400 && code < 500:
			status = "failed"
			errorClass = sql.NullString{String: "rejected", Valid: true}
			errorMessage = sql.NullString{String: fmt.Sprintf("HTTP %d", code), Valid: true}
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='failed',error_class=?,error_message=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, errorClass.String, errorMessage.String, id)
		case code >= 500:
			status = "unknown"
			errorClass = sql.NullString{String: "provider_error", Valid: true}
			errorMessage = sql.NullString{String: fmt.Sprintf("HTTP %d", code), Valid: true}
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='unknown',error_class=?,error_message=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, errorClass.String, errorMessage.String, id)
		default:
			status = "unknown"
			errorClass = sql.NullString{String: "unexpected_response", Valid: true}
			errorMessage = sql.NullString{String: "Unexpected provider response.", Valid: true}
			_, err = s.database.ExecContext(r.Context(), `UPDATE whatsapp_messages SET status='unknown',error_class=?,error_message=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, errorClass.String, errorMessage.String, id)
		}
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	_ = s.database.QueryRowContext(r.Context(), `SELECT status,provider_message_id,error_class,error_message,skip_reason,sent_at FROM whatsapp_messages WHERE id=?`, id).Scan(&status, &providerID, &errorClass, &errorMessage, &skipReason, &sentAt)
	props, _ := json.Marshal(map[string]any{"actor_id": actor.ID, "target_agent_id": agentID, "recipient": agentadmin.MaskWhatsAppNumber(msisdn), "status": status})
	ua := r.UserAgent()
	if len(ua) > 500 {
		ua = ua[:500]
	}
	_, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\WhatsAppMessage',?,'whatsapp.agent_test.sent',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, id, string(props), clientIP(r), ua)
	if err != nil {
		writeError(w, r, err)
		return
	}
	suffix := d.DeviceID
	if len(suffix) > 6 {
		suffix = suffix[len(suffix)-6:]
	}
	var suffixOut any = suffix
	httpx.OK(w, map[string]any{"id": id, "status": status, "recipient": agentadmin.MaskWhatsAppNumber(msisdn), "skip_reason": nullStringValue(skipReason), "error_class": nullStringValue(errorClass), "sender_suffix": suffixOut}, msg(r.Context(), "whatsapp.test_done"), nil)
}

func decodeAgentTestString(ctx context.Context, raw json.RawMessage, field string, required bool, max int) (string, map[string][]string) {
	if len(raw) == 0 || string(raw) == "null" {
		if required {
			return "", map[string][]string{field: requiredField(ctx, field)}
		}
		return "", nil
	}
	var value string
	if err := json.Unmarshal(raw, &value); err != nil {
		return "", map[string][]string{field: {i18n.ValidationMessage(LocaleFrom(ctx), "string", field)}}
	}
	if utf8.RuneCountInString(value) > max {
		return "", map[string][]string{field: {i18n.ValidationMessage(LocaleFrom(ctx), "max.string", field, "max", strconv.Itoa(max))}}
	}
	return value, nil
}
func cleanAgentWhatsAppTestMessage(raw string) string {
	value := strings.TrimSpace(raw)
	if value == "" {
		return ""
	}
	var b strings.Builder
	for _, r := range value {
		if r < 0x20 || r == 0x7f {
			b.WriteByte(' ')
		} else if r == '{' {
			b.WriteByte('(')
		} else if r == '}' {
			b.WriteByte(')')
		} else {
			b.WriteRune(r)
		}
	}
	out := strings.TrimSpace(b.String())
	rs := []rune(out)
	if len(rs) > 200 {
		out = string(rs[:200])
	}
	return out
}
func (s *Server) agentWhatsAppTestNumberAllowed(ctx context.Context, agentID int64, number string) (bool, int, error) {
	var table bool
	if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='agent_whatsapp_test_numbers')`).Scan(&table); err != nil {
		return false, 0, err
	}
	if !table {
		return false, 0, nil
	}
	var count int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM agent_whatsapp_test_numbers WHERE agent_id=?`, agentID).Scan(&count); err != nil {
		return false, 0, err
	}
	if number == "" {
		return false, count, nil
	}
	var exists bool
	err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM agent_whatsapp_test_numbers WHERE agent_id=? AND msisdn=?)`, agentID, number).Scan(&exists)
	return exists, count, err
}
func (s *Server) randomReaderRead(b []byte) (int, error) { return rand.Read(b) }
func nullableSQL(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}
func backoffSeconds(raw string) int {
	n, err := strconv.Atoi(raw)
	if err != nil || n < 10 {
		return 60
	}
	if n > 3600 {
		return 3600
	}
	return n
}
func agentWhatsAppGatewayPost(ctx context.Context, endpoint, user, password string, timeout int, device string, payload []byte) (int, []byte, error) {
	client := &http.Client{Timeout: time.Duration(timeout) * time.Second}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, strings.NewReader(string(payload)))
	if err != nil {
		return 0, nil, err
	}
	req.SetBasicAuth(user, password)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")
	if device != "" {
		req.Header.Set("X-Device-Id", device)
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	return resp.StatusCode, data, err
}
