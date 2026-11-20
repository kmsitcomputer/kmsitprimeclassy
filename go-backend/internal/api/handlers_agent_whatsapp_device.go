package api

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleAgentWhatsAppDeviceCreate(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	agentID := actor.ID
	if actor.IsRole(auth.RoleAdmin) && actor.AgentID != nil {
		agentID = *actor.AgentID
	} else if !actor.IsRole(auth.RoleAgen) {
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	if selected := httpx.QueryString(r, "agent_id"); selected != "" {
		id, e := strconv.ParseInt(selected, 10, 64)
		if e != nil || id != agentID {
			httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "whatsapp.scope_forbidden"), nil)
			return
		}
	}
	var role string
	if err := s.database.QueryRowContext(r.Context(), `SELECT rl.slug FROM users u JOIN roles rl ON rl.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL`, agentID).Scan(&role); err != nil || role != auth.RoleAgen {
		httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "whatsapp.scope_forbidden"), nil)
		return
	}
	var tableReady bool
	if err := s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='agent_whatsapp_devices')`).Scan(&tableReady); err != nil {
		writeError(w, r, err)
		return
	}
	if !tableReady {
		httpx.Failure(w, http.StatusServiceUnavailable, msg(r.Context(), "whatsapp.migration_pending"), nil)
		return
	}
	settings := map[string]string{}
	rows, err := s.database.QueryContext(r.Context(), `SELECT `+"`key`"+`,value FROM settings WHERE `+"`group`"+`='integration.whatsapp'`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	for rows.Next() {
		var k, v string
		if err = rows.Scan(&k, &v); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		settings[k] = v
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeError(w, r, err)
		return
	}
	rows.Close()
	password := ""
	if settings["wa.password"] != "" {
		password, _ = cryptox.DecryptString(s.cfg.AppKey, settings["wa.password"])
	}
	base, user := settings["wa.base_url"], settings["wa.username"]
	if base == "" || user == "" || password == "" {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.device_gateway_unconfigured", "whatsapp_gateway_unconfigured")
		return
	}
	var id int64
	var deviceID, state string
	var paired sql.NullTime
	err = s.database.QueryRowContext(r.Context(), `SELECT id,device_id,state,paired_at FROM agent_whatsapp_devices WHERE agent_id=?`, agentID).Scan(&id, &deviceID, &state, &paired)
	if err != nil && err != sql.ErrNoRows {
		writeError(w, r, err)
		return
	}
	if err == sql.ErrNoRows {
		b := make([]byte, 4)
		if _, err = rand.Read(b); err != nil {
			writeError(w, r, err)
			return
		}
		deviceID = fmt.Sprintf("pc-agent-%d-%s", agentID, hex.EncodeToString(b))
		payload, _ := json.Marshal(map[string]string{"device_id": deviceID})
		timeout := whatsAppTimeout(settings["wa.timeout"])
		status, _, callErr := agentWhatsAppGatewayCall(r.Context(), http.MethodPost, strings.TrimRight(base, "/")+"/devices", user, password, timeout, payload)
		if callErr != nil {
			agentWhatsAppDeviceError(w, r, http.StatusServiceUnavailable, "whatsapp.device_gateway_unreachable", "whatsapp_gateway_unreachable")
			return
		}
		if status < 200 || status >= 300 && status != http.StatusConflict {
			agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
			return
		}
		res, insertErr := s.database.ExecContext(r.Context(), `INSERT INTO agent_whatsapp_devices (agent_id,device_id,state,created_at,updated_at) VALUES (?,?,'not_paired',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, agentID, deviceID)
		if insertErr != nil {
			_, _, _ = agentWhatsAppGatewayCall(r.Context(), http.MethodDelete, strings.TrimRight(base, "/")+"/devices/"+url.PathEscape(deviceID), user, password, timeout, nil)
			if e := s.database.QueryRowContext(r.Context(), `SELECT id,device_id,state,paired_at FROM agent_whatsapp_devices WHERE agent_id=?`, agentID).Scan(&id, &deviceID, &state, &paired); e != nil {
				writeError(w, r, insertErr)
				return
			}
		} else {
			id, _ = res.LastInsertId()
			state = "not_paired"
			props, _ := json.Marshal(map[string]int64{"target_agent_id": agentID, "actor_id": actor.ID})
			ua := r.UserAgent()
			if len(ua) > 500 {
				ua = ua[:500]
			}
			_, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\AgentWhatsAppDevice',?,'whatsapp.device.created',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, id, string(props), clientIP(r), ua)
			if err != nil {
				writeError(w, r, err)
				return
			}
		}
	}
	s.refreshAgentWhatsAppDevice(r, id, agentID, deviceID, state, paired, settings, password)
	var phone sql.NullString
	var pairedAt, checked sql.NullTime
	var replaced uint
	if err = s.database.QueryRowContext(r.Context(), `SELECT state,phone_masked,paired_at,last_checked_at,replaced_count FROM agent_whatsapp_devices WHERE id=?`, id).Scan(&state, &phone, &pairedAt, &checked, &replaced); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, map[string]any{"global_enabled": truthy(settings["wa.enabled"]), "gateway_ready": true, "table_ready": true, "capabilities": map[string]bool{"qr": true, "pair_code": true}, "device": map[string]any{"state": state, "phone_masked": nullStringValue(phone), "paired_at": agentWhatsAppTime(pairedAt), "last_checked_at": agentWhatsAppTime(checked), "replaced_count": replaced}}, "Created")
}

