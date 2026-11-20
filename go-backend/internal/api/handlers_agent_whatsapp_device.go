package api

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
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
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

type agentWhatsAppDeviceRow struct {
	ID              int64
	DeviceID, State string
	Phone           sql.NullString
	Paired, Checked sql.NullTime
	Replaced        uint
}

func (s *Server) agentWhatsAppTarget(w http.ResponseWriter, r *http.Request) (*auth.User, int64, bool) {
	actor := UserFrom(r.Context())
	if actor == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return nil, 0, false
	}
	agentID := actor.ID
	if actor.IsRole(auth.RoleAdmin) && actor.AgentID != nil {
		agentID = *actor.AgentID
	} else if !actor.IsRole(auth.RoleAgen) {
		writeError(w, r, forbiddenError(r.Context()))
		return nil, 0, false
	}
	if selected := httpx.QueryString(r, "agent_id"); selected != "" {
		id, err := strconv.ParseInt(selected, 10, 64)
		if err != nil || id != agentID {
			httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "whatsapp.scope_forbidden"), nil)
			return nil, 0, false
		}
	}
	var role string
	if err := s.database.QueryRowContext(r.Context(), `SELECT rl.slug FROM users u JOIN roles rl ON rl.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL`, agentID).Scan(&role); err != nil || role != auth.RoleAgen {
		httpx.Failure(w, http.StatusForbidden, msg(r.Context(), "whatsapp.scope_forbidden"), nil)
		return nil, 0, false
	}
	return actor, agentID, true
}

func (s *Server) agentWhatsAppCredentials(ctx context.Context) (map[string]string, string, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT `+"`key`"+`,value FROM settings WHERE `+"`group`"+`='integration.whatsapp'`)
	if err != nil {
		return nil, "", err
	}
	defer rows.Close()
	settings := map[string]string{}
	for rows.Next() {
		var k, v string
		if err = rows.Scan(&k, &v); err != nil {
			return nil, "", err
		}
		settings[k] = v
	}
	if err = rows.Err(); err != nil {
		return nil, "", err
	}
	password := ""
	if settings["wa.password"] != "" {
		password, _ = cryptox.DecryptString(s.cfg.AppKey, settings["wa.password"])
	}
	return settings, password, nil
}

func (s *Server) agentWhatsAppDevice(ctx context.Context, agentID int64) (*agentWhatsAppDeviceRow, error) {
	var d agentWhatsAppDeviceRow
	err := s.database.QueryRowContext(ctx, `SELECT id,device_id,state,phone_masked,paired_at,last_checked_at,replaced_count FROM agent_whatsapp_devices WHERE agent_id=?`, agentID).Scan(&d.ID, &d.DeviceID, &d.State, &d.Phone, &d.Paired, &d.Checked, &d.Replaced)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &d, nil
}

func agentWhatsAppDeviceData(d *agentWhatsAppDeviceRow) map[string]any {
	return map[string]any{"state": d.State, "phone_masked": nullStringValue(d.Phone), "paired_at": agentWhatsAppTime(d.Paired), "last_checked_at": agentWhatsAppTime(d.Checked), "replaced_count": d.Replaced}
}

func agentWhatsAppAudit(ctx context.Context, d interface {
	ExecContext(context.Context, string, ...any) (sql.Result, error)
}, actorID, agentID, deviceRow int64, event string, props map[string]any, meta auth.ClientMeta) error {
	var raw any
	if props == nil {
		props = map[string]any{}
	}
	props["target_agent_id"] = agentID
	props["actor_id"] = actorID
	b, err := json.Marshal(props)
	if err != nil {
		return err
	}
	raw = string(b)
	_, err = d.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\AgentWhatsAppDevice',?,?,NULL,?,?,?,UTC_TIMESTAMP())`, actorID, deviceRow, event, raw, meta.IP, meta.UserAgent)
	return err
}

