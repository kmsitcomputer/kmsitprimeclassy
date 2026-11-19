package orders

// Rendering — OrderResource + OrderItemResource + PaymentSummaryService for a freshly created order.
//
// The role gates are the reference's own, and they are a SECURITY boundary, not formatting: a gudang or kurir
// viewer receives the operational projection only (no amounts, no payment summary, no ledger), while agent_fee is
// visible to agen/super_admin alone. Rendering every field for every viewer would leak the branch margin and the
// financial state of the order.
//
// `whenLoaded` semantics are reproduced faithfully: a key whose relation was not loaded is ABSENT from the JSON,
// not null. For a creation response the reference loads items, shipments.courier.user, paymentMethod and
// paymentTransactions.bankTransferVerification — so returns, sales, korsal, konsumen, delivery_verifications and
// fulfillment_shortage are all absent.

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

// publicBase is the APP_URL the asset URLs are built against (set once at boot).
var publicBase string

// SetPublicBase configures the absolute asset base used by the order/proof renderers.
func SetPublicBase(base string) { publicBase = strings.TrimRight(base, "/") }

// Viewer is the authenticated caller reduced to what rendering may consult.
type Viewer struct {
	ID      int64
	Roles   []string
	Role    string
	AgentID *int64
}

func (v Viewer) hasRole(roles ...string) bool {
	for _, want := range roles {
		for _, have := range v.Roles {
			if have == want {
				return true
			}
		}
	}
	return false
}

// seesFinancials is OrderResource's `$seesFinancials`.
func (v Viewer) seesFinancials() bool {
	return v.hasRole("super_admin", "agen", "admin", "keuangan", "konsumen", "sales", "sales-kurir-sub", "korsal")
}

