package api

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

type addOrderItemPayload struct {
	ProductID               *int64          `json:"product_id"`
	VariationID             *int64          `json:"product_variation_id"`
	Quantity                *int            `json:"quantity"`
	RequestedDate           *string         `json:"requested_delivery_date"`
	Reason                  *string         `json:"reason"`
	AdditionalPaymentMethod *string         `json:"additional_payment_method"`
	Invalid                 map[string]bool `json:"-"`
}

func (p *addOrderItemPayload) UnmarshalJSON(data []byte) error {
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(data, &raw); err != nil {
		return err
	}
	p.Invalid = map[string]bool{}
	if p.ProductID, p.Invalid["product_id"] = decodeOptionalInt(raw["product_id"]); p.Invalid["product_id"] {
		p.ProductID = nil
	}
	if parsed, invalid := decodeOptionalInt(raw["product_variation_id"]); invalid {
		p.Invalid["product_variation_id"] = true
	} else {
		p.VariationID = parsed
	}
	if parsed, invalid := decodeOptionalInt(raw["quantity"]); invalid {
		p.Invalid["quantity"] = true
	} else if parsed != nil {
		quantity := int(*parsed)
		p.Quantity = &quantity
	}
	for key, target := range map[string]**string{
		"requested_delivery_date":   &p.RequestedDate,
		"reason":                    &p.Reason,
		"additional_payment_method": &p.AdditionalPaymentMethod,
	} {
		value, invalid := decodeOptionalString(raw[key])
		if invalid {
			p.Invalid[key] = true
		} else {
			*target = value
		}
	}
	return nil
}

func decodeOptionalInt(raw json.RawMessage) (*int64, bool) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, false
	}
	value := string(raw)
	if value[0] == '"' {
		var text string
		if err := json.Unmarshal(raw, &text); err != nil {
			return nil, true
		}
		if text == "" {
			return nil, false
		}
		value = text
	}
	parsed, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		return nil, true
	}
	return &parsed, false
}

func decodeOptionalString(raw json.RawMessage) (*string, bool) {
	if len(raw) == 0 || string(raw) == "null" {
		return nil, false
	}
	var value string
	if err := json.Unmarshal(raw, &value); err != nil {
		return nil, true
	}
	if value == "" {
		return nil, false
	}
	return &value, false
}

// handleOrderLineAddition ports the Admin-only SC-03 endpoint. Validation and model binding precede
// policy/service execution, as they do in Laravel; the domain service then owns all transactional effects.
func (s *Server) handleOrderLineAddition(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	orderID, err := strconv.ParseInt(r.PathValue("order"), 10, 64)
	if err != nil {
		writeError(w, r, notFoundError(ctx))
		return
	}
	var bound int64
	if actor.AgentID == nil {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err = s.database.QueryRowContext(ctx, `SELECT id FROM orders WHERE id=? AND agent_id=?`, orderID, *actor.AgentID).Scan(&bound); errors.Is(err, sql.ErrNoRows) {
		writeError(w, r, notFoundError(ctx))
		return
	} else if err != nil {
		writeError(w, r, err)
		return
	}
	body, err := io.ReadAll(r.Body)
	if err != nil {
		writeError(w, r, err)
		return
	}
	r.Body = io.NopCloser(bytes.NewReader(body))
	var payload addOrderItemPayload
	if err = httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}
	var raw map[string]json.RawMessage
	if err = json.Unmarshal(body, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	if payload.RequestedDate != nil && *payload.RequestedDate == "" {
		payload.RequestedDate = nil
	}
	if fields := validateAddOrderItem(ctx, s, payload, raw); len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: 422, Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}
	key := strings.Trim(r.Header.Get("Idempotency-Key"), " \t\n\r\x00\x0b")
	method := "transfer"
	if payload.AdditionalPaymentMethod != nil {
		method = *payload.AdditionalPaymentMethod
	}
	service := s.orderService()
	order, replayed, err := service.AddLine(ctx, orders.AddLineInput{OrderID: orderID, ProductID: *payload.ProductID, VariationID: payload.VariationID,
		Quantity: *payload.Quantity, RequestedDate: payload.RequestedDate, Reason: *payload.Reason, AdditionalPaymentMethod: method,
		IdempotencyKey: key, ActorID: actor.ID, ActorRole: actor.Role, AgentID: actor.AgentID})
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	rendered, err := orders.Render(ctx, service, order, viewerOf(actor))
	if err != nil {
		writeError(w, r, err)
		return
	}
	if replayed {
		httpx.OK(w, rendered, msg(ctx, "order.line_added"), nil)
	} else {
		httpx.Created(w, rendered, msg(ctx, "order.line_added"))
	}
}

func validateAddOrderItem(ctx context.Context, s *Server, p addOrderItemPayload, raw map[string]json.RawMessage) map[string][]string {
	fields := map[string][]string{}
	add := func(k string, m []string) { fields[k] = append(fields[k], m...) }
	if p.Invalid["product_id"] {
		add("product_id", []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", "product_id")})
	} else if p.ProductID == nil {
		add("product_id", requiredField(ctx, "product_id"))
	} else if !existsIn(ctx, s.database, "products", "id", formatID(*p.ProductID)) {
		add("product_id", unknownChoice(ctx, "product_id"))
	}
	if p.Invalid["product_variation_id"] {
		add("product_variation_id", []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", "product_variation_id")})
	} else if p.VariationID != nil && !existsIn(ctx, s.database, "product_variations", "id", formatID(*p.VariationID)) {
		add("product_variation_id", unknownChoice(ctx, "product_variation_id"))
	}
	if p.Invalid["quantity"] {
		add("quantity", []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", "quantity")})
	} else if p.Quantity == nil {
		add("quantity", requiredField(ctx, "quantity"))
	} else if *p.Quantity < 1 {
		add("quantity", minNumeric(ctx, "quantity", 1))
	} else if *p.Quantity > 1000 {
		add("quantity", maxNumeric(ctx, "quantity", 1000))
	}
	if p.Invalid["requested_delivery_date"] || p.RequestedDate != nil && !validLooseDate(*p.RequestedDate) {
		add("requested_delivery_date", []string{i18n.ValidationMessage(LocaleFrom(ctx), "date", "requested_delivery_date")})
	}
	if p.Invalid["reason"] {
		add("reason", []string{i18n.ValidationMessage(LocaleFrom(ctx), "string", "reason")})
	} else if p.Reason == nil || strings.TrimSpace(*p.Reason) == "" {
		add("reason", requiredField(ctx, "reason"))
	} else if len([]rune(*p.Reason)) > 255 {
		add("reason", maxString(ctx, "reason", 255))
	}
	if p.Invalid["additional_payment_method"] {
		add("additional_payment_method", []string{i18n.ValidationMessage(LocaleFrom(ctx), "string", "additional_payment_method")})
	} else if p.AdditionalPaymentMethod != nil && *p.AdditionalPaymentMethod != "transfer" && *p.AdditionalPaymentMethod != "cod" {
		add("additional_payment_method", invalidChoice(ctx, "additional_payment_method"))
	}
	for _, key := range []string{"stock_source", "sub_location_id"} {
		if value, ok := raw[key]; ok && string(value) != "null" && string(value) != "\"\"" {
			add(key, []string{i18n.ValidationMessage(LocaleFrom(ctx), "prohibited", key)})
		}
	}
	return fields
}

func validLooseDate(raw string) bool {
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02 15:04:05", "2006-01-02"} {
		if _, err := time.Parse(layout, raw); err == nil {
			return true
		}
	}
	return false
}