func agentWhatsAppDeviceError(w http.ResponseWriter, r *http.Request, status int, messageKey, code string) {
	httpx.Failure(w, status, msg(r.Context(), messageKey), map[string][]string{"code": {code}})
}
func whatsAppTimeout(raw string) int {
	n, err := strconv.Atoi(raw)
	if err != nil || n < 2 {
		return 10
	}
	if n > 30 {
		return 30
	}
	return n
}

func (s *Server) handleAgentWhatsAppStatus(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	var agentID int64
	switch {
	case actor.IsRole(auth.RoleAgen):
		agentID = actor.ID
	case actor.IsRole(auth.RoleAdmin) && actor.AgentID != nil:
		agentID = *actor.AgentID
	default:
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	if selected := httpx.QueryString(r, "agent_id"); selected != "" {
		requested, err := strconv.ParseInt(selected, 10, 64)
		if err != nil || requested != agentID {
			httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "whatsapp.scope_forbidden"), nil)
			return
		}
	}
	var agentName, role string
	if err := s.database.QueryRowContext(r.Context(), `SELECT u.name,rl.slug FROM users u JOIN roles rl ON rl.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL`, agentID).Scan(&agentName, &role); err != nil || role != auth.RoleAgen {
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	settings := map[string]string{}
	rows, err := s.database.QueryContext(r.Context(), `SELECT `+"`key`"+`,value FROM settings WHERE `+"`group`"+`='integration.whatsapp'`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	for rows.Next() {
		var k, v string
		if err = rows.Scan(&k, &v); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		settings[k] = v
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeError(w, r, err)
		return
	}
	rows.Close()
	password := ""
	if settings["wa.password"] != "" {
		password, _ = cryptox.DecryptString(s.cfg.AppKey, settings["wa.password"])
	}
	configured := settings["wa.base_url"] != "" && settings["wa.username"] != "" && password != ""
	var tableReady bool
	if err = s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='agent_whatsapp_devices')`).Scan(&tableReady); err != nil {
		writeError(w, r, err)
		return
	}
	var device any
	if tableReady {
		var id int64
		var deviceID, state string
		var phone sql.NullString
		var paired sql.NullTime
		var replaced uint
		err = s.database.QueryRowContext(r.Context(), `SELECT id,device_id,state,phone_masked,paired_at,replaced_count FROM agent_whatsapp_devices WHERE agent_id=?`, agentID).Scan(&id, &deviceID, &state, &phone, &paired, &replaced)
		if err != nil && err != sql.ErrNoRows {
			writeError(w, r, err)
			return
		}
		if err == nil {
			if configured {
				s.refreshAgentWhatsAppDevice(r, id, agentID, deviceID, state, paired, settings, password)
			}
			var currentPhone sql.NullString
			var currentPaired, currentChecked sql.NullTime
			if err = s.database.QueryRowContext(r.Context(), `SELECT state,phone_masked,paired_at,last_checked_at,replaced_count FROM agent_whatsapp_devices WHERE id=?`, id).Scan(&state, &currentPhone, &currentPaired, &currentChecked, &replaced); err != nil {
				writeError(w, r, err)
				return
			}
			device = map[string]any{"state": state, "phone_masked": nullStringValue(currentPhone), "paired_at": agentWhatsAppTime(currentPaired), "last_checked_at": agentWhatsAppTime(currentChecked), "replaced_count": replaced}
		}
	}
	httpx.OK(w, map[string]any{"global_enabled": truthy(settings["wa.enabled"]), "gateway_ready": configured, "table_ready": tableReady, "capabilities": map[string]bool{"qr": true, "pair_code": true}, "device": device, "agent": map[string]any{"id": agentID, "name": agentName}}, "OK", nil)
}

func (s *Server) refreshAgentWhatsAppDevice(r *http.Request, rowID, agentID int64, deviceID, state string, paired sql.NullTime, settings map[string]string, password string) {
	base := strings.TrimRight(settings["wa.base_url"], "/")
	timeout, _ := strconv.Atoi(settings["wa.timeout"])
	if timeout < 2 {
		timeout = 10
	}
	if timeout > 30 {
		timeout = 30
	}
	status, body, err := agentWhatsAppGateway(r.Context(), base+"/devices/"+url.PathEscape(deviceID)+"/status", settings["wa.username"], password, timeout)
	if err != nil {
		return
	}
	var payload struct {
		Message string `json:"message"`
		Results struct {
			Connected bool `json:"is_connected"`
			LoggedIn  bool `json:"is_logged_in"`
		} `json:"results"`
	}
	_ = json.Unmarshal(body, &payload)
	notFound := status == http.StatusNotFound || (status >= 500 && strings.Contains(strings.ToLower(payload.Message), "not found"))
	if status < 200 || status >= 300 {
		if notFound && state != "not_paired" {
			_, _ = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state='not_paired',paired_at=NULL,last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, rowID)
		}
		return
	}
	if payload.Results.Connected && payload.Results.LoggedIn {
		if !paired.Valid {
			var masked any
			if _, numberBytes, e := agentWhatsAppGateway(r.Context(), base+"/devices/"+url.PathEscape(deviceID), settings["wa.username"], password, timeout); e == nil {
				var numberPayload struct {
					Results struct {
						Phone string `json:"phone_number"`
						JID   string `json:"jid"`
					} `json:"results"`
				}
				_ = json.Unmarshal(numberBytes, &numberPayload)
				raw := numberPayload.Results.Phone
				if raw == "" {
					raw = numberPayload.Results.JID
				}
				digits := agentadmin.NormalizeWhatsAppNumber(raw)
				if digits != "" {
					masked = agentadmin.MaskWhatsAppNumber(digits)
				}
			}
			_, _ = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state='connected',phone_masked=?,paired_at=UTC_TIMESTAMP(6),last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, masked, rowID)
			_, _ = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\AgentWhatsAppDevice',?,'whatsapp.device.paired',NULL,NULL,NULL,NULL,UTC_TIMESTAMP())`, agentID, rowID)
			return
		}
		_, _ = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state='connected',last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, rowID)
		return
	}
	next := "not_paired"
	if state == "pairing" {
		next = "pairing"
	} else if paired.Valid {
		next = "disconnected"
	}
	_, _ = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state=?,last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, next, rowID)
}

func agentWhatsAppGateway(ctx context.Context, endpoint, username, password string, timeout int) (int, []byte, error) {
	return agentWhatsAppGatewayCall(ctx, http.MethodGet, endpoint, username, password, timeout, nil)
}

func agentWhatsAppGatewayCall(ctx context.Context, method, endpoint, username, password string, timeout int, payload []byte) (int, []byte, error) {
	client := &http.Client{Timeout: time.Duration(timeout) * time.Second, Transport: &http.Transport{DialContext: (&net.Dialer{Timeout: time.Duration(timeout) * time.Second}).DialContext}}
	req, err := http.NewRequestWithContext(ctx, method, endpoint, strings.NewReader(string(payload)))
	if err != nil {
		return 0, nil, err
	}
	req.SetBasicAuth(username, password)
	req.Header.Set("Accept", "application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	return resp.StatusCode, body, err
}

func agentWhatsAppTime(value sql.NullTime) any {
	if !value.Valid {
		return nil
	}
	return value.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}
