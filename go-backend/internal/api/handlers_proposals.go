package api

// Stock-request proposal endpoints — the §16/§31 fulfillment gate.
//
//	POST /warehouse/stock-requests/{stockRequest}/fulfill          (gudang — reference REFUSES: proposal path only)
//	POST /warehouse/stock-requests/{stockRequest}/proposals        (gudang — propose, or report shortage-only)
//	GET  /warehouse/fulfillment-proposals                          (admin,gudang,koordinator-kurir — index)
//	GET  /warehouse/fulfillment-proposals/{proposal}               (same roles — show)
//	POST /warehouse/fulfillment-proposals/{proposal}/receive       (koordinator-kurir — the routine settlement)
//	POST /warehouse/fulfillment-proposals/{proposal}/approve       (admin — the EXCEPTION path)
//	POST /warehouse/fulfillment-proposals/{proposal}/reject        (admin)
//	POST /warehouse/stock-request-items/{item}/resolve-discrepancy (admin — F07 damaged write-off)

import (
	"context"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"sort"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

// proposePayload is the JSON shape of StoreRequestProposalController::store. The same fields arrive as a
// multipart form when a proof image is attached (the only way goods are actually handed over).
type proposePayload struct {
	Items []struct {
		ItemID         *int64  `json:"item_id"`
		Quantity       *int64  `json:"quantity"`
		ShortageReason *string `json:"shortage_reason"`
	} `json:"items"`
	Shortages []struct {
		ItemID *int64  `json:"item_id"`
		Reason *string `json:"reason"`
	} `json:"shortages"`
}

// readProposeRequest accepts BOTH content types the controller's validation does:
//
//   - application/json (a shortage-only report needs no image, so the SPA sends plain JSON there);
//   - multipart/form-data with `items[i][item_id|quantity|shortage_reason]`, `shortages[i][item_id|reason]`
//     and the `proof` file.
//
// The file is read with a hard size cap BEFORE any parsing (httpx's MaxBodyBytes still applies at the transport
// layer); the collection profile's own 5 MB cap is enforced by media.Validate.
func (s *Server) readProposeRequest(w http.ResponseWriter, r *http.Request) (payload proposePayload, proof *media.Upload, ok bool) {
	contentType := r.Header.Get("Content-Type")
	if strings.HasPrefix(contentType, "multipart/form-data") {
		reader, err := r.MultipartReader()
		if err != nil {
			writeError(w, r, &httpx.Error{Status: http.StatusBadRequest, Message: msg(r.Context(), "system.generic_error")})
			return payload, nil, false
		}
		itemFields := map[int]map[string]string{}
		shortageFields := map[int]map[string]string{}
		for {
			part, err := reader.NextPart()
			if err == io.EOF {
				break
			}
			if err != nil {
				writeError(w, r, &httpx.Error{Status: http.StatusBadRequest, Message: msg(r.Context(), "system.generic_error")})
				return payload, nil, false
			}
			name := part.FormName()
			switch {
			case name == "proof":
				content, rerr := io.ReadAll(io.LimitReader(part, 12<<20))
				if rerr != nil {
					writeError(w, r, &httpx.Error{Status: http.StatusBadRequest, Message: msg(r.Context(), "system.generic_error")})
					return payload, nil, false
				}
				proof = &media.Upload{Filename: part.FileName(), Content: content}
			case strings.HasPrefix(name, "items["):
				var index int
				var field string
				if _, err := fmt.Sscanf(name, "items[%d][%s", &index, &field); err == nil || parseTwoSegment(name, "items[%d][%s]", &index, &field) {
					field = strings.TrimSuffix(strings.TrimRight(field, "]"), "]")
					if itemFields[index] == nil {
						itemFields[index] = map[string]string{}
					}
					itemFields[index][field] = readPartString(part)
				}
			case strings.HasPrefix(name, "shortages["):
				var index int
				var field string
				if parseTwoSegment(name, "shortages[%d][%s]", &index, &field) {
					field = strings.TrimSuffix(strings.TrimRight(field, "]"), "]")
					if shortageFields[index] == nil {
						shortageFields[index] = map[string]string{}
					}
					shortageFields[index][field] = readPartString(part)
				}
			}
			_ = part.Close()
		}
		payload.Items = fieldsToItems(itemFields)
		payload.Shortages = fieldsToShortages(shortageFields)
		return payload, proof, true
	}

	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return payload, nil, false
	}
	return payload, nil, true
}

