package api

import (
	"context"
	"errors"
	"math"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

// quotePayload mirrors QuoteCheckoutRequest. Pointer fields distinguish "absent" from "zero", which the
// required_without:address_id rules depend on.
type quotePayload struct {
	KonsumenID *int64 `json:"konsumen_id"`
	Items      []struct {
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

	ShippingMethod *string `json:"shipping_method"`
	Courier        *string `json:"courier"`
	Service        *string `json:"service"`
	VoucherCode    *string `json:"voucher_code"`
}

// handleCheckoutQuote is POST /api/v1/checkout/quote.
//
// Preview only: it never reserves stock, never writes an order and never consumes a voucher. The response is the
// SAME arithmetic order creation will later persist (internal/pricing + internal/shipping), so the Review step
// cannot promise a total the order disagrees with.
func (s *Server) handleCheckoutQuote(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}

	var payload quotePayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}

	if fields := validateQuote(ctx, s.database, payload); len(fields) > 0 {
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
		// OrderPolicy::create denial surfaces as Laravel's AuthorizationException, whose message is the raw
		// English sentence — NOT the localised `system.unauthorized_action` used by the role middleware.
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "This action is unauthorized."})
		return
	}

	lines := make([]checkout.QuoteLine, 0, len(payload.Items))
	for _, item := range payload.Items {
		lines = append(lines, checkout.QuoteLine{
			ProductID:          *item.ProductID,
			ProductVariationID: item.ProductVariationID,
			Quantity:           int(*item.Quantity),
		})
	}

	quoter := &checkout.Quoter{
		Database: s.database,
		Messages: func(key string, params ...string) string { return message(ctx, key, params...) },
		Shipping: &shipping.Service{
			DB:     s.database,
			AppKey: s.cfg.AppKey,
			Config: s.cfg.Shipping,
		},
	}

	response, qerr := quoter.Quote(ctx, konsumen.ID, checkout.QuoteRequest{
		Lines: lines,
		Destination: checkout.Destination{
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
		ShippingMethod: payload.ShippingMethod,
		Courier:        payload.Courier,
		Service:        payload.Service,
		VoucherCode:    payload.VoucherCode,
	})
	if qerr != nil {
		var problem *checkout.Problem
		if errors.As(qerr, &problem) {
			// The reference throws ApiException(message, 422, [field => sentence]) for these, which serialises
			// the field value as a STRING. A validation failure (array shape) is a different response.
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, FieldStrings: problem.Fields})
			return
		}
		writeError(w, r, qerr)
		return
	}

	httpx.OK(w, response, "OK", nil)
}

// resolveKonsumen is Controller::resolveKonsumen: a konsumen is always themselves; a staff member may name a
// konsumen INSIDE their own branch (scoped lookup, so a foreign id is a 404, never a silent cross-branch read).
func resolveKonsumen(ctx context.Context, s *Server, actor *auth.User, konsumenID *int64) (*auth.User, error) {
	if actor.IsRole(auth.RoleKonsumen) {
		return actor, nil
	}
	if konsumenID == nil {
		return actor, nil
	}
	var agentID *int64
	if actor.AgentID != nil {
		agentID = actor.AgentID
	}
	user, err := auth.FindUserByID(ctx, s.database, *konsumenID)
	if err != nil {
		if errors.Is(err, auth.ErrUserNotFound) {
			return nil, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")}
		}
		return nil, err
	}
	if agentID == nil || user.AgentID == nil || *user.AgentID != *agentID {
		return nil, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")}
	}
	return user, nil
}

// canCreateOrder is OrderPolicy::create.
func canCreateOrder(actor, target *auth.User) bool {
	if actor.ID == target.ID {
		return actor.IsRole(auth.RoleKonsumen, auth.RoleAgen, auth.RoleKorsal, auth.RoleSales, auth.RoleSalesKurirSub)
	}
	if !target.IsRole(auth.RoleKonsumen) {
		return false
	}
	switch {
	case actor.IsRole(auth.RoleAgen):
		return target.AgentID != nil && actor.AgentID != nil && *target.AgentID == *actor.AgentID
	case actor.IsRole(auth.RoleKorsal):
		return target.KorsalID != nil && *target.KorsalID == actor.ID
	case actor.IsRole(auth.RoleSales, auth.RoleSalesKurirSub):
		return target.SalesID != nil && *target.SalesID == actor.ID
	default:
		return false
	}
}

