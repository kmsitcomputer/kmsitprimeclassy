package oauth

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"net/url"
	"time"
)

var ErrExpired = errors.New("oauth flow expired")
var ErrStateMismatch = errors.New("oauth state mismatch")

type GoogleFlow struct {
	State      string
	Nonce      string
	Verifier   string
	Mode       string
	Referral   string
	LinkUserID *int64
	AgentID    *int64
	CreatedAt  time.Time
}

func randomSecret(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}

func BeginGoogle(mode, referral string) (*GoogleFlow, error) {
	if mode != "login" && mode != "register" && mode != "link" {
		mode = "login"
	}
	state, err := randomSecret(36)
	if err != nil {
		return nil, err
	}
	nonce, err := randomSecret(36)
	if err != nil {
		return nil, err
	}
	verifier, err := randomSecret(48)
	if err != nil {
		return nil, err
	}
	return &GoogleFlow{
		State: state, Nonce: nonce, Verifier: verifier, Mode: mode,
		Referral: referral, CreatedAt: time.Now().UTC(),
	}, nil
}

func (f *GoogleFlow) AuthorizationURL(clientID, redirectURI string) string {
	sum := sha256.Sum256([]byte(f.Verifier))
	challenge := base64.RawURLEncoding.EncodeToString(sum[:])
	q := url.Values{}
	q.Set("client_id", clientID)
	q.Set("redirect_uri", redirectURI)
	q.Set("response_type", "code")
	q.Set("scope", "openid email profile")
	q.Set("state", f.State)
	q.Set("nonce", f.Nonce)
	q.Set("code_challenge", challenge)
	q.Set("code_challenge_method", "S256")
	q.Set("prompt", "select_account")
	return "https://accounts.google.com/o/oauth2/v2/auth?" + q.Encode()
}

func (f *GoogleFlow) Consume(returnedState string, now time.Time) error {
	if !consistentTimeString(f.State, returnedState) {
		return ErrStateMismatch
	}
	if now.Before(f.CreatedAt) || now.Sub(f.CreatedAt) > 10*time.Minute {
		return ErrExpired
	}
	return nil
}

func consistentTimeString(a, b string) bool {
	if len(a) != len(b) {
		return false
	}
	var diff byte
	for i := range len(a) {
		diff |= a[i] ^ b[i]
	}
	return diff == 0
}
