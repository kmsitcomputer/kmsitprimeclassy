package orders

// Support SQL for order creation: the row writers, the invoice sequence, voucher consumption, fee snapshots,
// commissions, the payment transaction, the stock request, the initial report snapshot and the activity log.
//
// Everything here runs inside the caller's transaction (the ONE transaction that makes the order atomic). The
// only reads that run outside it are the idempotency replay lookup and the final loadOrder.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/payment"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

const (
	stockSourceAgent = "agent"
	stockSourceSub   = "sub"

	invoiceSequenceKey = "orders.invoice_sequence"
	invoiceMaxSequence = 99999
)

// quoter builds the pricing/preview service so both paths share ONE implementation of line pricing,
// destination resolution and the voucher budget.
func (s *Service) quoter() *checkout.Quoter {
	return &checkout.Quoter{
		Database: s.Database,
		Messages: s.Messages,
		Shipping: &shipping.Service{DB: s.Database, AppKey: s.AppKey, Config: s.Shipping},
	}
}

func (s *Service) agentBranchOf(ctx context.Context, userID int64) (*int64, error) {
	return s.quoter().AgentBranchOf(ctx, userID)
}

// findByIdempotencyKey is the origin-scoped replay lookup. A different buyer can never replay another buyer's
// order because the key space is per konsumen.
func (s *Service) findByIdempotencyKey(ctx context.Context, konsumenID int64, key string) (*Order, error) {
	if key == "" {
		return nil, nil
	}
	var orderID int64
	err := s.Database.QueryRowContext(ctx, `SELECT id FROM orders WHERE konsumen_id = ? AND idempotency_key = ?`,
		konsumenID, key).Scan(&orderID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return s.loadOrder(ctx, orderID, 0)
}

// resolvePaymentMethod is OrderService::resolvePaymentMethod.
func (s *Service) resolvePaymentMethod(ctx context.Context, code string) (PaymentMethod, error) {
	if strings.TrimSpace(code) == "" {
		return PaymentMethod{}, &Problem{
			Status:  422,
			Message: s.Messages("payment.method_required"),
			Fields:  map[string]string{"payment_method_code": s.Messages("system.field_required")},
		}
	}
	var m PaymentMethod
	var active int
	err := s.Database.QueryRowContext(ctx, `SELECT id, code, name, type, is_active FROM payment_methods WHERE code = ?`,
		code).Scan(&m.ID, &m.Code, &m.Name, &m.Type, &active)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && active != 1) {
		return PaymentMethod{}, &Problem{
			Status:  422,
			Message: s.Messages("payment.invalid_method"),
			Fields:  map[string]string{"payment_method_code": s.Messages("system.field_invalid")},
		}
	}
	if err != nil {
		return PaymentMethod{}, err
	}
	return m, nil
}

// resolveStockSource is StockSourceResolver::resolve — the CLIENT's stock_source is a request, never an
// entitlement, and the Sub Location is derived from ownership rather than taken from the request.
func (s *Service) resolveStockSource(ctx context.Context, in Input) (string, *int64, error) {
	source := stockSourceAgent
	if in.StockSource != nil && *in.StockSource != "" {
		source = *in.StockSource
	}
	if source != stockSourceAgent && source != stockSourceSub {
		return "", nil, &Problem{Status: 422, Message: "Sumber stok tidak valid.",
			Fields: map[string]string{"stock_source": "Sumber stok tidak valid."}}
	}
	if source == stockSourceAgent {
		if in.SubLocationID != nil {
			return "", nil, &Problem{Status: 422, Message: "sub_location_id hanya berlaku untuk sumber stok Sub.",
				Fields: map[string]string{"sub_location_id": "Tidak valid untuk sumber stok Agent."}}
		}
		return source, nil, nil
	}

	var (
		actorRole  string
		actorAgent sql.NullInt64
		buyerRole  string
		buyerSales sql.NullInt64
		buyerAgent sql.NullInt64
	)
	if err := s.Database.QueryRowContext(ctx, `SELECT r.slug, u.agent_id FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL`, in.ActorID).Scan(&actorRole, &actorAgent); err != nil {
		return "", nil, err
	}
	if err := s.Database.QueryRowContext(ctx, `SELECT r.slug, u.sales_id, u.agent_id FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL`, in.KonsumenID).Scan(&buyerRole, &buyerSales, &buyerAgent); err != nil {
		return "", nil, err
	}

	if actorRole != "sales-kurir-sub" {
		return "", nil, &Problem{Status: 403, Message: "Hanya Sales-Kurir-Sub yang dapat memakai stok Sub."}
	}
	ownsBuyerOrder := in.KonsumenID == in.ActorID ||
		(buyerRole == "konsumen" && buyerSales.Valid && buyerSales.Int64 == in.ActorID &&
			buyerAgent.Valid && actorAgent.Valid && buyerAgent.Int64 == actorAgent.Int64)
	if !ownsBuyerOrder {
		return "", nil, &Problem{Status: 403,
			Message: "Stok Sub hanya dapat dipakai untuk pembelian sendiri atau konsumen referral sendiri."}
	}

	var locationID int64
	err := s.Database.QueryRowContext(ctx, `SELECT id FROM warehouse_sub_locations
		WHERE owner_user_id = ? AND is_active = 1 ORDER BY id LIMIT 1`, in.ActorID).Scan(&locationID)
	if errors.Is(err, sql.ErrNoRows) {
		return "", nil, &Problem{Status: 422, Message: "Anda belum memiliki Sub Location aktif.",
			Fields: map[string]string{"stock_source": "Sub Location tidak tersedia."}}
	}
	if err != nil {
		return "", nil, err
	}
	if in.SubLocationID != nil && *in.SubLocationID != locationID {
		return "", nil, &Problem{Status: 403, Message: "Sub Location bukan milik Anda."}
	}
	return source, &locationID, nil
}

