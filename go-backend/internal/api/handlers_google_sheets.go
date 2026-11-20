package api

import (
	"database/sql"
	_ "embed"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// The definitions and catalog are the serialized DatasetRegistry contract from Laravel.
// Keep this fixture synchronized when DatasetRegistry changes.
//
//go:embed google_sheets_catalog.json
var googleSheetsCatalogJSON []byte

func (s *Server) handleGoogleSheetsIndex(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	if !actor.IsRole(auth.RoleSuperAdmin) && (!actor.IsRole(auth.RoleAgen, auth.RoleAdmin) || actor.AgentID == nil) {
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	var catalog map[string]any
	if err := json.Unmarshal(googleSheetsCatalogJSON, &catalog); err != nil {
		writeError(w, r, err)
		return
	}
	admin := actor.IsRole(auth.RoleAdmin)
	if admin {
		for _, name := range []string{"datasets", "dataset_catalog", "dataset_defaults"} {
			if values, ok := catalog[name].(map[string]any); ok {
				delete(values, "financial_summary")
			}
		}
	}
	query := `SELECT id,spreadsheet_id,agent_id,spreadsheet_title,connection_status,last_tested_at,last_error_code,created_at,updated_at FROM sheets_destinations`
	args := []any{}
	if !actor.IsRole(auth.RoleSuperAdmin) {
		query += ` WHERE agent_id=?`
		args = append(args, *actor.AgentID)
	}
	destinations, err := s.queryMaps(r, query, args...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	ids := make([]any, 0, len(destinations))
	for _, destination := range destinations {
		ids = append(ids, destination["id"])
	}
	configs := []map[string]any{}
	if len(ids) > 0 {
		marks := strings.TrimRight(strings.Repeat("?,", len(ids)), ",")
		configs, err = s.queryMaps(r, `SELECT c.id,c.destination_id,c.name,c.tab,c.dataset,c.columns,c.filters,c.created_at,c.updated_at,d.id AS destination__id,d.spreadsheet_id AS destination__spreadsheet_id,d.agent_id AS destination__agent_id,d.spreadsheet_title AS destination__spreadsheet_title,d.connection_status AS destination__connection_status,d.last_tested_at AS destination__last_tested_at,d.last_error_code AS destination__last_error_code,d.created_at AS destination__created_at,d.updated_at AS destination__updated_at FROM sheets_configs c JOIN sheets_destinations d ON d.id=c.destination_id WHERE c.destination_id IN (`+marks+`)`, ids...)
		if err != nil {
			writeError(w, r, err)
			return
		}
		for _, config := range configs {
			for _, key := range []string{"columns", "filters"} {
				if raw, ok := config[key].([]byte); ok {
					var decoded any
					if json.Unmarshal(raw, &decoded) == nil {
						config[key] = decoded
					}
				}
			}
			destination := map[string]any{}
			for key := range config {
				if strings.HasPrefix(key, "destination__") {
					name := strings.TrimPrefix(key, "destination__")
					value := config[key]
					if name == "id" || name == "agent_id" {
						value = numericValue(value)
					}
					destination[name] = value
					delete(config, key)
				}
			}
			config["destination"] = destination
			config["id"] = numericValue(config["id"])
			config["destination_id"] = numericValue(config["destination_id"])
		}
	}
	if admin {
		filtered := configs[:0]
		for _, config := range configs {
			if config["dataset"] != "financial_summary" {
				filtered = append(filtered, config)
			}
		}
		configs = filtered
	}
	logQuery := `SELECT id,config_id,dataset,tab,actor_id,actor_role,agent_scope,started_at,completed_at,status,rows_processed,rows_success,rows_failed,error_summary FROM sheets_sync_logs`
	logArgs := []any{}
	if !actor.IsRole(auth.RoleSuperAdmin) {
		logQuery += ` WHERE agent_scope=?`
		logArgs = append(logArgs, *actor.AgentID)
	}
	logQuery += ` ORDER BY id DESC LIMIT 100`
	logs, err := s.queryMaps(r, logQuery, logArgs...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	for _, log := range logs {
		for _, key := range []string{"id", "config_id", "actor_id", "agent_scope", "rows_processed", "rows_success", "rows_failed"} {
			log[key] = numericValue(log[key])
		}
	}
	credential := googleSheetsCredentialInfo(s.cfg.GoogleSheets.CredentialsPath, s.cfg.StorageRoot)
	enabled := s.cfg.GoogleSheets.Enabled
	status := "not_checked"
	if !enabled {
		status = "disabled"
	}
	data := map[string]any{
		"destinations": destinations, "configs": configs,
		"datasets": catalog["datasets"], "dataset_catalog": catalog["dataset_catalog"],
		"money_positions": catalog["money_positions"], "dataset_defaults": catalog["dataset_defaults"],
		"dataset_filters": catalog["dataset_filters"], "logs": logs, "enabled": enabled,
		"service_account_email": credential["client_email"], "connection_status": status,
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) queryMaps(r *http.Request, query string, args ...any) ([]map[string]any, error) {
	rows, err := s.database.QueryContext(r.Context(), query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	columns, err := rows.Columns()
	if err != nil {
		return nil, err
	}
	result := make([]map[string]any, 0)
	for rows.Next() {
		values := make([]any, len(columns))
		destinations := make([]any, len(columns))
		for i := range values {
			destinations[i] = &values[i]
		}
		if err := rows.Scan(destinations...); err != nil {
			return nil, err
		}
		item := make(map[string]any, len(columns))
		for i, column := range columns {
			value := values[i]
			if bytes, ok := value.([]byte); ok {
				value = string(bytes)
			}
			item[column] = value
		}
		result = append(result, item)
	}
	return result, rows.Err()
}

func numericValue(value any) any {
	switch n := value.(type) {
	case int64:
		return n
	case int:
		return n
	case []byte:
		if parsed, err := strconv.ParseInt(string(n), 10, 64); err == nil {
			return parsed
		}
	case string:
		if parsed, err := strconv.ParseInt(n, 10, 64); err == nil {
			return parsed
		}
	}
	return value
}

func googleSheetsCredentialInfo(configuredPath, storageRoot string) map[string]any {
	path := configuredPath
	if path == "" {
		if storageRoot != "" {
			path = filepath.Join(storageRoot, "private/google/service-account.json")
		} else {
			path = "/app/storage/app/private/google/service-account.json"
		}
	}
	info := map[string]any{"resolved_path": path, "file_found": false, "readable": false, "json_valid": false, "type": nil, "project_id": nil, "client_email": nil, "private_key_present": false}
	stat, err := os.Stat(path)
	if err != nil || !stat.Mode().IsRegular() {
		return info
	}
	info["file_found"] = true
	data, err := os.ReadFile(path)
	if err != nil {
		return info
	}
	info["readable"] = true
	var credential map[string]any
	if json.Unmarshal(data, &credential) != nil {
		return info
	}
	info["json_valid"] = true
	for _, key := range []string{"type", "project_id", "client_email"} {
		if value, ok := credential[key]; ok {
			info[key] = value
		}
	}
	if private, ok := credential["private_key"].(string); ok {
		info["private_key_present"] = strings.TrimSpace(private) != ""
	}
	return info
}

func (s *Server) handleGoogleSheetsConnection(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	var request struct {
		DestinationID json.RawMessage `json:"destination_id"`
	}
	if err := httpx.DecodeJSON(r, &request); err != nil {
		writeError(w, r, err)
		return
	}
	rawID := strings.TrimSpace(string(request.DestinationID))
	if rawID == "" || rawID == "null" || rawID == `""` {
		writeError(w, r, httpx.Validation(map[string][]string{"destination_id": {i18n.ValidationMessage(LocaleFrom(r.Context()), "required", "destination_id")}}))
		return
	}
	id, err := strconv.ParseInt(strings.Trim(rawID, `"`), 10, 64)
	if err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"destination_id": {i18n.ValidationMessage(LocaleFrom(r.Context()), "integer", "destination_id")}}))
		return
	}
	var spreadsheet string
	var agentID sql.NullInt64
	if err = s.database.QueryRowContext(r.Context(), `SELECT spreadsheet_id,agent_id FROM sheets_destinations WHERE id=?`, id).Scan(&spreadsheet, &agentID); err == sql.ErrNoRows {
		writeError(w, r, httpx.Validation(map[string][]string{"destination_id": {i18n.ValidationMessage(LocaleFrom(r.Context()), "exists", "destination_id")}}))
		return
	} else if err != nil {
		writeError(w, r, err)
		return
	}
	if !actor.IsRole(auth.RoleSuperAdmin) && (actor.AgentID == nil || !agentID.Valid || agentID.Int64 != *actor.AgentID) {
		httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "system.forbidden"), nil)
		return
	}
	credential := googleSheetsCredentialInfo(s.cfg.GoogleSheets.CredentialsPath, s.cfg.StorageRoot)
	meta, apiErr := s.googleSheetsMetadata(r, spreadsheet)
	now := time.Now().UTC()
	if apiErr != nil {
		_, updateErr := s.database.ExecContext(r.Context(), `UPDATE sheets_destinations SET connection_status='failed',last_tested_at=?,last_error_code=? WHERE id=?`, now, apiErr.Code, id)
		if updateErr != nil {
			writeError(w, r, updateErr)
			return
		}
		credentialFields := map[string]any{}
		for _, key := range []string{"resolved_path", "file_found", "readable", "json_valid", "type", "project_id", "client_email", "private_key_present"} {
			credentialFields[key] = credential[key]
		}
		httpx.Failure(w, http.StatusUnprocessableEntity, apiErr.Message, map[string]any{"code": []string{apiErr.Code}, "credential": []map[string]any{credentialFields}})
		return
	}
	_, err = s.database.ExecContext(r.Context(), `UPDATE sheets_destinations SET spreadsheet_title=?,connection_status='connected',last_tested_at=?,last_error_code=NULL WHERE id=?`, meta.Title, now, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"status": "connected", "spreadsheet_title": meta.Title, "spreadsheet_id": spreadsheet, "service_account_email": credential["client_email"], "last_tested_at": now}, "OK", nil)
}

