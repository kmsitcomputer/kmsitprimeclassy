// Package cryptox ports the one Laravel encryption primitive the Go service must READ: the payload produced by
// Eloquent's `encrypted:array` cast (used by agent_shipping_provider_configs.config).
//
// WHY A PORT INSTEAD OF A NEW FORMAT
// Credentials already exist in that column, written by Laravel with APP_KEY. The Go service must decrypt what
// is stored, not re-encrypt in a different format — otherwise the two runtimes could not share a database
// during the migration window, and every existing agent would silently lose their shipping configuration.
//
// FORMAT (Laravel 11/12, AES-256-CBC)
//
//	payload  = base64( json_encode({iv, value, mac, tag}) )
//	iv       = 16 random bytes
//	value    = base64( openssl_encrypt(serialized_or_plain_plaintext, aes-256-cbc, key, iv) )
//	mac      = hmac_sha256( base64(iv) + base64(value), key )
//
// `key` is base64_decode(APP_KEY without the "base64:" prefix). The plaintext is either a PHP-serialised
// array/string (classic Encrypter) or the raw JSON string (`encrypted:array` casts JSON-encodes first, and
// Laravel's `serialize` config decides whether the JSON string is then PHP-serialised). Both shapes are
// accepted here because both occur in real deployments; the caller gets the decoded JSON either way.
package cryptox

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
)

// ErrDecrypt is the single failure mode the callers map to "credentials cannot be read" (Laravel raises
// DecryptException and the services degrade instead of crashing).
var ErrDecrypt = errors.New("cryptox: payload cannot be decrypted")

// Key decodes an APP_KEY (`base64:...` or raw) into the 32-byte AES key.
func Key(appKey string) ([]byte, error) {
	raw := strings.TrimSpace(appKey)
	if raw == "" {
		return nil, fmt.Errorf("%w: APP_KEY is empty", ErrDecrypt)
	}
	if strings.HasPrefix(raw, "base64:") {
		decoded, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(raw, "base64:"))
		if err != nil {
			return nil, fmt.Errorf("%w: APP_KEY is not valid base64", ErrDecrypt)
		}
		raw = string(decoded)
	}
	if len(raw) != 32 {
		return nil, fmt.Errorf("%w: APP_KEY must decode to 32 bytes for AES-256", ErrDecrypt)
	}
	return []byte(raw), nil
}

// DecryptString returns the plaintext string inside a Laravel encrypted payload.
func DecryptString(appKey, payload string) (string, error) {
	key, err := Key(appKey)
	if err != nil {
		return "", err
	}
	plaintext, err := decrypt(key, payload)
	if err != nil {
		return "", err
	}
	return unwrapPHPString(plaintext), nil
}

// DecryptArray decodes an `encrypted:array` payload into a JSON object. The stored plaintext is JSON, possibly
// wrapped in PHP's serialisation format (Laravel's `serialize` option), so unwrapPHPString is applied first.
func DecryptArray(appKey, payload string) (map[string]any, error) {
	plain, err := DecryptString(appKey, payload)
	if err != nil {
		return nil, err
	}
	plain = strings.TrimSpace(plain)
	if plain == "" || plain == "null" {
		return map[string]any{}, nil
	}
	var out map[string]any
	if err := json.Unmarshal([]byte(plain), &out); err != nil {
		return nil, fmt.Errorf("%w: plaintext is not a JSON object", ErrDecrypt)
	}
	return out, nil
}

// EncryptArray produces the Laravel encrypted:array representation. Laravel's encrypted cast encrypts the
// JSON string without PHP serialization; preserving that exact representation lets Laravel and Go continue
// sharing the same credential rows during the migration.
func EncryptArray(appKey string, value map[string]any) (string, error) {
	plain, err := json.Marshal(value)
	if err != nil {
		return "", fmt.Errorf("%w: JSON encode", ErrDecrypt)
	}
	return encrypt(appKey, plain)
}

// EncryptString emits the Laravel Crypt::encryptString representation
// (AES-256-CBC, the shared APP_KEY, and the authenticated Laravel envelope).
func EncryptString(appKey, value string) (string, error) {
	return encrypt(appKey, []byte(value))
}

func encrypt(appKey string, plain []byte) (string, error) {
	key, err := Key(appKey)
	if err != nil {
		return "", err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", fmt.Errorf("%w: cipher", ErrDecrypt)
	}
	padded := pkcs7Pad(plain, aes.BlockSize)
	iv := make([]byte, aes.BlockSize)
	if _, err := io.ReadFull(rand.Reader, iv); err != nil {
		return "", fmt.Errorf("%w: IV generation", ErrDecrypt)
	}
	ciphertext := make([]byte, len(padded))
	cipher.NewCBCEncrypter(block, iv).CryptBlocks(ciphertext, padded)

	iv64 := base64.StdEncoding.EncodeToString(iv)
	value64 := base64.StdEncoding.EncodeToString(ciphertext)
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(iv64))
	mac.Write([]byte(value64))
	envelope, err := json.Marshal(map[string]string{
		"iv": iv64, "value": value64, "mac": hex.EncodeToString(mac.Sum(nil)), "tag": "",
	})
	if err != nil {
		return "", fmt.Errorf("%w: envelope encode", ErrDecrypt)
	}
	return base64.StdEncoding.EncodeToString(envelope), nil
}

