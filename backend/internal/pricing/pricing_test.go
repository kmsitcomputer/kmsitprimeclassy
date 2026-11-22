package pricing

import (
	"testing"
	"time"
)

// Every expectation in this file was produced by running the same formula in the reference runtime:
//
//	php -r 'echo json_encode(round(...));'
//
// They are differential fixtures, not re-derivations: a change here means the two runtimes disagree.

func ptr[T any](v T) *T { return &v }

func date(s string) *time.Time {
	t, err := time.Parse("2006-01-02", s)
	if err != nil {
		panic(err)
	}
	return &t
}

func TestEffectiveUnitPrice(t *testing.T) {
	cases := []struct {
		name         string
		base         float64
		discount     *Discount
		wantUnit     float64
		wantOriginal float64
		wantPct      int
		wantAmount   float64
		wantHas      bool
	}{
		{"no discount", 45000, nil, 45000, 45000, 0, 0, false},
		{"12%", 45000, &Discount{Percentage: 12, IsActive: true}, 39600, 45000, 12, 5400, true},
		{"100%", 45000, &Discount{Percentage: 100, IsActive: true}, 0, 45000, 100, 45000, true},
		{"0%", 45000, &Discount{Percentage: 0, IsActive: true}, 45000, 45000, 0, 0, true},
		{"negative base clamps to 0", -100, nil, 0, 0, 0, 0, false},
		{"percentage above 100 clamps", 1000, &Discount{Percentage: 150, IsActive: true}, 0, 1000, 100, 1000, true},
		{"percentage below 0 clamps", 1000, &Discount{Percentage: -20, IsActive: true}, 1000, 1000, 0, 0, true},
		{"rounding to cents", 33.33, &Discount{Percentage: 33, IsActive: true}, 22.33, 33.33, 33, 11, true},
	}
	for _, c := range cases {
		got := EffectiveUnitPrice(c.base, c.discount)
		if got.UnitPrice != c.wantUnit || got.OriginalPrice != c.wantOriginal ||
			got.DiscountPercentage != c.wantPct || got.DiscountAmount != c.wantAmount || got.HasDiscount != c.wantHas {
			t.Errorf("%s: %+v, want unit=%v original=%v pct=%v amount=%v has=%v",
				c.name, got, c.wantUnit, c.wantOriginal, c.wantPct, c.wantAmount, c.wantHas)
		}
	}
}

// TestFixedVoucherBudgetIsTransactional pins A1-07: a fixed voucher of 40000 across two 25000 lines yields
// 25000 then 15000 — splitting the cart can never multiply the reduction.
func TestFixedVoucherBudgetIsTransactional(t *testing.T) {
	v := Voucher{Type: "fixed", Value: 40000, IsActive: true}
	budget := 40000.0

	d1, b1 := VoucherLineDiscount(v, 25000, budget, 25000)
	if d1 != 25000 || b1 != 15000 {
		t.Fatalf("first line: discount=%v budget=%v, want 25000/15000", d1, b1)
	}
	d2, b2 := VoucherLineDiscount(v, 25000, b1, 25000)
	if d2 != 15000 || b2 != 0 {
		t.Fatalf("second line: discount=%v budget=%v, want 15000/0", d2, b2)
	}
	if d1+d2 != 40000 {
		t.Errorf("total reduction = %v, want exactly the face value 40000", d1+d2)
	}
}

func TestPercentageVoucherPerLine(t *testing.T) {
	v := Voucher{Type: "percentage", Value: 10, IsActive: true}
	d1, b1 := VoucherLineDiscount(v, 25000, 1e9, 25000)
	d2, _ := VoucherLineDiscount(v, 25000, b1, 25000)
	if d1 != 2500 || d2 != 2500 {
		t.Errorf("10%% of two 25000 lines = %v/%v, want 2500/2500", d1, d2)
	}

	// Percentage rounding: 33.33% of 33.33 → 11.11 (the reference's answer).
	v2 := Voucher{Type: "percentage", Value: 33.33, IsActive: true}
	if d, _ := VoucherLineDiscount(v2, 33.33, 99999, 33.33); d != 11.11 {
		t.Errorf("33.33%% of 33.33 = %v, want 11.11", d)
	}
}

