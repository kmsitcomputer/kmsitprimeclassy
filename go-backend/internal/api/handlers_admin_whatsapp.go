package api

import (
	"context"
	"encoding/json"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

var whatsappRequiredVariables = []string{"nomor_pesanan"}

func (s *Server) handleAdminWhatsAppSettingsShow(w http.ResponseWriter, r *http.Request) {
	data, err := s.loadAdminWhatsAppSnapshot(r.Context())
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAdminWhatsAppTestConnection(w http.ResponseWriter, r *http.Request) {
	status, err := admininfra.New(s.database, s.cfg.AppKey).WhatsAppStatus(r.Context())
	if err != nil {
		writeError(w, r, err)
		return
	}
	props, err := json.Marshal(map[string]any{"state": status["state"]})
	if err != nil {
		writeError(w, r, err)
		return
	}
	_, err = s.database.ExecContext(r.Context(), "INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) VALUES ('wa.enabled','0','integration.whatsapp',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)")
	if err != nil {
		writeError(w, r, err)
		return
	}
	var anchorID int64
	if err = s.database.QueryRowContext(r.Context(), "SELECT id FROM settings WHERE `key`='wa.enabled' AND `group`='integration.whatsapp'").Scan(&anchorID); err != nil {
		writeError(w, r, err)
		return
	}
	actor := UserFrom(r.Context())
	ua := r.UserAgent()
	if len(ua) > 500 {
		ua = ua[:500]
	}
	_, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\Setting',?,'whatsapp.connection.tested',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, anchorID, string(props), clientIP(r), ua)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, status, "OK", nil)
}

func (s *Server) loadAdminWhatsAppSnapshot(ctx context.Context) (map[string]any, error) {
	data, err := admininfra.New(s.database, s.cfg.AppKey).Snapshot(ctx, "whatsapp")
	if err != nil {
		return nil, err
	}
	definitions, err := agentadmin.New(s.database, s.cfg.AppKey).WhatsAppEvents(ctx, 0)
	if err != nil {
		return nil, err
	}
	events := make([]map[string]any, 0, len(definitions))
	for _, event := range definitions {
		key, _ := event["key"].(string)
		var templateStored bool
		if key != "" {
			if err := s.database.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM settings WHERE `key`=? AND `group`='integration.whatsapp' AND value<>'')", "wa.template."+key).Scan(&templateStored); err != nil {
				return nil, err
			}
		}
		events = append(events, map[string]any{"key": event["key"], "label": event["label"], "enabled": event["enabled"],
			"template": event["template"], "is_default_template": !templateStored, "variables": event["variables"], "required": whatsappRequiredVariables})
	}
	data["events"] = events
	var tableReady bool
	err = s.database.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_messages')").Scan(&tableReady)
	if err != nil && !db.IsMissingSchema(err) {
		return nil, err
	}
	data["table_ready"] = tableReady
	return data, nil
}
