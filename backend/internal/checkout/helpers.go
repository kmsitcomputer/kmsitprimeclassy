package checkout

import (
	"database/sql"
	"strconv"
	"strings"
)

// parseNullableFloat converts a DECIMAL column read as text into a float64, treating NULL/garbage as zero.
// Monetary columns are never NULL in practice; this exists so a legacy row cannot crash the preview.
func parseNullableFloat(v sql.NullString) float64 {
	if !v.Valid || strings.TrimSpace(v.String) == "" {
		return 0
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(v.String), 64)
	if err != nil {
		return 0
	}
	return f
}

// nullStrPtr converts a nullable text column into the *string the order snapshot persists (NULL stays NULL, so an
// unknown region name is never rendered as an empty string).
func nullStrPtr(v sql.NullString) *string {
	if !v.Valid {
		return nil
	}
	s := v.String
	return &s
}
