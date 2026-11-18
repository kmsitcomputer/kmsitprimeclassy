package orders

// Deterministic identifier helpers and the credential decoder used on the create path.

import (
	"crypto/rand"
	"encoding/hex"
	"math/big"
	"strconv"

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