// assertPaymentMethodAllowed re-checks eligibility against the RESOLVED provider, distinguishing the role
// restriction from the shipping one (the reference emits two different sentences).
func (s *Service) assertPaymentMethodAllowed(ctx context.Context, agentID int64, providerCode string, method PaymentMethod, actorID int64) error {
	roleIsKonsumen, err := s.konsumenIsChainRole(ctx, actorID)
	if err != nil {
		return err
	}
	_ = roleIsKonsumen
	// The actor's role decides the konsumen-only filter; OrderService passes the ACTOR.
	var actorRole string
	if err := s.Database.QueryRowContext(ctx, `SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL`, actorID).Scan(&actorRole); err != nil {
		return err
	}
	isKonsumenActor := actorRole == "konsumen"

	shippingArg := providerCode
	allowed, err := payment.AvailableMethods(ctx, s.Database, &agentID, &shippingArg, isKonsumenActor)
	if err != nil {
		return err
	}
	if containsMethod(allowed, method.ID) {
		return nil
	}

	// Was it allowed without the role restriction, or without the shipping one?
	withoutRole, err := payment.AvailableMethods(ctx, s.Database, &agentID, &shippingArg, false)
	if err != nil {
		return err
	}
	roleBlocked := containsMethod(withoutRole, method.ID)
	message := "payment.method_not_available_for_shipping"
	if roleBlocked {
		message = "payment.method_not_available_for_role"
	}
	return &Problem{
		Status:  422,
		Message: s.Messages(message),
		Fields:  map[string]string{"payment_method_code": s.Messages("system.field_invalid")},
	}
}

func containsMethod(methods []payment.Method, id int64) bool {
	for _, m := range methods {
		if m.ID == id {
			return true
		}
	}
	return false
}

// konsumenIsChainRole reports whether the buyer is itself a chain role (self-purchase), which decides the sales
// commission recipient.
func (s *Service) konsumenIsChainRole(ctx context.Context, userID int64) (bool, error) {
	var role string
	if err := s.Database.QueryRowContext(ctx, `SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL`, userID).Scan(&role); err != nil {
		return false, err
	}
	switch role {
	case "agen", "korsal", "sales", "sales-kurir-sub":
		return true, nil
	}
	return false, nil
}

// salesBeneficiary is the documented rule: the konsumen's own sales rep, else their korsal, else the branch agent.
func (s *Service) salesBeneficiary(ctx context.Context, konsumenID, agentID int64) (int64, error) {
	var sales, korsal sql.NullInt64
	if err := s.Database.QueryRowContext(ctx, `SELECT sales_id, korsal_id FROM users WHERE id = ?`,
		konsumenID).Scan(&sales, &korsal); err != nil {
		return 0, err
	}
	if sales.Valid && sales.Int64 > 0 {
		return sales.Int64, nil
	}
	if korsal.Valid && korsal.Int64 > 0 {
		return korsal.Int64, nil
	}
	return agentID, nil
}