func (s *Server) handleAgentWhatsAppLogout(w http.ResponseWriter, r *http.Request) {
	s.agentWhatsAppDeviceTerminal(w, r, false)
}
func (s *Server) handleAgentWhatsAppReconnect(w http.ResponseWriter, r *http.Request) {
	s.agentWhatsAppDeviceTerminal(w, r, true)
}

func (s *Server) agentWhatsAppDeviceTerminal(w http.ResponseWriter, r *http.Request, reconnect bool) {
	actor, agentID, ok := s.agentWhatsAppTarget(w, r)
	if !ok {
		return
	}
	d, err := s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if d == nil {
		httpx.Failure(w, http.StatusNotFound, msg(r.Context(), "whatsapp.device_not_found"), nil)
		return
	}
	settings, password, err := s.agentWhatsAppCredentials(r.Context())
	if err != nil {
		writeError(w, r, err)
		return
	}
	base, user := settings["wa.base_url"], settings["wa.username"]
	if base == "" || user == "" || password == "" {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.device_gateway_unconfigured", "whatsapp_gateway_unconfigured")
		return
	}
	path := "/logout"
	event := "whatsapp.device.logged_out"
	if reconnect {
		path = "/reconnect"
		event = "whatsapp.device.reconnect_requested"
	}
	status, _, callErr := agentWhatsAppGatewayCall(r.Context(), http.MethodPost, strings.TrimRight(base, "/")+"/devices/"+url.PathEscape(d.DeviceID)+path, user, password, whatsAppTimeout(settings["wa.timeout"]), nil)
	if callErr != nil {
		agentWhatsAppDeviceError(w, r, http.StatusServiceUnavailable, "whatsapp.device_gateway_unreachable", "whatsapp_gateway_unreachable")
		return
	}
	if status < 200 || status >= 300 && !(status == http.StatusNotFound && !reconnect) {
		agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
		return
	}
	meta := clientMetaFrom(r.Context())
	if !reconnect {
		_, err = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state='not_paired',phone_masked=NULL,paired_at=NULL,last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, d.ID)
		if err != nil {
			writeError(w, r, err)
			return
		}
	}
	if err = agentWhatsAppAudit(r.Context(), s.database, actor.ID, agentID, d.ID, event, nil, meta); err != nil {
		writeError(w, r, err)
		return
	}
	if reconnect {
		s.refreshAgentWhatsAppDevice(r, d.ID, agentID, d.DeviceID, d.State, d.Paired, settings, password)
	}
	d, err = s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"device": agentWhatsAppDeviceData(d)}, "OK", nil)
}

