package admininfra

import (
	"strings"
	"testing"
)

func TestRedactSMTPSecrets(t *testing.T) {
	got := redactSMTPError("login user-secret failed for smtp://user-secret:pass-secret@mail.invalid", "user-secret", "pass-secret")
	for _, secret := range []string{"user-secret", "pass-secret"} {
		if strings.Contains(got, secret) {
			t.Fatalf("SMTP credential %q leaked in %q", secret, got)
		}
	}
}
