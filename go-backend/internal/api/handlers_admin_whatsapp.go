package api

import (
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

var whatsappRequiredVariables = []string{"nomor_pesanan"}

func (s *Server) handleAdminWhatsAppSettingsShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	data, err := admininfra.New(s.database, s.cfg.AppKey).Snapshot(ctx, "whatsapp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	definitions, err := agentadmin.New(s.database, s.cfg.AppKey).WhatsAppEvents(ctx, 0)
	if err != nil {
		writeError(w, r, err)
		return
	}
	events := make([]map[string]any, 0, len(definitions))
	for _, event := range definitions {
		key, _ := event["key"].(string)
		var templateStored bool
		if key != "" {
			if err := s.database.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM settings WHERE `key`=? AND `group`='integration.whatsapp' AND value<>'')", "wa.template."+key).Scan(&templateStored); err != nil {
				writeError(w, r, err)
				return
			}
		}
		events = append(events, map[string]any{"key": event["key"], "label": event["label"], "enabled": event["enabled"],
			"template": event["template"], "is_default_template": !templateStored, "variables": event["variables"], "required": whatsappRequiredVariables})
	}
	data["events"] = events
	var tableReady bool
	err = s.database.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_messages')").Scan(&tableReady)
	if err != nil && !db.IsMissingSchema(err) {
		writeError(w, r, err)
		return
	}
	data["table_ready"] = tableReady
	httpx.OK(w, data, "OK", nil)
}