// Render is OrderResource::toArray for the creation response.
func Render(ctx context.Context, s *Service, order *Order, viewer Viewer) (map[string]any, error) {
	seesFinancials := viewer.seesFinancials()
	financials := map[string]any{}
	if seesFinancials {
		summary, err := s.paymentSummary(ctx, order)
		if err != nil {
			return nil, err
		}
		financials = map[string]any{
			"payment_status":            order.PaymentStatus,
			"subtotal_amount":           money.String(order.Subtotal),
			"shipping_fee_amount":       money.String(order.ShippingFee),
			"admin_fee_amount":          money.String(order.AdminFee),
			"discount_amount":           money.String(order.DiscountAmount),
			"effective_discount_amount": money.String(effectiveDiscount(order.DiscountAmount, order.Subtotal)),
			"voucher_id":                nullableInt(order.VoucherID),
			"total_amount":              money.String(order.Total),
			"dp_amount":                 money.String(order.DPAmount),
			"paid_amount":               money.String(order.PaidAmount),
			"remaining_amount":          money.String(collectibleReceivable(order)),
			"payment_summary":           summary,
			"invoice_issued_at":         renderTimePtr(order.InvoiceIssuedAt),
			"due_date":                  renderDate(order.DueDate),
			"is_overdue":                isOverdue(order),
		}
	}

	shippingProvider := any(nil)
	var shipmentDates map[string]any
	if len(order.Shipments) > 0 {
		first := order.Shipments[0]
		if first.ShippingProviderCode != nil {
			shippingProvider = *first.ShippingProviderCode
		}
	}
	shipmentDates = map[string]any{}
	for _, shipment := range order.Shipments {
		date := shipmentDeliveryDate(order.Items, shipment.ID)
		shipmentDates[itoa(shipment.ID)] = map[string]any{
			"delivery_date": date,
			"status":        shipment.Status,
			"delivery_mode": shipment.DeliveryMode,
		}
	}

	out := map[string]any{
		"id":              order.ID,
		"order_no":        order.OrderNo,
		"status":          order.Status,
		"recipient_name":  order.RecipientName,
		"recipient_phone": order.RecipientPhone,
		"address":         order.Address,
		"province_id":     nullableString(order.ProvinceID),
		"regency_id":      nullableString(order.RegencyID),
		"district_id":     nullableString(order.DistrictID),
		"village_id":      nullableString(order.VillageID),
		"postal_code":     nullableString(order.PostalCode),
		// decimal(10,7) cast attributes serialise as STRINGS ("-6.9000000"), never as numbers.
		"latitude":               renderDecimal(order.Latitude, 7),
		"longitude":              renderDecimal(order.Longitude, 7),
		"shipping_provider":      shippingProvider,
		"reschedule_allowed":     rescheduleAllowed(order.Shipments),
		"delivery_date_estimate": renderDate(order.DeliveryDate),
		"cancellation_reason":    nullableString(order.CancellationReason),
		// G17: which authoritative origin owns the transaction. A CRM order is a different shape (staff-facing).
		"buyer_origin": buyerOrigin(order.CrmCustomerID != nil),
		// `couriers` is ONE entry per DISTINCT courier actually assigned (never a placeholder block).
		"couriers":                couriersFor(order.Shipments),
		"items":                   renderItems(order.Items, viewer, order.LoadItemShipment),
		"shipment_delivery_dates": shipmentDates,
		"delivery_groups":         deliveryGroups(order.Items),
		"created_at":              renderTime(order.CreatedAt),
	}
	for key, value := range financials {
		out[key] = value
	}

	// `viewer_can_download_invoice`: present for every authenticated viewer; the value is OrderPolicy::viewInvoice
	// (view() AND the invoice-audience role list) AND the invoice is issued or eligible.
	canDownload, err := s.viewerCanDownloadInvoice(ctx, viewer, order)
	if err != nil {
		return nil, err
	}
	out["viewer_can_download_invoice"] = canDownload

	// `crm_customer` is `when(isCrmOrigin(), ...)`: ABSENT for an account-origin order.
	if order.CrmCustomerID != nil {
		out["crm_customer"] = crmCustomer(order)
	}

	// `agent_id` is branch ROUTING data, exposed only to the courier-assignment roles.
	if viewer.hasRole("super_admin", "agen", "admin", "koordinator-kurir") {
		out["agent_id"] = order.AgentID
	}
	// `placed_by_user_id` and the referral chain are internal identity — never shown to a konsumen.
	if !viewer.hasRole("konsumen") {
		out["placed_by_user_id"] = nullableInt(order.PlacedByUserID)
	}

	// The cancel/show controllers load konsumen, sales and korsal (create does not). konsumen renders for
	// every viewer; sales/korsal are internal referral-chain identity, hidden from the konsumen's own view.
	if order.LoadActors {
		if order.KonsumenID != nil {
			if konsumen, err := s.loadKonsumenUser(order.KonsumenID); err == nil && konsumen != nil {
				out["konsumen"] = konsumen
			} else if err != nil {
				return nil, err
			}
		} else {
			// A CRM-origin order has no account buyer: whenLoaded('konsumen') renders the key as null.
			out["konsumen"] = nil
		}
		if !viewer.hasRole("konsumen") {
			if order.SalesID != nil {
				if sales, err := s.loadTinyUser(order.SalesID); err == nil && sales != nil {
					out["sales"] = sales
				} else if err != nil {
					return nil, err
				}
			} else {
				out["sales"] = nil
			}
			if order.KorsalID != nil {
				if korsal, err := s.loadTinyUser(order.KorsalID); err == nil && korsal != nil {
					out["korsal"] = korsal
				} else if err != nil {
					return nil, err
				}
			} else {
				out["korsal"] = nil
			}
		}
	}

	if seesFinancials && order.LoadPaymentMethod && order.PaymentMethod != nil {
		out["payment_method"] = map[string]any{
			"code": order.PaymentMethod.Code,
			"name": order.PaymentMethod.Name,
			"type": order.PaymentMethod.Type,
		}
	}
	// `payment_transaction` renders only when the paymentTransactions relation was loaded (and non-empty):
	// `when(seesFinancials && relationLoaded('paymentTransactions') && isNotEmpty, ...)`.
	if seesFinancials && order.LoadPaymentTransactions && order.Payment != nil {
		out["payment_transaction"] = renderPaymentTransaction(order.Payment,
			order.LoadTransactionVerification, order.LoadTransactionCodProof)
	}
	// `returns`: present only when the returnRequests relation was loaded — the SHOW controller loads it,
	// the list and every payment response leave it absent.
	if order.LoadReturns {
		out["returns"] = []any{}
	}
	// `fulfillment_shortage`: gated on relationLoaded('paymentTransactions') && the Admin-decision roles.
	if order.LoadPaymentTransactions && viewer.hasRole("super_admin", "agen", "admin") {
		shortage, err := s.fulfillmentShortageForOrder(ctx, order.ID)
		if err != nil {
			return nil, err
		}
		out["fulfillment_shortage"] = shortage
	}
	// `delivery_verifications`: the append-only Admin history — order-level relation, Admin/Super Admin only.
	if order.LoadDeliveryVerifications && viewer.hasRole("super_admin", "admin") {
		verifications, err := s.loadDeliveryVerifications(ctx, order.ID)
		if err != nil {
			return nil, err
		}
		out["delivery_verifications"] = verifications
	}
	return out, nil
}

