package auth

import (
	"context"
	"crypto/rand"
	"crypto/subtle"
	"database/sql"
	"encoding/hex"
	"errors"
	"math/big"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// CSRFCookieName and CSRFHeaderName are part of the browser contract: axios reads the cookie named
// XSRF-TOKEN and echoes it in X-XSRF-TOKEN. They must not change, or every SPA mutation breaks with 419.
const (
	CSRFCookieName = "XSRF-TOKEN"
	CSRFHeaderName = "X-XSRF-TOKEN"
	// CSRFHeaderAlt is Laravel's second accepted header (X-CSRF-TOKEN, un-encoded).
	CSRFHeaderAlt = "X-CSRF-TOKEN"
)

// ErrCSRFMismatch is returned when a state-changing request has no valid CSRF token.
var ErrCSRFMismatch = errors.New("csrf token mismatch")

// Session is one authenticated-or-anonymous browser session row.
type Session struct {
	ID           string
	UserID       *int64
	CSRFToken    string
	IPAddress    string
	UserAgent    string
	LastActivity time.Time
	ExpiresAt    time.Time
}

// Anonymous reports whether the session has no authenticated user.
func (s *Session) Anonymous() bool { return s == nil || s.UserID == nil }

// Manager owns session persistence and the two cookies.
type Manager struct {
	database *db.DB
	cfg      config.SessionConfig
	// now is injectable so tests can control expiry without sleeping.
	now func() time.Time
}

// NewManager builds a session manager.
func NewManager(database *db.DB, cfg config.SessionConfig) *Manager {
	return &Manager{database: database, cfg: cfg, now: time.Now}
}

// CookieName is the session cookie name (never `laravel_session`; see config validation).
func (m *Manager) CookieName() string { return m.cfg.CookieName }

func (m *Manager) sameSite() http.SameSite {
	switch m.cfg.SameSite {
	case "strict":
		return http.SameSiteStrictMode
	case "none":
		return http.SameSiteNoneMode
	default:
		return http.SameSiteLaxMode
	}
}

func randomToken(bytes int) (string, error) {
	buf := make([]byte, bytes)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(buf), nil
}

// w3cDatetime formats a timestamp the way Laravel does in activity_logs/session columns is not needed;
// this helper exists only for tests that need a stable textual form.
func w3cDatetime(t time.Time) string { return t.UTC().Format(time.RFC3339) }

// Load reads the session referenced by the request cookie. It returns (nil, nil) when there is no cookie
// or the row is gone/expired — callers treat that as "anonymous", never as an error.
func (m *Manager) Load(ctx context.Context, r *http.Request) (*Session, error) {
	cookie, err := r.Cookie(m.cfg.CookieName)
	if err != nil || strings.TrimSpace(cookie.Value) == "" {
		return nil, nil
	}
	return m.loadByID(ctx, cookie.Value)
}

func (m *Manager) loadByID(ctx context.Context, id string) (*Session, error) {
	var (
		s       Session
		userID  sql.NullInt64
		ip, ua  sql.NullString
		lastAct int64
		expires int64
	)
	err := m.database.QueryRowContext(ctx, `SELECT id, user_id, csrf_token, ip_address, user_agent,
			last_activity, expires_at FROM pc_go_sessions WHERE id = ?`, id).
		Scan(&s.ID, &userID, &s.CSRFToken, &ip, &ua, &lastAct, &expires)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		if db.IsMissingSchema(err) {
			// The Go schema has not been provisioned yet: behave like "no session" rather than 500.
			return nil, nil
		}
		return nil, err
	}
	if expires <= m.now().Unix() {
		// Expired: delete lazily and report anonymous.
		_, _ = m.database.ExecContext(ctx, `DELETE FROM pc_go_sessions WHERE id = ?`, id)
		return nil, nil
	}
	s.UserID = db.NullInt64(userID)
	s.IPAddress, _ = db.NullString(ip)
	s.UserAgent, _ = db.NullString(ua)
	s.LastActivity = time.Unix(lastAct, 0).UTC()
	s.ExpiresAt = time.Unix(expires, 0).UTC()
	return &s, nil
}

