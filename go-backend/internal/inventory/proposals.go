package inventory

// Stock-request proposals — the §16/§31 two-party fulfillment gate.
//
// Ported from StockRequestProposalService. Three actors, one flow:
//
//	GUDANG            proposes what it can hand over (and records what it cannot, with reasons)
//	KOORDINATOR KURIR physically receives (accepted / short / damaged per prepared item) — the ROUTINE settlement
//	ADMIN             may exceptionally approve the whole prepared quantity, reject a proposal, or resolve damaged
//	                  units held on a line (write_off only; restock is blocked pending an Owner inspection rule)
//
// Stock moves ONLY at settlement, from `transit` (no sub location) into `shipping` (no sub location), and only for
// the ACCEPTED units. Short/damaged units stay in Transit, keep their reservation, remain outstanding demand and
// are never sellable — resolving them is an Admin exception, never automatic.
//
// Lock order (each step): Order → StockRequest → StockRequestItem rows (locking read) → canonical Agent target
// locks → transit/shipping/reservation rows. This matches checkout and SC-03, so no cycle is possible.
//
// The demand-line sums after settlement use LOCKING reads, never a plain SUM(): under REPEATABLE READ a plain
// aggregate reads a snapshot older than the locks this transaction just waited for and could mark a request
// fulfilled while SC-03 appended demand meanwhile.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

// ProposalItemLine is one `items[]` entry of a propose request.
type ProposalItemLine struct {
	ItemID         int64
	Quantity       int
	ShortageReason string
}

// ShortageLine is one `shortages[]` entry (nothing can be handed over).
type ShortageLine struct {
	ItemID int64
	Reason string
}

// ReceiptLine is one Koordinator receipt line: accepted+short+damaged must equal the prepared quantity.
type ReceiptLine struct {
	ItemID   int64
	Accepted int
	Short    int
	Damaged  int
}

// Proposal is one stock_request_proposals row plus its rendered relations.
type Proposal struct {
	ID              int64
	AgentID         int64
	StockRequestID  int64
	Status          string
	RequestedBy     int64
	RequesterName   *string
	ApprovedBy      *int64
	ApprovedAt      *time.Time
	ReceivedBy      *int64
	ReceivedAt      *time.Time
	ReceiptNote     *string
	RejectedBy      *int64
	RejectedAt      *time.Time
	RejectionReason *string
	CreatedAt       time.Time
	UpdatedAt       time.Time

	Items       []ProposalItem
	Shortages   []RecordedShortage
	HandoverURL *string
	Request     *ProposalRequest
}

// ProposalItem is one stock_request_proposal_items row plus its demand line.
type ProposalItem struct {
	ID               int64
	ProposalID       int64
	StockRequestItem int64
	Quantity         int
	AcceptedQty      *int
	ShortQty         *int
	DamagedQty       *int
	// The demand line, rendered as the full StockRequestItemResource (see renderProposalItems).
	Demand RequestItem
}

// RequestItem is one stock_request_items row in the shape StockRequestItemResource renders.
type RequestItem struct {
	ID                 int64
	StockRequestID     int64
	OrderItemID        *int64
	ProductID          *int64
	ProductVariationID *int64
	ProductName        *string
	VariationLabel     *string
	SKUSnapshot        *string
	SKU                *string
	ProductImageURL    *string
	RequestedQty       int
	FulfilledQty       int
	RemainingQty       int
	// Product is the FULL model serialisation (columns + sku), present whenever the relation was loaded.
	Product map[string]any
	// Variation is the full variation model, present whenever loaded (null for product-targeted lines).
	Variation map[string]any
	// WithImages mirrors whether `product.images` was eager-loaded for THIS call: index and show load it
	// (the key renders as [], even when empty), propose/settle do NOT (the key is absent). Reproducing the
	// asymmetry is part of the contract.
	WithImages bool
}

// RecordedShortage is one activity-log shortage row attached to this proposal.
type RecordedShortage struct {
	StockRequestItemID int64
	Reason             string
	HandedOverQty      int
	ShortQty           int
}

// ProposalRequest is the parent StockRequest rendered inside a proposal.
type ProposalRequest struct {
	ID            int64
	OrderID       int64
	RequestNumber string
	Status        string
	OrderNo       any
	OrderStatus   any
}

// ProposeInput is the propose request.
type ProposeInput struct {
	ActorID      int64
	ActorRole    string
	ActorAgentID *int64
	RequestID    int64
	Items        []ProposalItemLine
	Shortages    []ShortageLine
	// Proof is the handover evidence upload. The reference REQUIRES an image whenever goods are handed over;
	// a shortage-only report carries none.
	Proof *media.Upload
}