// RenderItem renders ONE order item exactly as OrderItemResource does for a `fresh()` model: the shipment
// relation is NOT loaded, so delivery_mode / self_delivered_by_user_id / courier / delivery_proof_url are
// absent from the payload (they are `when(relationLoaded('shipment'))`).
func RenderItem(item Item, viewer Viewer) map[string]any {
	return renderItems([]Item{item}, viewer, false)[0].(map[string]any)
}

func renderItems(items []Item, viewer Viewer, withShipment bool) []any {
	financials := viewer.seesFinancials()
	out := make([]any, 0, len(items))
	for _, item := range items {
		rendered := map[string]any{
			"id":                      item.ID,
			"product_id":              item.ProductID,
			"product_variation_id":    nullableInt(item.ProductVariationID),
			"stock_source":            item.StockSource,
			"sub_location_id":         nullableInt(item.SubLocationID),
			"product_name":            item.ProductName,
			"variation_label":         nullableString(item.VariationLabel),
			"sku":                     nullableString(item.SKU),
			"original_quantity":       item.OriginalQuantity,
			"fulfilled_quantity":      item.FulfilledQuantity,
			"cancelled_quantity":      item.CancelledQuantity,
			"returned_quantity":       item.ReturnedQuantity,
			"refund_quantity":         item.RefundQuantity,
			"additional_quantity":     item.AdditionalQuantity,
			"status":                  item.Status,
			"requested_delivery_date": renderDate(item.RequestedDate),
			"shipment_id":             nullableInt(item.ShipmentID),
		}
		// Loaded shipment context (R-03): only when the calling controller loaded items.shipment
		// (show/cancel), never on the fresh-create payload.
		if withShipment {
			rendered["delivery_mode"] = nullableString(item.DeliveryMode)
			rendered["self_delivered_by_user_id"] = nullableInt(item.SelfDeliveredBy)
		}
		if financials {
			rendered["unit_price"] = money.String(item.UnitPrice)
			rendered["subtotal"] = money.String(item.Subtotal)
		}
		// Fee amounts are commission data with per-role visibility — the branch margin (agent_fee) is
		// agen/super_admin only, never admin/keuangan.
		if viewer.hasRole("super_admin", "agen") {
			rendered["agent_fee_amount"] = money.String(item.AgentFee)
		}
		if viewer.hasRole("super_admin", "agen", "sales", "sales-kurir-sub", "admin", "keuangan") {
			rendered["sales_fee_amount"] = money.String(item.SalesFee)
		}
		if viewer.hasRole("super_admin", "agen", "admin", "keuangan") {
			rendered["courier_fee_amount"] = money.String(item.CourierFee)
		}
		out = append(out, rendered)
	}
	return out
}

