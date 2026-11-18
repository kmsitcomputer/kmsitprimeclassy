package api

// Warehouse stock-request endpoints (5 of the 6 routes in this domain; `sub-adjustment-requests` shares the
// service and is registered with them).
//
//	GET    /warehouse/stock-addition-requests
//	POST   /warehouse/stock-addition-requests
//	GET    /warehouse/stock-addition-requests/{warehouseStockRequest}
//	POST   /warehouse/stock-addition-requests/{warehouseStockRequest}/approve
//	POST   /warehouse/stock-addition-requests/{warehouseStockRequest}/reject
//	POST   /warehouse/sub-adjustment-requests
//
// RBAC: the route group admits admin+gudang; the SERVICE narrows it (creator must be gudang, approver must be
// admin) and every row is agent-scoped, so a foreign id is a 404 rather than a 403.

import (
	"context"
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// stockRequestPayload is the create shape for an `addition` request.
type stockRequestPayload struct {
	ProductID       *int64  `json:"product_id"`
	VariationID     *int64  `json:"variation_id"`
	Quantity        *int64  `json:"quantity"`
	TargetStockType *string `json:"target_stock_type"`
	Reference       *string `json:"reference"`
	Note            *string `json:"note"`
}

// subAdjustmentPayload is the create shape for a `sub_adjustment` request.
type subAdjustmentPayload struct {
	ProductID     *int64  `json:"product_id"`
	VariationID   *int64  `json:"variation_id"`
	SubLocationID *int64  `json:"sub_location_id"`
	Delta         *int64  `json:"delta"`
	Reference     *string `json:"reference"`
	Note          *string `json:"note"`
}

// handleStockAdditionIndex is GET /warehouse/stock-addition-requests.
func (s *Server) handleStockAdditionIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	agentID, ok := s.requestingAgent(w, r)
	if !ok {
		return
	}

	perPage, _ := strconv.Atoi(httpx.QueryString(r, "per_page"))
	page, _ := strconv.Atoi(httpx.QueryString(r, "page"))

	var subLocationID *int64
	if raw := httpx.QueryString(r, "sub_location_id"); raw != "" {
		if parsed, err := strconv.ParseInt(raw, 10, 64); err == nil {
			subLocationID = &parsed
		}
	}

	rows, total, err := inventory.ListStockRequests(ctx, s.database, actor.ID, agentID, inventory.StockRequestFilter{
		Scope:           httpx.QueryString(r, "scope"),
		Status:          httpx.QueryString(r, "status"),
		RequestType:     httpx.QueryString(r, "request_type"),
		SubLocationID:   subLocationID,
		TargetStockType: httpx.QueryString(r, "target_stock_type"),
		Search:          httpx.QueryString(r, "search"),
		PerPage:         perPage,
		Page:            page,
	})
	if err != nil {
		writeError(w, r, err)
		return
	}

	if perPage < 1 {
		perPage = 15
	}
	current := page
	if current < 1 {
		current = 1
	}
	last := (total + perPage - 1) / perPage
	if last < 1 {
		last = 1
	}
	data := make([]any, 0, len(rows))
	for _, row := range rows {
		data = append(data, renderStockRequest(row))
	}
	httpx.OK(w, data, "OK", httpx.NewMeta(current, last, total))
}

