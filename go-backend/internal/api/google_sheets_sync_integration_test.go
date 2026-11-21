package api

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"database/sql"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

func TestGoogleSheetsSyncWritesAndCompletesLog(t *testing.T) {
	envPath := os.Getenv("GO_ENV_FILE")
	if envPath == "" {
		envPath = filepath.Join("..", "..", ".env")
	}
	if _, err := os.Stat(envPath); err != nil {
		t.Skipf("no %s; isolated test database unavailable", envPath)
	}
	cfg, err := config.Load(config.FileGetter(envPath))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Env != config.EnvTesting || !config.IsTestingDatabase(cfg.DB.Database) {
		t.Fatalf("refusing non-testing database %q", cfg.DB.Database)
	}
	database, err := db.Open(context.Background(), cfg.DB)
	if err != nil {
		t.Skipf("isolated test database unavailable: %v", err)
	}
	defer database.Close()
	lockConn, err := database.Conn(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	defer lockConn.Close()
	var acquired sql.NullInt64
	if err := lockConn.QueryRowContext(context.Background(), `SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing',0)`).Scan(&acquired); err != nil {
		t.Fatal(err)
	}
	if !acquired.Valid || acquired.Int64 != 1 {
		t.Fatal("test database is already owned by another runner")
	}
	defer func() {
		_, _ = lockConn.ExecContext(context.Background(), `SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing')`)
	}()

	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	privateDER, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	pemKey := string(pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: privateDER}))
	var wrote bool
	failWrite := false
	provider := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/token":
			_, _ = w.Write([]byte(`{"access_token":"stub-token"}`))
		case r.Method == http.MethodGet && strings.Contains(r.URL.Path, "/spreadsheets/sheets-sync-test-"):
			_, _ = w.Write([]byte(`{"properties":{"title":"Test"},"sheets":[{"properties":{"sheetId":8,"title":"Products","gridProperties":{"columnCount":2}}}]}`))
		case r.Method == http.MethodPost && strings.HasSuffix(r.URL.Path, ":batchUpdate"):
			if r.Header.Get("Authorization") != "Bearer stub-token" {
				t.Errorf("missing bearer token")
			}
			var body struct {
				Requests []json.RawMessage `json:"requests"`
			}
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				t.Errorf("decode batch update: %v", err)
			}
			if len(body.Requests) < 2 {
				t.Errorf("batch requests=%d want >=2", len(body.Requests))
			}
			wrote = true
			if failWrite {
				w.WriteHeader(http.StatusForbidden)
				_, _ = w.Write([]byte(`{"error":{"errors":[{"reason":"PERMISSION_DENIED"}]}}`))
				return
			}
			_, _ = w.Write([]byte(`{"replies":[{},{}]}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer provider.Close()
	credential := map[string]string{"type": "service_account", "project_id": "test", "client_email": "sheets@example.test", "private_key": pemKey, "token_uri": provider.URL + "/token"}
	credentialJSON, _ := json.Marshal(credential)
	credentialPath := filepath.Join(t.TempDir(), "service-account.json")
	if err := os.WriteFile(credentialPath, credentialJSON, 0600); err != nil {
		t.Fatal(err)
	}

	spreadsheetID := fmt.Sprintf("sheets-sync-test-%d", time.Now().UnixNano())
	result, err := database.ExecContext(context.Background(), `INSERT INTO sheets_destinations (spreadsheet_id,created_at,updated_at) VALUES (?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, spreadsheetID)
	if err != nil {
		t.Fatal(err)
	}
	destinationID, err := result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = database.ExecContext(context.Background(), `DELETE FROM sheets_destinations WHERE id=?`, destinationID)
	}()
	columns := []map[string]string{{"field": "sku", "label": "SKU"}, {"field": "price", "label": "Price"}}
	columnsJSON, _ := json.Marshal(columns)
	result, err = database.ExecContext(context.Background(), `INSERT INTO sheets_configs (destination_id,name,tab,dataset,columns,filters,created_at,updated_at) VALUES (?, 'Sync Test','Products','products',?,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, destinationID, string(columnsJSON))
	if err != nil {
		t.Fatal(err)
	}
	configID, err := result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = database.ExecContext(context.Background(), `DELETE FROM sheets_sync_logs WHERE config_id=?`, configID)
		_, _ = database.ExecContext(context.Background(), `DELETE FROM sheets_configs WHERE id=?`, configID)
	}()

	app := &Server{database: database, cfg: config.Config{GoogleSheets: config.GoogleSheetsConfig{Enabled: true, CredentialsPath: credentialPath, Timeout: 5 * time.Second, MaxRows: 10000}}, googleSheetsBase: provider.URL + "/v4"}
	actor := &auth.User{ID: 1, Role: auth.RoleSuperAdmin}
	missingRequest := httptest.NewRequest(http.MethodPost, "/api/v1/google-sheets/configs/999999999/sync", nil)
	missingRequest.SetPathValue("config", "999999999")
	missingRequest = missingRequest.WithContext(withState(missingRequest.Context(), &requestState{User: actor}))
	missingRecorder := httptest.NewRecorder()
	app.handleGoogleSheetsConfigSync(missingRecorder, missingRequest)
	if missingRecorder.Code != http.StatusNotFound || !strings.Contains(missingRecorder.Body.String(), "Endpoint not found.") {
		t.Fatalf("missing config response=%d body=%s", missingRecorder.Code, missingRecorder.Body.String())
	}
	request := httptest.NewRequest(http.MethodPost, "/api/v1/google-sheets/configs/"+strconv.FormatInt(configID, 10)+"/sync", nil)
	request.SetPathValue("config", strconv.FormatInt(configID, 10))
	request = request.WithContext(withState(request.Context(), &requestState{User: actor}))
	recorder := httptest.NewRecorder()
	app.handleGoogleSheetsConfigSync(recorder, request)
	if recorder.Code != http.StatusOK {
		t.Fatalf("sync HTTP status=%d body=%s", recorder.Code, recorder.Body.String())
	}
	if !wrote {
		t.Fatal("sync returned without writing to Sheets API")
	}
	var status string
	var rowsProcessed, rowsSuccess, rowsFailed int
	if err := database.QueryRowContext(context.Background(), `SELECT status,rows_processed,rows_success,rows_failed FROM sheets_sync_logs WHERE config_id=? ORDER BY id DESC LIMIT 1`, configID).Scan(&status, &rowsProcessed, &rowsSuccess, &rowsFailed); err != nil {
		t.Fatal(err)
	}
	if status != "success" || rowsSuccess != rowsProcessed || rowsFailed != 0 {
		t.Fatalf("sync log status=%s processed=%d success=%d failed=%d", status, rowsProcessed, rowsSuccess, rowsFailed)
	}
	failWrite = true
	wrote = false
	failedRequest := httptest.NewRequest(http.MethodPost, "/api/v1/google-sheets/configs/"+strconv.FormatInt(configID, 10)+"/sync", nil)
	failedRequest.SetPathValue("config", strconv.FormatInt(configID, 10))
	failedRequest = failedRequest.WithContext(withState(failedRequest.Context(), &requestState{User: actor}))
	failedRecorder := httptest.NewRecorder()
	app.handleGoogleSheetsConfigSync(failedRecorder, failedRequest)
	if failedRecorder.Code != http.StatusOK || !wrote {
		t.Fatalf("failed sync response=%d wrote=%v body=%s", failedRecorder.Code, wrote, failedRecorder.Body.String())
	}
	if err := database.QueryRowContext(context.Background(), `SELECT status,rows_processed,rows_success,rows_failed FROM sheets_sync_logs WHERE config_id=? ORDER BY id DESC LIMIT 1`, configID).Scan(&status, &rowsProcessed, &rowsSuccess, &rowsFailed); err != nil {
		t.Fatal(err)
	}
	if status != "failed" || rowsSuccess != 0 || rowsFailed != rowsProcessed {
		t.Fatalf("failed sync log status=%s processed=%d success=%d failed=%d", status, rowsProcessed, rowsSuccess, rowsFailed)
	}
}
