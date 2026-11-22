package api

import (
	"context"
	"errors"
	"net/http"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

// storeOrderPayload mirrors StoreOrderRequest. Like the reference it accepts ONLY identifiers and quantities —
// no price, fee, stock or total field is read from the client.
type storeOrderPayload struct {
	KonsumenID *int64 `json:"konsumen_id"`

	StockSource   *string `json:"stock_source"`
	SubLocationID *int64  `json:"sub_location_id"`

	Items []struct {
		ProductID          *int64 `json:"product_id"`
		ProductVariationID *int64 `json:"product_variation_id"`
		Quantity           *int64 `json:"quantity"`
	} `json:"items"`

	AddressID      *int64   `json:"address_id"`
	RecipientName  *string  `json:"recipient_name"`
	RecipientPhone *string  `json:"recipient_phone"`
	AddressLine    *string  `json:"address_line"`
	VillageID      *string  `json:"village_id"`
	Latitude       *float64 `json:"latitude"`
	Longitude      *float64 `json:"longitude"`
	PostalCode     *string  `json:"postal_code"`
	ProvinceID     *string  `json:"province_id"`
	RegencyID      *string  `json:"regency_id"`
	DistrictID     *string  `json:"district_id"`

	PaymentMethodCode *string  `json:"payment_method_code"`
	DPAmount          *float64 `json:"dp_amount"`
	DeliveryDate      *string  `json:"delivery_date"`
	ShippingMethod    *string  `json:"shipping_method"`
	Courier           *string  `json:"courier"`
	Service           *string  `json:"service"`
	VoucherCode       *string  `json:"voucher_code"`
}

// handleOrderStore is POST /api/v1/orders.
//
// The Idempotency-Key header is MANDATORY: without it a retried request could create a second order for the same
// purchase. A replay returns the existing order with 201 (the reference's own behaviour) and burns nothing.
func (s *Server) handleOrderStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}

	idempotencyKey := r.Header.Get("Idempotency-Key")
	if idempotencyKey == "" {
		writeError(w, r, &httpx.Error{
			Status:  http.StatusUnprocessableEntity,
			Message: msg(ctx, "payment.idempotency_key_required"),
			Fields:  map[string][]string{"idempotency_key": requiredField(ctx, "idempotency_key")},
		})
		return
	}

	var payload storeOrderPayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}

	if fields := validateStoreOrder(ctx, s, payload); len(fields) > 0 {
		writeError(w, r, &httpx.Error{
			Status:  http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  fields,
		})
		return
	}

	konsumen, err := resolveKonsumen(ctx, s, actor, payload.KonsumenID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if !canCreateOrder(actor, konsumen) {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "This action is unauthorized."})
		return
	}

	lines := make([]orders.Line, 0, len(payload.Items))
	for _, item := range payload.Items {
		lines = append(lines, orders.Line{
			ProductID:          *item.ProductID,
			ProductVariationID: item.ProductVariationID,
			Quantity:           int(*item.Quantity),
		})
	}

	service := &orders.Service{
		Database:  s.database,
		AppKey:    s.cfg.AppKey,
		Shipping:  s.cfg.Shipping,
		Messages:  func(key string, params ...string) string { return message(ctx, key, params...) },
		IP:        clientIP(r),
		UserAgent: r.UserAgent(),
	}

	order, replayed, err := service.Create(ctx, orders.Input{
		ActorID:    actor.ID,
		KonsumenID: konsumen.ID,
		Lines:      lines,
		Destination: orders.Destination{
			AddressID:      payload.AddressID,
			RecipientName:  payload.RecipientName,
			RecipientPhone: payload.RecipientPhone,
			AddressLine:    payload.AddressLine,
			VillageID:      payload.VillageID,
			Latitude:       payload.Latitude,
			Longitude:      payload.Longitude,
			PostalCode:     payload.PostalCode,
			ProvinceID:     payload.ProvinceID,
			RegencyID:      payload.RegencyID,
			DistrictID:     payload.DistrictID,
		},
		PaymentMethodCode: derefOrEmpty(payload.PaymentMethodCode),
		DeliveryDate:      payload.DeliveryDate,
		IdempotencyKey:    idempotencyKey,
		ShippingMethod:    payload.ShippingMethod,
		DPAmount:          payload.DPAmount,
		Courier:           payload.Courier,
		Service:           payload.Service,
		StockSource:       payload.StockSource,
		SubLocationID:     payload.SubLocationID,
		VoucherCode:       payload.VoucherCode,
	})
	_ = replayed
	if err != nil {
		var problem *checkout.Problem
		if errors.As(err, &problem) {
			// Only attach a payload that actually has content: an EMPTY map stored in `any` is not a nil
			// interface, so it would shadow the FieldStrings shape and answer "errors": null.
			var errorsPayload any
			if len(problem.Errors) > 0 {
				errorsPayload = problem.Errors
			}
			writeError(w, r, &httpx.Error{
				Status: problem.Status, Message: problem.Message,
				FieldStrings: problem.Fields, ErrorsAny: errorsPayload,
			})
			return
		}
		writeError(w, r, err)
		return
	}

	rendered, err := orders.Render(ctx, service, order, viewerOf(actor))
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, rendered, msg(ctx, "order.created"))
}