// renderPaymentTransaction is PaymentTransactionResource::toArray. `whenLoaded` semantics rule the two
// verification keys: a relation the controller did NOT load OMITS the key entirely, while a loaded-but-null
// relation renders null. Laravel:
//
//	whenLoaded('bankTransferVerification', fn () => ...) — func_num_args() < 3 → default = MissingValue,
//	so an unloaded relation drops the key; a loaded one renders null or the object.
func renderPaymentTransaction(p *PaymentTransaction, loadVerification, loadCodProof bool) map[string]any {
	var instructions any
	if p.Instructions != nil {
		instructions = p.Instructions
	}
	out := map[string]any{
		"id":                p.ID,
		"type":              p.Type,
		"amount":            money.String(p.Amount),
		"status":            p.Status,
		"gateway_reference": nullableString(p.GatewayReference),
		"instructions":      instructions,
		"paid_at":           renderTimePtr(p.PaidAt),
	}
	if loadVerification {
		out["bank_transfer_verification"] = renderBankVerification(p.Verification, p.Amount)
	}
	if loadCodProof {
		out["cod_payment_proof"] = renderCodProof(p.CodProof)
	}
	return out
}

// renderBankVerification is the bank_transfer_verification sub-object.
func renderBankVerification(v *BankTransferVerification, fallbackAmount float64) any {
	if v == nil {
		return nil
	}
	var proofURL any
	if v.ProofImagePath != "" {
		if v.ProofDisk == "local" {
			proofURL = fmt.Sprintf("/api/v1/payment-proofs/%d", v.ID)
		} else {
			proofURL = storageURL(v.ProofImagePath)
		}
	}
	return map[string]any{
		"id":                       v.ID,
		"status":                   v.Status,
		"proof_url":                proofURL,
		"proof_private":            v.ProofDisk == "local",
		"channel":                  v.Channel,
		"submitted_amount":         submittedAmountOr(v.SubmittedAmount, fallbackAmount),
		"verified_amount":          moneyPtrOrNil(v.VerifiedAmount),
		"amount_adjustment_reason": nullableString(v.AmountAdjustmentReason),
		"cash_received_by":         v.CashReceivedBy,
		"cash_received_at":         renderTimePtr(v.CashReceivedAt),
		"receipt_reference":        nullableString(v.ReceiptReference),
		"rejection_reason":         nullableString(v.RejectionReason),
		"submitted_on_behalf":      v.SubmittedOnBehalf,
		"submitted_by":             v.SubmittedBy,
		"paid_by":                  nullableString(v.PaidByRole),
		"submitted_at":             renderTimePtr(v.SubmittedAt),
		"verified_by":              v.VerifiedByUser,
		"verified_at":              renderTimePtr(v.VerifiedAt),
	}
}

// renderCodProof is the cod_payment_proof sub-object.
func renderCodProof(p *CodPaymentProof) any {
	if p == nil {
		return nil
	}
	var proofURL any
	if p.ProofPath != "" {
		proofURL = storageURL(p.ProofPath)
	}
	return map[string]any{
		"id":                  p.ID,
		"status":              p.Status,
		"proof_url":           proofURL,
		"rejection_reason":    nullableString(p.RejectionReason),
		"submitted_on_behalf": p.SubmittedOnBehalf,
		"submitted_by":        p.SubmittedBy,
		"paid_by":             nullableString(p.PaidByRole),
		"submitted_at":        renderTimePtr(p.SubmittedAt),
		"verified_by":         p.ConfirmedByUser,
		"verified_at":         renderTimePtr(p.ConfirmedAt),
	}
}

// couriersFor is OrderResource::couriers — ONE entry per DISTINCT courier across the order's shipments,
// in shipment order (pluck → filter → unique('id') keeps the first occurrence).
func couriersFor(shipments []Shipment) []any {
	out := []any{}
	seen := map[int64]bool{}
	for _, shipment := range shipments {
		if shipment.CourierID == nil || shipment.CourierName == nil || seen[*shipment.CourierID] {
			continue
		}
		seen[*shipment.CourierID] = true
		out = append(out, map[string]any{
			"name":  *shipment.CourierName,
			"phone": nullableString(shipment.CourierPhone),
		})
	}
	return out
}

// buyerOrigin is Order::isCrmOrigin ? 'crm' : 'account'.
func buyerOrigin(crm bool) string {
	if crm {
		return "crm"
	}
	return "account"
}

// crmCustomer is the staff-facing CRM identity block, present only on CRM-origin orders.
func crmCustomer(order *Order) any {
	if order.CrmCustomerID == nil {
		return nil
	}
	return map[string]any{
		"id":    *order.CrmCustomerID,
		"name":  nullableString(order.BuyerNameSnapshot),
		"phone": nullableString(order.BuyerPhoneSnapshot),
	}
}

