package api

import (
	"context"
	"crypto/rand"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// ---- error rendering -------------------------------------------------------------------------------

func msg(ctx context.Context, key string) string { return i18n.Translate(LocaleFrom(ctx), key) }

func unauthorizedError(ctx context.Context) *httpx.Error {
	return httpx.NewError(http.StatusUnauthorized, msg(ctx, "system.please_login"))
}

func forbiddenError(ctx context.Context) *httpx.Error {
	return httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action"))
}

// notFoundError renders the 404 message Laravel ACTUALLY returns for the API.
//
// App\Exceptions\Handler registers a messages.system.not_found renderer for ModelNotFoundException, but Laravel
// converts ModelNotFoundException to NotFoundHttpException before the custom renderers run, so the
// NotFoundHttpException callback wins and the body says `endpoint_not_found`. Verified against the running
// Laravel reference (tools/parity/compare_public_api.py). Reproducing the observed text is required: the SPA
// displays it verbatim. The unreachable not_found branch is recorded as a parity finding in docs/21 §5.
func notFoundError(ctx context.Context) *httpx.Error {
	return httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found"))
}

// requiredField / invalidField / unknownChoice render Laravel's validation sentences for the rules the Go
// service enforces (required, in, exists), including the ":attribute" substitution.
func requiredField(ctx context.Context, field string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "required", field)}
}

func invalidChoice(ctx context.Context, field string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "in", field)}
}

func unknownChoice(ctx context.Context, field string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "exists", field)}
}

// maxString renders the `max` sentence for a string field.
func maxString(ctx context.Context, field string, max int) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "max.string", field, "max", strconv.Itoa(max))}
}

// minNumeric renders the `min` sentence for a numeric field.
func minNumeric(ctx context.Context, field string, min int) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", field, "min", strconv.Itoa(min))}
}

// uniqueField renders the `unique` sentence.
func uniqueField(ctx context.Context, field string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "unique", field)}
}

// notInteger renders the `integer` sentence.
func notInteger(ctx context.Context, field string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", field)}
}

// writeError renders any error as the API envelope, mapping unknown errors to 500 without leaking internals
// (Laravel renders the same generic server_error message unless APP_DEBUG is on).
func writeError(w http.ResponseWriter, r *http.Request, err error) {
	var apiErr *httpx.Error
	if errors.As(err, &apiErr) {
		status := apiErr.Status
		if status == 0 {
			status = http.StatusInternalServerError
		}
		message := apiErr.Message
		if message == "" {
			if len(apiErr.Fields) > 0 {
				// Laravel renders every ValidationException with this one sentence.
				message = msg(r.Context(), "system.validation_failed")
			} else {
				message = msg(r.Context(), "system.generic_error")
			}
		}
		httpx.Failure(w, status, message, apiErr.ErrorsPayload())
		return
	}
	slog.ErrorContext(r.Context(), "unhandled request error",
		"error", err, "path", r.URL.Path, "request_id", RequestIDFrom(r.Context()))
	httpx.Failure(w, http.StatusInternalServerError, msg(r.Context(), "system.server_error"), nil)
}

// notImplementedError is returned for the routes the Go service does not own yet. It is deliberately a
// distinct status + code so a parity test can enumerate exactly which endpoints still live in Laravel.
func notImplementedError(ctx context.Context) *httpx.Error {
	return &httpx.Error{
		Status:  http.StatusNotImplemented,
		Message: "This endpoint is not implemented by the Go service yet; Laravel remains authoritative.",
		Code:    "not_implemented",
	}
}

// ---- middleware ------------------------------------------------------------------------------------

// Middleware is the standard net/http middleware shape.
type Middleware func(http.Handler) http.Handler

// chain applies middleware in order (first listed runs first).
func chain(h http.Handler, mws ...Middleware) http.Handler {
	for i := len(mws) - 1; i >= 0; i-- {
		h = mws[i](h)
	}
	return h
}

