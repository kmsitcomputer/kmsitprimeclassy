package orders

// "PEMENUHAN KURANG" read model — Services/Order/FulfillmentShortageService::forOrder.
//
// Nothing here is stored or estimated: every figure derives from the authoritative demand lines
// (stock_request_items) and the audit rows Gudang wrote when it recorded a shortage. The Admin's decision on the
// remainder is read back from the audit events of the existing operations, so the view can never disagree with
// what those operations actually did.

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"sort"
	"strconv"
	"strings"
	"time"
)

type shortageLine struct {
	id, orderItemID         int64
	hasOrderItem            bool
	productID               int64
	variationID             *int64
	sku                     *string
	requested, fulfilled    int
	remaining               int
	productName, productSKU *string
}

type shortageLog struct {
	id          int64
	subjectID   int64
	event       string
	description *string
	properties  map[string]any
	createdAt   time.Time
	causer      any
}

func isoTime(t time.Time) string { return t.UTC().Format("2006-01-02T15:04:05+00:00") }

func (s *Service) causerSummary(ctx context.Context, id sql.NullInt64) any {
	if !id.Valid {
		return nil
	}
	var name sql.NullString
	if err := s.Database.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, id.Int64).Scan(&name); err != nil {
		return nil
	}
	return map[string]any{"id": id.Int64, "name": nullableStringRow(name)}
}

