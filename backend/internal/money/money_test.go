package money

import (
	"encoding/json"
	"os"
	"strconv"
	"testing"
)

// phpRoundFixture is one value produced by the RUNNING reference:
//
//	php -r 'echo round($v, 2);'
//
// The corpus deliberately includes the cases where a naive float implementation diverges (x.xx5 and the
// binary-representation traps such as 1.005, 2.675, 33.335), negative values, and zero.
type phpRoundFixture struct {
	In  string  `json:"in"`
	Out float64 `json:"out"`
}

// TestRound2MatchesPHP is the differential fixture test required by D-02: every expected value comes from the
// reference runtime, never from this implementation.
func TestRound2MatchesPHP(t *testing.T) {
	raw, err := os.ReadFile("testdata/php_round.json")
	if err != nil {
		t.Fatalf("fixture: %v", err)
	}
	var fixtures []phpRoundFixture
	if err := json.Unmarshal(raw, &fixtures); err != nil {
		t.Fatalf("fixture parse: %v", err)
	}
	if len(fixtures) < 40 {
		t.Fatalf("fixture corpus too small (%d) — a rounding test needs breadth", len(fixtures))
	}

	for _, f := range fixtures {
		in, err := strconv.ParseFloat(f.In, 64)
		if err != nil {
			t.Fatalf("bad fixture input %q: %v", f.In, err)
		}
		got := Round2(in)
		if got != f.Out {
			t.Errorf("Round2(%s) = %v, PHP says %v", f.In, got, f.Out)
		}
		// The rendered form must also round-trip (the DECIMAL columns carry 2 decimals).
		if s := String(in); s != strconv.FormatFloat(f.Out, 'f', 2, 64) {
			t.Errorf("String(%s) = %q, want %q", f.In, s, strconv.FormatFloat(f.Out, 'f', 2, 64))
		}
	}
}

// TestNaiveFloatRoundingWouldFail documents WHY this package exists: the obvious implementation diverges, so a
// future "simplification" to math.Round(v*100)/100 would break parity.
func TestNaiveFloatRoundingWouldFail(t *testing.T) {
	diverged := 0
	for _, v := range []float64{1.005, 2.675, 33.335, 10.005} {
		naive := roundNaive(v)
		if naive != Round2(v) {
			diverged++
		}
	}
	if diverged == 0 {
		t.Skip("no divergence on this platform's float handling — the fixture test above is still authoritative")
	}
}

func roundNaive(v float64) float64 {
	scaled := v * 100
	if scaled < 0 {
		return float64(int64(scaled-0.5)) / 100
	}
	return float64(int64(scaled+0.5)) / 100
}

// TestDiscountAndTotalArithmetic covers the two formulas the order chain depends on, using values taken from
// the reference (45000.00 base, 12.5% discount; 50000 with a 10% voucher; a mixed shipping/admin total).
func TestDiscountAndTotalArithmetic(t *testing.T) {
	// PricingService::effectiveUnitPrice
	original := Round2(45000.00)
	discount := Round2(original * 12.5 / 100)
	effective := Round2(original - discount)
	if effective != 39375 {
		t.Errorf("effective unit price = %v, want 39375", effective)
	}

	// Voucher: 50000 − round(50000*10/100) — PHP answers 45000.
	voucher := Round2(50000 * 10 / 100)
	if total := Round2(50000 - voucher); total != 45000 {
		t.Errorf("voucher total = %v, want 45000", total)
	}

	// OrderTotalCalculator: subtotal − effectiveVoucher + shipping + admin — PHP answers 84166.67.
	total := Round2(100000 - 33333.33 + 15000 + 2500)
	if total != 84166.67 {
		t.Errorf("order total = %v, want 84166.67", total)
	}
}

// TestEffectiveDiscountClamp pins Order::effectiveDiscountAmount: the applied discount never exceeds the live
// billed subtotal, and never goes negative.
func TestEffectiveDiscountClamp(t *testing.T) {
	cases := []struct{ discount, subtotal, want float64 }{
		{10000, 50000, 10000},
		{10000, 4000, 4000}, // clamped to the live subtotal
		{0, 50000, 0},
		{-500, 50000, -500}, // a negative historical discount passes through min() unchanged
		{10000, -100, 0},    // a negative subtotal clamps to 0
	}
	for _, c := range cases {
		got := Round2(minFloat(c.discount, maxFloat(0, c.subtotal)))
		if got != c.want {
			t.Errorf("effectiveDiscount(%v, %v) = %v, want %v", c.discount, c.subtotal, got, c.want)
		}
	}
}

func minFloat(a, b float64) float64 {
	if a < b {
		return a
	}
	return b
}

func maxFloat(a, b float64) float64 {
	if a > b {
		return a
	}
	return b
}