func (s *Server) handleAgentWhatsAppReplace(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentWhatsAppTarget(w, r)
	if !ok {
		return
	}
	var body struct {
		Confirm json.RawMessage `json:"confirm"`
	}
	if r.ContentLength > 0 {
		if err := httpx.DecodeJSON(r, &body); err != nil {
			writeError(w, r, err)
			return
		}
	}
	accepted := string(body.Confirm) == "true" || string(body.Confirm) == "1" || string(body.Confirm) == `"1"` || string(body.Confirm) == `"yes"` || string(body.Confirm) == `"on"`
	if !accepted {
		writeError(w, r, httpx.Validation(map[string][]string{"confirm": {i18n.ValidationMessage(LocaleFrom(r.Context()), "accepted", "confirm")}}))
		return
	}
	d, err := s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if d == nil {
		httpx.Failure(w, http.StatusNotFound, msg(r.Context(), "whatsapp.device_not_found"), nil)
		return
	}
	settings, password, err := s.agentWhatsAppCredentials(r.Context())
	if err != nil {
		writeError(w, r, err)
		return
	}
	base, user := settings["wa.base_url"], settings["wa.username"]
	if base == "" || user == "" || password == "" {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.device_gateway_unconfigured", "whatsapp_gateway_unconfigured")
		return
	}
	newID, err := newAgentWhatsAppDeviceID(agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	timeout := whatsAppTimeout(settings["wa.timeout"])
	status, _, callErr := agentWhatsAppGatewayCall(r.Context(), http.MethodPost, strings.TrimRight(base, "/")+"/devices", user, password, timeout, mustJSON(map[string]string{"device_id": newID}))
	if callErr != nil {
		agentWhatsAppDeviceError(w, r, http.StatusServiceUnavailable, "whatsapp.device_gateway_unreachable", "whatsapp_gateway_unreachable")
		return
	}
	if status < 200 || status >= 300 && status != http.StatusConflict {
		agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
		return
	}
	_, _, _ = agentWhatsAppGatewayCall(r.Context(), http.MethodPost, strings.TrimRight(base, "/")+"/devices/"+url.PathEscape(d.DeviceID)+"/logout", user, password, timeout, nil)
	_, _, _ = agentWhatsAppGatewayCall(r.Context(), http.MethodDelete, strings.TrimRight(base, "/")+"/devices/"+url.PathEscape(d.DeviceID), user, password, timeout, nil)
	_, err = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET device_id=?,state='not_paired',phone_masked=NULL,paired_at=NULL,last_checked_at=UTC_TIMESTAMP(6),replaced_count=replaced_count+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, newID, d.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	props := map[string]any{"replaced_count": d.Replaced + 1}
	if err = agentWhatsAppAudit(r.Context(), s.database, actor.ID, agentID, d.ID, "whatsapp.device.replaced", props, clientMetaFrom(r.Context())); err != nil {
		writeError(w, r, err)
		return
	}
	d, err = s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"device": agentWhatsAppDeviceData(d)}, "OK", nil)
}

func (s *Server) ensureAgentWhatsAppDevice(r *http.Request, actor *auth.User, agentID int64, settings map[string]string, password string) (*agentWhatsAppDeviceRow, error) {
	d, err := s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil || d != nil {
		return d, err
	}
	newID, err := newAgentWhatsAppDeviceID(agentID)
	if err != nil {
		return nil, err
	}
	base, user := strings.TrimRight(settings["wa.base_url"], "/"), settings["wa.username"]
	timeout := whatsAppTimeout(settings["wa.timeout"])
	status, _, err := agentWhatsAppGatewayCall(r.Context(), http.MethodPost, base+"/devices", user, password, timeout, mustJSON(map[string]string{"device_id": newID}))
	if err != nil {
		return nil, err
	}
	if status < 200 || status >= 300 && status != http.StatusConflict {
		return nil, fmt.Errorf("gateway rejected device creation")
	}
	res, err := s.database.ExecContext(r.Context(), `INSERT INTO agent_whatsapp_devices (agent_id,device_id,state,created_at,updated_at) VALUES (?,?,'not_paired',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, agentID, newID)
	if err != nil {
		_, _, _ = agentWhatsAppGatewayCall(r.Context(), http.MethodDelete, base+"/devices/"+url.PathEscape(newID), user, password, timeout, nil)
		return s.agentWhatsAppDevice(r.Context(), agentID)
	}
	id, _ := res.LastInsertId()
	if err = agentWhatsAppAudit(r.Context(), s.database, actor.ID, agentID, id, "whatsapp.device.created", nil, clientMetaFrom(r.Context())); err != nil {
		return nil, err
	}
	return s.agentWhatsAppDevice(r.Context(), agentID)
}

func (s *Server) prepareAgentWhatsAppPair(w http.ResponseWriter, r *http.Request) (*auth.User, int64, *agentWhatsAppDeviceRow, map[string]string, string, bool) {
	actor, agentID, ok := s.agentWhatsAppTarget(w, r)
	if !ok {
		return nil, 0, nil, nil, "", false
	}
	var ready bool
	if err := s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='agent_whatsapp_devices')`).Scan(&ready); err != nil {
		writeError(w, r, err)
		return nil, 0, nil, nil, "", false
	}
	if !ready {
		httpx.Failure(w, http.StatusServiceUnavailable, msg(r.Context(), "whatsapp.migration_pending"), nil)
		return nil, 0, nil, nil, "", false
	}
	settings, password, err := s.agentWhatsAppCredentials(r.Context())
	if err != nil {
		writeError(w, r, err)
		return nil, 0, nil, nil, "", false
	}
	if settings["wa.base_url"] == "" || settings["wa.username"] == "" || password == "" {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.device_gateway_unconfigured", "whatsapp_gateway_unconfigured")
		return nil, 0, nil, nil, "", false
	}
	d, err := s.ensureAgentWhatsAppDevice(r, actor, agentID, settings, password)
	if err != nil {
		agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
		return nil, 0, nil, nil, "", false
	}
	if d == nil {
		writeError(w, r, sql.ErrNoRows)
		return nil, 0, nil, nil, "", false
	}
	s.refreshAgentWhatsAppDevice(r, d.ID, agentID, d.DeviceID, d.State, d.Paired, settings, password)
	d, err = s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return nil, 0, nil, nil, "", false
	}
	if d.State == "connected" {
		agentWhatsAppDeviceError(w, r, http.StatusConflict, "whatsapp.device_already_connected", "whatsapp_device_connected")
		return nil, 0, nil, nil, "", false
	}
	return actor, agentID, d, settings, password, true
}

