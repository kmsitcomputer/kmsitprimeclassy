package api

// Per-item fulfillment endpoints (OrderFulfillmentController):
//
//	PATCH /orders/{order}/items/{item}/fulfillment         adjust the fulfilled quantity of one line
//	GET   /orders/{order}/items/{item}/cancel-eligibility  read-only cancellation projection
//	POST  /orders/{order}/items/{item}/cancel-undelivered  cancel part of the undelivered remainder
//	PATCH /orders/{order}/items/{item}/reschedule          re-date (optionally split) a line
//
// The ORDER of effects mirrors the reference exactly, because every HTTP-visible difference here is a real
// behaviour, not a detail: route-model binding 404 first, then FormRequest validation 422, then the policy 403,
// then the controller's item-belongs-to-order 404. Validation therefore runs in the handler and the policy in
// the service.

import (
	"context"
	"encoding/json"
	"errors"
	"math"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// handleOrderItemAdjust is PATCH /orders/{order}/items/{item}/fulfillment.
func (s *Server) handleOrderItemAdjust(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}

	payload, err := decodeObject(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	quantity, hasQuantity := intValue(payload["fulfilled_quantity"])
	if _, present := payload["fulfilled_quantity"]; !present {
		fields["fulfilled_quantity"] = requiredField(ctx, "fulfilled_quantity")
	} else if !hasQuantity {
		fields["fulfilled_quantity"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", "fulfilled_quantity")}
	} else if quantity < 0 {
		fields["fulfilled_quantity"] = []string{
			i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", "fulfilled_quantity", "min", "0")}
	}
	reason, reasonOK := stringValue(payload["reason"])
	switch {
	case !requiredString(payload["reason"]):
		fields["reason"] = requiredField(ctx, "reason")
	case !reasonOK:
		fields["reason"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "string", "reason", "max", "255")}
	case len([]rune(reason)) > 255:
		fields["reason"] = maxString(ctx, "reason", 255)
	}
	method := "transfer"
	if raw, present := payload["additional_payment_method"]; present && raw != nil {
		value, isString := stringValue(raw)
		if !isString {
			fields["additional_payment_method"] = []string{
				i18n.ValidationMessage(LocaleFrom(ctx), "string", "additional_payment_method")}
		} else if value != "transfer" && value != "cod" {
			fields["additional_payment_method"] = invalidChoice(ctx, "additional_payment_method")
		} else {
			method = value
		}
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	service := s.orderService()
	item, err := service.AdjustItemQuantity(ctx, orders.AdjustInput{
		OrderID: orderID, ItemID: itemID, FulfilledQuantity: quantity, Reason: reason,
		AdditionalPaymentMethod: method, ActorID: actor.ID, ActorRole: actor.Role,
	})
	if err != nil {
		writeError(w, r, fulfillmentError(ctx, err))
		return
	}
	httpx.OK(w, orders.RenderItem(*item, viewerOf(actor)), msg(ctx, "fulfillment.adjusted"), nil)
}

// handleOrderItemCancelEligibility is GET /orders/{order}/items/{item}/cancel-eligibility.
func (s *Server) handleOrderItemCancelEligibility(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}

	eligible, reasonCode, maxQuantity, activeQuantity, err := s.orderService().CancellationBlocker(ctx,
		orders.CancelUndeliveredInput{OrderID: orderID, ItemID: itemID, ActorID: actor.ID, ActorRole: actor.Role})
	if err != nil {
		writeError(w, r, fulfillmentError(ctx, err))
		return
	}
	var code any
	if reasonCode != nil {
		code = *reasonCode
	}
	httpx.OK(w, map[string]any{
		"eligible":        eligible,
		"reason_code":     code,
		"max_quantity":    maxQuantity,
		"active_quantity": activeQuantity,
	}, "OK", nil)
}

// handleOrderItemCancelUndelivered is POST /orders/{order}/items/{item}/cancel-undelivered.
func (s *Server) handleOrderItemCancelUndelivered(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}

	payload, err := decodeObject(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	quantity, quantityPresent := 0, false
	if _, present := payload["quantity"]; !present {
		fields["quantity"] = requiredField(ctx, "quantity")
	} else if quantity, quantityPresent = intValue(payload["quantity"]); !quantityPresent {
		fields["quantity"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", "quantity")}
	} else if quantity < 1 {
		fields["quantity"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", "quantity", "min", "1")}
	}
	expected, expectedPresent := 0, false
	if _, present := payload["expected_quantity"]; !present {
		fields["expected_quantity"] = requiredField(ctx, "expected_quantity")
	} else if expected, expectedPresent = intValue(payload["expected_quantity"]); !expectedPresent {
		fields["expected_quantity"] = []string{
			i18n.ValidationMessage(LocaleFrom(ctx), "integer", "expected_quantity")}
	} else if expected < 1 {
		fields["expected_quantity"] = []string{
			i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", "expected_quantity", "min", "1")}
	}
	reason, reasonOK := stringValue(payload["reason"])
	switch {
	case !requiredString(payload["reason"]):
		fields["reason"] = requiredField(ctx, "reason")
	case !reasonOK:
		fields["reason"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "string", "reason", "max", "255")}
	case len([]rune(reason)) > 255:
		fields["reason"] = maxString(ctx, "reason", 255)
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}
	_ = quantityPresent

	service := s.orderService()
	item, err := service.CancelUndeliveredQuantity(ctx, orders.CancelUndeliveredInput{
		OrderID: orderID, ItemID: itemID, Quantity: quantity, ExpectedQuantity: expected,
		Reason: reason, ActorID: actor.ID, ActorRole: actor.Role,
	})
	if err != nil {
		writeError(w, r, fulfillmentError(ctx, err))
		return
	}
	httpx.OK(w, orders.RenderItem(*item, viewerOf(actor)), msg(ctx, "fulfillment.adjusted"), nil)
}

// handleOrderItemReschedule is PATCH /orders/{order}/items/{item}/reschedule.
func (s *Server) handleOrderItemReschedule(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	itemID, ok := pathID(w, r, "item")
	if !ok {
		return
	}

	payload, err := decodeObject(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	date, dateOK := stringValue(payload["requested_delivery_date"])
	if _, present := payload["requested_delivery_date"]; !present {
		fields["requested_delivery_date"] = requiredField(ctx, "requested_delivery_date")
	} else if !dateOK {
		fields["requested_delivery_date"] = []string{
			i18n.ValidationMessage(LocaleFrom(ctx), "date", "requested_delivery_date")}
	} else if _, parseErr := time.Parse("2006-01-02", date); parseErr != nil {
		// `date` accepts any strtotime-parseable string; the API contract only ever sends Y-m-d, so a
		// non-ISO value is both "not a valid date" and "not after or equal to today" — exactly the two
		// sentences the reference emits together.
		fields["requested_delivery_date"] = []string{
			i18n.ValidationMessage(LocaleFrom(ctx), "date", "requested_delivery_date"),
			i18n.ValidationMessage(LocaleFrom(ctx), "after_or_equal", "requested_delivery_date", "date", "today"),
		}
	} else if date < time.Now().Format("2006-01-02") {
		fields["requested_delivery_date"] = []string{
			i18n.ValidationMessage(LocaleFrom(ctx), "after_or_equal", "requested_delivery_date", "date", "today")}
	}
	reason, reasonOK := stringValue(payload["reason"])
	switch {
	case !requiredString(payload["reason"]):
		fields["reason"] = requiredField(ctx, "reason")
	case !reasonOK:
		fields["reason"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "string", "reason", "max", "255")}
	case len([]rune(reason)) > 255:
		fields["reason"] = maxString(ctx, "reason", 255)
	}
	var quantity *int
	if raw, present := payload["quantity"]; present && raw != nil {
		value, isInt := intValue(raw)
		if !isInt {
			fields["quantity"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "integer", "quantity")}
		} else if value < 1 {
			fields["quantity"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", "quantity", "min", "1")}
		} else {
			quantity = &value
		}
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	service := s.orderService()
	item, err := service.RescheduleItemDeliveryDate(ctx, orders.RescheduleInput{
		OrderID: orderID, ItemID: itemID, NewDate: date, Reason: reason, Quantity: quantity,
		ActorID: actor.ID, ActorRole: actor.Role,
	})
	if err != nil {
		writeError(w, r, fulfillmentError(ctx, err))
		return
	}
	httpx.OK(w, orders.RenderItem(*item, viewerOf(actor)), msg(ctx, "fulfillment.rescheduled"), nil)
}

// fulfillmentError maps the fulfillment domain errors onto the envelope.
func fulfillmentError(ctx context.Context, err error) error {
	if errors.Is(err, orders.ErrOrderItemNotFound) {
		return &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")}
	}
	return orderError(ctx, err)
}

// decodeObject decodes the JSON body into a field map preserving numeric text (json.Number), so an `integer`
// rule judgement matches PHP's filter_var rather than silently accepting `1.0`.
func decodeObject(r *http.Request) (map[string]any, error) {
	var raw json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		return nil, err
	}
	if len(raw) == 0 {
		return map[string]any{}, nil
	}
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.UseNumber()
	out := map[string]any{}
	if err := dec.Decode(&out); err != nil {
		return nil, httpx.Validation(map[string][]string{"body": {"The body must be valid JSON."}})
	}
	return out, nil
}

// requiredString mirrors Laravel's `required` rule for a string field: null, absent, an empty string and a
// whitespace-only string all fail it.
func requiredString(v any) bool {
	text, ok := stringValue(v)
	if !ok {
		return false
	}
	return strings.TrimSpace(text) != ""
}

// intValue reports whether a decoded JSON value satisfies Laravel's `integer` rule, and its value.
func intValue(v any) (int, bool) {
	switch value := v.(type) {
	case json.Number:
		parsed, err := strconv.ParseInt(string(value), 10, 64)
		if err != nil {
			return 0, false
		}
		return int(parsed), true
	case float64:
		if value != math.Trunc(value) {
			return 0, false
		}
		return int(value), true
	case string:
		parsed, err := strconv.ParseInt(strings.TrimSpace(value), 10, 64)
		if err != nil {
			return 0, false
		}
		return int(parsed), true
	}
	return 0, false
}

// stringValue reports whether a decoded JSON value satisfies Laravel's `string` rule, and its value.
func stringValue(v any) (string, bool) {
	switch value := v.(type) {
	case string:
		return value, true
	case json.Number:
		return string(value), true
	case float64:
		return strconv.FormatFloat(value, 'f', -1, 64), true
	case bool:
		if value {
			return "1", true
		}
		return "0", true
	}
	return "", false
}
