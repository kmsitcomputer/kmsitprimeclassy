package promo

import (
	"database/sql"
	"strconv"
	"time"
)

func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

// nullIntPtr renders a nullable integer (null when absent).
func nullIntPtr(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}

// nullIntPtr64 converts to *int64 for internal use.
func nullIntPtr64(v sql.NullInt64) *int64 {
	if !v.Valid {
		return nil
	}
	n := v.Int64
	return &n
}

// nullableInt renders *int64 for SQL.
func nullableInt(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

// nullableStrPtr renders *string for SQL, using NULL for nil or empty.
func nullableStrPtr(v *string) any {
	if v == nil || *v == "" {
		return nil
	}
	return *v
}

func strVal(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

func boolOr(v *bool, def bool) bool {
	if v == nil {
		return def
	}
	return *v
}

// decimalVal renders a monetary/percentage value as text so the DECIMAL column receives the exact value
// without a float round-trip.
func decimalVal(v *float64) any {
	if v == nil {
		return nil
	}
	return strconv.FormatFloat(*v, 'f', 2, 64)
}

// dateOnly renders a DATE column the way the reference does: these attributes carry a `date` cast, so Eloquent
// serialises them through Carbon as an ISO-8601 timestamp at midnight UTC — NOT as a bare "YYYY-MM-DD".
func dateOnly(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	for _, layout := range []string{"2006-01-02", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, v.String); err == nil {
			return t.UTC().Format("2006-01-02T15:04:05.000000Z")
		}
	}
	return v.String
}

// formatMySQLString renders a DATETIME/TIMESTAMP read as text in Eloquent's JSON form.
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
