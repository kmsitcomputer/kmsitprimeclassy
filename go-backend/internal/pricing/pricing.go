// Package pricing is the canonical line pricing + order-total arithmetic.
//
// It is the Go port of Services/Pricing/PricingService and Services/Order/OrderTotalCalculator. Both are pure
// arithmetic over already-scoped rows, which is why they are ported first: `POST /orders`, the fulfilment
// service and every report that recomputes a total depend on them, and porting them FIRST means the order chain
// can be written against a verified money boundary instead of re-deriving one inline.
//
// Money semantics: every rounding goes through internal/money.Round2, which is verified against a corpus
// produced by the running reference (see money_test.go). No binary-float rounding happens anywhere here.
package pricing

import (
	"context"
	"database/sql"
	"errors"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// Today is the application's `today()` — config('app.timezone') is UTC, so a DATE comparison uses the UTC date.
func Today() time.Time {
	now := time.Now().UTC()
	return time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC)
}

// Discount is one product_discounts row (only the fields the pricing rules read).
type Discount struct {
	ID          int64
	Percentage  int
	IsActive    bool
	ProductID   *int64
	VariationID *int64
	StartsAt    *time.Time
	EndsAt      *time.Time
}

// IsCurrentlyValid is ProductDiscount::isCurrentlyValid: active AND inside [starts_at, ends_at].
func (d Discount) IsCurrentlyValid(today time.Time) bool {
	if !d.IsActive {
		return false
	}
	if d.StartsAt != nil && today.Before(*d.StartsAt) {
		return false
	}
	if d.EndsAt != nil && today.After(*d.EndsAt) {
		return false
	}
	return true
}

// Voucher is one vouchers row (the fields the usability rule reads).
type Voucher struct {
	ID         int64
	Type       string // percentage | fixed
	Value      float64
	IsActive   bool
	ValidFrom  *time.Time
	ValidUntil *time.Time
	MaxUses    *int64
	UsedCount  int64
}

// IsUsable is Voucher::isUsable: active, inside the window, and not exhausted.
func (v Voucher) IsUsable(today time.Time) bool {
	if !v.IsActive {
		return false
	}
	if v.ValidFrom != nil && today.Before(*v.ValidFrom) {
		return false
	}
	if v.ValidUntil != nil && today.After(*v.ValidUntil) {
		return false
	}
	if v.MaxUses != nil && v.UsedCount >= *v.MaxUses {
		return false
	}
	return true
}

// DiscountQuery scopes a discount lookup by agent.
type queryer interface {
	QueryContext(ctx context.Context, query string, args ...any) (*sql.Rows, error)
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
}

// ActiveDiscount is PricingService::activeDiscount.
//
// Precedence (audit A1-09): a variation-targeted discount wins over a product-targeted one, and the validity
// window is checked BEFORE the precedence decision — an expired/future row must never mask an older valid row.
// Among equally-applicable rows the newest id wins.
func ActiveDiscount(ctx context.Context, q queryer, agentID int64, productID, variationID *int64) (*Discount, error) {
	today := Today()

	load := func(where string, args []any) ([]Discount, error) {
		rows, err := q.QueryContext(ctx, `SELECT id, percentage, is_active, product_id, product_variation_id,
				CAST(starts_at AS CHAR), CAST(ends_at AS CHAR)
			FROM product_discounts WHERE agent_id = ? AND is_active = 1 AND `+where, args...)
		if err != nil {
			if db.IsMissingSchema(err) {
				return nil, nil
			}
			return nil, err
		}
		defer rows.Close()
		var out []Discount
		for rows.Next() {
			var (
				d                Discount
				isActive         int
				pid, vid         sql.NullInt64
				startsAt, endsAt sql.NullString
			)
			if err := rows.Scan(&d.ID, &d.Percentage, &isActive, &pid, &vid, &startsAt, &endsAt); err != nil {
				return nil, err
			}
			d.IsActive = isActive == 1
			d.ProductID = nullIntPtr(pid)
			d.VariationID = nullIntPtr(vid)
			d.StartsAt = parseDate(startsAt)
			d.EndsAt = parseDate(endsAt)
			out = append(out, d)
		}
		return out, rows.Err()
	}

	newestValid := func(list []Discount) *Discount {
		var best *Discount
		for i := range list {
			if !list[i].IsCurrentlyValid(today) {
				continue
			}
			if best == nil || list[i].ID > best.ID {
				best = &list[i]
			}
		}
		return best
	}

	if variationID != nil {
		rows, err := load("product_variation_id = ?", []any{agentID, *variationID})
		if err != nil {
			return nil, err
		}
		if best := newestValid(rows); best != nil {
			return best, nil
		}
	}
	if productID != nil {
		rows, err := load("product_id = ? AND product_variation_id IS NULL", []any{agentID, *productID})
		if err != nil {
			return nil, err
		}
		if best := newestValid(rows); best != nil {
			return best, nil
		}
	}
	return nil, nil
}

// UnitPrice is PricingService::effectiveUnitPrice's result.
type UnitPrice struct {
	UnitPrice          float64 `json:"unit_price"`
	OriginalPrice      float64 `json:"original_price"`
	DiscountPercentage int     `json:"discount_percentage"`
	DiscountAmount     float64 `json:"discount_amount"`
	HasDiscount        bool    `json:"has_discount"`
}

