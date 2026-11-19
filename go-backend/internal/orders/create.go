package orders

// Order creation — the single most consequential write in the system.
//
// Ported from OrderService::createOrder (1,181 lines) with its dependencies: StockSourceResolver,
// InvoiceNumberService, FeeService, PaymentService::initiate, StockRequestService, OrderInitialSnapshotService,
// and the canonical shipment grouping rule (Human UAT-005).
//
// The order of operations IS the correctness argument, so it is stated once here and mirrored in the code:
//
//  1. IDEMPOTENCY BEFORE ANYTHING ELSE. A replay with the same Idempotency-Key returns the EXISTING order and
//     must never burn an invoice sequence number or reserve stock twice. The database unique index is the final
//     arbiter: two concurrent requests both pass the pre-check, one wins, and the loser is answered with the
//     winner's order instead of a 500.
//  2. EVERY LINE IS RESOLVED TO ITS ONE INVENTORY TARGET BEFORE ANY LOCK IS TAKEN, so the prelocked target can
//     never differ from the target actually reserved.
//  3. ALL TARGET LOCKS ARE TAKEN IN THE CANONICAL ORDER (internal/inventory), before the per-line reserve loop.
//     Cart order is preserved for the per-line loop only, so order-item ordering is untouched.
//  4. MONEY IS COMPUTED BY internal/pricing (the same code the checkout preview used), so the persisted total
//     can never disagree with what the customer was shown.
//  5. THE INVOICE NUMBER IS ALLOCATED INSIDE THE TRANSACTION under a row lock on the settings row — never from a
//     bare MAX() — and the allocation happens AFTER the voucher redemption so a failed checkout does not consume
//     a sequence number.
//  6. PAYMENT METHOD ELIGIBILITY IS RE-EVALUATED AGAINST THE RESOLVED PROVIDER, never the client's hint.
//
// MONEY: every amount goes through internal/money.Round2 (PHP-compatible), matching the reference exactly.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

// Line is one requested cart line.
type Line struct {
	ProductID          int64
	ProductVariationID *int64
	Quantity           int
}

// Destination mirrors the checkout destination shape.
type Destination = checkout.Destination

// Input is the validated create request.
type Input struct {
	ActorID    int64
	KonsumenID int64

	Lines       []Line
	Destination Destination

	PaymentMethodCode string
	DeliveryDate      *string
	IdempotencyKey    string
	ShippingMethod    *string
	DPAmount          *float64
	Courier           *string
	Service           *string
	StockSource       *string
	SubLocationID     *int64
	VoucherCode       *string
}

// Order is the persisted order, carrying everything the resource renders.
type Order struct {
	ID                 int64
	OrderNo            string
	Status             string
	PaymentStatus      string
	Subtotal           float64
	ShippingFee        float64
	AdminFee           float64
	DiscountAmount     float64
	VoucherID          *int64
	Total              float64
	DPAmount           float64
	PaidAmount         float64
	RemainingAmount    float64
	RecipientName      string
	RecipientPhone     string
	Address            string
	VillageSnapshot    *string
	DistrictSnapshot   *string
	RegencySnapshot    *string
	ProvinceSnapshot   *string
	Latitude           *float64
	Longitude          *float64
	ProvinceID         *string
	RegencyID          *string
	DistrictID         *string
	VillageID          *string
	PostalCode         *string
	DeliveryDate       *string
	DeliveryActual     *time.Time
	CancellationReason *string
	InvoiceIssuedAt    *time.Time
	DueDate            *string
	KonsumenID         *int64
	AgentID            int64
	SalesID            *int64
	KorsalID           *int64
	PlacedByUserID     *int64
	BuyerNameSnapshot  *string
	BuyerPhoneSnapshot *string
	CrmCustomerID      *int64
	CreatedAt          time.Time

	Items         []Item
	Shipments     []Shipment
	Payment       *PaymentTransaction
	PaymentMethod *PaymentMethod
	// LoadActors mirrors create vs show/cancel relation loading: create() loads ONLY
	// items + shipments.courier.user + paymentMethod + paymentTransactions, so konsumen/sales/korsal are ABSENT
	// there. show()/cancel() load them. LoadItemShipment gates the item's delivery_mode/self_delivered_by_user_id.
	LoadActors       bool
	LoadItemShipment bool
	// LoadFinancialRelations gates the payment_transaction + returns keys: the SHOW controller loads
	// paymentTransactions and returnRequests, the INDEX does NOT (so those keys are absent from the list).
	LoadFinancialRelations bool
}

