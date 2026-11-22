package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
)

func (s *Server) handleAdminWhatsAppMessageRetry(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	id, ok := pathID(w, r, "message")
	if !ok {
		return
	}
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	accept := false
	if value, exists := raw["accept_duplicate_risk"]; exists {
		var valid bool
		accept, valid = parseLaravelBoolean(value)
		if !valid {
			writeError(w, r, httpx.Validation(map[string][]string{"accept_duplicate_risk": {i18n.ValidationMessage(locale, "boolean", "accept_duplicate_risk")}}))
			return
		}
	}
	item, err := loadWhatsAppMessage(ctx, s.database.DB, id)
	if err != nil {
		if err == sql.ErrNoRows {
			writeError(w, r, &httpx.Error{Status: 404, Message: msg(ctx, "system.not_found")})
		} else {
			writeError(w, r, err)
		}
		return
	}
	purpose, _ := item["purpose"].(string)
	body, _ := item["body"].(string)
	status, _ := item["status"].(string)
	if purpose == "otp" {
		writeError(w, r, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "whatsapp.retry_otp_forbidden")})
		return
	}
	if body == "" {
		writeError(w, r, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "whatsapp.retry_not_allowed")})
		return
	}
	if status == "unknown" && !accept {
		writeError(w, r, &httpx.Error{Status: 409, Message: i18n.Translate(locale, "whatsapp.retry_unknown_needs_confirm"), ErrorsAny: map[string]any{"code": "duplicate_risk_confirmation_required"}})
		return
	}
	if status != "failed" && status != "unknown" {
		writeError(w, r, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "whatsapp.retry_not_allowed")})
		return
	}
	res, err := s.database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='queued',attempts=0,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('failed','unknown')`, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	claimed, _ := res.RowsAffected()
	if claimed == 0 {
		writeError(w, r, &httpx.Error{Status: 409, Message: i18n.Translate(locale, "whatsapp.retry_not_allowed")})
		return
	}
	actor := UserFrom(ctx)
	props, _ := json.Marshal(map[string]any{"previous_status": status, "accepted_duplicate_risk": accept})
	ua := r.UserAgent()
	if len(ua) > 500 {
		ua = ua[:500]
	}
	if _, err = s.database.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\WhatsAppMessage',?,'whatsapp.message.retried',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, id, string(props), clientIP(r), ua); err != nil {
		writeError(w, r, err)
		return
	}
	config, err := admininfra.New(s.database, s.cfg.AppKey).Snapshot(ctx, "whatsapp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	if config["configured"] != true {
		if _, err = s.database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='skipped',attempts=1,last_attempt_at=UTC_TIMESTAMP(6),skip_reason='gateway_disabled',secret_body=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, id); err != nil {
			writeError(w, r, err)
			return
		}
	} else if purpose == "test" {
		var phone, text string
		if err = s.database.QueryRowContext(ctx, `SELECT recipient_phone,COALESCE(secret_body,body,'') FROM whatsapp_messages WHERE id=?`, id).Scan(&phone, &text); err != nil {
			writeError(w, r, err)
			return
		}
		outcome, sendErr := admininfra.New(s.database, s.cfg.AppKey).SendWhatsAppText(ctx, phone, text)
		if sendErr != nil {
			writeError(w, r, sendErr)
			return
		}
		maxAttempts, _ := item["max_attempts"].(int)
		err = applyAdminWhatsAppRetryOutcome(ctx, s.database, id, 1, maxAttempts, config, outcome)
		if err != nil {
			writeError(w, r, err)
			return
		}
	} else if err = jobs.EnqueueWhatsApp(ctx, s.database, id); err != nil {
		writeError(w, r, err)
		return
	}
	item, err = loadWhatsAppMessage(ctx, s.database.DB, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	item["can_retry"] = (item["status"] == "failed" || item["status"] == "unknown") && item["purpose"] != "otp"
	item["needs_duplicate_risk_confirmation"] = item["status"] == "unknown"
	httpx.OK(w, item, i18n.Translate(locale, "whatsapp.retry_queued"), nil)
}

func applyAdminWhatsAppRetryOutcome(ctx context.Context, database *db.DB, id int64, attempts, maxAttempts int, settings map[string]any, outcome map[string]any) error {
	state, _ := outcome["outcome"].(string)
	class, _ := outcome["error_class"].(string)
	message, _ := outcome["error_message"].(string)
	switch state {
	case "sent":
		_, err := database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='sent',attempts=?,last_attempt_at=UTC_TIMESTAMP(6),provider_message_id=?,sent_at=UTC_TIMESTAMP(6),error_class=NULL,error_message=NULL,secret_body=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, attempts, nullableDBValue(anyString(outcome["provider_message_id"])), id)
		return err
	case "failed", "unknown":
		_, err := database.ExecContext(ctx, `UPDATE whatsapp_messages SET status=?,attempts=?,last_attempt_at=UTC_TIMESTAMP(6),error_class=?,error_message=?,secret_body=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, state, attempts, class, message, id)
		return err
	case "retry":
		if attempts >= maxAttempts {
			_, err := database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='failed',attempts=?,last_attempt_at=UTC_TIMESTAMP(6),error_class=?,error_message=?,secret_body=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, attempts, "retries_exhausted:"+class, message, id)
			return err
		}
		backoff := 60
		if value, ok := settings["backoff_seconds"].(int); ok && value > 0 {
			backoff = value
		}
		due := time.Now().Add(time.Duration(backoff*attempts) * time.Second)
		if _, err := database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='queued',attempts=?,last_attempt_at=UTC_TIMESTAMP(6),error_class=?,error_message=?,next_attempt_at=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, attempts, class, message, due.UTC(), id); err != nil {
			return err
		}
		return jobs.EnqueueWhatsAppAt(ctx, database, id, due)
	default:
		_, err := database.ExecContext(ctx, `UPDATE whatsapp_messages SET status='unknown',attempts=?,last_attempt_at=UTC_TIMESTAMP(6),error_class='unexpected_response',error_message='Unexpected provider response.',secret_body=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, attempts, id)
		return err
	}
}

func nullableDBValue(value string) any {
	if value == "" {
		return nil
	}
	return value
}

func loadWhatsAppMessage(ctx context.Context, db *sql.DB, id int64) (map[string]any, error) {
	var purpose, status string
	var event, templateClass, recipientRole, senderDevice, phone, body, provider, errorClass, errorMessage, skip sql.NullString
	var orderID sql.NullInt64
	var attempts, maxAttempts int
	var queued, sent, created sql.NullTime
	err := db.QueryRowContext(ctx, `SELECT purpose,event,status,template_class,recipient_role,sender_device_id,order_id,recipient_phone,body,attempts,max_attempts,provider_message_id,error_class,error_message,skip_reason,queued_at,sent_at,created_at FROM whatsapp_messages WHERE id=?`, id).Scan(&purpose, &event, &status, &templateClass, &recipientRole, &senderDevice, &orderID, &phone, &body, &attempts, &maxAttempts, &provider, &errorClass, &errorMessage, &skip, &queued, &sent, &created)
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": id, "purpose": purpose, "event": nullStringValue(event), "status": status, "template_class": nullStringValue(templateClass), "recipient_role": nullStringValue(recipientRole), "sender_device_id": nullStringValue(senderDevice), "order_id": nullInt64Value(orderID), "recipient": maskWhatsAppPhone(phone), "body": nullStringValue(body), "attempts": attempts, "max_attempts": maxAttempts, "provider_message_id": nullStringValue(provider), "error_class": nullStringValue(errorClass), "error_message": nullStringValue(errorMessage), "skip_reason": nullStringValue(skip), "queued_at": additionalPaymentTimestamp(queued), "sent_at": additionalPaymentTimestamp(sent), "created_at": additionalPaymentTimestamp(created)}, nil
}
