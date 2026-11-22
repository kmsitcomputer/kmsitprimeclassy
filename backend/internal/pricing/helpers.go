package pricing

import (
	"database/sql"
	"strconv"
	"strings"
)

// parseDecimal converts a DECIMAL column read as text (or NULL) into a float64 without going through a
// locale-sensitive path. DECIMAL(14,2) values are exactly representable enough for the 2-decimal arithmetic
// this package performs, and every result is re-rounded through money.Round2.
func parseDecimal(v sql.NullString) (float64, error) {
	if !v.Valid || strings.TrimSpace(v.String) == "" {
		return 0, nil
	}
	return strconv.ParseFloat(strings.TrimSpace(v.String), 64)
}
