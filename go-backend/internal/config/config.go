// Package config loads and validates the process configuration from the environment.
//
// Rules that matter for a migration:
//   - Validation happens once, at boot, and the process refuses to start on an invalid configuration
//     instead of failing on the first request.
//   - Nothing here reads production secrets from files that are not explicitly mounted; the values come
//     from the environment exactly like the Laravel `.env` does, so the same runbook applies.
//   - The database name is validated against the repository testing policy: a process that is not running
//     as a test may never point at a database whose name is not the configured application database.
package config

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

// Environment values mirror the Laravel deployment environments so the runbook and the .env keys stay
// recognisable. `testing` is the only environment allowed to point at a *_test/_testing database.
type Environment string

const (
	EnvLocal   Environment = "local"
	EnvTesting Environment = "testing"
	EnvStaging Environment = "staging"
	EnvProd    Environment = "production"
)

// Config is the fully validated process configuration.
type Config struct {
	Env      Environment
	HTTPAddr string
	// PublicURL is the browser-facing origin (Laravel: APP_URL). Used to build absolute asset URLs.
	PublicURL string
	// MediaRoot is the `public` disk root (Laravel: storage/app/public via MEDIA_DISK=public). Empty
	// leaves the internal/media package default, which is the relative path the container layout uses.
	MediaRoot string
	// StorageRoot is the private local disk root (Laravel: storage/app). Consent evidence
	// lands under consent/evidence relative to it.
	StorageRoot string

	// AppKey is Laravel's APP_KEY. The Go service needs it ONLY to READ values Laravel encrypted (the
	// agent shipping-provider credentials). It is never used to encrypt anything Go owns.
	AppKey string

	DB           DBConfig
	Session      SessionConfig
	HTTP         HTTPConfig
	Log          LogConfig
	Shipping     ShippingConfig
	GoogleSheets GoogleSheetsConfig
	CacheStore   string
	CachePrefix  string
}

// GoogleSheetsConfig mirrors backend/config/google_sheets.php.
type GoogleSheetsConfig struct {
	Enabled         bool
	CredentialsPath string
	Timeout         time.Duration
	MaxRows         int
}

// DBConfig describes the MySQL/MariaDB connection.
type DBConfig struct {
	Host     string
	Port     int
	Database string
	User     string
	Password string
	// MaxOpenConns/MaxIdleConns bound the pool so a runaway client cannot exhaust MariaDB connections.
	MaxOpenConns int
	MaxIdleConns int
	ConnMaxLife  time.Duration
}

// DSN renders the driver DSN. parseTime is required so DATETIME/TIMESTAMP scan into time.Time; the
// connection session time zone is pinned to UTC because the Laravel app stores UTC (APP_TIMEZONE=UTC)
// and every report window is computed in Asia/Jakarta explicitly on top of it.
func (d DBConfig) DSN() string {
	// The parameter string is a constant (no format verbs) so the URL-escaped time zone cannot be mangled.
	const params = "parseTime=true&loc=UTC&time_zone=%27%2B00%3A00%27&charset=utf8mb4&collation=utf8mb4_unicode_ci"
	return fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?%s", d.User, d.Password, d.Host, d.Port, d.Database, params)
}

// ShippingConfig carries the outbound shipping-provider settings that were env-driven in Laravel
// (config/services.php). The per-Agent credentials themselves live encrypted in the database.
type ShippingConfig struct {
	// OpenRouteBaseURL/Profile mirror services.openroute. The API key comes from the per-Agent config row.
	OpenRouteBaseURL string
	OpenRouteProfile string
	// OpenRouteKey is the optional deployment-wide ORS key (services.openroute.key); per-Agent config wins.
	OpenRouteKey string
	// KmsitTrustedHosts is the operator allow-list for private/plain-http routing endpoints (SSRF policy).
	KmsitTrustedHosts []string
	// RajaOngkirBaseURL mirrors the provider's hard-coded base URL. It is configurable purely so tests can
	// point it at a stub; the default is byte-identical to the reference.
	RajaOngkirBaseURL string
}

// SessionConfig describes the cookie/session behaviour the SPA depends on.
type SessionConfig struct {
	// CookieName is deliberately NOT `laravel_session`: Go sessions are stored by Go and are not
	// Laravel-serialised, so sharing one cookie name would let one runtime invalidate the other's
	// session. The browser-facing contract (HttpOnly session cookie + readable XSRF-TOKEN cookie) is
	// unchanged; only the internal cookie name differs. See docs/21 §Session interoperability.
	CookieName string
	Lifetime   time.Duration
	Secure     bool
	SameSite   string
	Path       string
	// StatefulDomains mirrors SANCTUM_STATEFUL_DOMAINS: only these origins are allowed to make
	// credentialed cross-site requests, and only these are echoed back in CORS.
	StatefulDomains []string
}

// HTTPConfig holds server timeouts and the request-size ceiling.
type HTTPConfig struct {
	ReadHeaderTimeout time.Duration
	ReadTimeout       time.Duration
	WriteTimeout      time.Duration
	IdleTimeout       time.Duration
	MaxBodyBytes      int64
	// TrustProxyHeaders enables X-Forwarded-For parsing (the single-domain layout puts a reverse proxy
	// in front of both runtimes).
	TrustProxyHeaders bool
}

