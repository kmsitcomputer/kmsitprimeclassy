// Package money is the single place monetary arithmetic is rounded.
//
// WHY THIS EXISTS
// The reference implementation computes money as PHP floats and applies `round($v, 2)` at specific points
// (PricingService, OrderTotalCalculator, the fee/commission writers). Reproducing the RESULT requires
// reproducing that rounding semantics exactly — a naive `math.Round(v*100)/100` does NOT: the float64 nearest
// to 1.005 is 1.00499999999999989…, so multiplying by 100 and rounding gives 1.00, while PHP answers 1.01.
//
// HOW IT WORKS
// Round2 renders the value with Go's shortest round-trip formatting (the same digits a human writes:
// "1.005", "33.335", "0.30000000000000004") and then rounds that DECIMAL STRING half-away-from-zero with
// integer arithmetic. No binary floating point participates in the rounding step, so the outcome is exact and
// identical to PHP 8.3's round($v, 2) for the value ranges this application uses.
//
// This is verified against a PHP-generated corpus (money_test.go / testdata/php_round.json), not assumed:
// every value in that fixture was produced by the running reference.
package money

import (
	"errors"
	"strconv"
	"strings"
)

// Amount is a rounded monetary value in the application's currency units. The DB column is DECIMAL(12,2) /
// DECIMAL(14,2), so an Amount is always representable exactly as a 2-decimal string.
type Amount float64

// ErrOverflow is returned when a value is too large to round at this precision (PHP errors the same way).
var ErrOverflow = errors.New("money: value too large to round")

// Round2 reproduces PHP's round($value, 2).
func Round2(v float64) float64 {
	out, err := roundString(strconv.FormatFloat(v, 'g', -1, 64), 2)
	if err != nil {
		// PHP would raise on an absurd magnitude; returning the input untouched is the least surprising
		// degenerate behaviour and is never reachable with DECIMAL(14,2) inputs.
		return v
	}
	return out
}

// Round2Amount is Round2 with the Amount type, for readability at call sites.
func Round2Amount(v Amount) Amount { return Amount(Round2(float64(v))) }

// String renders the value with exactly two decimals, which is what the DECIMAL columns and the API payloads
// carry (Eloquent serialises `decimal:2` casts as "45000.00").
func String(v float64) string {
	return strconv.FormatFloat(Round2(v), 'f', 2, 64)
}

// roundString rounds a decimal string to `places` decimals, half away from zero.
//
// The input is the shortest round-trip form of a float64, i.e. it contains only an optional sign, digits, an
// optional fraction and an optional exponent (FormatFloat('g') uses exponents for very large/small values).
func roundString(s string, places int) (float64, error) {
	neg := false
	if strings.HasPrefix(s, "-") {
		neg = true
		s = s[1:]
	} else if strings.HasPrefix(s, "+") {
		s = s[1:]
	}

	mantissa := s
	exp := 0
	if i := strings.IndexAny(s, "eE"); i >= 0 {
		mantissa = s[:i]
		n, err := strconv.Atoi(s[i+1:])
		if err != nil {
			return 0, err
		}
		exp = n
	}

	intPart := mantissa
	fracPart := ""
	if i := strings.IndexByte(mantissa, '.'); i >= 0 {
		intPart = mantissa[:i]
		fracPart = mantissa[i+1:]
	}

	// Normalise into a digit string + a decimal exponent: value = digits × 10^exp10.
	digits := intPart + fracPart
	exp10 := exp - len(fracPart)
	if digits == "" {
		digits = "0"
	}

	// We want the value rounded at the 2nd decimal place, i.e. keep digits up to exponent -places.
	cut := len(digits) + exp10 + places // number of digits to keep
	if cut < 0 {
		// Everything rounds away to zero.
		return signed(0, neg), nil
	}
	if cut >= len(digits) {
		// No rounding needed: shift back exactly.
		return scaleToFloat(digits, exp10, neg)
	}

	kept := digits[:cut]
	rest := digits[cut:]

	// Half away from zero: look only at the first dropped digit and the sticky remainder.
	roundUp := false
	if rest[0] >= '5' {
		if rest[0] > '5' {
			roundUp = true
		} else {
			// Exactly 5 followed by nothing else → half → away from zero. A non-zero remainder keeps it a
			// clear "greater than half".
			roundUp = true
		}
	}

	if kept == "" {
		kept = "0"
	}
	// Increment the kept digits as a decimal string (no integer overflow for long inputs).
	if roundUp {
		kept = incrementDecimal(kept)
	}

	// `kept` holds the leading `cut` digits, so its scale is the ORIGINAL exponent shifted by the number of
	// digits dropped: value = kept × 10^(exp10 + len(rest)).
	return scaleToFloat(kept, exp10+len(rest), neg)
}

// incrementDecimal adds one to a decimal digit string.
func incrementDecimal(digits string) string {
	buf := []byte(digits)
	for i := len(buf) - 1; i >= 0; i-- {
		if buf[i] < '9' {
			buf[i]++
			return string(buf)
		}
		buf[i] = '0'
	}
	return "1" + string(buf)
}

// scaleToFloat converts digits × 10^exp10 to a float64, applying the sign.
func scaleToFloat(digits string, exp10 int, neg bool) (float64, error) {
	// Build a canonical decimal string and let strconv parse it once — a single correctly-rounded conversion,
	// which is exactly how PHP ends up with 1234.57 rather than 1234.5699999999999.
	var sb strings.Builder
	if neg {
		sb.WriteByte('-')
	}
	if exp10 >= 0 {
		sb.WriteString(digits)
		sb.WriteString(strings.Repeat("0", exp10))
	} else {
		need := -exp10
		if need < len(digits) {
			sb.WriteString(digits[:len(digits)-need])
			sb.WriteByte('.')
			sb.WriteString(digits[len(digits)-need:])
		} else {
			sb.WriteString("0.")
			sb.WriteString(strings.Repeat("0", need-len(digits)))
			sb.WriteString(digits)
		}
	}
	v, err := strconv.ParseFloat(sb.String(), 64)
	if err != nil {
		return 0, ErrOverflow
	}
	return v, nil
}

func signed(v float64, neg bool) float64 {
	if neg {
		return -v
	}
	return v
}
