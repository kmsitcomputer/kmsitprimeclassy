package inventory

import (
	"context"
	"database/sql"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// SubStockRequestFilter narrows the Sub stock-request list.
type SubStockRequestFilter struct {
	// RequestedBy, when non-zero, restricts the list to that user's own requests. The controller applies this
	// for the Sales-Kurir-Sub role only; every other allowed role sees the branch.
	RequestedBy int64
	Status      string
	Direction   string
	Page        int
	PerPage     int
}

// ListSubStockRequests is SubStockRequestController::index.
//
// It keeps the eager-loaded relations the controller declares (items.product:id,name,sku, items.variation,
// subLocation:id,code,name, requester:id,name, transfer.handover) because they are part of the payload.
func ListSubStockRequests(ctx context.Context, d *db.DB, agentID int64, f SubStockRequestFilter) ([]map[string]any, Page, error) {
	where := []string{"agent_id = ?"}
	args := []any{agentID}
	if f.RequestedBy != 0 {
		where = append(where, "requested_by = ?")
		args = append(args, f.RequestedBy)
	}
	if f.Status != "" {
		where = append(where, "status = ?")
		args = append(args, f.Status)
	}
	if f.Direction != "" {
		where = append(where, "direction = ?")
		args = append(args, f.Direction)
	}
	whereSQL := strings.Join(where, " AND ")

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM sub_stock_requests WHERE `+whereSQL, args...).Scan(&total); err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, Page{CurrentPage: 1, LastPage: 1}, nil
		}
		return nil, Page{}, err
	}
	lastPage := (total + f.PerPage - 1) / f.PerPage
	if lastPage < 1 {
		lastPage = 1
	}
	offset := (f.Page - 1) * f.PerPage

	rows, err := d.QueryContext(ctx, `SELECT id, agent_id, sub_location_id, request_number, direction, status,
			requested_by, idempotency_key, note, approved_by, CAST(approved_at AS CHAR), rejected_by,
			CAST(rejected_at AS CHAR), rejection_reason, CAST(cancelled_at AS CHAR), executed_by,
			CAST(executed_at AS CHAR), stock_transfer_id, received_by, CAST(received_at AS CHAR),
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM sub_stock_requests WHERE `+whereSQL+` ORDER BY created_at DESC LIMIT ? OFFSET ?`,
		append(append([]any{}, args...), f.PerPage, offset)...)
	if err != nil {
		return nil, Page{}, err
	}
	defer rows.Close()

	type reqRow struct {
		id, agentID          int64
		subLocationID        sql.NullInt64
		requestNumber        sql.NullString
		direction, status    string
		requestedBy          int64
		idempotencyKey       sql.NullString
		note                 sql.NullString
		approvedBy           sql.NullInt64
		approvedAt           sql.NullString
		rejectedBy           sql.NullInt64
		rejectedAt           sql.NullString
		rejectionReason      sql.NullString
		cancelledAt          sql.NullString
		executedBy           sql.NullInt64
		executedAt           sql.NullString
		stockTransferID      sql.NullInt64
		receivedBy           sql.NullInt64
		receivedAt           sql.NullString
		createdAt, updatedAt sql.NullString
	}
	var list []reqRow
	var ids []int64
	for rows.Next() {
		var r reqRow
		if err := rows.Scan(&r.id, &r.agentID, &r.subLocationID, &r.requestNumber, &r.direction, &r.status,
			&r.requestedBy, &r.idempotencyKey, &r.note, &r.approvedBy, &r.approvedAt, &r.rejectedBy,
			&r.rejectedAt, &r.rejectionReason, &r.cancelledAt, &r.executedBy, &r.executedAt,
			&r.stockTransferID, &r.receivedBy, &r.receivedAt, &r.createdAt, &r.updatedAt); err != nil {
			return nil, Page{}, err
		}
		list = append(list, r)
		ids = append(ids, r.id)
	}
	if err := rows.Err(); err != nil {
		return nil, Page{}, err
	}
	if len(ids) == 0 {
		return []map[string]any{}, Page{CurrentPage: f.Page, LastPage: lastPage, Total: total}, nil
	}

	items, err := subStockRequestItems(ctx, d, ids)
	if err != nil {
		return nil, Page{}, err
	}
	var locationIDs, requesterIDs []int64
	for _, r := range list {
		if id, ok := nullInt(r.subLocationID); ok {
			locationIDs = append(locationIDs, id)
		}
		requesterIDs = append(requesterIDs, r.requestedBy)
	}
	locations, err := subLocationBriefs(ctx, d, locationIDs)
	if err != nil {
		return nil, Page{}, err
	}
	requesters, err := userBriefs(ctx, d, requesterIDs)
	if err != nil {
		return nil, Page{}, err
	}

	out := make([]map[string]any, 0, len(list))
	for _, r := range list {
		var subLocation any
		if id, ok := nullInt(r.subLocationID); ok {
			if v, found := locations[id]; found {
				subLocation = v
			}
		}
		var requester any
		if v, found := requesters[r.requestedBy]; found {
			requester = v
		}
		out = append(out, map[string]any{
			"id":                r.id,
			"agent_id":          r.agentID,
			"sub_location_id":   nullIntPtr(r.subLocationID),
			"request_number":    nullStr(r.requestNumber),
			"direction":         r.direction,
			"status":            r.status,
			"requested_by":      r.requestedBy,
			"idempotency_key":   nullStr(r.idempotencyKey),
			"note":              nullStr(r.note),
			"approved_by":       nullIntPtr(r.approvedBy),
			"approved_at":       formatMySQLString(r.approvedAt),
			"rejected_by":       nullIntPtr(r.rejectedBy),
			"rejected_at":       formatMySQLString(r.rejectedAt),
			"rejection_reason":  nullStr(r.rejectionReason),
			"cancelled_at":      formatMySQLString(r.cancelledAt),
			"executed_by":       nullIntPtr(r.executedBy),
			"executed_at":       formatMySQLString(r.executedAt),
			"stock_transfer_id": nullIntPtr(r.stockTransferID),
			"received_by":       nullIntPtr(r.receivedBy),
			"received_at":       formatMySQLString(r.receivedAt),
			"created_at":        formatMySQLString(r.createdAt),
			"updated_at":        formatMySQLString(r.updatedAt),
			"items":             items[r.id],
			"sub_location":      subLocation,
			"requester":         requester,
			"transfer":          nil, // no transfer exists for a request that has not been executed
		})
	}
	return out, Page{CurrentPage: f.Page, LastPage: lastPage, Total: total}, nil
}

// subStockRequestItems loads items with their product (id,name,sku) and variation.
func subStockRequestItems(ctx context.Context, d *db.DB, requestIDs []int64) (map[int64][]map[string]any, error) {
	args := make([]any, 0, len(requestIDs))
	for _, id := range requestIDs {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, sub_stock_request_id, product_id, product_variation_id, quantity,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM sub_stock_request_items WHERE sub_stock_request_id IN (`+placeholders(len(requestIDs))+`)
		ORDER BY id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type itemRow struct {
		id, requestID        int64
		productID, varID     sql.NullInt64
		quantity             int
		createdAt, updatedAt sql.NullString
	}
	var list []itemRow
	var productIDs, variationIDs []int64
	for rows.Next() {
		var r itemRow
		if err := rows.Scan(&r.id, &r.requestID, &r.productID, &r.varID, &r.quantity, &r.createdAt, &r.updatedAt); err != nil {
			return nil, err
		}
		list = append(list, r)
		if pid, ok := nullInt(r.productID); ok {
			productIDs = append(productIDs, pid)
		}
		if vid, ok := nullInt(r.varID); ok {
			variationIDs = append(variationIDs, vid)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	productBriefs, err := productBriefs(ctx, d, productIDs)
	if err != nil {
		return nil, err
	}
	variationBriefs, err := variationBriefs(ctx, d, variationIDs)
	if err != nil {
		return nil, err
	}

	out := map[int64][]map[string]any{}
	for _, r := range list {
		var product, variation any
		if pid, ok := nullInt(r.productID); ok {
			if v, found := productBriefs[pid]; found {
				product = v
			}
		}
		if vid, ok := nullInt(r.varID); ok {
			if v, found := variationBriefs[vid]; found {
				variation = v
			}
		}
		out[r.requestID] = append(out[r.requestID], map[string]any{
			"id":                   r.id,
			"sub_stock_request_id": r.requestID,
			"product_id":           nullIntPtr(r.productID),
			"product_variation_id": nullIntPtr(r.varID),
			"quantity":             r.quantity,
			"created_at":           formatMySQLString(r.createdAt),
			"updated_at":           formatMySQLString(r.updatedAt),
			"product":              product,
			"variation":            variation,
		})
	}
	return out, nil
}

// productBriefs renders `products:id,name,sku` partial models.
func productBriefs(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	out := map[int64]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, name, sku FROM products WHERE id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		var name string
		var sku sql.NullString
		if err := rows.Scan(&id, &name, &sku); err != nil {
			return nil, err
		}
		out[id] = map[string]any{"id": id, "name": name, "sku": nullStr(sku)}
	}
	return out, rows.Err()
}

// variationBriefs renders full `product_variations` models (the controller loads the relation whole).
func variationBriefs(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	return loadVariationModelsSimple(ctx, d, ids)
}

// subLocationBriefs renders `warehouse_sub_locations:id,code,name`.
func subLocationBriefs(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	out := map[int64]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, code, name FROM warehouse_sub_locations WHERE id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		var code, name string
		if err := rows.Scan(&id, &code, &name); err != nil {
			return nil, err
		}
		out[id] = map[string]any{"id": id, "code": code, "name": name}
	}
	return out, rows.Err()
}

// userBriefs renders `users:id,name`.
func userBriefs(ctx context.Context, d *db.DB, ids []int64) (map[int64]map[string]any, error) {
	out := map[int64]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, name FROM users WHERE id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		var name string
		if err := rows.Scan(&id, &name); err != nil {
			return nil, err
		}
		out[id] = map[string]any{"id": id, "name": name}
	}
	return out, rows.Err()
}
