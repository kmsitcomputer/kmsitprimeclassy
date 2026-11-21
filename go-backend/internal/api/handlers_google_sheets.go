package api

import (
	"context"
	"crypto/rand"
	"database/sql"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
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
				switch raw := config[key].(type) {
				case []byte:
					var decoded any
					if json.Unmarshal(raw, &decoded) == nil {
						config[key] = decoded
					}
				case string:
					var decoded any
					if json.Unmarshal([]byte(raw), &decoded) == nil {
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
		httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "system.generic_error"), nil)
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

func (s *Server) handleGoogleSheetsConfigStore(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	input, err := s.validateGoogleSheetsConfig(r, actor, 0)
	if err != nil {
		writeError(w, r, err)
		return
	}
	result, err := s.database.ExecContext(r.Context(), `INSERT INTO sheets_configs(destination_id,name,tab,dataset,columns,filters,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, input.destinationID, input.name, input.tab, input.dataset, string(input.columns), input.filtersJSON)
	if err != nil {
		writeError(w, r, err)
		return
	}
	id, err := result.LastInsertId()
	if err != nil {
		writeError(w, r, err)
		return
	}
	data, err := s.googleSheetsConfigResponse(r, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, data, "Created")
}

func (s *Server) handleGoogleSheetsConfigUpdate(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	id, err := strconv.ParseInt(r.PathValue("config"), 10, 64)
	if err != nil || id < 1 {
		httpx.Failure(w, http.StatusNotFound, msg(r.Context(), "system.not_found"), nil)
		return
	}
	current, err := s.googleSheetsConfigRow(r, id)
	if err == sql.ErrNoRows {
		httpx.Failure(w, http.StatusNotFound, msg(r.Context(), "system.not_found"), nil)
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !s.googleSheetsCanManage(actor, current.agentID, current.dataset) {
		httpx.Failure(w, http.StatusForbidden, "This action is unauthorized.", nil)
		return
	}
	input, err := s.validateGoogleSheetsConfig(r, actor, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	release, locked, err := s.acquireGoogleSheetsLock(r, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !locked {
		httpx.Failure(w, http.StatusConflict, "Sinkronisasi sedang berjalan.", nil)
		return
	}
	defer release()
	_, err = s.database.ExecContext(r.Context(), `UPDATE sheets_configs SET destination_id=?,name=?,tab=?,dataset=?,columns=?,filters=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, input.destinationID, input.name, input.tab, input.dataset, string(input.columns), input.filtersJSON, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	data, err := s.googleSheetsConfigResponse(r, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleGoogleSheetsConfigDestroy(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	id, err := strconv.ParseInt(r.PathValue("config"), 10, 64)
	if err != nil || id < 1 {
		httpx.Failure(w, http.StatusNotFound, msg(r.Context(), "system.not_found"), nil)
		return
	}
	current, err := s.googleSheetsConfigRow(r, id)
	if err == sql.ErrNoRows {
		httpx.Failure(w, http.StatusNotFound, msg(r.Context(), "system.not_found"), nil)
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !s.googleSheetsCanManage(actor, current.agentID, current.dataset) {
		httpx.Failure(w, http.StatusForbidden, "This action is unauthorized.", nil)
		return
	}
	release, locked, err := s.acquireGoogleSheetsLock(r, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !locked {
		httpx.Failure(w, http.StatusConflict, "Sinkronisasi sedang berjalan.", nil)
		return
	}
	defer release()
	if _, err = s.database.ExecContext(r.Context(), `DELETE FROM sheets_configs WHERE id=?`, id); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, "OK", nil)
}

type googleSheetsConfigOwner struct {
	agentID sql.NullInt64
	dataset string
}

func (s *Server) googleSheetsConfigRow(r *http.Request, id int64) (googleSheetsConfigOwner, error) {
	var row googleSheetsConfigOwner
	err := s.database.QueryRowContext(r.Context(), `SELECT d.agent_id,c.dataset FROM sheets_configs c JOIN sheets_destinations d ON d.id=c.destination_id WHERE c.id=?`, id).Scan(&row.agentID, &row.dataset)
	return row, err
}
func (s *Server) googleSheetsCanManage(actor *auth.User, agentID sql.NullInt64, dataset string) bool {
	if actor.IsRole(auth.RoleAdmin) && dataset == "financial_summary" {
		return false
	}
	return actor.IsRole(auth.RoleSuperAdmin) || (actor.IsRole(auth.RoleAgen, auth.RoleAdmin) && actor.AgentID != nil && agentID.Valid && *actor.AgentID == agentID.Int64)
}

func (s *Server) googleSheetsConfigResponse(r *http.Request, id int64) (map[string]any, error) {
	rows, err := s.queryMaps(r, `SELECT c.id,c.destination_id,c.name,c.tab,c.dataset,c.columns,c.filters,c.created_at,c.updated_at,d.id AS destination__id,d.spreadsheet_id AS destination__spreadsheet_id,d.agent_id AS destination__agent_id,d.spreadsheet_title AS destination__spreadsheet_title,d.connection_status AS destination__connection_status,d.last_tested_at AS destination__last_tested_at,d.last_error_code AS destination__last_error_code,d.created_at AS destination__created_at,d.updated_at AS destination__updated_at FROM sheets_configs c JOIN sheets_destinations d ON d.id=c.destination_id WHERE c.id=?`, id)
	if err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, sql.ErrNoRows
	}
	item := rows[0]
	for _, key := range []string{"columns", "filters"} {
		if raw, ok := item[key].(string); ok {
			var decoded any
			if json.Unmarshal([]byte(raw), &decoded) == nil {
				item[key] = decoded
			}
		}
	}
	destination := map[string]any{}
	for key := range item {
		if strings.HasPrefix(key, "destination__") {
			name := strings.TrimPrefix(key, "destination__")
			value := item[key]
			if name == "id" || name == "agent_id" {
				value = numericValue(value)
			}
			destination[name] = value
			delete(item, key)
		}
	}
	item["id"] = numericValue(item["id"])
	item["destination_id"] = numericValue(item["destination_id"])
	item["destination"] = destination
	return item, nil
}

func (s *Server) acquireGoogleSheetsLock(r *http.Request, id int64) (func(), bool, error) {
	if s.cfg.CacheStore == "array" {
		// Laravel's .env.testing uses its request-local array store. There is no
		// cross-request lock row in that environment, so mirror that behavior.
		return func() {}, true, nil
	}
	if s.cfg.CacheStore != "database" {
		return nil, false, fmt.Errorf("Google Sheets lock store %q is not supported by the Go runtime", s.cfg.CacheStore)
	}
	ownerBytes := make([]byte, 20)
	if _, err := rand.Read(ownerBytes); err != nil {
		return nil, false, err
	}
	owner := hex.EncodeToString(ownerBytes)
	key := s.cfg.CachePrefix + fmt.Sprintf("sheets-sync-%d", id)
	now := time.Now().Unix()
	expires := now + 180
	result, err := s.database.ExecContext(r.Context(), `INSERT IGNORE INTO cache_locks(`+"`key`"+`,owner,expiration) VALUES(?,?,?)`, key, owner, expires)
	if err != nil {
		return nil, false, err
	}
	affected, _ := result.RowsAffected()
	if affected == 0 {
		result, err = s.database.ExecContext(r.Context(), `UPDATE cache_locks SET owner=?,expiration=? WHERE `+"`key`"+`=? AND expiration<=?`, owner, expires, key, now)
		if err != nil {
			return nil, false, err
		}
		affected, _ = result.RowsAffected()
	}
	if affected == 0 {
		return func() {}, false, nil
	}
	release := func() {
		_, _ = s.database.ExecContext(context.Background(), `DELETE FROM cache_locks WHERE `+"`key`"+`=? AND owner=?`, key, owner)
	}
	return release, true, nil
}

type googleSheetsConfigInput struct {
	destinationID      int64
	name, tab, dataset string
	columns            []byte
	filters            any
	filtersJSON        []byte
}

func (s *Server) validateGoogleSheetsConfig(r *http.Request, actor *auth.User, configID int64) (googleSheetsConfigInput, error) {
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		return googleSheetsConfigInput{}, err
	}
	locale := LocaleFrom(r.Context())
	fields := map[string][]string{}
	add := func(key, rule string, args ...string) {
		fields[key] = append(fields[key], i18n.ValidationMessage(locale, rule, key, args...))
	}
	if _, exists := body["agent_id"]; exists {
		add("agent_id", "prohibited")
	}
	var out googleSheetsConfigInput
	forbiddenScope := false
	rawDestination := strings.TrimSpace(string(body["destination_id"]))
	if rawDestination == "" || rawDestination == "null" {
		add("destination_id", "required")
	} else if n, err := strconv.ParseInt(strings.Trim(rawDestination, `"`), 10, 64); err != nil {
		add("destination_id", "integer")
	} else {
		out.destinationID = n
		var destAgent sql.NullInt64
		err = s.database.QueryRowContext(r.Context(), `SELECT agent_id FROM sheets_destinations WHERE id=?`, n).Scan(&destAgent)
		if err == sql.ErrNoRows {
			add("destination_id", "exists")
		} else if err != nil {
			return out, err
		} else if !actor.IsRole(auth.RoleSuperAdmin) && (actor.AgentID == nil || !destAgent.Valid || destAgent.Int64 != *actor.AgentID) {
			forbiddenScope = true
		}
	}
	out.name = validateSheetsString(body, "name", 150, locale, fields, true)
	out.tab = validateSheetsString(body, "tab", 100, locale, fields, true)
	if out.tab != "" {
		if !sheetsTabPattern.MatchString(out.tab) {
			add("tab", "regex")
		}
		if out.destinationID > 0 {
			var exists int
			query := `SELECT EXISTS(SELECT 1 FROM sheets_configs WHERE destination_id=? AND tab=?`
			args := []any{out.destinationID, out.tab}
			if configID > 0 {
				query += ` AND id<>?`
				args = append(args, configID)
			}
			query += `)`
			if err := s.database.QueryRowContext(r.Context(), query, args...).Scan(&exists); err != nil {
				return out, err
			}
			if exists > 0 {
				add("tab", "unique")
			}
		}
	}
	var catalog map[string]any
	if err := json.Unmarshal(googleSheetsCatalogJSON, &catalog); err != nil {
		return out, err
	}
	datasetDefs, _ := catalog["datasets"].(map[string]any)
	out.dataset = jsonRawString(body["dataset"])
	if raw := body["dataset"]; len(raw) == 0 || string(raw) == "null" {
		add("dataset", "required")
	} else if _, ok := datasetDefs[out.dataset]; !ok {
		add("dataset", "in", "values", strings.Join(googleSheetsDatasetNames(datasetDefs), ", "))
	}
	if _, ok := body["columns"]; !ok || string(body["columns"]) == "null" {
		add("columns", "required")
	} else {
		var cols []map[string]json.RawMessage
		if json.Unmarshal(body["columns"], &cols) != nil {
			add("columns", "array")
		} else {
			if len(cols) < 1 {
				add("columns", "min.array", "min", "1")
			}
			if len(cols) > 60 {
				add("columns", "max.array", "max", "60")
			}
			fieldsSeen := map[string]bool{}
			datasetFields := []any{}
			if defs, ok := datasetDefs[out.dataset].([]any); ok {
				datasetFields = defs
			}
			var normalized []map[string]any
			for i, col := range cols {
				prefix := fmt.Sprintf("columns.%d", i)
				validKeys := map[string]bool{"field": true, "label": true}
				for key := range col {
					if !validKeys[key] {
						fields[prefix] = append(fields[prefix], i18n.ValidationMessage(locale, "array", prefix))
					}
				}
				fieldName := jsonRawString(col["field"])
				if len(col["field"]) == 0 || string(col["field"]) == "null" || fieldName == "" {
					fields[prefix+".field"] = append(fields[prefix+".field"], i18n.ValidationMessage(locale, "required", prefix+".field"))
				} else if col["field"][0] != '"' {
					fields[prefix+".field"] = append(fields[prefix+".field"], i18n.ValidationMessage(locale, "string", prefix+".field"))
				} else {
					if fieldsSeen[fieldName] {
						fields[prefix+".field"] = append(fields[prefix+".field"], i18n.ValidationMessage(locale, "distinct", prefix+".field"))
					}
					fieldsSeen[fieldName] = true
					found := false
					for _, item := range datasetFields {
						if item == fieldName {
							found = true
							break
						}
					}
					if !found {
						fields[prefix+".field"] = append(fields[prefix+".field"], i18n.ValidationMessage(locale, "in", prefix+".field", "values", strings.Join(anyStrings(datasetFields), ", ")))
					}
				}
				label := jsonRawString(col["label"])
				if len(col["label"]) == 0 || string(col["label"]) == "null" || label == "" {
					fields[prefix+".label"] = append(fields[prefix+".label"], i18n.ValidationMessage(locale, "required", prefix+".label"))
				} else if col["label"][0] != '"' {
					fields[prefix+".label"] = append(fields[prefix+".label"], i18n.ValidationMessage(locale, "string", prefix+".label"))
				} else if len([]rune(label)) > 100 {
					fields[prefix+".label"] = append(fields[prefix+".label"], i18n.ValidationMessage(locale, "max.string", prefix+".label", "max", "100"))
				}
				normalized = append(normalized, map[string]any{"field": fieldName, "label": label})
			}
			out.columns, _ = json.Marshal(normalized)
		}
	}
	if raw, exists := body["filters"]; exists && string(raw) != "null" {
		var filters map[string]json.RawMessage
		if json.Unmarshal(raw, &filters) != nil {
			add("filters", "array")
		} else {
			allowedKeys := map[string]bool{"period": true, "from": true, "to": true, "status": true, "category_group": true}
			for key := range filters {
				if !allowedKeys[key] {
					add("filters", "array")
					break
				}
			}
			normalized := map[string]any{}
			for key, rawValue := range filters {
				value := jsonRawString(rawValue)
				if string(rawValue) == "null" {
					normalized[key] = nil
					continue
				}
				switch key {
				case "period":
					if !sheetsSet(value, "daily", "weekly", "monthly", "custom") {
						add("filters.period", "in", "values", "daily, weekly, monthly, custom")
					}
					normalized[key] = value
				case "from", "to":
					if !sheetsDate(value) {
						add("filters."+key, "date_format", "format", "Y-m-d")
					}
					normalized[key] = value
				case "status":
					if rawValue[0] != '"' {
						add("filters.status", "string")
					} else if len([]rune(value)) > 40 {
						add("filters.status", "max.string", "max", "40")
					}
					normalized[key] = value
				case "category_group":
					if !sheetsSet(value, "cookies", "cake") {
						add("filters.category_group", "in", "values", "cookies, cake")
					}
					normalized[key] = value
				}
			}
			out.filters = normalized
		}
	}
	if len(fields) > 0 {
		return out, httpx.Validation(fields)
	}
	if forbiddenScope {
		return out, &httpx.Error{Status: http.StatusForbidden, Message: msg(r.Context(), "system.generic_error")}
	}
	if actor.IsRole(auth.RoleAdmin) && out.dataset == "financial_summary" {
		return out, &httpx.Error{Status: http.StatusForbidden, Message: "Dataset ini memuat komisi Agen dan tidak tersedia untuk Admin."}
	}
	if filters, ok := out.filters.(map[string]any); ok {
		if group, ok := filters["category_group"].(string); ok && group != "" && out.dataset != "transaction_worksheet" {
			fields["filters.category_group"] = []string{"Filter kategori hanya tersedia pada Laporan C."}
		}
		periodUsed := false
		for _, key := range []string{"period", "from", "to"} {
			if value, ok := filters[key].(string); ok && value != "" && value != "0" {
				periodUsed = true
			}
		}
		if periodUsed && out.dataset != "transaction_worksheet" {
			fields["filters"] = []string{"Filter periode hanya tersedia pada laporan Transaksi Awal dan Posisi Pembayaran Terkini."}
		} else if filters["period"] == "custom" {
			from, _ := filters["from"].(string)
			to, _ := filters["to"].(string)
			if from == "" || to == "" {
				fields["filters.from"] = []string{"Periode kustom wajib diisi tanggal mulai dan tanggal sampai."}
			}
			if from != "" && to != "" && from > to {
				fields["filters.to"] = []string{"Tanggal sampai tidak boleh lebih awal dari tanggal mulai."}
			}
		}
	}
	if len(fields) > 0 {
		return out, httpx.Validation(fields)
	}
	out.filtersJSON = nil
	if out.filters != nil {
		out.filtersJSON, _ = json.Marshal(out.filters)
	}
	return out, nil
}

func validateSheetsString(body map[string]json.RawMessage, field string, max int, locale string, errors map[string][]string, required bool) string {
	raw, exists := body[field]
	value := jsonRawString(raw)
	if !exists || string(raw) == "null" || value == "" {
		if required {
			errors[field] = append(errors[field], i18n.ValidationMessage(locale, "required", field))
		}
		return ""
	}
	if len(raw) == 0 || raw[0] != '"' {
		errors[field] = append(errors[field], i18n.ValidationMessage(locale, "string", field))
		return value
	}
	if len([]rune(value)) > max {
		errors[field] = append(errors[field], i18n.ValidationMessage(locale, "max.string", field, "max", strconv.Itoa(max)))
	}
	return value
}
func jsonRawString(raw json.RawMessage) string {
	if len(raw) == 0 {
		return ""
	}
	var value string
	if json.Unmarshal(raw, &value) == nil {
		return value
	}
	return strings.TrimSpace(string(raw))
}
func googleSheetsDatasetNames(defs map[string]any) []string {
	out := make([]string, 0, len(defs))
	for key := range defs {
		out = append(out, key)
	}
	sort.Strings(out)
	return out
}
func anyStrings(values []any) []string {
	out := make([]string, 0, len(values))
	for _, value := range values {
		out = append(out, fmt.Sprint(value))
	}
	return out
}
func sheetsSet(value string, allowed ...string) bool {
	for _, candidate := range allowed {
		if value == candidate {
			return true
		}
	}
	return false
}
func sheetsDate(value string) bool {
	if len(value) != 10 {
		return false
	}
	_, err := time.Parse("2006-01-02", value)
	return err == nil
}

var sheetsTabPattern = regexp.MustCompile(`^[^\[\]:*?\\/]+$`)
