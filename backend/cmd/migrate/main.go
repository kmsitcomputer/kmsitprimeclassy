// Command migrate applies the Go service's own additive schema migrations.
//
// It is intentionally NOT a general-purpose migration tool: the schema of the business domain belongs to the
// Laravel migrations, which stay authoritative. This command only provisions the tables the Go runtime itself
// needs (currently: pc_go_sessions).
//
// Safety rules:
//   - every file is idempotent (CREATE TABLE IF NOT EXISTS), so a re-run is a no-op;
//   - applied files are recorded in `go_migrations` so the history is auditable;
//   - APP_ENV=production is refused unless --allow-production is passed explicitly (Owner-authorised step);
//   - it never drops or alters an existing table.
package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"embed"
	"encoding/hex"
	"flag"
	"fmt"
	"os"
	"sort"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

//go:embed sql/*.sql
var files embed.FS

func main() {
	dryRun := flag.Bool("dry-run", false, "list the migrations that would run, without executing")
	allowProd := flag.Bool("allow-production", false, "required to run with APP_ENV=production")
	flag.Parse()

	if err := run(*dryRun, *allowProd); err != nil {
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		os.Exit(1)
	}
}

func run(dryRun, allowProd bool) error {
	cfg, err := config.Load(config.FileGetter(".env"))
	if err != nil {
		return err
	}
	if cfg.Env == config.EnvProd && !allowProd {
		return fmt.Errorf("refusing to migrate APP_ENV=production without --allow-production (Owner-authorised step)")
	}

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()

	database, err := db.Open(ctx, cfg.DB)
	if err != nil {
		return err
	}
	defer func() { _ = database.Close() }()

	if err := ensureHistory(ctx, database); err != nil {
		return err
	}

	entries, err := files.ReadDir("sql")
	if err != nil {
		return err
	}
	names := make([]string, 0, len(entries))
	for _, e := range entries {
		if strings.HasSuffix(e.Name(), ".sql") {
			names = append(names, e.Name())
		}
	}
	sort.Strings(names)

	if len(names) == 0 {
		fmt.Println("no migrations found")
		return nil
	}

	applied := 0
	for _, name := range names {
		body, err := files.ReadFile("sql/" + name)
		if err != nil {
			return err
		}
		sum := sha256.Sum256(body)
		digest := hex.EncodeToString(sum[:])

		var existing string
		err = database.QueryRowContext(ctx, `SELECT checksum FROM go_migrations WHERE name = ?`, name).Scan(&existing)
		if err == nil {
			if existing != digest {
				return fmt.Errorf("migration %s was modified after being applied (checksum mismatch); create a new migration instead", name)
			}
			fmt.Printf("skip    %s (already applied)\n", name)
			continue
		}
		if err != sql.ErrNoRows {
			return err
		}

		if dryRun {
			fmt.Printf("dry-run %s\n", name)
			continue
		}
		for _, stmt := range splitStatements(string(body)) {
			if _, err := database.ExecContext(ctx, stmt); err != nil {
				return fmt.Errorf("%s: %w", name, err)
			}
		}
		if _, err := database.ExecContext(ctx,
			`INSERT INTO go_migrations (name, checksum, applied_at) VALUES (?, ?, ?)`,
			name, digest, time.Now().UTC()); err != nil {
			return err
		}
		fmt.Printf("apply   %s\n", name)
		applied++
	}
	fmt.Printf("done: %d applied, %d total\n", applied, len(names))
	return nil
}

func ensureHistory(ctx context.Context, database *db.DB) error {
	_, err := database.ExecContext(ctx, `CREATE TABLE IF NOT EXISTS go_migrations (
		name       VARCHAR(191) NOT NULL,
		checksum   CHAR(64)     NOT NULL,
		applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (name)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`)
	return err
}

// splitStatements splits a .sql file on semicolons that terminate a statement, ignoring comments. The
// migration files are simple DDL, so this deliberately stays minimal rather than growing a SQL parser.
func splitStatements(body string) []string {
	var (
		out      []string
		current  strings.Builder
		inSQuote bool
		inDQuote bool
		inLineC  bool
	)
	for i := 0; i < len(body); i++ {
		c := body[i]
		switch {
		case inLineC:
			if c == '\n' {
				inLineC = false
				current.WriteByte(c)
			}
			continue
		case inSQuote:
			current.WriteByte(c)
			if c == '\'' {
				inSQuote = false
			}
			continue
		case inDQuote:
			current.WriteByte(c)
			if c == '"' {
				inDQuote = false
			}
			continue
		}
		if c == '-' && i+1 < len(body) && body[i+1] == '-' {
			inLineC = true
			i++
			continue
		}
		switch c {
		case '\'':
			inSQuote = true
		case '"':
			inDQuote = true
		case ';':
			if stmt := strings.TrimSpace(current.String()); stmt != "" {
				out = append(out, stmt)
			}
			current.Reset()
			continue
		}
		current.WriteByte(c)
	}
	if stmt := strings.TrimSpace(current.String()); stmt != "" {
		out = append(out, stmt)
	}
	return out
}