// Item is one persisted order line.
type Item struct {
	ID                 int64
	ProductID          int64
	ProductVariationID *int64
	StockSource        string
	SubLocationID      *int64
	ProductName        string
	VariationLabel     *string
	SKU                *string
	UnitPrice          float64
	AgentFee           float64
	SalesFee           float64
	CourierFee         float64
	Subtotal           float64
	OriginalQuantity   int
	FulfilledQuantity  int
	CancelledQuantity  int
	ReturnedQuantity   int
	RefundQuantity     int
	AdditionalQuantity int
	Status             string
	RequestedDate      *string
	ShipmentID         *int64
	DeliveryMode       *string
	SelfDeliveredBy    *int64
}

// Shipment is one canonical delivery unit.
type Shipment struct {
	ID                   int64
	CourierID            *int64
	ShippingProviderID   *int64
	ShippingProviderCode *string
	Status               string
	DeliveryMode         string
	SelfDeliveredBy      *int64
	DistanceKm           *float64
	RatePerKm            *float64
	ShippingFeeSnapshot  float64
}

// PaymentMethod is the chosen method.
type PaymentMethod struct {
	ID   int64
	Code string
	Name string
	Type string
}

// PaymentTransaction is the initiated transaction.
type PaymentTransaction struct {
	ID               int64
	Type             string
	Amount           float64
	Status           string
	GatewayReference *string
	// Instructions is the decoded raw_payload (the reference's `instructions` key).
	Instructions map[string]any
}

// Problem is a domain failure with an API status.
type Problem = checkout.Problem

// Service creates orders.
type Service struct {
	Database *db.DB
	AppKey   string
	Shipping config.ShippingConfig
	Messages func(key string, params ...string) string
	// IP and UserAgent are recorded on the audit row, exactly like ActivityLogger::log does.
	IP        string
	UserAgent string
}

// Create is OrderService::createOrder for the account-origin path.
func (s *Service) Create(ctx context.Context, in Input) (*Order, bool, error) {
	// 1. Idempotency pre-check: a replay returns the existing order, untouched.
	if existing, err := s.findByIdempotencyKey(ctx, in.KonsumenID, in.IdempotencyKey); err != nil {
		return nil, false, err
	} else if existing != nil {
		return existing, true, nil
	}

	method, err := s.resolvePaymentMethod(ctx, in.PaymentMethodCode)
	if err != nil {
		return nil, false, err
	}

	stockSource, subLocationID, err := s.resolveStockSource(ctx, in)
	if err != nil {
		return nil, false, err
	}

	agentID, err := s.agentBranchOf(ctx, in.KonsumenID)
	if err != nil {
		return nil, false, err
	}
	if agentID == nil {
		return nil, false, &Problem{Status: 422, Message: s.Messages("order.no_agent_branch")}
	}
	if len(in.Lines) == 0 {
		return nil, false, &Problem{Status: 422, Message: s.Messages("order.empty_items")}
	}

	order, err := s.createInTransaction(ctx, in, *agentID, method, stockSource, subLocationID)
	if err != nil {
		// Two concurrent requests with the same key: the unique index rejected the loser. Answer with the
		// winner's order — a replay is not an error.
		if isDuplicateKey(err) && in.IdempotencyKey != "" {
			if existing, ferr := s.findByIdempotencyKey(ctx, in.KonsumenID, in.IdempotencyKey); ferr == nil && existing != nil {
				return existing, true, nil
			}
		}
		return nil, false, err
	}
	return order, false, nil
}

