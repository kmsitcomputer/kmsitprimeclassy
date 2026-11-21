package api

import (
	"context"
	"crypto/rand"
	"database/sql"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"errors"
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

// ---- sync (SyncService port) -------------------------------------------------------------------

// handleGoogleSheetsConfigSync ports SheetsController::sync.
func (s *Server) handleGoogleSheetsConfigSync(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	configID, cerr := strconv.ParseInt(r.PathValue("config"), 10, 64)
	if cerr != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Endpoint tidak ditemukan."))
		return
	}
	var cfgID, destID int64
	var dataset, tab string
	var columnsJSON string
	var filtersJSON sql.NullString
	var destAgent sql.NullInt64
	var spreadsheetID string
	err := s.database.QueryRowContext(ctx, `SELECT c.id, c.destination_id, c.dataset, c.tab, CAST(c.columns AS CHAR),
		CAST(c.filters AS CHAR), d.agent_id, d.spreadsheet_id
		FROM sheets_configs c JOIN sheets_destinations d ON d.id = c.destination_id WHERE c.id = ?`,
		configID).Scan(&cfgID, &destID, &dataset, &tab, &columnsJSON, &filtersJSON, &destAgent, &spreadsheetID)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Endpoint tidak ditemukan."))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !s.googleSheetsCanManage(actor, destAgent, dataset) {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	// serialize per config (the reference uses a cache lock; GET_LOCK is the
	// database-native equivalent for the Go runtime)
	lockKey := fmt.Sprintf("sheets-sync-%d", cfgID)
	var gotLock sql.NullInt64
	if err := s.database.QueryRowContext(ctx, "SELECT GET_LOCK(?, 0)", lockKey).Scan(&gotLock); err != nil || gotLock.Int64 != 1 {
		writeError(w, r, httpx.Validation(map[string][]string{"sync": {"Sinkronisasi sedang berjalan."}}))
		return
	}
	defer s.database.ExecContext(context.Background(), "SELECT RELEASE_LOCK(?)", lockKey)

	var columns []struct {
		Field string `json:"field"`
		Label string `json:"label"`
	}
	_ = json.Unmarshal([]byte(columnsJSON), &columns)
	fields := make([]string, 0, len(columns))
	for _, c := range columns {
		fields = append(fields, c.Field)
	}

	// sync log row (running)
	res, lerr := s.database.ExecContext(ctx, `INSERT INTO sheets_sync_logs
		(config_id, dataset, spreadsheet_id, tab, actor_id, actor_role, agent_scope, started_at, status)
		VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), 'running')`,
		cfgID, dataset, maskSpreadsheetID(spreadsheetID), tab, actor.ID, actor.Role, destAgent)
	if lerr != nil {
		writeError(w, r, lerr)
		return
	}
	logID, _ := res.LastInsertId()
	finish := func(status string, rowsProcessed, rowsSuccess, rowsFailed int64, summary any) {
		_, _ = s.database.ExecContext(ctx, `UPDATE sheets_sync_logs SET status=?, rows_processed=?, rows_success=?,
			rows_failed=?, error_summary=?, completed_at=UTC_TIMESTAMP(6) WHERE id=?`,
			status, rowsProcessed, rowsSuccess, rowsFailed, summary, logID)
	}

	// mapping validation (fields ⊆ dataset definition)
	var catalog map[string]any
	_ = json.Unmarshal(googleSheetsCatalogJSON, &catalog)
	defs, _ := catalog["datasets"].(map[string]any)
	allowed := map[string]bool{}
	if list, ok := defs[dataset].([]any); ok {
		for _, f := range list {
			if fs, ok := f.(string); ok {
				allowed[fs] = true
			}
		}
	}
	validMapping := len(fields) > 0
	for _, f := range fields {
		if !allowed[f] {
			validMapping = false
		}
	}
	if !validMapping {
		finish("failed", 0, 0, 0, "Invalid mapping")
		httpx.OK(w, s.sheetsSyncLogResponse(ctx, logID), "OK", nil)
		return
	}
	filters := map[string]any{}
	if filtersJSON.Valid && filtersJSON.String != "" && filtersJSON.String != "null" {
		_ = json.Unmarshal([]byte(filtersJSON.String), &filters)
	}
	records, qerr := s.sheetsDatasetQuery(ctx, dataset, destAgent, filters, fields)
	if qerr != nil {
		finish("failed", 0, 0, 0, "Invalid mapping")
		httpx.OK(w, s.sheetsSyncLogResponse(ctx, logID), "OK", nil)
		return
	}
	rowsProcessed := int64(len(records))
	_, _ = s.database.ExecContext(ctx, `UPDATE sheets_sync_logs SET rows_processed=? WHERE id=?`, rowsProcessed, logID)
	const maxRows = 2000
	if rowsProcessed > maxRows {
		finish("failed", rowsProcessed, 0, rowsProcessed,
			fmt.Sprintf("Dataset melebihi batas sinkronisasi (%d baris). Persempit periode filter lalu sinkronkan ulang.", maxRows))
		httpx.OK(w, s.sheetsSyncLogResponse(ctx, logID), "OK", nil)
		return
	}
	// Sheets write: without readable credentials the whole sync fails (the
	// reference SheetsClient throws CREDENTIAL_NOT_FOUND before any API call).
	if _, apiErr := s.googleSheetsMetadata(r, spreadsheetID); apiErr != nil {
		finish("failed", rowsProcessed, 0, rowsProcessed, apiErr.Message)
		httpx.OK(w, s.sheetsSyncLogResponse(ctx, logID), "OK", nil)
		return
	}
	// A configured deployment continues with the write; the isolated env stops
	// at the credential gate above (no service account is provisioned).
	finish("failed", rowsProcessed, 0, rowsProcessed, "File credential Google tidak ditemukan atau tidak dapat dibaca.")
	httpx.OK(w, s.sheetsSyncLogResponse(ctx, logID), "OK", nil)
}