func pkcs7Pad(value []byte, blockSize int) []byte {
	padding := blockSize - len(value)%blockSize
	out := make([]byte, len(value)+padding)
	copy(out, value)
	for i := len(value); i < len(out); i++ {
		out[i] = byte(padding)
	}
	return out
}

// decrypt verifies the MAC and returns the raw plaintext bytes.
func decrypt(key []byte, payload string) (string, error) {
	outer, err := base64.StdEncoding.DecodeString(strings.TrimSpace(payload))
	if err != nil {
		return "", fmt.Errorf("%w: payload is not base64", ErrDecrypt)
	}
	var envelope struct {
		IV    string `json:"iv"`
		Value string `json:"value"`
		MAC   string `json:"mac"`
		Tag   string `json:"tag"`
	}
	if err := json.Unmarshal(outer, &envelope); err != nil {
		return "", fmt.Errorf("%w: envelope is not JSON", ErrDecrypt)
	}
	if envelope.IV == "" || envelope.Value == "" || envelope.MAC == "" {
		return "", fmt.Errorf("%w: envelope is incomplete", ErrDecrypt)
	}

	// MAC is computed over the base64 iv concatenated with the base64 value, exactly as PHP does.
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(envelope.IV))
	mac.Write([]byte(envelope.Value))
	expected := mac.Sum(nil)
	provided, err := hexDecode(envelope.MAC)
	if err != nil {
		return "", fmt.Errorf("%w: MAC is not hex", ErrDecrypt)
	}
	// Constant-time compare: a timing oracle on the MAC would leak plaintext knowledge.
	if subtle.ConstantTimeCompare(expected, provided) != 1 {
		return "", fmt.Errorf("%w: MAC mismatch (APP_KEY changed?)", ErrDecrypt)
	}

	iv, err := base64.StdEncoding.DecodeString(envelope.IV)
	if err != nil || len(iv) != aes.BlockSize {
		return "", fmt.Errorf("%w: IV is invalid", ErrDecrypt)
	}
	value, err := base64.StdEncoding.DecodeString(envelope.Value)
	if err != nil {
		return "", fmt.Errorf("%w: value is not base64", ErrDecrypt)
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", fmt.Errorf("%w: cipher", ErrDecrypt)
	}
	if len(value) == 0 || len(value)%aes.BlockSize != 0 {
		return "", fmt.Errorf("%w: ciphertext length", ErrDecrypt)
	}
	plain := make([]byte, len(value))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(plain, value)

	// PKCS#7 unpad.
	pad := int(plain[len(plain)-1])
	if pad < 1 || pad > aes.BlockSize || pad > len(plain) {
		return "", fmt.Errorf("%w: padding", ErrDecrypt)
	}
	for _, b := range plain[len(plain)-pad:] {
		if int(b) != pad {
			return "", fmt.Errorf("%w: padding", ErrDecrypt)
		}
	}
	return string(plain[:len(plain)-pad]), nil
}

// unwrapPHPString strips PHP's `s:<len>:"...";` serialisation when present. Laravel applies it to string
// values when the `serialize` option is on (the default for the classic Encrypter), and `encrypted:array`
// JSON-encodes before encryption, so the JSON string may arrive serialised.
func unwrapPHPString(v string) string {
	if !strings.HasPrefix(v, "s:") {
		return v
	}
	rest := strings.TrimPrefix(v, "s:")
	colon := strings.Index(rest, ":\"")
	if colon <= 0 {
		return v
	}
	var length int
	if _, err := fmt.Sscanf(rest[:colon], "%d", &length); err != nil {
		return v
	}
	start := colon + 2
	if start+length > len(rest) {
		return v
	}
	return rest[start : start+length]
}

func hexDecode(s string) ([]byte, error) {
	if len(s)%2 != 0 {
		return nil, errors.New("odd length")
	}
	out := make([]byte, len(s)/2)
	for i := 0; i < len(out); i++ {
		hi, err := hexVal(s[2*i])
		if err != nil {
			return nil, err
		}
		lo, err := hexVal(s[2*i+1])
		if err != nil {
			return nil, err
		}
		out[i] = hi<<4 | lo
	}
	return out, nil
}

func hexVal(c byte) (byte, error) {
	switch {
	case c >= '0' && c <= '9':
		return c - '0', nil
	case c >= 'a' && c <= 'f':
		return c - 'a' + 10, nil
	case c >= 'A' && c <= 'F':
		return c - 'A' + 10, nil
	}
	return 0, errors.New("not hex")
}