func (s *Service) createInTransaction(ctx context.Context, in Input, agentID int64, method PaymentMethod, stockSource string, subLocationID *int64) (*Order, error) {
	tx, err := s.Database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	quoter := s.quoter()

	profile, err := shipping.PickupLocation(ctx, s.Database, &agentID)
	if err != nil {
		return nil, err
	}
	if profile == nil {
		return nil, &Problem{Status: 422, Message: s.Messages("order.agent_profile_incomplete")}
	}

	dest, err := quoter.ResolveDestination(ctx, in.KonsumenID, in.Destination)
	if err != nil {
		return nil, err
	}

	// 2. Resolve every line to its authoritative inventory target BEFORE any lock.
	type pending struct {
		resolved checkout.ResolvedLine
		line     Line
	}
	var resolved []pending
	targets := make([]inventory.Target, 0, len(in.Lines))
	for _, line := range in.Lines {
		r, rerr := quoter.ResolveLine(ctx, checkout.QuoteLine{
			ProductID: line.ProductID, ProductVariationID: line.ProductVariationID, Quantity: line.Quantity,
		})
		if rerr != nil {
			return nil, rerr
		}
		if r.Quantity < 1 {
			return nil, &Problem{Status: 422, Message: s.Messages("order.invalid_quantity")}
		}
		resolved = append(resolved, pending{resolved: r, line: line})
		if r.Variation != nil {
			targets = append(targets, inventory.VariationTarget(r.Variation.ID))
		} else {
			targets = append(targets, inventory.ProductTarget(r.Product.ID))
		}
	}

	// 3. Canonical lock order, then the per-line loop in CART order.
	if stockSource == stockSourceSub {
		location, lerr := s.lockSubLocationForCheckout(ctx, tx, *subLocationID, in.ActorID, agentID)
		if lerr != nil {
			return nil, lerr
		}
		subLocationID = &location.ID
		if lerr := inventory.LockSubReservationTargets(ctx, tx, *subLocationID, targets); lerr != nil {
			return nil, lerr
		}
	} else if err := inventory.LockReservationTargets(ctx, tx, agentID, targets); err != nil {
		return nil, err
	}

	// Placeholder order, exactly like the reference: the real order_no needs the invoice sequence, which is
	// allocated only after nothing else can fail cheaply.
	initialStatus := "diterima"
	if method.Type == "cod" {
		initialStatus = "diproses"
	}

	orderID, err := s.insertPlaceholder(ctx, tx, in, agentID, method, initialStatus, dest)
	if err != nil {
		return nil, err
	}

	// 4. Price + reserve each line, snapshotting fees and recording commissions.
	var (
		subtotal    float64
		totalWeight int
	)
	ref := inventory.ReservationReference{Type: "order", ID: orderID, CreatedBy: &in.ActorID}
	for _, p := range resolved {
		lineSubtotal, lineWeight, perr := s.priceAndReserveLine(ctx, tx, quoter, orderID, agentID, in, p.resolved, stockSource, subLocationID, ref, initialStatus)
		if perr != nil {
			return nil, perr
		}
		subtotal += lineSubtotal
		totalWeight += lineWeight
	}
	subtotal = money.Round2(subtotal)

	adminFee, err := quoter.AdminFee(ctx)
	if err != nil {
		return nil, err
	}

	originLat, originLng := 0.0, 0.0
	if profile.Latitude != nil {
		originLat = *profile.Latitude
	}
	if profile.Longitude != nil {
		originLng = *profile.Longitude
	}
	destLat, destLng := 0.0, 0.0
	if dest.Latitude != nil {
		destLat = *dest.Latitude
	}
	if dest.Longitude != nil {
		destLng = *dest.Longitude
	}

	var courier *shipping.CourierOption
	if in.Courier != nil && in.Service != nil {
		courier = &shipping.CourierOption{Courier: *in.Courier, Service: *in.Service}
	}
	quote, err := s.quoter().Shipping.Quote(ctx, shipping.Context{
		OriginLat: originLat, OriginLng: originLng, DestLat: destLat, DestLng: destLng,
		DestRegencyID: dest.RegencyID, DestVillageID: dest.VillageID,
		WeightGrams: totalWeight, Subtotal: subtotal, AgentID: agentID,
		PreferredProvider: in.ShippingMethod, SelectedCourier: courier,
	})
	if err != nil {
		var se *shipping.QuoteError
		if errors.As(err, &se) {
			return nil, &Problem{
				Status:  422,
				Message: s.Messages("shipping.method_not_available"),
				Fields:  map[string]string{"shipping_method": s.Messages("system.field_invalid")},
			}
		}
		return nil, err
	}

	// 6. Payment method eligibility is re-evaluated against the RESOLVED provider.
	if err := s.assertPaymentMethodAllowed(ctx, agentID, quote.ProviderCode, method, in.ActorID); err != nil {
		return nil, err
	}
	shippingFee := quote.Cost

	// Voucher: resolve, consume under the row lock, then compute the canonical budget.
	var (
		voucherID       *int64
		voucherDiscount float64
	)
	if in.VoucherCode != nil && strings.TrimSpace(*in.VoucherCode) != "" {
		voucher, verr := quoter.LoadVoucher(ctx, agentID, strings.TrimSpace(*in.VoucherCode))
		if verr != nil {
			return nil, verr
		}
		consumed, cerr := s.consumeVoucher(ctx, tx, agentID, voucher.ID)
		if cerr != nil {
			return nil, cerr
		}
		if !consumed {
			return nil, &Problem{
				Status:  422,
				Message: s.Messages("voucher.invalid"),
				Fields:  map[string]string{"voucher_code": s.Messages("voucher.invalid")},
			}
		}
		id := voucher.ID
		voucherID = &id
		resolvedLines := make([]checkout.ResolvedLine, 0, len(resolved))
		for _, p := range resolved {
			resolvedLines = append(resolvedLines, p.resolved)
		}
		voucherDiscount, verr = quoter.VoucherBudgetForLines(ctx, agentID, voucher, resolvedLines)
		if verr != nil {
			return nil, verr
		}
	}

	total := money.Round2(subtotal - voucherDiscount + shippingFee + adminFee)

	// 5. Invoice number: inside the transaction, under a row lock, after the redemption.
	invoiceNo, err := allocateInvoiceNumber(ctx, tx, time.Now().UTC())
	if err != nil {
		return nil, err
	}

	dpAmount, paidAmount, remaining := 0.0, 0.0, 0.0
	if method.Code == "down_payment" {
		if in.DPAmount == nil || *in.DPAmount <= 0 || *in.DPAmount >= total {
			return nil, &Problem{
				Status:  422,
				Message: s.Messages("payment.invalid_dp_amount"),
				Fields:  map[string]string{"dp_amount": s.Messages("payment.invalid_dp_amount")},
			}
		}
		dpAmount = money.Round2(*in.DPAmount)
		remaining = total
	}

	if _, err := tx.ExecContext(ctx, `UPDATE orders SET order_no = ?, subtotal_amount = ?, shipping_fee_amount = ?,
			admin_fee_amount = ?, discount_amount = ?, voucher_id = ?, total_amount = ?,
			dp_amount = ?, paid_amount = ?, remaining_amount = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`,
		invoiceNo, subtotal, shippingFee, adminFee, money.Round2(voucherDiscount), nullableInt(voucherID), total,
		dpAmount, paidAmount, remaining, orderID); err != nil {
		return nil, err
	}

	var providerRowID *int64
	if quote.ProviderCode != "free" && quote.ProviderCode != "pickup" {
		if row, perr := providerRow(ctx, tx, quote.ProviderCode); perr != nil {
			return nil, perr
		} else if row != nil {
			providerRowID = &row.ID
		}
	}

	// UAT-005 LOCKED grouping rule: items sharing (requested delivery date, delivery mode, Sub owner) share ONE
	// shipment. The fee snapshot lives on the FIRST shipment only — the order-level shipping_fee_amount is the
	// authoritative charge, duplicating it would look like N charges.
	deliveryMode := "standard"
	if stockSource == stockSourceSub {
		deliveryMode = "self_sub"
	}
	var subOwner *int64
	if stockSource == stockSourceSub && subLocationID != nil {
		owner, oerr := subLocationOwner(ctx, tx, *subLocationID)
		if oerr != nil {
			return nil, oerr
		}
		subOwner = owner
	}

	items, err := loadOrderItemsTx(ctx, tx, orderID)
	if err != nil {
		return nil, err
	}
	shipmentByGroup := map[string]int64{}
	shipmentIndex := 0
	for _, item := range items {
		groupKey := fmt.Sprintf("%s|%s|%s", derefString(item.RequestedDate), deliveryMode, derefInt(subOwner))
		shipmentID, ok := shipmentByGroup[groupKey]
		if !ok {
			shipmentID, err = insertShipment(ctx, tx, shipmentInput{
				OrderID: orderID, ProviderRowID: providerRowID, ProviderCode: quote.ProviderCode,
				OriginLat: originLat, OriginLng: originLng, DestLat: destLat, DestLng: destLng,
				DistanceKm: quote.DistanceKm, RatePerKm: quote.RatePerKm,
				Meta: quote.Meta, DeliveryMode: deliveryMode, SelfDeliveredBy: subOwner,
				First: shipmentIndex == 0, ShippingFee: shippingFee,
			})
			if err != nil {
				return nil, err
			}
			shipmentByGroup[groupKey] = shipmentID
			shipmentIndex++
		}
		if _, err := tx.ExecContext(ctx, `UPDATE order_items SET shipment_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
			shipmentID, item.ID); err != nil {
			return nil, err
		}
	}

	// Stock request for an order that starts in `diproses` (COD), Agent-sourced items only.
	if initialStatus == "diproses" {
		if _, err := s.createStockRequest(ctx, tx, orderID, agentID, in.ActorID); err != nil {
			return nil, err
		}
	}

	paymentTxnID, err := s.initiatePayment(ctx, tx, orderID, agentID, method, total, dpAmount)
	if err != nil {
		return nil, err
	}

	// Owner rule: a COD invoice is issued as soon as the order exists; transfer invoices wait for the first
	// verified payment. syncAfterChange issues AND freezes the v1 balance snapshot, exactly like the reference.
	if method.Type == "cod" {
		if err := s.syncInvoiceAfterChange(ctx, tx, orderID, in.ActorID, "order_created_cod"); err != nil {
			return nil, err
		}
	}

	if err := s.captureInitialSnapshot(ctx, tx, orderID, invoiceNo, method); err != nil {
		return nil, err
	}

	if err := logActivity(ctx, tx, in.ActorID, orderID, "order.created", map[string]any{
		"total_amount":      total,
		"item_count":        len(in.Lines),
		"konsumen_id":       in.KonsumenID,
		"crm_customer_id":   nil,
		"placed_by_user_id": in.ActorID,
		"shipping_provider": quote.ProviderCode,
	}, s.IP, s.UserAgent); err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}

	return s.loadOrder(ctx, orderID, paymentTxnID)
}

// priceAndReserveLine is OrderService::priceAndReserveLine.
func (s *Service) priceAndReserveLine(ctx context.Context, tx *sql.Tx, quoter *checkout.Quoter, orderID, agentID int64, in Input, resolved checkout.ResolvedLine, stockSource string, subLocationID *int64, ref inventory.ReservationReference, initialItemStatus string) (float64, int, error) {
	qty := resolved.Quantity
	if qty < 1 {
		return 0, 0, &Problem{Status: 422, Message: s.Messages("order.invalid_quantity")}
	}

	unitPrice, unitWeight, err := quoter.UnitPriceAndWeight(ctx, agentID, resolved)
	if err != nil {
		return 0, 0, err
	}
	if unitWeight < 1 {
		return 0, 0, &Problem{
			Status:  422,
			Message: "Berat produk belum dikonfigurasi.",
			Fields:  map[string]string{"items": "Berat produk wajib minimal 1 gram."},
		}
	}

	itemName := resolved.Product.Name
	if resolved.Variation != nil && resolved.Variation.Label != nil {
		itemName = *resolved.Variation.Label
	}

	// Agent stock is reserved only for Agent-sourced lines; a Sub line reserves in the Sub ledger below, after
	// its order_item row exists (the reservation is keyed by order_item_id).
	if stockSource != stockSourceSub {
		if resolved.Variation != nil {
			if err := inventory.ReserveForVariation(ctx, tx, agentID, resolved.Variation.ID, qty, ref, itemName); err != nil {
				return 0, 0, mapInsufficient(err, s)
			}
		} else {
			if err := inventory.ReserveForProduct(ctx, tx, agentID, resolved.Product.ID, qty, ref, resolved.Product.Name); err != nil {
				return 0, 0, mapInsufficient(err, s)
			}
		}
	}

	fees, err := resolveFees(ctx, tx, resolved)
	if err != nil {
		return 0, 0, err
	}
	agentFee := money.Round2(fees.Agent * float64(qty))
	salesFee := money.Round2(fees.Sales * float64(qty))
	courierFee := money.Round2(fees.Courier * float64(qty))
	lineSubtotal := money.Round2(unitPrice * float64(qty))

	requestedDate := in.DeliveryDate
	itemID, err := insertOrderItem(ctx, tx, orderItemInput{
		OrderID: orderID, ProductID: resolved.Product.ID, VariationID: variationID(resolved),
		StockSource: stockSource, SubLocationID: subLocationID,
		ProductName: resolved.Product.Name, VariationLabel: variationLabel(resolved),
		SKU: skuOf(resolved), UnitPrice: unitPrice,
		AgentFee: agentFee, SalesFee: salesFee, CourierFee: courierFee, Subtotal: lineSubtotal,
		Quantity: qty, RequestedDate: requestedDate, Status: initialItemStatus,
	})
	if err != nil {
		return 0, 0, err
	}

	if stockSource == stockSourceSub && subLocationID != nil {
		if err := inventory.ReserveSubStock(ctx, tx, inventory.SubReservationInput{
			AgentID: agentID, SubLocationID: *subLocationID, OrderItemID: itemID,
			ProductID: productTargetID(resolved), VariationID: variationID(resolved),
			Quantity: qty, ReservedBy: &in.ActorID, ItemName: itemName,
		}); err != nil {
			return 0, 0, mapInsufficient(err, s)
		}
	}

	// Sales commission recipient (OrderService's documented rule): a self-purchase by a chain role credits
	// THEMSELVES; otherwise the konsumen's own sales rep, else their korsal, else the branch agent.
	beneficiary := in.KonsumenID
	chainRole, err := s.konsumenIsChainRole(ctx, in.KonsumenID)
	if err != nil {
		return 0, 0, err
	}
	if !chainRole {
		beneficiary, err = s.salesBeneficiary(ctx, in.KonsumenID, agentID)
		if err != nil {
			return 0, 0, err
		}
	}
	if err := recordCommission(ctx, tx, orderID, itemID, agentID, beneficiary, agentFee, salesFee); err != nil {
		return 0, 0, err
	}

	return lineSubtotal, unitWeight * qty, nil
}

// mapInsufficient turns the inventory error into the API's 422 with the reference's sentence.
// mapInsufficient turns the inventory error into InsufficientStockException's exact response: the localised
// sentence as the message, and a MIXED-typed errors map carrying the label and the two numbers (the frontend
// renders "only N left" from those, so an `{"items": "..."}` shape would lose the numbers).
func mapInsufficient(err error, s *Service) error {
	var insufficient *inventory.InsufficientStockError
	if errors.As(err, &insufficient) {
		return &Problem{
			Status:  422,
			Message: s.Messages("order.insufficient_stock", "item", insufficient.Item),
			Errors: map[string]any{
				"item":      insufficient.Item,
				"available": insufficient.Available,
				"requested": insufficient.Requested,
			},
		}
	}
	return err
}

// variationLabel is nil-safe: a simple product has no variation at all.
func variationLabel(r checkout.ResolvedLine) *string {
	if r.Variation == nil {
		return nil
	}
	return r.Variation.Label
}

func variationID(r checkout.ResolvedLine) *int64 {
	if r.Variation == nil {
		return nil
	}
	id := r.Variation.ID
	return &id
}

func skuOf(r checkout.ResolvedLine) *string {
	if r.Variation != nil && r.Variation.SKU != nil {
		return r.Variation.SKU
	}
	return r.Product.SKU
}

func productTargetID(r checkout.ResolvedLine) *int64 {
	if r.Variation != nil {
		return nil
	}
	id := r.Product.ID
	return &id
}

func derefString(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

func derefInt(v *int64) string {
	if v == nil {
		return ""
	}
	return fmt.Sprintf("%d", *v)
}

func nullableInt(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}
