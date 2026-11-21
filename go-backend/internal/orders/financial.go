package orders

// Financial primitives shared by the fulfillment paths (quantity adjustment, cancellation, returns).
//
// Ported from:
//   - OrderFinancialSnapshotService (ensure / assertQuantityAdjustable / availableUnits / consumeUnits /
//     unitOwnerItemId / captureLines / allocate / checksum);
//   - RefundLedgerService::{createForwardObligation,createVerificationObligation} + RefundMetrics;
//   - OrderTotalCalculator::recalculate;
//   - PaymentService::{reconcileTotals,recalculatePaymentStatus,initiateAdditionalPayment};
//   - InvoiceIssuanceService::syncAfterChange.
//
// Every cents value in this file is an INTEGER (Money::cents) exactly like the reference: the largest-remainder
// allocation, the checksum and the refund ledger all compare cents, never floats. DECIMAL columns receive the
// canonical "123.45" text so a binary float can never leak a rounding artefact into a money column.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/whatsappnotify"
)

// Snapshot triggers, matching order_financial_snapshots.trigger.
const (
	triggerFirstHandover        = "first_handover"
	triggerReturnApproval       = "return_approval"
	triggerFulfillmentShortfall = "fulfillment_shortfall"
	triggerOrderCancellation    = "order_cancellation"
	triggerProvisionalLegacy    = "provisional_legacy_backfill"
)

// toCents is Money::cents.
func toCents(v float64) int64 { return int64(money.Round2(v * 100)) }

// amountOf is Money::amount — the DECIMAL text for a cents value (never a float).
func amountOf(cents int64) string {
	return money.String(float64(cents) / 100)
}

// snapshotUnitRow is one unit read/written from order_financial_snapshot_units.
type snapshotUnitRow struct {
	ID                int64
	OrderItemID       int64
	UnitIndex         int
	UnitGrossCents    int64
	AllocatedDiscount int64
	UnitNetCents      int64
}

