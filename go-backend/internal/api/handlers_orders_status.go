package api

// Order lifecycle endpoints.
//
//	PATCH /orders/{order}/status   (super_admin, agen, admin) — the office bulk forward transition
//	POST  /orders/{order}/cancel   (the order's own konsumen, the branch owner agen, or super_admin)

import (
	"context"
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// handleOrderUpdateStatus is PATCH /orders/{order}/status.
func (s *Server) handleOrderUpdateStatus(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "order")
	if !ok {
		return
	}

	var payload struct {
		Status *string `json:"status"`
	}
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}
	if payload.Status == nil || *payload.Status == "" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"status": requiredField(ctx, "status")}})
		return
	}
	switch *payload.Status {
	case "diproses", "dikirim", "terkirim":
	default:
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"status": invalidChoice(ctx, "status")}})
		return
	}

	// The service re-reads the order under lock and applies OrderPolicy::updateStatus.
	service := s.orderService()
	order, err := service.UpdateStatus(ctx, orders.UpdateStatusInput{
		ActorID: actor.ID, ActorRole: actor.Role, OrderID: id, NewStatus: *payload.Status,
	})
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	rendered, rerr := orders.Render(ctx, service, order, viewerOf(actor))
	if rerr != nil {
		writeError(w, r, rerr)
		return
	}
	httpx.OK(w, rendered, msg(ctx, "order.status_updated"), nil)
}

// handleOrderCancel is POST /orders/{order}/cancel.
func (s *Server) handleOrderCancel(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "order")
	if !ok {
		return
	}

	var payload struct {
		Reason *string `json:"reason"`
	}
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}
	if payload.Reason == nil || *payload.Reason == "" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"reason": requiredField(ctx, "reason")}})
		return
	}
	if len(*payload.Reason) > 255 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"reason": maxString(ctx, "reason", 255)}})
		return
	}

	service := s.orderService()
	order, err := service.Cancel(ctx, orders.CancelInput{
		ActorID: actor.ID, ActorRole: actor.Role, OrderID: id, Reason: *payload.Reason,
	})
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	rendered, rerr := orders.Render(ctx, service, order, viewerOf(actor))
	if rerr != nil {
		writeError(w, r, rerr)
		return
	}
	httpx.OK(w, rendered, msg(ctx, "order.cancelled"), nil)
}

// orderService builds the order service (one per request; stateless apart from config).
func (s *Server) orderService() *orders.Service {
	return &orders.Service{
		Database: s.database,
		AppKey:   s.cfg.AppKey,
		Shipping: s.cfg.Shipping,
		Messages: func(key string, params ...string) string { return message(context.Background(), key, params...) },
	}
}

// orderError maps the order domain problems onto the envelope.
func orderError(ctx context.Context, err error) error {
	if errors.Is(err, orders.ErrOrderNotFound) {
		return &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")}
	}
	var problem *checkout.Problem
	if errors.As(err, &problem) {
		var errorsPayload any
		if len(problem.Errors) > 0 {
			errorsPayload = problem.Errors
		}
		return &httpx.Error{
			Status: problem.Status, Message: problem.Message,
			FieldStrings: problem.Fields, ErrorsAny: errorsPayload,
		}
	}
	return err
}

var _ = auth.RoleKonsumen
