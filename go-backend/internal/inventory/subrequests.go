package inventory

// The Sub stock-request workflow — R-02's Transit ↔ Sub two-party chain.
//
//	replenish: Sales-Kurir-Sub requests → Admin approves → Gudang executes (Transit→Sub transfer + handover)
//	           → Sales-Kurir-Sub confirms receipt (closes the handover)
//	return:    Sales-Kurir-Sub requests → Admin approves → Gudang receives (Sub→Transit, handover auto-closed)
//
// Creating/approving/rejecting/cancelling NEVER touches stock; only Execute does, once (the locked request row's
// status is the idempotency guard). The physical move goes through the SAME executeApprovedTransfer machinery
// the warehouse transfer path uses, so it is movement-backed and audited identically.
//
// Lock order: the request row, then the Sub Location row (EXCLUSIVE — the exact counterpart of checkout's SHARED
// first lock), then the transfer execution's canonical bucket locks.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// SubStockRequestStatuses mirrors the enum.
var subStockRequestTerminal = map[string]bool{"executed": true, "received": true}

// SubRequest is one sub_stock_requests row with its rendered relations.
type SubRequest struct {
	ID              int64
	AgentID         int64
	SubLocationID   int64
	RequestNumber   *string
	Direction       string
	Status          string
	RequestedBy     int64
	ApprovedBy      *int64
	ApprovedAt      *time.Time
	RejectedBy      *int64
	RejectedAt      *time.Time
	RejectionReason *string
	CancelledAt     *time.Time
	StockTransferID *int64
	ExecutedBy      *int64
	ExecutedAt      *time.Time
	ReceivedBy      *int64
	ReceivedAt      *time.Time
	IdempotencyKey  *string
	Note            *string
	CreatedAt       time.Time
	UpdatedAt       time.Time

	Items            []SubRequestItem
	SubLocation      *map[string]any
	Requester        *map[string]any
	Transfer         *map[string]any
	TransferHandover *map[string]any
	// CreatedOnly marks the fresh-create serialisation: the Eloquent model returned by create() carries ONLY
	// the insert-time attributes, so the create response omits every other column (approved_by, rejection_reason,
	// ...). A fresh() re-read — every action response — carries them all. An idempotent REPLAY reads the existing
	// row, so it serialises fully too.
	CreatedOnly bool
}

// SubRequestItem is one sub_stock_request_items row plus its product/variation relation.
type SubRequestItem struct {
	ID          int64
	RequestID   int64
	ProductID   *int64
	VariationID *int64
	Quantity    int
	CreatedAt   time.Time
	UpdatedAt   time.Time
	Product     *map[string]any
	Variation   *map[string]any
}

// SubRequestInput is the create request.
type SubRequestInput struct {
	ActorID        int64
	ActorRole      string
	ActorAgentID   *int64
	Direction      string
	Items          []SubRequestItemLine
	Note           *string
	IdempotencyKey string
}

// SubRequestItemLine is one requested line.
type SubRequestItemLine struct {
	ProductID   *int64
	VariationID *int64
	Quantity    int
}

