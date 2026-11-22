package agentadmin

import (
	"strings"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
)

func TestResolveAgentUsesAuthenticatedBranchAndRole(t *testing.T) {
	branch := int64(29)
	agen := &auth.User{ID: branch, Role: auth.RoleAgen, AgentID: &branch}
	admin := &auth.User{ID: 31, Role: auth.RoleAdmin, AgentID: &branch}
	foreign := &auth.User{ID: 32, Role: auth.RoleAdmin, AgentID: intPtr(40)}
	if got, err := ResolveAgent(agen, false); err != nil || got != branch {
		t.Fatalf("agen scope = %d, %v", got, err)
	}
	if got, err := ResolveAgent(admin, true); err != nil || got != branch {
		t.Fatalf("admin scope = %d, %v", got, err)
	}
	if _, err := ResolveAgent(admin, false); err == nil {
		t.Fatal("Admin unexpectedly gained Agen-only shipping/profile access")
	}
	if _, err := ResolveAgent(foreign, false); err == nil {
		t.Fatal("non-Agen unexpectedly gained shipping/profile access")
	}
}

func TestWhatsAppTemplateValidationRejectsUnknownAndMalformedVariables(t *testing.T) {
	if err := validateTemplate("order_created", "Order {nomor_pesanan}"); err != nil {
		t.Fatalf("valid event variable rejected: %v", err)
	}
	for _, invalid := range []string{"Order {secret}", "Order {nomor_pesanan", "Order }", "  "} {
		if err := validateTemplate("order_created", invalid); err == nil {
			t.Errorf("invalid template %q accepted", invalid)
		}
	}
	if err := validateTemplate("not_an_event", "Hello"); err == nil {
		t.Fatal("template for a non-transactional event accepted")
	}
}

func TestWhatsAppPhoneNormalizationAndMasking(t *testing.T) {
	if got := normalizeMSISDN("+62 812-3456-7890"); got != "6281234567890" {
		t.Fatalf("normalized number = %q", got)
	}
	if got := maskNumber("6281234567890"); got != "62••••890" {
		t.Fatalf("masked number = %q", got)
	}
	for _, bad := range []string{"abc", ""} {
		if got := normalizeMSISDN(bad); got != "" {
			t.Errorf("invalid number %q normalized to %q", bad, got)
		}
	}
	if got := normalizeMSISDN("08-1234 ext 567890"); got != "6281234567890" {
		t.Errorf("WhatsAppNumber strips non-digits before applying its length rule: got %q", got)
	}
}

func TestKmsitEndpointValidationRejectsPrivateAndPlainHTTP(t *testing.T) {
	for _, endpoint := range []string{"http://example.com/api", "https://127.0.0.1/api", "https://localhost/api", "file:///etc/passwd", "https://user:pass@example.com", "https://example.com/?next=internal"} {
		if err := vetKmsitURL(endpoint, nil); err == nil {
			t.Errorf("unsafe endpoint %q accepted", endpoint)
		}
	}
	if err := vetKmsitURL("https://8.8.8.8/api/v1", nil); err != nil {
		t.Errorf("public HTTPS endpoint rejected: %v", err)
	}
	if err := vetKmsitURL("http://10.0.0.1:8080", []string{"10.0.0.1"}); err != nil {
		t.Errorf("operator-trusted private endpoint rejected: %v", err)
	}
}

func TestShippingCredentialsNeverAppearInProviderPresentation(t *testing.T) {
	cfg := map[string]any{"api_key": "do-not-return", "origin_label": "Bandung", "couriers": []any{"jne"}}
	p := ShippingProvider{Code: "rajaongkir", Configured: true, Origin: stringPtr(valueString(cfg, "origin_label", ""))}
	if strings.Contains(strings.Join([]string{p.Code, *p.Origin}, " "), "do-not-return") {
		t.Fatal("credential was included in provider presentation")
	}
}

func intPtr(v int64) *int64 { return &v }
