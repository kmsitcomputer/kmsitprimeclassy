package jobs

import "testing"

func TestMessageIDFromLaravelPayload(t *testing.T) {
	payload := []byte(`{"displayName":"App\\Jobs\\SendWhatsAppMessageJob","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\SendWhatsAppMessageJob","command":"O:34:\"App\\Jobs\\SendWhatsAppMessageJob\":2:{s:11:\"messageId\";i:42;s:8:\"onQueue\";s:18:\"primeclassy-notify\";}"}}`)
	id, name, ok := MessageID(payload)
	if !ok {
		t.Fatal("failed to decode Laravel queued job payload")
	}
	if id != 42 {
		t.Fatalf("message id = %d, want 42", id)
	}
	if name != "App\\Jobs\\SendWhatsAppMessageJob" {
		t.Fatalf("command name = %q", name)
	}
}

func TestMessageIDRejectsInvalidPayload(t *testing.T) {
	if _, _, ok := MessageID([]byte(`{"data":{}}`)); ok {
		t.Fatal("invalid payload must not decode")
	}
}
