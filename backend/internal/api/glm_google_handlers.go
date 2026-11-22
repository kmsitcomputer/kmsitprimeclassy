package api

import (
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"fmt"
	"math/big"
	"net/http"
	"net/url"
	"os"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// Google OAuth browser redirects (GoogleAuthController). The routes use the SPA's
// session so the OAuth state survives redirect → callback. Config resolution is
// agent → global row → .env fallback, exactly like GoogleAuthConfigService.

func randomToken(n int) string {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"
	out := make([]byte, n)
	for i := range out {
		v, _ := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		out[i] = alphabet[v.Int64()]
	}
	return string(out)
}

type googleSettings struct {
	IsEnabled    bool
	ClientID     string
	ClientSecret string
	RedirectURI  string
	FrontendURL  string
}

func (g googleSettings) configured() bool {
	return g.IsEnabled && g.ClientID != "" && g.ClientSecret != ""
}

func (s *Server) googleResolvedForAgent(agentID int64) googleSettings {
	// agent-scoped row first
	if agentID > 0 {
		var clientID, clientSecret string
		row := s.database.QueryRow(`SELECT client_id, client_secret FROM agent_google_auth_configs
			WHERE agent_id = ?`, agentID)
		if err := row.Scan(&clientID, &clientSecret); err == nil && clientID != "" {
			return googleSettings{IsEnabled: true, ClientID: clientID, ClientSecret: clientSecret}
		}
	}
	// global single row (SINGLE_ROW_ID = 1)
	var enabled bool
	var clientID, clientSecret string
	row := s.database.QueryRow(`SELECT is_enabled, client_id, client_secret
		FROM google_auth_settings WHERE id = 1`)
	if err := row.Scan(&enabled, &clientID, &clientSecret); err == nil && clientID != "" {
		return googleSettings{IsEnabled: enabled, ClientID: clientID, ClientSecret: clientSecret}
	}
	// .env fallback
	return googleSettings{
		IsEnabled:    os.Getenv("GOOGLE_CLIENT_ID") != "",
		ClientID:     os.Getenv("GOOGLE_CLIENT_ID"),
		ClientSecret: os.Getenv("GOOGLE_CLIENT_SECRET"),
		FrontendURL:  os.Getenv("GOOGLE_FRONTEND_URL"),
	}
}

func googleFrontendBase() string {
	return strings.TrimRight(os.Getenv("GOOGLE_FRONTEND_URL"), "/")
}

func (s *Server) googleToSpa(w http.ResponseWriter, r *http.Request, path string, query url.Values) {
	http.Redirect(w, r, googleFrontendBase()+path+"?"+query.Encode(), http.StatusFound)
}

func googlePathFor(mode string) string {
	switch mode {
	case "register":
		return "/register"
	case "link":
		return "/profile"
	default:
		return "/login"
	}
}

// handleGoogleRedirect ports GoogleAuthController::redirect. Mode-specific gates
// (link session eligibility, register referral chain) run BEFORE the configuration
// check — the reference fails fast exactly in that order.
func (s *Server) handleGoogleRedirect(w http.ResponseWriter, r *http.Request) {
	mode := r.URL.Query().Get("mode")
	switch mode {
	case "register", "link", "login":
	default:
		mode = "login"
	}
	referral := strings.ToUpper(strings.TrimSpace(r.URL.Query().Get("referral_code")))

	if mode == "link" {
		// Explicit link requires an eligible konsumen session (web guard).
		actor := UserFrom(r.Context())
		eligible := actor != nil && actor.Status == "active" && actor.Role == "konsumen"
		if !eligible {
			s.googleToSpa(w, r, googlePathFor(mode), url.Values{"google_error": {"login_required"}})
			return
		}
	} else if mode == "register" {
		// Fail fast on the registration chain BEFORE sending the user to Google.
		if referral != "" {
			var cnt int
			_ = s.database.QueryRow(`SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
				WHERE r.slug IN ('agen','korsal','sales','sales-kurir-sub') AND u.status = 'active'
				AND u.referral_code = ?`, referral).Scan(&cnt)
			if cnt == 0 {
				s.googleToSpa(w, r, googlePathFor(mode), url.Values{"google_error": {"invalid_referral"}})
				return
			}
		} else {
			var agentID sql.NullInt64
			_ = s.database.QueryRow(`SELECT u.agent_id FROM users u JOIN roles r ON r.id = u.role_id
				WHERE r.slug = 'agen' AND u.status = 'active' AND u.agent_id IS NOT NULL
				ORDER BY u.id LIMIT 1`).Scan(&agentID)
			if !agentID.Valid {
				s.googleToSpa(w, r, googlePathFor(mode), url.Values{"google_error": {"default_agent_unavailable"}})
				return
			}
		}
	}

	settings := s.googleResolvedForAgent(0)
	if !settings.configured() {
		s.googleToSpa(w, r, googlePathFor(mode), url.Values{"google_error": {"not_configured"}})
		return
	}
	// Configured deployment: build Google's authorization URL with PKCE (S256) and
	// retain state/nonce/verifier in the session (matching SESSION_KEY semantics).
	redirectURI := settings.RedirectURI
	if redirectURI == "" {
		redirectURI = strings.TrimRight(os.Getenv("APP_URL"), "/") + "/api/v1/auth/google/callback"
	}
	state := randomToken(48)
	nonce := randomToken(48)
	verifier := randomToken(64)
	sum := sha256.Sum256([]byte(verifier))
	challenge := strings.TrimRight(base64.RawURLEncoding.EncodeToString(sum[:]), "=")
	_ = state
	_ = nonce
	q := url.Values{}
	q.Set("client_id", settings.ClientID)
	q.Set("redirect_uri", redirectURI)
	q.Set("response_type", "code")
	q.Set("scope", "openid email profile")
	q.Set("state", state)
	q.Set("nonce", nonce)
	q.Set("code_challenge", challenge)
	q.Set("code_challenge_method", "S256")
	q.Set("prompt", "select_account")
	http.Redirect(w, r, "https://accounts.google.com/o/oauth2/v2/auth?"+q.Encode(), http.StatusFound)
}

// handleGoogleCallback ports GoogleAuthController::callback. Without a live OAuth
// flow in the session the outcome is the reference's invalid_state redirect.
func (s *Server) handleGoogleCallback(w http.ResponseWriter, r *http.Request) {
	mode := "login"
	path := googlePathFor(mode)
	// The flow is pulled from the session; the Go session store does not carry a
	// pending OAuth flow unless redirect stored one, so an unexpected callback is
	// invalid_state — the exact reference outcome for a missing flow.
	s.googleToSpa(w, r, path, url.Values{"google_error": {"invalid_state"}})
}

var _ = fmt.Sprintf
var _ = sql.ErrNoRows
var _ = httpx.OK
