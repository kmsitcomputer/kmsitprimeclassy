package jobs

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

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

type failingHandler struct{}

func (failingHandler) HandleWhatsApp(context.Context, int64) error {
	return errors.New("fixture handler failure")
}
func (failingHandler) HandleEmail(context.Context, int64) error {
	return errors.New("fixture handler failure")
}

type countingHandler struct{ calls int }

func (h *countingHandler) HandleWhatsApp(context.Context, int64) error { h.calls++; return nil }
func (h *countingHandler) HandleEmail(context.Context, int64) error    { h.calls++; return nil }

func TestProcessLogsLaravelFailedJobsAfterOneAttempt(t *testing.T) {
	envPath := os.Getenv("GO_ENV_FILE")
	if envPath == "" {
		envPath = filepath.Join("..", "..", ".env")
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

	uuid := fmt.Sprintf("imp010-failed-job-%d", time.Now().UnixNano())
	command := `O:31:"App\Jobs\SendWhatsAppMessageJob":2:{s:9:"messageId";i:987654;s:5:"queue";s:18:"primeclassy-notify";}`
	payload := fmt.Sprintf(`{"uuid":%q,"displayName":"App\\Jobs\\SendWhatsAppMessageJob","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":1,"data":{"commandName":"App\\Jobs\\SendWhatsAppMessageJob","command":%q}}`, uuid, command)
	result, err := database.ExecContext(context.Background(), `INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at) VALUES (?,?,0,NULL,?,?)`, NotificationQueue, payload, time.Now().Unix(), time.Now().Unix())
	if err != nil {
		t.Fatal(err)
	}
	jobID, err := result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = database.ExecContext(context.Background(), `DELETE FROM jobs WHERE id=?`, jobID)
		_, _ = database.ExecContext(context.Background(), `DELETE FROM failed_jobs WHERE uuid=?`, uuid)
	}()
	processed, err := Process(context.Background(), database, failingHandler{})
	if !processed || err == nil || err.Error() != "fixture handler failure" {
		t.Fatalf("Process() = (%v, %v), want processed with fixture error", processed, err)
	}
	var queueRows, failedRows int
	if err := database.QueryRowContext(context.Background(), `SELECT COUNT(*) FROM jobs WHERE id=?`, jobID).Scan(&queueRows); err != nil {
		t.Fatal(err)
	}
	if err := database.QueryRowContext(context.Background(), `SELECT COUNT(*) FROM failed_jobs WHERE uuid=? AND connection='database' AND queue=? AND payload=? AND exception=?`, uuid, NotificationQueue, payload, "fixture handler failure").Scan(&failedRows); err != nil {
		t.Fatal(err)
	}
	if queueRows != 0 || failedRows != 1 {
		t.Fatalf("queue rows=%d failed rows=%d, want 0 and 1", queueRows, failedRows)
	}

	maxedUUID := uuid + "-maxed"
	maxedPayload := fmt.Sprintf(`{"uuid":%q,"displayName":"App\\Jobs\\SendWhatsAppMessageJob","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":1,"data":{"commandName":"App\\Jobs\\SendWhatsAppMessageJob","command":%q}}`, maxedUUID, command)
	result, err = database.ExecContext(context.Background(), `INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at) VALUES (?,?,1,NULL,?,?)`, NotificationQueue, maxedPayload, time.Now().Unix(), time.Now().Unix())
	if err != nil {
		t.Fatal(err)
	}
	maxedJobID, err := result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = database.ExecContext(context.Background(), `DELETE FROM jobs WHERE id=?`, maxedJobID)
		_, _ = database.ExecContext(context.Background(), `DELETE FROM failed_jobs WHERE uuid=?`, maxedUUID)
	}()
	noCall := &countingHandler{}
	processed, err = Process(context.Background(), database, noCall)
	if !processed || err == nil || noCall.calls != 0 {
		t.Fatalf("Process(max tries exceeded) = (%v, %v), handler calls=%d; want failed without invocation", processed, err, noCall.calls)
	}
	if err := database.QueryRowContext(context.Background(), `SELECT COUNT(*) FROM failed_jobs WHERE uuid=? AND payload=?`, maxedUUID, maxedPayload).Scan(&failedRows); err != nil {
		t.Fatal(err)
	}
	if failedRows != 1 {
		t.Fatalf("failed_jobs rows for max-attempt fixture=%d, want 1", failedRows)
	}
}