// handleStockAdditionStore is POST /warehouse/stock-addition-requests.
func (s *Server) handleStockAdditionStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)

	var payload stockRequestPayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}

	fields := map[string][]string{}
	if payload.ProductID != nil && !existsIn(ctx, s.database, "products", "id", formatID(*payload.ProductID)) {
		fields["product_id"] = unknownChoice(ctx, "product_id")
	}
	if payload.VariationID != nil && !existsIn(ctx, s.database, "product_variations", "id", formatID(*payload.VariationID)) {
		fields["variation_id"] = unknownChoice(ctx, "variation_id")
	}
	if payload.Quantity == nil {
		fields["quantity"] = requiredField(ctx, "quantity")
	} else if *payload.Quantity < 1 {
		fields["quantity"] = []string{i18nMinNumeric(ctx, "quantity", 1)}
	}
	if payload.TargetStockType == nil || *payload.TargetStockType == "" {
		fields["target_stock_type"] = requiredField(ctx, "target_stock_type")
	} else if *payload.TargetStockType != "transit" && *payload.TargetStockType != "factory_plan" {
		fields["target_stock_type"] = invalidChoice(ctx, "target_stock_type")
	}
	if payload.Reference != nil && len(*payload.Reference) > 255 {
		fields["reference"] = maxString(ctx, "reference", 255)
	}
	if payload.Note != nil && len(*payload.Note) > 255 {
		fields["note"] = maxString(ctx, "note", 255)
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	quantity := 0
	if payload.Quantity != nil {
		quantity = int(*payload.Quantity)
	}
	targetType := ""
	if payload.TargetStockType != nil {
		targetType = *payload.TargetStockType
	}

	request, err := inventory.CreateStockRequest(ctx, s.database, inventory.CreateStockRequestInput{
		ActorID:         actor.ID,
		ActorRole:       actor.Role,
		ActorAgentID:    actor.AgentID,
		ProductID:       payload.ProductID,
		VariationID:     payload.VariationID,
		Quantity:        quantity,
		TargetStockType: targetType,
		Reference:       payload.Reference,
		Note:            payload.Note,
	})
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.Created(w, renderStockRequest(request), defaultMessage(ctx, "Created"))
}

// handleSubAdjustmentStore is POST /warehouse/sub-adjustment-requests.
func (s *Server) handleSubAdjustmentStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)

	var payload subAdjustmentPayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}

	fields := map[string][]string{}
	if payload.ProductID != nil && !existsIn(ctx, s.database, "products", "id", formatID(*payload.ProductID)) {
		fields["product_id"] = unknownChoice(ctx, "product_id")
	}
	if payload.VariationID != nil && !existsIn(ctx, s.database, "product_variations", "id", formatID(*payload.VariationID)) {
		fields["variation_id"] = unknownChoice(ctx, "variation_id")
	}
	if payload.SubLocationID == nil {
		fields["sub_location_id"] = requiredField(ctx, "sub_location_id")
	} else if !existsIn(ctx, s.database, "warehouse_sub_locations", "id", formatID(*payload.SubLocationID)) {
		fields["sub_location_id"] = unknownChoice(ctx, "sub_location_id")
	}
	if payload.Delta == nil {
		fields["delta"] = requiredField(ctx, "delta")
	} else if *payload.Delta == 0 {
		fields["delta"] = []string{notInMessage(ctx, "delta", "0")}
	}
	if payload.Reference != nil && len(*payload.Reference) > 255 {
		fields["reference"] = maxString(ctx, "reference", 255)
	}
	if payload.Note != nil && len(*payload.Note) > 255 {
		fields["note"] = maxString(ctx, "note", 255)
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	delta := 0
	if payload.Delta != nil {
		delta = int(*payload.Delta)
	}
	subLocationID := int64(0)
	if payload.SubLocationID != nil {
		subLocationID = *payload.SubLocationID
	}

	request, err := inventory.CreateSubAdjustmentRequest(ctx, s.database, inventory.CreateSubAdjustmentInput{
		ActorID:       actor.ID,
		ActorRole:     actor.Role,
		ActorAgentID:  actor.AgentID,
		ProductID:     payload.ProductID,
		VariationID:   payload.VariationID,
		SubLocationID: subLocationID,
		Delta:         delta,
		Reference:     payload.Reference,
		Note:          payload.Note,
	})
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.Created(w, renderStockRequest(request), defaultMessage(ctx, "Created"))
}

// handleStockAdditionShow is GET /warehouse/stock-addition-requests/{warehouseStockRequest}.
func (s *Server) handleStockAdditionShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.requestingAgent(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "warehouseStockRequest")
	if !ok {
		return
	}
	request, err := inventory.LoadStockRequest(ctx, s.database, agentID, id)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	if err := inventory.AttachCurrentStock(ctx, s.database, request); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, renderStockRequest(request), "OK", nil)
}