// RequestState initialises the per-request state, correlation id, locale and client metadata.
func RequestState(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// A trailing slash addresses the same resource (a harmless internal rewrite, never an external
		// redirect), so `/api/v1/products/` reaches the collection handler instead of the catch-all.
		if len(r.URL.Path) > 1 && strings.HasSuffix(r.URL.Path, "/") {
			trimmed := strings.TrimRight(r.URL.Path, "/")
			if trimmed == "" {
				trimmed = "/"
			}
			r.URL.Path = trimmed
			if r.URL.RawPath != "" {
				r.URL.RawPath = strings.TrimRight(r.URL.RawPath, "/")
			}
			r.RequestURI = trimmed
			if r.URL.RawQuery != "" {
				r.RequestURI = trimmed + "?" + r.URL.RawQuery
			}
		}
		st := &requestState{
			RequestID: newRequestID(),
			Locale:    i18n.ResolveLocale(r.Header.Get("X-Locale"), r.Header.Get("Accept-Language")),
			IP:        clientIP(r),
			UserAgent: r.UserAgent(),
		}
		w.Header().Set("X-Request-Id", st.RequestID)
		next.ServeHTTP(w, r.WithContext(withState(r.Context(), st)))
	})
}

// Recover turns a panic into a logged 500 envelope instead of a dropped connection.
func Recover(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if rec := recover(); rec != nil {
				slog.ErrorContext(r.Context(), "panic recovered",
					"panic", rec, "path", r.URL.Path, "request_id", RequestIDFrom(r.Context()))
				httpx.Failure(w, http.StatusInternalServerError, msg(r.Context(), "system.server_error"), nil)
			}
		}()
		next.ServeHTTP(w, r)
	})
}

// statusRecorder captures the response status for the access log.
type statusRecorder struct {
	http.ResponseWriter
	status int
	bytes  int
}

func (s *statusRecorder) WriteHeader(code int) {
	s.status = code
	s.ResponseWriter.WriteHeader(code)
}

func (s *statusRecorder) Write(b []byte) (int, error) {
	if s.status == 0 {
		s.status = http.StatusOK
	}
	n, err := s.ResponseWriter.Write(b)
	s.bytes += n
	return n, err
}

// AccessLog writes one structured line per request (never the body, never credentials).
func AccessLog(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		rec := &statusRecorder{ResponseWriter: w}
		next.ServeHTTP(rec, r)
		if rec.status == 0 {
			rec.status = http.StatusOK
		}
		level := slog.LevelInfo
		if rec.status >= 500 {
			level = slog.LevelError
		} else if rec.status >= 400 {
			level = slog.LevelWarn
		}
		slog.Log(r.Context(), level, "http_request",
			"method", r.Method,
			"path", r.URL.Path,
			"status", rec.status,
			"bytes", rec.bytes,
			"duration_ms", time.Since(start).Milliseconds(),
			"request_id", RequestIDFrom(r.Context()),
			"user_id", userIDOrNil(UserFrom(r.Context())),
			"ip", stateFrom(r.Context()).IP,
		)
	})
}

func userIDOrNil(u *auth.User) any {
	if u == nil {
		return nil
	}
	return u.ID
}