// LogConfig selects the structured log format.
type LogConfig struct {
	Level  string
	Format string // json|text
	Pretty bool
}

// Load reads the environment and validates it.
func Load(getenv func(string) string) (Config, error) {
	if getenv == nil {
		getenv = os.Getenv
	}
	var errs []string
	add := func(format string, args ...any) { errs = append(errs, fmt.Sprintf(format, args...)) }

	env := Environment(strings.ToLower(strings.TrimSpace(defaultStr(getenv("APP_ENV"), string(EnvLocal)))))
	switch env {
	case EnvLocal, EnvTesting, EnvStaging, EnvProd:
	default:
		add("APP_ENV must be one of local|testing|staging|production, got %q", env)
	}

	port, err := strconv.Atoi(defaultStr(getenv("DB_PORT"), "3306"))
	if err != nil || port <= 0 || port > 65535 {
		add("DB_PORT must be a valid TCP port, got %q", getenv("DB_PORT"))
	}

	db := DBConfig{
		Host:         strings.TrimSpace(getenv("DB_HOST")),
		Port:         port,
		Database:     strings.TrimSpace(getenv("DB_DATABASE")),
		User:         strings.TrimSpace(getenv("DB_USERNAME")),
		Password:     getenv("DB_PASSWORD"),
		MaxOpenConns: defaultInt(getenv("DB_MAX_OPEN_CONNS"), 25),
		MaxIdleConns: defaultInt(getenv("DB_MAX_IDLE_CONNS"), 10),
		ConnMaxLife:  defaultDuration(getenv("DB_CONN_MAX_LIFETIME"), 5*time.Minute),
	}
	if db.Host == "" {
		add("DB_HOST is required")
	}
	if db.Database == "" {
		add("DB_DATABASE is required")
	}
	if db.User == "" {
		add("DB_USERNAME is required")
	}
	if db.MaxOpenConns < 1 {
		add("DB_MAX_OPEN_CONNS must be >= 1")
	}
	if db.MaxIdleConns < 0 || db.MaxIdleConns > db.MaxOpenConns {
		add("DB_MAX_IDLE_CONNS must be between 0 and DB_MAX_OPEN_CONNS")
	}

	// Repository testing policy (AGENTS.md §7): a test process may only ever touch a database whose
	// name contains _test/_testing. Enforced here so no test can be pointed at DEV or production data.
	if env == EnvTesting && !IsTestingDatabase(db.Database) {
		add("APP_ENV=testing requires DB_DATABASE to contain _test/_testing, got %q", db.Database)
	}
	// The inverse is just as dangerous: a non-test process silently pointed at a scratch/test database
	// would look healthy while serving fixtures.
	if env != EnvTesting && IsTestingDatabase(db.Database) {
		add("APP_ENV=%s may not use test database %q", env, db.Database)
	}

	sess := SessionConfig{
		CookieName:      defaultStr(getenv("SESSION_COOKIE"), "pc_sid"),
		Lifetime:        defaultDurationMinutes(getenv("SESSION_LIFETIME"), 120),
		Secure:          defaultBool(getenv("SESSION_SECURE_COOKIE"), env != EnvLocal && env != EnvTesting),
		SameSite:        strings.ToLower(defaultStr(getenv("SESSION_SAME_SITE"), "lax")),
		Path:            defaultStr(getenv("SESSION_PATH"), "/"),
		StatefulDomains: splitList(getenv("SANCTUM_STATEFUL_DOMAINS")),
	}
	switch sess.SameSite {
	case "lax", "strict", "none":
	default:
		add("SESSION_SAME_SITE must be lax|strict|none, got %q", sess.SameSite)
	}
	if sess.SameSite == "none" && !sess.Secure {
		add("SESSION_SAME_SITE=none requires SESSION_SECURE_COOKIE=true")
	}
	if sess.CookieName == "laravel_session" {
		add("SESSION_COOKIE must not be laravel_session: Go and Laravel sessions are not interchangeable")
	}

	httpCfg := HTTPConfig{
		ReadHeaderTimeout: defaultDuration(getenv("HTTP_READ_HEADER_TIMEOUT"), 10*time.Second),
		ReadTimeout:       defaultDuration(getenv("HTTP_READ_TIMEOUT"), 30*time.Second),
		WriteTimeout:      defaultDuration(getenv("HTTP_WRITE_TIMEOUT"), 60*time.Second),
		IdleTimeout:       defaultDuration(getenv("HTTP_IDLE_TIMEOUT"), 120*time.Second),
		MaxBodyBytes:      int64(defaultInt(getenv("HTTP_MAX_BODY_BYTES"), 1<<20)),
		TrustProxyHeaders: defaultBool(getenv("TRUST_PROXY_HEADERS"), true),
	}

	shippingCfg := ShippingConfig{
		OpenRouteBaseURL:  strings.TrimRight(defaultStr(getenv("OPENROUTE_BASE_URL"), "https://api.openrouteservice.org"), "/"),
		OpenRouteProfile:  defaultStr(getenv("OPENROUTE_PROFILE"), "driving-car"),
		OpenRouteKey:      strings.TrimSpace(getenv("OPENROUTE_API_KEY")),
		KmsitTrustedHosts: splitList(getenv("KMSIT_TRUSTED_HOSTS")),
		RajaOngkirBaseURL: strings.TrimRight(defaultStr(getenv("RAJAONGKIR_BASE_URL"), "https://rajaongkir.komerce.id/api/v1"), "/"),
	}
	googleSheetsCfg := GoogleSheetsConfig{
		Enabled:         defaultBool(getenv("GOOGLE_SHEETS_ENABLED"), true),
		CredentialsPath: defaultStr(getenv("GOOGLE_SHEETS_CREDENTIALS_PATH"), "/app/storage/app/private/google/service-account.json"),
		Timeout:         defaultDuration(getenv("GOOGLE_SHEETS_TIMEOUT"), 30*time.Second),
		MaxRows:         defaultInt(getenv("GOOGLE_SHEETS_MAX_ROWS"), 10000),
	}
	cacheStore := strings.TrimSpace(getenv("CACHE_STORE"))
	if cacheStore == "" {
		cacheStore = "database"
		if env == EnvTesting {
			cacheStore = "array"
		}
	}
	cachePrefix := strings.TrimSpace(getenv("CACHE_PREFIX"))
	if cachePrefix == "" {
		appName := defaultStr(getenv("APP_NAME"), "Laravel")
		cachePrefix = laravelCachePrefix(appName)
	}

	logCfg := LogConfig{
		Level:  strings.ToLower(defaultStr(getenv("LOG_LEVEL"), "info")),
		Format: strings.ToLower(defaultStr(getenv("LOG_FORMAT"), "json")),
	}
	switch logCfg.Level {
	case "debug", "info", "warn", "error":
	default:
		add("LOG_LEVEL must be debug|info|warn|error, got %q", logCfg.Level)
	}
	switch logCfg.Format {
	case "json", "text":
	default:
		add("LOG_FORMAT must be json|text, got %q", logCfg.Format)
	}

	cfg := Config{
		Env:          env,
		AppKey:       getenv("APP_KEY"),
		HTTPAddr:     defaultStr(getenv("HTTP_ADDR"), ":8081"),
		PublicURL:    strings.TrimRight(strings.TrimSpace(getenv("APP_URL")), "/"),
		MediaRoot:    strings.TrimSpace(getenv("MEDIA_ROOT")),
		StorageRoot:  strings.TrimSpace(getenv("STORAGE_ROOT")),
		DB:           db,
		Session:      sess,
		HTTP:         httpCfg,
		Log:          logCfg,
		Shipping:     shippingCfg,
		GoogleSheets: googleSheetsCfg,
		CacheStore:   cacheStore,
		CachePrefix:  cachePrefix,
	}

	if len(errs) > 0 {
		return Config{}, errors.New("invalid configuration:\n  - " + strings.Join(errs, "\n  - "))
	}
	return cfg, nil
}