// ensureSnapshot is OrderFinancialSnapshotService::ensure.
//
// It freezes EVERY billable unit (fulfilled_quantity > 0) with the integer-cent largest-remainder allocation of
// the effective voucher across them, and returns the snapshot id + provenance. The first caller wins: an existing
// snapshot is returned untouched (the basis is immutable once frozen).
func (s *Service) ensureSnapshot(ctx context.Context, tx *sql.Tx, orderID, actorID int64,
	trigger, provenance string) (int64, string, error) {

	var (
		existingID         int64
		existingProvenance string
	)
	err := tx.QueryRowContext(ctx, `SELECT id, provenance FROM order_financial_snapshots
		WHERE order_id = ? FOR UPDATE`, orderID).Scan(&existingID, &existingProvenance)
	if err == nil {
		return existingID, existingProvenance, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return 0, "", err
	}

	// captureLines: every item with fulfilled_quantity > 0, in id order, under the same lock.
	type line struct {
		itemID    int64
		quantity  int
		unitGross int64
	}
	rows, err := tx.QueryContext(ctx, `SELECT id, fulfilled_quantity, CAST(unit_price_snapshot AS CHAR)
		FROM order_items WHERE order_id = ? AND fulfilled_quantity > 0 ORDER BY id FOR UPDATE`, orderID)
	if err != nil {
		return 0, "", err
	}
	var lines []line
	for rows.Next() {
		var l line
		var price string
		if err := rows.Scan(&l.itemID, &l.quantity, &price); err != nil {
			rows.Close()
			return 0, "", err
		}
		l.unitGross = toCents(parseDecimalString(price))
		lines = append(lines, l)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, "", err
	}
	if len(lines) == 0 {
		return 0, "", &Problem{Status: 422, Message: "Order tidak memiliki unit yang dapat di-snapshot.",
			Errors: map[string]any{"code": "order_has_no_billable_units"}}
	}

	// Canonical subtotal law: subtotal = SUM(unit_price_snapshot * fulfilled_quantity), derived from the frozen
	// units themselves — never from a possibly stale orders.subtotal_amount.
	var units []snapshotUnit
	for _, l := range lines {
		for i := 1; i <= l.quantity; i++ {
			units = append(units, snapshotUnit{itemID: l.itemID, index: i, gross: l.unitGross})
		}
	}
	subtotal := int64(0)
	for _, u := range units {
		subtotal += u.gross
	}

	// Effective voucher over the frozen subtotal (Order::effectiveDiscountAmount).
	var discountRaw string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(discount_amount AS CHAR) FROM orders WHERE id = ?`, orderID).
		Scan(&discountRaw); err != nil {
		return 0, "", err
	}
	voucher := toCents(effectiveDiscount(parseDecimalString(discountRaw), float64(subtotal)/100))

	allocated := allocateLargestRemainder(units, voucher)
	checksum := snapshotChecksum(orderID, subtotal, voucher, units, allocated)

	// Metadata matches the reference: trigger + line_count + unit_count.
	metadata := map[string]any{
		"trigger":    trigger,
		"line_count": len(lines),
		"unit_count": len(units),
	}
	metadataJSON, err := json.Marshal(metadata)
	if err != nil {
		return 0, "", err
	}

	res, err := tx.ExecContext(ctx, `INSERT INTO order_financial_snapshots
		(order_id, snapshot_version, `+"`trigger`"+`, subtotal_basis, effective_voucher_basis,
		 voucher_source_type, voucher_source_id, allocation_method, allocation_checksum, frozen_at, frozen_by,
		 provenance, metadata, created_at, updated_at)
		VALUES (?, 1, ?, ?, ?,
		 (SELECT CASE WHEN voucher_id IS NULL THEN NULL ELSE 'voucher' END FROM orders WHERE id = ?),
		 (SELECT voucher_id FROM orders WHERE id = ?),
		 'largest_remainder_unit_v1', ?, UTC_TIMESTAMP(), ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, trigger, amountOf(subtotal), amountOf(voucher), orderID, orderID,
		checksum, actorID, provenance, string(metadataJSON))
	if err != nil {
		return 0, "", err
	}
	snapshotID, err := res.LastInsertId()
	if err != nil {
		return 0, "", err
	}

	// Per-unit rows and per-line bases.
	lineGross := map[int64]int64{}
	lineDiscount := map[int64]int64{}
	for _, l := range lines {
		lineGross[l.itemID] = 0
		lineDiscount[l.itemID] = 0
	}
	for i, u := range units {
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_financial_snapshot_units
			(snapshot_id, order_item_id, unit_index, unit_gross, allocated_discount, unit_net,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			snapshotID, u.itemID, u.index, amountOf(u.gross), amountOf(allocated[i]),
			amountOf(u.gross-allocated[i])); err != nil {
			return 0, "", err
		}
		lineGross[u.itemID] += u.gross
		lineDiscount[u.itemID] += allocated[i]
	}
	// The freeze itself is audited (RefundAudit 'snapshot.created', once per order via its event_key).
	if err := s.snapshotAudit(ctx, tx, orderID, snapshotID, actorID, trigger, checksum, subtotal,
		voucher, len(units)); err != nil {
		return 0, "", err
	}

	for _, l := range lines {
		if _, err := tx.ExecContext(ctx, `INSERT INTO order_item_financial_bases
			(order_id, order_item_id, snapshot_id, qty_basis, unit_gross_basis, line_gross_basis,
			 allocated_discount_total, line_net_basis, allocation_checksum, frozen_at, provenance,
			 created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			orderID, l.itemID, snapshotID, l.quantity, amountOf(l.unitGross), amountOf(lineGross[l.itemID]),
			amountOf(lineDiscount[l.itemID]), amountOf(lineGross[l.itemID]-lineDiscount[l.itemID]),
			checksum, provenance); err != nil {
			return 0, "", err
		}
	}
	return snapshotID, provenance, nil
}

// snapshotChecksum is OrderFinancialSnapshotService::checksum.
//
// The payload is a PHP associative array marshalled with json_encode: keys in INSERTION order (order_id,
// subtotal, voucher, units) and `units` as an array of 4-element ARRAYS — not objects. A Go map would sort the
// keys and produce a different digest, and a struct list would produce objects; both silently diverge from the
// frozen rows the reference verifies, so the encoding is spelled out here.
func snapshotChecksum(orderID, subtotal, voucher int64, units []snapshotUnit, allocated []int64) string {
	type payload struct {
		OrderID  int64     `json:"order_id"`
		Subtotal int64     `json:"subtotal"`
		Voucher  int64     `json:"voucher"`
		Units    [][]int64 `json:"units"`
	}
	triplets := make([][]int64, 0, len(units))
	for i, u := range units {
		triplets = append(triplets, []int64{u.itemID, int64(u.index), u.gross, allocated[i]})
	}
	raw, err := json.Marshal(payload{OrderID: orderID, Subtotal: subtotal, Voucher: voucher, Units: triplets})
	if err != nil {
		return ""
	}
	return sha256Hex(raw)
}

// assertQuantityAdjustable is OrderFinancialSnapshotService::assertQuantityAdjustable.
//
// D6 safe default: once the order-wide financial snapshot exists no quantity/voucher/discount/add-line change is
// allowed — EXCEPT while the snapshot is a pre-dispatch fulfillment-shortfall basis and the order is still
// `diproses` (the Package A/B reduce/increase cycle). The snapshot itself already absorbed the shortfall.
func (s *Service) assertQuantityAdjustable(ctx context.Context, tx *sql.Tx, orderID int64, orderStatus string) error {
	var trigger string
	err := tx.QueryRowContext(ctx, `SELECT `+"`trigger`"+` FROM order_financial_snapshots WHERE order_id = ?`,
		orderID).Scan(&trigger)
	if errors.Is(err, sql.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if trigger == triggerFulfillmentShortfall && orderStatus == "diproses" {
		return nil
	}
	return &Problem{
		Status:  422,
		Message: "Order sudah memiliki snapshot keuangan; perubahan quantity/voucher/diskon/tambah baris tidak diizinkan.",
		Errors:  map[string]any{"code": "order_financial_snapshot_frozen"},
	}
}

// unitOwnerItemID is OrderFinancialSnapshotService::unitOwnerItemId — the F01 lineage resolution: a split child
// created AFTER the freeze owns no units, so its units are the unconsumed ones of the nearest ancestor that does.
func unitOwnerItemID(ctx context.Context, tx *sql.Tx, snapshotID, itemID int64) (int64, error) {
	current := itemID
	for depth := 0; depth < 10 && current != 0; depth++ {
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM order_financial_snapshot_units
			WHERE snapshot_id = ? AND order_item_id = ?)`, snapshotID, current).Scan(&exists); err != nil {
			return 0, err
		}
		if exists {
			return current, nil
		}
		var parent sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT split_from_order_item_id FROM order_items WHERE id = ?`,
			current).Scan(&parent); err != nil {
			return 0, err
		}
		if !parent.Valid {
			break
		}
		current = parent.Int64
	}
	return itemID, nil
}

// availableUnits is OrderFinancialSnapshotService::availableUnits (0 for a provisional-legacy basis).
func (s *Service) availableUnits(ctx context.Context, tx *sql.Tx, snapshotID int64, provenance string, itemID int64) (int, error) {
	if provenance == "provisional_legacy" {
		return 0, nil
	}
	owner, err := unitOwnerItemID(ctx, tx, snapshotID, itemID)
	if err != nil {
		return 0, err
	}
	var count int
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM order_financial_snapshot_units
		WHERE snapshot_id = ? AND order_item_id = ? AND consumed_at IS NULL`,
		snapshotID, owner).Scan(&count); err != nil {
		return 0, err
	}
	return count, nil
}

