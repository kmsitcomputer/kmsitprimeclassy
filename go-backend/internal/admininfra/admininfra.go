// Package admininfra contains the platform-level configuration and
// reference-data operations that are independent of order and inventory
// workflows.
package admininfra

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

var ErrNotFound = errors.New("admin infrastructure record not found")

type Service struct {
	database *db.DB
	appKey   string
}

func New(database *db.DB, appKey string) *Service {
	return &Service{database: database, appKey: appKey}
}

type Setting struct {
	Key   string `json:"key"`
	Value string `json:"value"`
}

func (s *Service) settings(ctx context.Context, group string) (map[string]string, error) {
	rows, err := s.database.QueryContext(ctx, "SELECT `key`,value FROM settings WHERE `group`=?", group)
	if err != nil {
		if db.IsMissingSchema(err) {
			return map[string]string{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	out := map[string]string{}
	for rows.Next() {
		var key, value string
		if err := rows.Scan(&key, &value); err != nil {
			return nil, err
		}
		out[key] = value
	}
	return out, rows.Err()
}

func (s *Service) Snapshot(ctx context.Context, integration string) (map[string]any, error) {
	group, prefix := "integration.smtp", "smtp."
	if integration == "whatsapp" {
		group, prefix = "integration.whatsapp", "wa."
	} else if integration != "smtp" {
		return nil, errors.New("unknown integration")
	}
	raw, err := s.settings(ctx, group)
	if err != nil {
		return nil, err
	}
	get := func(key, fallback string) string {
		if value, ok := raw[prefix+key]; ok && value != "" {
			return value
		}
		return fallback
	}
	flag := func(key string) bool { return get(key, "0") == "1" || strings.EqualFold(get(key, "0"), "true") }
	out := map[string]any{"enabled": flag("enabled")}
	secretNames := []string{"password"}
	if integration == "smtp" {
		out["host"] = blankNil(get("host", ""))
		out["port"] = intSetting(get("port", ""))
		out["encryption"] = get("encryption", "tls")
		out["username"] = blankNil(get("username", ""))
		out["has_password"] = raw[prefix+"password"] != ""
		out["from_address"] = blankNil(get("from_address", ""))
		out["from_name"] = blankNil(get("from_name", ""))
		out["timeout"] = intSetting(get("timeout", "10"))
		out["transactional_enabled"] = flag("transactional_enabled")
		port := get("port", "")
		out["complete"] = get("host", "") != "" && port != "" && get("from_address", "") != ""
		out["active_source"] = "environment"
		if out["enabled"] == true && out["complete"] == true {
			out["active_source"] = "dashboard"
		}
		return out, nil
	}
	_ = secretNames
	out["provider"] = "gowa"
	out["base_url"] = blankNil(get("base_url", ""))
	out["username"] = blankNil(get("username", ""))
	out["has_password"] = raw["wa.password"] != ""
	out["device_id"] = blankNil(get("device_id", ""))
	out["timeout"] = intSetting(get("timeout", "10"))
	out["max_attempts"] = intSetting(get("max_attempts", "3"))
	out["backoff_seconds"] = intSetting(get("backoff_seconds", "60"))
	passwordReady := false
	if cipher := raw["wa.password"]; cipher != "" {
		plain, err := cryptox.DecryptString(s.appKey, cipher)
		passwordReady = err == nil && plain != ""
	}
	out["configured"] = flag("enabled") && get("base_url", "") != "" && get("username", "") != "" && passwordReady
	var testNumbers []string
	_ = json.Unmarshal([]byte(get("test_numbers", "[]")), &testNumbers)
	out["test_numbers_count"] = len(testNumbers)
	masked := make([]map[string]string, 0, len(testNumbers))
	for _, number := range testNumbers {
		masked = append(masked, map[string]string{"masked": maskNumber(number)})
	}
	out["test_numbers"] = masked
	out["events"] = []any{}
	out["otp"] = map[string]any{
		"enabled":              rawBool(raw, "wa.otp.enabled", false),
		"ttl_minutes":          rawInt(raw, "wa.otp.ttl_minutes", 5),
		"max_attempts":         rawInt(raw, "wa.otp.max_attempts", 5),
		"cooldown_seconds":     rawInt(raw, "wa.otp.cooldown_seconds", 60),
		"limit":                rawInt(raw, "wa.otp.limit", 3),
		"limit_window_minutes": rawInt(raw, "wa.otp.limit_window_minutes", 10),
	}
	out["limits"] = map[string]int{"per_recipient_per_hour": intDefault(get("rate.recipient_hour", "6"), 6), "global_per_minute": intDefault(get("rate.global_minute", "20"), 20)}
	return out, nil
}

func rawBool(values map[string]string, key string, fallback bool) bool {
	v, ok := values[key]
	if !ok {
		return fallback
	}
	return v == "1" || strings.EqualFold(v, "true") || strings.EqualFold(v, "yes") || strings.EqualFold(v, "on")
}

func rawInt(values map[string]string, key string, fallback int) int {
	if n, err := strconv.Atoi(values[key]); err == nil {
		return n
	}
	return fallback
}

func blankNil(v string) any {
	if v == "" {
		return nil
	}
	return v
}

func intSetting(v string) any {
	if v == "" {
		return nil
	}
	return intDefault(v, 0)
}

func intDefault(v string, fallback int) int {
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func maskNumber(v string) string {
	if len(v) <= 5 {
		return v
	}
	return v[:2] + "••••" + v[len(v)-3:]
}

func (s *Service) snapshotSettings(ctx context.Context, group string, secretKeys map[string]bool) (map[string]any, error) {
	raw, err := s.settings(ctx, group)
	if err != nil {
		return nil, err
	}
	out := map[string]any{}
	for key, value := range raw {
		name := strings.TrimPrefix(key, map[string]string{
			"integration.smtp": "smtp.", "integration.whatsapp": "wa.",
		}[group])
		if secretKeys[name] {
			out["has_"+name] = value != ""
			continue
		}
		out[name] = settingValue(value)
	}
	return out, nil
}

func settingValue(value string) any {
	switch value {
	case "0":
		return false
	case "1":
		return true
	}
	if n, err := strconv.Atoi(value); err == nil {
		return n
	}
	return value
}

// SaveSettings writes only the recognized fields for the selected integration.
// Secrets are Laravel-encrypted at rest and omitted from returned snapshots.
func (s *Service) SaveSettings(ctx context.Context, group string, values map[string]any) (map[string]any, error) {
	prefix := map[string]string{"smtp": "smtp.", "whatsapp": "wa."}[group]
	if prefix == "" {
		return nil, fmt.Errorf("unknown integration")
	}
	dbGroup := "integration." + group
	secrets := map[string]bool{"password": true}
	var old map[string]string
	var err error
	if err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		old = map[string]string{}
		rows, err := tx.QueryContext(ctx, "SELECT `key`,value FROM settings WHERE `group`=? FOR UPDATE", dbGroup)
		if err != nil && !db.IsMissingSchema(err) {
			return err
		}
		if err == nil {
			defer rows.Close()
			for rows.Next() {
				var k, v string
				if err := rows.Scan(&k, &v); err != nil {
					return err
				}
				old[k] = v
			}
			if err := rows.Err(); err != nil {
				return err
			}
		}
		writes := map[string]string{}
		for key, value := range values {
			if group == "whatsapp" {
				if key == "limits" {
					limits, _ := value.(map[string]any)
					for name, limit := range limits {
						dbKey := map[string]string{"per_recipient_per_hour": "rate.recipient_hour", "global_per_minute": "rate.global_minute"}[name]
						if dbKey != "" {
							text, err := encodeSetting(limit)
							if err != nil {
								return err
							}
							writes[prefix+dbKey] = text
						}
					}
					continue
				}
				if key == "otp" {
					otp, _ := value.(map[string]any)
					for name, item := range otp {
						if containsWhatsAppOTPField(name) {
							text, err := encodeSetting(item)
							if err != nil {
								return err
							}
							writes[prefix+"otp."+name] = text
						}
					}
					continue
				}
				if key == "events" {
					events, _ := value.(map[string]any)
					for name, enabled := range events {
						if isWhatsAppEvent(name) {
							text, err := encodeSetting(enabled)
							if err != nil {
								return err
							}
							writes[prefix+"event."+name] = text
						}
					}
					continue
				}
			}
			if !allowedSetting(group, key) {
				continue
			}
			text, err := encodeSetting(value)
			if err != nil {
				return err
			}
			if secrets[key] {
				if text == "" { // Blank means keep the existing value.
					continue
				}
				text, err = cryptox.EncryptString(s.appKey, text)
				if err != nil {
					return err
				}
			}
			writes[prefix+key] = text
		}
		if enabled, ok := writes[prefix+"enabled"]; ok && enabled == "1" {
			host, port, from := choose(writes, old, prefix+"host"), choose(writes, old, prefix+"port"), choose(writes, old, prefix+"from_address")
			if group == "whatsapp" {
				host, port, from = choose(writes, old, "wa.base_url"), choose(writes, old, "wa.username"), choose(writes, old, "wa.password")
			}
			if host == "" || port == "" || from == "" {
				return errors.New("integration configuration is incomplete")
			}
		}
		for key, value := range writes {
			if _, err := tx.ExecContext(ctx, "INSERT INTO settings (`key`,value,`group`,is_public,created_at,updated_at) "+
				"VALUES (?,?,?,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "+
				"ON DUPLICATE KEY UPDATE value=VALUES(value),`group`=VALUES(`group`),is_public=0,updated_at=UTC_TIMESTAMP(6)",
				key, value, dbGroup); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return nil, err
	}
	return s.snapshotSettings(ctx, dbGroup, secrets)
}

func containsWhatsAppOTPField(name string) bool {
	switch name {
	case "enabled", "ttl_minutes", "max_attempts", "cooldown_seconds", "limit", "limit_window_minutes":
		return true
	}
	return false
}

func isWhatsAppEvent(name string) bool {
	switch name {
	case "order_created", "payment_paid", "payment_partial", "order_processed", "order_shipped", "order_delivered", "order_cancelled", "return_requested", "return_approved", "refund_completed", "payment_proof_uploaded", "payment_failed", "return_rejected", "refund_requested", "refund_approved", "refund_rejected":
		return true
	}
	return false
}

func allowedSetting(group, key string) bool {
	smtp := map[string]bool{
		"enabled": true, "transactional_enabled": true, "host": true, "port": true, "encryption": true,
		"username": true, "password": true, "clear_password": true, "from_address": true, "from_name": true, "timeout": true,
	}
	wa := map[string]bool{
		"enabled": true, "base_url": true, "username": true, "password": true, "device_id": true,
		"timeout": true, "max_attempts": true, "backoff_seconds": true, "test_numbers": true, "limits": true, "otp": true, "events": true,
	}
	if group == "smtp" {
		return smtp[key]
	}
	return wa[key]
}

func encodeSetting(value any) (string, error) {
	switch v := value.(type) {
	case string:
		return strings.TrimSpace(v), nil
	case bool:
		if v {
			return "1", nil
		}
		return "0", nil
	case float64:
		return strconv.FormatInt(int64(v), 10), nil
	case int:
		return strconv.Itoa(v), nil
	case nil:
		return "", nil
	default:
		b, err := json.Marshal(v)
		return string(b), err
	}
}

func choose(writes, existing map[string]string, key string) string {
	if v, ok := writes[key]; ok {
		return v
	}
	return existing[key]
}

func (s *Service) GoogleAuth(ctx context.Context, agentID *int64) (map[string]any, error) {
	table, scope := "google_auth_settings", int64(1)
	if agentID != nil {
		table, scope = "agent_google_auth_configs", *agentID
	}
	query := `SELECT is_enabled,client_id,client_secret,redirect_uri,frontend_url FROM ` + table + ` WHERE `
	args := []any{scope}
	if agentID == nil {
		query += `id=?`
	} else {
		query += `agent_id=?`
	}
	var enabled bool
	var clientID, cipher, redirect, frontend sql.NullString
	err := s.database.QueryRowContext(ctx, query, args...).Scan(&enabled, &clientID, &cipher, &redirect, &frontend)
	if errors.Is(err, sql.ErrNoRows) {
		return map[string]any{"is_enabled": false, "client_id": nil, "redirect_uri": nil, "frontend_url": nil, "has_secret": false}, nil
	}
	if err != nil && !db.IsMissingSchema(err) {
		return nil, err
	}
	var secret string
	if cipher.Valid && cipher.String != "" {
		secret, err = cryptox.DecryptString(s.appKey, cipher.String)
		if err != nil {
			secret = ""
		}
	}
	_ = secret // Decrypted only to validate the Laravel cast; never returned.
	return map[string]any{"is_enabled": enabled, "client_id": nullable(clientID), "redirect_uri": nullable(redirect),
		"frontend_url": nullable(frontend), "has_secret": secret != ""}, nil
}

// SaveGoogleAuth retains a previous secret when the caller sends a blank one,
// permits explicit clearing, and never returns plaintext or ciphertext.
func (s *Service) SaveGoogleAuth(ctx context.Context, agentID *int64, input map[string]any) (map[string]any, error) {
	table, keyCol, scope := "google_auth_settings", "id", int64(1)
	if agentID != nil {
		table, keyCol, scope = "agent_google_auth_configs", "agent_id", *agentID
	}
	secret, _ := input["client_secret"].(string)
	clear, _ := input["clear_secret"].(bool)
	var ciphertext any
	if clear {
		ciphertext = nil
	} else if strings.TrimSpace(secret) != "" {
		enc, err := cryptox.EncryptString(s.appKey, secret)
		if err != nil {
			return nil, err
		}
		ciphertext = enc
	}
	enabled, _ := input["is_enabled"].(bool)
	getText := func(k string) any {
		v, _ := input[k].(string)
		v = strings.TrimSpace(v)
		if v == "" {
			return nil
		}
		return v
	}
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var err error
		var exists bool
		if agentID == nil {
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM google_auth_settings WHERE id=?)`, scope).Scan(&exists); err != nil {
				return err
			}
		} else if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM agent_google_auth_configs WHERE agent_id=?)`, scope).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			if agentID == nil {
				if clear || ciphertext != nil {
					_, err = tx.ExecContext(ctx, `INSERT INTO google_auth_settings (id,is_enabled,client_id,redirect_uri,frontend_url,client_secret,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, scope, enabled, getText("client_id"), getText("redirect_uri"), getText("frontend_url"), ciphertext)
				} else {
					_, err = tx.ExecContext(ctx, `INSERT INTO google_auth_settings (id,is_enabled,client_id,redirect_uri,frontend_url,created_at,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, scope, enabled, getText("client_id"), getText("redirect_uri"), getText("frontend_url"))
				}
			} else if clear || ciphertext != nil {
				_, err = tx.ExecContext(ctx, `INSERT INTO agent_google_auth_configs (agent_id,is_enabled,client_id,redirect_uri,frontend_url,client_secret,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, scope, enabled, getText("client_id"), getText("redirect_uri"), getText("frontend_url"), ciphertext)
			} else {
				_, err = tx.ExecContext(ctx, `INSERT INTO agent_google_auth_configs (agent_id,is_enabled,client_id,redirect_uri,frontend_url,created_at,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, scope, enabled, getText("client_id"), getText("redirect_uri"), getText("frontend_url"))
			}
			return err
		}
		if clear || ciphertext != nil {
			_, err = tx.ExecContext(ctx, `UPDATE `+table+` SET is_enabled=?,client_id=?,redirect_uri=?,frontend_url=?,client_secret=?,updated_at=UTC_TIMESTAMP(6) WHERE `+keyCol+`=?`, enabled, getText("client_id"), getText("redirect_uri"), getText("frontend_url"), ciphertext, scope)
		} else {
			_, err = tx.ExecContext(ctx, `UPDATE `+table+` SET is_enabled=?,client_id=?,redirect_uri=?,frontend_url=?,updated_at=UTC_TIMESTAMP(6) WHERE `+keyCol+`=?`, enabled, getText("client_id"), getText("redirect_uri"), getText("frontend_url"), scope)
		}
		return err
	})
	if err != nil {
		return nil, err
	}
	updated, err := s.GoogleAuth(ctx, agentID)
	if err != nil {
		return nil, err
	}
	return map[string]any{"has_secret": updated["has_secret"]}, nil
}

func (s *Service) ShippingProviders(ctx context.Context) ([]map[string]any, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT id,code,name,is_active FROM shipping_providers WHERE code IN ('rajaongkir','openroute','kmsit') ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id int64
		var code, name string
		var active bool
		if err := rows.Scan(&id, &code, &name, &active); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": id, "code": code, "name": name, "is_active": active})
	}
	return out, rows.Err()
}

func (s *Service) ToggleShippingProvider(ctx context.Context, id int64) (map[string]any, error) {
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var code, name string
		var active bool
		err := tx.QueryRowContext(ctx, `SELECT code,name,is_active FROM shipping_providers WHERE id=? FOR UPDATE`, id).Scan(&code, &name, &active)
		if errors.Is(err, sql.ErrNoRows) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		if code != "rajaongkir" && code != "openroute" && code != "kmsit" {
			return ErrNotFound
		}
		active = !active
		_, err = tx.ExecContext(ctx, `UPDATE shipping_providers SET is_active=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, active, id)
		out = map[string]any{"id": id, "code": code, "name": name, "is_active": active}
		return err
	})
	return out, err
}

func (s *Service) Couriers(ctx context.Context) ([]map[string]any, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT id,code,name,is_active FROM shipping_couriers ORDER BY name`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id int64
		var code, name string
		var active bool
		if err := rows.Scan(&id, &code, &name, &active); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": id, "code": code, "name": name, "is_active": active})
	}
	return out, rows.Err()
}

func (s *Service) CreateCourier(ctx context.Context, code, name string) (map[string]any, error) {
	code, name = strings.ToLower(strings.TrimSpace(code)), strings.TrimSpace(name)
	if !validCourierCode(code) || name == "" || len(code) > 30 || len(name) > 100 {
		return nil, errors.New("invalid courier")
	}
	res, err := s.database.ExecContext(ctx, `INSERT INTO shipping_couriers (code,name,is_active,created_at,updated_at) VALUES (?,?,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, code, name)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": id, "code": code, "name": name, "is_active": true}, nil
}

func validCourierCode(code string) bool {
	if code == "" {
		return false
	}
	for _, c := range code {
		if !(c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '_' || c == '-') {
			return false
		}
	}
	return true
}

func (s *Service) ToggleCourier(ctx context.Context, id int64) (map[string]any, error) {
	var code, name string
	var active bool
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		err := tx.QueryRowContext(ctx, `SELECT code,name,is_active FROM shipping_couriers WHERE id=? FOR UPDATE`, id).Scan(&code, &name, &active)
		if errors.Is(err, sql.ErrNoRows) {
			return ErrNotFound
		}
		if err != nil {
			return err
		}
		active = !active
		_, err = tx.ExecContext(ctx, `UPDATE shipping_couriers SET is_active=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, active, id)
		return err
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": id, "code": code, "name": name, "is_active": active}, nil
}

func ValidateWhatsAppBaseURL(raw string, trustedHosts []string) (string, error) {
	raw = strings.TrimRight(strings.TrimSpace(raw), "/")
	u, err := url.Parse(raw)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" || u.User != nil {
		return "", errors.New("invalid base URL")
	}
	host := strings.ToLower(u.Hostname())
	if host == "metadata.google.internal" {
		return "", errors.New("invalid base URL")
	}
	if ip := net.ParseIP(host); ip != nil && ip.IsLinkLocalUnicast() {
		return "", errors.New("invalid base URL")
	}
	for _, allow := range trustedHosts {
		if strings.EqualFold(host, allow) {
			return raw, nil
		}
	}
	return raw, nil
}

func nullable(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func StringValue(m map[string]any, key string) string {
	value, _ := m[key].(string)
	return value
}

func ParseDate(value string) (*time.Time, error) {
	if value == "" {
		return nil, nil
	}
	t, err := time.Parse("2006-01-02", value)
	if err != nil {
		return nil, err
	}
	return &t, nil
}
