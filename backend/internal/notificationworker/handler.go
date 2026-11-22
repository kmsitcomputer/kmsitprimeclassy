// Package notificationworker processes Laravel-compatible durable lifecycle jobs.
package notificationworker

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"html"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
	"github.com/kmsitcomputer/primeclassy-go/internal/notifications"
	"github.com/kmsitcomputer/primeclassy-go/internal/whatsappnotify"
)

type Handler struct {
	database *db.DB
	appKey   string
	infra    *admininfra.Service
}

func New(database *db.DB, appKey string) *Handler {
	return &Handler{database: database, appKey: appKey, infra: admininfra.New(database, appKey)}
}

type waMessage struct {
	id, userID, agentID, orderID                                         int64
	userValid, agentValid, orderValid                                    bool
	purpose, event, role, templateClass, phone, body, secretBody, status string
	errorClass                                                           string
	attempts, maxAttempts                                                int
}

func (h *Handler) HandleWhatsApp(ctx context.Context, id int64) error {
	m, claimed, err := h.claimWhatsApp(ctx, id)
	if err != nil || !claimed {
		return err
	}
	if m.status != "queued" {
		return nil
	}

	snapshot, err := h.infra.Snapshot(ctx, "whatsapp")
	if err != nil {
		return err
	}
	if snapshot["configured"] != true {
		return h.finishWhatsApp(ctx, m, "skipped", "gateway_disabled", "", "", nil)
	}
	if reason := h.authorizeWhatsApp(ctx, m); reason != "" {
		return h.finishWhatsApp(ctx, m, "skipped", reason, "", "", nil)
	}

	deviceID := ""
	if m.purpose != "test" || m.agentValid {
		if !m.agentValid {
			return h.finishWhatsApp(ctx, m, "skipped", "agent_device_not_ready", "", "", nil)
		}
		if err := h.database.QueryRowContext(ctx, `SELECT device_id FROM agent_whatsapp_devices
			WHERE agent_id=? AND paired_at IS NOT NULL`, m.agentID).Scan(&deviceID); err != nil || deviceID == "" {
			return h.finishWhatsApp(ctx, m, "skipped", "agent_device_not_ready", "", "", nil)
		}
		if _, err := h.database.ExecContext(ctx, `UPDATE whatsapp_messages SET sender_device_id=?,updated_at=UTC_TIMESTAMP(6)
			WHERE id=? AND status='sending'`, deviceID, id); err != nil {
			return err
		}
	}
	if limited, delay, scope, err := h.volumeBlock(ctx, m); err != nil {
		return err
	} else if limited {
		if !strings.HasPrefix(m.errorClass, "rate_limited") {
			properties := fmt.Sprintf(`{"scope":%q,"purpose":%q,"event":%q}`, scope, m.purpose, m.event)
			if _, err := h.database.ExecContext(ctx, `INSERT INTO activity_logs
				(causer_id,subject_type,subject_id,event,description,properties,created_at)
				VALUES (NULL,'App\\Models\\WhatsAppMessage',?,'whatsapp.message.rate_limited',NULL,?,UTC_TIMESTAMP(6))`, m.id, properties); err != nil {
				return err
			}
		}
		if m.purpose != "transaction" {
			return h.finishWhatsApp(ctx, m, "skipped", "rate_limited_"+scope, "rate_limited:"+scope, "", nil)
		}
		queuedAt, qerr := h.queuedAt(ctx, id)
		if qerr != nil {
			return qerr
		}
		if !queuedAt.IsZero() && queuedAt.Before(time.Now().Add(-6*time.Hour)) {
			return h.finishWhatsApp(ctx, m, "failed", "", "rate_limited_expired", "Not sent: volume limit held it past its useful life.", nil)
		}
		return h.requeueWhatsApp(ctx, m, delay, "rate_limited:"+scope, true)
	}

	text := m.body
	if m.secretBody != "" {
		text, err = cryptox.DecryptString(h.appKey, m.secretBody)
		if err != nil {
			return h.finishWhatsApp(ctx, m, "failed", "", "secret_decryption_failed", "Message could not be decrypted.", nil)
		}
	}
	var result map[string]any
	if deviceID != "" {
		result, err = h.infra.SendWhatsAppTextFromDevice(ctx, m.phone, text, deviceID)
	} else {
		result, err = h.infra.SendWhatsAppText(ctx, m.phone, text)
	}
	if err != nil {
		return h.finishWhatsApp(ctx, m, "unknown", "", "transport_error", sanitize(err.Error()), nil)
	}
	outcome, _ := result["outcome"].(string)
	errorClass, _ := result["error_class"].(string)
	errorMessage, _ := result["error_message"].(string)
	providerID, _ := result["provider_message_id"].(string)
	switch outcome {
	case "sent":
		return h.finishWhatsApp(ctx, m, "sent", "", "", "", providerString(providerID))
	case "failed":
		return h.finishWhatsApp(ctx, m, "failed", "", errorClass, errorMessage, nil)
	case "unknown":
		return h.finishWhatsApp(ctx, m, "unknown", "", errorClass, errorMessage, nil)
	case "retry":
		if m.purpose == "otp" {
			return h.finishWhatsApp(ctx, m, "failed", "", "otp_not_delivered:"+errorClass, errorMessage, nil)
		}
		if m.attempts >= m.maxAttempts {
			return h.finishWhatsApp(ctx, m, "failed", "", "retries_exhausted:"+errorClass, errorMessage, nil)
		}
		backoff := integer(snapshot["backoff_seconds"], 60)
		return h.requeueWhatsApp(ctx, m, time.Duration(backoff*m.attempts)*time.Second, errorClass, false)
	default:
		return h.finishWhatsApp(ctx, m, "unknown", "", "unexpected_response", "Unexpected provider response.", nil)
	}
}