// consumeUnits is OrderFinancialSnapshotService::consumeUnits — ascending unit_index, once per unit ever.
//
// Returns the reduction in CENTS (the sum of the consumed units' NET value) and a `frozen` flag. A
// provisional-legacy basis consumes nothing and reports zero, so the caller creates a needs_verification
// obligation instead of an auto-refund.
func (s *Service) consumeUnits(ctx context.Context, tx *sql.Tx, snapshotID int64, provenance string,
	itemID int64, quantity int, consumedByType string) (int64, bool, error) {

	if quantity < 1 {
		return 0, false, &Problem{Status: 422, Message: "Jumlah unit yang dikonsumsi tidak valid.",
			Errors: map[string]any{"code": "invalid_snapshot_consumption_quantity"}}
	}
	if provenance == "provisional_legacy" {
		return 0, false, nil
	}
	owner, err := unitOwnerItemID(ctx, tx, snapshotID, itemID)
	if err != nil {
		return 0, false, err
	}

	rows, err := tx.QueryContext(ctx, `SELECT id, CAST(unit_gross AS CHAR), CAST(allocated_discount AS CHAR),
			CAST(unit_net AS CHAR)
		FROM order_financial_snapshot_units
		WHERE snapshot_id = ? AND order_item_id = ? AND consumed_at IS NULL
		ORDER BY unit_index LIMIT ? FOR UPDATE`, snapshotID, owner, quantity)
	if err != nil {
		return 0, false, err
	}
	var units []snapshotUnitRow
	for rows.Next() {
		var u snapshotUnitRow
		var gross, discount, net string
		if err := rows.Scan(&u.ID, &gross, &discount, &net); err != nil {
			rows.Close()
			return 0, false, err
		}
		u.UnitGrossCents = toCents(parseDecimalString(gross))
		u.AllocatedDiscount = toCents(parseDecimalString(discount))
		u.UnitNetCents = toCents(parseDecimalString(net))
		units = append(units, u)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, false, err
	}
	if len(units) < quantity {
		return 0, false, &Problem{Status: 409, Message: "Unit snapshot tidak mencukupi untuk konsumsi ini.",
			Errors: map[string]any{"code": "snapshot_units_exhausted"}}
	}

	var maxSeq sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT MAX(consumption_seq) FROM order_financial_snapshot_units
		WHERE snapshot_id = ?`, snapshotID).Scan(&maxSeq); err != nil {
		return 0, false, err
	}
	sequence := 0
	if maxSeq.Valid {
		sequence = int(maxSeq.Int64)
	}

	reduction := int64(0)
	for _, u := range units {
		sequence++
		res, err := tx.ExecContext(ctx, `UPDATE order_financial_snapshot_units
			SET consumed_at = UTC_TIMESTAMP(), consumed_by_type = ?, consumed_by_id = ?,
				consumption_seq = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ? AND consumed_at IS NULL`, consumedByType, itemID, sequence, u.ID)
		if err != nil {
			return 0, false, err
		}
		if affected, _ := res.RowsAffected(); affected != 1 {
			return 0, false, &Problem{Status: 409, Message: "Terjadi konflik konsumsi unit; silakan ulangi.",
				Errors: map[string]any{"code": "snapshot_unit_consumption_conflict"}}
		}
		reduction += u.UnitNetCents
	}

	// The per-line basis totals move with the consumed units.
	consumedGross := int64(0)
	consumedDiscount := int64(0)
	for _, u := range units {
		consumedGross += u.UnitGrossCents
		consumedDiscount += u.AllocatedDiscount
	}
	if _, err := tx.ExecContext(ctx, `UPDATE order_item_financial_bases
		SET consumed_units_count = consumed_units_count + ?,
			consumed_gross_total = CAST(CAST(consumed_gross_total AS DECIMAL(14,2)) + ? AS DECIMAL(14,2)),
			consumed_discount_total = CAST(CAST(consumed_discount_total AS DECIMAL(14,2)) + ? AS DECIMAL(14,2)),
			consumed_net_total = CAST(CAST(consumed_net_total AS DECIMAL(14,2)) + ? AS DECIMAL(14,2)),
			updated_at = UTC_TIMESTAMP()
		WHERE snapshot_id = ? AND order_item_id = ?`,
		len(units), amountOf(consumedGross), amountOf(consumedDiscount), amountOf(reduction),
		snapshotID, owner); err != nil {
		return 0, false, err
	}
	return reduction, true, nil
}

// refundMetrics is RefundMetrics::forOrder, computed from the ledger in integer cents.
type refundMetrics struct {
	GrossCents       int64
	PaidCents        int64
	AppliedCents     int64
	ProvenCents      int64
	UnverifiedCents  int64
	ReservedCents    int64
	BillableCents    int64
	OutstandingCents int64
	OverpaymentCents int64
	AvailableCents   int64
}

// metricsForOrder is RefundMetrics::forOrder.
func (s *Service) metricsForOrder(ctx context.Context, tx *sql.Tx, orderID int64) (refundMetrics, error) {
	var gross, paid, total string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(gross_received_amount AS CHAR),
			CAST(paid_amount AS CHAR), CAST(total_amount AS CHAR)
		FROM orders WHERE id = ?`, orderID).Scan(&gross, &paid, &total); err != nil {
		return refundMetrics{}, err
	}
	m := refundMetrics{
		GrossCents: toCents(parseDecimalString(gross)),
		PaidCents:  toCents(parseDecimalString(paid)),
	}

	scalarCents := func(query string, args ...any) (int64, error) {
		var raw sql.NullString
		if err := tx.QueryRowContext(ctx, query, args...).Scan(&raw); err != nil {
			return 0, err
		}
		return toCents(parseDecimalString(raw.String)), nil
	}

	var err error
	if m.AppliedCents, err = scalarCents(`SELECT COALESCE(SUM(amount),0) FROM refund_payouts
		WHERE order_id = ? AND affects_paid_amount = 1`, orderID); err != nil {
		return m, err
	}
	if m.ProvenCents, err = scalarCents(`SELECT COALESCE(SUM(amount),0) FROM refund_payouts
		WHERE order_id = ? AND affects_paid_amount = 1 AND verification_status = 'proven'`, orderID); err != nil {
		return m, err
	}

	// Reserved = the unpaid remainder of every OPEN obligation.
	var reservedRaw sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(
			GREATEST(o.amount - COALESCE(p.paid_total, 0), 0)), 0)
		FROM refund_obligations o
		LEFT JOIN (SELECT obligation_id, SUM(amount) AS paid_total FROM refund_payouts GROUP BY obligation_id) p
			ON p.obligation_id = o.id
		WHERE o.order_id = ? AND o.status IN ('pending','approved','partially_paid','needs_verification')`,
		orderID).Scan(&reservedRaw); err != nil {
		return m, err
	}
	m.ReservedCents = toCents(parseDecimalString(reservedRaw.String))

	postInvoice, err := scalarCents(`SELECT COALESCE(SUM(amount),0) FROM order_sales_reductions
		WHERE order_id = ? AND applied_to_order_total = 0 AND reinstated_at IS NULL`, orderID)
	if err != nil {
		return m, err
	}

	m.BillableCents = toCents(parseDecimalString(total)) - postInvoice
	if m.BillableCents < 0 {
		m.BillableCents = 0
	}
	m.OutstandingCents = max64(0, m.BillableCents-m.PaidCents)
	m.OverpaymentCents = max64(0, m.PaidCents-m.BillableCents)
	m.UnverifiedCents = max64(0, m.AppliedCents-m.ProvenCents)
	m.AvailableCents = max64(0, m.PaidCents-m.BillableCents-m.ReservedCents)
	return m, nil
}

func max64(a, b int64) int64 {
	if a > b {
		return a
	}
	return b
}

// recordShortfallLedger is OrderFulfillmentService::recordShortfallLedger.
//
// The frozen units were already consumed by the caller, so this only books the sales reduction (marked
// applied_to_order_total=true because recalculate() ALREADY lowered the total) and the resulting refund
// entitlement. A provisional-legacy basis produces a needs_verification obligation, never an auto-refund.
func (s *Service) recordShortfallLedger(ctx context.Context, tx *sql.Tx, orderID, itemID, snapshotID int64,
	provenance string, reductionCents int64, fromQuantity, toQuantity int, adjustmentID *int64,
	actorID int64, reason string) error {

	if reductionCents <= 0 {
		return nil
	}

	reductionProvenance := "forward"
	if provenance == "provisional_legacy" {
		reductionProvenance = "provisional_legacy"
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO order_sales_reductions
		(order_id, source_type, source_id, amount, applied_to_order_total, provenance,
		 created_at, updated_at)
		VALUES (?, 'fulfillment_shortfall', ?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, itemID, amountOf(reductionCents), reductionProvenance); err != nil {
		return err
	}

	key := fmt.Sprintf("shortfall:%d:%d-%d", itemID, fromQuantity, toQuantity)
	if adjustmentID != nil {
		key = "adjustment:" + strconv.FormatInt(*adjustmentID, 10)
	}

	if provenance == "provisional_legacy" {
		return s.createVerificationObligation(ctx, tx, orderID, itemID, key, snapshotID, reductionCents,
			actorID, reason, "provisional_legacy")
	}
	return s.createForwardObligation(ctx, tx, orderID, itemID, key, snapshotID, reductionCents,
		actorID, reason, "fulfillment_shortfall", "fulfillment_reduction", "Shortfall exceeds available cash")
}

// createForwardObligation is RefundLedgerService::createForwardObligation (auto-approve variant used by a
// fulfillment reduction: the approving reduction IS the owner/admin sign-off, Keuangan only executes the payout).
func (s *Service) createForwardObligation(ctx context.Context, tx *sql.Tx, orderID, itemID int64,
	key string, snapshotID, eventValueCents, actorID int64, reason, sourceType, approvalSource,
	deferredReason string) error {

	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM refund_obligations WHERE obligation_key = ? FOR UPDATE`, key).
		Scan(&existing)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	var finalStatus sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT cancellation_financial_status FROM orders WHERE id = ? FOR UPDATE`,
		orderID).Scan(&finalStatus); err != nil {
		return err
	}
	if finalStatus.Valid && finalStatus.String == "finalized" {
		return &Problem{Status: 422,
			Message: "Order sudah difinalisasi secara finansial; obligation baru memerlukan koreksi manual Owner.",
			Errors:  map[string]any{"code": "order_financially_finalized"}}
	}

	metrics, err := s.metricsForOrder(ctx, tx, orderID)
	if err != nil {
		return err
	}
	amount := eventValueCents
	if amount > max64(0, metrics.AvailableCents) {
		amount = max64(0, metrics.AvailableCents)
	}
	deferred := eventValueCents - amount

	obligationNo, err := ulid()
	if err != nil {
		return err
	}
	metadata, err := json.Marshal(map[string]any{
		"event_value_cents":           eventValueCents,
		"available_cents_at_creation": metrics.AvailableCents,
	})
	if err != nil {
		return err
	}

	var deferredReasonValue any
	if deferred > 0 {
		deferredReasonValue = deferredReason
	}

	var snapshotValue any
	if snapshotID != 0 {
		snapshotValue = snapshotID
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO refund_obligations
		(obligation_no, order_id, source_type, source_id, obligation_key, amount, status, provenance,
		 reason, approved_at, approval_source, snapshot_id, deferred_amount, deferred_remaining,
		 deferred_reason, created_by, metadata, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, 'approved', 'forward', ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?,
			UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		"RO-"+obligationNo, orderID, sourceType, itemID, key, amountOf(amount), reason,
		approvalSource, snapshotValue, amountOf(deferred), amountOf(deferred),
		deferredReasonValue, actorID, string(metadata))
	if err != nil {
		return err
	}
	obligationID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	// WhatsAppLifecycleObserver::obligationCreated — a root obligation CREATED as
	// approved announces refund_approved (derived children announce nothing).
	if nerr := whatsappnotify.NotifyObligationByID(ctx, tx, whatsappnotify.EventRefundApproved, obligationID); nerr != nil {
		slog.WarnContext(ctx, "refund-approved notification dispatch failed", "obligation_id", obligationID, "error", nerr)
	}
	return s.refundAudit(ctx, tx, "obligation.created", orderID, obligationID, actorID, nil, map[string]any{
		"amount_cents":   amount,
		"deferred_cents": deferred,
		"source_type":    sourceType,
		"obligation_key": key,
	})
}

// createVerificationObligation is RefundLedgerService::createVerificationObligation: an unallocatable
// (provisional-legacy) basis produces a needs_verification obligation with no auto-approval.
func (s *Service) createVerificationObligation(ctx context.Context, tx *sql.Tx, orderID, itemID int64,
	key string, snapshotID, eventValueCents, actorID int64, reason, provenance string) error {

	var existing int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM refund_obligations WHERE obligation_key = ? FOR UPDATE`, key).
		Scan(&existing)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	var finalStatus sql.NullString
	if err := tx.QueryRowContext(ctx, `SELECT cancellation_financial_status FROM orders WHERE id = ? FOR UPDATE`,
		orderID).Scan(&finalStatus); err != nil {
		return err
	}
	if finalStatus.Valid && finalStatus.String == "finalized" {
		return &Problem{Status: 422,
			Message: "Order sudah difinalisasi secara finansial; obligation baru memerlukan koreksi manual Owner.",
			Errors:  map[string]any{"code": "order_financially_finalized"}}
	}

	obligationNo, err := ulid()
	if err != nil {
		return err
	}
	metadata, err := json.Marshal(map[string]any{
		"event_value_cents": eventValueCents,
		"requires_evidence": true,
	})
	if err != nil {
		return err
	}
	var snapshotValue any
	if snapshotID != 0 {
		snapshotValue = snapshotID
	}
	obligationReason := reason
	if obligationReason == "" {
		obligationReason = "Evidence ambiguous — reconciliation required"
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO refund_obligations
		(obligation_no, order_id, source_type, source_id, obligation_key, amount, status, provenance,
		 reason, snapshot_id, deferred_amount, deferred_remaining, created_by, metadata,
		 created_at, updated_at)
		VALUES (?, ?, 'fulfillment_shortfall', ?, ?, ?, 'needs_verification', ?, ?, ?, '0.00', '0.00', ?, ?,
			UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		"RO-"+obligationNo, orderID, itemID, key, amountOf(eventValueCents), provenance,
		obligationReason, snapshotValue, actorID, string(metadata))
	if err != nil {
		return err
	}
	obligationID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	return s.refundAudit(ctx, tx, "obligation.needs_verification", orderID, obligationID, actorID,
		obligationReason, map[string]any{"amount_cents": eventValueCents, "provenance": provenance})
}

