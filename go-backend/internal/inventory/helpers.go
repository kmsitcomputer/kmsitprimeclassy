package inventory

import (
	"database/sql"
	"strconv"
	"strings"
	"time"
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