// CORS implements the Sanctum stateful-origin contract: only origins listed in SANCTUM_STATEFUL_DOMAINS
// get credentials and an echoed Origin. Everything else gets no CORS headers at all (the browser then
// blocks the response), which is the fail-closed behaviour of config/cors.php with supports_credentials.
func CORS(stateful []string) Middleware {
	allowed := make(map[string]bool, len(stateful))
	for _, d := range stateful {
		allowed[normalizeHost(d)] = true
	}
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			origin := r.Header.Get("Origin")
			if origin != "" && allowed[normalizeHost(hostOf(origin))] {
				w.Header().Set("Access-Control-Allow-Origin", origin)
				w.Header().Set("Access-Control-Allow-Credentials", "true")
				w.Header().Set("Vary", "Origin")
				if r.Method == http.MethodOptions && r.Header.Get("Access-Control-Request-Method") != "" {
					w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
					w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-XSRF-TOKEN, X-Requested-With, X-Locale, Accept, Authorization")
					w.Header().Set("Access-Control-Max-Age", "600")
					w.WriteHeader(http.StatusNoContent)
					return
				}
			}
			if r.Method == http.MethodOptions {
				w.WriteHeader(http.StatusNoContent)
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}

// LoadSession resolves the browser session (if any) and refreshes its activity. It never rejects the
// request: authentication is RequireAuth's job, so public endpoints keep working with a stale cookie.
func (s *Server) LoadSession(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ctx := r.Context()
		session, err := s.sessions.Load(ctx, r)
		if err != nil {
			// A session-store failure must not silently degrade to "anonymous" for a state-changing
			// request; surface it so it is visible in logs and the client gets a real error.
			slog.ErrorContext(ctx, "session load failed", "error", err)
			writeError(w, r, err)
			return
		}
		st := stateFrom(ctx)
		st.Session = session
		if session != nil && session.UserID != nil {
			u, err := auth.FindUserByID(ctx, s.database, *session.UserID)
			if err == nil {
				st.User = u
			} else if !errors.Is(err, auth.ErrUserNotFound) {
				slog.ErrorContext(ctx, "session user lookup failed", "error", err)
			}
			// A session pointing at a deleted user is treated as anonymous and destroyed.
			if errors.Is(err, auth.ErrUserNotFound) {
				_ = s.sessions.Destroy(ctx, session)
				st.Session = nil
			}
		}
		if st.Session != nil {
			if err := s.sessions.Touch(ctx, st.Session); err != nil {
				slog.WarnContext(ctx, "session touch failed", "error", err)
			}
		}
		next.ServeHTTP(w, r)
	})
}

// ValidateCSRF enforces the double-submit token on unsafe methods.
//
// The install endpoints are exempt exactly like bootstrap/app.php does for `api/v1/install/*`: the
// installer runs before any session exists and is sealed by `not.installed` + super-admin checks.
func ValidateCSRF(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasPrefix(r.URL.Path, "/api/v1/install/") {
			next.ServeHTTP(w, r)
			return
		}
		if !implementsRoute(r.Method, r.URL.Path) {
			// Not served by this build: no side effect can happen, so a CSRF check would only mask the real
			// answer (501 not-implemented / 404 unknown) behind a 419.
			next.ServeHTTP(w, r)
			return
		}
		if err := auth.ValidateCSRF(r, SessionFrom(r.Context())); err != nil {
			// Laravel's TokenMismatchException message, rendered through the same envelope.
			httpx.Failure(w, 419, "CSRF token mismatch.", nil)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// RequireAuth rejects anonymous requests with the 401 envelope.
func RequireAuth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if UserFrom(r.Context()) == nil {
			writeError(w, r, unauthorizedError(r.Context()))
			return
		}
		next.ServeHTTP(w, r)
	})
}

// RequireRole is the coarse route gate (EnsureRole): the role is always re-read from the database row,
// never trusted from the client.
func RequireRole(spec string) Middleware {
	roles := auth.ParseRoleList(spec)
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			u := UserFrom(r.Context())
			if u == nil {
				writeError(w, r, unauthorizedError(r.Context()))
				return
			}
			if !u.HasAnyRole(roles...) {
				writeError(w, r, forbiddenError(r.Context()))
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}

// RequireAgentLinked is EnsureAgentLinked: a non-super-admin must belong to a live Agent, and everyone but
// super_admin/agen must point at an Agent that is still valid (fail closed, never repaired).
func (s *Server) RequireAgentLinked(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ctx := r.Context()
		u := UserFrom(ctx)
		if u == nil {
			next.ServeHTTP(w, r)
			return
		}
		if u.IsRole(auth.RoleSuperAdmin) {
			next.ServeHTTP(w, r)
			return
		}
		if u.AgentID == nil {
			writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.agent_not_linked")))
			return
		}
		if !u.IsRole(auth.RoleAgen) {
			valid, err := s.isValidAgent(ctx, *u.AgentID)
			if err != nil {
				writeError(w, r, err)
				return
			}
			if !valid {
				writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.agent_not_linked")))
				return
			}
		}
		next.ServeHTTP(w, r)
	})
}

// isValidAgent is ValidAgentResolver::isValid — exists, not soft-deleted, active, and actually an Agen.
func (s *Server) isValidAgent(ctx context.Context, agentID int64) (bool, error) {
	var ok bool
	err := s.database.QueryRowContext(ctx, `SELECT EXISTS(
		SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL AND u.status = 'active' AND r.slug = 'agen')`, agentID).Scan(&ok)
	if err != nil {
		return false, err
	}
	return ok, nil
}