// snapshotAudit is the `snapshot.created` RefundAudit record written by OrderFinancialSnapshotService.
// Its event_key (`snapshot:<order>`) makes it once-per-order: a second freeze attempt is a silent no-op.
func (s *Service) snapshotAudit(ctx context.Context, tx *sql.Tx, orderID, snapshotID, actorID int64,
	trigger, checksum string, subtotalCents, voucherCents int64, unitCount int) error {

	payload, err := json.Marshal(map[string]any{
		"snapshot_id":    snapshotID,
		"trigger":        trigger,
		"checksum":       checksum,
		"subtotal_cents": subtotalCents,
		"voucher_cents":  voucherCents,
		"unit_count":     unitCount,
	})
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO refund_ledger_events
		(order_id, event_type, event_key, actor_type, actor_id, reason, payload,
		 occurred_at, created_at, updated_at)
		VALUES (?, 'snapshot.created', ?, 'user', ?, NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
		ON DUPLICATE KEY UPDATE id = id`,
		orderID, fmt.Sprintf("snapshot:%d", orderID), actorID, string(payload))
	return err
}

// refundAudit is RefundAudit::record for the two events this package emits. `event_key` makes it once-only.
func (s *Service) refundAudit(ctx context.Context, tx *sql.Tx, eventType string, orderID, obligationID, actorID int64,
	reason any, payload map[string]any) error {

	raw, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	eventKey := fmt.Sprintf("obligation_created:%d", obligationID)
	_, err = tx.ExecContext(ctx, `INSERT INTO refund_ledger_events
		(order_id, obligation_id, event_type, event_key, actor_type, actor_id, reason, payload,
		 occurred_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, 'user', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, obligationID, eventType, eventKey, actorID, reason, string(raw))
	return err
}

// recalculateTotals is OrderTotalCalculator::recalculate.
//
// The ONLY place subtotal_amount/total_amount are recomputed after creation. Per-item value is
// unit_price_snapshot * fulfilled_quantity (never subtotal_snapshot, which stays the historical as-ordered
// value); shipping/admin/discount are untouched and the effective voucher is clamped to the live subtotal (A1-08).
func (s *Service) recalculateTotals(ctx context.Context, tx *sql.Tx, orderID int64) error {
	var subtotalRaw string
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(unit_price_snapshot * fulfilled_quantity), 0)
		FROM order_items WHERE order_id = ?`, orderID).Scan(&subtotalRaw); err != nil {
		return err
	}
	subtotal := money.Round2(parseDecimalString(subtotalRaw))

	var shipping, admin, discount string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(shipping_fee_amount AS CHAR),
			CAST(admin_fee_amount AS CHAR), CAST(discount_amount AS CHAR)
		FROM orders WHERE id = ? FOR UPDATE`, orderID).Scan(&shipping, &admin, &discount); err != nil {
		return err
	}
	effectiveVoucher := effectiveDiscount(parseDecimalString(discount), subtotal)
	total := money.Round2(subtotal - effectiveVoucher + parseDecimalString(shipping) + parseDecimalString(admin))
	if total < 0 {
		total = 0
	}
	_, err := tx.ExecContext(ctx, `UPDATE orders SET subtotal_amount = ?, total_amount = ?,
		updated_at = UTC_TIMESTAMP() WHERE id = ?`, money.String(subtotal), money.String(total), orderID)
	return err
}