// Create starts a new anonymous session.
func (m *Manager) Create(ctx context.Context, r *http.Request, userID *int64) (*Session, error) {
	if err := m.gc(ctx); err != nil {
		// Garbage collection is opportunistic; a failure must not block login.
		_ = err
	}
	id, err := randomToken(32)
	if err != nil {
		return nil, err
	}
	csrf, err := randomToken(32)
	if err != nil {
		return nil, err
	}
	now := m.now().UTC()
	s := &Session{
		ID:           id,
		UserID:       userID,
		CSRFToken:    csrf,
		IPAddress:    clientIP(r),
		UserAgent:    truncate(r.UserAgent(), 255),
		LastActivity: now,
		ExpiresAt:    now.Add(m.cfg.Lifetime),
	}
	_, err = m.database.ExecContext(ctx, `INSERT INTO pc_go_sessions
		(id, user_id, csrf_token, ip_address, user_agent, last_activity, expires_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		s.ID, nullable(userID), s.CSRFToken, nullableStr(s.IPAddress), nullableStr(s.UserAgent),
		s.LastActivity.Unix(), s.ExpiresAt.Unix())
	if err != nil {
		return nil, err
	}
	return s, nil
}

// Rotate issues a fresh session id (and CSRF token) for an existing session. Called on login and logout —
// session fixation defence, exactly like Laravel's $request->session()->regenerate().
func (m *Manager) Rotate(ctx context.Context, old *Session, userID *int64) (*Session, error) {
	if old != nil {
		_, _ = m.database.ExecContext(ctx, `DELETE FROM pc_go_sessions WHERE id = ?`, old.ID)
	}
	return m.Create(ctx, requestFromSession(old), userID)
}

// AttachUser binds an existing session to a user (used by login after credential verification).
func (m *Manager) AttachUser(ctx context.Context, s *Session, userID int64) error {
	now := m.now().UTC()
	_, err := m.database.ExecContext(ctx, `UPDATE pc_go_sessions
		SET user_id = ?, last_activity = ?, expires_at = ? WHERE id = ?`,
		userID, now.Unix(), now.Add(m.cfg.Lifetime).Unix(), s.ID)
	if err != nil {
		return err
	}
	s.UserID = &userID
	s.LastActivity = now
	s.ExpiresAt = now.Add(m.cfg.Lifetime)
	return nil
}

// Touch extends the session and records activity (Laravel's session write on every request).
func (m *Manager) Touch(ctx context.Context, s *Session) error {
	now := m.now().UTC()
	// Only write when it would actually change something; avoids a write amplification on every request.
	if now.Sub(s.LastActivity) < 60*time.Second && s.ExpiresAt.After(now.Add(m.cfg.Lifetime-time.Minute)) {
		return nil
	}
	_, err := m.database.ExecContext(ctx, `UPDATE pc_go_sessions
		SET last_activity = ?, expires_at = ? WHERE id = ?`,
		now.Unix(), now.Add(m.cfg.Lifetime).Unix(), s.ID)
	if err != nil {
		return err
	}
	s.LastActivity = now
	s.ExpiresAt = now.Add(m.cfg.Lifetime)
	return nil
}

// Destroy removes the session row (logout).
func (m *Manager) Destroy(ctx context.Context, s *Session) error {
	if s == nil {
		return nil
	}
	_, err := m.database.ExecContext(ctx, `DELETE FROM pc_go_sessions WHERE id = ?`, s.ID)
	return err
}

// WriteCookies sets the session cookie (HttpOnly) and the readable XSRF-TOKEN cookie.
//
// The CSRF cookie value is the token itself, URL-escaped — the same encoding Laravel/Sanctum produce for
// a token (hex, so escaping is a no-op) and what axios sends back in the header. Laravel additionally
// encrypts this cookie; Go does not need to, because the value is only ever compared to the token stored
// server-side for this session id (double-submit + SameSite). Documented in docs/21 §Session.
func (m *Manager) WriteCookies(w http.ResponseWriter, s *Session) {
	maxAge := int(time.Until(s.ExpiresAt).Seconds())
	if maxAge < 1 {
		maxAge = int(m.cfg.Lifetime.Seconds())
	}
	http.SetCookie(w, &http.Cookie{
		Name:     m.cfg.CookieName,
		Value:    s.ID,
		Path:     m.cfg.Path,
		MaxAge:   maxAge,
		HttpOnly: true,
		Secure:   m.cfg.Secure,
		SameSite: m.sameSite(),
	})
	http.SetCookie(w, &http.Cookie{
		Name:     CSRFCookieName,
		Value:    url.QueryEscape(s.CSRFToken),
		Path:     m.cfg.Path,
		MaxAge:   maxAge,
		HttpOnly: false, // the browser must be able to read it
		Secure:   m.cfg.Secure,
		SameSite: m.sameSite(),
	})
}

// ClearCookies expires both cookies (logout).
func (m *Manager) ClearCookies(w http.ResponseWriter) {
	for _, name := range []string{m.cfg.CookieName, CSRFCookieName} {
		http.SetCookie(w, &http.Cookie{
			Name:     name,
			Value:    "",
			Path:     m.cfg.Path,
			MaxAge:   -1,
			HttpOnly: name == m.cfg.CookieName,
			Secure:   m.cfg.Secure,
			SameSite: m.sameSite(),
		})
	}
}

// ValidateCSRF enforces the double-submit contract for unsafe methods.
//
// Safe methods (GET/HEAD/OPTIONS) are exempt, exactly like Laravel's VerifyCsrfToken. A missing session is
// itself a mismatch: a state-changing request with no session has no token to match.
func ValidateCSRF(r *http.Request, s *Session) error {
	switch r.Method {
	case http.MethodGet, http.MethodHead, http.MethodOptions:
		return nil
	}
	if s == nil || s.CSRFToken == "" {
		return ErrCSRFMismatch
	}
	provided := r.Header.Get(CSRFHeaderName)
	if provided == "" {
		provided = r.Header.Get(CSRFHeaderAlt)
	}
	if provided == "" {
		provided = r.PostFormValue("_token")
	}
	if provided == "" {
		return ErrCSRFMismatch
	}
	if decoded, err := url.QueryUnescape(provided); err == nil {
		provided = decoded
	}
	if subtle.ConstantTimeCompare([]byte(provided), []byte(s.CSRFToken)) != 1 {
		return ErrCSRFMismatch
	}
	return nil
}

// gc deletes expired rows with a small probability per call, mirroring Laravel's session lottery. The row
// count stays bounded without a scheduler.
func (m *Manager) gc(ctx context.Context) error {
	n, err := rand.Int(rand.Reader, big.NewInt(50))
	if err != nil || n.Int64() != 0 {
		return nil
	}
	_, err = m.database.ExecContext(ctx, `DELETE FROM pc_go_sessions WHERE expires_at < ?`, m.now().Unix())
	return err
}

func nullableStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	return s[:max]
}

// clientIP resolves the caller address, honouring X-Forwarded-For only when the deployment says a trusted
// proxy is in front (TRUST_PROXY_HEADERS) — the same assumption Laravel's TrustProxies makes.
func clientIP(r *http.Request) string {
	if r == nil {
		return ""
	}
	if forwarded := r.Header.Get("X-Forwarded-For"); forwarded != "" {
		first := strings.TrimSpace(strings.Split(forwarded, ",")[0])
		if first != "" {
			return truncate(first, 45)
		}
	}
	host := r.RemoteAddr
	if i := strings.LastIndex(host, ":"); i > 0 {
		host = host[:i]
	}
	return truncate(host, 45)
}

// requestFromSession rebuilds a minimal request for Rotate (only the client metadata is carried over).
func requestFromSession(s *Session) *http.Request {
	if s == nil {
		return &http.Request{}
	}
	r := &http.Request{Header: http.Header{}}
	if s.UserAgent != "" {
		r.Header.Set("User-Agent", s.UserAgent)
	}
	if s.IPAddress != "" {
		r.Header.Set("X-Forwarded-For", s.IPAddress)
	}
	return r
}