// parseTwoSegment parses `prefix[index][field]` form names.
func parseTwoSegment(name, pattern string, index *int, field *string) bool {
	// pattern like "items[%d][%s" — handled manually to keep the field name intact.
	open := strings.Index(name, "[")
	if open < 0 {
		return false
	}
	close1 := strings.Index(name[open:], "]")
	if close1 < 0 {
		return false
	}
	if _, err := fmt.Sscanf(name[open+1:open+close1], "%d", index); err != nil {
		return false
	}
	rest := name[open+close1+1:]
	if !strings.HasPrefix(rest, "[") || !strings.HasSuffix(rest, "]") {
		return false
	}
	*field = strings.TrimSuffix(strings.TrimPrefix(rest, "["), "]")
	return true
}

func readPartString(part *multipart.Part) string {
	content, err := io.ReadAll(io.LimitReader(part, 1<<16))
	if err != nil {
		return ""
	}
	return string(content)
}

func fieldsToItems(fields map[int]map[string]string) []struct {
	ItemID         *int64  `json:"item_id"`
	Quantity       *int64  `json:"quantity"`
	ShortageReason *string `json:"shortage_reason"`
} {
	indexes := make([]int, 0, len(fields))
	for index := range fields {
		indexes = append(indexes, index)
	}
	sort.Ints(indexes)
	out := make([]struct {
		ItemID         *int64  `json:"item_id"`
		Quantity       *int64  `json:"quantity"`
		ShortageReason *string `json:"shortage_reason"`
	}, 0, len(indexes))
	for _, index := range indexes {
		row := fields[index]
		var item struct {
			ItemID         *int64  `json:"item_id"`
			Quantity       *int64  `json:"quantity"`
			ShortageReason *string `json:"shortage_reason"`
		}
		if v, err := strconv.ParseInt(row["item_id"], 10, 64); err == nil && row["item_id"] != "" {
			item.ItemID = &v
		}
		if v, err := strconv.ParseInt(row["quantity"], 10, 64); err == nil && row["quantity"] != "" {
			item.Quantity = &v
		}
		if reason, present := row["shortage_reason"]; present {
			item.ShortageReason = &reason
		}
		out = append(out, item)
	}
	return out
}

func fieldsToShortages(fields map[int]map[string]string) []struct {
	ItemID *int64  `json:"item_id"`
	Reason *string `json:"reason"`
} {
	indexes := make([]int, 0, len(fields))
	for index := range fields {
		indexes = append(indexes, index)
	}
	sort.Ints(indexes)
	out := make([]struct {
		ItemID *int64  `json:"item_id"`
		Reason *string `json:"reason"`
	}, 0, len(indexes))
	for _, index := range indexes {
		row := fields[index]
		var shortage struct {
			ItemID *int64  `json:"item_id"`
			Reason *string `json:"reason"`
		}
		if v, err := strconv.ParseInt(row["item_id"], 10, 64); err == nil && row["item_id"] != "" {
			shortage.ItemID = &v
		}
		if reason, present := row["reason"]; present {
			shortage.Reason = &reason
		}
		out = append(out, shortage)
	}
	return out
}

// receivePayload is the Koordinator receipt.
type receivePayload struct {
	Items []struct {
		ItemID   *int64 `json:"item_id"`
		Accepted *int64 `json:"accepted"`
		Short    *int64 `json:"short"`
		Damaged  *int64 `json:"damaged"`
	} `json:"items"`
	Note *string `json:"note"`
}