// reconcileTotals is PaymentService::reconcileTotals: re-derive remaining_amount/payment_status against the
// CURRENT total without adding or removing money, then sync the invoice balance.
func (s *Service) reconcileTotals(ctx context.Context, tx *sql.Tx, orderID, actorID int64) error {
	var total, paid string
	if err := tx.QueryRowContext(ctx, `SELECT CAST(total_amount AS CHAR), CAST(paid_amount AS CHAR)
		FROM orders WHERE id = ? FOR UPDATE`, orderID).Scan(&total, &paid); err != nil {
		return err
	}
	totalValue := money.Round2(parseDecimalString(total))
	paidValue := money.Round2(parseDecimalString(paid))
	remaining := money.Round2(totalValue - paidValue)
	if remaining < 0 {
		remaining = 0
	}
	status := "unpaid"
	if remaining <= 0 {
		status = "paid"
	} else if paidValue > 0 {
		status = "partially_paid"
	}
	if _, err := tx.ExecContext(ctx, `UPDATE orders SET remaining_amount = ?, payment_status = ?,
		updated_at = UTC_TIMESTAMP() WHERE id = ?`, money.String(remaining), status, orderID); err != nil {
		return err
	}
	return s.syncInvoiceAfterChange(ctx, tx, orderID, actorID, "order_total_changed")
}

