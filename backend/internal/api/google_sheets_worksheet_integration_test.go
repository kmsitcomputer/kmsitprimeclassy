package api

import (
	"context"
	"database/sql"
	"os"
	"path/filepath"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

func TestGoogleSheetsWorksheetProjectionUsesLaravelColumns(t *testing.T) {
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

	server := &Server{database: database}
	records, err := server.sheetsWorksheetQuery(context.Background(), sql.NullInt64{}, map[string]any{}, 1)
	if err != nil {
		t.Fatalf("worksheet projection query: %v", err)
	}
	if len(records) > 2 {
		t.Fatalf("query returned %d rows with maxRows=1; want <=2 including overflow sentinel", len(records))
	}
	for _, record := range records {
		if len(record) != 48 {
			t.Fatalf("worksheet projection columns=%d want=48", len(record))
		}
		for _, field := range []string{"no", "invoice_no", "update_money_position", "settlement_note", "settlement_total", "order_status"} {
			if _, ok := record[field]; !ok {
				t.Errorf("worksheet projection omitted %q", field)
			}
		}
	}
}
