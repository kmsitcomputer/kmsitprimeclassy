package orders

// Deterministic identifier helpers and the credential decoder used on the create path.

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"math/big"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
)

func randomHex(n int) (string, error) {
	buf := make([]byte, n)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(buf), nil
}

const alphaNumeric = "abcdefghijklmnopqrstuvwxyz0123456789"

func randomAlphaNumeric(n int) (string, error) {
	out := make([]byte, n)
	max := big.NewInt(int64(len(alphaNumeric)))
	for i := range out {
		idx, err := rand.Int(rand.Reader, max)
		if err != nil {
			return "", err
		}
		out[i] = alphaNumeric[idx.Int64()]
	}
	return string(out), nil
}

// decryptJSON reads a Laravel `encrypted:array` value. A failure degrades to nulls at the call site — order
// creation must not fail because a branch's bank display could not be decrypted.
func decryptJSON(appKey, payload string) (map[string]any, error) {
	return cryptox.DecryptArray(appKey, payload)
}

// formatInt is used by the renderer to key per-shipment maps the way the JSON payload expects.
func formatInt(v int64) string { return strconv.FormatInt(v, 10) }

// ErrOrderNotFound is the model-binding 404 for a missing order.
var ErrOrderNotFound = errors.New("order not found")

// parseDecimalString converts a DECIMAL column rendered as text into a float64.
func parseDecimalString(v string) float64 {
	f, err := strconv.ParseFloat(strings.TrimSpace(v), 64)
	if err != nil {
		return 0
	}
	return f
}

// sha256Hex is the reference's checksum hash.
func sha256Hex(data []byte) string {
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])
}

// ulid mimics Str::ulid() closely enough for the obligation_no column (26-char Crockford base32, time-prefixed).
// Uniqueness is enforced by the caller's obligation_key lookup, not by this format.
func ulid() (string, error) {
	buf := make([]byte, 16)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	const alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
	ms := time.Now().UnixMilli()
	out := make([]byte, 26)
	for i := 9; i >= 0; i-- {
		out[i] = alphabet[ms%32]
		ms /= 32
	}
	for i := 10; i < 26; i++ {
		out[i] = alphabet[int(buf[i-10])%32]
	}
	return string(out), nil
}