// Propose is StockRequestProposalService::propose.
//
// NO stock moves here. The Koordinator's receipt (or the Admin exception) remains the single authoritative stock
// event; a proposal only records intent and the shortage audit trail.
func Propose(ctx context.Context, d *db.DB, in ProposeInput) (*Proposal, error) {
	if in.ActorRole != "gudang" || in.ActorAgentID == nil {
		return nil, requestProblem(403, "Hanya Gudang dengan network valid.")
	}
	if len(in.Items) == 0 {
		return nil, requestProblem(422, "Proposal harus memiliki item.")
	}

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()
	agentID := *in.ActorAgentID

	// A1-04: the Order row is the canonical serialization boundary — lock it first.
	var orderAgent int64
	var orderStatus string
	err = tx.QueryRowContext(ctx, `SELECT agent_id, status FROM orders WHERE id =
		(SELECT order_id FROM stock_requests WHERE id = ?) FOR UPDATE`, in.RequestID).Scan(&orderAgent, &orderStatus)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	if orderAgent != agentID {
		return nil, requestProblem(403, "Hanya Gudang dengan network valid.")
	}

	lockedStatus, inScope, err := lockRequestAndScope(ctx, tx, agentID, in.RequestID)
	if err != nil {
		return nil, err
	}
	if lockedStatus == "cancelled" || lockedStatus == "fulfilled" {
		return nil, requestProblem(422, "Stock Request tidak dapat diusulkan lagi.")
	}
	if (orderStatus != "diproses" && orderStatus != "dikirim") || !inScope {
		return nil, requestProblem(422, "Order tidak lagi dalam antrean Gudang; tidak dapat mengusulkan fulfillment.")
	}

	items, err := loadDemandLinesLocked(ctx, tx, in.RequestID)
	if err != nil {
		return nil, err
	}
	byID := map[int64]*demandLine{}
	for i := range items {
		byID[items[i].ID] = &items[i]
	}

	res, err := tx.ExecContext(ctx, `INSERT INTO stock_request_proposals
		(agent_id, stock_request_id, status, requested_by, created_at, updated_at)
		VALUES (?, ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		agentID, in.RequestID, in.ActorID)
	if err != nil {
		return nil, err
	}
	proposalID, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}

	seen := map[int64]bool{}
	var shortageRecords []shortageRecord

	for _, line := range in.Items {
		item, ok := byID[line.ItemID]
		if !ok || line.Quantity <= 0 || line.Quantity > item.RemainingQty-item.DamagedHold {
			return nil, requestProblem(422, "Jumlah proposal melebihi sisa request.")
		}
		if seen[line.ItemID] {
			return nil, requestProblem(422, "Item proposal tidak boleh duplikat.")
		}
		seen[line.ItemID] = true

		if _, err := tx.ExecContext(ctx, `INSERT INTO stock_request_proposal_items
			(stock_request_proposal_id, stock_request_item_id, quantity, decision_status, created_at, updated_at)
			VALUES (?, ?, ?, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			proposalID, line.ItemID, line.Quantity); err != nil {
			return nil, err
		}

		available := item.RemainingQty - item.DamagedHold
		if line.Quantity < available {
			reason := strings.TrimSpace(line.ShortageReason)
			if len([]rune(reason)) < 3 {
				return nil, requestProblem(422, "fulfillment.shortage_reason_required")
			}
			shortageRecords = append(shortageRecords, shortageRecord{item: item, handedOver: line.Quantity, reason: reason})
		}
	}

	for _, line := range in.Shortages {
		item, ok := byID[line.ItemID]
		if !ok || item.RemainingQty <= 0 {
			return nil, requestProblem(422, "fulfillment.shortage_item_invalid")
		}
		if seen[line.ItemID] {
			return nil, requestProblem(422, "fulfillment.shortage_item_duplicate")
		}
		reason := strings.TrimSpace(line.Reason)
		if len([]rune(reason)) < 3 {
			return nil, requestProblem(422, "fulfillment.shortage_reason_required")
		}
		seen[line.ItemID] = true
		shortageRecords = append(shortageRecords, shortageRecord{item: item, handedOver: 0, reason: reason})
	}

	// Handover evidence: stored and linked INSIDE the same transaction, exactly like the reference
	// (MediaService::store($proof, 'fulfillment_handover_proof', $proposal, $actor)).
	if in.Proof != nil {
		mediableType := "App\\Models\\StockRequestProposal"
		mediableID := proposalID
		createdBy := in.ActorID
		if _, err := media.Store(ctx, tx, "fulfillment_handover_proof", *in.Proof, &mediableType, &mediableID, &createdBy); err != nil {
			return nil, err
		}
	}

	if len(shortageRecords) > 0 {
		if err := recordShortages(ctx, tx, in.ActorID, in.RequestID, proposalID, shortageRecords); err != nil {
			return nil, err
		}
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadProposal(ctx, d, agentID, proposalID)
}

// ReportShortageOnly is StockRequestProposalService::reportShortageOnly: Gudang can hand over NOTHING.
// No proposal row exists (there is nothing to receive) and no stock moves; only the audit trail is written.
func ReportShortageOnly(ctx context.Context, d *db.DB, in ProposeInput) ([]RecordedShortage, error) {
	if in.ActorRole != "gudang" || in.ActorAgentID == nil {
		return nil, requestProblem(403, "Hanya Gudang dengan network valid.")
	}
	if len(in.Shortages) == 0 {
		return nil, requestProblem(422, "fulfillment.shortage_item_invalid")
	}

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()
	agentID := *in.ActorAgentID

	var orderStatus string
	err = tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id =
		(SELECT order_id FROM stock_requests WHERE id = ?) FOR UPDATE`, in.RequestID).Scan(&orderStatus)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}

	lockedStatus, inScope, err := lockRequestAndScope(ctx, tx, agentID, in.RequestID)
	if err != nil {
		return nil, err
	}
	if lockedStatus == "cancelled" || lockedStatus == "fulfilled" ||
		(orderStatus != "diproses" && orderStatus != "dikirim") || !inScope {
		return nil, requestProblem(422, "Order tidak lagi dalam antrean Gudang; tidak dapat mengusulkan fulfillment.")
	}

	items, err := loadDemandLinesLocked(ctx, tx, in.RequestID)
	if err != nil {
		return nil, err
	}
	byID := map[int64]*demandLine{}
	for i := range items {
		byID[items[i].ID] = &items[i]
	}

	seen := map[int64]bool{}
	var shortageRecords []shortageRecord
	for _, line := range in.Shortages {
		item, ok := byID[line.ItemID]
		if !ok || item.RemainingQty <= 0 {
			return nil, requestProblem(422, "fulfillment.shortage_item_invalid")
		}
		if seen[line.ItemID] {
			return nil, requestProblem(422, "fulfillment.shortage_item_duplicate")
		}
		reason := strings.TrimSpace(line.Reason)
		if len([]rune(reason)) < 3 {
			return nil, requestProblem(422, "fulfillment.shortage_reason_required")
		}
		seen[line.ItemID] = true
		shortageRecords = append(shortageRecords, shortageRecord{item: item, handedOver: 0, reason: reason})
	}
	if err := recordShortages(ctx, tx, in.ActorID, in.RequestID, 0, shortageRecords); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}

	out := make([]RecordedShortage, 0, len(shortageRecords))
	for _, record := range shortageRecords {
		out = append(out, RecordedShortage{
			StockRequestItemID: record.item.ID, Reason: record.reason,
			HandedOverQty: record.handedOver, ShortQty: record.item.RemainingQty - record.handedOver,
		})
	}
	return out, nil
}

// ApproveProposal is the ADMIN exception path: the whole prepared quantity is settled as accepted, with a
// mandatory reason recorded in the audit log. Routine settlement is the Koordinator's receipt.
func ApproveProposal(ctx context.Context, d *db.DB, actorID, agentID, proposalID int64, reason string) (*Proposal, error) {
	return settleProposal(ctx, d, actorID, agentID, proposalID, nil, reason)
}

// ReceiveProposal is the Koordinator Kurir's physical receipt — the routine settlement.
func ReceiveProposal(ctx context.Context, d *db.DB, actorID, agentID, proposalID int64, lines []ReceiptLine, note *string) (*Proposal, error) {
	return settleProposal(ctx, d, actorID, agentID, proposalID, lines, derefString(note))
}

// settleProposal is StockRequestProposalService::settle.
//
// The whole method is ONE transaction: lock the proposal, the request and its demand lines (locking read), take
// the canonical target locks, verify physical Transit and reservations cover the accepted totals, then move stock
// transit→shipping, decrement the reservation, advance the demand lines and recompute the request status from
// LOCKING sums.
func settleProposal(ctx context.Context, d *db.DB, actorID, agentID, proposalID int64, receipt []ReceiptLine, note string) (*Proposal, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	// Lock the proposal, agent-scoped.
	var proposalStatus string
	err = tx.QueryRowContext(ctx, `SELECT status FROM stock_request_proposals WHERE id = ? AND agent_id = ? FOR UPDATE`,
		proposalID, agentID).Scan(&proposalStatus)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	if proposalStatus == "approved" {
		// Already settled: return the current row, never move stock twice.
		if err := tx.Rollback(); err != nil {
			return nil, err
		}
		return LoadProposal(ctx, d, agentID, proposalID)
	}
	if proposalStatus != "pending" {
		return nil, requestProblem(422, "Proposal sudah diproses.")
	}

	var requestID int64
	var requestStatus string
	err = tx.QueryRowContext(ctx, `SELECT id, status FROM stock_requests WHERE id =
		(SELECT stock_request_id FROM stock_request_proposals WHERE id = ?) AND agent_id = ? FOR UPDATE`,
		proposalID, agentID).Scan(&requestID, &requestStatus)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	if requestStatus == "cancelled" || requestStatus == "fulfilled" {
		return nil, requestProblem(422, "Stock Request tidak dapat dipenuhi lagi.")
	}

	// Proposal items.
	proposalItems, err := loadProposalItemsLocked(ctx, tx, proposalID)
	if err != nil {
		return nil, err
	}
	// Demand lines, under lock (reads holds committed while this proposal waited).
	demand, err := loadDemandLinesLocked(ctx, tx, requestID)
	if err != nil {
		return nil, err
	}
	demandByID := map[int64]*demandLine{}
	for i := range demand {
		demandByID[demand[i].ID] = &demand[i]
	}

	// Accepted per proposal item.
	accepted := map[int64]int{}
	if receipt == nil {
		for _, item := range proposalItems {
			accepted[item.ID] = item.Quantity
		}
	} else {
		if len(receipt) != len(proposalItems) {
			return nil, requestProblem(422, "Penerimaan harus mencakup semua item proposal.")
		}
		byReceipt := map[int64]ReceiptLine{}
		for _, line := range receipt {
			byReceipt[line.ItemID] = line
		}
		for _, item := range proposalItems {
			line, ok := byReceipt[item.ID]
			if !ok || line.Accepted < 0 || line.Short < 0 || line.Damaged < 0 ||
				line.Accepted+line.Short+line.Damaged != item.Quantity {
				return nil, requestProblem(422, "Diterima + kurang + rusak harus sama dengan jumlah yang diserahkan Gudang.")
			}
			accepted[item.ID] = line.Accepted
		}
	}

	// Validate against the demand lines and collect the affected targets.
	type moveLine struct {
		proposalItem proposalItemRow
		demand       *demandLine
		key          string
		target       Target
	}
	var moves []moveLine
	for _, item := range proposalItems {
		dl := demandByID[item.StockRequestItem]
		if dl == nil || item.Quantity <= 0 || item.Quantity > dl.RemainingQty-dl.DamagedHold {
			return nil, requestProblem(422, "Jumlah proposal melebihi sisa request.")
		}
		if accepted[item.ID] == 0 {
			continue // nothing accepted → no stock movement, demand unchanged
		}
		var target Target
		if dl.VariationID != nil {
			target = VariationTarget(*dl.VariationID)
		} else {
			target = ProductTarget(*dl.ProductID)
		}
		moves = append(moves, moveLine{
			proposalItem: item, demand: dl,
			key: targetKey(target.ProductID, target.VariationID), target: target,
		})
	}

	// Canonical target locks — the SAME vocabulary as checkout and SC-03.
	targets := make([]Target, 0, len(moves))
	seenTarget := map[string]bool{}
	for _, m := range moves {
		if !seenTarget[m.key] {
			seenTarget[m.key] = true
			targets = append(targets, m.target)
		}
	}
	if err := LockReservationTargets(ctx, tx, agentID, targets); err != nil {
		return nil, err
	}

	// Aggregate accepted totals per target and validate the physical/reservation coverage.
	totals := map[string]int{}
	for _, m := range moves {
		totals[m.key] += accepted[m.proposalItem.ID]
	}
	for key, total := range totals {
		var target Target
		for _, m := range moves {
			if m.key == key {
				target = m.target
				break
			}
		}
		var transitQuantity int
		if err := tx.QueryRowContext(ctx, bucketQuantityLocked("transit"), agentID,
			nullableInt(target.ProductID), nullableInt(target.VariationID)).Scan(&transitQuantity); err != nil {
			return nil, err
		}
		if transitQuantity < total {
			return nil, requestProblem(422, "Stok fisik Transit tidak mencukupi.")
		}
		reserved, err := reservedForQuery(ctx, tx, agentID, target)
		if err != nil {
			return nil, err
		}
		if reserved < total {
			return nil, requestProblem(422, "Reservasi tidak mencukupi untuk fulfillment.")
		}
	}

	// Idempotent fulfillment marker: one StockRequestFulfillment per settlement.
	var operationID int64
	if len(totals) > 0 {
		res, execErr := tx.ExecContext(ctx, `INSERT INTO stock_request_fulfillments
			(stock_request_id, idempotency_key, fulfilled_by, created_at, updated_at)
			VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			requestID, fmt.Sprintf("proposal-%d", proposalID), actorID)
		if execErr != nil {
			return nil, execErr
		}
		if operationID, err = res.LastInsertId(); err != nil {
			return nil, err
		}
	}

	for key, total := range totals {
		var target Target
		var dl *demandLine
		for _, m := range moves {
			if m.key == key {
				target, dl = m.target, m.demand
				break
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity - ?, updated_at = UTC_TIMESTAMP()
			WHERE agent_id = ? AND stock_type = 'transit' AND sub_location_id IS NULL AND `+targetClauseRaw(target),
			total, agentID, nullableInt(target.ProductID), nullableInt(target.VariationID)); err != nil {
			return nil, err
		}
		// The shipping bucket may not exist yet — insert-or-increment in one statement (the row is protected by
		// the same target lock taken above).
		if _, err := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks
			(agent_id, product_id, product_variation_id, stock_type, quantity, created_at, updated_at)
			VALUES (?, ?, ?, 'shipping', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = UTC_TIMESTAMP()`,
			agentID, nullableInt(target.ProductID), nullableInt(target.VariationID)); err != nil {
			return nil, err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity + ?, updated_at = UTC_TIMESTAMP()
			WHERE agent_id = ? AND stock_type = 'shipping' AND sub_location_id IS NULL AND `+targetClauseRaw(target),
			total, agentID, nullableInt(target.ProductID), nullableInt(target.VariationID)); err != nil {
			return nil, err
		}
		if _, err := tx.ExecContext(ctx, decrementReservation(target),
			total, agentID, nullableInt(target.ProductID), nullableInt(target.VariationID)); err != nil {
			return nil, err
		}

		note := fmt.Sprintf("fulfillment_operation=%d;proposal=%d", operationID, proposalID)
		movements := []struct {
			stockType   string
			quantity    int
			counterpart string
		}{
			{"transit", -total, "shipping"},
			{"shipping", total, "transit"},
		}
		for _, movementType := range movements {
			if _, err := tx.ExecContext(ctx, `INSERT INTO stock_movements
				(agent_id, product_id, product_variation_id, type, quantity, stock_type, sub_location_id,
				 counterpart_stock_type, reference_type, reference_id, note, created_by, created_at)
				VALUES (?, ?, ?, 'fulfillment', ?, ?, NULL, ?, 'App\\\\Models\\\\StockRequest', ?, ?, ?, UTC_TIMESTAMP())`,
				agentID, nullableInt(dl.ProductID), nullableInt(dl.VariationID),
				movementType.quantity, movementType.stockType, movementType.counterpart,
				requestID, note, actorID); err != nil {
				return nil, err
			}
		}
	}

	// Advance the demand lines.
	for _, m := range moves {
		qty := accepted[m.proposalItem.ID]
		if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items
			SET fulfilled_qty = fulfilled_qty + ?, remaining_qty = remaining_qty - ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, qty, qty, m.proposalItem.StockRequestItem); err != nil {
			return nil, err
		}
	}

	// LOCKING sums — a plain SUM() would read the pre-lock REPEATABLE READ snapshot (see the package comment).
	var remaining, fulfilled int
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(remaining_qty),0), COALESCE(SUM(fulfilled_qty),0)
		FROM stock_request_items WHERE stock_request_id = ? FOR UPDATE`, requestID).Scan(&remaining, &fulfilled); err != nil {
		return nil, err
	}
	newStatus := "pending"
	if remaining == 0 {
		newStatus = "fulfilled"
	} else if fulfilled > 0 {
		newStatus = "partial"
	}
	var fulfilledAt any
	if remaining == 0 {
		fulfilledAt = time.Now().UTC()
	}
	if _, err := tx.ExecContext(ctx, `UPDATE stock_requests
		SET status = ?, fulfilled_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		newStatus, fulfilledAt, requestID); err != nil {
		return nil, err
	}

	if receipt == nil {
		// Admin exception approval.
		if _, err := tx.ExecContext(ctx, `UPDATE stock_request_proposals
			SET status = 'approved', approved_by = ?, approved_at = UTC_TIMESTAMP(), receipt_note = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actorID, nullIfEmpty(note), proposalID); err != nil {
			return nil, err
		}
		if err := writeProposalActivity(ctx, tx, actorID, proposalID, "stock_request.admin_exception_approval", note, map[string]any{
			"stock_request_id": requestID,
		}); err != nil {
			return nil, err
		}
	} else {
		// F07: damaged units are NOT accepted and NOT sellable — hold them on the line until an Admin resolves them.
		byReceipt := map[int64]ReceiptLine{}
		for _, line := range receipt {
			byReceipt[line.ItemID] = line
		}
		for _, item := range proposalItems {
			line := byReceipt[item.ID]
			if line.Damaged > 0 {
				if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items
					SET damaged_hold_qty = damaged_hold_qty + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
					line.Damaged, item.StockRequestItem); err != nil {
					return nil, err
				}
			}
			if _, err := tx.ExecContext(ctx, `UPDATE stock_request_proposal_items
				SET accepted_qty = ?, short_qty = ?, damaged_qty = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
				line.Accepted, line.Short, line.Damaged, item.ID); err != nil {
				return nil, err
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE stock_request_proposals
			SET status = 'approved', received_by = ?, received_at = UTC_TIMESTAMP(), receipt_note = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, actorID, nullIfEmpty(note), proposalID); err != nil {
			return nil, err
		}
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadProposal(ctx, d, agentID, proposalID)
}

// RejectProposal is StockRequestProposalService::reject.
func RejectProposal(ctx context.Context, d *db.DB, actorID, agentID, proposalID int64, reason string) (*Proposal, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	var status string
	err = tx.QueryRowContext(ctx, `SELECT status FROM stock_request_proposals WHERE id = ? AND agent_id = ? FOR UPDATE`,
		proposalID, agentID).Scan(&status)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	if status == "rejected" {
		return LoadProposal(ctx, d, agentID, proposalID)
	}
	if status != "pending" {
		return nil, requestProblem(422, "Proposal sudah diproses.")
	}
	if _, err := tx.ExecContext(ctx, `UPDATE stock_request_proposals
		SET status = 'rejected', rejected_by = ?, rejected_at = UTC_TIMESTAMP(), rejection_reason = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, actorID, reason, proposalID); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadProposal(ctx, d, agentID, proposalID)
}

// ResolveDiscrepancyInput is the F07 Admin write-off request.
type ResolveDiscrepancyInput struct {
	ActorID        int64
	ActorAgentID   *int64
	ItemID         int64
	Action         string
	Quantity       int
	Reason         string
	IdempotencyKey string
}

// ResolveDiscrepancy is StockRequestProposalService::resolveDiscrepancy.
//
// `restock` is REFUSED outright (pending an Owner-approved physical-inspection workflow — the reference refuses it
// too). `write_off` reduces Transit by an adjustment movement and lifts the hold; the line still owes those units,
// so Gudang prepares replacements. The idempotency key is checked through a LOCKING read of the activity log, so a
// retried resolution answers 409 instead of writing off twice.
func ResolveDiscrepancy(ctx context.Context, d *db.DB, in ResolveDiscrepancyInput) (itemID int64, damagedHold, remaining int, err error) {
	if in.ActorAgentID == nil {
		return 0, 0, 0, requestProblem(403, "Hanya Admin yang dapat menyelesaikan selisih stok.")
	}
	if in.Action != "restock" && in.Action != "write_off" {
		return 0, 0, 0, requestProblem(422, "Aksi tidak valid.")
	}
	if in.Action == "restock" {
		return 0, 0, 0, requestProblem(422, "Restock ditahan sampai prosedur inspeksi fisik disetujui Owner.")
	}
	if !isUUID(in.IdempotencyKey) {
		return 0, 0, 0, requestProblem(422, "Kunci idempotensi UUID wajib diisi.")
	}

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return 0, 0, 0, err
	}
	defer func() { _ = tx.Rollback() }()
	agentID := *in.ActorAgentID

	var requestID int64
	err = tx.QueryRowContext(ctx, `SELECT stock_request_id FROM stock_request_items WHERE id = ?`, in.ItemID).Scan(&requestID)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, 0, 0, ErrStockRequestNotFound
	}
	if err != nil {
		return 0, 0, 0, err
	}
	var requestAgent int64
	if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM stock_requests WHERE id = ? FOR UPDATE`, requestID).
		Scan(&requestAgent); err != nil {
		return 0, 0, 0, err
	}
	if requestAgent != agentID {
		return 0, 0, 0, ErrStockRequestNotFound
	}

	var (
		productID, variationID sql.NullInt64
		damagedHoldQty         int
		remainingQty           int
	)
	err = tx.QueryRowContext(ctx, `SELECT product_id, product_variation_id, damaged_hold_qty, remaining_qty
		FROM stock_request_items WHERE id = ? AND stock_request_id = ? FOR UPDATE`, in.ItemID, requestID).
		Scan(&productID, &variationID, &damagedHoldQty, &remainingQty)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, 0, 0, ErrStockRequestNotFound
	}
	if err != nil {
		return 0, 0, 0, err
	}

	// Idempotency: a locking read of the activity log sees a committed retry key even under REPEATABLE READ.
	var previous int64
	err = tx.QueryRowContext(ctx, `SELECT id FROM activity_logs
		WHERE subject_type = 'App\\\\Models\\\\StockRequestItem' AND subject_id = ?
		  AND event = 'stock_request.discrepancy_resolved'
		  AND JSON_UNQUOTE(JSON_EXTRACT(properties, '$.idempotency_key')) = ? FOR UPDATE`,
		in.ItemID, in.IdempotencyKey).Scan(&previous)
	if err == nil {
		return 0, 0, 0, requestProblem(409, "Resolusi dengan kunci ini sudah diproses.")
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return 0, 0, 0, err
	}

	if in.Quantity < 1 || in.Quantity > damagedHoldQty {
		return 0, 0, 0, requestProblem(422, "Jumlah melebihi selisih rusak yang tertahan.")
	}

	// write_off: reduce Transit by the written-off quantity.
	var transitQuantity int
	err = tx.QueryRowContext(ctx, bucketQuantityLocked("transit"), agentID,
		nullableInt(nullInt64Ptr(productID)), nullableInt(nullInt64Ptr(variationID))).Scan(&transitQuantity)
	if err != nil {
		return 0, 0, 0, err
	}
	if transitQuantity < in.Quantity {
		return 0, 0, 0, requestProblem(422, "Stok Transit tidak mencukupi untuk penghapusan.")
	}
	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity - ?, updated_at = UTC_TIMESTAMP()
		WHERE agent_id = ? AND stock_type = 'transit' AND sub_location_id IS NULL AND product_id <=> ? AND product_variation_id <=> ?`,
		in.Quantity, agentID, nullableInt(nullInt64Ptr(productID)), nullableInt(nullInt64Ptr(variationID))); err != nil {
		return 0, 0, 0, err
	}

	reason := in.Reason
	if len([]rune(reason)) > 120 {
		reason = string([]rune(reason)[:120])
	}
	note := fmt.Sprintf("damaged_write_off;item=%d;reason=%s", in.ItemID, reason)
	if _, err := tx.ExecContext(ctx, `INSERT INTO stock_movements
		(agent_id, product_id, product_variation_id, type, quantity, stock_type,
		 reference_type, reference_id, note, created_by, created_at)
		VALUES (?, ?, ?, 'adjustment', ?, 'transit', 'App\\\\Models\\\\StockRequest', ?, ?, ?, UTC_TIMESTAMP())`,
		agentID, nullableInt(nullInt64Ptr(productID)), nullableInt(nullInt64Ptr(variationID)),
		-in.Quantity, requestID, note, in.ActorID); err != nil {
		return 0, 0, 0, err
	}

	if _, err := tx.ExecContext(ctx, `UPDATE stock_request_items
		SET damaged_hold_qty = damaged_hold_qty - ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		in.Quantity, in.ItemID); err != nil {
		return 0, 0, 0, err
	}
	if err := writeItemActivity(ctx, tx, in.ActorID, in.ItemID, "stock_request.discrepancy_resolved", in.Reason, map[string]any{
		"action": in.Action, "quantity": in.Quantity, "idempotency_key": in.IdempotencyKey,
	}); err != nil {
		return 0, 0, 0, err
	}
	if err := tx.Commit(); err != nil {
		return 0, 0, 0, err
	}
	return in.ItemID, damagedHoldQty - in.Quantity, remainingQty, nil
}

// ---- internals -------------------------------------------------------------------------------------

// shortageRecord is one shortage audit-trail entry, written under the caller's locks.
type shortageRecord struct {
	item       *demandLine
	handedOver int
	reason     string
}

// demandLine is one stock_request_items row in the shape the settlement reads.
type demandLine struct {
	ID           int64
	RequestID    int64
	OrderItemID  int64
	ProductID    *int64
	VariationID  *int64
	SKUSnapshot  *string
	RequestedQty int
	FulfilledQty int
	RemainingQty int
	DamagedHold  int
}

type proposalItemRow struct {
	ID               int64
	StockRequestItem int64
	Quantity         int
}

// lockRequestAndScope locks the StockRequest row and evaluates Order::isInWarehouseScope:
// a self-delivering (Sub) order is NEVER in scope; a diproses order without any courier assignment is;
// otherwise a diproses/dikirim order with outstanding warehouse demand (pending/partial request) is.
func lockRequestAndScope(ctx context.Context, tx *sql.Tx, agentID, requestID int64) (string, bool, error) {
	var status string
	var orderID int64
	err := tx.QueryRowContext(ctx, `SELECT status, order_id FROM stock_requests WHERE id = ? AND agent_id = ? FOR UPDATE`,
		requestID, agentID).Scan(&status, &orderID)
	if errors.Is(err, sql.ErrNoRows) {
		return "", false, ErrStockRequestNotFound
	}
	if err != nil {
		return "", false, err
	}

	var selfDelivery, courierAssigned bool
	if err := tx.QueryRowContext(ctx, `SELECT
			EXISTS(SELECT 1 FROM shipments WHERE order_id = ? AND self_delivered_by_user_id IS NOT NULL),
			EXISTS(SELECT 1 FROM shipments WHERE order_id = ? AND courier_id IS NOT NULL)`, orderID, orderID).
		Scan(&selfDelivery, &courierAssigned); err != nil {
		return "", false, err
	}
	if selfDelivery {
		return status, false, nil
	}

	var orderStatus string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ?`, orderID).Scan(&orderStatus); err != nil {
		return "", false, err
	}
	if orderStatus == "diproses" && !courierAssigned {
		return status, true, nil
	}
	if orderStatus == "diproses" || orderStatus == "dikirim" {
		var outstanding bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_requests
			WHERE order_id = ? AND status IN ('pending','partial'))`, orderID).Scan(&outstanding); err != nil {
			return "", false, err
		}
		return status, outstanding, nil
	}
	return status, false, nil
}

func loadDemandLinesLocked(ctx context.Context, tx *sql.Tx, requestID int64) ([]demandLine, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, stock_request_id, order_item_id, product_id, product_variation_id,
			sku_snapshot, requested_qty, fulfilled_qty, remaining_qty, damaged_hold_qty
		FROM stock_request_items WHERE stock_request_id = ? ORDER BY id FOR UPDATE`, requestID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []demandLine
	for rows.Next() {
		var (
			line                   demandLine
			productID, variationID sql.NullInt64
			sku                    sql.NullString
		)
		if err := rows.Scan(&line.ID, &line.RequestID, &line.OrderItemID, &productID, &variationID,
			&sku, &line.RequestedQty, &line.FulfilledQty, &line.RemainingQty, &line.DamagedHold); err != nil {
			return nil, err
		}
		line.ProductID = nullInt64Ptr(productID)
		line.VariationID = nullInt64Ptr(variationID)
		line.SKUSnapshot = nullStringPtrLocal(sku)
		out = append(out, line)
	}
	return out, rows.Err()
}