// HandleEmail follows SendTransactionalEmailJob: one attempt, durable ledger result, no resend.
func (h *Handler) HandleEmail(ctx context.Context, id int64) error {
	var event, recipient, subject, body, state string
	var agentID, orderID sql.NullInt64
	tx, err := h.database.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	err = tx.QueryRowContext(ctx, `SELECT event,recipient_email,subject,body,status,agent_id,order_id
		FROM email_messages WHERE id=? FOR UPDATE`, id).Scan(&event, &recipient, &subject, &body, &state, &agentID, &orderID)
	if errors.Is(err, sql.ErrNoRows) || err == nil && state != "queued" {
		_ = tx.Rollback()
		return nil
	}
	if err != nil {
		_ = tx.Rollback()
		return err
	}
	if _, err = tx.ExecContext(ctx, `UPDATE email_messages SET status='sending',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, id); err != nil {
		_ = tx.Rollback()
		return err
	}
	if err = tx.Commit(); err != nil {
		return err
	}

	snapshot, err := h.infra.Snapshot(ctx, "smtp")
	if err != nil {
		return err
	}
	if snapshot["enabled"] != true || snapshot["complete"] != true || snapshot["transactional_enabled"] != true {
		return h.finishEmail(ctx, id, "skipped", "mail_disabled", "", "", event, agentID, orderID)
	}
	sent, detail, err := h.infra.SendTransactionalMail(ctx, recipient, subject, body)
	if err != nil {
		return err
	}
	if sent {
		return h.finishEmail(ctx, id, "sent", "", "", "", event, agentID, orderID)
	}
	if detail == "smtp_disabled" {
		return h.finishEmail(ctx, id, "skipped", "mail_disabled", "", "", event, agentID, orderID)
	}
	class, message := smtpFailure(detail)
	return h.finishEmail(ctx, id, "failed", "", class, message, event, agentID, orderID)
}

// HandleRegistrationEmail delivers the queued AccountRegisteredMail mailable.
// SMTP stays a runtime setting, and queue retries remain governed by the
// serialized mailable's Laravel tries/backoff values.
func (h *Handler) HandleRegistrationEmail(ctx context.Context, recipient, name string) error {
	snapshot, err := h.infra.Snapshot(ctx, "smtp")
	if err != nil {
		return err
	}
	if snapshot["enabled"] != true || snapshot["complete"] != true {
		return nil // Laravel's configured log-mailer has no external side effect.
	}
	escape := func(value string) string {
		return strings.NewReplacer("&#34;", "&quot;", "&#39;", "&#039;").Replace(html.EscapeString(value))
	}
	subject := i18n.Translate("id", "auth.registered_mail_subject")
	body := escape(subject) + "<br><br>" + escape(name) + ",<br><br>" + escape(i18n.Translate("id", "auth.registered_mail_body"))
	sent, detail, err := h.infra.SendRegisteredAccountMail(ctx, recipient, subject, body)
	if err != nil {
		return err
	}
	if sent || detail == "smtp_disabled" {
		return nil
	}
	return fmt.Errorf("registered account email delivery failed: %s", detail)
}

// smtpFailure matches the exception class and formatted response Symfony's
// Laravel mail transport persists when an SMTP server rejects a command.
func smtpFailure(detail string) (string, string) {
	fields := strings.Fields(detail)
	if len(fields) > 0 {
		code, err := strconv.Atoi(fields[0])
		if err == nil && code >= 400 && code <= 599 {
			response := strings.TrimSpace(strings.TrimPrefix(detail, fields[0]))
			if len(response) >= 2 && response[0] == '"' && response[len(response)-1] == '"' {
				response = response[1 : len(response)-1]
			}
			message := fmt.Sprintf(`Expected response code "250/251/252" but got code "%d", with message "%d %s".`, code, code, response)
			if len(message) > 200 {
				message = message[:200]
			}
			return "UnexpectedResponseException", message
		}
	}
	if len(detail) > 200 {
		detail = detail[:200]
	}
	return "TransportException", detail
}

func (h *Handler) claimWhatsApp(ctx context.Context, id int64) (waMessage, bool, error) {
	var m waMessage
	tx, err := h.database.BeginTx(ctx, nil)
	if err != nil {
		return m, false, err
	}
	defer tx.Rollback()
	var user, agent, order sql.NullInt64
	var secret sql.NullString
	err = tx.QueryRowContext(ctx, `SELECT id,purpose,IFNULL(event,''),IFNULL(recipient_role,''),IFNULL(template_class,''),
		IFNULL(recipient_phone,''),IFNULL(body,''),secret_body,status,attempts,max_attempts,IFNULL(error_class,''),recipient_user_id,agent_id,order_id
		FROM whatsapp_messages WHERE id=? FOR UPDATE`, id).Scan(&m.id, &m.purpose, &m.event, &m.role, &m.templateClass,
		&m.phone, &m.body, &secret, &m.status, &m.attempts, &m.maxAttempts, &m.errorClass, &user, &agent, &order)
	if errors.Is(err, sql.ErrNoRows) {
		return m, false, nil
	}
	if err != nil {
		return m, false, err
	}
	if m.status != "queued" {
		return m, false, nil
	}
	if secret.Valid {
		m.secretBody = secret.String
	}
	if user.Valid {
		m.userID, m.userValid = user.Int64, true
	}
	if agent.Valid {
		m.agentID, m.agentValid = agent.Int64, true
	}
	if order.Valid {
		m.orderID, m.orderValid = order.Int64, true
	}
	m.attempts++
	if _, err := tx.ExecContext(ctx, `UPDATE whatsapp_messages SET status='sending',attempts=?,last_attempt_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='queued'`, m.attempts, id); err != nil {
		return m, false, err
	}
	return m, true, tx.Commit()
}

func (h *Handler) authorizeWhatsApp(ctx context.Context, m waMessage) string {
	if m.purpose != "transaction" {
		return ""
	}
	if m.templateClass == "restricted" {
		if m.secretBody != "" || !restrictedEvent(m.role, m.event) {
			return "restricted_not_allowed"
		}
		if m.role == "recipient" {
			if m.userValid || !m.orderValid || !m.agentValid {
				return "restricted_not_allowed"
			}
			var agent int64
			var phone sql.NullString
			if err := h.database.QueryRowContext(ctx, `SELECT agent_id,recipient_phone_snapshot FROM orders WHERE id=?`, m.orderID).Scan(&agent, &phone); err != nil || agent != m.agentID {
				return "recipient_order_mismatch"
			}
			if !phone.Valid || whatsappnotify.NormalizeMSISDN(phone.String) != m.phone {
				return "recipient_changed"
			}
			return ""
		}
		if m.role == "registrant" && m.userValid && !m.orderValid {
			var agent sql.NullInt64
			var phone sql.NullString
			if err := h.database.QueryRowContext(ctx, `SELECT agent_id,phone FROM users WHERE id=?`, m.userID).Scan(&agent, &phone); err != nil {
				return "recipient_order_mismatch"
			}
			owner := m.userID
			if agent.Valid {
				owner = agent.Int64
			}
			if !m.agentValid || owner != m.agentID {
				return "recipient_order_mismatch"
			}
			if !phone.Valid || whatsappnotify.NormalizeMSISDN(phone.String) != m.phone {
				return "recipient_changed"
			}
			return ""
		}
		return "restricted_not_allowed"
	}
	if !m.userValid || whatsappnotify.MsisdnForUser(ctx, h.database, m.userID) != m.phone {
		return "number_not_verified"
	}
	return ""
}

func restrictedEvent(role, event string) bool {
	if role == "registrant" {
		return event == "account_registered"
	}
	if role != "recipient" {
		return false
	}
	switch event {
	case whatsappnotify.EventOrderCreated, whatsappnotify.EventOrderProcessed, whatsappnotify.EventOrderShipped,
		whatsappnotify.EventOrderDelivered, whatsappnotify.EventOrderCancelled:
		return true
	}
	return false
}

func (h *Handler) volumeBlock(ctx context.Context, m waMessage) (bool, time.Duration, string, error) {
	if m.purpose == "test" {
		return false, 0, "", nil
	}
	snapshot, err := h.infra.Snapshot(ctx, "whatsapp")
	if err != nil {
		return false, 0, "", err
	}
	limits, _ := snapshot["limits"].(map[string]int)
	globalLimit := limits["global_per_minute"]
	if globalLimit < 1 {
		globalLimit = 20
	}
	var count int
	err = h.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM whatsapp_messages WHERE id<>? AND status IN ('sent','sending','unknown') AND last_attempt_at>=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE`, m.id).Scan(&count)
	if err != nil {
		return false, 0, "", err
	}
	if count >= globalLimit {
		return true, time.Minute, "global", nil
	}
	if m.purpose == "otp" {
		return false, 0, "", nil
	}
	perRecipient := limits["per_recipient_per_hour"]
	if perRecipient < 1 {
		perRecipient = 6
	}
	err = h.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM whatsapp_messages WHERE id<>? AND recipient_phone=? AND status IN ('sent','sending','unknown') AND last_attempt_at>=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR`, m.id, m.phone).Scan(&count)
	if err != nil {
		return false, 0, "", err
	}
	if count >= perRecipient {
		return true, 10 * time.Minute, "recipient", nil
	}
	return false, 0, "", nil
}

func (h *Handler) queuedAt(ctx context.Context, id int64) (time.Time, error) {
	var t sql.NullTime
	err := h.database.QueryRowContext(ctx, `SELECT queued_at FROM whatsapp_messages WHERE id=?`, id).Scan(&t)
	if err != nil {
		return time.Time{}, err
	}
	if !t.Valid {
		return time.Time{}, nil
	}
	return t.Time, nil
}

func (h *Handler) finishWhatsApp(ctx context.Context, m waMessage, status, skipReason, class, message string, providerID *string) error {
	tx, err := h.database.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	_, err = tx.ExecContext(ctx, `UPDATE whatsapp_messages SET status=?,skip_reason=?,error_class=?,error_message=?,
		provider_message_id=?,sent_at=IF(?='sent',UTC_TIMESTAMP(6),sent_at),secret_body=NULL,next_attempt_at=NULL,updated_at=UTC_TIMESTAMP(6)
		WHERE id=? AND status='sending'`, status, nullable(skipReason), nullable(class), nullable(sanitize(message)), providerID, status, m.id)
	if err != nil {
		return err
	}
	// Laravel's AdminNotificationObserver only raises delivery.failed for
	// transactional WhatsApp messages. Test, promotional, and OTP traffic do
	// not create operator alerts.
	if m.purpose == "transaction" && (status == "failed" || status == "unknown") {
		label, _ := whatsappnotify.Def(m.event)
		var agentID, orderID *int64
		if m.agentValid {
			v := m.agentID
			agentID = &v
		}
		if m.orderValid {
			v := m.orderID
			orderID = &v
		}
		if err := notifications.RecordDeliveryFailed(ctx, tx, "whatsapp", m.event, agentID, orderID, "whatsapp_message", m.id, status, label.Label); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func (h *Handler) requeueWhatsApp(ctx context.Context, m waMessage, delay time.Duration, class string, releaseAttempt bool) error {
	available := time.Now().Add(delay)
	tx, err := h.database.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	attempts := m.attempts
	if releaseAttempt && attempts > 0 {
		attempts--
	}
	errorClass := class
	if strings.HasPrefix(class, "rate_limited:") {
		errorClass = class
	}
	_, err = tx.ExecContext(ctx, `UPDATE whatsapp_messages SET status='queued',attempts=?,error_class=?,next_attempt_at=?,updated_at=UTC_TIMESTAMP(6)
		WHERE id=? AND status='sending'`, attempts, nullable(errorClass), available, m.id)
	if err != nil {
		return err
	}
	if err := jobs.EnqueueWhatsAppTx(ctx, tx, m.id, available); err != nil {
		return err
	}
	return tx.Commit()
}

func (h *Handler) finishEmail(ctx context.Context, id int64, status, skipReason, class, message, event string,
	agentID, orderID sql.NullInt64) error {
	tx, err := h.database.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	_, err = tx.ExecContext(ctx, `UPDATE email_messages SET status=?,skip_reason=?,error_class=?,error_message=?,sent_at=IF(?='sent',UTC_TIMESTAMP(6),sent_at),updated_at=UTC_TIMESTAMP(6)
		WHERE id=? AND status='sending'`, status, nullable(skipReason), nullable(class), nullable(sanitize(message)), status, id)
	if err != nil {
		return err
	}
	if status == "failed" {
		var agent, order *int64
		if agentID.Valid {
			v := agentID.Int64
			agent = &v
		}
		if orderID.Valid {
			v := orderID.Int64
			order = &v
		}
		label, _ := whatsappnotify.Def(event)
		if err := notifications.RecordDeliveryFailed(ctx, tx, "email", event, agent, order, "email_message", id, status, label.Label); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func nullable(value string) any {
	if value == "" {
		return nil
	}
	return value
}
func providerString(value string) *string {
	if value == "" {
		return nil
	}
	return &value
}
func integer(v any, fallback int) int {
	if value, ok := v.(int); ok && value > 0 {
		return value
	}
	return fallback
}
func sanitize(s string) string {
	s = strings.Join(strings.Fields(s), " ")
	if len(s) > 255 {
		return s[:255]
	}
	return s
}

// compile-time guard: the handler satisfies the durable jobs runner contract.
var _ jobs.Handler = (*Handler)(nil)