// CreateSubRequest is SubStockRequestService::create.
func CreateSubRequest(ctx context.Context, d *db.DB, in SubRequestInput) (*SubRequest, error) {
	if in.ActorRole != "sales-kurir-sub" || in.ActorAgentID == nil {
		return nil, requestProblem(403, "Hanya Sales-Kurir-Sub yang dapat membuat permintaan stok Sub.")
	}
	if in.Direction != "replenish" && in.Direction != "return" {
		return nil, requestProblem(422, "Arah permintaan tidak valid.")
	}

	// The caller's own active location — ownership derived server-side, never from the request.
	var locationID int64
	err := d.QueryRowContext(ctx, `SELECT id FROM warehouse_sub_locations
		WHERE owner_user_id = ? AND is_active = 1 ORDER BY id LIMIT 1`, in.ActorID).Scan(&locationID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, requestProblem(422, "Anda belum memiliki Sub Location aktif.")
	}
	if err != nil {
		return nil, err
	}

	normalized, err := normalizeSubItems(ctx, d, in.Items)
	if err != nil {
		return nil, err
	}
	if in.Direction == "replenish" {
		if err := assertReplenishable(ctx, d, normalized); err != nil {
			return nil, err
		}
	}

	// Idempotent replay: same requester + same key returns the existing request.
	if in.IdempotencyKey != "" {
		var existing int64
		err := d.QueryRowContext(ctx, `SELECT id FROM sub_stock_requests
			WHERE requested_by = ? AND idempotency_key = ?`, in.ActorID, in.IdempotencyKey).Scan(&existing)
		if err == nil {
			return LoadSubRequest(ctx, d, existing)
		}
		if !errors.Is(err, sql.ErrNoRows) {
			return nil, err
		}
	}

	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if in.Direction == "return" {
		for _, row := range normalized {
			sellable, serr := subSellableQuantity(ctx, tx, locationID, row)
			if serr != nil {
				return nil, serr
			}
			if row.Quantity > sellable {
				return nil, &RequestProblem{
					Status: 422, Message: "Jumlah retur melebihi stok Sub yang tersedia.",
					Fields: map[string]string{"quantity": "Melebihi stok Sub tersedia."},
				}
			}
		}
	}

	number, err := uniqueSubRequestNumber(ctx, tx)
	if err != nil {
		return nil, err
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO sub_stock_requests
		(agent_id, sub_location_id, request_number, direction, status, requested_by, idempotency_key, note,
		 created_at, updated_at)
		VALUES (?, ?, ?, ?, 'requested', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		*in.ActorAgentID, locationID, number, in.Direction, in.ActorID,
		emptyToNil(in.IdempotencyKey), nullableStrPtrNote(in.Note))
	if err != nil {
		// Two concurrent requests with the same key: the unique index rejected the loser.
		if isDuplicateKey(err) && in.IdempotencyKey != "" {
			var existing int64
			if ferr := d.QueryRowContext(ctx, `SELECT id FROM sub_stock_requests
				WHERE requested_by = ? AND idempotency_key = ?`, in.ActorID, in.IdempotencyKey).Scan(&existing); ferr == nil {
				return LoadSubRequest(ctx, d, existing)
			}
		}
		return nil, err
	}
	requestID, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	for _, row := range normalized {
		if _, err := tx.ExecContext(ctx, `INSERT INTO sub_stock_request_items
			(sub_stock_request_id, product_id, product_variation_id, quantity, created_at, updated_at)
			VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			requestID, nullableInt(row.ProductID), nullableInt(row.VariationID), row.Quantity); err != nil {
			return nil, err
		}
	}
	if err := writeSubRequestActivity(ctx, tx, in.ActorID, requestID, *in.ActorAgentID, in.Direction, "sub_stock_request.created"); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	created, err := LoadSubRequest(ctx, d, requestID)
	if err != nil {
		return nil, err
	}
	created.CreatedOnly = true
	return created, nil
}

// ApproveSubRequest is SubStockRequestService::approve.
func ApproveSubRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64) (*SubRequest, error) {
	if agentID == 0 {
		return nil, requestProblem(403, "Hanya Admin yang dapat menyetujui permintaan stok Sub.")
	}
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	locked, err := lockSubRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if locked.Status == "approved" {
		return LoadSubRequest(ctx, d, requestID)
	}
	if locked.Status != "requested" {
		return nil, requestProblem(422, "Permintaan ini tidak dapat disetujui.")
	}
	if _, err := tx.ExecContext(ctx, `UPDATE sub_stock_requests
		SET status = 'approved', approved_by = ?, approved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, actorID, requestID); err != nil {
		return nil, err
	}
	if err := writeSubRequestActivity(ctx, tx, actorID, requestID, agentID, locked.Direction, "sub_stock_request.approved"); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadSubRequest(ctx, d, requestID)
}

// RejectSubRequest is SubStockRequestService::reject.
func RejectSubRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64, reason string) (*SubRequest, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	locked, err := lockSubRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if locked.Status == "rejected" {
		return LoadSubRequest(ctx, d, requestID)
	}
	if locked.Status != "requested" {
		return nil, requestProblem(422, "Permintaan ini tidak dapat ditolak.")
	}
	if _, err := tx.ExecContext(ctx, `UPDATE sub_stock_requests
		SET status = 'rejected', rejected_by = ?, rejected_at = UTC_TIMESTAMP(), rejection_reason = ?,
			updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, actorID, reason, requestID); err != nil {
		return nil, err
	}
	if err := writeSubRequestActivity(ctx, tx, actorID, requestID, agentID, locked.Direction, "sub_stock_request.rejected"); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadSubRequest(ctx, d, requestID)
}

// CancelSubRequest is SubStockRequestService::cancel — only the requester, only while still `requested`.
func CancelSubRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64) (*SubRequest, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	locked, err := lockSubRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if locked.RequestedBy != actorID {
		return nil, requestProblem(403, "Hanya pemohon yang dapat membatalkan permintaan.")
	}
	if locked.Status == "cancelled" {
		return LoadSubRequest(ctx, d, requestID)
	}
	if locked.Status != "requested" {
		return nil, requestProblem(422, "Hanya permintaan yang belum disetujui yang dapat dibatalkan.")
	}
	if _, err := tx.ExecContext(ctx, `UPDATE sub_stock_requests
		SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, requestID); err != nil {
		return nil, err
	}
	if err := writeSubRequestActivity(ctx, tx, actorID, requestID, agentID, locked.Direction, "sub_stock_request.cancelled"); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadSubRequest(ctx, d, requestID)
}

// ExecuteSubRequest is SubStockRequestService::execute — the ONLY stock-moving step, idempotent.
func ExecuteSubRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64) (*SubRequest, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	locked, err := lockSubRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if subStockRequestTerminal[locked.Status] {
		return LoadSubRequest(ctx, d, requestID) // retry: nothing moves twice
	}
	if locked.Status != "approved" {
		return nil, requestProblem(422, "Permintaan harus disetujui Admin sebelum dieksekusi Gudang.")
	}

	// The Sub Location row, EXCLUSIVE, re-validated under the lock — the counterpart of checkout's shared lock.
	var (
		active int
		owner  sql.NullInt64
	)
	err = tx.QueryRowContext(ctx, `SELECT is_active, owner_user_id FROM warehouse_sub_locations
		WHERE id = ? AND agent_id = ? FOR UPDATE`, locked.SubLocationID, locked.AgentID).Scan(&active, &owner)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && (active != 1 || !owner.Valid || owner.Int64 != locked.RequestedBy)) {
		return nil, requestProblem(422, "Sub Location tidak lagi aktif atau bukan milik pemohon.")
	}
	if err != nil {
		return nil, err
	}

	items, err := loadSubRequestItemsLocked(ctx, tx, requestID)
	if err != nil {
		return nil, err
	}

	// The physical move — one transfer, movement-backed, exactly like the warehouse path.
	transferID, handoverID, err := executeForSubRequest(ctx, tx, actorID, locked, items)
	if err != nil {
		return nil, err
	}

	if _, err := tx.ExecContext(ctx, `UPDATE sub_stock_requests
		SET status = 'executed', executed_by = ?, executed_at = UTC_TIMESTAMP(), stock_transfer_id = ?,
			updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, actorID, transferID, requestID); err != nil {
		return nil, err
	}
	if err := writeSubRequestActivityWith(ctx, tx, actorID, requestID, locked.AgentID, locked.Direction,
		"sub_stock_request.executed", map[string]any{"stock_transfer_id": transferID}); err != nil {
		return nil, err
	}
	_ = handoverID
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadSubRequest(ctx, d, requestID)
}

// ReceiveSubRequest is SubStockRequestService::receive — the requester confirms a replenishment.
func ReceiveSubRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64) (*SubRequest, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	locked, err := lockSubRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if locked.RequestedBy != actorID {
		return nil, requestProblem(403, "Hanya pemohon yang dapat mengonfirmasi penerimaan.")
	}
	if locked.Direction != "replenish" {
		return nil, requestProblem(422, "Penerimaan hanya berlaku untuk pengisian stok Sub.")
	}
	if locked.Status == "received" {
		return LoadSubRequest(ctx, d, requestID)
	}
	if locked.Status != "executed" {
		return nil, requestProblem(422, "Barang belum diserahkan oleh Gudang.")
	}
	if locked.StockTransferID != nil {
		if _, err := tx.ExecContext(ctx, `UPDATE stock_handovers
			SET status = 'received', received_by = ?, received_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
			WHERE stock_transfer_id = ?`, actorID, *locked.StockTransferID); err != nil {
			return nil, err
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE sub_stock_requests
		SET status = 'received', received_by = ?, received_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, actorID, requestID); err != nil {
		return nil, err
	}
	if err := writeSubRequestActivity(ctx, tx, actorID, requestID, agentID, locked.Direction, "sub_stock_request.received"); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return LoadSubRequest(ctx, d, requestID)
}

// ListSubRequestsFilter carries the index filters.
type ListSubRequestsFilter struct {
	ViewerIsSub bool
	ViewerID    int64
	Status      string
	Direction   string
	PerPage     int
	Page        int
}

// ---- internals -------------------------------------------------------------------------------------

// lockSubRequest locks the request row, agent-scoped.
func lockSubRequest(ctx context.Context, tx *sql.Tx, agentID, requestID int64) (*SubRequest, error) {
	var (
		r                       SubRequest
		number, note, reason    sql.NullString
		approvedBy, rejectedBy  sql.NullInt64
		approvedAt, rejectedAt  sql.NullTime
		cancelledAt, executedAt sql.NullTime
		receivedBy, executedBy  sql.NullInt64
		receivedAt              sql.NullTime
		transferID              sql.NullInt64
		idempotency             sql.NullString
	)
	err := tx.QueryRowContext(ctx, `SELECT id, agent_id, sub_location_id, request_number, direction, status,
			requested_by, approved_by, approved_at, rejected_by, rejected_at, rejection_reason,
			cancelled_at, stock_transfer_id, executed_by, executed_at, received_by, received_at,
			idempotency_key, note, created_at, updated_at
		FROM sub_stock_requests WHERE id = ? AND agent_id = ? FOR UPDATE`, requestID, agentID).
		Scan(&r.ID, &r.AgentID, &r.SubLocationID, &number, &r.Direction, &r.Status,
			&r.RequestedBy, &approvedBy, &approvedAt, &rejectedBy, &rejectedAt, &reason,
			&cancelledAt, &transferID, &executedBy, &executedAt, &receivedBy, &receivedAt,
			&idempotency, &note, &r.CreatedAt, &r.UpdatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	r.RequestNumber = nullStringPtrLocal(number)
	r.Note = nullStringPtrLocal(note)
	r.RejectionReason = nullStringPtrLocal(reason)
	r.IdempotencyKey = nullStringPtrLocal(idempotency)
	r.ApprovedBy = nullInt64Ptr(approvedBy)
	r.RejectedBy = nullInt64Ptr(rejectedBy)
	r.StockTransferID = nullInt64Ptr(transferID)
	r.ExecutedBy = nullInt64Ptr(executedBy)
	r.ReceivedBy = nullInt64Ptr(receivedBy)
	if approvedAt.Valid {
		t := approvedAt.Time
		r.ApprovedAt = &t
	}
	if rejectedAt.Valid {
		t := rejectedAt.Time
		r.RejectedAt = &t
	}
	if cancelledAt.Valid {
		t := cancelledAt.Time
		r.CancelledAt = &t
	}
	if executedAt.Valid {
		t := executedAt.Time
		r.ExecutedAt = &t
	}
	if receivedAt.Valid {
		t := receivedAt.Time
		r.ReceivedAt = &t
	}
	return &r, nil
}

func loadSubRequestItemsLocked(ctx context.Context, tx *sql.Tx, requestID int64) ([]SubRequestItemLine, error) {
	rows, err := tx.QueryContext(ctx, `SELECT product_id, product_variation_id, quantity
		FROM sub_stock_request_items WHERE sub_stock_request_id = ? ORDER BY id`, requestID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []SubRequestItemLine
	for rows.Next() {
		var (
			line                   SubRequestItemLine
			productID, variationID sql.NullInt64
		)
		if err := rows.Scan(&productID, &variationID, &line.Quantity); err != nil {
			return nil, err
		}
		line.ProductID = nullInt64Ptr(productID)
		line.VariationID = nullInt64Ptr(variationID)
		out = append(out, line)
	}
	return out, rows.Err()
}

// executeForSubRequest is StockTransferService::executeForSubRequest: create the pending transfer, move the stock
// through the same movement-backed path, then adjust the handover's actor fields per direction.
func executeForSubRequest(ctx context.Context, tx *sql.Tx, gudangID int64, locked *SubRequest, items []SubRequestItemLine) (transferID, handoverID int64, err error) {
	replenish := locked.Direction == "replenish"

	sourceType, sourceSub := "sub", nullableInt(&locked.SubLocationID)
	destinationType, destinationSub := "transit", any(nil)
	if replenish {
		sourceType, sourceSub = "transit", nil
		destinationType, destinationSub = "sub", nullableInt(&locked.SubLocationID)
	}
	note := "Pengembalian stok Sub"
	if replenish {
		note = "Pengisian stok Sub"
	}

	transferNumber, nerr := uniqueTransferNumber(ctx, tx, "TRF")
	if nerr != nil {
		return 0, 0, nerr
	}
	res, err := tx.ExecContext(ctx, `INSERT INTO stock_transfers
		(agent_id, transfer_number, source_stock_type, source_sub_location_id,
		 destination_stock_type, destination_sub_location_id, status, reference, note, created_by,
		 created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		locked.AgentID, transferNumber, sourceType, sourceSub, destinationType, destinationSub,
		derefString(locked.RequestNumber), note, locked.RequestedBy)
	if err != nil {
		return 0, 0, err
	}
	transferID, err = res.LastInsertId()
	if err != nil {
		return 0, 0, err
	}
	for _, item := range items {
		if _, err := tx.ExecContext(ctx, `INSERT INTO stock_transfer_items
			(stock_transfer_id, product_id, product_variation_id, quantity, created_at, updated_at)
			VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			transferID, nullableInt(item.ProductID), nullableInt(item.VariationID), item.Quantity); err != nil {
			return 0, 0, err
		}
	}

	// The physical move: canonical bucket locks, movements, handover — reusing the transfer execution core.
	if err := executeApprovedTransferTx(ctx, tx, transferID, gudangID); err != nil {
		return 0, 0, err
	}

	if err := tx.QueryRowContext(ctx, `SELECT id FROM stock_handovers WHERE stock_transfer_id = ?`,
		transferID).Scan(&handoverID); err != nil {
		return 0, 0, err
	}
	if replenish {
		// Gudang hands the goods over; the Sub confirms receipt later.
		if _, err := tx.ExecContext(ctx, `UPDATE stock_handovers SET handed_over_by = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, gudangID, handoverID); err != nil {
			return 0, 0, err
		}
	} else {
		if _, err := tx.ExecContext(ctx, `UPDATE stock_handovers
			SET handed_over_by = ?, received_by = ?, status = 'received', received_at = UTC_TIMESTAMP(),
				updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, locked.RequestedBy, gudangID, handoverID); err != nil {
			return 0, 0, err
		}
	}
	return transferID, handoverID, nil
}

// normalizeSubItems is normalizeItems: exactly one target per line, positive quantity, no duplicates, and the
// target must exist (not soft-deleted).
func normalizeSubItems(ctx context.Context, d *db.DB, items []SubRequestItemLine) ([]SubRequestItemLine, error) {
	if len(items) == 0 {
		return nil, requestProblem(422, "Permintaan harus memiliki item.")
	}
	seen := map[string]bool{}
	for _, item := range items {
		if (item.ProductID == nil) == (item.VariationID == nil) || item.Quantity < 1 {
			return nil, requestProblem(422, "Setiap item harus memiliki tepat satu target dan jumlah positif.")
		}
		key := "p:" + itoa(derefInt64Value(item.ProductID))
		if item.VariationID != nil {
			key = "v:" + itoa(*item.VariationID)
		}
		if seen[key] {
			return nil, requestProblem(422, "Target item tidak boleh duplikat.")
		}
		seen[key] = true

		query := `SELECT EXISTS(SELECT 1 FROM products WHERE id = ? AND deleted_at IS NULL)`
		arg := item.ProductID
		if item.VariationID != nil {
			query = `SELECT EXISTS(SELECT 1 FROM product_variations WHERE id = ? AND deleted_at IS NULL)`
			arg = item.VariationID
		}
		var valid bool
		var qerr error
		if arg != nil {
			qerr = d.QueryRowContext(ctx, query, *arg).Scan(&valid)
		}
		if qerr != nil || !valid {
			return nil, requestProblem(422, "Product/variation tidak valid.")
		}
	}
	return items, nil
}

func assertReplenishable(ctx context.Context, d *db.DB, items []SubRequestItemLine) error {
	for _, row := range items {
		var eligible bool
		var err error
		if row.VariationID != nil {
			err = d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM product_variations v
				JOIN products p ON p.id = v.product_id
				WHERE v.id = ? AND v.is_active = 1 AND p.status = 'active' AND p.has_variations = 1)`,
				*row.VariationID).Scan(&eligible)
		} else if row.ProductID != nil {
			err = d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM products
				WHERE id = ? AND status = 'active' AND has_variations = 0)`, *row.ProductID).Scan(&eligible)
		}
		if err != nil {
			return err
		}
		if !eligible {
			return &RequestProblem{
				Status: 422, Message: "Produk/varian tidak valid atau tidak aktif untuk pengisian Sub.",
				Fields: map[string]string{"items": "Target tidak valid."},
			}
		}
	}
	return nil
}

// subSellableQuantity is SubStockService::sellable for the return-direction bound.
func subSellableQuantity(ctx context.Context, tx *sql.Tx, subLocationID int64, row SubRequestItemLine) (int, error) {
	clause, args := subStockClause(row.ProductID, row.VariationID)
	var physical int
	err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks
		WHERE sub_location_id = ? AND stock_type = 'sub' AND `+clause,
		append([]any{subLocationID}, args...)...).Scan(&physical)
	if err != nil {
		return 0, err
	}
	reserved, err := ReservedSubStock(ctx, tx, subLocationID, Target{ProductID: row.ProductID, VariationID: row.VariationID})
	if err != nil {
		return 0, err
	}
	sellable := physical - reserved
	if sellable < 0 {
		sellable = 0
	}
	return sellable, nil
}