func loadProposalItemsLocked(ctx context.Context, tx *sql.Tx, proposalID int64) ([]proposalItemRow, error) {
	rows, err := tx.QueryContext(ctx, `SELECT id, stock_request_item_id, quantity
		FROM stock_request_proposal_items WHERE stock_request_proposal_id = ? ORDER BY id FOR UPDATE`, proposalID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []proposalItemRow
	for rows.Next() {
		var item proposalItemRow
		if err := rows.Scan(&item.ID, &item.StockRequestItem, &item.Quantity); err != nil {
			return nil, err
		}
		out = append(out, item)
	}
	return out, rows.Err()
}

func bucketQuantityLocked(stockType string) string {
	return `SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = '` + stockType + `' AND sub_location_id IS NULL
		  AND product_id <=> ? AND product_variation_id <=> ? FOR UPDATE`
}

func targetClauseRaw(t Target) string {
	if t.VariationID != nil {
		return "product_variation_id = ? AND product_id IS NULL"
	}
	return "product_id = ? AND product_variation_id IS NULL"
}

func decrementReservation(t Target) string {
	if t.VariationID != nil {
		return `UPDATE product_variation_stocks
			SET quantity_reserved = GREATEST(CAST(quantity_reserved AS SIGNED) - ?, 0), updated_at = UTC_TIMESTAMP()
			WHERE agent_id = ? AND product_variation_id = ?`
	}
	return `UPDATE product_stocks
		SET quantity_reserved = GREATEST(CAST(quantity_reserved AS SIGNED) - ?, 0), updated_at = UTC_TIMESTAMP()
		WHERE agent_id = ? AND product_id = ?`
}

// recordShortages writes one audit row per shortage line and (deduplicated) notifies the branch Admin/Agen.
// The notification is a queue concern in the reference; the AUDIT ROWS are durable state and are ported here.
func recordShortages(ctx context.Context, tx *sql.Tx, actorID, requestID, proposalID int64, records []shortageRecord) error {
	for _, record := range records {
		props := map[string]any{
			"order_id":           nil, // filled below
			"order_item_id":      record.item.OrderItemID,
			"proposal_id":        nil,
			"requested_qty":      record.item.RequestedQty,
			"outstanding_before": record.item.RemainingQty,
			"handed_over_qty":    record.handedOver,
			"short_qty":          record.item.RemainingQty - record.handedOver,
		}
		if proposalID > 0 {
			props["proposal_id"] = proposalID
		}
		var orderID int64
		if err := tx.QueryRowContext(ctx, `SELECT order_id FROM stock_requests WHERE id = ?`, requestID).
			Scan(&orderID); err != nil {
			return err
		}
		props["order_id"] = orderID
		if err := writeItemActivity(ctx, tx, actorID, record.item.ID, "stock_request.fulfillment_shortage", record.reason, props); err != nil {
			return err
		}
	}
	return nil
}

func writeItemActivity(ctx context.Context, tx *sql.Tx, actorID, itemID int64, event, description string, properties map[string]any) error {
	raw, err := json.Marshal(properties)
	if err != nil {
		return err
	}
	var payload any = string(raw)
	if len(properties) == 0 {
		payload = nil
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, created_at)
		VALUES (?, 'App\\\\Models\\\\StockRequestItem', ?, ?, ?, ?, UTC_TIMESTAMP())`,
		actorID, itemID, event, description, payload)
	return err
}

func writeProposalActivity(ctx context.Context, tx *sql.Tx, actorID, proposalID int64, event, description string, properties map[string]any) error {
	raw, err := json.Marshal(properties)
	if err != nil {
		return err
	}
	var payload any = string(raw)
	if len(properties) == 0 {
		payload = nil
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, created_at)
		VALUES (?, 'App\\\\Models\\\\StockRequestProposal', ?, ?, ?, ?, UTC_TIMESTAMP())`,
		actorID, proposalID, event, description, payload)
	return err
}

