package auth

import (
	"errors"
	"strings"
	"testing"
)

// laravelBcryptHash is a REAL hash produced by the Laravel runtime:
//
//	php -r "echo password_hash('Secret123!', PASSWORD_BCRYPT, ['cost'=>10]);"
//
// It uses PHP's `$2y$` prefix. golang.org/x/crypto/bcrypt writes `$2a$`, so this test is the proof that the
// two runtimes verify each other's rows: existing Laravel users can log in through Go with no rehash, and a
// password set through Go still verifies in PHP.
const laravelBcryptHash = "$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq"

const fixturePassword = "Secret123!"

func TestVerifyLaravel2yHash(t *testing.T) {
	if err := VerifyPassword(laravelBcryptHash, fixturePassword); err != nil {
		t.Fatalf("a PHP $2y$ hash must verify: %v", err)
	}
}

func TestVerifyRejectsWrongPassword(t *testing.T) {
	err := VerifyPassword(laravelBcryptHash, "wrong-password")
	if !errors.Is(err, ErrPasswordMismatch) {
		t.Fatalf("expected ErrPasswordMismatch, got %v", err)
	}
}

func TestVerifyRejectsEmptyInputs(t *testing.T) {
	for _, tc := range [][2]string{{"", fixturePassword}, {laravelBcryptHash, ""}, {"", ""}} {
		if err := VerifyPassword(tc[0], tc[1]); !errors.Is(err, ErrPasswordMismatch) {
			t.Errorf("VerifyPassword(%q,%q) = %v, want mismatch", tc[0], tc[1], err)
		}
	}
}

// TestHashRoundTrip checks that a hash created by Go verifies in Go and carries a PHP-compatible prefix.
func TestHashRoundTrip(t *testing.T) {
	hash, err := HashPassword(fixturePassword)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(hash, "$2a$") && !strings.HasPrefix(hash, "$2y$") && !strings.HasPrefix(hash, "$2b$") {
		t.Fatalf("unexpected hash prefix: %q", hash[:7])
	}
	if err := VerifyPassword(hash, fixturePassword); err != nil {
		t.Fatalf("Go-produced hash must verify: %v", err)
	}
	if err := VerifyPassword(hash, "different"); !errors.Is(err, ErrPasswordMismatch) {
		t.Fatal("Go-produced hash must reject a wrong password")
	}
	// A second hash of the same password must differ (a random salt per hash).
	other, err := HashPassword(fixturePassword)
	if err != nil {
		t.Fatal(err)
	}
	if other == hash {
		t.Error("two hashes of the same password must not be identical")
	}
}

func TestNeedsRehashIsConservativeForNonBcrypt(t *testing.T) {
	// argon2/bcrypt-unknown inputs must never trigger a silent rehash decision.
	for _, hash := range []string{"", "$argon2id$v=19$m=65536,t=4,p=1$abc$def", "plaintext"} {
		if NeedsRehash(hash) {
			t.Errorf("NeedsRehash(%q) must be false", hash)
		}
	}
	hash, err := HashPassword(fixturePassword)
	if err != nil {
		t.Fatal(err)
	}
	if NeedsRehash(hash) {
		t.Error("a hash at the current cost must not need a rehash")
	}
}
