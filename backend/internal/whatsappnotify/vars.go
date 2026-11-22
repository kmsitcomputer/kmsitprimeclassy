package whatsappnotify

import (
	"context"
	"database/sql"
	"fmt"
	"math"
	"strings"
)

// LoadOrder reads everything data() needs.
func LoadOrder(ctx context.Context, q queryer, orderID int64) (*orderRow, error) {
	row := q.QueryRowContext(ctx, `SELECT o.id, o.agent_id, o.konsumen_id, o.placed_by_user_id,
		o.order_no, o.status, o.payment_status, o.created_at, o.subtotal_amount, o.discount_amount,
		o.shipping_fee_amount, o.total_amount, o.paid_amount,
		pm.name, o.recipient_phone_snapshot, k.name,
		ap.store_name
		FROM orders o
		LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
		LEFT JOIN users k ON k.id = o.konsumen_id
		LEFT JOIN agent_profiles ap ON ap.user_id = o.agent_id
		WHERE o.id = ?`, orderID)
	var o orderRow
	var createdAt sql.NullTime
	err := row.Scan(&o.ID, &o.AgentID, &o.KonsumenID, &o.PlacedByUserID, &o.OrderNo, &o.Status,
		&o.PaymentStatus, &createdAt, &o.Subtotal, &o.Discount, &o.ShippingFee, &o.Total,
		&o.Paid, &o.PaymentMethodName, &o.RecipientPhone, &o.KonsumenName, &o.StoreName)
	if err != nil {
		return nil, err
	}
	if createdAt.Valid {
		o.CreatedAt = createdAt.Time
	}
	o.CRMOrigin = crmOrigin(ctx, q, orderID)
	return &o, nil
}

func crmOrigin(ctx context.Context, q queryer, orderID int64) bool {
	// Order::isCrmOrigin: created through the CRM assisted flow (source snapshot).
	var cnt int
	_ = q.QueryRowContext(ctx, `SELECT COUNT(*) FROM crm_orders WHERE order_id = ?`, orderID).Scan(&cnt)
	return cnt > 0
}

// OrderVars ports OrderNotifier::data — the full-template variable set.
func OrderVars(ctx context.Context, q queryer, o *orderRow) map[string]*string {
	vars := map[string]*string{
		"nama_pelanggan":    nilIfEmpty(o.KonsumenName),
		"nomor_pesanan":     Str(o.OrderNo),
		"status":            Str(statusLabels[o.Status]),
		"total":             Str(moneyID(o.Total)),
		"tanggal":           dateID(o.CreatedAt),
		"nama_toko":         storeName(o.StoreName),
		"daftar_produk":     productLines(ctx, q, o.ID),
		"subtotal":          Str(moneyID(o.Subtotal)),
		"diskon":            Str(moneyID(o.Discount)),
		"ongkir":            Str(moneyID(o.ShippingFee)),
		"total_tagihan":     Str(moneyID(o.Total)),
		"metode_pembayaran": nilIfEmpty(o.PaymentMethodName),
		"status_pembayaran": Str(o.PaymentStatus),
		"jumlah_dibayarkan": Str(moneyID(o.Paid)),
		"sisa_tagihan":      Str(moneyID(math.Max(0, o.Total-o.Paid))),
	}
	// Latest VERIFIED payment transaction (D-10).
	var verifiedMethod sql.NullString
	var verifiedPaidAt sql.NullTime
	var verifiedAmount sql.NullFloat64
	_ = q.QueryRowContext(ctx, `SELECT pm.name, t.paid_at, t.amount
		FROM payment_transactions t LEFT JOIN payment_methods pm ON pm.id = t.payment_method_id
		WHERE t.order_id = ? AND t.status = 'paid'
		ORDER BY (t.paid_at IS NULL), t.id DESC LIMIT 1`, o.ID).
		Scan(&verifiedMethod, &verifiedPaidAt, &verifiedAmount)
	if verifiedMethod.Valid {
		vars["metode_pembayaran"] = Str(verifiedMethod.String)
	}
	if verifiedPaidAt.Valid {
		vars["tanggal_pembayaran"] = dateID(verifiedPaidAt.Time)
		vars["jumlah_pembayaran_ini"] = Str(moneyID(verifiedAmount.Float64))
	}
	couriers, resi := shipmentFacts(ctx, q, o.ID)
	vars["kurir"] = couriers
	vars["resi"] = resi
	vars["nama_kurir"] = couriers
	vars["nomor_resi"] = resi
	var shippedAt, deliveredAt sql.NullTime
	_ = q.QueryRowContext(ctx, `SELECT MAX(shipped_at), MAX(delivered_at) FROM shipments
		WHERE order_id = ? AND status <> 'cancelled'`, o.ID).Scan(&shippedAt, &deliveredAt)
	if shippedAt.Valid {
		vars["tanggal_pengiriman"] = dateID(shippedAt.Time)
	}
	if deliveredAt.Valid {
		vars["tanggal_diterima"] = dateID(deliveredAt.Time)
	}
	vars["link_pesanan"] = orderURL(o.ID)
	return vars
}

