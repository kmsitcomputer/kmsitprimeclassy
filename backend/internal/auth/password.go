// Package auth implements the identity domain: password verification compatible with the existing
// Laravel hashes, DB-backed sessions, CSRF tokens and the frontend permission map.
package auth

import (
	"errors"
	"fmt"

	"golang.org/x/crypto/bcrypt"
)

// ErrPasswordMismatch is returned when the supplied password does not match the stored hash.
var ErrPasswordMismatch = errors.New("password mismatch")

// VerifyPassword checks a plaintext password against a stored Laravel hash.
//
// Laravel stores bcrypt hashes with the `$2y$` prefix (PHP's crypt); golang.org/x/crypto/bcrypt parses
// `$2a$`, `$2b$` AND `$2y$` for verification, so existing rows keep working with no rehash. New hashes
// written by Go use `$2a$`, which PHP's password_verify() also accepts — so the two runtimes stay
// mutually compatible in both directions.
func VerifyPassword(hash, plaintext string) error {
	if hash == "" || plaintext == "" {
		return ErrPasswordMismatch
	}
	if err := bcrypt.CompareHashAndPassword([]byte(hash), []byte(plaintext)); err != nil {
		if errors.Is(err, bcrypt.ErrMismatchedHashAndPassword) {
			return ErrPasswordMismatch
		}
		return fmt.Errorf("bcrypt: %w", err)
	}
	return nil
}

// HashPassword produces a hash for a new credential. Cost matches PHP's default (10) so a password
// created by Go has the same work factor as one created by Laravel.
func HashPassword(plaintext string) (string, error) {
	buf, err := bcrypt.GenerateFromPassword([]byte(plaintext), bcrypt.DefaultCost)
	if err != nil {
		return "", err
	}
	return string(buf), nil
}

// NeedsRehash reports whether a stored hash is below the current cost (bcrypt only, never rehashes a
// non-bcrypt hash into bcrypt silently — that would be an unrequested security change).
func NeedsRehash(hash string) bool {
	cost, err := bcrypt.Cost([]byte(hash))
	if err != nil {
		return false
	}
	return cost < bcrypt.DefaultCost
}
