// Package agentadmin implements branch-scoped agent profile and configuration operations.
package agentadmin

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net"
	"net/url"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

type Error struct {
	Status  int
	Message string
	Fields  map[string][]string
}

func (e *Error) Error() string              { return e.Message }
func fail(status int, message string) error { return &Error{Status: status, Message: message} }

type Service struct {
	database     *db.DB
	appKey       string
	trustedHosts []string
}

func New(database *db.DB, appKey string, trustedHosts ...[]string) *Service {
	s := &Service{database: database, appKey: appKey}
	if len(trustedHosts) > 0 {
		s.trustedHosts = trustedHosts[0]
	}
	return s
}

// ResolveAgent applies the two distinct Laravel scopes: profile/shipping are agen-only; payment and WhatsApp
// management allow the branch's Agen or Admin, with the branch id derived from the authenticated identity.
func ResolveAgent(actor *auth.User, allowAdmin bool) (int64, error) {
	if actor == nil || actor.AgentID == nil {
		return 0, fail(403, "Anda tidak memiliki akses untuk melakukan aksi ini.")
	}
	if actor.IsRole(auth.RoleAgen) && *actor.AgentID == actor.ID {
		return actor.ID, nil
	}
	if allowAdmin && actor.IsRole(auth.RoleAdmin) {
		return *actor.AgentID, nil
	}
	return 0, fail(403, "Anda tidak memiliki akses untuk melakukan aksi ini.")
}

type StoreProfile struct {
	StoreName string  `json:"store_name"`
	Address   string  `json:"address"`
	Phone     *string `json:"phone"`
	Latitude  *string `json:"latitude"`
	Longitude *string `json:"longitude"`
}

type StoreProfileInput struct {
	StoreName string   `json:"store_name"`
	Address   string   `json:"address"`
	Phone     *string  `json:"phone"`
	Latitude  *float64 `json:"latitude"`
	Longitude *float64 `json:"longitude"`
}

func (s *Service) StoreProfile(ctx context.Context, agentID int64) (*StoreProfile, error) {
	var out StoreProfile
	var phone sql.NullString
	var lat, lng sql.NullString
	err := s.database.QueryRowContext(ctx, `SELECT store_name,address,phone,CAST(latitude AS CHAR),CAST(longitude AS CHAR) FROM agent_profiles WHERE user_id=?`, agentID).
		Scan(&out.StoreName, &out.Address, &phone, &lat, &lng)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if phone.Valid {
		out.Phone = &phone.String
	}
	if lat.Valid {
		out.Latitude = &lat.String
	}
	if lng.Valid {
		out.Longitude = &lng.String
	}
	return &out, nil
}

func (s *Service) UpdateStoreProfile(ctx context.Context, agentID int64, p StoreProfileInput) error {
	fields := make(map[string][]string)
	required := func(field string, missing bool) {
		if missing {
			fields[field] = []string{i18n.ValidationMessage(i18n.Default, "required", field)}
		}
	}
	required("store_name", strings.TrimSpace(p.StoreName) == "")
	required("address", strings.TrimSpace(p.Address) == "")
	required("latitude", p.Latitude == nil)
	required("longitude", p.Longitude == nil)
	if len([]rune(p.StoreName)) > 150 {
		fields["store_name"] = []string{i18n.ValidationMessage(i18n.Default, "max.string", "store_name", "max", "150")}
	}
	if p.Phone != nil && len([]rune(*p.Phone)) > 20 {
		fields["phone"] = []string{i18n.ValidationMessage(i18n.Default, "max.string", "phone", "max", "20")}
	}
	if p.Latitude != nil && (math.IsNaN(*p.Latitude) || math.Abs(*p.Latitude) > 90) {
		fields["latitude"] = []string{i18n.ValidationMessage(i18n.Default, "between.numeric", "latitude", "min", "-90", "max", "90")}
	}
	if p.Longitude != nil && (math.IsNaN(*p.Longitude) || math.Abs(*p.Longitude) > 180) {
		fields["longitude"] = []string{i18n.ValidationMessage(i18n.Default, "between.numeric", "longitude", "min", "-180", "max", "180")}
	}
	if len(fields) != 0 {
		return &Error{Status: 422, Message: i18n.Translate(i18n.Default, "system.validation_failed"), Fields: fields}
	}
	_, err := s.database.ExecContext(ctx, `INSERT INTO agent_profiles (user_id,store_name,address,phone,latitude,longitude,created_at,updated_at)
		VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE store_name=VALUES(store_name),address=VALUES(address),phone=VALUES(phone),
			latitude=VALUES(latitude),longitude=VALUES(longitude),updated_at=UTC_TIMESTAMP(6)`,
		agentID, strings.TrimSpace(p.StoreName), strings.TrimSpace(p.Address), nullableString(p.Phone), *p.Latitude, *p.Longitude)
	return err
}

type PaymentMethod struct {
	ID           int64  `json:"id"`
	Code         string `json:"code"`
	Name         string `json:"name"`
	Type         string `json:"type"`
	GlobalActive bool   `json:"globally_active"`
	Active       bool   `json:"is_active"`
	Configured   *bool  `json:"configured"`
}

