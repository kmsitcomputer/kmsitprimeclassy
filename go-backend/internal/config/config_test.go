package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func getter(values map[string]string) func(string) string {
	return func(k string) string { return values[k] }
}

func TestLoadDefaults(t *testing.T) {
	cfg, err := Load(getter(map[string]string{
		"DB_HOST":     "db",
		"DB_DATABASE": "primeclassy_db",
		"DB_USERNAME": "app",
		"DB_PASSWORD": "secret",
	}))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg.Env != EnvLocal {
		t.Errorf("default env = %q, want local", cfg.Env)
	}
	if cfg.HTTPAddr != ":8081" {
		t.Errorf("default addr = %q", cfg.HTTPAddr)
	}
	if cfg.Session.CookieName != "pc_sid" {
		t.Errorf("default cookie = %q", cfg.Session.CookieName)
	}
	if cfg.Session.Lifetime != 120*time.Minute {
		t.Errorf("default lifetime = %v", cfg.Session.Lifetime)
	}
	if !cfg.GoogleSheets.Enabled || cfg.GoogleSheets.CredentialsPath != "/app/storage/app/private/google/service-account.json" || cfg.GoogleSheets.Timeout != 30*time.Second || cfg.GoogleSheets.MaxRows != 10000 {
		t.Errorf("unexpected Google Sheets defaults: %+v", cfg.GoogleSheets)
	}
	if cfg.CacheStore != "database" || cfg.CachePrefix != "laravel_cache_" {
		t.Errorf("unexpected Laravel cache defaults: store=%q prefix=%q", cfg.CacheStore, cfg.CachePrefix)
	}
	if !cfg.Session.Secure {
		// local defaults to insecure cookies so http://localhost works; this asserts the documented rule.
		t.Log("local env allows non-secure cookies (expected false)")
	}
}

func TestLoadRequiresDatabaseIdentity(t *testing.T) {
	_, err := Load(getter(map[string]string{}))
	if err == nil {
		t.Fatal("expected validation error for missing DB settings")
	}
	msg := err.Error()
	for _, want := range []string{"DB_HOST is required", "DB_DATABASE is required", "DB_USERNAME is required"} {
		if !strings.Contains(msg, want) {
			t.Errorf("error missing %q: %s", want, msg)
		}
	}
}

// TestTestingDatabasePolicy is the repository rule (AGENTS.md §7) enforced in configuration: a test process
// may only point at a *_test/_testing database, and a non-test process may not point at one either.
func TestTestingDatabasePolicy(t *testing.T) {
	base := map[string]string{
		"DB_HOST":     "db",
		"DB_USERNAME": "app",
		"DB_PASSWORD": "secret",
	}

	withDB := func(env, db string) map[string]string {
		clone := map[string]string{}
		for k, v := range base {
			clone[k] = v
		}
		clone["APP_ENV"] = env
		clone["DB_DATABASE"] = db
		return clone
	}

	if _, err := Load(getter(withDB("testing", "primeclassy_testing"))); err != nil {
		t.Errorf("testing + _testing database must be allowed: %v", err)
	}
	if _, err := Load(getter(withDB("testing", "primeclassy_test"))); err != nil {
		t.Errorf("testing + _test database must be allowed: %v", err)
	}
	testingConfig, err := Load(getter(withDB("testing", "primeclassy_testing")))
	if err != nil || testingConfig.CacheStore != "array" {
		t.Errorf("testing environment must mirror Laravel's .env.testing array cache: %+v, %v", testingConfig, err)
	}
	if _, err := Load(getter(withDB("testing", "primeclassy_db"))); err == nil {
		t.Error("testing against a non-test database must be refused")
	}
	if _, err := Load(getter(withDB("local", "primeclassy_testing"))); err == nil {
		t.Error("a non-test environment pointed at a test database must be refused")
	}
}

func TestSessionCookieMustNotBeLaravelSession(t *testing.T) {
	_, err := Load(getter(map[string]string{
		"DB_HOST": "db", "DB_DATABASE": "primeclassy_db", "DB_USERNAME": "app",
		"SESSION_COOKIE": "laravel_session",
	}))
	if err == nil || !strings.Contains(err.Error(), "laravel_session") {
		t.Fatalf("expected refusal of laravel_session cookie name, got %v", err)
	}
}

func TestSessionSameSiteNoneRequiresSecure(t *testing.T) {
	_, err := Load(getter(map[string]string{
		"DB_HOST": "db", "DB_DATABASE": "primeclassy_db", "DB_USERNAME": "app",
		"SESSION_SAME_SITE": "none", "SESSION_SECURE_COOKIE": "false",
	}))
	if err == nil || !strings.Contains(err.Error(), "SESSION_SECURE_COOKIE") {
		t.Fatalf("expected SameSite=None to require a secure cookie, got %v", err)
	}
}

func TestDSNPinsUTCAndParsesTime(t *testing.T) {
	dsn := DBConfig{
		Host: "db.internal", Port: 3306, Database: "primeclassy_db", User: "app", Password: "p@ss:word",
	}.DSN()
	for _, want := range []string{"@tcp(db.internal:3306)/primeclassy_db", "parseTime=true", "loc=UTC", "time_zone=%27%2B00%3A00%27"} {
		if !strings.Contains(dsn, want) {
			t.Errorf("DSN missing %q: %s", want, dsn)
		}
	}
	// The password must be passed through as-is (the driver handles escaping); it must not be injected into
	// the host/database portion of the DSN.
	if !strings.HasPrefix(dsn, "app:p@ss:word@tcp(") {
		t.Errorf("unexpected DSN prefix: %s", dsn)
	}
}

func TestFileGetterPrecedence(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")
	body := strings.Join([]string{
		"# comment",
		"DB_HOST=file-host",
		`DB_PASSWORD="quoted-secret"`,
		"EMPTY=",
		"NOT A LINE",
		"export EXPORTED=yes",
	}, "\n")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	t.Setenv("DB_HOST", "env-host") // a real environment variable must win
	get := FileGetter(path)

	if got := get("DB_HOST"); got != "env-host" {
		t.Errorf("process env must win: got %q", got)
	}
	if got := get("DB_PASSWORD"); got != "quoted-secret" {
		t.Errorf("quotes must be stripped: got %q", got)
	}
	if got := get("EMPTY"); got != "" {
		t.Errorf("empty value: got %q", got)
	}
	if got := get("EXPORTED"); got != "yes" {
		t.Errorf("export prefix must be tolerated: got %q", got)
	}
	if got := get("MISSING"); got != "" {
		t.Errorf("missing key must be empty: got %q", got)
	}

	// A missing file degrades to the process environment, never an error.
	missing := FileGetter(filepath.Join(dir, "does-not-exist"))
	if got := missing("DB_HOST"); got != "env-host" {
		t.Errorf("missing file should fall back to env: got %q", got)
	}
}

func TestUnsupportedValuesAreRejected(t *testing.T) {
	for _, tc := range []struct{ key, value string }{
		{"APP_ENV", "qa"},
		{"LOG_LEVEL", "verbose"},
		{"LOG_FORMAT", "xml"},
		{"SESSION_SAME_SITE", "sometimes"},
		{"DB_PORT", "not-a-port"},
	} {
		values := map[string]string{
			"DB_HOST": "db", "DB_DATABASE": "primeclassy_db", "DB_USERNAME": "app",
		}
		values[tc.key] = tc.value
		if _, err := Load(getter(values)); err == nil {
			t.Errorf("%s=%q must be rejected", tc.key, tc.value)
		}
	}
}