// insertPlaceholder is the TEMP order row. Totals are filled in after items are priced.
func (s *Service) insertPlaceholder(ctx context.Context, tx *sql.Tx, in Input, agentID int64, method PaymentMethod, status string, dest checkout.DestinationSnapshot) (int64, error) {
	placeholder, err := randomHex(12)
	if err != nil {
		return 0, err
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO orders
		(order_no, idempotency_key, konsumen_id, placed_by_user_id, sales_id, korsal_id, agent_id,
		 payment_method_id, source_address_id, status, payment_status,
		 subtotal_amount, shipping_fee_amount, admin_fee_amount, total_amount,
		 recipient_name_snapshot, recipient_phone_snapshot, address_snapshot,
		 village_snapshot, district_snapshot, regency_snapshot, province_snapshot,
		 latitude_snapshot, longitude_snapshot,
		 province_id, regency_id, district_id, village_id, postal_code,
		 delivery_date_estimate, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		"TEMP-"+placeholder, nullIfEmpty(in.IdempotencyKey), in.KonsumenID, in.ActorID,
		nullInt64(s.userColumn(ctx, in.KonsumenID, "sales_id")), nullInt64(s.userColumn(ctx, in.KonsumenID, "korsal_id")),
		agentID, method.ID, nullableInt(dest.AddressID), status,
		derefStringPtr(dest.RecipientName), derefStringPtr(dest.RecipientPhone), derefStringPtr(dest.AddressLine),
		nullableString(dest.VillageName), nullableString(dest.DistrictName), nullableString(dest.RegencyName),
		nullableString(dest.ProvinceName), nullableFloat(dest.Latitude), nullableFloat(dest.Longitude),
		nullableString(dest.ProvinceID), nullableString(dest.RegencyID), nullableString(dest.DistrictID),
		nullableString(dest.VillageID), nullableString(dest.PostalCode), nullableString(in.DeliveryDate))
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

// userColumn reads one nullable id column from users (used for the attribution snapshot at insert time).
func (s *Service) userColumn(ctx context.Context, userID int64, column string) *int64 {
	var v sql.NullInt64
	if err := s.Database.QueryRowContext(ctx, `SELECT `+column+` FROM users WHERE id = ?`, userID).Scan(&v); err != nil {
		return nil
	}
	if !v.Valid {
		return nil
	}
	id := v.Int64
	return &id
}

// allocateInvoiceNumber is InvoiceNumberService::next.
//
// The format is `D-MMYY#####` with a GLOBAL sequence that never resets; allocation is serialised by a row lock on
// the settings row, never by a MAX() over orders. After 99999 the sequence rolls over, and if that candidate
// already exists the namespace is exhausted and the caller gets a 409 — numbers are never reused.
func allocateInvoiceNumber(ctx context.Context, tx *sql.Tx, now time.Time) (string, error) {
	if _, err := tx.ExecContext(ctx, `INSERT IGNORE INTO settings (`+"`key`"+`, `+"`value`"+`, `+"`group`"+`, is_public, created_at, updated_at)
		VALUES (?, '0', 'orders', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, invoiceSequenceKey); err != nil {
		return "", err
	}
	var raw sql.NullString
	if err := tx.QueryRowContext(ctx, "SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE", invoiceSequenceKey).
		Scan(&raw); err != nil {
		return "", err
	}
	current := 0
	if raw.Valid {
		if parsed, err := strconv.Atoi(strings.TrimSpace(raw.String)); err == nil {
			current = parsed
		}
	}
	next := current + 1
	if next > invoiceMaxSequence {
		next = 1
	}
	candidate := "D-" + now.Format("0106")[:2] + now.Format("06") + padLeft(strconv.Itoa(next), 5)

	var exists bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM orders WHERE order_no = ?)`, candidate).Scan(&exists); err != nil {
		return "", err
	}
	if exists {
		return "", &Problem{Status: 409, Message: "Rentang nomor invoice untuk periode ini sudah habis. " +
			"Nomor tidak pernah dipakai ulang; selesaikan konflik kapasitas sebelum membuat pesanan baru."}
	}

	if _, err := tx.ExecContext(ctx, "UPDATE settings SET `value` = ?, updated_at = UTC_TIMESTAMP() WHERE `key` = ?",
		strconv.Itoa(next), invoiceSequenceKey); err != nil {
		return "", err
	}
	return candidate, nil
}

func padLeft(v string, width int) string {
	for len(v) < width {
		v = "0" + v
	}
	return v
}

// consumeVoucher is VoucherService::consume: one redemption under the row lock. A raced/overspent voucher
// returns false so the whole checkout aborts (and rolls back everything above).
func (s *Service) consumeVoucher(ctx context.Context, tx *sql.Tx, agentID, voucherID int64) (bool, error) {
	var (
		active                int
		value                 sql.NullString
		validFrom, validUntil sql.NullString
		maxUses               sql.NullInt64
		usedCount             int64
	)
	err := tx.QueryRowContext(ctx, `SELECT is_active, CAST(value AS CHAR), CAST(valid_from AS CHAR), CAST(valid_until AS CHAR),
			max_uses, used_count
		FROM vouchers WHERE id = ? AND agent_id = ? FOR UPDATE`, voucherID, agentID).
		Scan(&active, &value, &validFrom, &validUntil, &maxUses, &usedCount)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}

	today := time.Now().UTC().Format("2006-01-02")
	usable := active == 1
	if usable && validFrom.Valid && today < validFrom.String {
		usable = false
	}
	if usable && validUntil.Valid && today > validUntil.String {
		usable = false
	}
	if usable && maxUses.Valid && usedCount >= maxUses.Int64 {
		usable = false
	}
	if !usable {
		return false, nil
	}

	if _, err := tx.ExecContext(ctx, `UPDATE vouchers SET used_count = used_count + 1, updated_at = UTC_TIMESTAMP()
		WHERE id = ? AND agent_id = ?`, voucherID, agentID); err != nil {
		return false, err
	}
	return true, nil
}

// fees is FeeService::resolveForLine's result.
type fees struct {
	Agent   float64
	Sales   float64
	Courier float64
}

// resolveFees reads the ACTIVE fee rows for the target's mode — variation-targeted when the line has a variation,
// otherwise product-level. There is deliberately no fallback chain between the two: a product is in exactly one
// mode, so a missing row means that beneficiary earns 0 for this line, not an error.
func resolveFees(ctx context.Context, tx *sql.Tx, resolved checkout.ResolvedLine) (fees, error) {
	var out fees

	var (
		query string
		arg   int64
	)
	if resolved.Variation != nil {
		query = `SELECT beneficiary_role, CAST(amount AS CHAR) FROM product_variation_fees
			WHERE product_variation_id = ? AND is_active = 1`
		arg = resolved.Variation.ID
	} else {
		query = `SELECT beneficiary_role, CAST(amount AS CHAR) FROM product_fees
			WHERE product_id = ? AND is_active = 1`
		arg = resolved.Product.ID
	}

	rows, err := tx.QueryContext(ctx, query, arg)
	if err != nil {
		if db.IsMissingSchema(err) {
			return out, nil
		}
		return out, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			role   string
			amount sql.NullString
		)
		if err := rows.Scan(&role, &amount); err != nil {
			return out, err
		}
		value := parseDecimal(amount)
		switch role {
		case "agent":
			out.Agent = value
		case "sales":
			out.Sales = value
		case "courier":
			out.Courier = value
		}
	}
	return out, rows.Err()
}