func isUUID(v string) bool {
	if len(v) != 36 {
		return false
	}
	for i, c := range v {
		switch i {
		case 8, 13, 18, 23:
			if c != '-' {
				return false
			}
		default:
			isHex := (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F')
			if !isHex {
				return false
			}
		}
	}
	return true
}

func derefString(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

// nullIfEmpty persists an empty note as NULL, matching Laravel's null $note (an ” would serialise and
// report differently from NULL).
func nullIfEmpty(v string) any {
	if v == "" {
		return nil
	}
	return v
}

// LoadProposal reads one proposal with every relation the resource renders (items + demand lines, shortages from
// the activity log, the handover proof, the parent request and its order).
func LoadProposal(ctx context.Context, d *db.DB, agentID, proposalID int64) (*Proposal, error) {
	return loadProposal(ctx, d, agentID, proposalID, false)
}

// LoadProposalDetail is the index/show shape: product.images ARE loaded (the key renders even when empty).
func LoadProposalDetail(ctx context.Context, d *db.DB, agentID, proposalID int64) (*Proposal, error) {
	return loadProposal(ctx, d, agentID, proposalID, true)
}

func loadProposal(ctx context.Context, d *db.DB, agentID, proposalID int64, withImages bool) (*Proposal, error) {
	var (
		p                         Proposal
		requesterName             sql.NullString
		approvedBy, receivedBy    sql.NullInt64
		rejectedBy                sql.NullInt64
		approvedAt, receivedAt    sql.NullTime
		rejectedAt                sql.NullTime
		receiptNote, rejectReason sql.NullString
		proofPath                 sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT p.id, p.agent_id, p.stock_request_id, p.status, p.requested_by,
			u.name,
			p.approved_by, p.approved_at, p.received_by, p.received_at, p.receipt_note,
			p.rejected_by, p.rejected_at, p.rejection_reason, p.created_at, p.updated_at,
			(SELECT m.path FROM media m WHERE m.mediable_type = 'App\\\\Models\\\\StockRequestProposal'
			 AND m.mediable_id = p.id AND m.collection = 'fulfillment_handover_proof'
			 ORDER BY m.id DESC LIMIT 1)
		FROM stock_request_proposals p
		LEFT JOIN users u ON u.id = p.requested_by
		WHERE p.id = ? AND p.agent_id = ?`, proposalID, agentID).
		Scan(&p.ID, &p.AgentID, &p.StockRequestID, &p.Status, &p.RequestedBy, &requesterName,
			&approvedBy, &approvedAt, &receivedBy, &receivedAt, &receiptNote,
			&rejectedBy, &rejectedAt, &rejectReason, &p.CreatedAt, &p.UpdatedAt, &proofPath)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	p.RequesterName = nullStringPtrLocal(requesterName)
	p.ApprovedBy = nullInt64Ptr(approvedBy)
	p.ReceivedBy = nullInt64Ptr(receivedBy)
	p.RejectedBy = nullInt64Ptr(rejectedBy)
	p.ReceiptNote = nullStringPtrLocal(receiptNote)
	p.RejectionReason = nullStringPtrLocal(rejectReason)
	if approvedAt.Valid {
		t := approvedAt.Time
		p.ApprovedAt = &t
	}
	if receivedAt.Valid {
		t := receivedAt.Time
		p.ReceivedAt = &t
	}
	if rejectedAt.Valid {
		t := rejectedAt.Time
		p.RejectedAt = &t
	}
	if proofPath.Valid {
		url := assetURLLocal(proofPath.String)
		p.HandoverURL = &url
	}

	// Items + their demand lines, rendered as the full StockRequestItemResource (product WITH images,
	// variation with compositions.option — both relations are loaded on every read path here).
	rows, err := d.QueryContext(ctx, `SELECT pi.id, pi.stock_request_proposal_id, pi.stock_request_item_id, pi.quantity,
			pi.accepted_qty, pi.short_qty, pi.damaged_qty
		FROM stock_request_proposal_items pi
		WHERE pi.stock_request_proposal_id = ? ORDER BY pi.id`, proposalID)
	if err != nil {
		return nil, err
	}
	var proposalItems []ProposalItem
	for rows.Next() {
		var (
			it                       ProposalItem
			accepted, short, damaged sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &it.ProposalID, &it.StockRequestItem, &it.Quantity,
			&accepted, &short, &damaged); err != nil {
			rows.Close()
			return nil, err
		}
		if accepted.Valid {
			v := int(accepted.Int64)
			it.AcceptedQty = &v
		}
		if short.Valid {
			v := int(short.Int64)
			it.ShortQty = &v
		}
		if damaged.Valid {
			v := int(damaged.Int64)
			it.DamagedQty = &v
		}
		proposalItems = append(proposalItems, it)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	for i := range proposalItems {
		demand, derr := loadRequestItem(ctx, d, proposalItems[i].StockRequestItem, withImages)
		if derr != nil {
			return nil, derr
		}
		proposalItems[i].Demand = *demand
	}
	p.Items = proposalItems

	// Recorded shortages come from the activity log (proposal-scoped).
	logRows, err := d.QueryContext(ctx, `SELECT subject_id, description,
			CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.handed_over_qty')) AS UNSIGNED),
			CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.short_qty')) AS UNSIGNED)
		FROM activity_logs
		WHERE subject_type = 'App\\\\Models\\\\StockRequestItem' AND event = 'stock_request.fulfillment_shortage'
		  AND JSON_UNQUOTE(JSON_EXTRACT(properties, '$.proposal_id')) = ?
		ORDER BY id`, proposalID)
	if err != nil {
		return nil, err
	}
	for logRows.Next() {
		var s RecordedShortage
		if err := logRows.Scan(&s.StockRequestItemID, &s.Reason, &s.HandedOverQty, &s.ShortQty); err != nil {
			logRows.Close()
			return nil, err
		}
		p.Shortages = append(p.Shortages, s)
	}
	logRows.Close()
	if err := logRows.Err(); err != nil {
		return nil, err
	}

	// The parent request and its order.
	var (
		req         ProposalRequest
		orderNo     sql.NullString
		orderStatus sql.NullString
	)
	err = d.QueryRowContext(ctx, `SELECT r.id, r.order_id, r.request_number, r.status, o.order_no, o.status
		FROM stock_requests r LEFT JOIN orders o ON o.id = r.order_id
		WHERE r.id = ? AND r.agent_id = ?`, p.StockRequestID, agentID).
		Scan(&req.ID, &req.OrderID, &req.RequestNumber, &req.Status, &orderNo, &orderStatus)
	if err == nil {
		if orderNo.Valid {
			req.OrderNo = orderNo.String
		}
		if orderStatus.Valid {
			req.OrderStatus = orderStatus.String
		}
		p.Request = &req
	} else if !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	return &p, nil
}

// ListProposalsFilter carries the index query filters.
type ListProposalsFilter struct {
	Scope          string
	Status         string
	StockRequestID *int64
	PerPage        int
	Page           int
}

// ListProposals is StockRequestProposalController::index.
func ListProposals(ctx context.Context, d *db.DB, actorID, agentID int64, f ListProposalsFilter) ([]*Proposal, int, error) {
	where := " FROM stock_request_proposals p WHERE p.agent_id = ?"
	args := []any{agentID}
	if f.Scope == "mine" {
		where += " AND p.requested_by = ?"
		args = append(args, actorID)
	}
	if f.Status != "" {
		where += " AND p.status = ?"
		args = append(args, f.Status)
	}
	if f.StockRequestID != nil {
		where += " AND p.stock_request_id = ?"
		args = append(args, *f.StockRequestID)
	}

	var total int
	if err := d.QueryRowContext(ctx, "SELECT COUNT(*)"+where, args...).Scan(&total); err != nil {
		return nil, 0, err
	}
	perPage := f.PerPage
	if perPage < 1 {
		perPage = 15
	}
	page := f.Page
	if page < 1 {
		page = 1
	}

	rows, err := d.QueryContext(ctx, "SELECT p.id"+where+" ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?",
		append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, 0, err
	}
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return nil, 0, err
		}
		ids = append(ids, id)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, 0, err
	}

	out := make([]*Proposal, 0, len(ids))
	for _, id := range ids {
		proposal, err := LoadProposalDetail(ctx, d, agentID, id)
		if err != nil {
			return nil, 0, err
		}
		out = append(out, proposal)
	}
	return out, total, nil
}

// ---- helpers shared with the handlers --------------------------------------------------------------

func assetURLLocal(path string) string {
	return asset.URL(context.Background(), path)
}

// SortInt64 is a small helper used by deterministic id ordering.
func SortInt64(v []int64) { sort.Slice(v, func(i, j int) bool { return v[i] < v[j] }) }

// loadRequestItem reads one demand line in the full StockRequestItemResource shape, with the product (and its
// images) and the variation (with compositions.option) serialised the way Eloquent renders those models.
func loadRequestItem(ctx context.Context, d *db.DB, itemID int64, withImages bool) (*RequestItem, error) {
	var (
		it                     RequestItem
		orderItemID            sql.NullInt64
		productID, variationID sql.NullInt64
		loadedImages           = withImages
		productName            sql.NullString
		skuSnapshot            sql.NullString
		productSKU             sql.NullString
		variationSKU           sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT sri.id, sri.stock_request_id, sri.order_item_id, sri.product_id,
			sri.product_variation_id, pr.name, sri.sku_snapshot, pr.sku, pv.sku,
			sri.requested_qty, sri.fulfilled_qty, sri.remaining_qty
		FROM stock_request_items sri
		LEFT JOIN products pr ON pr.id = sri.product_id
		LEFT JOIN product_variations pv ON pv.id = sri.product_variation_id
		WHERE sri.id = ?`, itemID).
		Scan(&it.ID, &it.StockRequestID, &orderItemID, &productID, &variationID, &productName,
			&skuSnapshot, &productSKU, &variationSKU, &it.RequestedQty, &it.FulfilledQty, &it.RemainingQty)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	it.WithImages = loadedImages
	it.OrderItemID = nullInt64Ptr(orderItemID)
	it.ProductID = nullInt64Ptr(productID)
	it.ProductVariationID = nullInt64Ptr(variationID)
	it.ProductName = nullStringPtrLocal(productName)
	it.SKUSnapshot = nullStringPtrLocal(skuSnapshot)
	// `sku` prefers the snapshot, then the variation's sku, then the product's.
	switch {
	case skuSnapshot.Valid && skuSnapshot.String != "":
		s := skuSnapshot.String
		it.SKU = &s
	case variationID.Valid && variationSKU.Valid:
		s := variationSKU.String
		it.SKU = &s
	case productSKU.Valid:
		s := productSKU.String
		it.SKU = &s
	}

	if productID.Valid {
		product, perr := serializeProduct(ctx, d, productID.Int64, it.WithImages)
		if perr != nil {
			return nil, perr
		}
		if path := primaryImagePath(product); path != "" {
			url := assetURLLocal(path)
			it.ProductImageURL = &url
		}
		delete(product, "__image_path")
		it.Product = product
	}
	if variationID.Valid {
		variation, verr := serializeVariation(ctx, d, variationID.Int64)
		if verr != nil {
			return nil, verr
		}
		it.Variation = variation
		if label, present := variation["__label"]; present {
			if labelString, ok := label.(string); ok {
				it.VariationLabel = &labelString
			}
		}
		delete(variation, "__label")
	}
	return &it, nil
}

// primaryImagePath pulls the internal helper key the serializer leaves behind.
func primaryImagePath(product map[string]any) string {
	if path, ok := product["__image_path"].(string); ok {
		return path
	}
	return ""
}

// serializeProduct renders a Product model exactly as Eloquent does: every column, the appended sku, and — only
// when the caller loaded it — the `images` relation. `__image_path` is an internal helper key, removed before
// rendering.
func serializeProduct(ctx context.Context, d queryExecer, productID int64, withImages bool) (map[string]any, error) {
	var (
		product               serializedProduct
		hasVariations, status sql.NullString
		basePrice             sql.NullString
		categoryID, createdBy sql.NullInt64
		weight                sql.NullInt64
		globalSKU             sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT p.id, p.category_id, p.created_by, p.name, p.slug, p.description,
			p.short_description, p.has_variations, CAST(p.base_price AS CHAR), p.weight_grams, p.status,
			p.created_at, p.updated_at, p.deleted_at, p.sku
		FROM products p WHERE p.id = ?`, productID).
		Scan(&product.ID, &categoryID, &createdBy, &product.Name, &product.Slug, &product.Description,
			&product.ShortDescription, &hasVariations, &basePrice, &weight, &status,
			&product.CreatedAt, &product.UpdatedAt, &product.DeletedAt, &globalSKU)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}

	out := map[string]any{
		"id":                product.ID,
		"category_id":       nullableInt(nullInt64Ptr(categoryID)),
		"created_by":        nullableInt(nullInt64Ptr(createdBy)),
		"name":              product.Name,
		"slug":              product.Slug,
		"description":       product.Description,
		"short_description": product.ShortDescription,
		"has_variations":    hasVariations.Valid && hasVariations.String == "1",
		"base_price":        nil,
		"status":            status.String,
		"created_at":        renderJSONTime(product.CreatedAt),
		"updated_at":        renderJSONTime(product.UpdatedAt),
		"deleted_at":        renderJSONTimePtr(product.DeletedAt),
		"sku":               jsonNullableString(nullStringPtrLocal(globalSKU)),
	}
	if basePrice.Valid {
		out["base_price"] = basePrice.String
	}
	if weight.Valid {
		out["weight_grams"] = weight.Int64
	} else {
		out["weight_grams"] = nil
	}

	// images: ONLY when the caller eager-loaded them (index/show); propose/settle leave the key absent.
	if withImages {
		images, ierr := serializeProductImages(ctx, d, productID)
		if ierr != nil {
			return nil, ierr
		}
		out["images"] = images
		if len(images) > 0 {
			if first, ok := images[0].(map[string]any); ok {
				if path, present := first["path"].(string); present {
					out["__image_path"] = path
				}
			}
		}
	}
	return out, nil
}

type serializedProduct struct {
	ID               int64
	Name, Slug       string
	Description      string
	ShortDescription string
	CreatedAt        sql.NullTime
	UpdatedAt        sql.NullTime
	DeletedAt        sql.NullTime
}

func serializeProductImages(ctx context.Context, d queryExecer, productID int64) ([]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, product_id, product_variation_id, path, is_primary, sort_order,
			created_at, updated_at
		FROM product_images WHERE product_id = ? ORDER BY sort_order, id`, productID)
	if err != nil {
		if db.IsMissingSchema(err) {
			return []any{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	// A product with no images renders `images: []`, never null — a nil slice marshals to JSON null.
	out := make([]any, 0)
	for rows.Next() {
		var (
			id, pid     int64
			variationID sql.NullInt64
			path        string
			isPrimary   int
			sortOrder   int
			createdAt   sql.NullTime
			updatedAt   sql.NullTime
		)
		if err := rows.Scan(&id, &pid, &variationID, &path, &isPrimary, &sortOrder, &createdAt, &updatedAt); err != nil {
			return nil, err
		}
		image := map[string]any{
			"id":                   id,
			"product_id":           pid,
			"product_variation_id": nullableInt(nullInt64Ptr(variationID)),
			"path":                 path,
			"is_primary":           isPrimary == 1,
			"sort_order":           sortOrder,
			"created_at":           renderJSONTime(createdAt),
			"updated_at":           renderJSONTime(updatedAt),
		}
		out = append(out, image)
	}
	return out, rows.Err()
}

// serializeVariation renders a ProductVariation with compositions.option loaded.
func serializeVariation(ctx context.Context, d queryExecer, variationID int64) (map[string]any, error) {
	var (
		id, productID        int64
		sku                  string
		price                sql.NullString
		weight               sql.NullInt64
		isActive             int
		sortOrder            int
		createdAt, updatedAt sql.NullTime
		deletedAt            sql.NullTime
	)
	err := d.QueryRowContext(ctx, `SELECT id, product_id, sku, CAST(price AS CHAR), weight_grams, is_active,
			sort_order, created_at, updated_at, deleted_at
		FROM product_variations WHERE id = ?`, variationID).
		Scan(&id, &productID, &sku, &price, &weight, &isActive, &sortOrder, &createdAt, &updatedAt, &deletedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}

	// compositions with option — ordered by composition id (Eloquent's natural order).
	rows, err := d.QueryContext(ctx, `SELECT c.id, c.product_variation_id, c.product_variation_attribute_id,
			c.product_variation_attribute_option_id, o.id, o.product_variation_attribute_id, o.value, o.sort_order,
			o.created_at, o.updated_at
		FROM product_variation_compositions c
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id = ? ORDER BY c.id`, variationID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var compositions []any
	var labelParts []string
	for rows.Next() {
		var (
			cid, cvid, caid, coid int64
			oid, oaid, osort      int64
			value                 string
			oCreated, oUpdated    sql.NullTime
		)
		if err := rows.Scan(&cid, &cvid, &caid, &coid, &oid, &oaid, &value, &osort, &oCreated, &oUpdated); err != nil {
			return nil, err
		}
		compositions = append(compositions, map[string]any{
			"id":                                    cid,
			"product_variation_id":                  cvid,
			"product_variation_attribute_id":        caid,
			"product_variation_attribute_option_id": coid,
			"option": map[string]any{
				"id":                             oid,
				"product_variation_attribute_id": oaid,
				"value":                          value,
				"sort_order":                     osort,
				"created_at":                     renderJSONTime(oCreated),
				"updated_at":                     renderJSONTime(oUpdated),
			},
		})
		labelParts = append(labelParts, value)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	out := map[string]any{
		"id":           id,
		"product_id":   productID,
		"sku":          sku,
		"price":        nil,
		"is_active":    isActive == 1,
		"sort_order":   sortOrder,
		"created_at":   renderJSONTime(createdAt),
		"updated_at":   renderJSONTime(updatedAt),
		"deleted_at":   renderJSONTimePtr(deletedAt),
		"compositions": compositions,
	}
	if price.Valid {
		out["price"] = price.String
	}
	if weight.Valid {
		out["weight_grams"] = weight.Int64
	} else {
		out["weight_grams"] = nil
	}
	// label(): the option values joined with " / " (this model's own separator).
	out["__label"] = strings.Join(labelParts, " / ")
	return out, nil
}

func renderJSONTime(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

func renderJSONTimePtr(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// jsonNullableString renders a *string as the value or JSON null.
func jsonNullableString(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}
