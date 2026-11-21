package whatsappnotify

import (
	"context"
	"database/sql"
	"strconv"
	"strings"
)

type queryer interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
	QueryRowContext(context.Context, string, ...any) *sql.Row
}

// settings.go ports WhatsAppSettings (the notification-relevant subset): the
// global `wa.event.*` flags with per-Agent overrides in agent_whatsapp_event_settings,
// per-event template overrides, and max_attempts.

// EventEnabledFor is the effective switch for ONE Agen: its own scoped row wins,
// otherwise the legacy global flag (default OFF — nothing is sent to anyone until a
// Super Admin deliberately switches an event on).
func EventEnabledFor(ctx context.Context, q queryer, event string, agentID *int64) bool {
	if !Exists(event) {
		return false
	}
	if agentID != nil {
		var enabled sql.NullBool
		err := q.QueryRowContext(ctx, `SELECT enabled FROM agent_whatsapp_event_settings
			WHERE agent_id = ? AND event = ?`, *agentID, event).Scan(&enabled)
		if err == nil && enabled.Valid {
			return enabled.Bool
		}
	}
	return eventFlag(ctx, q, "wa.event."+event)
}

// TemplateFor is the effective template: the Agen's scoped row, the global override,
// then the built-in default.
func TemplateFor(ctx context.Context, q queryer, event string, agentID *int64) string {
	if agentID != nil {
		var template sql.NullString
		err := q.QueryRowContext(ctx, `SELECT template FROM agent_whatsapp_event_settings
			WHERE agent_id = ? AND event = ?`, *agentID, event).Scan(&template)
		if err == nil && template.Valid && template.String != "" {
			return template.String
		}
	}
	var override sql.NullString
	_ = q.QueryRowContext(ctx, `SELECT value FROM settings WHERE `+"`group`"+`='integration.whatsapp'
		AND `+"`key`"+` = ?`, "wa.template."+event).Scan(&override)
	if override.Valid && override.String != "" {
		return override.String
	}
	return DefaultTemplate(event)
}

// MaxAttempts is WhatsAppSettings::maxAttempts (1..5, default 3).
func MaxAttempts(ctx context.Context, q queryer) int64 {
	n := int64(3)
	var v sql.NullString
	if err := q.QueryRowContext(ctx, `SELECT value FROM settings WHERE `+"`group`"+`='integration.whatsapp'
		AND `+"`key`"+` = 'wa.max_attempts'`).Scan(&v); err == nil && v.Valid && v.String != "" {
		if parsed, perr := strconv.ParseInt(v.String, 10, 64); perr == nil {
			n = parsed
		}
	}
	if n < 1 {
		n = 1
	}
	if n > 5 {
		n = 5
	}
	return n
}

func eventFlag(ctx context.Context, q queryer, key string) bool {
	var v sql.NullString
	if err := q.QueryRowContext(ctx, `SELECT value FROM settings WHERE `+"`group`"+`='integration.whatsapp'
		AND `+"`key`"+` = ?`, key).Scan(&v); err != nil || !v.Valid || v.String == "" {
		return false
	}
	switch strings.ToLower(strings.TrimSpace(v.String)) {
	case "1", "true", "on", "yes":
		return true
	}
	return false
}
