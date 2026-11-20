package admininfra

import (
	"strings"
	"testing"
)

func TestSanitizeGatewayErrorRedactsCredentialsAndCapsLength(t *testing.T) {
	got := sanitizeGatewayError(" Authorization: Basic c3ludGhldGljOnBhc3M= https://user:secret@example.test/path  " + string(make([]rune, 220)))
	if got == "" || len([]rune(got)) > 200 {
		t.Fatalf("sanitized message length = %d", len([]rune(got)))
	}
	for _, secret := range []string{"c3ludGhldGljOnBhc3M=", "user:secret"} {
		if strings.Contains(got, secret) {
			t.Fatalf("credential %q leaked in %q", secret, got)
		}
	}
}