func storeName(v sql.NullString) *string {
	if v.Valid && v.String != "" {
		return Str(v.String)
	}
	return Str("Prime Classy")
}

func nilIfEmpty(v sql.NullString) *string {
	if v.Valid && v.String != "" {
		return Str(v.String)
	}
	return nil
}

func productLines(ctx context.Context, q queryer, orderID int64) *string {
	rows, err := q.QueryContext(ctx, `SELECT product_name_snapshot,
		IFNULL(variation_label_snapshot, ''), COALESCE(original_quantity, quantity, 1)
		FROM order_items WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil
	}
	defer rows.Close()
	var parts []string
	for rows.Next() {
		var name, variation string
		var qty int64
		if err := rows.Scan(&name, &variation, &qty); err != nil {
			return nil
		}
		line := strings.TrimSpace(name)
		if variation != "" {
			line += " (" + variation + ")"
		}
		line += fmt.Sprintf(" x%d", qty)
		if line != "" {
			parts = append(parts, line)
		}
	}
	if len(parts) == 0 {
		return nil
	}
	s := strings.Join(parts, ", ")
	return &s
}

func shipmentFacts(ctx context.Context, q queryer, orderID int64) (couriers, resi *string) {
	rows, err := q.QueryContext(ctx, `SELECT c.name, self.name, s.tracking_number
		FROM shipments s
		LEFT JOIN couriers c ON c.id = s.courier_id
		LEFT JOIN users self ON self.id = s.self_delivered_by_user_id
		WHERE s.order_id = ? AND s.status <> 'cancelled' ORDER BY s.id`, orderID)
	if err != nil {
		return nil, nil
	}
	defer rows.Close()
	var courierList, resiList []string
	seenCourier, seenResi := map[string]bool{}, map[string]bool{}
	for rows.Next() {
		var courier, selfDelivered, tracking sql.NullString
		if err := rows.Scan(&courier, &selfDelivered, &tracking); err != nil {
			return nil, nil
		}
		name := courier
		if !name.Valid || name.String == "" {
			name = selfDelivered
		}
		if name.Valid && name.String != "" && !seenCourier[name.String] {
			seenCourier[name.String] = true
			courierList = append(courierList, name.String)
		}
		if tracking.Valid && tracking.String != "" && !seenResi[tracking.String] {
			seenResi[tracking.String] = true
			resiList = append(resiList, tracking.String)
		}
	}
	if len(courierList) > 0 {
		s := strings.Join(courierList, ", ")
		couriers = &s
	}
	if len(resiList) > 0 {
		s := strings.Join(resiList, ", ")
		resi = &s
	}
	return couriers, resi
}