func parseDecimal(v sql.NullString) float64 {
	if !v.Valid {
		return 0
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(v.String), 64)
	if err != nil {
		return 0
	}
	return f
}

// recordCommission is OrderService::recordCommission — one row per non-zero fee, status pending.
func recordCommission(ctx context.Context, tx *sql.Tx, orderID, itemID, agentID, salesBeneficiary int64, agentFee, salesFee float64) error {
	if agentFee > 0 {
		if _, err := tx.ExecContext(ctx, `INSERT INTO commissions
			(order_id, order_item_id, beneficiary_user_id, beneficiary_role, amount, status, earned_at, created_at, updated_at)
			VALUES (?, ?, ?, 'agent', ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, itemID, agentID, agentFee); err != nil {
			return err
		}
	}
	if salesFee > 0 {
		if _, err := tx.ExecContext(ctx, `INSERT INTO commissions
			(order_id, order_item_id, beneficiary_user_id, beneficiary_role, amount, status, earned_at, created_at, updated_at)
			VALUES (?, ?, ?, 'sales', ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, itemID, salesBeneficiary, salesFee); err != nil {
			return err
		}
	}
	return nil
}

// orderItemInput is one order_items insert.
type orderItemInput struct {
	OrderID        int64
	ProductID      int64
	VariationID    *int64
	StockSource    string
	SubLocationID  *int64
	ProductName    string
	VariationLabel *string
	SKU            *string
	UnitPrice      float64
	AgentFee       float64
	SalesFee       float64
	CourierFee     float64
	Subtotal       float64
	Quantity       int
	RequestedDate  *string
	Status         string
}

func insertOrderItem(ctx context.Context, tx *sql.Tx, in orderItemInput) (int64, error) {
	res, err := tx.ExecContext(ctx, `INSERT INTO order_items
		(order_id, product_id, product_variation_id, stock_source, sub_location_id,
		 product_name_snapshot, variation_label_snapshot, sku_snapshot,
		 unit_price_snapshot, agent_fee_amount, sales_fee_amount, courier_fee_amount, subtotal_snapshot,
		 original_quantity, fulfilled_quantity, requested_delivery_date, status, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		in.OrderID, in.ProductID, nullableInt(in.VariationID), in.StockSource, nullableInt(in.SubLocationID),
		in.ProductName, nullableString(in.VariationLabel), nullableString(in.SKU),
		in.UnitPrice, in.AgentFee, in.SalesFee, in.CourierFee, in.Subtotal,
		in.Quantity, in.Quantity, nullableString(in.RequestedDate), in.Status)
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

type shipmentInput struct {
	OrderID         int64
	ProviderRowID   *int64
	ProviderCode    string
	OriginLat       float64
	OriginLng       float64
	DestLat         float64
	DestLng         float64
	DistanceKm      *float64
	RatePerKm       *float64
	Meta            map[string]any
	DeliveryMode    string
	SelfDeliveredBy *int64
	First           bool
	ShippingFee     float64
}

func insertShipment(ctx context.Context, tx *sql.Tx, in shipmentInput) (int64, error) {
	meta, err := json.Marshal(in.Meta)
	if err != nil {
		return 0, err
	}
	ratePerKm := 0.0
	if in.RatePerKm != nil {
		ratePerKm = *in.RatePerKm
	}
	fee := 0.0
	if in.First {
		fee = in.ShippingFee
	}
	res, execErr := tx.ExecContext(ctx, `INSERT INTO shipments
		(order_id, shipping_provider_id, shipping_provider_code,
		 origin_latitude, origin_longitude, destination_latitude, destination_longitude,
		 distance_km, provider_meta, status, delivery_mode, self_delivered_by_user_id,
		 rate_per_km, shipping_fee_snapshot, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		in.OrderID, nullableInt(in.ProviderRowID), nullIfEmpty(in.ProviderCode),
		in.OriginLat, in.OriginLng, in.DestLat, in.DestLng,
		nullableFloat(in.DistanceKm), string(meta), in.DeliveryMode, nullableInt(in.SelfDeliveredBy),
		ratePerKm, fee)
	if execErr != nil {
		return 0, execErr
	}
	return res.LastInsertId()
}

// initiatePayment is PaymentService::initiate for the create path.
//
// COD needs no bank configuration; a manual method snapshots the branch's account onto the transaction; DP uses the
// branch's bank_transfer account but charges only the DP nominal. The gateway type is not reachable from checkout.
func (s *Service) initiatePayment(ctx context.Context, tx *sql.Tx, orderID, agentID int64, method PaymentMethod, total, dpAmount float64) (int64, error) {
	amount := total
	reference := any(nil)
	payload := map[string]any{}

	switch {
	case method.Code == "down_payment":
		amount = dpAmount
		// DP reuses the branch's bank_transfer account; a missing configuration is a hard 422, exactly like
		// PaymentService::activeConfigFor — never a silently empty instruction.
		config, cerr := s.activePaymentConfig(ctx, agentID, "bank_transfer")
		if cerr != nil {
			return 0, cerr
		}
		payload = config
		reference = s.manualReference()
	case method.Type == "cod":
		payload = map[string]any{"note": "cash_on_delivery"}
	case method.Type == "manual":
		config, cerr := s.activePaymentConfig(ctx, agentID, method.Code)
		if cerr != nil {
			return 0, cerr
		}
		payload = config
		reference = s.manualReference()
	default:
		return 0, &Problem{Status: 422, Message: s.Messages("payment.unsupported_method_type", "type", method.Type)}
	}

	raw, err := json.Marshal(payload)
	if err != nil {
		return 0, err
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
		(order_id, payment_method_id, type, amount, status, gateway_reference, raw_payload, created_at, updated_at)
		VALUES (?, ?, 'payment', ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, method.ID, amount, reference, string(raw))
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

// activePaymentConfig is PaymentService::activeConfigFor: the branch's configured account for one method, with the
// environment taken from the branch's own method setting (default sandbox).
//
// A MISSING configuration is a 422 `payment.method_not_configured` naming the method — not an empty payload. The
// customer would otherwise be told to transfer to no account at all, which is exactly the failure the reference
// refuses.
func (s *Service) activePaymentConfig(ctx context.Context, agentID int64, methodCode string) (map[string]any, error) {
	var methodID int64
	var methodName string
	if err := s.Database.QueryRowContext(ctx, `SELECT id, name FROM payment_methods WHERE code = ?`, methodCode).
		Scan(&methodID, &methodName); err != nil {
		return nil, err
	}

	environment := "sandbox"
	var activeEnv sql.NullString
	err := s.Database.QueryRowContext(ctx, `SELECT active_environment FROM agent_payment_method_settings
		WHERE agent_id = ? AND payment_method_id = ?`, agentID, methodID).Scan(&activeEnv)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	if activeEnv.Valid && activeEnv.String != "" {
		environment = activeEnv.String
	}

	var raw sql.NullString
	err = s.Database.QueryRowContext(ctx, `SELECT config FROM agent_payment_gateway_configs
		WHERE agent_id = ? AND payment_method_id = ? AND environment = ? LIMIT 1`,
		agentID, methodID, environment).Scan(&raw)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && !raw.Valid) {
		return nil, &Problem{
			Status:  422,
			Message: s.Messages("payment.method_not_configured", "name", methodName),
		}
	}
	if err != nil {
		return nil, err
	}

	// The column is Laravel `encrypted:array`. An undecryptable value cannot be shown as instructions, and silently
	// emitting nulls would look like "no account" — the same 422 is the honest answer.
	decoded, derr := decryptJSON(s.AppKey, raw.String)
	if derr != nil {
		return nil, &Problem{
			Status:  422,
			Message: s.Messages("payment.method_not_configured", "name", methodName),
		}
	}
	return map[string]any{
		"bank_name":      decoded["bank_name"],
		"account_name":   decoded["account_name"],
		"account_number": decoded["account_number"],
	}, nil
}

func (s *Service) manualReference() string {
	value, err := randomAlphaNumeric(10)
	if err != nil {
		return "MT-0000000000"
	}
	return "MT-" + strings.ToUpper(value)
}

// createStockRequest is StockRequestService::createForOrderWhenProcessing — COD orders start in diproses, so the
// warehouse demand exists immediately. Sub-sourced items are excluded: a Sub order has no warehouse demand.
func (s *Service) createStockRequest(ctx context.Context, tx *sql.Tx, orderID, agentID, actorID int64) (int64, error) {
	var status string
	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id, (SELECT status FROM orders WHERE id = ?) FROM stock_requests WHERE order_id = ?`,
		orderID, orderID).Scan(&existing, &status)
	if err == nil {
		return existing, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return 0, err
	}
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ? FOR UPDATE`, orderID).Scan(&status); err != nil {
		return 0, err
	}
	if status != "diproses" {
		return 0, &Problem{Status: 422, Message: "Stock Request hanya dibuat saat order diproses."}
	}

	rows, err := tx.QueryContext(ctx, `SELECT id, product_id, product_variation_id, sku_snapshot, original_quantity
		FROM order_items WHERE order_id = ? AND stock_source = 'agent' ORDER BY id`, orderID)
	if err != nil {
		return 0, err
	}
	type agentItem struct {
		id        int64
		product   int64
		variation *int64
		sku       *string
		quantity  int
	}
	var items []agentItem
	for rows.Next() {
		var (
			it        agentItem
			variation sql.NullInt64
			sku       sql.NullString
		)
		if err := rows.Scan(&it.id, &it.product, &variation, &sku, &it.quantity); err != nil {
			rows.Close()
			return 0, err
		}
		if variation.Valid {
			v := variation.Int64
			it.variation = &v
		}
		if sku.Valid {
			s := sku.String
			it.sku = &s
		}
		items = append(items, it)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, err
	}
	if len(items) == 0 {
		return 0, nil
	}

	reference, err := s.uniqueStockRequestNumber(ctx, tx)
	if err != nil {
		return 0, err
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO stock_requests
		(agent_id, order_id, request_number, status, created_by, created_at, updated_at)
		VALUES (?, ?, ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, agentID, orderID, reference, actorID)
	if err != nil {
		return 0, err
	}
	requestID, err := res.LastInsertId()
	if err != nil {
		return 0, err
	}
	for _, it := range items {
		if _, err := tx.ExecContext(ctx, `INSERT INTO stock_request_items
			(stock_request_id, order_item_id, product_id, product_variation_id, sku_snapshot,
			 requested_qty, fulfilled_qty, remaining_qty, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			requestID, it.id, it.product, nullableInt(it.variation), nullableString(it.sku), it.quantity, it.quantity); err != nil {
			return 0, err
		}
	}
	return requestID, nil
}

func (s *Service) uniqueStockRequestNumber(ctx context.Context, tx *sql.Tx) (string, error) {
	for attempt := 0; attempt < 12; attempt++ {
		suffix, err := randomAlphaNumeric(5)
		if err != nil {
			return "", err
		}
		number := "SR-" + time.Now().UTC().Format("20060102150405") + "-" + strings.ToUpper(suffix)
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_requests WHERE request_number = ?)`, number).
			Scan(&exists); err != nil {
			return "", err
		}
		if !exists {
			return number, nil
		}
	}
	return "", fmt.Errorf("orders: could not allocate a unique stock request number")
}

// captureInitialSnapshot is OrderInitialSnapshotService::capture — one row per item, written in the SAME
// transaction as the checkout so a partial snapshot can never exist.
func (s *Service) captureInitialSnapshot(ctx context.Context, tx *sql.Tx, orderID int64, orderNo string, method PaymentMethod) error {
	var (
		orderCreated                                        time.Time
		agentID                                             int64
		korsalID, salesID, konsumenID                       sql.NullInt64
		subtotal, discount, shipping, adminFee, total, paid sql.NullString
		paymentStatus                                       string
		recipientName, recipientPhone, address              string
		village, district, regency, province                sql.NullString
	)
	err := tx.QueryRowContext(ctx, `SELECT created_at, agent_id, korsal_id, sales_id, konsumen_id,
			CAST(subtotal_amount AS CHAR), CAST(discount_amount AS CHAR), CAST(shipping_fee_amount AS CHAR),
			CAST(admin_fee_amount AS CHAR), CAST(total_amount AS CHAR), CAST(paid_amount AS CHAR), payment_status,
			recipient_name_snapshot, recipient_phone_snapshot, address_snapshot,
			village_snapshot, district_snapshot, regency_snapshot, province_snapshot
		FROM orders WHERE id = ?`, orderID).
		Scan(&orderCreated, &agentID, &korsalID, &salesID, &konsumenID,
			&subtotal, &discount, &shipping, &adminFee, &total, &paid, &paymentStatus,
			&recipientName, &recipientPhone, &address, &village, &district, &regency, &province)
	if err != nil {
		return err
	}

	var existing bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_initial_report_items WHERE order_id = ?)`, orderID).
		Scan(&existing); err != nil {
		return err
	}
	if existing {
		return fmt.Errorf("orders: initial snapshot already exists for order %d", orderID)
	}

	rows, err := tx.QueryContext(ctx, `SELECT id, product_id, product_variation_id, sku_snapshot,
			product_name_snapshot, variation_label_snapshot, original_quantity,
			CAST(unit_price_snapshot AS CHAR), CAST(subtotal_snapshot AS CHAR)
		FROM order_items WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return err
	}
	type snapshotItem struct {
		id, productID  int64
		variationID    *int64
		sku            *string
		productName    string
		variationLabel *string
		quantity       int
		unitPrice      float64
		subtotal       float64
	}
	var items []snapshotItem
	for rows.Next() {
		var (
			it             snapshotItem
			variation      sql.NullInt64
			sku, label     sql.NullString
			unit, subtotal string
		)
		if err := rows.Scan(&it.id, &it.productID, &variation, &sku, &it.productName, &label, &it.quantity, &unit, &subtotal); err != nil {
			rows.Close()
			return err
		}
		if variation.Valid {
			v := variation.Int64
			it.variationID = &v
		}
		if sku.Valid {
			v := sku.String
			it.sku = &v
		}
		if label.Valid {
			v := label.String
			it.variationLabel = &v
		}
		it.unitPrice = parseString(unit)
		it.subtotal = parseString(subtotal)
		items = append(items, it)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	if len(items) == 0 {
		return fmt.Errorf("orders: cannot snapshot an order without items")
	}

	totalValue := parseString(total.String)
	paidValue := parseString(paid.String)
	remaining := money.Round2(totalValue - paidValue)
	if remaining < 0 {
		remaining = 0
	}

	for position, it := range items {
		var categoryName sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT c.name FROM products p
			LEFT JOIN product_categories c ON c.id = p.category_id WHERE p.id = ?`, it.productID).Scan(&categoryName); err != nil &&
			!errors.Is(err, sql.ErrNoRows) {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_initial_report_items
			(order_id, order_item_id, order_no, order_created_at, agent_id, korsal_id, sales_id, konsumen_id,
			 product_id, product_variation_id, sku_snapshot, product_name_snapshot, variation_label_snapshot,
			 category_name_snapshot, initial_quantity, unit_price_snapshot, item_subtotal_snapshot,
			 recipient_name_snapshot, recipient_phone_snapshot, address_snapshot,
			 village_snapshot, district_snapshot, regency_snapshot, province_snapshot,
			 invoice_subtotal_snapshot, invoice_discount_snapshot, invoice_shipping_snapshot,
			 invoice_admin_fee_snapshot, invoice_total_snapshot, initial_paid_snapshot, initial_remaining_snapshot,
			 initial_payment_status, payment_method_code_snapshot, payment_method_name_snapshot, item_position,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, it.id, orderNo, orderCreated, agentID, nullableInt(nullIntPtr(korsalID)), nullableInt(nullIntPtr(salesID)),
			nullableInt(nullIntPtr(konsumenID)), it.productID, nullableInt(it.variationID),
			nullableString(it.sku), it.productName, nullableString(it.variationLabel), nullableString(nullStringPtr(categoryName)),
			it.quantity, it.unitPrice, it.subtotal, recipientName, recipientPhone, address,
			nullableString(nullStringPtr(village)), nullableString(nullStringPtr(district)),
			nullableString(nullStringPtr(regency)), nullableString(nullStringPtr(province)),
			parseString(subtotal.String), parseString(discount.String), parseString(shipping.String),
			parseString(adminFee.String), totalValue, paidValue, remaining,
			paymentStatus, method.Code, method.Name, position+1); err != nil {
			return err
		}
	}
	return nil
}

// logActivity writes the audit row through the same shape ActivityLogger uses.
//
// The column is `causer_id` (not user_id) and the table has NO `updated_at`; a wrong guess here fails the whole
// order with a 500 AFTER every reservation has been taken, which is exactly how this was found.
// `properties` has a json_valid() CHECK, so the value is always real JSON, and an empty property set is persisted
// as NULL (the reference's `?: null`) rather than "{}".
func logActivity(ctx context.Context, tx *sql.Tx, actorID, orderID int64, event string, properties map[string]any, ip, userAgent string) error {
	return logActivityDescribed(ctx, tx, actorID, "App\\Models\\Order", orderID, event, nil, properties, ip, userAgent)
}

// logActivityDescribed is ActivityLogger::log with its optional description argument and an explicit subject —
// the payment flow audits with the rejection/adjustment reason as the description, and the additional-payment
// settlement audits with the OrderAdditionalPayment model itself as the subject.
func logActivityDescribed(ctx context.Context, tx *sql.Tx, actorID int64, subjectType string, subjectID int64,
	event string, description *string, properties map[string]any, ip, userAgent string) error {

	var payload any
	if len(properties) > 0 {
		raw, err := json.Marshal(redactProperties(properties))
		if err != nil {
			return err
		}
		payload = string(raw)
	}
	var described any
	if description != nil {
		described = *description
	}
	// Laravel's ActivityLogger takes a ?int causerId — several payment flows call it WITHOUT an actor
	// (cod_collected / cod_unmarked / refund_processed), so their invoice.issued rows carry causer NULL.
	var causer any
	if actorID > 0 {
		causer = actorID
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())`,
		causer, subjectType, subjectID, event, described, payload,
		nullIfEmpty(ip), nullIfEmpty(truncate(userAgent, 500)))
	return err
}

// redactedKeys mirrors ActivityLogger::REDACTED_KEYS: a secret must never reach the audit trail even when a caller
// passes it by mistake.
var redactedKeys = map[string]bool{
	"password": true, "password_confirmation": true, "token": true, "secret": true,
	"api_key": true, "api_secret": true, "access_token": true, "refresh_token": true,
}

func redactProperties(in map[string]any) map[string]any {
	out := make(map[string]any, len(in))
	for key, value := range in {
		if nested, ok := value.(map[string]any); ok {
			out[key] = redactProperties(nested)
			continue
		}
		if redactedKeys[strings.ToLower(key)] {
			out[key] = "[redacted]"
			continue
		}
		out[key] = value
	}
	return out
}

func truncate(v string, max int) string {
	if len(v) <= max {
		return v
	}
	return v[:max]
}

// lockSubLocationForCheckout takes the Sub Location parent row FIRST, SHARED, and re-validates it.
//
// The executor (SubStockRequestService::execute) locks the SAME location exclusively and then the stock rows, so a
// checkout that instead held a stock row first and only needed the parent at the order_item insert would form a
// location <-> stock cycle. A shared lock is enough: checkout only needs the parent to stay stable.
func (s *Service) lockSubLocationForCheckout(ctx context.Context, tx *sql.Tx, locationID, actorID, agentID int64) (*struct{ ID int64 }, error) {
	var (
		id            int64
		active        int
		owner         sql.NullInt64
		locationAgent int64
	)
	err := tx.QueryRowContext(ctx, `SELECT id, is_active, owner_user_id, agent_id FROM warehouse_sub_locations
		WHERE id = ? LOCK IN SHARE MODE`, locationID).Scan(&id, &active, &owner, &locationAgent)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &Problem{Status: 422, Message: "Sub Location tidak lagi aktif atau bukan milik Anda.",
			Fields: map[string]string{"stock_source": "Sub Location tidak tersedia."}}
	}
	if err != nil {
		return nil, err
	}
	if active != 1 || locationAgent != agentID || !owner.Valid || owner.Int64 != actorID {
		return nil, &Problem{Status: 422, Message: "Sub Location tidak lagi aktif atau bukan milik Anda.",
			Fields: map[string]string{"stock_source": "Sub Location tidak tersedia."}}
	}
	return &struct{ ID int64 }{ID: id}, nil
}

func subLocationOwner(ctx context.Context, tx *sql.Tx, locationID int64) (*int64, error) {
	var owner sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT owner_user_id FROM warehouse_sub_locations WHERE id = ?`, locationID).
		Scan(&owner); err != nil {
		return nil, err
	}
	return nullIntPtr(owner), nil
}

// providerRow resolves a shipping_providers row by code inside the transaction.
func providerRow(ctx context.Context, tx *sql.Tx, code string) (*struct{ ID int64 }, error) {
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM shipping_providers WHERE code = ?`, code).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &struct{ ID int64 }{ID: id}, nil
}