func uniqueSubRequestNumber(ctx context.Context, tx *sql.Tx) (string, error) {
	for attempt := 0; attempt < 12; attempt++ {
		suffix := randomSuffix(5)
		number := "SSR-" + time.Now().UTC().Format("20060102150405") + "-" + strings.ToUpper(suffix)
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM sub_stock_requests WHERE request_number = ?)`,
			number).Scan(&exists); err != nil {
			return "", err
		}
		if !exists {
			return number, nil
		}
	}
	return "", fmt.Errorf("inventory: could not allocate a unique sub request number")
}

func writeSubRequestActivity(ctx context.Context, tx *sql.Tx, actorID, requestID, agentID int64, direction, event string) error {
	return writeSubRequestActivityWith(ctx, tx, actorID, requestID, agentID, direction, event, nil)
}

func writeSubRequestActivityWith(ctx context.Context, tx *sql.Tx, actorID, requestID, agentID int64, direction, event string, extra map[string]any) error {
	properties := map[string]any{"agent_id": agentID, "direction": direction}
	for key, value := range extra {
		properties[key] = value
	}
	raw, err := jsonMarshal(properties)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, properties, created_at)
		VALUES (?, 'App\\\\Models\\\\SubStockRequest', ?, ?, ?, UTC_TIMESTAMP())`,
		actorID, requestID, event, raw)
	return err
}