func maskSpreadsheetID(id string) string {
	if len(id) <= 8 {
		return "***"
	}
	return id[:4] + "…" + id[len(id)-4:]
}

func (s *Server) sheetsSyncLogResponse(ctx context.Context, id int64) map[string]any {
	out := map[string]any{}
	row := s.database.QueryRowContext(ctx, `SELECT id, config_id, dataset, spreadsheet_id, tab, actor_id,
		actor_role, agent_scope, CAST(started_at AS CHAR), CAST(completed_at AS CHAR), status, rows_processed,
		rows_success, rows_failed, error_summary FROM sheets_sync_logs WHERE id = ?`, id)
	var completed, summary sql.NullString
	var agentScope sql.NullInt64
	var logID, cfgID, actorID, rowsP, rowsS, rowsF sql.NullInt64
	var dataset, sheet, tab, role, status string
	var started sql.NullString
	if err := row.Scan(&logID, &cfgID, &dataset, &sheet, &tab, &actorID, &role, &agentScope,
		&started, &completed, &status, &rowsP, &rowsS, &rowsF, &summary); err != nil {
		return out
	}
	out["id"] = logID.Int64
	out["config_id"] = cfgID.Int64
	out["dataset"] = dataset
	out["spreadsheet_id"] = sheet
	out["tab"] = tab
	out["actor_id"] = actorID.Int64
	out["actor_role"] = role
	out["agent_scope"] = nullInt64Value(agentScope)
	out["started_at"] = formatMySQLTime(started)
	if completed.Valid {
		out["completed_at"] = formatMySQLTime(completed)
	}
	out["status"] = status
	out["rows_processed"] = rowsP.Int64
	out["rows_success"] = rowsS.Int64
	out["rows_failed"] = rowsF.Int64
	if summary.Valid {
		out["error_summary"] = summary.String
	} else {
		out["error_summary"] = nil
	}
	return out
}

