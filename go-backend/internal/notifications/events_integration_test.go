package notifications

import (
	"context"
	"database/sql"
	"os"
	"path/filepath"
	"reflect"
	"strconv"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

func TestRecordOrderCreatedUsesBranchRecipientsAndIsIdempotent(t *testing.T) {
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

	ctx := context.Background()
	lockConn, err := database.Conn(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer lockConn.Close()
	var acquired sql.NullInt64
	if err := lockConn.QueryRowContext(ctx, `SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing',0)`).Scan(&acquired); err != nil {
		t.Fatal(err)
	}
	if !acquired.Valid || acquired.Int64 != 1 {
		t.Fatal("test database is already owned by another runner")
	}
	defer func() {
		_, _ = lockConn.ExecContext(ctx, `SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing')`)
	}()

	conn, err := database.Conn(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	for _, statement := range []string{
		`CREATE TEMPORARY TABLE roles (id BIGINT PRIMARY KEY,slug VARCHAR(50) NOT NULL)`,
		`CREATE TEMPORARY TABLE users (id BIGINT PRIMARY KEY,role_id BIGINT NOT NULL,agent_id BIGINT NULL)`,
		`CREATE TEMPORARY TABLE admin_notifications (
			dedupe_key VARCHAR(191) PRIMARY KEY,recipient_user_id BIGINT NOT NULL,agent_id BIGINT NULL,
			type VARCHAR(60) NOT NULL,severity VARCHAR(10) NOT NULL,title VARCHAR(150) NOT NULL,body VARCHAR(500) NOT NULL,
			order_id BIGINT NULL,subject_type VARCHAR(40) NULL,subject_id BIGINT NULL,created_at DATETIME(6),updated_at DATETIME(6))`,
	} {
		if _, err := conn.ExecContext(ctx, statement); err != nil {
			t.Fatal(err)
		}
	}
	for _, row := range []struct {
		id   int
		slug string
	}{{1, "super_admin"}, {2, "agen"}, {3, "admin"}} {
		if _, err := conn.ExecContext(ctx, `INSERT INTO roles (id,slug) VALUES (?,?)`, row.id, row.slug); err != nil {
			t.Fatal(err)
		}
	}
	for _, row := range []struct{ id, role, branch int }{{10, 2, 10}, {11, 3, 10}, {12, 3, 20}, {1, 1, 10}} {
		if _, err := conn.ExecContext(ctx, `INSERT INTO users (id,role_id,agent_id) VALUES (?,?,?)`, row.id, row.role, row.branch); err != nil {
			t.Fatal(err)
		}
	}
	tx, err := conn.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := RecordOrderCreated(ctx, tx, 77, 10, "D-1126-00001", 1234567.5); err != nil {
		t.Fatal(err)
	}
	if err := RecordOrderCreated(ctx, tx, 77, 10, "D-1126-00001", 1234567.5); err != nil {
		t.Fatal(err)
	}
	if err := tx.Commit(); err != nil {
		t.Fatal(err)
	}
	rows, err := conn.QueryContext(ctx, `SELECT recipient_user_id,type,severity,title,body,dedupe_key FROM admin_notifications ORDER BY recipient_user_id`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	var recipients []int64
	for rows.Next() {
		var id int64
		var eventType, severity, title, body, key string
		if err := rows.Scan(&id, &eventType, &severity, &title, &body, &key); err != nil {
			t.Fatal(err)
		}
		recipients = append(recipients, id)
		if eventType != "order.created" || severity != "info" || title != "Pesanan baru" || body != "Pesanan D-1126-00001 · Total Rp 1.234.568" || key != "order.created:order:77:"+stringInt(id) {
			t.Fatalf("unexpected order-created notification: id=%d type=%q severity=%q title=%q body=%q key=%q", id, eventType, severity, title, body, key)
		}
	}
	if err := rows.Err(); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(recipients, []int64{10, 11}) {
		t.Fatalf("recipients=%v, want branch Agent/Admin [10 11]", recipients)
	}
}

func stringInt(value int64) string {
	return strconv.FormatInt(value, 10)
}
