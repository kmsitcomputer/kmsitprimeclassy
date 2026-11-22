// Package installer ports the first-run wizard (InstallController). Every write
// endpoint mirrors the reference gates: the storage lock file (the SAME marker
// Laravel writes), plus the hasSuperAdmin defence in depth.
package installer

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

// RequiredExtensions mirrors InstallController::REQUIRED_EXTENSIONS (labels only —
// a Go binary has no separate PHP extensions, so every requirement is satisfied
// by construction; the labels keep the wizard contract identical).
var RequiredExtensions = []string{
	"pdo_mysql", "mbstring", "openssl", "tokenizer", "xml", "ctype", "json", "bcmath", "fileinfo",
}

// Service is the installer domain service.
type Service struct {
	lockPath string
	envFile  string
	openDB   func(dsn string) (*sql.DB, error)
}

// New builds an installer service.
func New(lockPath, envFile string) *Service {
	return &Service{lockPath: lockPath, envFile: envFile, openDB: func(dsn string) (*sql.DB, error) {
		return sql.Open("mysql", dsn)
	}}
}

// Requirements ports InstallController::requirements.
func (s *Service) Requirements() map[string]any {
	storageOK := dirWritable(filepath.Dir(s.lockPath))
	envOK := fileWritable(s.envFile)
	// 'bootstrap/cache/' is a Laravel-framework requirement that does not apply to a
	// Go binary — vacuously satisfied.
	extensions := make([]map[string]any, 0, len(RequiredExtensions))
	allExt := true
	for _, e := range RequiredExtensions {
		extensions = append(extensions, map[string]any{"label": e, "ok": true})
	}
	_ = allExt
	permissions := []map[string]any{
		{"label": "storage/", "ok": storageOK},
		{"label": "bootstrap/cache/", "ok": true},
		{"label": ".env", "ok": envOK},
	}
	allPerms := true
	for _, p := range permissions {
		if !p["ok"].(bool) {
			allPerms = false
		}
	}
	return map[string]any{
		"php":         map[string]any{"version": runtime.Version(), "ok": true, "minimum": "8.2.0"},
		"extensions":  extensions,
		"permissions": permissions,
		"all_ok":      storageOK && envOK && allPerms,
	}
}

func dirWritable(dir string) bool {
	if dir == "" {
		return false
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return false
	}
	probe := filepath.Join(dir, ".write-probe")
	if err := os.WriteFile(probe, []byte("x"), 0o644); err != nil {
		return false
	}
	_ = os.Remove(probe)
	return true
}

func fileWritable(path string) bool {
	if path == "" {
		return false
	}
	if _, err := os.Stat(path); err != nil {
		// Not existing yet: writable when the parent is.
		return dirWritable(filepath.Dir(path))
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_APPEND, 0o644)
	if err != nil {
		return false
	}
	f.Close()
	return true
}

// HasSuperAdmin reports whether a super_admin user exists (the defence in depth).
func HasSuperAdmin(ctx context.Context, d *sql.DB) bool {
	var has bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u
		JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin')`).Scan(&has); err != nil {
		return false
	}
	return has
}