func laravelCachePrefix(appName string) string {
	var b strings.Builder
	underscore := false
	for _, r := range strings.ToLower(appName) {
		if r >= 'a' && r <= 'z' || r >= '0' && r <= '9' {
			b.WriteRune(r)
			underscore = false
		} else if b.Len() > 0 && !underscore {
			b.WriteByte('_')
			underscore = true
		}
	}
	return strings.Trim(b.String(), "_") + "_cache_"
}

// IsTestingDatabase implements the repository rule: only names containing _test or _testing.
func IsTestingDatabase(name string) bool {
	n := strings.ToLower(name)
	return strings.Contains(n, "_test") || strings.Contains(n, "_testing")
}

func defaultStr(v, def string) string {
	if strings.TrimSpace(v) == "" {
		return def
	}
	return v
}

func defaultInt(v string, def int) int {
	if strings.TrimSpace(v) == "" {
		return def
	}
	n, err := strconv.Atoi(strings.TrimSpace(v))
	if err != nil {
		return def
	}
	return n
}

func defaultBool(v string, def bool) bool {
	if strings.TrimSpace(v) == "" {
		return def
	}
	b, err := strconv.ParseBool(strings.TrimSpace(v))
	if err != nil {
		return def
	}
	return b
}

func defaultDuration(v string, def time.Duration) time.Duration {
	if strings.TrimSpace(v) == "" {
		return def
	}
	d, err := time.ParseDuration(strings.TrimSpace(v))
	if err != nil {
		return def
	}
	return d
}

func defaultDurationMinutes(v string, defMinutes int) time.Duration {
	n := defaultInt(v, defMinutes)
	if n <= 0 {
		n = defMinutes
	}
	return time.Duration(n) * time.Minute
}

func splitList(v string) []string {
	var out []string
	for _, part := range strings.Split(v, ",") {
		p := strings.ToLower(strings.TrimSpace(part))
		if p != "" {
			out = append(out, p)
		}
	}
	return out
}