func (s *Service) PaymentMethods(ctx context.Context, agentID int64) ([]PaymentMethod, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT pm.id,pm.code,pm.name,pm.type,pm.is_active,
		COALESCE(ams.is_active,1),CASE WHEN pm.type='cod' THEN NULL ELSE
		EXISTS(SELECT 1 FROM agent_payment_gateway_configs c WHERE c.agent_id=? AND c.payment_method_id=pm.id) END
		FROM payment_methods pm LEFT JOIN agent_payment_method_settings ams
		  ON ams.agent_id=? AND ams.payment_method_id=pm.id
		WHERE pm.type<>'gateway' ORDER BY pm.id`, agentID, agentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []PaymentMethod{}
	for rows.Next() {
		var m PaymentMethod
		var configured sql.NullBool
		if err := rows.Scan(&m.ID, &m.Code, &m.Name, &m.Type, &m.GlobalActive, &m.Active, &configured); err != nil {
			return nil, err
		}
		if configured.Valid {
			m.Configured = &configured.Bool
		}
		out = append(out, m)
	}
	return out, rows.Err()
}

func (s *Service) TogglePaymentMethod(ctx context.Context, agentID, methodID int64) (*PaymentMethod, error) {
	var m PaymentMethod
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := tx.QueryRowContext(ctx, `SELECT id,code,name,type,is_active FROM payment_methods WHERE id=?`, methodID).
			Scan(&m.ID, &m.Code, &m.Name, &m.Type, &m.GlobalActive); err != nil {
			return err
		}
		if m.Type != "manual" && m.Type != "cod" {
			return fail(403, i18n.Translate(i18n.Default, "system.unauthorized_action"))
		}
		var current bool
		err := tx.QueryRowContext(ctx, `SELECT is_active FROM agent_payment_method_settings WHERE agent_id=? AND payment_method_id=?`, agentID, methodID).Scan(&current)
		if errors.Is(err, sql.ErrNoRows) {
			current = true
		} else if err != nil {
			return err
		}
		m.Active = !current
		if _, err := tx.ExecContext(ctx, `INSERT INTO agent_payment_method_settings (agent_id,payment_method_id,is_active,created_at,updated_at)
			VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),updated_at=UTC_TIMESTAMP(6)`,
			agentID, methodID, m.Active); err != nil {
			return err
		}
		// Laravel's toggle response computes `configured` exactly like the index projection:
		// null for COD, otherwise whether a gateway-config row exists for this agent+method.
		if m.Type == "cod" {
			m.Configured = nil
		} else {
			var cfg bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM agent_payment_gateway_configs
				WHERE agent_id=? AND payment_method_id=?)`, agentID, methodID).Scan(&cfg); err != nil {
				return err
			}
			m.Configured = &cfg
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return &m, nil
}

