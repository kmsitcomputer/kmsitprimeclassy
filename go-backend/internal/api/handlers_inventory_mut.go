package api

import (
	"database/sql"
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// warehouseAuthoritative mirrors config/warehouse.php: `WAREHOUSE_STOCK_AUTHORITATIVE` defaults to TRUE.
//
// It is read once per request from the environment, exactly like Laravel's config() call. The default matters:
// with warehouse-authoritative mode on, `POST /stock/adjust` is refused with 403 — verified against the running
// reference before implementing. A build that implemented the legacy write path unconditionally would silently
// allow stock edits the reference forbids.
func warehouseAuthoritative() bool {
	v, ok := lookupEnv("WAREHOUSE_STOCK_AUTHORITATIVE")
	if !ok || v == "" {
		return true
	}
	switch v {
	case "0", "false", "FALSE", "no", "off":
		return false
	}
	return true
}

// inventoryActor builds the mutation actor from the authenticated user.
func inventoryActor(u *auth.User) inventory.Actor {
	a := inventory.Actor{ID: u.ID, Role: u.Role}
	if u.AgentID != nil {
		a.AgentID = *u.AgentID
	}
	return a
}

// writeDomainError maps an inventory domain error onto the API envelope, keeping its exact status/message.
func writeDomainError(w http.ResponseWriter, r *http.Request, err error) {
	var de *inventory.DomainError
	if errors.As(err, &de) {
		var fields any
		if len(de.Fields) > 0 {
			payload := make(map[string][]string, len(de.Fields))
			for k, v := range de.Fields {
				payload[k] = []string{v}
			}
			fields = payload
		}
		httpx.Failure(w, de.Status, de.Message, fields)
		return
	}
	if errors.Is(err, inventory.ErrNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	writeError(w, r, err)
}

// ---- stock adjustment ------------------------------------------------------------------------------

type adjustRequest struct {
	ProductID   *int64 `json:"product_id"`
	VariationID *int64 `json:"product_variation_id"`
	Delta       *int64 `json:"delta"`
	Reason      string `json:"reason"`
}

// handleStockAdjust is POST /stock/adjust.
//
// CAPTURED BEHAVIOUR (verified against the running reference, not assumed):
//   - role gate `super_admin,agen` never applies: AdjustStockRequest::authorize() narrows it to agen/admin
//     AND requires legacy mode, so a super_admin gets 403 "unauthorized_action" and so does anyone when
//     `WAREHOUSE_STOCK_AUTHORITATIVE` is on (the default).
//   - therefore, in the shipping configuration, this endpoint is a 403 for every caller.
//
// The legacy write path is implemented too, so turning the flag off behaves like the reference; it is NOT
// reachable with the default configuration.
func (s *Server) handleStockAdjust(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	// authorize(): legacy mode AND (agen or admin).
	if warehouseAuthoritative() || !u.IsRole(auth.RoleAgen, auth.RoleAdmin) {
		writeError(w, r, forbiddenError(ctx))
		return
	}

	var body adjustRequest
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.ProductID == nil && body.VariationID == nil {
		fields["product_id"] = requiredField(ctx, "product_id")
	}
	if body.Delta == nil {
		fields["delta"] = requiredField(ctx, "delta")
	} else if *body.Delta == 0 {
		fields["delta"] = invalidChoice(ctx, "delta")
	}
	if body.Reason == "" {
		fields["reason"] = requiredField(ctx, "reason")
	} else if len(body.Reason) > 255 {
		fields["reason"] = invalidChoice(ctx, "reason")
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	actor := inventoryActor(u)
	result, err := inventory.AdjustStock(ctx, s.database, actor, body.ProductID, body.VariationID, *body.Delta, body.Reason)
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, result, msg(ctx, "stock.adjusted"), nil)
}

// ---- transfers -------------------------------------------------------------------------------------

// handleTransferIndex is GET /warehouse/transfers.
func (s *Server) handleTransferIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	rows, page, err := inventory.ListTransfers(ctx, s.database, inventoryActor(u), httpx.QueryString(r, "status"))
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", page)
}

// handleTransferShow is GET /warehouse/transfers/{transfer}.
func (s *Server) handleTransferShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "transfer")
	if !ok {
		return
	}
	row, err := inventory.ShowTransfer(ctx, s.database, inventoryActor(u), id)
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