func (s *Server) handleAgentWhatsAppPairCode(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Phone json.RawMessage `json:"phone"`
	}
	if r.ContentLength > 0 {
		if err := httpx.DecodeJSON(r, &body); err != nil {
			writeError(w, r, err)
			return
		}
	}
	phone := ""
	if len(body.Phone) == 0 || string(body.Phone) == "null" || string(body.Phone) == `""` {
		writeError(w, r, httpx.Validation(map[string][]string{"phone": requiredField(r.Context(), "phone")}))
		return
	}
	if err := json.Unmarshal(body.Phone, &phone); err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"phone": {i18n.ValidationMessage(LocaleFrom(r.Context()), "string", "phone")}}))
		return
	}
	if len([]rune(phone)) > 20 {
		writeError(w, r, httpx.Validation(map[string][]string{"phone": {i18n.ValidationMessage(LocaleFrom(r.Context()), "max.string", "phone", "max", "20")}}))
		return
	}
	digits := agentadmin.NormalizeWhatsAppNumber(phone)
	if digits == "" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(r.Context(), "whatsapp.invalid_number"), Fields: map[string][]string{"phone": {msg(r.Context(), "whatsapp.invalid_number")}}})
		return
	}
	actor, agentID, d, settings, password, ok := s.prepareAgentWhatsAppPair(w, r)
	if !ok {
		return
	}
	base := strings.TrimRight(settings["wa.base_url"], "/")
	endpoint := base + "/devices/" + url.PathEscape(d.DeviceID) + "/login/code?phone=" + url.QueryEscape(digits)
	status, raw, err := agentWhatsAppGatewayCall(r.Context(), http.MethodPost, endpoint, settings["wa.username"], password, whatsAppTimeout(settings["wa.timeout"]), nil)
	if err != nil {
		agentWhatsAppDeviceError(w, r, http.StatusServiceUnavailable, "whatsapp.device_gateway_unreachable", "whatsapp_gateway_unreachable")
		return
	}
	var payload struct {
		Results struct {
			Code string `json:"pair_code"`
		} `json:"results"`
	}
	_ = json.Unmarshal(raw, &payload)
	if status < 200 || status >= 300 || payload.Results.Code == "" {
		agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
		return
	}
	if _, err = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state='pairing',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, d.ID); err != nil {
		writeError(w, r, err)
		return
	}
	if err = agentWhatsAppAudit(r.Context(), s.database, actor.ID, agentID, d.ID, "whatsapp.device.pairing_started", map[string]any{"method": "code"}, clientMetaFrom(r.Context())); err != nil {
		writeError(w, r, err)
		return
	}
	d, err = s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"code": payload.Results.Code, "device": agentWhatsAppDeviceData(d)}, "OK", nil)
}