// viewerOf reduces an authenticated user to the renderer's viewer.
func viewerOf(u *auth.User) orders.Viewer {
	return orders.Viewer{ID: u.ID, Roles: auth.SlugsFor(u.Role), Role: u.Role, AgentID: u.AgentID}
}

func derefOrEmpty(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

// validateStoreOrder mirrors StoreOrderRequest::rules. It validates SHAPE only: whether the chosen konsumen is
// actually in the actor's network is the policy check in the handler, and the Sub entitlement is StockSourceResolver's.
func validateStoreOrder(ctx context.Context, s *Server, p storeOrderPayload) map[string][]string {
	fields := map[string][]string{}
	add := func(field string, messages []string) {
		if len(messages) == 0 {
			return
		}
		fields[field] = append(fields[field], messages...)
	}

	hasAddress := p.AddressID != nil

	if p.StockSource != nil && *p.StockSource != "" {
		switch *p.StockSource {
		case "agent", "sub":
		default:
			add("stock_source", invalidChoice(ctx, "stock_source"))
		}
	}
	if len(p.Items) == 0 {
		add("items", requiredField(ctx, "items"))
	}
	if p.KonsumenID != nil && !existsIn(ctx, s.database, "users", "id", formatID(*p.KonsumenID)) {
		add("konsumen_id", unknownChoice(ctx, "konsumen_id"))
	}
	if p.AddressID != nil && !existsIn(ctx, s.database, "konsumen_addresses", "id", formatID(*p.AddressID)) {
		add("address_id", unknownChoice(ctx, "address_id"))
	}
	if p.ProvinceID != nil && *p.ProvinceID != "" && !existsIn(ctx, s.database, "provinces", "id", *p.ProvinceID) {
		add("province_id", unknownChoice(ctx, "province_id"))
	}
	if p.RegencyID != nil && *p.RegencyID != "" && !existsIn(ctx, s.database, "regencies", "id", *p.RegencyID) {
		add("regency_id", unknownChoice(ctx, "regency_id"))
	}
	if p.DistrictID != nil && *p.DistrictID != "" && !existsIn(ctx, s.database, "districts", "id", *p.DistrictID) {
		add("district_id", unknownChoice(ctx, "district_id"))
	}

	for i, item := range p.Items {
		prefix := "items." + itoa(i) + "."
		if item.ProductID == nil {
			add(prefix+"product_id", requiredField(ctx, prefix+"product_id"))
		} else if !existsIn(ctx, s.database, "products", "id", formatID(*item.ProductID)) {
			add(prefix+"product_id", unknownChoice(ctx, prefix+"product_id"))
		}
		if item.ProductVariationID != nil &&
			!existsIn(ctx, s.database, "product_variations", "id", formatID(*item.ProductVariationID)) {
			add(prefix+"product_variation_id", unknownChoice(ctx, prefix+"product_variation_id"))
		}
		if item.Quantity == nil {
			add(prefix+"quantity", requiredField(ctx, prefix+"quantity"))
		} else if *item.Quantity < 1 {
			add(prefix+"quantity", []string{i18nMinNumeric(ctx, prefix+"quantity", 1)})
		} else if *item.Quantity > 1000 {
			add(prefix+"quantity", []string{i18nMaxNumeric(ctx, prefix+"quantity", 1000)})
		}
	}

	if !hasAddress {
		if p.RecipientName == nil {
			add("recipient_name", requiredWithout(ctx, "recipient_name", "address_id"))
		} else if len(*p.RecipientName) > 150 {
			add("recipient_name", maxString(ctx, "recipient_name", 150))
		}
		if p.RecipientPhone == nil {
			add("recipient_phone", requiredWithout(ctx, "recipient_phone", "address_id"))
		} else if len(*p.RecipientPhone) > 20 {
			add("recipient_phone", maxString(ctx, "recipient_phone", 20))
		}
		if p.AddressLine == nil {
			add("address_line", requiredWithout(ctx, "address_line", "address_id"))
		} else if len(*p.AddressLine) > 1000 {
			add("address_line", maxString(ctx, "address_line", 1000))
		}
		if p.VillageID == nil || *p.VillageID == "" {
			add("village_id", requiredWithout(ctx, "village_id", "address_id"))
		} else if !existsIn(ctx, s.database, "villages", "id", *p.VillageID) {
			add("village_id", unknownChoice(ctx, "village_id"))
		}
		if p.Latitude == nil {
			add("latitude", requiredWithout(ctx, "latitude", "address_id"))
		} else if *p.Latitude < -90 || *p.Latitude > 90 {
			add("latitude", []string{betweenMessage(ctx, "latitude", -90, 90)})
		}
		if p.Longitude == nil {
			add("longitude", requiredWithout(ctx, "longitude", "address_id"))
		} else if *p.Longitude < -180 || *p.Longitude > 180 {
			add("longitude", []string{betweenMessage(ctx, "longitude", -180, 180)})
		}
	}

	if p.PostalCode != nil && len(*p.PostalCode) > 10 {
		add("postal_code", maxString(ctx, "postal_code", 10))
	}
	if p.PaymentMethodCode == nil || *p.PaymentMethodCode == "" {
		add("payment_method_code", requiredField(ctx, "payment_method_code"))
	} else if !existsIn(ctx, s.database, "payment_methods", "code", *p.PaymentMethodCode) {
		add("payment_method_code", unknownChoice(ctx, "payment_method_code"))
	}
	if p.DPAmount != nil && *p.DPAmount < 1 {
		add("dp_amount", []string{i18nMinNumeric(ctx, "dp_amount", 1)})
	}
	if p.DeliveryDate != nil && *p.DeliveryDate != "" {
		if err := validateDateNotBeforeToday(*p.DeliveryDate); err != nil {
			add("delivery_date", []string{err.Error()})
		}
	}
	if p.ShippingMethod != nil && *p.ShippingMethod != "" {
		switch *p.ShippingMethod {
		case "rajaongkir", "openroute", "pickup":
		default:
			add("shipping_method", invalidChoice(ctx, "shipping_method"))
		}
	}
	if p.Courier != nil && p.Service == nil {
		add("service", requiredWith(ctx, "service", "courier"))
	}
	if p.Service != nil && p.Courier == nil {
		add("courier", requiredWith(ctx, "courier", "service"))
	}
	if p.VoucherCode != nil && len(*p.VoucherCode) > 60 {
		add("voucher_code", maxString(ctx, "voucher_code", 60))
	}
	return fields
}

var _ = shipping.Method{}

// formatID renders an integer id for an exists-check.
func formatID(v int64) string { return strconv.FormatInt(v, 10) }

// itoa renders an integer for a nested field path.
func itoa(v int) string { return strconv.Itoa(v) }

// validateDateNotBeforeToday is StoreOrderRequest's `date|after_or_equal:today` rule, in the reference's own words.
func validateDateNotBeforeToday(raw string) error {
	parsed, err := time.Parse("2006-01-02", raw)
	if err != nil {
		return errors.New(i18n.ValidationMessage(i18n.Default, "date", "delivery_date"))
	}
	today := time.Now().UTC().Truncate(24 * time.Hour)
	if parsed.Before(today) {
		// Laravel renders after_or_equal with its `validation.after_or_equal` sentence; the attribute catalogue
		// supplies the display name.
		return errors.New(i18n.ValidationMessage(i18n.Default, "after_or_equal", "delivery_date", "date", "hari ini"))
	}
	return nil
}