// validateQuote renders the reference's validation errors. Rules and messages mirror QuoteCheckoutRequest.
func validateQuote(ctx context.Context, d *db.DB, p quotePayload) map[string][]string {
	fields := map[string][]string{}
	add := func(field string, messages []string) {
		if len(messages) == 0 {
			return
		}
		fields[field] = append(fields[field], messages...)
	}

	hasAddress := p.AddressID != nil

	// items: required|array|min:1
	if len(p.Items) == 0 {
		add("items", requiredField(ctx, "items"))
	}

	// Existence rules run BEFORE the service logic (Laravel's FormRequest validates first), which is why an
	// unknown village is a validation error here and not the service's ApiException.
	if p.KonsumenID != nil && !existsIn(ctx, d, "users", "id", strconv.FormatInt(*p.KonsumenID, 10)) {
		add("konsumen_id", unknownChoice(ctx, "konsumen_id"))
	}
	if p.AddressID != nil && !existsIn(ctx, d, "konsumen_addresses", "id", strconv.FormatInt(*p.AddressID, 10)) {
		add("address_id", unknownChoice(ctx, "address_id"))
	}
	if !hasAddress && p.VillageID != nil && strings.TrimSpace(*p.VillageID) != "" &&
		!existsIn(ctx, d, "villages", "id", *p.VillageID) {
		add("village_id", unknownChoice(ctx, "village_id"))
	}

	for i, item := range p.Items {
		prefix := "items." + strconv.Itoa(i) + "."
		if item.ProductID == nil {
			add(prefix+"product_id", requiredField(ctx, prefix+"product_id"))
		} else if !existsIn(ctx, d, "products", "id", strconv.FormatInt(*item.ProductID, 10)) {
			add(prefix+"product_id", unknownChoice(ctx, prefix+"product_id"))
		}
		if item.ProductVariationID != nil &&
			!existsIn(ctx, d, "product_variations", "id", strconv.FormatInt(*item.ProductVariationID, 10)) {
			add(prefix+"product_variation_id", unknownChoice(ctx, prefix+"product_variation_id"))
		}
		if item.Quantity == nil {
			add(prefix+"quantity", requiredField(ctx, prefix+"quantity"))
		} else {
			qty := *item.Quantity
			if qty < 1 {
				add(prefix+"quantity", []string{i18nMinNumeric(ctx, prefix+"quantity", 1)})
			} else if qty > 1000 {
				add(prefix+"quantity", []string{i18nMaxNumeric(ctx, prefix+"quantity", 1000)})
			}
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
		if p.VillageID == nil || strings.TrimSpace(*p.VillageID) == "" {
			add("village_id", requiredWithout(ctx, "village_id", "address_id"))
		}
		if p.Latitude == nil {
			add("latitude", requiredWithout(ctx, "latitude", "address_id"))
		} else if math.Abs(*p.Latitude) > 90 {
			add("latitude", []string{betweenMessage(ctx, "latitude", -90, 90)})
		}
		if p.Longitude == nil {
			add("longitude", requiredWithout(ctx, "longitude", "address_id"))
		} else if math.Abs(*p.Longitude) > 180 {
			add("longitude", []string{betweenMessage(ctx, "longitude", -180, 180)})
		}
	} else {
		if p.RecipientName != nil && len(*p.RecipientName) > 150 {
			add("recipient_name", maxString(ctx, "recipient_name", 150))
		}
		if p.RecipientPhone != nil && len(*p.RecipientPhone) > 20 {
			add("recipient_phone", maxString(ctx, "recipient_phone", 20))
		}
		if p.AddressLine != nil && len(*p.AddressLine) > 1000 {
			add("address_line", maxString(ctx, "address_line", 1000))
		}
	}

	if p.PostalCode != nil && len(*p.PostalCode) > 10 {
		add("postal_code", maxString(ctx, "postal_code", 10))
	}
	if p.ShippingMethod != nil && *p.ShippingMethod != "" {
		switch *p.ShippingMethod {
		case "rajaongkir", "openroute", "pickup":
		default:
			add("shipping_method", invalidChoice(ctx, "shipping_method"))
		}
	}
	// courier/service are mutually required (required_with).
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

// requiredWithout / requiredWith render Laravel's `required_without` / `required_with` sentences, whose
// ":values" placeholder is the OTHER field's display name (underscores become spaces unless the attribute
// catalogue defines the field).
func requiredWithout(ctx context.Context, field, other string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "required_without", field, "values", otherAttributeName(ctx, other))}
}

func requiredWith(ctx context.Context, field, other string) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "required_with", field, "values", otherAttributeName(ctx, other))}
}

func otherAttributeName(ctx context.Context, field string) string {
	name := i18n.AttributeName(LocaleFrom(ctx), field)
	if name == strings.ReplaceAll(field, "_", " ") || name == field {
		return strings.ReplaceAll(field, "_", " ")
	}
	return name
}

// existsIn is Laravel's `exists:<table>,<column>` rule.
func existsIn(ctx context.Context, d *db.DB, table, column, value string) bool {
	var exists bool
	query := "SELECT EXISTS(SELECT 1 FROM " + table + " WHERE " + column + " = ?)"
	if err := d.QueryRowContext(ctx, query, value).Scan(&exists); err != nil {
		return false
	}
	return exists
}

func i18nMinNumeric(ctx context.Context, field string, min float64) string {
	return i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", field, "min", trimFloat(min))
}

func i18nMaxNumeric(ctx context.Context, field string, max float64) string {
	return i18n.ValidationMessage(LocaleFrom(ctx), "max.numeric", field, "max", trimFloat(max))
}

// message renders a catalogue key with :placeholder substitutions (the services take this as a function so they
// stay free of locale plumbing).
func message(ctx context.Context, key string, params ...string) string {
	out := msg(ctx, key)
	for i := 0; i+1 < len(params); i += 2 {
		out = strings.ReplaceAll(out, ":"+params[i], params[i+1])
	}
	return out
}