func (s *Server) handleAgentWhatsAppPairQR(w http.ResponseWriter, r *http.Request) {
	actor, agentID, d, settings, password, ok := s.prepareAgentWhatsAppPair(w, r)
	if !ok {
		return
	}
	base := strings.TrimRight(settings["wa.base_url"], "/")
	timeout := whatsAppTimeout(settings["wa.timeout"])
	status, raw, err := agentWhatsAppGatewayCall(r.Context(), http.MethodGet, base+"/devices/"+url.PathEscape(d.DeviceID)+"/login", settings["wa.username"], password, timeout, nil)
	if err != nil {
		agentWhatsAppDeviceError(w, r, http.StatusServiceUnavailable, "whatsapp.device_gateway_unreachable", "whatsapp_gateway_unreachable")
		return
	}
	var payload struct {
		Results struct {
			Link     string `json:"qr_link"`
			Duration *int   `json:"qr_duration"`
		} `json:"results"`
	}
	_ = json.Unmarshal(raw, &payload)
	path := strings.TrimSpace(payload.Results.Link)
	parsed, parseErr := url.Parse(path)
	qrPath := ""
	if parseErr == nil {
		qrPath = parsed.Path
	}
	if status < 200 || status >= 300 || !(strings.HasPrefix(qrPath, "/statics/qrcode/") || strings.HasPrefix(qrPath, "/statics/images/qrcode/")) || strings.Contains(qrPath, "..") || !strings.HasSuffix(qrPath, ".png") {
		agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
		return
	}
	imgStatus, img, mime, err := agentWhatsAppGatewayImage(r.Context(), base+qrPath, settings["wa.username"], password, timeout)
	if err != nil {
		agentWhatsAppDeviceError(w, r, http.StatusServiceUnavailable, "whatsapp.device_gateway_unreachable", "whatsapp_gateway_unreachable")
		return
	}
	if imgStatus < 200 || imgStatus >= 300 || len(img) == 0 {
		agentWhatsAppDeviceError(w, r, http.StatusBadGateway, "whatsapp.device_gateway_error", "whatsapp_gateway_error")
		return
	}
	if !strings.HasPrefix(mime, "image/") {
		mime = "image/png"
	}
	if _, err = s.database.ExecContext(r.Context(), `UPDATE agent_whatsapp_devices SET state='pairing',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, d.ID); err != nil {
		writeError(w, r, err)
		return
	}
	if err = agentWhatsAppAudit(r.Context(), s.database, actor.ID, agentID, d.ID, "whatsapp.device.pairing_started", map[string]any{"method": "qr"}, clientMetaFrom(r.Context())); err != nil {
		writeError(w, r, err)
		return
	}
	d, err = s.agentWhatsAppDevice(r.Context(), agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	var expires any = payload.Results.Duration
	httpx.OK(w, map[string]any{"image": "data:" + mime + ";base64," + base64.StdEncoding.EncodeToString(img), "expires_in": expires, "device": agentWhatsAppDeviceData(d)}, "OK", nil)
}

func agentWhatsAppGatewayImage(ctx context.Context, endpoint, user, password string, timeout int) (int, []byte, string, error) {
	client := &http.Client{Timeout: time.Duration(timeout) * time.Second}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return 0, nil, "", err
	}
	req.SetBasicAuth(user, password)
	resp, err := client.Do(req)
	if err != nil {
		return 0, nil, "", err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	return resp.StatusCode, data, strings.Split(resp.Header.Get("Content-Type"), ";")[0], err
}

func newAgentWhatsAppDeviceID(agentID int64) (string, error) {
	b := make([]byte, 4)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return fmt.Sprintf("pc-agent-%d-%s", agentID, hex.EncodeToString(b)), nil
}
func mustJSON(v any) []byte { b, _ := json.Marshal(v); return b }

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
