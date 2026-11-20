package api

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
)

func TestGoogleSheetsMetadataUsesServiceAccountAndReturnsTitle(t *testing.T) {
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	privateKey, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	pemKey := string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKey}))
	var server *httptest.Server
	server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/token":
			if r.Method != http.MethodPost || r.FormValue("grant_type") != "urn:ietf:params:oauth:grant-type:jwt-bearer" || r.FormValue("assertion") == "" {
				t.Errorf("unexpected token request: %s %s", r.Method, r.URL.Path)
				w.WriteHeader(http.StatusBadRequest)
				return
			}
			_, _ = w.Write([]byte(`{"access_token":"stub-token","token_type":"Bearer","expires_in":3600}`))
		case "/v4/spreadsheets/sheet-id":
			if r.Header.Get("Authorization") != "Bearer stub-token" {
				t.Errorf("missing bearer token: %q", r.Header.Get("Authorization"))
				w.WriteHeader(http.StatusUnauthorized)
				return
			}
			_, _ = w.Write([]byte(`{"properties":{"title":"Parity Sheet"},"sheets":[]}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer server.Close()
	credential := map[string]string{"type": "service_account", "project_id": "project", "client_email": "sheets@example.test", "private_key": pemKey, "token_uri": server.URL + "/token"}
	content, _ := json.Marshal(credential)
	path := filepath.Join(t.TempDir(), "service-account.json")
	if err := os.WriteFile(path, content, 0600); err != nil {
		t.Fatal(err)
	}
	app := &Server{cfg: config.Config{GoogleSheets: config.GoogleSheetsConfig{Enabled: true, CredentialsPath: path, Timeout: 2_000_000_000}}}
	request := httptest.NewRequestWithContext(context.Background(), http.MethodPost, "/", nil)
	metadata, apiErr := app.googleSheetsMetadataAt(request, "sheet-id", server.URL+"/v4")
	if apiErr != nil {
		t.Fatalf("metadata returned %s: %s", apiErr.Code, apiErr.Message)
	}
	if metadata.Title != "Parity Sheet" {
		t.Fatalf("title=%q", metadata.Title)
	}
}

func TestGoogleSheetsDisabledAndGoogleErrorsMapToLaravelCodes(t *testing.T) {
	cases := []struct {
		status     int
		body, code string
	}{{401, `{}`, "AUTHENTICATION_ERROR"}, {403, `{"error":{"details":[{"reason":"SERVICE_DISABLED"}]}}`, "API_DISABLED"}, {403, `{}`, "PERMISSION_DENIED"}, {404, `{}`, "SPREADSHEET_NOT_FOUND"}, {429, `{}`, "RATE_LIMIT"}, {400, `{}`, "INVALID_REQUEST"}, {500, `{}`, "UNKNOWN_GOOGLE_API_ERROR"}}
	for _, tc := range cases {
		if got := mapGoogleSheetsError(tc.status, []byte(tc.body)).Code; got != tc.code {
			t.Errorf("status %d code=%s want=%s", tc.status, got, tc.code)
		}
	}
}