// ---- rate limiting ---------------------------------------------------------------------------------

// limiter is a fixed-window in-process limiter.
//
// Laravel's `throttle:N,M` is backed by the cache store and is therefore shared across PHP workers. This
// implementation is per-process: correct for the single-instance local/dev topology of IMP-010 and for the
// gateway cutover (one Go process per origin). Multi-instance deployments must move the counter to the
// shared cache — recorded as a limitation in docs/21 §7.
type limiter struct {
	mu      sync.Mutex
	buckets map[string]*bucket
	now     func() time.Time
}

type bucket struct {
	count int
	reset time.Time
}

func newLimiter() *limiter {
	return &limiter{buckets: make(map[string]*bucket), now: time.Now}
}

// allow consumes one unit for key, returning (allowed, retryAfter).
func (l *limiter) allow(key string, limit int, window time.Duration) (bool, time.Duration) {
	l.mu.Lock()
	defer l.mu.Unlock()
	now := l.now()
	b, ok := l.buckets[key]
	if !ok || now.After(b.reset) {
		l.buckets[key] = &bucket{count: 1, reset: now.Add(window)}
		return true, 0
	}
	if b.count >= limit {
		return false, time.Until(b.reset)
	}
	b.count++
	return true, 0
}

// prune drops expired buckets so the map cannot grow without bound.
func (l *limiter) prune() {
	l.mu.Lock()
	defer l.mu.Unlock()
	now := l.now()
	for k, b := range l.buckets {
		if now.After(b.reset) {
			delete(l.buckets, k)
		}
	}
}

// Throttle implements `throttle:<limit>,<minutes>` keyed by authenticated user id when present, else client
// IP — the same key Laravel's ThrottleRequests derives.
//
// A throttled request returns 429 with a Retry-After header; the envelope message matches what Laravel
// emits ("Too Many Requests").
func (s *Server) Throttle(limit int, window time.Duration) Middleware {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			ctx := r.Context()
			st := stateFrom(ctx)
			key := "ip:" + st.IP + "|" + r.URL.Path
			if st.User != nil {
				key = "u:" + strconv.FormatInt(st.User.ID, 10) + "|" + r.URL.Path
			}
			allowed, retryAfter := s.limiter.allow(key, limit, window)
			if !allowed {
				seconds := int(retryAfter.Seconds() + 0.999)
				if seconds < 1 {
					seconds = 1
				}
				w.Header().Set("Retry-After", strconv.Itoa(seconds))
				w.Header().Set("X-RateLimit-Limit", strconv.Itoa(limit))
				w.Header().Set("X-RateLimit-Remaining", "0")
				httpx.Failure(w, http.StatusTooManyRequests, "Too Many Requests", nil)
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}

// ---- small helpers ---------------------------------------------------------------------------------

func newRequestID() string {
	b := make([]byte, 16)
	// crypto/rand failure is not recoverable here; fall back to a time-based id rather than panicking.
	if _, err := rand.Read(b); err != nil {
		return strconv.FormatInt(time.Now().UnixNano(), 36)
	}
	const hex = "0123456789abcdef"
	out := make([]byte, len(b)*2)
	for i, c := range b {
		out[i*2] = hex[c>>4]
		out[i*2+1] = hex[c&0x0f]
	}
	return string(out)
}

func clientIP(r *http.Request) string {
	if r == nil {
		return ""
	}
	if fwd := r.Header.Get("X-Forwarded-For"); fwd != "" {
		if first := strings.TrimSpace(strings.Split(fwd, ",")[0]); first != "" {
			return first
		}
	}
	if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		return host
	}
	return r.RemoteAddr
}

func hostOf(origin string) string {
	if i := strings.Index(origin, "://"); i >= 0 {
		origin = origin[i+3:]
	}
	if i := strings.IndexByte(origin, '/'); i >= 0 {
		origin = origin[:i]
	}
	return origin
}

func normalizeHost(h string) string { return strings.ToLower(strings.TrimSpace(h)) }