// HasTables ports SafeSchema::hasTable('roles').
func HasTables(ctx context.Context, d *sql.DB) bool {
	var has bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM information_schema.tables
		WHERE table_schema = DATABASE() AND table_name = 'roles')`).Scan(&has); err != nil {
		return false
	}
	return has
}

// IsInstalled is the InstallLock file check.
func (s *Service) IsInstalled() bool {
	if s.lockPath == "" {
		return false
	}
	_, err := os.Stat(s.lockPath)
	return err == nil
}

// MarkInstalled writes the lock file.
func (s *Service) MarkInstalled() error {
	if err := os.MkdirAll(filepath.Dir(s.lockPath), 0o755); err != nil {
		return err
	}
	return os.WriteFile(s.lockPath, []byte(nowISO()), 0o644)
}

// TestConnection ports attemptConnection: generic failure reasons only — the
// driver message (which can echo the DSN) is never returned verbatim.
func (s *Service) TestConnection(host string, port int64, database, username, password string) error {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?charset=utf8mb4&timeout=5s",
		username, password, host, port, database)
	db, err := s.openDB(dsn)
	if err != nil {
		return errors.New("Tidak dapat terhubung ke database. Periksa kembali host, port, username, dan password.")
	}
	defer db.Close()
	if err := db.Ping(); err != nil {
		var me *mysql.MySQLError
		if errors.As(err, &me) && me.Number == 1049 {
			return errors.New("Database tidak ditemukan. Pastikan nama database sudah benar dan sudah dibuat di server.")
		}
		return errors.New("Tidak dapat terhubung ke database. Periksa kembali host, port, username, dan password.")
	}
	return nil
}

var envKeyRe = regexp.MustCompile(`(?m)^\s*(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|APP_NAME|APP_URL|FRONTEND_URLS|SANCTUM_STATEFUL_DOMAINS)\s*=.*$`)

// SaveDatabaseConfig ports saveDatabaseConfig: verify the connection, then persist
// the DB_* block to the .env file (EnvFileWriter::set semantics).
func (s *Service) SaveDatabaseConfig(host string, port int64, database, username, password string) error {
	if err := s.TestConnection(host, port, database, username, password); err != nil {
		return err
	}
	return s.envSet(map[string]string{
		"DB_CONNECTION": "mysql",
		"DB_HOST":       host,
		"DB_PORT":       fmt.Sprintf("%d", port),
		"DB_DATABASE":   database,
		"DB_USERNAME":   username,
		"DB_PASSWORD":   password,
	})
}

// ConfigureApp ports configureApp (stateful domain derivation for Sanctum).
func (s *Service) ConfigureApp(appName, appURL, frontendURL string) error {
	frontendURL = strings.TrimRight(frontendURL, "/")
	u, err := url.Parse(frontendURL)
	if err != nil || u.Host == "" {
		return errors.New("Data yang dikirim tidak valid.")
	}
	stateful := u.Host
	if p := u.Port(); p != "" {
		stateful = fmt.Sprintf("%s:%s", u.Hostname(), p)
	}
	return s.envSet(map[string]string{
		"APP_NAME":                 appName,
		"APP_URL":                  strings.TrimRight(appURL, "/"),
		"FRONTEND_URLS":            frontendURL,
		"SANCTUM_STATEFUL_DOMAINS": stateful,
	})
}

// envSet upserts KEY=VALUE lines in the .env file (EnvFileWriter::set semantics:
// replace in place when the key exists, append otherwise).
func (s *Service) envSet(values map[string]string) error {
	content := ""
	if b, err := os.ReadFile(s.envFile); err == nil {
		content = string(b)
	}
	lines := strings.Split(content, "\n")
	for key, val := range values {
		re := regexp.MustCompile(`(?m)^\s*` + regexp.QuoteMeta(key) + `\s*=.*$`)
		if re.MatchString(content) {
			for i, line := range lines {
				if re.MatchString(line) {
					lines[i] = key + "=" + val
				}
			}
		} else {
			lines = append(lines, key+"="+val)
		}
	}
	out := strings.Join(lines, "\n")
	return os.WriteFile(s.envFile, []byte(out), 0o644)
}

// Finalize ports finalize: pure summary/verification step.
func (s *Service) Finalize(ctx context.Context, d *sql.DB) map[string]any {
	return map[string]any{
		"has_tables":      HasTables(ctx, d),
		"has_super_admin": HasSuperAdmin(ctx, d),
		"storage_linked":  storageLinked(s.lockPath),
	}
}

func storageLinked(lockPath string) bool {
	// The public/storage symlink is a Laravel artifact; in the Go deployment media
	// is served by the Go binary itself, so this is vacuously satisfied whenever
	// the storage directory exists.
	if lockPath == "" {
		return false
	}
	_, err := os.Stat(filepath.Dir(lockPath))
	return err == nil
}

func nowISO() string { return time.Now().UTC().Format("2006-01-02T15:04:05+07:00") }
