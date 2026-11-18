package i18n

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// The keys the Go service can emit. Kept in sync with tools/parity/gen_i18n.py.
var expectedKeys = map[string][]string{
	"system": {
		"validation_failed", "unauthorized_action", "please_login", "not_found", "endpoint_not_found",
		"generic_error", "server_error", "agent_not_linked", "field_required", "field_invalid",
	},
	"auth":     {"login_failed", "register_success", "login_success", "logout_success", "registration_unavailable"},
	"profile":  {"current_password_incorrect", "password_updated"},
	"referral": {"not_linked_to_agent", "invalid_code", "code_not_found"},
}

// TestCatalogMatchesLaravelMessages re-extracts the messages from backend/lang/*/messages.php and fails when
// the committed catalogue has drifted. Without this, a PHP message edit would silently make the Go runtime
// answer with stale text — the exact class of bug that makes a migration look "done" while users see the
// wrong language.
func TestCatalogMatchesLaravelMessages(t *testing.T) {
	root := filepath.Join("..", "..", "..", "backend", "lang")
	if _, err := os.Stat(root); err != nil {
		t.Skipf("Laravel lang directory not available (%v); catalogue parity is verified in the repository checkout", err)
	}

	if err := LoadError(); err != nil {
		t.Fatalf("embedded catalogue is not valid JSON: %v", err)
	}

	for _, locale := range []string{"id", "en", "ar", "zh"} {
		path := filepath.Join(root, locale, "messages.php")
		body, err := os.ReadFile(path)
		if err != nil {
			t.Fatalf("read %s: %v", path, err)
		}
		for group, keys := range expectedKeys {
			block, err := phpBlock(string(body), group)
			if err != nil {
				t.Fatalf("%s: %v", locale, err)
			}
			pairs := phpPairs(block)
			for _, key := range keys {
				want, ok := pairs[key]
				if !ok {
					t.Fatalf("%s: message %s.%s missing from the PHP source", locale, group, key)
				}
				got := Translate(locale, group+"."+key)
				if got != want {
					t.Errorf("%s %s.%s = %q, PHP says %q", locale, group, key, got, want)
				}
			}
		}
	}
}

// TestUnknownLocaleFallsBackToDefault proves an unsupported/hostile locale can never produce an empty message.
func TestUnknownLocaleFallsBackToDefault(t *testing.T) {
	if got := Translate("xx", "system.please_login"); got != Translate("id", "system.please_login") {
		t.Errorf("unknown locale must fall back to id, got %q", got)
	}
	if got := Translate("id", "system.does_not_exist"); got != "system.does_not_exist" {
		t.Errorf("missing key must be visible, got %q", got)
	}
}

// TestResolveLocalePrecedence mirrors App\Http\Middleware\SetLocale: X-Locale, then Accept-Language, then id.
func TestResolveLocalePrecedence(t *testing.T) {
	cases := []struct {
		header, accept, want string
	}{
		{"en", "id", "en"},
		{"EN", "", "en"},
		{"zh-CN", "", "zh"},
		{"", "en-US,en;q=0.9,id;q=0.8", "en"},
		{"", "ar", "ar"},
		{"", "", Default},
		{"klingon", "id-ID", "id"},
		{"xx", "", Default},
	}
	for _, tc := range cases {
		if got := ResolveLocale(tc.header, tc.accept); got != tc.want {
			t.Errorf("ResolveLocale(%q,%q) = %q, want %q", tc.header, tc.accept, got, tc.want)
		}
	}
}

func TestAllLocalesPresent(t *testing.T) {
	locales := Locales()
	if len(locales) != 4 {
		t.Errorf("expected 4 locales, got %v", locales)
	}
	for _, want := range []string{"id", "en", "ar", "zh"} {
		if !Supported(want) {
			t.Errorf("locale %q missing", want)
		}
	}
}

// TestCatalogJSONHasNoEmptyMessages catches a truncated generation (an empty string would render as a blank
// error toast in the UI).
func TestCatalogJSONHasNoEmptyMessages(t *testing.T) {
	var catalog map[string]map[string]string
	if err := json.Unmarshal(catalogJSON, &catalog); err != nil {
		t.Fatal(err)
	}
	for locale, messages := range catalog {
		for key, value := range messages {
			if strings.TrimSpace(value) == "" {
				t.Errorf("%s %s is empty", locale, key)
			}
		}
	}
}

// ---- minimal PHP lang reader (same approach as tools/parity/gen_i18n.py) ----------------------------

var pairRe = regexp.MustCompile(`(?s)'([a-z_0-9]+)'\s*=>\s*'((?:[^'\\]|\\.)*)'`)

func phpBlock(src, name string) (string, error) {
	marker := "'" + name + "' => ["
	i := strings.Index(src, marker)
	if i < 0 {
		return "", os.ErrNotExist
	}
	start := strings.Index(src[i:], "[") + i
	depth := 0
	for k := start; k < len(src); k++ {
		switch src[k] {
		case '[':
			depth++
		case ']':
			depth--
			if depth == 0 {
				return src[start : k+1], nil
			}
		}
	}
	return "", os.ErrInvalid
}

func phpPairs(block string) map[string]string {
	out := map[string]string{}
	for _, m := range pairRe.FindAllStringSubmatch(block, -1) {
		value := strings.ReplaceAll(m[2], `\'`, `'`)
		value = strings.ReplaceAll(value, `\\`, `\`)
		out[m[1]] = value
	}
	return out
}
