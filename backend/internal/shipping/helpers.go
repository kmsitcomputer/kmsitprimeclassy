package shipping

import (
	"database/sql"
	"strconv"
	"strings"
)

// parseNullableFloat converts a DECIMAL column read as text into the *float64 the payload carries. The
// reference casts with `(float)` and only when the column is non-null, so an absent coordinate stays null.
func parseNullableFloat(v sql.NullString) *float64 {
	if !v.Valid || strings.TrimSpace(v.String) == "" {
		return nil
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(v.String), 64)
	if err != nil {
		return nil
	}
	return &f
}
