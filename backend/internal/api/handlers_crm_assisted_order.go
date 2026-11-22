package api

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// handleCrmAssistedOrder ports CrmAssistedController::placeOrder. The CRM service validates the
// same-branch owner/upline authority; the order service owns pricing, Agent reservation, snapshots,
// payment state, shipping, and CRM-scoped idempotency in the canonical creation transaction.
func (s *Server) handleCrmAssistedOrder(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	key := r.Header.Get("Idempotency-Key")
	if key == "" {
		writeError(w, r, &httpx.Error{Status: 422, Message: msg(ctx, "payment.idempotency_key_required"), Fields: map[string][]string{"idempotency_key": requiredField(ctx, "idempotency_key")}})
		return
	}
	var payload storeOrderPayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}
	validationPayload := payload
	// AssistedOrderRequest requires explicit destination fields even if an address_id was sent;
	// the service then rejects address-book use with its dedicated CRM error.
	validationPayload.AddressID = nil
	validationPayload.KonsumenID = nil  // AssistedOrderRequest removes this account-origin field.
	validationPayload.StockSource = nil // likewise, CRM orders have no client-selectable inventory source.
	validationPayload.SubLocationID = nil
	if fields := validateStoreOrder(ctx, s, validationPayload); len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: 422, Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}
	customerID, err := strconv.ParseInt(r.PathValue("customer"), 10, 64)
	if err != nil {
		writeError(w, r, &httpx.Error{Status: 404, Message: "No query results for model [App\\Models\\CrmCustomer]"})
		return
	}
	lines := make([]orders.Line, 0, len(payload.Items))
	for _, item := range payload.Items {
		lines = append(lines, orders.Line{ProductID: *item.ProductID, ProductVariationID: item.ProductVariationID, Quantity: int(*item.Quantity)})
	}
	service := &orders.Service{Database: s.database, AppKey: s.cfg.AppKey, Shipping: s.cfg.Shipping,
		Messages: func(key string, params ...string) string { return message(ctx, key, params...) }, IP: clientIP(r), UserAgent: r.UserAgent()}
	created, replayed, err := service.CreateAssisted(ctx, orders.Input{
		ActorID: actor.ID, Lines: lines,
		Destination: orders.Destination{RecipientName: payload.RecipientName, RecipientPhone: payload.RecipientPhone,
			AddressID:   payload.AddressID,
			AddressLine: payload.AddressLine, VillageID: payload.VillageID, Latitude: payload.Latitude, Longitude: payload.Longitude,
			PostalCode: payload.PostalCode, ProvinceID: payload.ProvinceID, RegencyID: payload.RegencyID, DistrictID: payload.DistrictID},
		PaymentMethodCode: derefOrEmpty(payload.PaymentMethodCode), DeliveryDate: payload.DeliveryDate, IdempotencyKey: key,
		ShippingMethod: payload.ShippingMethod, DPAmount: payload.DPAmount, Courier: payload.Courier, Service: payload.Service, VoucherCode: payload.VoucherCode,
	}, customerID, actor.Role, actor.AgentID)
	if err != nil {
		var problem *checkout.Problem
		if errors.As(err, &problem) {
			var errorsPayload any
			if len(problem.Errors) > 0 {
				errorsPayload = problem.Errors
			}
			writeError(w, r, &httpx.Error{Status: problem.Status, Message: problem.Message, FieldStrings: problem.Fields, ErrorsAny: errorsPayload})
			return
		}
		writeError(w, r, err)
		return
	}
	rendered, err := orders.Render(ctx, service, created, viewerOf(actor))
	if err != nil {
		writeError(w, r, err)
		return
	}
	_ = replayed // The Laravel endpoint intentionally responds 201 for both create and replay.
	httpx.Created(w, rendered, msg(ctx, "order.created"))
}