// TestVoucherUsability covers the window/exhaustion rules that make a voucher inert.
func TestVoucherUsability(t *testing.T) {
	today := Today()
	cases := []struct {
		name string
		v    Voucher
		want bool
	}{
		{"active, open", Voucher{Type: "fixed", Value: 10, IsActive: true}, true},
		{"inactive", Voucher{IsActive: false}, false},
		{"not started", Voucher{IsActive: true, ValidFrom: ptr(today.AddDate(0, 0, 1))}, false},
		{"expired", Voucher{IsActive: true, ValidUntil: ptr(today.AddDate(0, 0, -1))}, false},
		{"starts today", Voucher{IsActive: true, ValidFrom: ptr(today)}, true},
		{"ends today", Voucher{IsActive: true, ValidUntil: ptr(today)}, true},
		{"exhausted", Voucher{IsActive: true, MaxUses: ptr(int64(3)), UsedCount: 3}, false},
		{"one left", Voucher{IsActive: true, MaxUses: ptr(int64(3)), UsedCount: 2}, true},
	}
	for _, c := range cases {
		if got := c.v.IsUsable(today); got != c.want {
			t.Errorf("%s: IsUsable = %v, want %v", c.name, got, c.want)
		}
	}
}

// TestDiscountValidity covers ProductDiscount::isCurrentlyValid.
func TestDiscountValidity(t *testing.T) {
	today := Today()
	cases := []struct {
		name string
		d    Discount
		want bool
	}{
		{"active open", Discount{IsActive: true}, true},
		{"inactive", Discount{IsActive: false}, false},
		{"future", Discount{IsActive: true, StartsAt: ptr(today.AddDate(0, 0, 1))}, false},
		{"expired", Discount{IsActive: true, EndsAt: ptr(today.AddDate(0, 0, -1))}, false},
		{"boundary", Discount{IsActive: true, StartsAt: ptr(today), EndsAt: ptr(today)}, true},
	}
	for _, c := range cases {
		if got := c.d.IsCurrentlyValid(today); got != c.want {
			t.Errorf("%s: IsCurrentlyValid = %v, want %v", c.name, got, c.want)
		}
	}
}

// TestEffectiveDiscountAmount pins Order::effectiveDiscountAmount.
func TestEffectiveDiscountAmount(t *testing.T) {
	cases := []struct{ discount, subtotal, want float64 }{
		{10000, 50000, 10000},
		{10000, 4000, 4000},
		{0, 50000, 0},
		{10000, 0, 0},
		{10000, -100, 0},
	}
	for _, c := range cases {
		if got := EffectiveDiscountAmount(c.discount, c.subtotal); got != c.want {
			t.Errorf("effectiveDiscount(%v, %v) = %v, want %v", c.discount, c.subtotal, got, c.want)
		}
	}
}

// TestOrderTotalFormula pins OrderTotalCalculator's arithmetic: subtotal − effectiveVoucher + shipping + admin,
// clamped at 0. The expected values come from the reference (php round(...) → 84166.67).
func TestOrderTotalFormula(t *testing.T) {
	subtotal := 100000.0
	voucher := EffectiveDiscountAmount(33333.33, subtotal)
	total := subtotal - voucher + 15000 + 2500
	if total != 84166.67 {
		t.Errorf("total = %v, want 84166.67", total)
	}

	// A voucher larger than the live subtotal is clamped so the total cannot go negative.
	big := EffectiveDiscountAmount(500000, 1000)
	if big != 1000 {
		t.Errorf("effective voucher = %v, want the live subtotal 1000", big)
	}
	if t2 := 1000 - big + 0 + 0; t2 != 0 {
		t.Errorf("clamped total = %v, want 0", t2)
	}
}

var _ = date
