package i18n

import (
	"encoding/json"
	"strings"
	"testing"
)

// Required public message keys. Keep this contract test independent of historical PHP source.
var expectedKeys = map[string][]string{
	"system": {
		"validation_failed", "unauthorized_action", "please_login", "not_found", "endpoint_not_found",
		"generic_error", "server_error", "agent_not_linked", "field_required", "field_invalid",
	},
	"auth":     {"login_failed", "register_success", "login_success", "logout_success", "registration_unavailable"},
	"profile":  {"current_password_incorrect", "password_updated"},
	"stock":    {"agent_id_required", "invalid_agent"},
	"referral": {"not_linked_to_agent", "invalid_code", "code_not_found"},
}

// TestCatalogHasRequiredMessages protects the checked-in, verified API message contract.
func TestCatalogHasRequiredMessages(t *testing.T) {
	if err := LoadError(); err != nil {
		t.Fatalf("embedded catalogue is not valid JSON: %v", err)
	}
	for _, locale := range []string{"id", "en", "ar", "zh"} {
		for group, keys := range expectedKeys {
			for _, key := range keys {
				messageKey := group + "." + key
				if got := Translate(locale, messageKey); strings.TrimSpace(got) == "" || got == messageKey {
					t.Errorf("%s %s is missing or empty", locale, messageKey)
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