func (s *Service) SavePaymentConfig(ctx context.Context, agentID, methodID int64, environment string, config map[string]any) error {
	var code, methodType string
	if err := s.database.QueryRowContext(ctx, `SELECT code,type FROM payment_methods WHERE id=?`, methodID).Scan(&code, &methodType); err != nil {
		return err
	}
	// Laravel ordering: the FormRequest validates FIRST (config required array + config.* rules +
	// environment in:sandbox,production), THEN the controller's type check 403s a non-manual method.
	// Sentences: top-level names go through validation.attributes ("config" -> "config");
	// config.bank_name renders as "config.bank name" (headline-case of the post-dot segment).
	fields := map[string][]string{}
	if environment != "" && environment != "sandbox" && environment != "production" {
		fields["environment"] = []string{i18n.ValidationMessage(i18n.Default, "in", "environment")}
	}
	if len(config) == 0 {
		fields["config"] = []string{i18n.ValidationMessage(i18n.Default, "required", "config")}
	}
	// PaymentGatewayFields::rulesFor(bank_transfer) — the only method with a field contract.
	for field, maxLen := range map[string]int{"bank_name": 60, "account_name": 100, "account_number": 40} {
		value, hasValue := config[field].(string)
		path := "config." + field
		if !hasValue || strings.TrimSpace(value) == "" {
			fields[path] = append(fields[path], i18n.ValidationMessage(i18n.Default, "required", path))
		} else if len([]rune(value)) > maxLen {
			fields[path] = append(fields[path], i18n.ValidationMessage(i18n.Default, "max.string", path, "max", fmt.Sprint(maxLen)))
		}
	}
	if len(fields) > 0 {
		return &Error{Status: 422, Message: i18n.Translate(i18n.Default, "system.validation_failed"), Fields: fields}
	}
	if methodType != "manual" || code != "bank_transfer" {
		return fail(403, i18n.Translate(i18n.Default, "system.unauthorized_action"))
	}
	if environment == "" {
		_ = s.database.QueryRowContext(ctx, `SELECT active_environment FROM agent_payment_method_settings WHERE agent_id=? AND payment_method_id=?`, agentID, methodID).Scan(&environment)
		if environment == "" {
			environment = "sandbox"
		}
	}
	ciphertext, err := cryptox.EncryptArray(s.appKey, config)
	if err != nil {
		return err
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO agent_payment_gateway_configs (agent_id,payment_method_id,environment,config,created_at,updated_at)
		VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE config=VALUES(config),updated_at=UTC_TIMESTAMP(6)`, agentID, methodID, environment, ciphertext)
	return err
}

type ShippingProvider struct {
	ID           int64          `json:"id"`
	Code         string         `json:"code"`
	Name         string         `json:"name"`
	GlobalActive bool           `json:"globally_active"`
	Active       bool           `json:"is_active"`
	Configured   bool           `json:"configured"`
	APIVersion   *string        `json:"api_version"`
	Origin       *string        `json:"origin"`
	Couriers     []string       `json:"couriers"`
	Profile      *string        `json:"profile"`
	BaseURL      *string        `json:"base_url"`
	HasToken     *bool          `json:"has_token"`
	OriginCoords map[string]any `json:"origin_coordinates"`
	Pricing      map[string]any `json:"pricing"`
}

func (s *Service) ShippingProviders(ctx context.Context, agentID int64) ([]ShippingProvider, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT p.id,p.code,p.name,p.is_active,COALESCE(st.is_active,1),c.config,
		sc.price_per_km,sc.minimum_distance_km,sc.minimum_charge,sc.free_shipping_enabled,sc.free_shipping_min_amount,
		ap.latitude,ap.longitude
		FROM shipping_providers p
		LEFT JOIN agent_shipping_provider_settings st ON st.agent_id=? AND st.shipping_provider_id=p.id
		LEFT JOIN agent_shipping_provider_configs c ON c.agent_id=? AND c.shipping_provider_id=p.id
		LEFT JOIN shipping_configurations sc ON sc.agent_id=? AND sc.shipping_provider_id=(SELECT id FROM shipping_providers WHERE code='openroute' LIMIT 1)
		LEFT JOIN agent_profiles ap ON ap.user_id=?
		WHERE p.code IN ('rajaongkir','openroute','kmsit') ORDER BY p.id`, agentID, agentID, agentID, agentID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []ShippingProvider{}
	for rows.Next() {
		var p ShippingProvider
		var raw sql.NullString
		var price, dist, charge, freeMin sql.NullFloat64
		var free sql.NullBool
		var lat, lng sql.NullFloat64
		if err := rows.Scan(&p.ID, &p.Code, &p.Name, &p.GlobalActive, &p.Active, &raw, &price, &dist, &charge, &free, &freeMin, &lat, &lng); err != nil {
			return nil, err
		}
		p.Couriers = []string{}
		var cfg map[string]any
		if raw.Valid {
			p.Configured = true
			cfg, _ = cryptox.DecryptArray(s.appKey, raw.String)
		}
		switch p.Code {
		case "rajaongkir":
			p.APIVersion = stringPtr(valueString(cfg, "api_version", "legacy"))
			p.Origin = stringVal(cfg, "origin_label")
			p.Couriers = valueStrings(cfg["couriers"])
		case "openroute":
			// The index falls back to config('services.openroute.profile') — 'driving-car'.
			p.Profile = stringPtr(valueString(cfg, "profile", "driving-car"))
		case "kmsit":
			p.Profile = stringPtr(valueString(cfg, "profile", "car"))
			p.BaseURL = stringVal(cfg, "base_url")
			has := strings.TrimSpace(valueString(cfg, "token", "")) != ""
			p.HasToken = &has
		}
		if p.Code == "openroute" && lat.Valid && lng.Valid {
			p.OriginCoords = map[string]any{"latitude": lat.Float64, "longitude": lng.Float64}
		}
		// Laravel's pricing block carries EXACTLY three keys (price_per_km / minimum_distance_km /
		// minimum_charge) — KurirTokoTariff::present output, no free-shipping fields.
		if (p.Code == "openroute" || p.Code == "kmsit") && price.Valid {
			p.Pricing = map[string]any{"price_per_km": price.Float64, "minimum_distance_km": floatOrZero(dist), "minimum_charge": floatOrZero(charge)}
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

type ShippingProviderToggle struct {
	ID           int64  `json:"id"`
	Code         string `json:"code"`
	Name         string `json:"name"`
	GlobalActive bool   `json:"globally_active"`
	Active       bool   `json:"is_active"`
}

func (s *Service) ToggleShippingProvider(ctx context.Context, agentID, providerID int64) (*ShippingProviderToggle, error) {
	full, err := s.toggleShippingProviderDetail(ctx, agentID, providerID)
	if err != nil {
		return nil, err
	}
	// Laravel's toggle response carries EXACTLY five keys: id/code/name/globally_active/is_active.
	return &ShippingProviderToggle{ID: full.ID, Code: full.Code, Name: full.Name,
		GlobalActive: full.GlobalActive, Active: full.Active}, nil
}

// toggleShippingProviderDetail computes the toggle against the full projection (config rows, settings).
func (s *Service) toggleShippingProviderDetail(ctx context.Context, agentID, providerID int64) (*ShippingProvider, error) {
	var p ShippingProvider
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := tx.QueryRowContext(ctx, `SELECT id,code,name,is_active FROM shipping_providers WHERE id=?`, providerID).Scan(&p.ID, &p.Code, &p.Name, &p.GlobalActive); err != nil {
			return err
		}
		var active bool
		err := tx.QueryRowContext(ctx, `SELECT is_active FROM agent_shipping_provider_settings WHERE agent_id=? AND shipping_provider_id=?`, agentID, providerID).Scan(&active)
		if errors.Is(err, sql.ErrNoRows) {
			active = true
		} else if err != nil {
			return err
		}
		p.Active = !active
		_, err = tx.ExecContext(ctx, `INSERT INTO agent_shipping_provider_settings (agent_id,shipping_provider_id,is_active,created_at,updated_at)
			VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active),updated_at=UTC_TIMESTAMP(6)`,
			agentID, providerID, p.Active)
		return err
	})
	return &p, err
}

func (s *Service) SaveShippingConfig(ctx context.Context, agentID, providerID int64, config map[string]any) error {
	var code string
	if err := s.database.QueryRowContext(ctx, `SELECT code FROM shipping_providers WHERE id=?`, providerID).Scan(&code); err != nil {
		return err
	}
	if code != "rajaongkir" && code != "openroute" && code != "kmsit" {
		return fail(404, "Provider tidak ditemukan.")
	}
	if config == nil {
		config = map[string]any{}
	}
	if code == "kmsit" && strings.TrimSpace(valueString(config, "profile", "")) == "" {
		config["profile"] = "car"
	}
	if code == "openroute" && strings.TrimSpace(valueString(config, "profile", "")) == "" {
		config["profile"] = "driving-car"
	}
	if err := validateShippingConfig(code, config); err != nil {
		return err
	}
	if code == "rajaongkir" {
		if err := s.validateSupportedCouriers(ctx, config); err != nil {
			return err
		}
	}
	// A blank KMSIT token means "keep the old secret", never erase or echo it.
	if code == "kmsit" && strings.TrimSpace(valueString(config, "token", "")) == "" {
		var raw string
		err := s.database.QueryRowContext(ctx, `SELECT config FROM agent_shipping_provider_configs WHERE agent_id=? AND shipping_provider_id=?`, agentID, providerID).Scan(&raw)
		if err == nil {
			old, decryptErr := cryptox.DecryptArray(s.appKey, raw)
			if decryptErr != nil {
				return fail(422, "Konfigurasi terenkripsi tidak dapat dibaca; simpan ulang kredensial.")
			}
			if token := valueString(old, "token", ""); token != "" {
				config["token"] = token
			}
		}
	}
	if code == "kmsit" {
		if strings.TrimSpace(valueString(config, "token", "")) == "" {
			return &Error{Status: 422, Message: "API key KMSIT wajib diisi.", Fields: map[string][]string{"config.token": {"API key KMSIT wajib diisi."}}}
		}
		config["base_url"] = strings.TrimRight(valueString(config, "base_url", ""), "/")
		if strings.TrimSpace(valueString(config, "profile", "")) == "" {
			config["profile"] = "car"
		}
		if err := vetKmsitURL(valueString(config, "base_url", ""), s.trustedHosts); err != nil {
			return &Error{Status: 422,
				Message: "Endpoint KMSIT tidak diizinkan atau tidak dapat dijangkau.",
				Fields:  map[string][]string{"config.base_url": {"Endpoint ditolak oleh kebijakan keamanan."}},
			}
		}
	}
	raw, err := cryptox.EncryptArray(s.appKey, config)
	if err != nil {
		return err
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO agent_shipping_provider_configs (agent_id,shipping_provider_id,config,created_at,updated_at)
		VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE config=VALUES(config),updated_at=UTC_TIMESTAMP(6)`, agentID, providerID, raw)
	return err
}

func (s *Service) validateSupportedCouriers(ctx context.Context, config map[string]any) error {
	values, ok := config["couriers"].([]any)
	if !ok {
		return fail(422, "Pilih minimal satu kurir.")
	}
	rows, err := s.database.QueryContext(ctx, `SELECT LOWER(code) FROM shipping_couriers WHERE is_active=1`)
	if err != nil {
		return err
	}
	supported := map[string]bool{}
	for rows.Next() {
		var code string
		if err := rows.Scan(&code); err != nil {
			rows.Close()
			return err
		}
		supported[code] = true
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	for _, value := range values {
		code, ok := value.(string)
		if !ok || !supported[strings.ToLower(strings.TrimSpace(code))] {
			return fail(422, "Kurir yang dipilih tidak didukung.")
		}
	}
	return nil
}

func validateShippingConfig(code string, c map[string]any) error {
	required := map[string][]string{
		"rajaongkir": {"api_key", "api_version", "origin_destination_id", "origin_label", "origin_search", "couriers"},
		"openroute":  {"api_key", "profile"},
		"kmsit":      {"base_url", "profile"},
	}[code]
	fields := make(map[string][]string)
	if len(c) == 0 {
		fields["config"] = []string{i18n.ValidationMessage(i18n.Default, "required", "config")}
	}
	for _, key := range required {
		if c[key] == nil || strings.TrimSpace(fmt.Sprint(c[key])) == "" {
			path := "config." + key
			fields[path] = []string{i18n.ValidationMessage(i18n.Default, "required", path)}
		}
	}
	if len(fields) > 0 {
		return &Error{Status: 422, Message: i18n.Translate(i18n.Default, "system.validation_failed"), Fields: fields}
	}
	for field, maxLength := range map[string]int{"api_key": 255, "origin_label": 255, "origin_search": 100, "base_url": 255, "token": 255} {
		if value, ok := c[field].(string); ok && len(value) > maxLength {
			return &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"config." + field: {"Nilai melebihi panjang maksimum."}}}
		}
	}
	switch code {
	case "rajaongkir":
		if valueString(c, "api_version", "") != "komerce_v2" {
			return fail(422, "Versi RajaOngkir tidak valid.")
		}
		id, err := strconv.ParseInt(fmt.Sprint(c["origin_destination_id"]), 10, 64)
		if err != nil || id < 1 {
			return fail(422, "Origin RajaOngkir tidak valid.")
		}
		arr, ok := c["couriers"].([]any)
		if !ok || len(arr) == 0 {
			return fail(422, "Pilih minimal satu kurir.")
		}
	case "openroute":
		if valueString(c, "profile", "") != "driving-car" && valueString(c, "profile", "") != "driving-hgv" && valueString(c, "profile", "") != "cycling-regular" && valueString(c, "profile", "") != "cycling-road" && valueString(c, "profile", "") != "cycling-mountain" && valueString(c, "profile", "") != "cycling-electric" && valueString(c, "profile", "") != "foot-walking" && valueString(c, "profile", "") != "foot-hiking" && valueString(c, "profile", "") != "wheelchair" {
			return fail(422, "Profil OpenRoute tidak valid.")
		}
	case "kmsit":
		if p := valueString(c, "profile", "car"); p != "car" && p != "motorcycle" {
			return fail(422, "Profil KMSIT tidak valid.")
		}
	}
	return nil
}

func vetKmsitURL(raw string, trustedHosts []string) error {
	u, err := url.ParseRequestURI(raw)
	if err != nil || u.Hostname() == "" || (u.Scheme != "https" && u.Scheme != "http") {
		return fail(422, "Endpoint KMSIT tidak diizinkan.")
	}
	if u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return fail(422, "Endpoint KMSIT tidak diizinkan.")
	}
	host := strings.ToLower(u.Hostname())
	trusted := false
	for _, allowed := range trustedHosts {
		if strings.EqualFold(strings.TrimSpace(allowed), host) {
			trusted = true
			break
		}
	}
	if u.Scheme == "http" && !trusted {
		return fail(422, "Endpoint KMSIT tidak diizinkan.")
	}
	if !trusted && (strings.EqualFold(host, "localhost") || strings.HasSuffix(host, ".localhost")) {
		return fail(422, "Endpoint KMSIT tidak diizinkan.")
	}
	var ips []net.IP
	if ip := net.ParseIP(host); ip != nil {
		ips = []net.IP{ip}
	} else if resolved, err := net.LookupIP(host); err == nil {
		ips = resolved
	}
	if len(ips) == 0 {
		return fail(422, "Endpoint KMSIT tidak diizinkan.")
	}
	for _, ip := range ips {
		if ip.IsUnspecified() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() ||
			(!trusted && (!ip.IsGlobalUnicast() || ip.IsPrivate() || ip.IsLoopback())) {
			return fail(422, "Endpoint KMSIT tidak diizinkan.")
		}
	}
	return nil
}

func (s *Service) ShippingCouriers(ctx context.Context, agentID, providerID int64) ([]map[string]any, error) {
	var code string
	if err := s.database.QueryRowContext(ctx, `SELECT code FROM shipping_providers WHERE id=?`, providerID).Scan(&code); err != nil {
		return nil, err
	}
	if code != "rajaongkir" {
		return nil, fail(404, i18n.Translate(i18n.Default, "system.endpoint_not_found"))
	}
	enabled := map[string]bool{}
	var raw string
	if s.database.QueryRowContext(ctx, `SELECT config FROM agent_shipping_provider_configs WHERE agent_id=? AND shipping_provider_id=?`, agentID, providerID).Scan(&raw) == nil {
		cfg, err := cryptox.DecryptArray(s.appKey, raw)
		if err != nil {
			return nil, fail(422, "Konfigurasi terenkripsi tidak dapat dibaca.")
		}
		for _, c := range valueStrings(cfg["couriers"]) {
			enabled[strings.ToLower(c)] = true
		}
	}
	rows, err := s.database.QueryContext(ctx, `SELECT code,name FROM shipping_couriers WHERE is_active=1 ORDER BY name`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var code, name string
		if err := rows.Scan(&code, &name); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"code": code, "name": name, "supported": true, "enabled": enabled[strings.ToLower(code)]})
	}
	return out, rows.Err()
}

func (s *Service) UpdateCouriers(ctx context.Context, agentID, providerID int64, couriers []string) ([]string, error) {
	var code string
	if err := s.database.QueryRowContext(ctx, `SELECT code FROM shipping_providers WHERE id=?`, providerID).Scan(&code); err != nil {
		return nil, err
	}
	if code != "rajaongkir" {
		return nil, fail(404, i18n.Translate(i18n.Default, "system.endpoint_not_found"))
	}
	rows, err := s.database.QueryContext(ctx, `SELECT LOWER(code) FROM shipping_couriers WHERE is_active=1`)
	if err != nil {
		return nil, err
	}
	supported := map[string]bool{}
	for rows.Next() {
		var c string
		if err := rows.Scan(&c); err != nil {
			rows.Close()
			return nil, err
		}
		supported[c] = true
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	seen := map[string]bool{}
	normalized := []string{}
	for _, c := range couriers {
		c = strings.ToLower(strings.TrimSpace(c))
		if c == "" || seen[c] {
			continue
		}
		if !supported[c] {
			return nil, fail(422, "Kurir yang dipilih tidak didukung.")
		}
		seen[c] = true
		normalized = append(normalized, c)
	}
	var cfg map[string]any
	var raw string
	err = s.database.QueryRowContext(ctx, `SELECT config FROM agent_shipping_provider_configs WHERE agent_id=? AND shipping_provider_id=?`, agentID, providerID).Scan(&raw)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	if err == nil {
		cfg, err = cryptox.DecryptArray(s.appKey, raw)
		if err != nil {
			return nil, fail(422, "Konfigurasi terenkripsi tidak dapat dibaca.")
		}
	} else {
		cfg = map[string]any{}
	}
	cfg["couriers"] = normalized
	encrypted, err := cryptox.EncryptArray(s.appKey, cfg)
	if err != nil {
		return nil, err
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO agent_shipping_provider_configs (agent_id,shipping_provider_id,config,created_at,updated_at)
		VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE config=VALUES(config),updated_at=UTC_TIMESTAMP(6)`, agentID, providerID, encrypted)
	if err != nil {
		return nil, err
	}
	return normalized, nil
}

func (s *Service) Tariff(ctx context.Context, agentID int64) (map[string]any, error) {
	var row struct{}
	_ = row
	var price, dist, charge, freeMin sql.NullFloat64
	var free, active sql.NullBool
	err := s.database.QueryRowContext(ctx, `SELECT price_per_km,minimum_distance_km,minimum_charge,free_shipping_enabled,free_shipping_min_amount,is_active
		FROM shipping_configurations WHERE agent_id=? AND shipping_provider_id=(SELECT id FROM shipping_providers WHERE code='openroute' LIMIT 1)`, agentID).
		Scan(&price, &dist, &charge, &free, &freeMin, &active)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"price_per_km": floatOrZero(price), "minimum_distance_km": floatOrZero(dist), "minimum_charge": floatOrZero(charge),
		"free_shipping_enabled": free.Bool, "free_shipping_min_amount": nullableFloat(freeMin), "is_active": active.Bool}, nil
}

func (s *Service) SaveTariff(ctx context.Context, agentID int64, v map[string]any) (map[string]any, error) {
	price, ok1 := number(v["price_per_km"])
	dist, ok2 := number(v["minimum_distance_km"])
	if !ok1 || !ok2 || price < 0 || price > 100000000 || dist < 0 || dist > 100000 {
		return nil, fail(422, "Tarif pengiriman tidak valid.")
	}
	charge := 0.0
	var free bool
	var freeMin any
	old, _ := s.Tariff(ctx, agentID)
	if old != nil {
		charge, _ = number(old["minimum_charge"])
		free, _ = old["free_shipping_enabled"].(bool)
		freeMin = old["free_shipping_min_amount"]
	}
	if value, ok := v["minimum_charge"]; ok {
		if value == nil {
			charge = 0
		} else {
			var valid bool
			charge, valid = number(value)
			if !valid {
				return nil, fail(422, "Tarif minimum tidak valid.")
			}
		}
	}
	if charge < 0 || charge > 100000000 {
		return nil, fail(422, "Tarif minimum tidak valid.")
	}
	var anchor int64
	if err := s.database.QueryRowContext(ctx, `SELECT id FROM shipping_providers WHERE code='openroute'`).Scan(&anchor); err != nil {
		return nil, err
	}
	if val, ok := v["free_shipping_enabled"]; ok {
		var valid bool
		free, valid = val.(bool)
		if !valid {
			return nil, fail(422, "Pengaturan bebas ongkir tidak valid.")
		}
	}
	if val, ok := v["free_shipping_min_amount"]; ok {
		if val == nil {
			freeMin = nil
		} else {
			n, valid := number(val)
			if !valid || n < 0 || n > 100000000000 {
				return nil, fail(422, "Batas bebas ongkir tidak valid.")
			}
			freeMin = n
		}
	}
	_, err := s.database.ExecContext(ctx, `INSERT INTO shipping_configurations (agent_id,shipping_provider_id,price_per_km,minimum_distance_km,minimum_charge,free_shipping_enabled,free_shipping_min_amount,is_active,created_at,updated_at)
		VALUES (?,?,?,?,?,?,?,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE shipping_provider_id=VALUES(shipping_provider_id),price_per_km=VALUES(price_per_km),minimum_distance_km=VALUES(minimum_distance_km),
		minimum_charge=VALUES(minimum_charge),free_shipping_enabled=VALUES(free_shipping_enabled),free_shipping_min_amount=VALUES(free_shipping_min_amount),is_active=1,updated_at=UTC_TIMESTAMP(6)`,
		agentID, anchor, price, dist, charge, free, freeMin)
	if err != nil {
		return nil, err
	}
	return s.Tariff(ctx, agentID)
}

// WhatsAppEventKeys is the configurable transactional event allow-list, excluding account, OTP and recovery traffic.
var WhatsAppEventKeys = []string{"order_created", "payment_paid", "payment_partial", "order_processed", "order_shipped", "order_delivered", "order_cancelled", "return_requested", "return_approved", "refund_completed", "payment_proof_uploaded", "payment_failed", "return_rejected", "refund_requested", "refund_approved", "refund_rejected"}

func (s *Service) WhatsAppEvents(ctx context.Context, agentID int64) ([]map[string]any, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT event,enabled,template FROM agent_whatsapp_event_settings WHERE agent_id=?`, agentID)
	if err != nil {
		if !db.IsNoSuchTable(err) {
			return nil, err
		}
	}
	type setting struct {
		enabled  sql.NullBool
		template sql.NullString
	}
	settings := map[string]setting{}
	if rows != nil {
		for rows.Next() {
			var event string
			var v setting
			if err := rows.Scan(&event, &v.enabled, &v.template); err != nil {
				rows.Close()
				return nil, err
			}
			settings[event] = v
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
	}
	out := make([]map[string]any, 0, len(WhatsAppEventKeys))
	for _, key := range WhatsAppEventKeys {
		v := settings[key]
		enabled, source := s.globalEventEnabled(ctx, key), "default"
		if v.enabled.Valid {
			enabled, source = v.enabled.Bool, "agent"
		}
		tpl := s.globalTemplate(ctx, key)
		if tpl == nil {
			tpl = eventDefaultTemplates[key]
		}
		templateSource := "default"
		if v.template.Valid && v.template.String != "" {
			tpl = v.template.String
			templateSource = "agent"
		}
		out = append(out, map[string]any{
			"key": key, "label": eventLabels[key], "variables": eventVariableOrder[key],
			"enabled": enabled, "enabled_source": source, "template": tpl,
			"template_source": templateSource, "default_template": eventDefaultTemplates[key],
		})
	}
	return out, nil
}

func (s *Service) UpdateWhatsAppEvent(ctx context.Context, actorID, agentID int64, event string, enabled *bool, template *string) (map[string]any, error) {
	if !contains(WhatsAppEventKeys, event) {
		message := i18n.Translate(i18n.Default, "whatsapp.event_not_configurable")
		return nil, &Error{Status: 422, Message: message, Fields: map[string][]string{"event": {message}}}
	}
	if template != nil {
		if err := validateTemplate(event, *template); err != nil {
			return nil, err
		}
	}
	var oldEnabled sql.NullBool
	var oldTemplate sql.NullString
	err := s.database.QueryRowContext(ctx, `SELECT enabled,template FROM agent_whatsapp_event_settings WHERE agent_id=? AND event=?`, agentID, event).Scan(&oldEnabled, &oldTemplate)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	if enabled == nil {
		if oldEnabled.Valid {
			b := oldEnabled.Bool
			enabled = &b
		}
	}
	if template == nil {
		if oldTemplate.Valid {
			t := oldTemplate.String
			template = &t
		}
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO agent_whatsapp_event_settings (agent_id,event,enabled,template,updated_by,created_at,updated_at)
		VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),template=VALUES(template),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP(6)`,
		agentID, event, nullableBool(enabled), nullableString(template), actorID)
	if err != nil {
		return nil, err
	}
	rows, err := s.WhatsAppEvents(ctx, agentID)
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		if row["key"] == event {
			return row, nil
		}
	}
	return nil, nil
}

