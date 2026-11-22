package api

// Sub stock-request mutations — the R-02 Transit ↔ Sub chain.
//
//	POST /sub-stock/requests                                (sales-kurir-sub)
//	GET  /sub-stock/requests/{subStockRequest}              (policy view)
//	POST /sub-stock/requests/{subStockRequest}/approve      (admin)
//	POST /sub-stock/requests/{subStockRequest}/reject       (admin)
//	POST /sub-stock/requests/{subStockRequest}/cancel       (sales-kurir-sub, the requester)
//	POST /sub-stock/requests/{subStockRequest}/execute      (gudang — the ONLY stock-moving step)
//	POST /sub-stock/requests/{subStockRequest}/receive      (sales-kurir-sub, the requester)
//
// The index (GET /sub-stock/requests) was already served; this file adds the mutations and the detail view.

import (
	"context"
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// subRequestPayload is the create shape.
type subRequestPayload struct {
	Direction *string `json:"direction"`
	Note      *string `json:"note"`
	Items     []struct {
		ProductID          *int64 `json:"product_id"`
		ProductVariationID *int64 `json:"product_variation_id"`
		Quantity           *int64 `json:"quantity"`
	} `json:"items"`
}

// handleSubRequestStore is POST /sub-stock/requests.
func (s *Server) handleSubRequestStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}

	var payload subRequestPayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}

	fields := map[string][]string{}
	if payload.Direction == nil {
		fields["direction"] = requiredField(ctx, "direction")
	} else if *payload.Direction != "replenish" && *payload.Direction != "return" {
		fields["direction"] = invalidChoice(ctx, "direction")
	}
	if len(payload.Items) == 0 {
		fields["items"] = requiredField(ctx, "items")
	} else if len(payload.Items) > 100 {
		fields["items"] = maxArray(ctx, "items", 100)
	}
	for i, item := range payload.Items {
		prefix := "items." + strconv.Itoa(i) + "."
		if (item.ProductID == nil) && (item.ProductVariationID == nil) {
			// Both absent: the service rejects; Laravel's rules only require quantity, so the shape error
			// surfaces as the service's own 422 sentence.
		}
		if item.Quantity == nil {
			fields[prefix+"quantity"] = requiredField(ctx, prefix+"quantity")
		} else if *item.Quantity < 1 {
			fields[prefix+"quantity"] = []string{i18nMinNumeric(ctx, prefix+"quantity", 1)}
		} else if *item.Quantity > 100000 {
			fields[prefix+"quantity"] = []string{i18nMaxNumeric(ctx, prefix+"quantity", 100000)}
		}
	}
	if payload.Note != nil && len(*payload.Note) > 255 {
		fields["note"] = maxString(ctx, "note", 255)
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	items := make([]inventory.SubRequestItemLine, 0, len(payload.Items))
	for _, item := range payload.Items {
		items = append(items, inventory.SubRequestItemLine{
			ProductID: item.ProductID, VariationID: item.ProductVariationID,
			Quantity: int(derefInt64(item.Quantity)),
		})
	}

	direction := ""
	if payload.Direction != nil {
		direction = *payload.Direction
	}
	request, err := inventory.CreateSubRequest(ctx, s.database, inventory.SubRequestInput{
		ActorID:        actor.ID,
		ActorRole:      actor.Role,
		ActorAgentID:   actor.AgentID,
		Direction:      direction,
		Items:          items,
		Note:           payload.Note,
		IdempotencyKey: r.Header.Get("Idempotency-Key"),
	})
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return
	}
	httpx.Created(w, renderSubRequest(request), "Created")
}

// handleSubRequestShow is GET /sub-stock/requests/{subStockRequest}.
func (s *Server) handleSubRequestShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "subStockRequest")
	if !ok {
		return
	}
	request, err := s.loadSubRequestForViewer(w, r, actor, id)
	if err != nil || request == nil {
		return
	}
	httpx.OK(w, renderSubRequest(request), "OK", nil)
}

// handleSubRequestApprove is POST .../approve.
func (s *Server) handleSubRequestApprove(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "Hanya Admin yang dapat menyetujui permintaan stok Sub."})
		return
	}
	id, ok := pathID(w, r, "subStockRequest")
	if !ok {
		return
	}
	request, err := inventory.ApproveSubRequest(ctx, s.database, actor.ID, *actor.AgentID, id)
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderSubRequest(request), "OK", nil)
}

// handleSubRequestReject is POST .../reject.
func (s *Server) handleSubRequestReject(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "Hanya Admin yang dapat menolak permintaan stok Sub."})
		return
	}
	id, ok := pathID(w, r, "subStockRequest")
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
	request, err := inventory.RejectSubRequest(ctx, s.database, actor.ID, *actor.AgentID, id, *payload.Reason)
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderSubRequest(request), "OK", nil)
}

// handleSubRequestCancel is POST .../cancel.
func (s *Server) handleSubRequestCancel(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "subStockRequest")
	if !ok {
		return
	}
	// Cancel checks the requester INSIDE the transaction; any admitted role reaches it.
	request, err := inventory.CancelSubRequest(ctx, s.database, actor.ID, derefInt64(actor.AgentID), id)
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderSubRequest(request), "OK", nil)
}

// handleSubRequestExecute is POST .../execute — Gudang's stock move.
func (s *Server) handleSubRequestExecute(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("gudang") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "Hanya Gudang yang dapat mengeksekusi permintaan stok Sub."})
		return
	}
	id, ok := pathID(w, r, "subStockRequest")
	if !ok {
		return
	}
	request, err := inventory.ExecuteSubRequest(ctx, s.database, actor.ID, *actor.AgentID, id)
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderSubRequest(request), "OK", nil)
}

