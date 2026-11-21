package whatsappnotify

import (
	"database/sql"
	"fmt"
	"math"
	"os"
	"strings"
	"time"
)

// resolver.go ports OrderNotifier::data (the verified per-order variable set —
// server-derived snapshots, never client input) and the courier/shipment facts.

type orderRow struct {
	ID                int64
	AgentID           sql.NullInt64
	KonsumenID        sql.NullInt64
	PlacedByUserID    sql.NullInt64
	OrderNo           string
	Status            string
	PaymentStatus     string
	CreatedAt         time.Time
	Subtotal          float64
	Discount          float64
	ShippingFee       float64
	Total             float64
	Paid              float64
	PaymentMethodName sql.NullString
	RecipientPhone    sql.NullString
	KonsumenName      sql.NullString
	CRMOrigin         bool
	StoreName         sql.NullString
}

var statusLabels = map[string]string{
	"diterima": "Diterima", "diproses": "Diproses", "dikirim": "Dikirim", "terkirim": "Terkirim",
	"dibatalkan": "Dibatalkan", "pengembalian": "Pengembalian", "kembali": "Dikembalikan",
}

var monthsID = []string{"Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli",
	"Agustus", "September", "Oktober", "November", "Desember"}

// dateID formats a timestamp like OrderNotifier::date (Asia/Jakarta calendar words).
func dateID(t time.Time) *string {
	if t.IsZero() {
		return nil
	}
	jakarta := t.In(time.FixedZone("WIB", 7*3600))
	s := fmt.Sprintf("%d %s %d", jakarta.Day(), monthsID[jakarta.Month()-1], jakarta.Year())
	return &s
}

// dateTimeID formats like OrderNotifier::dateTime (Asia/Jakarta).
func dateTimeID(t time.Time) *string {
	if t.IsZero() {
		return nil
	}
	jakarta := t.In(time.FixedZone("WIB", 7*3600))
	s := fmt.Sprintf("%d %s %d, %02d.%02d WIB", jakarta.Day(), monthsID[jakarta.Month()-1],
		jakarta.Year(), jakarta.Hour(), jakarta.Minute())
	return &s
}

func moneyID(amount float64) string {
	return "Rp " + groupThousands(int64(math.Round(amount)))
}

func groupThousands(v int64) string {
	neg := v < 0
	if neg {
		v = -v
	}
	s := fmt.Sprintf("%d", v)
	var parts []string
	for len(s) > 3 {
		parts = append([]string{s[len(s)-3:]}, parts...)
		s = s[:len(s)-3]
	}
	parts = append([]string{s}, parts...)
	out := strings.Join(parts, ".")
	if neg {
		return "-" + out
	}
	return out
}

// MoneyID exposes the Laravel "Rp 1.234.567" formatting for callers building
// notification extras.
func MoneyID(amount float64) string { return moneyID(amount) }

// orderURL ports OrderNotifier::orderUrl: config('app.frontend_url') which is
// APP_FRONTEND_URL falling back to APP_URL; /orders/{id}, nil when unset.
func orderURL(orderID int64) *string {
	base := strings.TrimRight(os.Getenv("APP_FRONTEND_URL"), "/")
	if base == "" {
		base = strings.TrimRight(os.Getenv("FRONTEND_URL"), "/")
	}
	if base == "" {
		base = strings.TrimRight(os.Getenv("APP_URL"), "/")
	}
	if base == "" {
		return nil
	}
	s := fmt.Sprintf("%s/orders/%d", base, orderID)
	return &s
}