func (s *Service) ResetWhatsAppTemplate(ctx context.Context, actorID, agentID int64, event string) (map[string]any, error) {
	if !contains(WhatsAppEventKeys, event) {
		message := i18n.Translate(i18n.Default, "whatsapp.event_not_configurable")
		return nil, &Error{Status: 422, Message: message, Fields: map[string][]string{"event": {message}}}
	}
	var template sql.NullString
	err := s.database.QueryRowContext(ctx, `SELECT template FROM agent_whatsapp_event_settings WHERE agent_id=? AND event=?`, agentID, event).Scan(&template)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	if err == nil && template.Valid {
		if _, err := s.database.ExecContext(ctx, `UPDATE agent_whatsapp_event_settings SET template=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE agent_id=? AND event=?`, actorID, agentID, event); err != nil {
			return nil, err
		}
	}
	rows, err := s.WhatsAppEvents(ctx, agentID)
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		if row["key"] == event {
			return row, nil
		}
	}
	return nil, nil
}

func validateTemplate(event, template string) error {
	if !contains(WhatsAppEventKeys, event) {
		message := i18n.Translate(i18n.Default, "whatsapp.event_not_configurable")
		return &Error{Status: 422, Message: message, Fields: map[string][]string{"event": {message}}}
	}
	if strings.TrimSpace(template) == "" || len([]rune(template)) > 1000 {
		message := strings.ReplaceAll(i18n.Translate(i18n.Default, "whatsapp.template_length"), ":max", "1000")
		return &Error{Status: 422, Message: message, Fields: map[string][]string{"template": {message}}}
	}
	if strings.ContainsAny(template, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0c\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f") {
		message := i18n.Translate(i18n.Default, "whatsapp.template_chars")
		return &Error{Status: 422, Message: message, Fields: map[string][]string{"template": {message}}}
	}
	vars := eventVariables[event]
	stripped := tokenPattern.ReplaceAllString(template, "")
	if strings.ContainsAny(stripped, "{}") {
		message := i18n.Translate(i18n.Default, "whatsapp.template_braces")
		return &Error{Status: 422, Message: message, Fields: map[string][]string{"template": {message}}}
	}
	unknown := []string{}
	seen := map[string]bool{}
	for _, token := range tokenPattern.FindAllStringSubmatch(template, -1) {
		if !vars[token[1]] && !seen[token[1]] {
			unknown = append(unknown, token[1])
			seen[token[1]] = true
		}
	}
	if len(unknown) > 0 {
		message := i18n.Translate(i18n.Default, "whatsapp.template_unknown_vars")
		message = strings.ReplaceAll(message, ":vars", "{"+strings.Join(unknown, "}, {")+"}")
		return &Error{Status: 422, Message: message, Fields: map[string][]string{"template": {message}}}
	}
	return nil
}