// syncInvoiceAfterChange is InvoiceIssuanceService::syncAfterChange: issue the ONE main invoice if the order is
// eligible and not yet issued, then append a balance snapshot only when the balance really changed.
func (s *Service) syncInvoiceAfterChange(ctx context.Context, tx *sql.Tx, orderID, actorID int64, trigger string) error {
	var (
		issuedAt      sql.NullTime
		orderNo       string
		status        string
		total, paid   string
		paymentStatus string
		dueDate       sql.NullString
		paymentType   sql.NullString
		gross         string
	)
	err := tx.QueryRowContext(ctx, `SELECT o.invoice_issued_at, o.order_no, o.status,
			CAST(o.total_amount AS CHAR), CAST(o.paid_amount AS CHAR), o.payment_status,
			CAST(o.due_date AS CHAR), pm.type, CAST(o.gross_received_amount AS CHAR)
		FROM orders o LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
		WHERE o.id = ? FOR UPDATE`, orderID).
		Scan(&issuedAt, &orderNo, &status, &total, &paid, &paymentStatus, &dueDate, &paymentType, &gross)
	if err != nil {
		return err
	}

	issuedNow := false
	if !issuedAt.Valid {
		eligible, err := s.invoiceEligible(ctx, tx, orderID, status, paymentType.String, paid, gross)
		if err != nil {
			return err
		}
		if !eligible {
			return nil
		}
		if _, err := tx.ExecContext(ctx, `UPDATE orders SET invoice_issued_at = UTC_TIMESTAMP(),
			updated_at = UTC_TIMESTAMP() WHERE id = ?`, orderID); err != nil {
			return err
		}
		issuedNow = true
		if paymentType.Valid && paymentType.String == "cod" {
			trigger = "order_created_cod"
		} else {
			trigger = "first_payment_verified"
		}
	}

	if err := s.snapshotInvoiceIfChanged(ctx, tx, orderID, orderNo, trigger, total, paid,
		paymentStatus, dueDate); err != nil {
		return err
	}

	if issuedNow {
		return logActivity(ctx, tx, actorID, orderID, "invoice.issued", map[string]any{
			"invoice_number": orderNo, "trigger": trigger,
		}, s.IP, s.UserAgent)
	}
	return nil
}

