package cryptox

import (
	"bufio"
	"os"
	"strings"
	"testing"
)

// laravelAppKey reads the REFERENCE deployment's APP_KEY from backend/.env.testing.
//
// The fixture below was produced by the running reference (`php artisan tinker --env=testing`) encrypting a real
// agent shipping config, so a passing test proves the Go service can read rows Laravel wrote — not that it
// agrees with itself.
func laravelAppKey(t *testing.T) string {
	t.Helper()
	for _, path := range []string{"../../../backend/.env.testing", "../../backend/.env.testing", "../../../backend/.env"} {
		file, err := os.Open(path)
		if err != nil {
			continue
		}
		defer file.Close()
		scanner := bufio.NewScanner(file)
		for scanner.Scan() {
			line := strings.TrimSpace(scanner.Text())
			if strings.HasPrefix(line, "APP_KEY=") {
				return strings.TrimPrefix(line, "APP_KEY=")
			}
		}
	}
	t.Skip("no backend .env with APP_KEY available")
	return ""
}

// phpEncryptedArray is the exact `agent_shipping_provider_configs.config` value the reference produced for
// {api_key:"RAJA-SECRET-123", origin_destination_id:"456", origin_label:"Bandung", couriers:["jne","tiki"]}.
const phpEncryptedArray = "eyJpdiI6Im5lUVQ5Tm5YZ3U3QW45dFJnR0JZNFE9PSIsInZhbHVlIjoiVFpyY2VoZTRWQmoxZDYzOEo0RlVpdmFpOXZKWWloNURSd0o1NEdGaHBiaFBEMHpxdGdTM3M2L1V4cmpJMEthTkg1SEt5b1RrdTMxVUEzZzlvWGp2MTIySG42aE5MNjZzNHI4UHpveVhod0RPTlpSQ3VKZWhpc05nK3Zvbm83cDNhaTBCdEIyMTBIQVBQaTRmaElUSjFBPT0iLCJtYWMiOiJjM2M2ZDk0ZmJmMGZmMzk2OTBhYjQ3MDM2ZDg2OGNiOTdlMWYyN2FmMzZlNzA5YTJlY2ZhOWViZDBkZTU1YTMwIiwidGFnIjoiIn0="

func TestDecryptArrayReadsLaravelEncryptedConfig(t *testing.T) {
	key := laravelAppKey(t)
	config, err := DecryptArray(key, phpEncryptedArray)
	if err != nil {
		t.Fatalf("decrypt: %v", err)
	}
	if got := config["api_key"]; got != "RAJA-SECRET-123" {
		t.Errorf("api_key = %v, want RAJA-SECRET-123", got)
	}
	if got := config["origin_destination_id"]; got != "456" {
		t.Errorf("origin_destination_id = %v", got)
	}
	couriers, ok := config["couriers"].([]any)
	if !ok || len(couriers) != 2 || couriers[0] != "jne" || couriers[1] != "tiki" {
		t.Errorf("couriers = %v", config["couriers"])
	}
}

// TestTamperedPayloadIsRejected pins the MAC check: a modified ciphertext must never decrypt.
func TestTamperedPayloadIsRejected(t *testing.T) {
	key := laravelAppKey(t)
	tampered := strings.Replace(phpEncryptedArray, "VFpyY2VoZTRWQmox", "VFpyY2VoZTRWQmoz", 1)
	if _, err := DecryptArray(key, tampered); err == nil {
		t.Fatal("a tampered payload decrypted — the MAC check is not enforced")
	}
}

func TestWrongKeyIsRejected(t *testing.T) {
	wrong := "base64:" + strings.Repeat("A", 43) + "="
	if _, err := DecryptArray(wrong, phpEncryptedArray); err == nil {
		t.Fatal("a wrong APP_KEY decrypted the payload")
	}
}
