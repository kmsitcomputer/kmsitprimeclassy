package oauth

import (
	"strings"
	"testing"
	"time"
)

func TestGoogleFlowPKCEAndState(t *testing.T) {
	f, err := BeginGoogle("link", "")
	if err != nil {
		t.Fatal(err)
	}
	u := f.AuthorizationURL("client-id", "https://example.test/api/v1/auth/google/callback")
	if !strings.Contains(u, "code_challenge_method=S256") ||
		!strings.Contains(u, "state="+f.State) {
		t.Fatalf("authorization URL = %q", u)
	}
	if err := f.Consume(f.State, f.CreatedAt.Add(5*time.Minute)); err != nil {
		t.Fatalf("valid flow rejected: %v", err)
	}
	if err := f.Consume("wrong", f.CreatedAt.Add(time.Minute)); err != ErrStateMismatch {
		t.Fatalf("wrong state error = %v", err)
	}
	if err := f.Consume(f.State, f.CreatedAt.Add(11*time.Minute)); err != ErrExpired {
		t.Fatalf("expired state error = %v", err)
	}
}
