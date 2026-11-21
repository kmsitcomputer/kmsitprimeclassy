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

func TestWhatsAppGatewayResponseDuplicateSafety(t *testing.T) {
	tests := []struct {
		name    string
		status  int
		payload map[string]any
		outcome string
		class   string
	}{
		{name: "success", status: 200, payload: map[string]any{"code": "SUCCESS", "results": map[string]any{"message_id": "m1"}}, outcome: "sent"},
		{name: "auth is final", status: 401, outcome: "failed", class: "auth"},
		{name: "device not ready is safe to retry", status: 503, payload: map[string]any{"message": "device is not connected"}, outcome: "retry", class: "device_disconnected"},
		{name: "generic server error may have sent", status: 503, payload: map[string]any{"message": "upstream failed"}, outcome: "unknown", class: "provider_error"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := classifyWhatsAppResponse(tc.status, tc.payload)
			if got["outcome"] != tc.outcome {
				t.Fatalf("outcome=%v, want %s", got["outcome"], tc.outcome)
			}
			if tc.class != "" && got["error_class"] != tc.class {
				t.Fatalf("error_class=%v, want %s", got["error_class"], tc.class)
			}
		})
	}
}

func TestWhatsAppFromDeviceNeverFallsBackWithoutSlot(t *testing.T) {
	var service *Service
	got, err := service.SendWhatsAppTextFromDevice(t.Context(), "628123456789", "text", " ")
	if err != nil {
		t.Fatal(err)
	}
	if got["outcome"] != "failed" || got["error_class"] != "device_not_ready" {
		t.Fatalf("unexpected result without an Agent device slot: %#v", got)
	}
}

func TestTransactionalMailHTMLMatchesLaravelMailable(t *testing.T) {
	got := renderTransactionalEmailHTML("A <B> & 'quote' \"yes\"\nsecond line")
	want := "A &lt;B&gt; &amp; &#039;quote&#039; &quot;yes&quot;<br />\nsecond line" +
		"<br><br>Salam,<br><strong>Tim PrimeClassy</strong><br><br><small>Pesan ini dikirim otomatis berdasarkan aktivitas transaksi atau akun Anda. Untuk bantuan, gunakan kanal layanan pelanggan resmi PrimeClassy.</small>"
	if got != want {
		t.Fatalf("transactional email HTML mismatch:\n got %q\nwant %q", got, want)
	}
}
