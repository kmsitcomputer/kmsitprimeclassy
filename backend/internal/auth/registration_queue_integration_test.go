package auth

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
	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
)

func TestRegistrationWhatsAppLedgerAndQueueAreDurableTogether(t *testing.T) {
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
		t.Fatalf("refusing non-testing database: env=%q database=%q", cfg.Env, cfg.DB.Database)
	}
	database, err := db.Open(context.Background(), cfg.DB)
	if err != nil {
		t.Skipf("isolated test database unavailable: %v", err)
	}
	defer database.Close()

	// Match the repository's single-runner MySQL lock before touching test fixtures.
	lockConn, err := database.Conn(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	defer lockConn.Close()
	var acquired sql.NullInt64
	if err := lockConn.QueryRowContext(context.Background(), `SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing', 0)`).Scan(&acquired); err != nil {
		t.Fatal(err)
	}
	if !acquired.Valid || acquired.Int64 != 1 {
		t.Fatal("test database is already owned by another runner")
	}
	defer func() {
		_, _ = lockConn.ExecContext(context.Background(), `SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing')`)
	}()

	ctx := context.Background()
	var oldValue sql.NullString
	var oldGroup sql.NullString
	var oldPublic bool
	var oldUpdatedAt sql.NullTime
	var settingID int64
	err = database.QueryRowContext(ctx, `SELECT id,value,`+"`group`"+`,is_public,updated_at FROM settings WHERE `+"`key`"+`='wa.enabled'`).Scan(&settingID, &oldValue, &oldGroup, &oldPublic, &oldUpdatedAt)
	settingCreated := false
	if errors.Is(err, sql.ErrNoRows) {
		settingCreated = true
		if _, err := database.ExecContext(ctx, `INSERT INTO settings (`+"`key`"+`,value,`+"`group`"+`,is_public,created_at,updated_at) VALUES ('wa.enabled','1','integration.whatsapp',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`); err != nil {
			t.Fatal(err)
		}
	} else if err != nil {
		t.Fatal(err)
	} else if _, err := database.ExecContext(ctx, `UPDATE settings SET value='1',`+"`group`"+`='integration.whatsapp',is_public=0,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, settingID); err != nil {
		t.Fatal(err)
	}
	defer func() {
		if settingCreated {
			_, _ = database.ExecContext(ctx, `DELETE FROM settings WHERE `+"`key`"+`='wa.enabled'`)
		} else {
			_, _ = database.ExecContext(ctx, `UPDATE settings SET value=?,`+"`group`"+`=?,is_public=?,updated_at=? WHERE id=?`, oldValue, oldGroup, oldPublic, oldUpdatedAt, settingID)
		}
	}()

	unique := time.Now().UnixNano()
	phone := fmt.Sprintf("62899%010d", unique%10_000_000_000)
	service := NewService(database)
	user, err := service.Register(ctx, RegisterInput{
		Name: "Queue Integration", Email: fmt.Sprintf("queue-%d@example.test", unique),
		Phone: phone, Password: "SafeTest123!", PasswordConf: "SafeTest123!",
	}, ClientMeta{IP: "127.0.0.1", UserAgent: "registration-queue-test"})
	if err != nil {
		t.Fatal(err)
	}
	key := "txn:account_registered:user:" + fmt.Sprint(user.ID)
	defer func() {
		var createdMessageID int64
		_ = database.QueryRowContext(ctx, `SELECT id FROM whatsapp_messages WHERE idempotency_key=?`, key).Scan(&createdMessageID)
		if createdMessageID != 0 {
			_, _ = database.ExecContext(ctx, `DELETE FROM jobs WHERE queue=? AND payload LIKE ?`, jobs.NotificationQueue, fmt.Sprintf("%%i:%d;%%", createdMessageID))
			_, _ = database.ExecContext(ctx, `DELETE FROM whatsapp_messages WHERE id=?`, createdMessageID)
		}
		_, _ = database.ExecContext(ctx, `DELETE FROM activity_logs WHERE subject_id=? AND (subject_type LIKE '%User%' OR causer_id=?)`, user.ID, user.ID)
		_, _ = database.ExecContext(ctx, `DELETE FROM users WHERE id=?`, user.ID)
	}()
	var messageID int64
	if err := database.QueryRowContext(ctx, `SELECT id FROM whatsapp_messages WHERE idempotency_key=? AND status='queued'`, key).Scan(&messageID); err != nil {
		t.Fatalf("registration WhatsApp ledger row missing: %v", err)
	}
	var count int
	var payload string
	if err := database.QueryRowContext(ctx, `SELECT COUNT(*),MAX(payload) FROM jobs WHERE queue=? AND payload LIKE ?`, jobs.NotificationQueue, fmt.Sprintf("%%i:%d;%%", messageID)).Scan(&count, &payload); err != nil {
		t.Fatal(err)
	}
	if count != 1 {
		t.Fatalf("queued WhatsApp jobs = %d, want exactly 1", count)
	}
	gotID, gotName, ok := jobs.MessageID([]byte(payload))
	if !ok || gotID != messageID || gotName != jobs.WhatsAppJobName {
		t.Fatalf("job payload does not identify the ledger row: id=%d name=%q ok=%v", gotID, gotName, ok)
	}

	// A duplicate notifier invocation must not dispatch a second job for the same idempotency key.
	attempted, notifyErr := service.notifyRegistration(ctx, user)
	if !attempted || notifyErr != nil {
		t.Fatalf("duplicate registration notifier attempted=%v error=%v", attempted, notifyErr)
	}
	if err := database.QueryRowContext(ctx, `SELECT COUNT(*) FROM jobs WHERE queue=? AND payload LIKE ?`, jobs.NotificationQueue, fmt.Sprintf("%%i:%d;%%", messageID)).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 1 {
		t.Fatalf("duplicate notification created %d jobs, want 1", count)
	}

	// A rollback after enqueue must roll back the queue row too.
	rollbackID := unique % 1_000_000_000
	rollbackErr := errors.New("intentional rollback")
	err = database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := jobs.EnqueueWhatsAppTx(ctx, tx, rollbackID, time.Now()); err != nil {
			return err
		}
		return rollbackErr
	})
	if !errors.Is(err, rollbackErr) {
		t.Fatalf("transaction error = %v, want rollback sentinel", err)
	}
	if err := database.QueryRowContext(ctx, `SELECT COUNT(*) FROM jobs WHERE queue=? AND payload LIKE ?`, jobs.NotificationQueue, fmt.Sprintf("%%i:%d;%%", rollbackID)).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != 0 {
		t.Fatalf("rolled-back transaction left %d queue jobs", count)
	}

}