// handleStockAdditionApprove is POST .../{warehouseStockRequest}/approve.
func (s *Server) handleStockAdditionApprove(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		// The policy requires admin AND a matching branch; without a branch there is nothing to match.
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "warehouseStockRequest")
	if !ok {
		return
	}
	request, err := inventory.ApproveStockRequest(ctx, s.database, actor.ID, *actor.AgentID, id)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderStockRequest(request), "OK", nil)
}

// handleStockAdditionReject is POST .../{warehouseStockRequest}/reject.
func (s *Server) handleStockAdditionReject(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "warehouseStockRequest")
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

	request, err := inventory.RejectStockRequest(ctx, s.database, actor.ID, *actor.AgentID, id, *payload.Reason)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderStockRequest(request), "OK", nil)
}

// ---- shared helpers --------------------------------------------------------------------------------

// requestingAgent resolves the branch these endpoints operate on. The policy is agent-scoped for both roles, so an
// actor without a branch cannot see or create anything.
func (s *Server) requestingAgent(w http.ResponseWriter, r *http.Request) (int64, bool) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return 0, false
	}
	if actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.agent_not_linked")})
		return 0, false
	}
	return *actor.AgentID, true
}

// stockRequestError maps the inventory domain problem onto the API envelope.
func stockRequestError(ctx context.Context, err error) error {
	if errors.Is(err, inventory.ErrStockRequestNotFound) {
		// The reference answers a missing stock request with the ENDPOINT-not-found sentence, not the model one.
		// That reads oddly, but it is the observable contract and the frontend branches on it.
		return &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")}
	}
	var problem *inventory.RequestProblem
	if errors.As(err, &problem) {
		return &httpx.Error{Status: problem.Status, Message: problem.Message}
	}
	return err
}

// renderStockRequest is WarehouseStockRequestResource::toArray. A key whose relation is absent is OMITTED, exactly
// like `whenLoaded`.
func renderStockRequest(r *inventory.StockRequest) map[string]any {
	out := map[string]any{
		"id":                   r.ID,
		"agent_id":             r.AgentID,
		"request_type":         r.RequestType,
		"product_id":           nullableInt(r.ProductID),
		"product_variation_id": nullableInt(r.ProductVariationID),
		"target_stock_type":    r.TargetStockType,
		"sub_location_id":      nullableInt(r.SubLocationID),
		"quantity":             r.Quantity,
		"reference":            nullableString(r.Reference),
		"note":                 nullableString(r.Note),
		"status":               r.Status,
		"requested_by":         r.RequestedBy,
		"approved_by":          nullableInt(r.ApprovedBy),
		"approved_at":          renderTimestamp(r.ApprovedAt),
		"rejected_by":          nullableInt(r.RejectedBy),
		"rejected_at":          renderTimestamp(r.RejectedAt),
		"rejection_reason":     nullableString(r.RejectionReason),
		"product_name":         nullableString(r.ProductName),
		"variation_label":      nullableString(r.VariationLabel),
		"sku":                  nullableString(r.SKU),
		"product_image_url":    nullableString(r.ProductImageURL),
		"current_stock":        nullableIntPtr(r.CurrentStock),
		"created_at":           r.CreatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"updated_at":           r.UpdatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
	}
	if r.RequesterName != nil {
		out["requester"] = map[string]any{"id": r.RequestedBy, "name": *r.RequesterName}
	}
	// `sub_location` is present only when the reference eager-loaded it for this call (see StockRequest's flag).
	// A loaded-but-EMPTY relation renders JSON null, not a dict of nulls — the resource's callback is skipped for a
	// missing related model.
	if r.LoadSubLocation {
		if r.SubLocationID == nil {
			out["sub_location"] = nil
		} else {
			out["sub_location"] = map[string]any{
				"id":   *r.SubLocationID,
				"code": nullableString(r.SubLocationCode),
				"name": nullableString(r.SubLocationName),
			}
		}
	}
	return out
}
