package install

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestWriteEnvPreservesAndUpdates(t *testing.T) {
	path := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(path, []byte("DB_HOST=old\nAPP_NAME=Keep\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := WriteEnv(path, map[string]string{"DB_HOST": "new", "DB_PORT": "3306"}); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	text := string(raw)
	if !strings.Contains(text, "DB_HOST=new") ||
		!strings.Contains(text, "APP_NAME=Keep") ||
		!strings.Contains(text, "DB_PORT=3306") {
		t.Fatalf(".env = %q", text)
	}
}