type transferItemPayload struct {
	ProductID   *int64 `json:"product_id"`
	VariationID *int64 `json:"product_variation_id"`
	Quantity    int64  `json:"quantity"`
}

type transferCreateRequest struct {
	SourceStockType          string                `json:"source_stock_type"`
	SourceSubLocationID      *int64                `json:"source_sub_location_id"`
	DestinationStockType     string                `json:"destination_stock_type"`
	DestinationSubLocationID *int64                `json:"destination_sub_location_id"`
	Reference                *string               `json:"reference"`
	Note                     *string               `json:"note"`
	Items                    []transferItemPayload `json:"items"`
}

// handleTransferStore is POST /warehouse/transfers.
func (s *Server) handleTransferStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	var body transferCreateRequest
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.SourceStockType == "" {
		fields["source_stock_type"] = requiredField(ctx, "source_stock_type")
	}
	if body.DestinationStockType == "" {
		fields["destination_stock_type"] = requiredField(ctx, "destination_stock_type")
	}
	if len(body.Items) == 0 {
		fields["items"] = requiredField(ctx, "items")
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	items := make([]inventory.TransferItemInput, 0, len(body.Items))
	for _, it := range body.Items {
		items = append(items, inventory.TransferItemInput{
			ProductID:   it.ProductID,
			VariationID: it.VariationID,
			Quantity:    it.Quantity,
		})
	}
	row, err := inventory.CreateTransfer(ctx, s.database, inventoryActor(u), inventory.CreateTransferInput{
		SourceStockType:          body.SourceStockType,
		SourceSubLocationID:      body.SourceSubLocationID,
		DestinationStockType:     body.DestinationStockType,
		DestinationSubLocationID: body.DestinationSubLocationID,
		Reference:                body.Reference,
		Note:                     body.Note,
		Items:                    items,
	})
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.Created(w, row, "Created")
}

// handleTransferApprove is POST /warehouse/transfers/{transfer}/approve.
func (s *Server) handleTransferApprove(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "transfer")
	if !ok {
		return
	}
	row, err := inventory.ApproveTransfer(ctx, s.database, inventoryActor(u), id)
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleTransferCancel is POST /warehouse/transfers/{transfer}/cancel.
func (s *Server) handleTransferCancel(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "transfer")
	if !ok {
		return
	}
	row, err := inventory.CancelTransfer(ctx, s.database, inventoryActor(u), id)
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleTransferReject is POST /warehouse/transfers/{transfer}/reject.
func (s *Server) handleTransferReject(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "transfer")
	if !ok {
		return
	}
	var body struct {
		Reason string `json:"reason"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	row, err := inventory.RejectTransfer(ctx, s.database, inventoryActor(u), id, body.Reason)
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleFactoryPlanUpdate is PATCH /warehouse/settings/factory-plan.
func (s *Server) handleFactoryPlanUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	var body struct {
		FactoryPlanEnabled *bool `json:"factory_plan_enabled"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if body.FactoryPlanEnabled == nil {
		writeError(w, r, httpx.Validation(map[string][]string{
			"factory_plan_enabled": requiredField(ctx, "factory_plan_enabled"),
		}))
		return
	}
	row, err := inventory.SetFactoryPlan(ctx, s.database, inventoryActor(u), *body.FactoryPlanEnabled)
	if err != nil {
		writeDomainError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// unusedSQL keeps the database/sql import honest if a helper is temporarily unused.
var _ = sql.ErrNoRows
var _ = strconv.Itoa