// invoiceEligible is InvoiceIssuanceService::isEligible.
func (s *Service) invoiceEligible(ctx context.Context, tx *sql.Tx, orderID int64, status, paymentType,
	paid, gross string) (bool, error) {

	if status == "dibatalkan" && parseDecimalString(paid) <= 0 {
		return false, nil
	}
	if paymentType == "cod" {
		return true, nil
	}
	if parseDecimalString(gross) > 0 || parseDecimalString(paid) > 0 {
		return true, nil
	}
	var verified bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(
			SELECT 1 FROM bank_transfer_verifications v
			JOIN payment_transactions t ON t.id = v.payment_transaction_id
			WHERE t.order_id = ? AND v.status = 'verified')`, orderID).Scan(&verified); err != nil {
		return false, err
	}
	return verified, nil
}

// EnsureInvoiceIssued ports InvoiceIssuanceService::ensureIssuedIfEligible. Legacy eligible invoices
// are issued lazily under the canonical Order lock; ineligible orders remain untouched.
func (s *Service) EnsureInvoiceIssued(ctx context.Context, orderID, actorID int64) (bool, error) {
	issued := false
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		order, err := s.lockOrderForPayment(ctx, tx, orderID)
		if err != nil {
			return err
		}
		var issuedAt sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT invoice_issued_at FROM orders WHERE id=?`, orderID).Scan(&issuedAt); err != nil {
			return err
		}
		if issuedAt.Valid {
			issued = true
			return nil
		}
		eligible, err := s.invoiceEligible(ctx, tx, orderID, order.Status, derefString(order.PaymentType),
			money.String(order.Paid), money.String(order.GrossReceived))
		if err != nil || !eligible {
			return err
		}
		if err := s.syncInvoiceAfterChange(ctx, tx, orderID, actorID, "invoice_issued"); err != nil {
			return err
		}
		issued = true
		return nil
	})
	return issued, err
}