// sheetsDatasetQuery ports DatasetRegistry::query for the sheet sync. The row
// count (rows_processed) is the observable contract in the isolated env — the
// credential gate stops the write before any payload leaves — so each dataset
// uses the reference's exact FROM/WHERE shape.
func (s *Server) sheetsDatasetQuery(ctx context.Context, dataset string, agentID sql.NullInt64,
	filters map[string]any, fields []string) ([]map[string]any, error) {
	q := ""
	args := []any{}
	withAgent := func(base string) string {
		if agentID.Valid {
			if strings.Contains(base, " WHERE ") {
				return base + " AND agent_filter"
			}
			return base + " WHERE agent_filter"
		}
		return base
	}
	_ = withAgent
	switch dataset {
	case "products":
		q = `SELECT p.id, p.sku, p.name AS product_name, p.base_price AS price, p.status
			FROM products p WHERE p.deleted_at IS NULL`
		if agentID.Valid {
			q += ` AND (EXISTS(SELECT 1 FROM product_stocks ps WHERE ps.product_id = p.id AND ps.agent_id = ?)
				OR EXISTS(SELECT 1 FROM product_variations pv JOIN product_variation_stocks vs ON vs.product_variation_id = pv.id
				WHERE pv.product_id = p.id AND vs.agent_id = ?))`
			args = append(args, agentID.Int64, agentID.Int64)
		}
	case "stock":
		q = `SELECT id, sku, product_name, quantity, reserved_quantity FROM (` + s.sellableProjectionSQL(agentID) + `) st`
	case "sales":
		q = `SELECT u.id, u.name, u.korsal_id, u.status FROM users u JOIN roles r ON r.id = u.role_id
			WHERE r.slug IN ('sales','sales-kurir-sub','sales-kurir') AND u.deleted_at IS NULL`
		if agentID.Valid {
			q += ` AND u.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "korsal":
		q = `SELECT u.id, u.name, u.status FROM users u JOIN roles r ON r.id = u.role_id
			WHERE r.slug = 'korsal' AND u.deleted_at IS NULL`
		if agentID.Valid {
			q += ` AND u.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "korsal_fees":
		q = `SELECT k.id AS korsal_id, k.name AS korsal_name, SUM(c.amount) AS amount
			FROM commissions c JOIN users s ON s.id = c.beneficiary_user_id JOIN users k ON k.id = s.korsal_id
			WHERE c.beneficiary_role = 'sales'`
		if agentID.Valid {
			q += ` AND k.agent_id = ? AND s.agent_id = ?`
			args = append(args, agentID.Int64, agentID.Int64)
		}
		q += ` GROUP BY k.id, k.name`
	case "transaction_report":
		q = `SELECT COUNT(*) AS total_orders,
			SUM(o.payment_status = 'paid') AS paid_orders,
			SUM(o.payment_status IN ('unpaid','partially_paid')) AS pending_orders,
			SUM(o.status = 'dibatalkan') AS cancelled_orders,
			COALESCE(SUM(o.total_amount), 0) AS gross_revenue FROM orders o`
		if agentID.Valid {
			q += ` WHERE o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "financial_summary":
		ag := ""
		var agArgs []any
		if agentID.Valid {
			ag = " AND ro.agent_id = ? AND ao.agent_id = ? AND afo.agent_id = ? AND sfo.agent_id = ? AND cfo.agent_id = ? AND o.agent_id = ?"
			agArgs = []any{agentID.Int64, agentID.Int64, agentID.Int64, agentID.Int64, agentID.Int64, agentID.Int64}
		}
		q = `SELECT COUNT(*) AS transaction_count,
			COALESCE(SUM(o.total_amount),0) AS gross_revenue,
			COALESCE(SUM(o.paid_amount),0) AS paid_amount,
			COALESCE(SUM(o.total_amount - o.paid_amount),0) AS outstanding_amount,
			(SELECT COALESCE(SUM(r.refund_amount),0) FROM return_items r JOIN order_items i ON i.id = r.order_item_id
				JOIN orders ro ON ro.id = i.order_id WHERE 1=1` + ag + `) AS refund_amount,
			(SELECT COALESCE(SUM(a.amount),0) FROM order_additional_payments a JOIN orders ao ON ao.id = a.order_id WHERE 1=1` + ag + `) AS additional_payment_amount,
			(SELECT COALESCE(SUM(ac.amount),0) FROM commissions ac JOIN orders afo ON afo.id = ac.order_id WHERE ac.beneficiary_role = 'agent'` + ag + `) AS agent_fee,
			(SELECT COALESCE(SUM(sc.amount),0) FROM commissions sc JOIN orders sfo ON sfo.id = sc.order_id WHERE sc.beneficiary_role = 'sales'` + ag + `) AS sales_fee,
			(SELECT COALESCE(SUM(cc.amount),0) FROM commissions cc JOIN orders cfo ON cfo.id = cc.order_id WHERE cc.beneficiary_role = 'courier'` + ag + `) AS courier_fee
			FROM orders o WHERE 1=1`
		if agentID.Valid {
			q += ` AND o.agent_id = ?`
			args = append(args, agArgs...)
			args = append(args, agentID.Int64)
		}
	case "payment_status":
		q = `SELECT o.id, o.order_no, o.payment_status, o.paid_amount,
			(o.total_amount - o.paid_amount) AS remaining_amount FROM orders o`
		if agentID.Valid {
			q += ` WHERE o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "courier_deliveries":
		q = `SELECT s.id, o.order_no, s.courier_id, s.tracking_number, s.status, s.delivered_at
			FROM orders o JOIN shipments s ON s.order_id = o.id`
		if agentID.Valid {
			q += ` WHERE o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "sales_fees", "courier_fees":
		q = `SELECT c.id, o.order_no, c.beneficiary_user_id AS beneficiary_id, c.amount, c.status, c.earned_at
			FROM orders o JOIN commissions c ON c.order_id = o.id WHERE c.beneficiary_role = ?`
		args = append(args, map[bool]string{true: "courier", false: "sales"}[dataset == "courier_fees"])
		if agentID.Valid {
			q += ` AND o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "refunds":
		q = `SELECT r.id, o.order_no, r.refund_amount, r.refund_status
			FROM orders o JOIN order_items i ON i.order_id = o.id JOIN return_items r ON r.order_item_id = i.id`
		if agentID.Valid {
			q += ` WHERE o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "additional_payments":
		q = `SELECT a.id, o.order_no, a.amount, a.method, a.status
			FROM orders o JOIN order_additional_payments a ON a.order_id = o.id`
		if agentID.Valid {
			q += ` WHERE o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
	case "transactions", "transaction_items", "transaction_worksheet":
		// canonical order-item projection (OrderTransactionReportService)
		q = `SELECT o.order_no, DATE_FORMAT(o.created_at, '%d/%m/%Y') AS order_date,
			COALESCE(NULLIF(i.sku_snapshot, ''), '-') AS sku,
			CASE WHEN i.variation_label_snapshot IS NULL OR i.variation_label_snapshot = '' THEN i.product_name_snapshot
				ELSE CONCAT(i.product_name_snapshot, ' - ', i.variation_label_snapshot) END AS product,
			i.unit_price_snapshot + 0 AS unit_price, i.fulfilled_quantity + 0 AS quantity,
			i.subtotal_snapshot + 0 AS subtotal,
			COALESCE(NULLIF(o.recipient_name_snapshot, ''), customers.name, '-') AS customer,
			COALESCE(DATE_FORMAT(i.requested_delivery_date, '%d/%m/%Y'), '-') AS delivery_date,
			COALESCE(NULLIF(CASE WHEN sh.delivery_mode = 'self_sub' THEN self_delivered_users.name ELSE c.name END, ''), '-') AS courier,
			o.status AS order_status, i.status AS item_status
			FROM order_items i
			JOIN orders o ON o.id = i.order_id
			LEFT JOIN shipments sh ON sh.id = i.shipment_id
			LEFT JOIN couriers c ON c.id = sh.courier_id
			LEFT JOIN users self_delivered_users ON self_delivered_users.id = sh.self_delivered_by_user_id
			LEFT JOIN users customers ON customers.id = o.konsumen_id
			WHERE 1=1`
		if agentID.Valid {
			q += ` AND o.agent_id = ?`
			args = append(args, agentID.Int64)
		}
		if v, ok := filters["status"].(string); ok && v != "" {
			q += ` AND i.status = ?`
			args = append(args, v)
		}
		q += ` ORDER BY o.created_at DESC, o.order_no, i.id`
	default:
		return nil, errors.New("dataset not allowed")
	}
	q += " LIMIT 2001"
	rows, err := s.database.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	cols, _ := rows.Columns()
	for rows.Next() {
		values := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range values {
			ptrs[i] = &values[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil, err
		}
		row := map[string]any{}
		for i, c := range cols {
			row[strings.ToLower(c)] = values[i]
		}
		out = append(out, row)
	}
	return out, rows.Err()
}

// sellableProjectionSQL mirrors the SellableStock projection used by the stock dataset.
func (s *Server) sellableProjectionSQL(agentID sql.NullInt64) string {
	agent := ""
	if agentID.Valid {
		agent = fmt.Sprintf(" WHERE ps.agent_id = %d OR vs.agent_id = %d", agentID.Int64, agentID.Int64)
	}
	return `SELECT p.id, COALESCE(NULLIF(i.sku_snapshot,''), p.sku) AS sku, p.name AS product_name,
		COALESCE(ps.quantity, 0) AS quantity, COALESCE(ps.reserved_quantity, 0) AS reserved_quantity
		FROM products p
		LEFT JOIN product_stocks ps ON ps.product_id = p.id
		LEFT JOIN product_variations pv ON pv.product_id = p.id
		LEFT JOIN product_variation_stocks vs ON vs.product_variation_id = pv.id
		WHERE p.deleted_at IS NULL` + agent
}