func (s *Server) handleGoogleSheetsDestination(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	var request struct {
		SpreadsheetID json.RawMessage `json:"spreadsheet_id"`
		AgentID       json.RawMessage `json:"agent_id"`
	}
	if err := httpx.DecodeJSON(r, &request); err != nil {
		writeError(w, r, err)
		return
	}
	idText := strings.TrimSpace(laravelStringInput(request.SpreadsheetID))
	if match := googleSpreadsheetURL.FindStringSubmatch(idText); len(match) > 1 {
		idText = match[1]
	}
	fields := map[string][]string{}
	if idText == "" || string(request.SpreadsheetID) == "null" {
		fields["spreadsheet_id"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "required", "spreadsheet_id")}
	} else {
		if utf8.RuneCountInString(idText) > 150 {
			fields["spreadsheet_id"] = append(fields["spreadsheet_id"], i18n.ValidationMessage(LocaleFrom(r.Context()), "max.string", "spreadsheet_id", "max", "150"))
		}
		if !googleSpreadsheetID.MatchString(idText) {
			fields["spreadsheet_id"] = append(fields["spreadsheet_id"], i18n.ValidationMessage(LocaleFrom(r.Context()), "regex", "spreadsheet_id"))
		}
		var exists int
		if err := s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM sheets_destinations WHERE spreadsheet_id=?)`, idText).Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if exists != 0 {
			fields["spreadsheet_id"] = append(fields["spreadsheet_id"], i18n.ValidationMessage(LocaleFrom(r.Context()), "unique", "spreadsheet_id"))
		}
	}
	var selectedAgent *int64
	if string(request.AgentID) != "" && string(request.AgentID) != "null" && string(request.AgentID) != `""` {
		n, err := strconv.ParseInt(strings.Trim(strings.TrimSpace(string(request.AgentID)), `"`), 10, 64)
		if err != nil {
			fields["agent_id"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "integer", "agent_id")}
		} else {
			var role string
			err = s.database.QueryRowContext(r.Context(), `SELECT rl.slug FROM users u JOIN roles rl ON rl.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL`, n).Scan(&role)
			if err != nil || role != auth.RoleAgen {
				fields["agent_id"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "exists", "agent_id")}
			} else {
				selectedAgent = &n
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if !actor.IsRole(auth.RoleSuperAdmin) {
		selectedAgent = actor.AgentID
	}
	var agent any
	if selectedAgent != nil {
		agent = *selectedAgent
	}
	result, err := s.database.ExecContext(r.Context(), `INSERT INTO sheets_destinations(spreadsheet_id,agent_id,created_at,updated_at) VALUES(?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, idText, agent)
	if err != nil {
		writeError(w, r, err)
		return
	}
	newID, err := result.LastInsertId()
	if err != nil {
		writeError(w, r, err)
		return
	}
	rows, err := s.queryMaps(r, `SELECT id,spreadsheet_id,agent_id,created_at,updated_at FROM sheets_destinations WHERE id=?`, newID)
	if err != nil || len(rows) == 0 {
		if err != nil {
			writeError(w, r, err)
		} else {
			writeError(w, r, fmt.Errorf("created destination missing"))
		}
		return
	}
	rows[0]["id"] = numericValue(rows[0]["id"])
	rows[0]["agent_id"] = numericValue(rows[0]["agent_id"])
	if actor.IsRole(auth.RoleSuperAdmin) && len(request.AgentID) == 0 {
		delete(rows[0], "agent_id")
	}
	httpx.Created(w, rows[0], "Created")
}

var googleSpreadsheetID = regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)
var googleSpreadsheetURL = regexp.MustCompile(`docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)`)

func laravelStringInput(raw json.RawMessage) string {
	value := strings.TrimSpace(string(raw))
	switch {
	case value == "", value == "null", value == "false":
		return ""
	case value == "true":
		return "1"
	case strings.HasPrefix(value, `"`):
		var decoded string
		if json.Unmarshal(raw, &decoded) == nil {
			return decoded
		}
	case strings.HasPrefix(value, "{") || strings.HasPrefix(value, "["):
		return "Array"
	}
	return value
}