// handleSubRequestReceive is POST .../receive.
func (s *Server) handleSubRequestReceive(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "subStockRequest")
	if !ok {
		return
	}
	request, err := inventory.ReceiveSubRequest(ctx, s.database, actor.ID, derefInt64(actor.AgentID), id)
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderSubRequest(request), "OK", nil)
}

// ---- shared helpers --------------------------------------------------------------------------------

// loadSubRequestForViewer applies SubStockRequestPolicy::view: super_admin sees anything; a Sales-Kurir-Sub only
// their own; agen/admin/gudang their own branch. A denial is a 404, never a 403.
func (s *Server) loadSubRequestForViewer(w http.ResponseWriter, r *http.Request, actor *auth.User, id int64) (*inventory.SubRequest, error) {
	ctx := r.Context()
	request, err := inventory.LoadSubRequest(ctx, s.database, id)
	if err != nil {
		writeError(w, r, subRequestError(ctx, err))
		return nil, err
	}
	allowed := false
	switch {
	case actor.IsRole(auth.RoleSuperAdmin):
		allowed = true
	case actor.IsRole(auth.RoleSalesKurirSub):
		allowed = request.RequestedBy == actor.ID
	default:
		allowed = actor.IsRole(auth.RoleAgen, auth.RoleAdmin, "gudang") &&
			actor.AgentID != nil && *actor.AgentID == request.AgentID
	}
	if !allowed {
		// The reference's model binding 404s; indistinguishable from "not found".
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		return nil, nil
	}
	return request, nil
}

// subRequestError maps the domain problems onto the envelope.
func subRequestError(ctx context.Context, err error) error {
	if errors.Is(err, inventory.ErrStockRequestNotFound) {
		return &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")}
	}
	var problem *inventory.RequestProblem
	if errors.As(err, &problem) {
		return &httpx.Error{Status: problem.Status, Message: problem.Message, FieldStrings: problem.Fields,
			ErrorsAny: map[string]any(problem.Errors)}
	}
	var domain *inventory.DomainError
	if errors.As(err, &domain) {
		return &httpx.Error{Status: domain.Status, Message: domain.Message}
	}
	return err
}

// renderSubRequest is the raw model serialisation (the controller loads the relations then returns the model).
//
// CreatedOnly reproduces Eloquent's fresh-create serialisation: a model built by create() carries ONLY the
// insert-time attributes, so the create response omits every other column. A fresh() re-read (every action
// response, and an idempotent replay) carries them all.
func renderSubRequest(req *inventory.SubRequest) map[string]any {
	out := map[string]any{
		"id":              req.ID,
		"agent_id":        req.AgentID,
		"sub_location_id": req.SubLocationID,
		"request_number":  nullableString(req.RequestNumber),
		"direction":       req.Direction,
		"status":          req.Status,
		"requested_by":    req.RequestedBy,
		"created_at":      req.CreatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"updated_at":      req.UpdatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"items":           renderSubRequestItems(req.Items),
	}
	// idempotency_key and note are insert-time attributes: they ALWAYS render (null when unset), in both
	// serialisations.
	out["idempotency_key"] = nullableString(req.IdempotencyKey)
	out["note"] = nullableString(req.Note)
	if !req.CreatedOnly {
		out["approved_by"] = nullableInt(req.ApprovedBy)
		out["approved_at"] = renderTimestamp(req.ApprovedAt)
		out["rejected_by"] = nullableInt(req.RejectedBy)
		out["rejected_at"] = renderTimestamp(req.RejectedAt)
		out["rejection_reason"] = nullableString(req.RejectionReason)
		out["cancelled_at"] = renderTimestamp(req.CancelledAt)
		out["stock_transfer_id"] = nullableInt(req.StockTransferID)
		out["executed_by"] = nullableInt(req.ExecutedBy)
		out["executed_at"] = renderTimestamp(req.ExecutedAt)
		out["received_by"] = nullableInt(req.ReceivedBy)
		out["received_at"] = renderTimestamp(req.ReceivedAt)
	}
	if req.SubLocation != nil {
		out["sub_location"] = *req.SubLocation
	}
	if req.Requester != nil {
		out["requester"] = *req.Requester
	}
	if req.Transfer != nil {
		transfer := *req.Transfer
		if req.TransferHandover != nil {
			transfer["handover"] = *req.TransferHandover
		}
		out["transfer"] = transfer
	}
	return out
}

func renderSubRequestItems(items []inventory.SubRequestItem) []any {
	out := make([]any, 0, len(items))
	for _, item := range items {
		// The model has no $hidden and the controller loads BOTH relations, so both keys always render —
		// null for the target the line does not have (a product line has no variation, and vice versa).
		var product any
		if item.Product != nil {
			product = *item.Product
		}
		var variation any
		if item.Variation != nil {
			variation = *item.Variation
		}
		out = append(out, map[string]any{
			"id":                   item.ID,
			"sub_stock_request_id": item.RequestID,
			"product_id":           nullableInt(item.ProductID),
			"product_variation_id": nullableInt(item.VariationID),
			"quantity":             item.Quantity,
			"created_at":           item.CreatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
			"updated_at":           item.UpdatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
			"product":              product,
			"variation":            variation,
		})
	}
	return out
}

func derefInt64(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

// maxArray renders Laravel's `max` sentence for an array field.
func maxArray(ctx context.Context, field string, max int) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "max.array", field, "max", strconv.Itoa(max))}
}