func isDuplicateKey(err error) bool {
	var mysqlErr interface{ Number() uint16 }
	if errors.As(err, &mysqlErr) {
		return mysqlErr.Number() == 1062
	}
	return false
}

// ---- small helpers ---------------------------------------------------------------------------------

func nullIfEmpty(v string) any {
	if v == "" {
		return nil
	}
	return v
}

func nullableString(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullableFloat(v *float64) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullInt64(v *int64) any { return nullableInt(v) }

func nullIntPtr(v sql.NullInt64) *int64 {
	if !v.Valid {
		return nil
	}
	id := v.Int64
	return &id
}

func nullStringPtr(v sql.NullString) *string {
	if !v.Valid {
		return nil
	}
	s := v.String
	return &s
}

func derefStringPtr(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

func parseString(v string) float64 {
	f, err := strconv.ParseFloat(strings.TrimSpace(v), 64)
	if err != nil {
		return 0
	}
	return f
}

// loadOrderItemsTx reads the items the CURRENT transaction just inserted (the create path must see its own
// uncommitted rows, so it cannot use the post-commit loader).
func loadOrderItemsTx(ctx context.Context, tx *sql.Tx, orderID int64) ([]Item, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, product_id, product_variation_id, stock_source, sub_location_id,
			product_name_snapshot, variation_label_snapshot, sku_snapshot,
			CAST(unit_price_snapshot AS CHAR), CAST(agent_fee_amount AS CHAR), CAST(sales_fee_amount AS CHAR),
			CAST(courier_fee_amount AS CHAR), CAST(subtotal_snapshot AS CHAR),
			original_quantity, fulfilled_quantity, cancelled_quantity, returned_quantity, refund_quantity,
			additional_quantity, status, CAST(requested_delivery_date AS CHAR), shipment_id
		FROM order_items WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Item
	for rows.Next() {
		var (
			it                        Item
			variation, subLocation    sql.NullInt64
			label, sku, requestedDate sql.NullString
			unit, agentFee, salesFee  sql.NullString
			courierFee, subtotal      sql.NullString
			shipmentID                sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &it.ProductID, &variation, &it.StockSource, &subLocation,
			&it.ProductName, &label, &sku, &unit, &agentFee, &salesFee, &courierFee, &subtotal,
			&it.OriginalQuantity, &it.FulfilledQuantity, &it.CancelledQuantity, &it.ReturnedQuantity, &it.RefundQuantity,
			&it.AdditionalQuantity, &it.Status, &requestedDate, &shipmentID); err != nil {
			return nil, err
		}
		it.ProductVariationID = nullIntPtr(variation)
		it.SubLocationID = nullIntPtr(subLocation)
		it.VariationLabel = nullStringPtr(label)
		it.SKU = nullStringPtr(sku)
		it.UnitPrice = parseString(unit.String)
		it.AgentFee = parseString(agentFee.String)
		it.SalesFee = parseString(salesFee.String)
		it.CourierFee = parseString(courierFee.String)
		it.Subtotal = parseString(subtotal.String)
		it.RequestedDate = nullStringPtr(requestedDate)
		it.ShipmentID = nullIntPtr(shipmentID)
		out = append(out, it)
	}
	return out, rows.Err()
}