// storageURL mirrors Storage::disk('public')->url(): APP_URL + /storage + path (relative when unset).
func storageURL(path string) string {
	base := publicBase
	if base == "" {
		return "/storage/" + strings.TrimLeft(path, "/")
	}
	return base + "/storage/" + strings.TrimLeft(path, "/")
}

func submittedAmountOr(v *float64, fallback float64) any {
	if v == nil {
		return money.String(fallback)
	}
	return money.String(*v)
}

func moneyPtrOrNil(v *float64) any {
	if v == nil {
		return nil
	}
	return money.String(*v)
}

// deliveryGroups is the derived R-03 projection: one group per requested delivery date. There is no group table;
// the order-level payment truth is never duplicated per group.
func deliveryGroups(items []Item) []any {
	order := []string{}
	byDate := map[string][]Item{}
	for _, item := range items {
		key := derefStringPtr(item.RequestedDate)
		if _, seen := byDate[key]; !seen {
			order = append(order, key)
		}
		byDate[key] = append(byDate[key], item)
	}
	out := make([]any, 0, len(order))
	for _, key := range order {
		group := byDate[key]
		ids := make([]any, 0, len(group))
		shipments := make([]any, 0, len(group))
		seenShipment := map[int64]bool{}
		totalQty := 0
		for _, item := range group {
			ids = append(ids, item.ID)
			totalQty += item.FulfilledQuantity
			if item.ShipmentID != nil && !seenShipment[*item.ShipmentID] {
				seenShipment[*item.ShipmentID] = true
				shipments = append(shipments, *item.ShipmentID)
			}
		}
		// delivery_groups uses `?->toDateString()`, so this one field IS the bare date even though the
		// `requested_delivery_date` attribute itself serialises as a timestamp.
		var date any
		if key != "" {
			date = key
		}
		out = append(out, map[string]any{
			"delivery_date":  date,
			"item_ids":       ids,
			"item_count":     len(group),
			"total_quantity": totalQty,
			"shipment_ids":   shipments,
		})
	}
	return out
}

// shipmentDeliveryDate reports the shipment's single canonical date, or null when unset/mixed (never inventing one).
func shipmentDeliveryDate(items []Item, shipmentID int64) any {
	dates := map[string]bool{}
	for _, item := range items {
		if item.ShipmentID != nil && *item.ShipmentID == shipmentID && item.RequestedDate != nil {
			dates[*item.RequestedDate] = true
		}
	}
	if len(dates) != 1 {
		return nil
	}
	for date := range dates {
		return date
	}
	return nil
}

// rescheduleAllowed: 'openroute' (Kurir Toko) and 'self_sub' may reschedule; 'rajaongkir' and 'pickup' may not.
func rescheduleAllowed(shipments []Shipment) bool {
	for _, shipment := range shipments {
		if shipment.DeliveryMode == "self_sub" {
			return true
		}
		if shipment.ShippingProviderCode != nil && *shipment.ShippingProviderCode == "openroute" {
			return true
		}
	}
	return false
}

// effectiveDiscount is Order::effectiveDiscountAmount.
func effectiveDiscount(discount, subtotal float64) float64 {
	if subtotal < 0 {
		subtotal = 0
	}
	if discount > subtotal {
		return subtotal
	}
	return discount
}

// collectibleReceivable is Support\CollectibleReceivable::forOrder (WS-7 M-2).
func collectibleReceivable(order *Order) float64 {
	if order.Status == "dibatalkan" {
		return 0
	}
	value := money.Round2(order.Total - order.PaidAmount)
	if value < 0 {
		return 0
	}
	return value
}

func isOverdue(order *Order) bool {
	if order.DueDate == nil || order.Status == "dibatalkan" {
		return false
	}
	due, err := time.Parse("2006-01-02", *order.DueDate)
	if err != nil {
		return false
	}
	today := time.Now().UTC().Truncate(24 * time.Hour)
	return due.Before(today) && collectibleReceivable(order) > 0
}

