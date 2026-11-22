package jobs

import (
	"encoding/json"
	"testing"
)

func TestWhatsAppJobIdentityAndLaravelSerializedID(t *testing.T) {
	command := `O:31:"App\Jobs\SendWhatsAppMessageJob":2:{s:9:"messageId";i:123;s:5:"queue";s:18:"primeclassy-notify";}`
	payload, err := json.Marshal(map[string]any{"displayName": WhatsAppJobName, "job": "Illuminate\\Queue\\CallQueuedHandler@call", "data": map[string]string{"commandName": WhatsAppJobName, "command": command}})
	if err != nil {
		t.Fatal(err)
	}
	if id, name, ok := MessageID(payload); !ok || id != 123 || name != WhatsAppJobName {
		t.Fatalf("MessageID() = %d, %q, %v", id, name, ok)
	}
}