// LoadSubRequest reads one request with the relations the controller renders.
func LoadSubRequest(ctx context.Context, d *db.DB, requestID int64) (*SubRequest, error) {
	var (
		r                       SubRequest
		number, note, reason    sql.NullString
		approvedBy, rejectedBy  sql.NullInt64
		approvedAt, rejectedAt  sql.NullTime
		cancelledAt, executedAt sql.NullTime
		receivedBy, executedBy  sql.NullInt64
		receivedAt              sql.NullTime
		transferID              sql.NullInt64
		idempotency             sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT id, agent_id, sub_location_id, request_number, direction, status,
			requested_by, approved_by, approved_at, rejected_by, rejected_at, rejection_reason,
			cancelled_at, stock_transfer_id, executed_by, executed_at, received_by, received_at,
			idempotency_key, note, created_at, updated_at
		FROM sub_stock_requests WHERE id = ?`, requestID).
		Scan(&r.ID, &r.AgentID, &r.SubLocationID, &number, &r.Direction, &r.Status,
			&r.RequestedBy, &approvedBy, &approvedAt, &rejectedBy, &rejectedAt, &reason,
			&cancelledAt, &transferID, &executedBy, &executedAt, &receivedBy, &receivedAt,
			&idempotency, &note, &r.CreatedAt, &r.UpdatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	r.RequestNumber = nullStringPtrLocal(number)
	r.Note = nullStringPtrLocal(note)
	r.RejectionReason = nullStringPtrLocal(reason)
	r.IdempotencyKey = nullStringPtrLocal(idempotency)
	r.ApprovedBy = nullInt64Ptr(approvedBy)
	r.RejectedBy = nullInt64Ptr(rejectedBy)
	r.StockTransferID = nullInt64Ptr(transferID)
	r.ExecutedBy = nullInt64Ptr(executedBy)
	r.ReceivedBy = nullInt64Ptr(receivedBy)
	if approvedAt.Valid {
		t := approvedAt.Time
		r.ApprovedAt = &t
	}
	if rejectedAt.Valid {
		t := rejectedAt.Time
		r.RejectedAt = &t
	}
	if cancelledAt.Valid {
		t := cancelledAt.Time
		r.CancelledAt = &t
	}
	if executedAt.Valid {
		t := executedAt.Time
		r.ExecutedAt = &t
	}
	if receivedAt.Valid {
		t := receivedAt.Time
		r.ReceivedAt = &t
	}

	// items.product:id,name,sku + items.variation
	rows, err := d.QueryContext(ctx, `SELECT i.id, i.sub_stock_request_id, i.product_id, i.product_variation_id,
			i.quantity, i.created_at, i.updated_at
		FROM sub_stock_request_items i WHERE i.sub_stock_request_id = ? ORDER BY i.id`, requestID)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var (
			it                     SubRequestItem
			productID, variationID sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &it.RequestID, &productID, &variationID, &it.Quantity, &it.CreatedAt, &it.UpdatedAt); err != nil {
			rows.Close()
			return nil, err
		}
		it.ProductID = nullInt64Ptr(productID)
		it.VariationID = nullInt64Ptr(variationID)
		if productID.Valid {
			product, perr := serializeProductColumns(ctx, d, productID.Int64)
			if perr != nil {
				rows.Close()
				return nil, perr
			}
			it.Product = product
		}
		if variationID.Valid {
			variation, verr := serializeVariationColumns(ctx, d, variationID.Int64)
			if verr != nil {
				rows.Close()
				return nil, verr
			}
			it.Variation = variation
		}
		r.Items = append(r.Items, it)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return nil, err
	}

	// subLocation:id,code,name + requester:id,name
	if sub, err := loadTinyLocation(ctx, d, r.SubLocationID); err == nil && sub != nil {
		r.SubLocation = sub
	} else if err != nil {
		return nil, err
	}
	if requester, err := loadTinyUser(ctx, d, r.RequestedBy); err == nil && requester != nil {
		r.Requester = requester
	} else if err != nil {
		return nil, err
	}

	// transfer.handover
	if transferID.Valid {
		transfer, terr := serializeTransferForHandover(ctx, d, transferID.Int64)
		if terr != nil {
			return nil, terr
		}
		StripPrintHelpers(*transfer)
		r.Transfer = transfer
		var (
			hID               int64
			hNumber           sql.NullString
			hStatus           string
			hHanded           sql.NullInt64
			hGot              sql.NullInt64
			hHandedAt, hGotAt sql.NullTime
		)
		herr := d.QueryRowContext(ctx, `SELECT id, handover_number, status, handed_over_by, received_by,
			handed_over_at, received_at FROM stock_handovers WHERE stock_transfer_id = ?`, transferID.Int64).
			Scan(&hID, &hNumber, &hStatus, &hHanded, &hGot, &hHandedAt, &hGotAt)
		if herr == nil {
			handover := map[string]any{
				"id": hID, "stock_transfer_id": transferID.Int64,
				"agent_id": r.AgentID, "handover_number": nullableStringRow(hNumber),
				"handed_over_by": nullableInt(nullInt64Ptr(hHanded)), "received_by": nullableInt(nullInt64Ptr(hGot)),
				"status": hStatus, "handed_over_at": renderJSONTime(hHandedAt),
				"received_at": renderJSONTime(hGotAt),
			}
			r.TransferHandover = &handover
		} else if !errors.Is(herr, sql.ErrNoRows) {
			return nil, herr
		}
	}
	return &r, nil
}

// serializeProductColumns renders the `product:id,name,sku` projection the controller selects.
func serializeProductColumns(ctx context.Context, d *db.DB, productID int64) (*map[string]any, error) {
	var (
		name, sku sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT name, sku FROM products WHERE id = ?`, productID).Scan(&name, &sku)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{"id": productID, "name": nullableStringRow(name), "sku": nullableStringRow(sku)}
	return &out, nil
}