// handleStockRequestFulfill is POST /warehouse/stock-requests/{stockRequest}/fulfill.
//
// The reference endpoint exists but its service REFUSES: direct Gudang fulfilment was replaced by the proposal
// gate. The refusal is reproduced verbatim (status, message) because the frontend shows it.
func (s *Server) handleStockRequestFulfill(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "stockRequest")
	if !ok {
		return
	}
	// Authorization first (policy: gudang + own branch), then the fixed refusal.
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	var agentID int64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM stock_requests WHERE id = ?`, id).
		Scan(&agentID); err != nil {
		writeError(w, r, stockRequestError(ctx, inventory.ErrStockRequestNotFound))
		return
	}
	if !actor.IsRole("gudang") || actor.AgentID == nil || *actor.AgentID != agentID {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
		Message: "Fulfillment langsung oleh Gudang tidak tersedia. Ajukan proposal untuk persetujuan Admin."})
}

// handleProposalStore is POST /warehouse/stock-requests/{stockRequest}/proposals.
func (s *Server) handleProposalStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "stockRequest")
	if !ok {
		return
	}

	payload, proof, ok := s.readProposeRequest(w, r)
	if !ok {
		return
	}

	// The controller's own conditional rule: proof is REQUIRED when goods are handed over, nullable for a
	// shortage-only report ("nothing to hand over" has no evidence).
	if len(payload.Items) > 0 && proof == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"proof": requiredField(ctx, "proof")}})
		return
	}

	fields := map[string][]string{}
	for i, item := range payload.Items {
		prefix := "items." + strconv.Itoa(i) + "."
		if item.ItemID == nil {
			fields[prefix+"item_id"] = requiredField(ctx, prefix+"item_id")
		}
		if item.Quantity == nil {
			fields[prefix+"quantity"] = requiredField(ctx, prefix+"quantity")
		} else if *item.Quantity < 1 {
			fields[prefix+"quantity"] = []string{i18nMinNumeric(ctx, prefix+"quantity", 1)}
		}
		if item.ShortageReason != nil && len(*item.ShortageReason) > 255 {
			fields[prefix+"shortage_reason"] = maxString(ctx, prefix+"shortage_reason", 255)
		}
	}
	for i, shortage := range payload.Shortages {
		prefix := "shortages." + strconv.Itoa(i) + "."
		if shortage.ItemID == nil {
			fields[prefix+"item_id"] = requiredField(ctx, prefix+"item_id")
		}
		if shortage.Reason == nil {
			fields[prefix+"reason"] = requiredField(ctx, prefix+"reason")
		} else if len([]rune(*shortage.Reason)) < 3 {
			fields[prefix+"reason"] = minStringMessage(ctx, prefix+"reason", 3)
		} else if len(*shortage.Reason) > 255 {
			fields[prefix+"reason"] = maxString(ctx, prefix+"reason", 255)
		}
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	var items []inventory.ProposalItemLine
	for _, item := range payload.Items {
		line := inventory.ProposalItemLine{ItemID: *item.ItemID, Quantity: int(*item.Quantity)}
		if item.ShortageReason != nil {
			line.ShortageReason = *item.ShortageReason
		}
		items = append(items, line)
	}
	var shortages []inventory.ShortageLine
	for _, shortage := range payload.Shortages {
		shortages = append(shortages, inventory.ShortageLine{ItemID: *shortage.ItemID, Reason: *shortage.Reason})
	}

	if len(items) == 0 {
		lines, err := inventory.ReportShortageOnly(ctx, s.database, inventory.ProposeInput{
			ActorID: actor.ID, ActorRole: actor.Role, ActorAgentID: actor.AgentID,
			RequestID: id, Shortages: shortages,
		})
		if err != nil {
			writeError(w, r, stockRequestError(ctx, err))
			return
		}
		rendered := make([]any, 0, len(lines))
		for _, line := range lines {
			rendered = append(rendered, map[string]any{
				"stock_request_item_id": line.StockRequestItemID,
				"reason":                line.Reason,
			})
		}
		httpx.Created(w, map[string]any{"shortages": rendered}, "Created")
		return
	}

	proposal, err := inventory.Propose(ctx, s.database, inventory.ProposeInput{
		ActorID: actor.ID, ActorRole: actor.Role, ActorAgentID: actor.AgentID,
		RequestID: id, Items: items, Shortages: shortages, Proof: proof,
	})
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.Created(w, renderProposal(proposal), "Created")
}

// handleProposalIndex is GET /warehouse/fulfillment-proposals.
func (s *Server) handleProposalIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	agentID, ok := s.requestingAgent(w, r)
	if !ok {
		return
	}

	var requestID *int64
	if raw := httpx.QueryString(r, "stock_request_id"); raw != "" {
		if parsed, err := strconv.ParseInt(raw, 10, 64); err == nil {
			requestID = &parsed
		}
	}
	perPage, _ := strconv.Atoi(httpx.QueryString(r, "per_page"))
	page, _ := strconv.Atoi(httpx.QueryString(r, "page"))

	rows, total, err := inventory.ListProposals(ctx, s.database, actor.ID, agentID, inventory.ListProposalsFilter{
		Scope:          httpx.QueryString(r, "scope"),
		Status:         httpx.QueryString(r, "status"),
		StockRequestID: requestID,
		PerPage:        perPage,
		Page:           page,
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
		data = append(data, renderProposal(row))
	}
	httpx.OK(w, data, "OK", httpx.NewMeta(current, last, total))
}

// handleProposalShow is GET /warehouse/fulfillment-proposals/{proposal}.
func (s *Server) handleProposalShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	if !actor.IsRole("super_admin", "agen", "admin", "gudang", "koordinator-kurir") {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "proposal")
	if !ok {
		return
	}
	agentID, ok := s.requestingAgent(w, r)
	if !ok {
		return
	}
	proposal, err := inventory.LoadProposalDetail(ctx, s.database, agentID, id)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderProposal(proposal), "OK", nil)
}

// handleProposalApprove is POST /warehouse/fulfillment-proposals/{proposal}/approve — the ADMIN exception path.
func (s *Server) handleProposalApprove(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "proposal")
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
	if payload.Reason == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"reason": requiredField(ctx, "reason")}})
		return
	}
	if len([]rune(*payload.Reason)) < 5 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"reason": minStringMessage(ctx, "reason", 5)}})
		return
	}
	if len(*payload.Reason) > 255 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"),
			Fields:  map[string][]string{"reason": maxString(ctx, "reason", 255)}})
		return
	}
	proposal, err := inventory.ApproveProposal(ctx, s.database, actor.ID, *actor.AgentID, id, *payload.Reason)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderProposal(proposal), "OK", nil)
}

// handleProposalReceive is POST /warehouse/fulfillment-proposals/{proposal}/receive — the routine settlement.
func (s *Server) handleProposalReceive(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("koordinator-kurir") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "proposal")
	if !ok {
		return
	}
	var payload receivePayload
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if len(payload.Items) == 0 {
		fields["items"] = requiredField(ctx, "items")
	}
	for i, line := range payload.Items {
		prefix := "items." + strconv.Itoa(i) + "."
		if line.ItemID == nil {
			fields[prefix+"item_id"] = requiredField(ctx, prefix+"item_id")
		}
		if line.Accepted == nil {
			fields[prefix+"accepted"] = requiredField(ctx, prefix+"accepted")
		} else if *line.Accepted < 0 {
			fields[prefix+"accepted"] = []string{i18nMinNumeric(ctx, prefix+"accepted", 0)}
		}
		if line.Short == nil {
			fields[prefix+"short"] = requiredField(ctx, prefix+"short")
		} else if *line.Short < 0 {
			fields[prefix+"short"] = []string{i18nMinNumeric(ctx, prefix+"short", 0)}
		}
		if line.Damaged == nil {
			fields[prefix+"damaged"] = requiredField(ctx, prefix+"damaged")
		} else if *line.Damaged < 0 {
			fields[prefix+"damaged"] = []string{i18nMinNumeric(ctx, prefix+"damaged", 0)}
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

	lines := make([]inventory.ReceiptLine, 0, len(payload.Items))
	for _, line := range payload.Items {
		lines = append(lines, inventory.ReceiptLine{
			ItemID: *line.ItemID, Accepted: int(*line.Accepted),
			Short: int(*line.Short), Damaged: int(*line.Damaged),
		})
	}
	proposal, err := inventory.ReceiveProposal(ctx, s.database, actor.ID, *actor.AgentID, id, lines, payload.Note)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderProposal(proposal), "OK", nil)
}

// handleProposalReject is POST /warehouse/fulfillment-proposals/{proposal}/reject.
func (s *Server) handleProposalReject(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "proposal")
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
	if payload.Reason == nil {
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
	proposal, err := inventory.RejectProposal(ctx, s.database, actor.ID, *actor.AgentID, id, *payload.Reason)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderProposal(proposal), "OK", nil)
}

// handleResolveDiscrepancy is POST /warehouse/stock-request-items/{item}/resolve-discrepancy.
func (s *Server) handleResolveDiscrepancy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if !actor.IsRole("admin") || actor.AgentID == nil {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "item")
	if !ok {
		return
	}
	var payload struct {
		IdempotencyKey *string `json:"idempotency_key"`
		Action         *string `json:"action"`
		Quantity       *int64  `json:"quantity"`
		Reason         *string `json:"reason"`
	}
	if err := httpx.DecodeJSON(r, &payload); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if payload.IdempotencyKey == nil {
		fields["idempotency_key"] = requiredField(ctx, "idempotency_key")
	}
	if payload.Action == nil {
		fields["action"] = requiredField(ctx, "action")
	} else if *payload.Action != "restock" && *payload.Action != "write_off" {
		fields["action"] = invalidChoice(ctx, "action")
	}
	if payload.Quantity == nil {
		fields["quantity"] = requiredField(ctx, "quantity")
	} else if *payload.Quantity < 1 {
		fields["quantity"] = []string{i18nMinNumeric(ctx, "quantity", 1)}
	}
	if payload.Reason == nil {
		fields["reason"] = requiredField(ctx, "reason")
	} else if len([]rune(*payload.Reason)) < 5 {
		fields["reason"] = minStringMessage(ctx, "reason", 5)
	} else if len(*payload.Reason) > 255 {
		fields["reason"] = maxString(ctx, "reason", 255)
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	itemID, damaged, remaining, err := inventory.ResolveDiscrepancy(ctx, s.database, inventory.ResolveDiscrepancyInput{
		ActorID: actor.ID, ActorAgentID: actor.AgentID, ItemID: id,
		Action: *payload.Action, Quantity: int(*payload.Quantity),
		Reason: *payload.Reason, IdempotencyKey: *payload.IdempotencyKey,
	})
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, map[string]any{
		"id":               itemID,
		"damaged_hold_qty": damaged,
		"remaining_qty":    remaining,
	}, "OK", nil)
}

// ---- rendering -------------------------------------------------------------------------------------

// renderProposal is StockRequestProposalResource::toArray. `whenLoaded` semantics: an absent relation is OMITTED.
func renderProposal(p *inventory.Proposal) map[string]any {
	out := map[string]any{
		"id":               p.ID,
		"agent_id":         p.AgentID,
		"stock_request_id": p.StockRequestID,
		"status":           p.Status,
		"requested_by":     p.RequestedBy,
		"approved_by":      nullableInt(p.ApprovedBy),
		"approved_at":      renderTimestamp(p.ApprovedAt),
		"received_by":      nullableInt(p.ReceivedBy),
		"received_at":      renderTimestamp(p.ReceivedAt),
		"receipt_note":     nullableString(p.ReceiptNote),
		"rejected_by":      nullableInt(p.RejectedBy),
		"rejected_at":      renderTimestamp(p.RejectedAt),
		"rejection_reason": nullableString(p.RejectionReason),
		"shortages":        renderShortages(p.Shortages),
		"items":            renderProposalItems(p.Items),
		"created_at":       p.CreatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"updated_at":       p.UpdatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
	}
	if p.RequesterName != nil {
		out["requester"] = map[string]any{"id": p.RequestedBy, "name": *p.RequesterName}
	}
	if p.HandoverURL != nil {
		out["handover_proof_url"] = *p.HandoverURL
	}
	if p.Request != nil {
		out["request"] = renderProposalRequest(p.Request)
	}
	return out
}

func renderShortages(s []inventory.RecordedShortage) []any {
	out := make([]any, 0, len(s))
	for _, row := range s {
		out = append(out, map[string]any{
			"stock_request_item_id": row.StockRequestItemID,
			"reason":                row.Reason,
			"handed_over_qty":       row.HandedOverQty,
			"short_qty":             row.ShortQty,
		})
	}
	return out
}

func renderProposalItems(items []inventory.ProposalItem) []any {
	out := make([]any, 0, len(items))
	for _, item := range items {
		out = append(out, map[string]any{
			"id":                        item.ID,
			"stock_request_proposal_id": item.ProposalID,
			"stock_request_item_id":     item.StockRequestItem,
			"quantity":                  item.Quantity,
			"accepted_qty":              nullableIntPtr(item.AcceptedQty),
			"short_qty":                 nullableIntPtr(item.ShortQty),
			"damaged_qty":               nullableIntPtr(item.DamagedQty),
			"request_item":              renderRequestItem(item.Demand),
		})
	}
	return out
}

// renderProposalRequest mirrors StockRequestResource's keys for the embedded parent. The reference's
// whenLoaded('order') renders order unconditionally once loaded (an unloaded order is null, not absent).
func renderProposalRequest(req *inventory.ProposalRequest) map[string]any {
	return map[string]any{
		"id":             req.ID,
		"order_id":       req.OrderID,
		"request_number": req.RequestNumber,
		"status":         req.Status,
		"order":          req.OrderNo,
	}
}

// minStringMessage renders Laravel's `min` sentence for a string field (a rune count, not bytes).
func minStringMessage(ctx context.Context, field string, min int) []string {
	return []string{i18n.ValidationMessage(LocaleFrom(ctx), "min.string", field, "min", strconv.Itoa(min))}
}

// renderRequestItem is StockRequestItemResource::toArray. `current_stock` is an attribute only the
// warehouse-list controller fills; everywhere else it renders null.
func renderRequestItem(d inventory.RequestItem) map[string]any {
	out := map[string]any{
		"id":                   d.ID,
		"stock_request_id":     d.StockRequestID,
		"order_item_id":        nullableInt(d.OrderItemID),
		"product_id":           nullableInt(d.ProductID),
		"product_variation_id": nullableInt(d.ProductVariationID),
		"product_name":         nullableString(d.ProductName),
		"variation_label":      nullableString(d.VariationLabel),
		"sku":                  nullableString(d.SKU),
		"sku_snapshot":         nullableString(d.SKUSnapshot),
		"product_image_url":    nullableString(d.ProductImageURL),
		"requested_qty":        d.RequestedQty,
		"fulfilled_qty":        d.FulfilledQty,
		"remaining_qty":        d.RemainingQty,
		"current_stock":        nil,
	}
	// The relation is LOADED on every read path here (loaded-but-null still renders the key), so product and
	// variation are always present — null for the target the line does not have.
	out["product"] = nullableMap(d.Product)
	out["variation"] = nullableMap(d.Variation)
	return out
}

// nullableMap renders a loaded relation that may be null.
func nullableMap(v map[string]any) any {
	if v == nil {
		return nil
	}
	return v
}