// snapshotInvoiceIfChanged is InvoiceIssuanceService::snapshotIfChanged.
func (s *Service) snapshotInvoiceIfChanged(ctx context.Context, tx *sql.Tx, orderID int64, orderNo, trigger,
	total, paid, paymentStatus string, dueDate sql.NullString) error {

	var (
		lastID      int64
		lastVersion int
		lastTotal   string
		lastPaid    string
		lastStatus  string
		lastDue     sql.NullString
	)
	err := tx.QueryRowContext(ctx, `SELECT id, version, CAST(total_amount AS CHAR),
			CAST(verified_paid_amount AS CHAR), payment_status, CAST(due_date AS CHAR)
		FROM order_invoice_snapshots WHERE order_id = ? ORDER BY version DESC LIMIT 1`, orderID).
		Scan(&lastID, &lastVersion, &lastTotal, &lastPaid, &lastStatus, &lastDue)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	outstanding := money.Round2(parseDecimalString(total) - parseDecimalString(paid))
	if outstanding < 0 {
		outstanding = 0
	}

	if err == nil {
		sameDue := (!dueDate.Valid && !lastDue.Valid) ||
			(dueDate.Valid && lastDue.Valid && dueDate.String == lastDue.String)
		if money.Round2(parseDecimalString(lastTotal)) == money.Round2(parseDecimalString(total)) &&
			money.Round2(parseDecimalString(lastPaid)) == money.Round2(parseDecimalString(paid)) &&
			lastStatus == paymentStatus && sameDue {
			return nil
		}
	}

	version := lastVersion + 1
	var dueValue any
	if dueDate.Valid && dueDate.String != "" {
		dueValue = dueDate.String
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO order_invoice_snapshots
		(order_id, version, `+"`trigger`"+`, invoice_number, total_amount, verified_paid_amount, outstanding_amount,
		 payment_status, due_date, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())`,
		orderID, version, trigger, orderNo, money.String(parseDecimalString(total)),
		money.String(parseDecimalString(paid)), money.String(outstanding), paymentStatus, dueValue)
	return err
}

// initiateAdditionalPayment is PaymentService::initiateAdditionalPayment (a COD obligation has no gateway
// transaction: the cash is collected physically).
func (s *Service) initiateAdditionalPayment(ctx context.Context, tx *sql.Tx, orderID, agentID int64,
	methodCode string, amount float64) (int64, error) {

	if methodCode == "cod" {
		return 0, nil
	}
	var (
		methodID  int64
		configRaw sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT id FROM payment_methods WHERE code = 'bank_transfer'`).
		Scan(&methodID); err != nil {
		return 0, err
	}
	// The active environment + config for this branch, exactly like activeConfigFor(). A missing config is a 422
	// (the reference throws before creating anything).
	var environment string
	err := tx.QueryRowContext(ctx, `SELECT active_environment FROM agent_payment_method_settings
		WHERE agent_id = ? AND payment_method_id = ?`, agentID, methodID).Scan(&environment)
	if errors.Is(err, sql.ErrNoRows) {
		environment = "sandbox"
	} else if err != nil {
		return 0, err
	}
	if err := tx.QueryRowContext(ctx, `SELECT config FROM agent_payment_gateway_configs
		WHERE agent_id = ? AND payment_method_id = ? AND environment = ?`, agentID, methodID, environment).
		Scan(&configRaw); errors.Is(err, sql.ErrNoRows) {
		return 0, &Problem{Status: 422, Message: s.Messages("payment.method_not_configured", "name", "Bank Transfer"),
			Fields: map[string]string{"payment_method": s.Messages("payment.method_not_configured", "name", "Bank Transfer")}}
	} else if err != nil {
		return 0, err
	}

	var config map[string]any
	if configRaw.Valid {
		_ = json.Unmarshal([]byte(configRaw.String), &config)
	}
	instructions, _ := json.Marshal(map[string]any{
		"bank_name":      config["bank_name"],
		"account_name":   config["account_name"],
		"account_number": config["account_number"],
	})
	reference, err := randomAlphaNumeric(10)
	if err != nil {
		return 0, err
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO payment_transactions
		(order_id, payment_method_id, type, amount, status, gateway_reference, raw_payload,
		 created_at, updated_at)
		VALUES (?, ?, 'additional_payment', ?, 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		orderID, methodID, money.String(amount), "AP-"+upperASCII(reference), string(instructions))
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

func upperASCII(v string) string {
	out := []byte(v)
	for i := range out {
		if out[i] >= 'a' && out[i] <= 'z' {
			out[i] -= 'a' - 'A'
		}
	}
	return string(out)
}
