package inventory

import (
	"context"
	"database/sql"
	"errors"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// nullStr renders a nullable string the way Eloquent's JSON serialisation does (null, never "").
func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

// nullInt reports whether the value is present, for lookups.
func nullInt(v sql.NullInt64) (int64, bool) {
	if !v.Valid {
		return 0, false
	}
	return v.Int64, true
}

// nullIntPtr renders a nullable integer as a JSON-friendly pointer (null when absent).
func nullIntPtr(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}

// formatTime renders a nullable timestamp exactly as Eloquent serialises Carbon to JSON
// (UTC, ISO-8601 with microseconds). A fixed layout matters: the comparison harness diffs these strings.
func formatTime(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// formatMySQLString renders a DATETIME/TIMESTAMP read as text (CAST(... AS CHAR)) in Eloquent's JSON form.
func formatMySQLString(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	for _, layout := range []string{"2006-01-02 15:04:05.000000", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, v.String); err == nil {
			return t.UTC().Format("2006-01-02T15:04:05.000000Z")
		}
	}
	return v.String
}

// placeholders builds "?,?,?" for an IN clause.
func placeholders(n int) string {
	if n <= 0 {
		return ""
	}
	return strings.TrimSuffix(strings.Repeat("?,", n), ",")
}

func itoa(v int64) string { return strconv.FormatInt(v, 10) }

// planIf applies the factory-plan switch (the plan quantity only counts when the agent enabled it).
func planIf(enabled bool, plan int) int {
	if enabled {
		return plan
	}
	return 0
}

// strPtr turns a nullable column into a *string.
func strPtr(v sql.NullString) *string {
	if !v.Valid {
		return nil
	}
	s := v.String
	return &s
}

// nullInt64Ptr converts a nullable column into *int64.
func nullInt64Ptr(v sql.NullInt64) *int64 {
	if !v.Valid {
		return nil
	}
	n := v.Int64
	return &n
}

// nullableInt renders *int64 for SQL (NULL when absent).
func nullableInt(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

// nullableStrPtr renders *string for SQL (NULL when absent).
func nullableStrPtr(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

// nullableStrMember renders a plain string, using NULL for the empty value (stock_movements.stock_type).
func nullableStrMember(v string) any {
	if v == "" {
		return nil
	}
	return v
}

// stringOrEmpty renders a scanned nullable timestamp column verbatim (Eloquent's JSON form).
func stringOrEmpty(v sql.NullString) string {
	if !v.Valid {
		return ""
	}
	return v.String
}

// strOrNil returns the value or nil so an omitted relation column serialises as null.
func strOrNil(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

// subLocationBrief returns (name, code) for a sub-location id (nil-safe).
func subLocationBrief(ctx context.Context, d *db.DB, id *int64) (*string, *string, error) {
	if id == nil {
		return nil, nil, nil
	}
	var code, name string
	err := d.QueryRowContext(ctx, `SELECT code, name FROM warehouse_sub_locations WHERE id = ?`, *id).Scan(&code, &name)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil, nil
	}
	if err != nil {
		return nil, nil, err
	}
	return &name, &code, nil
}

// userBrief renders `users:id,name` (nil-safe).
func userBrief(ctx context.Context, d *db.DB, id *int64) (map[string]any, error) {
	if id == nil {
		return nil, nil
	}
	var name string
	err := d.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, *id).Scan(&name)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"id": *id, "name": name}, nil
}

// strPtrVal returns a pointer to a plain string (for SQL columns that take *string).
func strPtrVal(v string) *string { return &v }
