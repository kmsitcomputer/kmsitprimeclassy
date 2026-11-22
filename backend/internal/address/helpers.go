package address

import "strconv"

// formatDecimal renders a coordinate with 7 decimal places (the column's scale) without going through a
// float-to-string conversion that could drop trailing zeros.
func formatDecimal(v float64) string { return strconv.FormatFloat(v, 'f', 7, 64) }