func (s *Service) activityLogs(ctx context.Context, subjectType string, ids []int64, events []string) ([]shortageLog, error) {
	if len(ids) == 0 {
		return nil, nil
	}
	args := []any{subjectType}
	idMarks := strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")
	for _, id := range ids {
		args = append(args, id)
	}
	eventMarks := strings.TrimSuffix(strings.Repeat("?,", len(events)), ",")
	for _, e := range events {
		args = append(args, e)
	}
	rows, err := s.Database.QueryContext(ctx, `SELECT id, subject_id, event, description, properties, created_at, causer_id
		FROM activity_logs WHERE subject_type = ? AND subject_id IN (`+idMarks+`) AND event IN (`+eventMarks+`)
		ORDER BY id`, args...)
	if err != nil {
		return nil, err
	}
	type raw struct {
		log    shortageLog
		causer sql.NullInt64
	}
	var collected []raw
	for rows.Next() {
		var (
			r      raw
			desc   sql.NullString
			props  sql.NullString
			causer sql.NullInt64
		)
		if err := rows.Scan(&r.log.id, &r.log.subjectID, &r.log.event, &desc, &props, &r.log.createdAt, &causer); err != nil {
			rows.Close()
			return nil, err
		}
		r.log.description = nullStringPtr(desc)
		if props.Valid && strings.TrimSpace(props.String) != "" {
			_ = json.Unmarshal([]byte(props.String), &r.log.properties)
		}
		r.causer = causer
		collected = append(collected, r)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	out := make([]shortageLog, 0, len(collected))
	for _, r := range collected {
		r.log.causer = s.causerSummary(ctx, r.causer)
		out = append(out, r.log)
	}
	return out, nil
}

func rootOfLine(parents map[int64]*int64, id int64) int64 {
	for hops := 0; hops < 50; hops++ {
		parent, ok := parents[id]
		if !ok || parent == nil {
			return id
		}
		if _, exists := parents[*parent]; !exists {
			return id
		}
		id = *parent
	}
	return id
}

// fulfillmentShortageForOrder returns nil when the order never had a Gudang-reported shortage.
func (s *Service) fulfillmentShortageForOrder(ctx context.Context, orderID int64) (any, error) {
	var (
		requestID     int64
		status        string
		coordinatorBy sql.NullInt64
		coordinatorAt sql.NullTime
		orderNo       string
	)
	err := s.Database.QueryRowContext(ctx, `SELECT sr.id, sr.status, sr.coordinator_requested_by,
			sr.coordinator_requested_at, o.order_no
		FROM stock_requests sr JOIN orders o ON o.id = sr.order_id WHERE sr.order_id = ? ORDER BY sr.id LIMIT 1`, orderID).
		Scan(&requestID, &status, &coordinatorBy, &coordinatorAt, &orderNo)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	itemRows, err := s.Database.QueryContext(ctx, `SELECT i.id, i.order_item_id, i.product_id, i.product_variation_id,
			i.sku_snapshot, i.requested_qty, i.fulfilled_qty, i.remaining_qty, p.name, p.sku, v.sku
		FROM stock_request_items i
		LEFT JOIN products p ON p.id = i.product_id
		LEFT JOIN product_variations v ON v.id = i.product_variation_id
		WHERE i.stock_request_id = ? ORDER BY i.id`, requestID)
	if err != nil {
		return nil, err
	}
	type lineExtra struct {
		line     shortageLine
		variaSKU sql.NullString
	}
	var lines []shortageLine
	for itemRows.Next() {
		var (
			l                    shortageLine
			orderItem            sql.NullInt64
			variation            sql.NullInt64
			sku, name, pSKU, vSK sql.NullString
		)
		if err := itemRows.Scan(&l.id, &orderItem, &l.productID, &variation, &sku, &l.requested, &l.fulfilled,
			&l.remaining, &name, &pSKU, &vSK); err != nil {
			itemRows.Close()
			return nil, err
		}
		l.hasOrderItem = orderItem.Valid
		l.orderItemID = orderItem.Int64
		l.variationID = nullIntPtr(variation)
		// `$head->sku_snapshot ?? $head->variation?->sku ?? $head->product?->sku`
		switch {
		case sku.Valid:
			l.sku = nullStringPtr(sku)
		case vSK.Valid:
			l.sku = nullStringPtr(vSK)
		default:
			l.sku = nullStringPtr(pSKU)
		}
		l.productName = nullStringPtr(name)
		lines = append(lines, l)
	}
	itemRows.Close()
	if err := itemRows.Err(); err != nil {
		return nil, err
	}
	if len(lines) == 0 {
		return nil, nil
	}

	lineIDs := make([]int64, len(lines))
	for i, l := range lines {
		lineIDs[i] = l.id
	}
	reportLogs, err := s.activityLogs(ctx, "App\\Models\\StockRequestItem", lineIDs, []string{"stock_request.fulfillment_shortage"})
	if err != nil {
		return nil, err
	}
	reports := map[int64]shortageLog{} // last report per demand line
	for _, l := range reportLogs {
		reports[l.subjectID] = l
	}
	if len(reports) == 0 {
		return nil, nil
	}

	// Quantity handed over that the Koordinator has not confirmed yet is not "short" any more.
	awaiting := map[int64]int{}
	awaitRows, err := s.Database.QueryContext(ctx, `SELECT pi.stock_request_item_id, SUM(pi.quantity)
		FROM stock_request_proposal_items pi JOIN stock_request_proposals p ON p.id = pi.stock_request_proposal_id
		WHERE p.stock_request_id = ? AND p.status = 'pending' GROUP BY pi.stock_request_item_id`, requestID)
	if err != nil {
		return nil, err
	}
	for awaitRows.Next() {
		var id int64
		var qty int
		if err := awaitRows.Scan(&id, &qty); err != nil {
			awaitRows.Close()
			return nil, err
		}
		awaiting[id] = qty
	}
	awaitRows.Close()
	if err := awaitRows.Err(); err != nil {
		return nil, err
	}

	// Order items: lineage + original quantity.
	parents := map[int64]*int64{}
	originals := map[int64]int{}
	var itemOrder []int64
	oiRows, err := s.Database.QueryContext(ctx, `SELECT id, split_from_order_item_id, original_quantity
		FROM order_items WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	for oiRows.Next() {
		var id int64
		var parent sql.NullInt64
		var original int
		if err := oiRows.Scan(&id, &parent, &original); err != nil {
			oiRows.Close()
			return nil, err
		}
		parents[id] = nullIntPtr(parent)
		originals[id] = original
		itemOrder = append(itemOrder, id)
	}
	oiRows.Close()
	if err := oiRows.Err(); err != nil {
		return nil, err
	}

	decisionLogs, err := s.activityLogs(ctx, "App\\Models\\OrderItem", itemOrder,
		[]string{"order_item.delivery_rescheduled", "order_item.undelivered_cancelled"})
	if err != nil {
		return nil, err
	}
	decisionsByItem := map[int64][]shortageLog{}
	for _, l := range decisionLogs {
		decisionsByItem[l.subjectID] = append(decisionsByItem[l.subjectID], l)
	}

	// Group the demand lines by the root order line (or by the line itself when it has no order item).
	var groupOrder []string
	groups := map[string][]shortageLine{}
	for _, l := range lines {
		key := "d" + strconv.FormatInt(l.id, 10)
		if l.hasOrderItem {
			key = strconv.FormatInt(rootOfLine(parents, l.orderItemID), 10)
		}
		if _, seen := groups[key]; !seen {
			groupOrder = append(groupOrder, key)
		}
		groups[key] = append(groups[key], l)
	}

	var rows []map[string]any
	openLines, orderedTotal, handedTotal, shortTotal := 0, 0, 0, 0
	lastReported := ""
	for _, key := range groupOrder {
		group := groups[key]
		var report *shortageLog
		for _, l := range group {
			if r, ok := reports[l.id]; ok {
				if report == nil || r.id > report.id {
					copyR := r
					report = &copyR
				}
			}
		}
		if report == nil {
			continue
		}
		var members []int64
		for _, id := range itemOrder {
			if strconv.FormatInt(rootOfLine(parents, id), 10) == key {
				members = append(members, id)
			}
		}
		shorts := make([]int, len(group))
		short := 0
		handed := 0
		awaitingTotal := 0
		for i, l := range group {
			value := l.remaining - awaiting[l.id]
			if value < 0 {
				value = 0
			}
			shorts[i] = value
			short += value
			handed += l.fulfilled
			awaitingTotal += awaiting[l.id]
		}
		remainder := 0
		for i := range group {
			if shorts[i] > shorts[remainder] {
				remainder = i
			}
		}
		var decision *shortageLog
		for _, id := range members {
			for _, l := range decisionsByItem[id] {
				if l.createdAt.Before(report.createdAt) {
					continue
				}
				if decision == nil || l.id > decision.id {
					copyL := l
					decision = &copyL
				}
			}
		}

		var rowStatus string
		switch {
		case short > 0 && decision != nil && decision.event == "order_item.delivery_rescheduled":
			rowStatus = "rescheduled"
		case short > 0:
			rowStatus = "pending_decision"
		case decision != nil && decision.event == "order_item.undelivered_cancelled":
			rowStatus = "cancelled"
		case decision != nil && decision.event == "order_item.delivery_rescheduled":
			rowStatus = "rescheduled"
		default:
			rowStatus = "resolved"
		}

		head := group[0]
		var variationLabel any
		if head.variationID != nil {
			label, err := s.variationLabel(ctx, *head.variationID)
			if err != nil {
				return nil, err
			}
			variationLabel = label
		}
		ordered := 0
		if len(members) == 0 {
			for _, l := range group {
				ordered += l.requested
			}
		} else {
			for _, id := range members {
				ordered += originals[id]
			}
		}
		var orderItemRef any
		if group[remainder].hasOrderItem {
			orderItemRef = group[remainder].orderItemID
		}
		var decisionOut any
		if decision != nil {
			action := "reschedule"
			if decision.event == "order_item.undelivered_cancelled" {
				action = "cancel_remainder"
			}
			decisionOut = map[string]any{
				"action":   action,
				"reason":   nullableString(decision.description),
				"quantity": decision.properties["quantity_cancelled"],
				"date":     decision.properties["to"],
				"by":       decision.causer,
				"at":       isoTime(decision.createdAt),
			}
		}
		reportedAt := isoTime(report.createdAt)
		if reportedAt > lastReported {
			lastReported = reportedAt
		}
		rows = append(rows, map[string]any{
			"stock_request_item_id": group[remainder].id,
			"order_item_id":         orderItemRef,
			"product_name":          nullableString(head.productName),
			"variation_label":       variationLabel,
			"sku":                   nullableString(head.sku),
			"unit":                  nil,
			"ordered_qty":           ordered,
			"handed_over_qty":       handed,
			"awaiting_receipt_qty":  awaitingTotal,
			"short_qty":             short,
			"reason":                nullableString(report.description),
			"reported_at":           reportedAt,
			"reported_by":           report.causer,
			"status":                rowStatus,
			"decision":              decisionOut,
		})
		if short > 0 {
			openLines++
		}
		orderedTotal += ordered
		handedTotal += handed
		shortTotal += short
	}
	if len(rows) == 0 {
		return nil, nil
	}

	proofs, err := s.shortageProofs(ctx, requestID)
	if err != nil {
		return nil, err
	}
	var coordinator any
	if coordinatorBy.Valid {
		coordinator = s.causerSummary(ctx, coordinatorBy)
	}
	var coordinatorRequestedAt any
	if coordinatorAt.Valid {
		coordinatorRequestedAt = isoTime(coordinatorAt.Time)
	}
	items := make([]any, len(rows))
	for i, r := range rows {
		items[i] = r
	}
	return map[string]any{
		"order_no":                 orderNo,
		"fulfillment_status":       status,
		"has_open_shortage":        openLines > 0,
		"open_lines":               openLines,
		"ordered_qty":              orderedTotal,
		"handed_over_qty":          handedTotal,
		"short_qty":                shortTotal,
		"last_reported_at":         lastReported,
		"coordinator":              coordinator,
		"coordinator_requested_at": coordinatorRequestedAt,
		"proofs":                   proofs,
		"items":                    items,
	}, nil
}

func (s *Service) shortageProofs(ctx context.Context, requestID int64) ([]any, error) {
	rows, err := s.Database.QueryContext(ctx, `SELECT p.id, p.status, p.created_at, p.received_at, p.requested_by, p.received_by,
			(SELECT m.path FROM media m WHERE m.mediable_type = ? AND m.mediable_id = p.id
			   AND m.collection = 'fulfillment_handover_proof' ORDER BY m.created_at DESC, m.id DESC LIMIT 1)
		FROM stock_request_proposals p WHERE p.stock_request_id = ? ORDER BY p.id`,
		"App\\Models\\StockRequestProposal", requestID)
	if err != nil {
		return nil, err
	}
	type proofRow struct {
		id                    int64
		status                string
		createdAt, receivedAt sql.NullTime
		requestedBy, received sql.NullInt64
		path                  sql.NullString
	}
	var collected []proofRow
	for rows.Next() {
		var r proofRow
		if err := rows.Scan(&r.id, &r.status, &r.createdAt, &r.receivedAt, &r.requestedBy, &r.received, &r.path); err != nil {
			rows.Close()
			return nil, err
		}
		collected = append(collected, r)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}
	sort.SliceStable(collected, func(i, j int) bool { return collected[i].id < collected[j].id })
	out := []any{}
	for _, r := range collected {
		if !r.path.Valid {
			continue
		}
		var at, receivedAt any
		if r.createdAt.Valid {
			at = isoTime(r.createdAt.Time)
		}
		if r.receivedAt.Valid {
			receivedAt = isoTime(r.receivedAt.Time)
		}
		out = append(out, map[string]any{
			"proposal_id": r.id,
			"url":         storageURL(r.path.String),
			"at":          at,
			"gudang":      s.causerSummary(ctx, r.requestedBy),
			"received_at": receivedAt,
			"koordinator": s.causerSummary(ctx, r.received),
			"status":      r.status,
		})
	}
	return out, nil
}

// variationLabel is ProductVariation::label — the option values joined with " / ".
func (s *Service) variationLabel(ctx context.Context, variationID int64) (any, error) {
	rows, err := s.Database.QueryContext(ctx, `SELECT o.value
		FROM product_variation_compositions c
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id = ? ORDER BY c.id`, variationID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var parts []string
	for rows.Next() {
		var value sql.NullString
		if err := rows.Scan(&value); err != nil {
			return nil, err
		}
		parts = append(parts, value.String)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return strings.Join(parts, " / "), nil
}