type WhatsAppTestNumber struct {
	ID     int64  `json:"id"`
	Masked string `json:"masked"`
}

func (s *Service) WhatsAppTestNumbers(ctx context.Context, agentID int64) ([]WhatsAppTestNumber, error) {
	rows, err := s.database.QueryContext(ctx, `SELECT id,msisdn FROM agent_whatsapp_test_numbers WHERE agent_id=? ORDER BY id`, agentID)
	if err != nil {
		if db.IsNoSuchTable(err) {
			return []WhatsAppTestNumber{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	out := []WhatsAppTestNumber{}
	for rows.Next() {
		var n WhatsAppTestNumber
		var raw string
		if err := rows.Scan(&n.ID, &raw); err != nil {
			return nil, err
		}
		n.Masked = maskNumber(raw)
		out = append(out, n)
	}
	return out, rows.Err()
}
func (s *Service) AddWhatsAppTestNumber(ctx context.Context, actorID, agentID int64, raw string) ([]WhatsAppTestNumber, bool, error) {
	n := normalizeMSISDN(raw)
	if n == "" {
		message := i18n.Translate(i18n.Default, "whatsapp.invalid_number")
		return nil, false, &Error{Status: 422, Message: message, Fields: map[string][]string{"number": {message}}}
	}
	returnValue := []WhatsAppTestNumber{}
	created := false
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var locked int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM users WHERE id=? AND deleted_at IS NULL FOR UPDATE`, agentID).Scan(&locked); err != nil {
			return err
		}
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM agent_whatsapp_test_numbers WHERE agent_id=? AND msisdn=?)`, agentID, n).Scan(&exists); err != nil {
			return err
		}
		if exists {
			return nil
		}
		var count int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM agent_whatsapp_test_numbers WHERE agent_id=?`, agentID).Scan(&count); err != nil {
			return err
		}
		if count >= 5 {
			message := strings.ReplaceAll(i18n.Translate(i18n.Default, "whatsapp.test_numbers_limit"), ":max", "5")
			return &Error{Status: 422, Message: message, Fields: map[string][]string{"number": {message}}}
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO agent_whatsapp_test_numbers (agent_id,msisdn,registered_by,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, agentID, n, actorID)
		created = err == nil
		return err
	})
	if err != nil {
		if db.IsNoSuchTable(err) {
			return nil, false, fail(503, "Migrasi konfigurasi WhatsApp belum diterapkan.")
		}
		return nil, false, err
	}
	returnValue, err = s.WhatsAppTestNumbers(ctx, agentID)
	return returnValue, created, err
}
func (s *Service) RemoveWhatsAppTestNumber(ctx context.Context, agentID, id int64) ([]WhatsAppTestNumber, error) {
	_, err := s.database.ExecContext(ctx, `DELETE FROM agent_whatsapp_test_numbers WHERE id=? AND agent_id=?`, id, agentID)
	if err != nil {
		if db.IsNoSuchTable(err) {
			return nil, fail(503, "Migrasi konfigurasi WhatsApp belum diterapkan.")
		}
		return nil, err
	}
	return s.WhatsAppTestNumbers(ctx, agentID)
}

func (s *Service) globalEventEnabled(ctx context.Context, event string) bool {
	var value string
	err := s.database.QueryRowContext(ctx, "SELECT value FROM settings WHERE `key`=?", "wa.event."+event).Scan(&value)
	if err != nil {
		return false
	}
	return value == "1" || strings.EqualFold(value, "true") || strings.EqualFold(value, "yes") || value == "on"
}

func (s *Service) globalTemplate(ctx context.Context, event string) any {
	var value string
	if err := s.database.QueryRowContext(ctx, "SELECT value FROM settings WHERE `key`=?", "wa.template."+event).Scan(&value); err == nil && value != "" {
		return value
	}
	return nil
}

func normalizeMSISDN(raw string) string {
	var b strings.Builder
	for _, r := range strings.TrimSpace(raw) {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	n := b.String()
	if n == "" {
		return ""
	}
	if strings.HasPrefix(n, "0") {
		n = "62" + n[1:]
	} else if strings.HasPrefix(n, "8") {
		n = "62" + n
	}
	if len(n) < 9 || len(n) > 15 {
		return ""
	}
	return n
}
func maskNumber(n string) string {
	if n == "" {
		return n
	}
	if len(n) <= 5 {
		return n[:2] + "••••" + n[len(n)-3:]
	}
	return n[:2] + "••••" + n[len(n)-3:]
}

func MaskWhatsAppNumber(raw string) string { return maskNumber(normalizeMSISDN(raw)) }

func NormalizeWhatsAppNumber(raw string) string { return normalizeMSISDN(raw) }
func nullableString(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}
func nullableBool(v *bool) any {
	if v == nil {
		return nil
	}
	return *v
}
func nullableFloat(v sql.NullFloat64) any {
	if !v.Valid {
		return nil
	}
	return v.Float64
}
func floatOrZero(v sql.NullFloat64) float64 {
	if !v.Valid {
		return 0
	}
	return v.Float64
}
func number(v any) (float64, bool) {
	switch x := v.(type) {
	case float64:
		return x, !math.IsNaN(x) && !math.IsInf(x, 0)
	case int:
		return float64(x), true
	case int64:
		return float64(x), true
	case json.Number:
		f, e := x.Float64()
		return f, e == nil
	case string:
		f, e := strconv.ParseFloat(x, 64)
		return f, e == nil
	}
	return 0, false
}
func stringPtr(s string) *string { return &s }
func stringVal(m map[string]any, k string) *string {
	v, ok := m[k].(string)
	if !ok {
		return nil
	}
	return &v
}
func valueString(m map[string]any, k, def string) string {
	if v, ok := m[k].(string); ok {
		return v
	}
	return def
}
func valueStrings(v any) []string {
	switch x := v.(type) {
	case []string:
		return x
	case []any:
		out := []string{}
		for _, v := range x {
			if s, ok := v.(string); ok {
				out = append(out, s)
			}
		}
		return out
	}
	return []string{}
}
func contains(values []string, value string) bool {
	for _, v := range values {
		if v == value {
			return true
		}
	}
	return false
}