// EffectiveUnitPrice is PricingService::effectiveUnitPrice.
//
// The percentage is clamped to [0,100] and the effective price to >= 0, so a bad row can never produce a
// negative line or a discount above the original price.
func EffectiveUnitPrice(basePrice float64, discount *Discount) UnitPrice {
	original := maxFloat(0, money.Round2(basePrice))
	percentage := 0
	if discount != nil {
		percentage = discount.Percentage
		if percentage < 0 {
			percentage = 0
		}
		if percentage > 100 {
			percentage = 100
		}
	}
	discountAmount := money.Round2(original * float64(percentage) / 100)
	effective := maxFloat(0, money.Round2(original-discountAmount))
	return UnitPrice{
		UnitPrice:          effective,
		OriginalPrice:      original,
		DiscountPercentage: percentage,
		DiscountAmount:     discountAmount,
		HasDiscount:        discount != nil,
	}
}

// VoucherLineDiscount is PricingService::voucherLineDiscount.
//
// A1-07: a FIXED voucher has ONE transaction-level face-value budget — it is capped at the FIRST line it hits and
// is then zero, so splitting a cart into more lines can never multiply the reduction. A percentage voucher is a
// percentage of each eligible line, still bounded by the remaining budget and the applicable subtotal.
//
// Returns (discount, remainingBudget).
func VoucherLineDiscount(v Voucher, lineSubtotal, remainingBudget, remainingApplicableSubtotal float64) (float64, float64) {
	lineSubtotal = maxFloat(0, lineSubtotal)
	if !v.IsUsable(Today()) || remainingBudget <= 0 {
		return 0, remainingBudget
	}

	var discount float64
	if v.Type == "fixed" {
		discount = minFloat(v.Value, lineSubtotal)
		discount = minFloat(discount, remainingBudget)
		discount = minFloat(discount, remainingApplicableSubtotal)
	} else {
		percentage := v.Value
		if percentage < 0 {
			percentage = 0
		}
		if percentage > 100 {
			percentage = 100
		}
		discount = money.Round2(lineSubtotal * percentage / 100)
		discount = minFloat(discount, remainingBudget)
		discount = minFloat(discount, remainingApplicableSubtotal)
	}
	discount = maxFloat(0, money.Round2(discount))
	newBudget := maxFloat(0, money.Round2(remainingBudget-discount))
	return discount, newBudget
}

// ---- order totals ----------------------------------------------------------------------------------

// OrderMoney is the subset of an orders row the total calculation reads/writes.
type OrderMoney struct {
	ID             int64
	DiscountAmount float64
	ShippingFee    float64
	AdminFee       float64
}

// EffectiveDiscountAmount is Order::effectiveDiscountAmount: the voucher reduction actually applied can never
// exceed the live billed subtotal (A1-08), while the historical discount_amount stays untouched.
func EffectiveDiscountAmount(discountAmount, subtotal float64) float64 {
	if subtotal < 0 {
		subtotal = 0
	}
	return money.Round2(minFloat(discountAmount, subtotal))
}

// RecalculateTotals is OrderTotalCalculator::recalculate.
//
// Per-item value is `unit_price_snapshot * fulfilled_quantity` — deliberately NOT subtotal_snapshot, which stays
// the historical as-ordered value. Shipping/admin/discount are NOT re-evaluated here.
func RecalculateTotals(ctx context.Context, d *db.DB, orderID int64) (subtotal float64, total float64, err error) {
	var order OrderMoney
	err = d.QueryRowContext(ctx, `SELECT id, discount_amount, shipping_fee_amount, admin_fee_amount
		FROM orders WHERE id = ?`, orderID).
		Scan(&order.ID, &order.DiscountAmount, &order.ShippingFee, &order.AdminFee)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, 0, errors.New("order not found")
	}
	if err != nil {
		return 0, 0, err
	}

	var sum sql.NullString
	if err := d.QueryRowContext(ctx, `SELECT COALESCE(SUM(unit_price_snapshot * fulfilled_quantity), 0)
		FROM order_items WHERE order_id = ?`, orderID).Scan(&sum); err != nil {
		return 0, 0, err
	}
	raw, parseErr := parseDecimal(sum)
	if parseErr != nil {
		return 0, 0, parseErr
	}
	subtotal = money.Round2(raw)

	effectiveVoucher := EffectiveDiscountAmount(order.DiscountAmount, subtotal)
	total = money.Round2(subtotal - effectiveVoucher + order.ShippingFee + order.AdminFee)
	// Defensive clamp: the DB column is unsigned, so a total can never be negative.
	if total < 0 {
		total = 0
	}
	return subtotal, total, nil
}

// ---- small helpers ---------------------------------------------------------------------------------

func parseDate(v sql.NullString) *time.Time {
	if !v.Valid || v.String == "" {
		return nil
	}
	for _, layout := range []string{"2006-01-02", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, v.String); err == nil {
			return &t
		}
	}
	return nil
}

func nullIntPtr(v sql.NullInt64) *int64 {
	if !v.Valid {
		return nil
	}
	n := v.Int64
	return &n
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
