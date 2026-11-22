package api

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
)

func TestGoogleSheetsReplaceWritesConfiguredRows(t *testing.T) {
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	privateKey, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	pemKey := string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKey}))
	wrote := false
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/token":
			_, _ = w.Write([]byte(`{"access_token":"stub-token"}`))
		case "/v4/spreadsheets/sheet-id":
			if r.Header.Get("Authorization") != "Bearer stub-token" {
				t.Errorf("missing bearer token")
			}
			_, _ = w.Write([]byte(`{"properties":{"title":"Parity"},"sheets":[{"properties":{"sheetId":7,"title":"Sales","gridProperties":{"columnCount":2}}}]}`))
		case "/v4/spreadsheets/sheet-id:batchUpdate":
			if r.Method != http.MethodPost || r.Header.Get("Authorization") != "Bearer stub-token" {
				t.Errorf("unexpected write request: %s %q", r.Method, r.Header.Get("Authorization"))
			}
			var payload struct {
				Requests []map[string]any `json:"requests"`
			}
			if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
				t.Errorf("decode write: %v", err)
				return
			}
			if len(payload.Requests) != 2 {
				t.Errorf("request count=%d want=2", len(payload.Requests))
			}
			update, ok := payload.Requests[1]["updateCells"].(map[string]any)
			if !ok {
				t.Errorf("missing updateCells request: %#v", payload.Requests)
			} else {
				rows, _ := update["rows"].([]any)
				first, _ := rows[1].(map[string]any)
				cells, _ := first["values"].([]any)
				cell, _ := cells[1].(map[string]any)
				entered, _ := cell["userEnteredValue"].(map[string]any)
				if entered["numberValue"] != float64(12.5) {
					t.Errorf("numeric cell=%#v", entered)
				}
			}
			wrote = true
			_, _ = w.Write([]byte(`{"replies":[{},{}]}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer server.Close()
	path := writeSheetsTestCredential(t, server.URL, pemKey)
	app := &Server{cfg: config.Config{GoogleSheets: config.GoogleSheetsConfig{Enabled: true, CredentialsPath: path, Timeout: 2 * time.Second}}}
	request := httptest.NewRequest(http.MethodPost, "/", bytes.NewReader(nil))
	if apiErr := app.googleSheetsReplaceAt(request, "sheet-id", "Sales", [][]any{{"Name", "Amount"}, {"Cake", 12.5}}, server.URL+"/v4"); apiErr != nil {
		t.Fatalf("replace returned %s: %s", apiErr.Code, apiErr.Message)
	}
	if !wrote {
		t.Fatal("successful sync did not write to the Sheets API")
	}
}

func TestGoogleSheetsReplaceKeepsProtectedCellsOutOfUpdate(t *testing.T) {
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	privateKey, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	pemKey := string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateKey}))
	var batch []byte
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/token":
			_, _ = w.Write([]byte(`{"access_token":"stub-token"}`))
		case "/v4/spreadsheets/sheet-id":
			_, _ = w.Write([]byte(`{"properties":{"title":"Parity"},"sheets":[{"properties":{"sheetId":7,"title":"Ledger","gridProperties":{"columnCount":3}}}]}`))
		case "/v4/spreadsheets/sheet-id/values/'Ledger'!A1:C":
			_, _ = w.Write([]byte(`{"values":[["No","System","Manual"],["1","old-one","operator-note"],["2","old-two","operator-value"]]}`))
		case "/v4/spreadsheets/sheet-id:batchUpdate":
			batch, _ = io.ReadAll(r.Body)
			_, _ = w.Write([]byte(`{"replies":[]}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer server.Close()
	path := writeSheetsTestCredential(t, server.URL, pemKey)
	app := &Server{cfg: config.Config{GoogleSheets: config.GoogleSheetsConfig{Enabled: true, CredentialsPath: path, Timeout: 2 * time.Second}}}
	request := httptest.NewRequest(http.MethodPost, "/", nil)
	rows := [][]any{{"No", "System", "Manual"}, {"2", "new-two", ""}, {"3", "new-three", ""}}
	protect := sheetsProtectedConfig{Indexes: []int{2}, RowKeys: []string{"2", "3"}, Validation: map[int][]string{2: {"konsumen", "keuangan"}}}
	if apiErr := app.googleSheetsReplaceAt(request, "sheet-id", "Ledger", rows, server.URL+"/v4", protect); apiErr != nil {
		t.Fatalf("protected replace returned %s: %s", apiErr.Code, apiErr.Message)
	}
	if len(batch) == 0 {
		t.Fatal("protected sync did not issue a batch update")
	}
	if bytes.Contains(batch, []byte("operator-note")) || bytes.Contains(batch, []byte("operator-value")) {
		t.Fatalf("manual cell contents leaked into write payload: %s", batch)
	}
	var decoded struct {
		Requests []map[string]json.RawMessage `json:"requests"`
	}
	if err := json.Unmarshal(batch, &decoded); err != nil {
		t.Fatal(err)
	}
	foundValidation := false
	for _, request := range decoded.Requests {
		if raw := request["setDataValidation"]; len(raw) > 0 {
			foundValidation = true
		}
		if raw := request["updateCells"]; len(raw) > 0 {
			var update struct {
				Range struct {
					StartRow    int `json:"startRowIndex"`
					EndRow      int `json:"endRowIndex"`
					StartColumn int `json:"startColumnIndex"`
					EndColumn   int `json:"endColumnIndex"`
				} `json:"range"`
			}
			_ = json.Unmarshal(raw, &update)
			if update.Range.StartRow > 0 && update.Range.EndColumn > 2 {
				t.Fatalf("data write overlaps protected column: %s", raw)
			}
		}
	}
	if !foundValidation {
		t.Fatal("protected dropdown validation request missing")
	}
}

func writeSheetsTestCredential(t *testing.T, serverURL, pemKey string) string {
	t.Helper()
	credential := map[string]string{"type": "service_account", "project_id": "project", "client_email": "sheets@example.test", "private_key": pemKey, "token_uri": serverURL + "/token"}
	content, _ := json.Marshal(credential)
	path := filepath.Join(t.TempDir(), "service-account.json")
	if err := os.WriteFile(path, content, 0600); err != nil {
		t.Fatal(err)
	}
	return path
}

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