// serializeVariationColumns renders the full variation model (the controller's `items.variation` has no column
// projection, so every column serialises).
func serializeVariationColumns(ctx context.Context, d *db.DB, variationID int64) (*map[string]any, error) {
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
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{
		"id": id, "product_id": productID, "sku": sku, "price": nullableStringRow(price),
		"is_active": isActive == 1, "sort_order": sortOrder,
		"created_at": renderJSONTime(createdAt), "updated_at": renderJSONTime(updatedAt),
		"deleted_at": renderJSONTime(deletedAt),
	}
	if weight.Valid {
		out["weight_grams"] = weight.Int64
	} else {
		out["weight_grams"] = nil
	}
	return &out, nil
}

func loadTinyLocation(ctx context.Context, d *db.DB, locationID int64) (*map[string]any, error) {
	var (
		code, name sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT code, name FROM warehouse_sub_locations WHERE id = ?`, locationID).
		Scan(&code, &name)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{"id": locationID, "code": nullableStringRow(code), "name": nullableStringRow(name)}
	return &out, nil
}

func loadTinyUser(ctx context.Context, d *db.DB, userID int64) (*map[string]any, error) {
	var name sql.NullString
	err := d.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, userID).Scan(&name)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{"id": userID, "name": nullableStringRow(name)}
	return &out, nil
}

func derefInt64Value(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

func nullableStrPtrNote(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func emptyToNil(v string) any {
	if v == "" {
		return nil
	}
	return v
}

// ---- read-model helpers shared with the GET /sub-stock/requests surface ----

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
