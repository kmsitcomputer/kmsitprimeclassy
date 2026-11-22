// Package db owns the MySQL/MariaDB connection and the transaction boundary.
//
// Deliberate decisions:
//   - No retry-on-deadlock helper. AGENTS.md §11 forbids "fixing" a 1213 with sleeps or by swallowing it;
//     the ordering that avoids the deadlock has to be correct instead. This package therefore just exposes
//     IsDeadlock/IsLockWaitTimeout so a caller can log or map the failure, and propagates everything else.
//   - Transactions are the unit of work. Any multi-row write goes through Tx so the partial-failure path is
//     structurally impossible to forget.
package db

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"

	"github.com/go-sql-driver/mysql"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
)

// DB wraps *sql.DB so handlers depend on this package rather than on database/sql directly.
type DB struct {
	*sql.DB
}

// Open connects and verifies the connection.
func Open(ctx context.Context, cfg config.DBConfig) (*DB, error) {
	connector, err := mysql.NewConnector(mustParseConfig(cfg))
	if err != nil {
		return nil, fmt.Errorf("mysql connector: %w", err)
	}
	pool := sql.OpenDB(connector)
	pool.SetMaxOpenConns(cfg.MaxOpenConns)
	pool.SetMaxIdleConns(cfg.MaxIdleConns)
	pool.SetConnMaxLifetime(cfg.ConnMaxLife)

	pingCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	if err := pool.PingContext(pingCtx); err != nil {
		_ = pool.Close()
		return nil, fmt.Errorf("database ping: %w", err)
	}
	return &DB{DB: pool}, nil
}

// mustParseConfig turns the application config into a driver config. It never panics: an unparseable DSN
// is a validation failure surfaced at boot.
func mustParseConfig(cfg config.DBConfig) *mysql.Config {
	c := mysql.NewConfig()
	c.User = cfg.User
	c.Passwd = cfg.Password
	c.Net = "tcp"
	c.Addr = fmt.Sprintf("%s:%d", cfg.Host, cfg.Port)
	c.DBName = cfg.Database
	c.ParseTime = true
	c.Loc = time.UTC
	c.Collation = "utf8mb4_unicode_ci"
	c.Params = map[string]string{"time_zone": "'+00:00'"}
	// The API always speaks JSON; binary protocol keeps decimals as text and preserves precision.
	c.InterpolateParams = false
	return c
}

// Tx runs fn inside a transaction, committing on success and rolling back on error or panic.
func (d *DB) Tx(ctx context.Context, fn func(context.Context, *sql.Tx) error) error {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin: %w", err)
	}
	defer func() {
		if p := recover(); p != nil {
			_ = tx.Rollback()
			panic(p)
		}
	}()
	if err := fn(ctx, tx); err != nil {
		if rbErr := tx.Rollback(); rbErr != nil && !errors.Is(rbErr, sql.ErrTxDone) {
			return fmt.Errorf("%w (rollback failed: %v)", err, rbErr)
		}
		return err
	}
	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit: %w", err)
	}
	return nil
}

// mysqlErrNumber extracts the server error number from a driver error.
func mysqlErrNumber(err error) uint16 {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		return me.Number
	}
	return 0
}

// IsDeadlock reports MySQL error 1213 (deadlock found when trying to get lock).
func IsDeadlock(err error) bool { return mysqlErrNumber(err) == 1213 }

// IsLockWaitTimeout reports MySQL error 1205.
func IsLockWaitTimeout(err error) bool { return mysqlErrNumber(err) == 1205 }

// IsDuplicateKey reports MySQL error 1062 (used to detect a replayed idempotency key).
func IsDuplicateKey(err error) bool { return mysqlErrNumber(err) == 1062 }

// IsNoSuchTable reports MySQL error 1146 — the shape the installer relies on for a fresh, unmigrated
// database (Laravel handles this through SafeSchema; Go treats it as "feature not installed yet").
func IsNoSuchTable(err error) bool { return mysqlErrNumber(err) == 1146 }

// IsMissingSchema reports errors that mean "this deployment has no schema yet": either no such table, or
// no database selected/unknown database. Endpoints that are reachable before the installer has run
// degrade to an empty payload instead of a 500, exactly like SafeSchema::hasTable does.
func IsMissingSchema(err error) bool {
	switch mysqlErrNumber(err) {
	case 1146, 1049, 1046:
		return true
	}
	return false
}

// NullString converts sql.NullString into (string, bool).
func NullString(v sql.NullString) (string, bool) { return v.String, v.Valid }

// NullInt64 converts sql.NullInt64 into (*int64).
func NullInt64(v sql.NullInt64) *int64 {
	if !v.Valid {
		return nil
	}
	n := v.Int64
	return &n
}

// NullFloat64 converts sql.NullFloat64 into (*float64).
func NullFloat64(v sql.NullFloat64) *float64 {
	if !v.Valid {
		return nil
	}
	f := v.Float64
	return &f
}

// NullTime converts sql.NullTime into (*time.Time) in UTC.
func NullTime(v sql.NullTime) *time.Time {
	if !v.Valid {
		return nil
	}
	t := v.Time.UTC()
	return &t
}
