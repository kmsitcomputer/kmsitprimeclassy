package api

import (
	"database/sql"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleAdminWhatsAppMessages(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var tableReady bool
	if err := s.database.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_messages')").Scan(&tableReady); err != nil {
		writeError(w, r, err)
		return
	}
	if !tableReady {
		httpx.OK(w, map[string]any{"migration_pending": true, "data": []any{}, "total": 0, "last_page": 1, "current_page": 1}, "OK", nil)
		return
	}
	where, args := []string{"1=1"}, []any{}
	for _, field := range []string{"status", "purpose", "event"} {
		if value := r.URL.Query().Get(field); value != "" {
			where = append(where, field+"=?")
			args = append(args, value)
		}
	}
	base := " FROM whatsapp_messages WHERE " + strings.Join(where, " AND ")
	var total int
	if err := s.database.QueryRowContext(ctx, "SELECT COUNT(*)"+base, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	perPage := 20
	if value := r.URL.Query().Get("per_page"); value != "" {
		if n, err := strconv.Atoi(value); err == nil {
			perPage = n
		}
	}
	if perPage > 100 {
		perPage = 100
	}
	if perPage < 1 {
		perPage = 1
	}
	page := 1
	if n, err := strconv.Atoi(r.URL.Query().Get("page")); err == nil && n > 0 {
		page = n
	}
	listArgs := append(append([]any{}, args...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT id,purpose,event,template_class,recipient_role,sender_device_id,order_id,recipient_phone,body,status,attempts,max_attempts,provider_message_id,error_class,error_message,skip_reason,queued_at,sent_at,created_at`+base+` ORDER BY id DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	data := []map[string]any{}
	for rows.Next() {
		var id int64
		var purpose, status string
		var event, templateClass, recipientRole, senderDevice, phone, body, provider, errorClass, errorMessage, skip sql.NullString
		var orderID sql.NullInt64
		var attempts, maxAttempts int
		var queued, sent, created sql.NullTime
		if err := rows.Scan(&id, &purpose, &event, &templateClass, &recipientRole, &senderDevice, &orderID, &phone, &body, &status, &attempts, &maxAttempts, &provider, &errorClass, &errorMessage, &skip, &queued, &sent, &created); err != nil {
			writeError(w, r, err)
			return
		}
		data = append(data, map[string]any{"id": id, "purpose": purpose, "event": nullStringValue(event), "status": status, "template_class": nullStringValue(templateClass), "recipient_role": nullStringValue(recipientRole), "sender_device_id": nullStringValue(senderDevice), "order_id": nullInt64Value(orderID), "recipient": maskWhatsAppPhone(phone), "body": nullStringValue(body), "attempts": attempts, "max_attempts": maxAttempts, "provider_message_id": nullStringValue(provider), "error_class": nullStringValue(errorClass), "error_message": nullStringValue(errorMessage), "skip_reason": nullStringValue(skip), "queued_at": additionalPaymentTimestamp(queued), "sent_at": additionalPaymentTimestamp(sent), "created_at": additionalPaymentTimestamp(created), "can_retry": (status == "failed" || status == "unknown") && purpose != "otp", "needs_duplicate_risk_confirmation": status == "unknown"})
	}
	if err := rows.Err(); err != nil {
		writeError(w, r, err)
		return
	}
	last := (total + perPage - 1) / perPage
	if last < 1 {
		last = 1
	}
	httpx.OK(w, map[string]any{"migration_pending": false, "data": data, "total": total, "last_page": last, "current_page": page}, "OK", nil)
}

func maskWhatsAppPhone(phone sql.NullString) any {
	if !phone.Valid || phone.String == "" {
		return nil
	}
	v := phone.String
	if len(v) < 3 {
		return v
	}
	return v[:2] + "••••" + v[len(v)-3:]
}
func nullInt64Value(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}