// paymentSummary is PaymentSummaryService::summarize for a freshly created order.
func (s *Service) paymentSummary(ctx context.Context, order *Order) (map[string]any, error) {
	requestedDP := order.DPAmount
	verifiedDP := 0.0
	if requestedDP > 0 {
		verifiedDP = requestedDP
		if order.PaidAmount < verifiedDP {
			verifiedDP = order.PaidAmount
		}
	}

	regularCount, err := s.regularPaymentCount(ctx, order.ID)
	if err != nil {
		return nil, err
	}
	limit, err := s.regularPaymentLimit(ctx, order)
	if err != nil {
		return nil, err
	}

	overpaid := order.PaidAmount - order.Total
	if overpaid < 0 {
		overpaid = 0
	}

	return map[string]any{
		"grand_total":                 order.Total,
		"requested_dp":                requestedDP,
		"verified_dp":                 verifiedDP,
		"submitted_dp":                0.0,
		"total_paid":                  order.PaidAmount,
		"regular_payments_count":      regularCount,
		"regular_payments_limit":      limit,
		"remaining_balance":           collectibleReceivable(order),
		"overpaid_amount":             overpaid,
		"payment_status":              order.PaymentStatus,
		"is_fully_paid":               order.PaymentStatus == "paid" && order.RemainingAmount <= 0,
		"pending_verification_status": nil,
		"has_pending_proof":           false,
		"additional_payment_amount":   0.0,
		"additional_payment_status":   nil,
		"refund_amount":               0.0,
		"refund_status":               nil,
	}, nil
}

// regularPaymentCount is PaymentService::regularPaymentCountFor — successful (paid/refunded) regular payments.
func (s *Service) regularPaymentCount(ctx context.Context, orderID int64) (int, error) {
	var count int
	err := s.Database.QueryRowContext(ctx, `SELECT COUNT(*) FROM payment_transactions
		WHERE order_id = ? AND status IN ('paid','refunded') AND type <> 'additional_payment'`, orderID).Scan(&count)
	return count, err
}

// regularPaymentLimit is PaymentService::regularPaymentLimitFor. The channel is derived from PERSISTED shipment
// data, never from delivery_mode alone: expedition first, `other` for pickup/empty/non-standard, else kurir_toko.
func (s *Service) regularPaymentLimit(ctx context.Context, order *Order) (*int, error) {
	var (
		total   int
		exped   int
		special int
	)
	err := s.Database.QueryRowContext(ctx, `SELECT COUNT(*),
			COALESCE(SUM(shipping_provider_code = 'rajaongkir'), 0),
			COALESCE(SUM(delivery_mode <> 'standard' OR shipping_provider_code = 'pickup'), 0)
		FROM shipments WHERE order_id = ?`, order.ID).Scan(&total, &exped, &special)
	if err != nil {
		return nil, err
	}
	if exped > 0 {
		return nil, nil
	}
	if total == 0 || special > 0 {
		return nil, nil
	}
	limit := 3 // KURIR_TOKO_MAX_SUCCESSFUL_PAYMENTS
	return &limit, nil
}

// ---- serialisation helpers -------------------------------------------------------------------------

func itoa(v int64) string { return formatInt(v) }

// renderDecimal renders a `decimal:N` cast attribute as the STRING Eloquent produces ("-6.9000000"). A null stays
// null; a value is never re-rounded, so the stored precision is what the client sees.
func renderDecimal(v *float64, places int) any {
	if v == nil {
		return nil
	}
	return strconv.FormatFloat(*v, 'f', places, 64)
}

func renderTime(t time.Time) string {
	return t.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// renderDate renders a DATE-cast attribute the way Eloquent does: an ISO timestamp at midnight UTC, never a bare
// "YYYY-MM-DD". This is the same trap recorded for the promo domain (docs/21b D-7).
func renderDate(v *string) any {
	if v == nil || *v == "" {
		return nil
	}
	parsed, err := time.Parse("2006-01-02", *v)
	if err != nil {
		return nil
	}
	return renderTime(parsed)
}

func renderTimePtr(t *time.Time) any {
	if t == nil {
		return nil
	}
	return renderTime(*t)
}
