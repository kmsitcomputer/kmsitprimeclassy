package inventory

// Warehouse stock requests — the warehouse's two-party gate for increasing stock or adjusting a Sub location.
//
// Ported from WarehouseStockRequestService + WarehouseStockRequestController + WarehouseStockRequestPolicy. Two
// request types share one table:
//
//	addition       : Gudang asks to ADD stock into `transit` (a factory receipt) or `factory_plan`; Admin approves.
//	sub_adjustment : Gudang asks for a SIGNED delta on one Sub Location's physical stock; Admin approves.
//
// The invariants that matter:
//
//   - The requester must be `gudang`; the approver must be `admin`; both scoped to the SAME agent (branch). The
//     route role gate alone is not enough — the policy compares agent_id, so a forged id from another branch 404s.
//   - Approval is IDEMPOTENT: an already-approved request returns the current row instead of applying the stock
//     movement a second time, and the movement table is the durable evidence (the reference checks for an existing
//     `warehouse_stock_request_id` before moving anything).
//   - A negative Sub adjustment may never cut physical stock below the ACTIVE Sub reservations (SubStockService
//     ::assertPhysicalDecreaseAllowed) and may never make the row negative.
//   - A factory_plan change must keep `transit + plan >= reserved` — a plan reduction can never invalidate stock
//     already promised to an order.
//
// The row lock order is: the request row, then the target stock row. That matches the transfer path.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Request problems carry an API status; the handler renders them.
type RequestProblem struct {
	Status  int
	Message string
}

func (p *RequestProblem) Error() string { return p.Message }

func requestProblem(status int, message string) *RequestProblem {
	return &RequestProblem{Status: status, Message: message}
}

// ErrStockRequestNotFound is the reference's ModelNotFoundException for a row outside the actor's branch.
var ErrStockRequestNotFound = errors.New("stock request not found")

// StockRequest is one warehouse_stock_requests row.
type StockRequest struct {
	ID                 int64
	AgentID            int64
	RequestType        string
	ProductID          *int64
	ProductVariationID *int64
	TargetStockType    string
	SubLocationID      *int64
	Quantity           int
	Reference          *string
	Note               *string
	Status             string
	RequestedBy        int64
	ApprovedBy         *int64
	ApprovedAt         *time.Time
	RejectedBy         *int64
	RejectedAt         *time.Time
	RejectionReason    *string
	CreatedAt          time.Time
	UpdatedAt          time.Time

	// Rendered extras (not columns).
	ProductName     *string
	VariationLabel  *string
	SKU             *string
	ProductImageURL *string
	RequesterName   *string
	SubLocationCode *string
	SubLocationName *string
	CurrentStock    *int
	// LoadSubLocation mirrors which relations the reference eager-loaded FOR THIS CALL. The resource renders
	// `sub_location` only when the relation was loaded, so the same row serialises differently from create(),
	// approve() and show() — that asymmetry is the contract, not a bug:
	//   create(addition) no · create(sub_adjustment) yes · approve yes · reject yes · index yes · show no
	LoadSubLocation bool
}

// CreateStockRequestInput is the `addition` request shape.
type CreateStockRequestInput struct {
	ActorID         int64
	ActorRole       string
	ActorAgentID    *int64
	ProductID       *int64
	VariationID     *int64
	Quantity        int
	TargetStockType string
	Reference       *string
	Note            *string
}

// CreateSubAdjustmentInput is the `sub_adjustment` request shape.
type CreateSubAdjustmentInput struct {
	ActorID       int64
	ActorRole     string
	ActorAgentID  *int64
	ProductID     *int64
	VariationID   *int64
	SubLocationID int64
	Delta         int
	Reference     *string
	Note          *string
}

// CreateStockRequest is WarehouseStockRequestService::create.
func CreateStockRequest(ctx context.Context, d *db.DB, in CreateStockRequestInput) (*StockRequest, error) {
	if in.ActorRole != "gudang" || in.ActorAgentID == nil {
		return nil, requestProblem(403, "Hanya Gudang dengan network valid.")
	}
	if in.Quantity <= 0 {
		return nil, requestProblem(422, "Jumlah permintaan harus lebih besar dari nol.")
	}
	if in.TargetStockType != "transit" && in.TargetStockType != "factory_plan" {
		return nil, requestProblem(422, "Sumber stok tidak valid.")
	}
	if err := assertRequestTarget(ctx, d, in.ProductID, in.VariationID); err != nil {
		return nil, err
	}

	productID, variationID := normaliseTarget(in.ProductID, in.VariationID)
	res, err := d.ExecContext(ctx, `INSERT INTO warehouse_stock_requests
		(agent_id, request_type, product_id, product_variation_id, target_stock_type, sub_location_id,
		 quantity, reference, note, status, requested_by, created_at, updated_at)
		VALUES (?, 'addition', ?, ?, ?, NULL, ?, ?, ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		*in.ActorAgentID, nullableInt(productID), nullableInt(variationID), in.TargetStockType,
		in.Quantity, nullableStrPtr(in.Reference), nullableStrPtr(in.Note), in.ActorID)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return loadStockRequestFlagged(ctx, d, *in.ActorAgentID, id, false)
}

// CreateSubAdjustmentRequest is WarehouseStockRequestService::createSubAdjustment.
func CreateSubAdjustmentRequest(ctx context.Context, d *db.DB, in CreateSubAdjustmentInput) (*StockRequest, error) {
	if in.ActorRole != "gudang" || in.ActorAgentID == nil {
		return nil, requestProblem(403, "Hanya Gudang dengan network valid.")
	}
	if in.Delta == 0 {
		return nil, requestProblem(422, "Selisih penyesuaian tidak boleh nol.")
	}
	if err := assertRequestTarget(ctx, d, in.ProductID, in.VariationID); err != nil {
		return nil, err
	}

	var active int
	err := d.QueryRowContext(ctx, `SELECT is_active FROM warehouse_sub_locations
		WHERE id = ? AND agent_id = ?`, in.SubLocationID, *in.ActorAgentID).Scan(&active)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && active != 1) {
		return nil, requestProblem(422, "Sub location tidak aktif atau bukan milik network ini.")
	}
	if err != nil {
		return nil, err
	}

	productID, variationID := normaliseTarget(in.ProductID, in.VariationID)
	res, err := d.ExecContext(ctx, `INSERT INTO warehouse_stock_requests
		(agent_id, request_type, product_id, product_variation_id, target_stock_type, sub_location_id,
		 quantity, reference, note, status, requested_by, created_at, updated_at)
		VALUES (?, 'sub_adjustment', ?, ?, 'sub', ?, ?, ?, ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		*in.ActorAgentID, nullableInt(productID), nullableInt(variationID), in.SubLocationID,
		in.Delta, nullableStrPtr(in.Reference), nullableStrPtr(in.Note), in.ActorID)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return loadStockRequestFlagged(ctx, d, *in.ActorAgentID, id, true)
}

// ApproveStockRequest is WarehouseStockRequestService::approve.
//
// The lock order is request row → target stock row. An already-approved request returns the row untouched, so a
// retry can never move stock twice.
func ApproveStockRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64) (*StockRequest, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	request, err := lockStockRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if request.Status == "approved" {
		return loadStockRequestFlagged(ctx, d, agentID, requestID, true)
	}
	if request.Status != "pending" {
		return nil, requestProblem(422, "Permintaan sudah diproses.")
	}

	switch request.RequestType {
	case "sub_adjustment":
		if err := applySubAdjustment(ctx, tx, actorID, request); err != nil {
			return nil, err
		}
	case "addition":
		if request.TargetStockType != "transit" && request.TargetStockType != "factory_plan" {
			return nil, requestProblem(422, "Permintaan ini tidak dapat disetujui pada Phase 1.")
		}
		reference := "STOCK-REQUEST-" + itoa(request.ID)
		if request.Reference != nil && *request.Reference != "" {
			reference = *request.Reference
		}
		productID, variationID := normaliseTarget(request.ProductID, request.ProductVariationID)
		if request.TargetStockType == "transit" {
			if err := receiveFactoryStock(ctx, tx, actorID, agentID, productID, variationID, request.Quantity, reference, request.ID); err != nil {
				return nil, err
			}
		} else {
			if err := adjustFactoryPlan(ctx, tx, actorID, agentID, productID, variationID, request.Quantity, reference, request.ID); err != nil {
				return nil, err
			}
		}
	default:
		return nil, requestProblem(422, "Permintaan ini tidak dapat disetujui pada Phase 1.")
	}

	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stock_requests
		SET status = 'approved', approved_by = ?, approved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
		WHERE id = ? AND agent_id = ?`, actorID, requestID, agentID); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return loadStockRequestFlagged(ctx, d, agentID, requestID, true)
}

// RejectStockRequest is WarehouseStockRequestService::reject.
func RejectStockRequest(ctx context.Context, d *db.DB, actorID, agentID, requestID int64, reason string) (*StockRequest, error) {
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	request, err := lockStockRequest(ctx, tx, agentID, requestID)
	if err != nil {
		return nil, err
	}
	if request.Status == "rejected" {
		return loadStockRequestFlagged(ctx, d, agentID, requestID, true)
	}
	if request.Status != "pending" {
		return nil, requestProblem(422, "Permintaan sudah diproses.")
	}

	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stock_requests
		SET status = 'rejected', rejected_by = ?, rejected_at = UTC_TIMESTAMP(), rejection_reason = ?,
			updated_at = UTC_TIMESTAMP()
		WHERE id = ? AND agent_id = ?`, actorID, reason, requestID, agentID); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return loadStockRequestFlagged(ctx, d, agentID, requestID, true)
}

// ---- internals -------------------------------------------------------------------------------------

// lockStockRequest locks the request row, agent-scoped. A row from another branch is a 404 (the policy's own
// answer), never a 403 that would confirm the id exists.
func lockStockRequest(ctx context.Context, tx *sql.Tx, agentID, requestID int64) (*StockRequest, error) {
	var (
		r                       StockRequest
		productID, variationID  sql.NullInt64
		subLocationID           sql.NullInt64
		approvedBy, rejectedBy  sql.NullInt64
		reference, note, reason sql.NullString
		approvedAt, rejectedAt  sql.NullTime
	)
	err := tx.QueryRowContext(ctx, `SELECT id, agent_id, request_type, product_id, product_variation_id,
			target_stock_type, sub_location_id, quantity, reference, note, status, requested_by,
			approved_by, approved_at, rejected_by, rejected_at, rejection_reason, created_at, updated_at
		FROM warehouse_stock_requests WHERE id = ? AND agent_id = ? FOR UPDATE`, requestID, agentID).
		Scan(&r.ID, &r.AgentID, &r.RequestType, &productID, &variationID, &r.TargetStockType, &subLocationID,
			&r.Quantity, &reference, &note, &r.Status, &r.RequestedBy, &approvedBy, &approvedAt,
			&rejectedBy, &rejectedAt, &reason, &r.CreatedAt, &r.UpdatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		// ModelNotFoundException renders `messages.system.not_found`; the handler resolves the localised text.
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	r.ProductID = nullInt64Ptr(productID)
	r.ProductVariationID = nullInt64Ptr(variationID)
	r.SubLocationID = nullInt64Ptr(subLocationID)
	r.Reference = nullStringPtrLocal(reference)
	r.Note = nullStringPtrLocal(note)
	r.ApprovedBy = nullInt64Ptr(approvedBy)
	r.RejectedBy = nullInt64Ptr(rejectedBy)
	r.RejectionReason = nullStringPtrLocal(reason)
	if approvedAt.Valid {
		t := approvedAt.Time
		r.ApprovedAt = &t
	}
	if rejectedAt.Valid {
		t := rejectedAt.Time
		r.RejectedAt = &t
	}
	return &r, nil
}

// applySubAdjustment is WarehouseStockRequestService::applySubAdjustment.
func applySubAdjustment(ctx context.Context, tx *sql.Tx, actorID int64, request *StockRequest) error {
	if request.TargetStockType != "sub" || request.SubLocationID == nil {
		return requestProblem(422, "Permintaan Sub tidak valid.")
	}

	var active int
	err := tx.QueryRowContext(ctx, `SELECT is_active FROM warehouse_sub_locations
		WHERE id = ? AND agent_id = ?`, *request.SubLocationID, request.AgentID).Scan(&active)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && active != 1) {
		return requestProblem(422, "Sub location tidak aktif atau bukan milik network ini.")
	}
	if err != nil {
		return err
	}

	// Durable idempotency: an existing movement for this request means it was already applied.
	var existing int64
	err = tx.QueryRowContext(ctx, `SELECT id FROM stock_movements
		WHERE agent_id = ? AND warehouse_stock_request_id = ? LIMIT 1`, request.AgentID, request.ID).Scan(&existing)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	productID, variationID := normaliseTarget(request.ProductID, request.ProductVariationID)
	clause, args := subStockClause(productID, variationID)

	var (
		stockID int64
		onHand  int
	)
	err = tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = 'sub' AND sub_location_id = ? AND `+clause+` FOR UPDATE`,
		append([]any{request.AgentID, *request.SubLocationID}, args...)...).Scan(&stockID, &onHand)
	stockExists := true
	if errors.Is(err, sql.ErrNoRows) {
		stockExists = false
		onHand = 0
	} else if err != nil {
		return err
	}

	after := onHand + request.Quantity
	if after < 0 {
		return requestProblem(422, "Stok Sub tidak boleh negatif.")
	}
	if request.Quantity < 0 {
		// R-02: a Sub reduction may never cut physical below the active Sub reservations.
		target := Target{ProductID: productID, VariationID: variationID}
		reserved, rerr := ReservedSubStock(ctx, tx, *request.SubLocationID, target)
		if rerr != nil {
			return rerr
		}
		if onHand+request.Quantity < reserved {
			return requestProblem(422, "Pengurangan stok Sub akan berada di bawah reservasi aktif.")
		}
	}

	if !stockExists {
		res, ierr := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks
			(agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity, created_at, updated_at)
			VALUES (?, ?, ?, 'sub', ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			request.AgentID, nullableInt(productID), nullableInt(variationID), *request.SubLocationID)
		if ierr != nil {
			return ierr
		}
		if stockID, ierr = res.LastInsertId(); ierr != nil {
			return ierr
		}
	}

	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, after, stockID); err != nil {
		return err
	}

	note := fmt.Sprintf("before=%d;after=%d", onHand, after)
	_, err = tx.ExecContext(ctx, `INSERT INTO stock_movements
		(agent_id, product_id, product_variation_id, type, quantity, stock_type, sub_location_id,
		 reference_type, reference_id, note, warehouse_stock_request_id, created_by, created_at)
		VALUES (?, ?, ?, 'sub_adjustment', ?, 'sub', ?, 'App\\\\Models\\\\WarehouseStockRequest', ?, ?, ?, ?, UTC_TIMESTAMP())`,
		request.AgentID, nullableInt(productID), nullableInt(variationID), request.Quantity,
		*request.SubLocationID, request.ID, note, request.ID, actorID)
	return err
}

// receiveFactoryStock is WarehouseStockService::receiveFactoryStock for the approval path.
func receiveFactoryStock(ctx context.Context, tx *sql.Tx, actorID, agentID int64, productID, variationID *int64, quantity int, reference string, requestID int64) error {
	if quantity <= 0 {
		return requestProblem(422, "Jumlah penerimaan harus lebih besar dari nol.")
	}

	// Idempotency, same as the reference: an existing movement for this request returns without moving stock.
	var existingMovement int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM stock_movements
		WHERE agent_id = ? AND warehouse_stock_request_id = ? LIMIT 1`, agentID, requestID).Scan(&existingMovement)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	stockID, err := lockOrCreateBucket(ctx, tx, agentID, productID, variationID, "transit")
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = quantity + ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, quantity, stockID); err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO stock_movements
		(agent_id, product_id, product_variation_id, type, quantity, stock_type,
		 reference_type, reference_id, note, warehouse_stock_request_id, created_by, created_at)
		VALUES (?, ?, ?, 'factory_in', ?, 'transit', 'factory_receipt', ?, ?, ?, ?, UTC_TIMESTAMP())`,
		agentID, nullableInt(productID), nullableInt(variationID), quantity, stockID, reference, requestID, actorID)
	return err
}

// adjustFactoryPlan is WarehouseStockService::adjustFactoryPlan for the approval path.
//
// `fromApproval` is true here, which skips the "factory plan enabled" gate (an approval is the authority), but the
// commitment guard still applies: transit + plan must stay >= the reserved quantity.
func adjustFactoryPlan(ctx context.Context, tx *sql.Tx, actorID, agentID int64, productID, variationID *int64, delta int, reference string, requestID int64) error {
	if delta == 0 {
		return requestProblem(422, "Perubahan Plan Pabrik tidak boleh nol.")
	}

	var existingMovement int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM stock_movements
		WHERE agent_id = ? AND warehouse_stock_request_id = ? LIMIT 1`, agentID, requestID).Scan(&existingMovement)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}

	stockID, err := lockOrCreateBucket(ctx, tx, agentID, productID, variationID, "factory_plan")
	if err != nil {
		return err
	}
	var current int
	if err := tx.QueryRowContext(ctx, `SELECT quantity FROM warehouse_stocks WHERE id = ? FOR UPDATE`, stockID).
		Scan(&current); err != nil {
		return err
	}
	newQuantity := current + delta

	reserved, err := reservedForQuery(ctx, tx, agentID, Target{ProductID: productID, VariationID: variationID})
	if err != nil {
		return err
	}
	transit, err := transitQuantity(ctx, tx, agentID, productID, variationID)
	if err != nil {
		return err
	}
	if newQuantity < 0 || transit+newQuantity < reserved {
		return requestProblem(422, "Perubahan Plan Pabrik melampaui komitmen reservasi.")
	}

	if _, err := tx.ExecContext(ctx, `UPDATE warehouse_stocks SET quantity = ?, updated_at = UTC_TIMESTAMP()
		WHERE id = ?`, newQuantity, stockID); err != nil {
		return err
	}
	movementType := "factory_plan_in"
	if delta < 0 {
		movementType = "factory_plan_out"
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO stock_movements
		(agent_id, product_id, product_variation_id, type, quantity, stock_type,
		 reference_type, note, warehouse_stock_request_id, created_by, created_at)
		VALUES (?, ?, ?, ?, ?, 'factory_plan', 'factory_plan', ?, ?, ?, UTC_TIMESTAMP())`,
		agentID, nullableInt(productID), nullableInt(variationID), movementType, delta, reference, requestID, actorID)
	return err
}

// lockOrCreateBucket takes the row lock on one bucket, creating it at zero when absent (the CREATE is inside the
// caller's transaction, so a rollback removes it).
func lockOrCreateBucket(ctx context.Context, tx *sql.Tx, agentID int64, productID, variationID *int64, stockType string) (int64, error) {
	clause, args := targetClauseFor(productID, variationID)
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = ? AND `+clause+` FOR UPDATE`,
		append([]any{agentID, stockType}, args...)...).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		res, ierr := tx.ExecContext(ctx, `INSERT INTO warehouse_stocks
			(agent_id, product_id, product_variation_id, stock_type, quantity, created_at, updated_at)
			VALUES (?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			agentID, nullableInt(productID), nullableInt(variationID), stockType)
		if ierr != nil {
			return 0, ierr
		}
		return res.LastInsertId()
	}
	if err != nil {
		return 0, err
	}
	return id, nil
}

func targetClauseFor(productID, variationID *int64) (string, []any) {
	if variationID != nil {
		return "product_variation_id = ? AND product_id IS NULL", []any{*variationID}
	}
	return "product_id = ? AND product_variation_id IS NULL", []any{*productID}
}

func transitQuantity(ctx context.Context, tx *sql.Tx, agentID int64, productID, variationID *int64) (int, error) {
	clause, args := targetClauseFor(productID, variationID)
	var quantity int
	err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = 'transit' AND `+clause,
		append([]any{agentID}, args...)...).Scan(&quantity)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, nil
	}
	return quantity, err
}

// assertRequestTarget is WarehouseStockRequestService::assertTarget: exactly ONE of product/variation, the
// variation must exist, and a has_variations product must be ordered by variation.
func assertRequestTarget(ctx context.Context, d *db.DB, productID, variationID *int64) error {
	if (productID == nil) == (variationID == nil) {
		return requestProblem(422, "Pilih product atau variation.")
	}
	if variationID != nil {
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM product_variations WHERE id = ?)`, *variationID).
			Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return requestProblem(422, "Variation tidak valid.")
		}
		return nil
	}
	var hasVariations bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM products WHERE id = ? AND has_variations = 1)`,
		*productID).Scan(&hasVariations); err != nil {
		return err
	}
	if hasVariations {
		return requestProblem(422, "Produk bervarian harus memilih variation.")
	}
	return nil
}

// normaliseTarget enforces the product-XOR-variation invariant the schema also enforces.
func normaliseTarget(productID, variationID *int64) (*int64, *int64) {
	if variationID != nil {
		return nil, variationID
	}
	return productID, nil
}

// LoadStockRequest reads one request WITHOUT the sub-location relation (the `show` shape).
func LoadStockRequest(ctx context.Context, d *db.DB, agentID, requestID int64) (*StockRequest, error) {
	return loadStockRequestFlagged(ctx, d, agentID, requestID, false)
}

// loadStockRequestFlagged reads one request and records whether `sub_location` is renderable.
func loadStockRequestFlagged(ctx context.Context, d *db.DB, agentID, requestID int64, withSubLocation bool) (*StockRequest, error) {
	var (
		r                      StockRequest
		productID, variationID sql.NullInt64
		subLocationID          sql.NullInt64
		approvedBy, rejectedBy sql.NullInt64
		reference, note        sql.NullString
		reason                 sql.NullString
		approvedAt, rejectedAt sql.NullTime
		productName, sku       sql.NullString
		variationSKU           sql.NullString
		requesterName          sql.NullString
		subCode, subName       sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT r.id, r.agent_id, r.request_type, r.product_id, r.product_variation_id,
			r.target_stock_type, r.sub_location_id, r.quantity, r.reference, r.note, r.status, r.requested_by,
			r.approved_by, r.approved_at, r.rejected_by, r.rejected_at, r.rejection_reason,
			r.created_at, r.updated_at,
			p.name, p.sku, v.sku, u.name, l.code, l.name
		FROM warehouse_stock_requests r
		LEFT JOIN products p ON p.id = r.product_id
		LEFT JOIN product_variations v ON v.id = r.product_variation_id
		LEFT JOIN users u ON u.id = r.requested_by
		LEFT JOIN warehouse_sub_locations l ON l.id = r.sub_location_id
		WHERE r.id = ? AND r.agent_id = ?`, requestID, agentID).
		Scan(&r.ID, &r.AgentID, &r.RequestType, &productID, &variationID, &r.TargetStockType, &subLocationID,
			&r.Quantity, &reference, &note, &r.Status, &r.RequestedBy, &approvedBy, &approvedAt,
			&rejectedBy, &rejectedAt, &reason, &r.CreatedAt, &r.UpdatedAt,
			&productName, &sku, &variationSKU, &requesterName, &subCode, &subName)
	if errors.Is(err, sql.ErrNoRows) {
		// ModelNotFoundException renders `messages.system.not_found`; the handler resolves the localised text.
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	r.ProductID = nullInt64Ptr(productID)
	r.ProductVariationID = nullInt64Ptr(variationID)
	r.SubLocationID = nullInt64Ptr(subLocationID)
	r.Reference = nullStringPtrLocal(reference)
	r.Note = nullStringPtrLocal(note)
	r.ApprovedBy = nullInt64Ptr(approvedBy)
	r.RejectedBy = nullInt64Ptr(rejectedBy)
	r.RejectionReason = nullStringPtrLocal(reason)
	if approvedAt.Valid {
		t := approvedAt.Time
		r.ApprovedAt = &t
	}
	if rejectedAt.Valid {
		t := rejectedAt.Time
		r.RejectedAt = &t
	}
	r.ProductName = nullStringPtrLocal(productName)
	r.SKU = nullStringPtrLocal(sku)
	if variationSKU.Valid {
		r.SKU = nullStringPtrLocal(variationSKU)
	}
	r.RequesterName = nullStringPtrLocal(requesterName)
	r.SubLocationCode = nullStringPtrLocal(subCode)
	r.SubLocationName = nullStringPtrLocal(subName)
	r.LoadSubLocation = withSubLocation

	// Variation label: the joined attribute values, " / " separated (the resource's own separator).
	if variationID.Valid {
		label, lerr := variationLabelJoined(ctx, d, variationID.Int64)
		if lerr != nil {
			return nil, lerr
		}
		r.VariationLabel = label
	}
	// Primary image: is_primary first, else the first by sort_order.
	var path sql.NullString
	imageQuery := `SELECT path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1`
	var imageArg int64
	if productID.Valid {
		imageArg = productID.Int64
	} else if variationID.Valid {
		imageQuery = `SELECT path FROM product_images WHERE product_variation_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1`
		imageArg = variationID.Int64
	}
	if imageArg != 0 {
		if ierr := d.QueryRowContext(ctx, imageQuery, imageArg).Scan(&path); ierr != nil && !errors.Is(ierr, sql.ErrNoRows) {
			return nil, ierr
		}
	}
	if path.Valid {
		url := publicStorageURL(ctx, path.String)
		r.ProductImageURL = &url
	}

	return &r, nil
}

// AttachCurrentStock fills the `current_stock_quantity` attribute. ONLY the index and show controller actions do
// this in the reference, so create/approve/reject responses carry `current_stock: null` — reproducing that is part
// of the contract, not an oversight.
func AttachCurrentStock(ctx context.Context, d *db.DB, r *StockRequest) error {
	current, err := currentStockFor(ctx, d, r)
	if err != nil {
		return err
	}
	r.CurrentStock = &current
	return nil
}

// currentStockFor is the controller's currentStock(): the bucket quantity the request targets.
func currentStockFor(ctx context.Context, d *db.DB, r *StockRequest) (int, error) {
	query := `SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks
		WHERE agent_id = ? AND stock_type = ?`
	args := []any{r.AgentID, r.TargetStockType}
	if r.TargetStockType == "sub" {
		query += ` AND sub_location_id <=> ?`
		args = append(args, nullableInt(r.SubLocationID))
	}
	if r.ProductID != nil {
		query += ` AND product_id = ? AND product_variation_id IS NULL`
		args = append(args, *r.ProductID)
	} else if r.ProductVariationID != nil {
		query += ` AND product_variation_id = ? AND product_id IS NULL`
		args = append(args, *r.ProductVariationID)
	} else {
		return 0, nil
	}
	var quantity int
	if err := d.QueryRowContext(ctx, query, args...).Scan(&quantity); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return 0, nil
		}
		return 0, err
	}
	return quantity, nil
}

// variationLabelJoined renders a variation's attribute values joined with " / ", which is the separator the
// warehouse resource uses (the order resource uses ", " — both are preserved, they are different payloads).
func variationLabelJoined(ctx context.Context, d *db.DB, variationID int64) (*string, error) {
	rows, err := d.QueryContext(ctx, `SELECT o.value
		FROM product_variation_compositions c
		JOIN product_variation_attribute_options o ON o.id = c.product_variation_attribute_option_id
		WHERE c.product_variation_id = ?
		ORDER BY o.sort_order, o.id`, variationID)
	if err != nil {
		if db.IsMissingSchema(err) {
			return nil, nil
		}
		return nil, err
	}
	defer rows.Close()
	var parts []string
	for rows.Next() {
		var value string
		if err := rows.Scan(&value); err != nil {
			return nil, err
		}
		if strings.TrimSpace(value) != "" {
			parts = append(parts, value)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if len(parts) == 0 {
		return nil, nil
	}
	label := strings.Join(parts, " / ")
	return &label, nil
}

// publicStorageURL renders the public-disk URL exactly like Storage::disk('public')->url(), reusing the SAME
// asset helper every other resource uses (an absolute APP_URL + /storage base, or a relative path when unset).
func publicStorageURL(ctx context.Context, path string) string {
	return asset.URL(ctx, path)
}

// nullStringPtrLocal converts a nullable text column into a *string (a NULL stays NULL, never "").
func nullStringPtrLocal(v sql.NullString) *string {
	if !v.Valid {
		return nil
	}
	s := v.String
	return &s
}

// StockRequestFilter carries the list query filters WarehouseStockRequestController::index applies.
type StockRequestFilter struct {
	Scope           string // "mine" restricts to the caller's own requests
	Status          string
	RequestType     string
	SubLocationID   *int64
	TargetStockType string
	Search          string
	PerPage         int
	Page            int
}

// ListStockRequests is the index query. Ordering is `created_at DESC` (Laravel's latest()); ties are broken by id
// DESC so a page composition is deterministic — the reference leaves ties undefined, which cannot be reproduced.
func ListStockRequests(ctx context.Context, d *db.DB, actorID, agentID int64, f StockRequestFilter) ([]*StockRequest, int, error) {
	where := ` FROM warehouse_stock_requests r
		LEFT JOIN products p ON p.id = r.product_id
		LEFT JOIN product_variations v ON v.id = r.product_variation_id
		LEFT JOIN users u ON u.id = r.requested_by
		LEFT JOIN warehouse_sub_locations l ON l.id = r.sub_location_id
		WHERE r.agent_id = ?`
	args := []any{agentID}

	if f.Scope == "mine" {
		where += ` AND r.requested_by = ?`
		args = append(args, actorID)
	}
	if f.Status != "" {
		where += ` AND r.status = ?`
		args = append(args, f.Status)
	}
	if f.RequestType != "" {
		where += ` AND r.request_type = ?`
		args = append(args, f.RequestType)
	}
	if f.SubLocationID != nil {
		where += ` AND r.sub_location_id = ?`
		args = append(args, *f.SubLocationID)
	}
	if f.TargetStockType != "" {
		where += ` AND r.target_stock_type = ?`
		args = append(args, f.TargetStockType)
	}
	if f.Search != "" {
		like := "%" + f.Search + "%"
		where += ` AND (p.name LIKE ? OR p.sku LIKE ? OR v.sku LIKE ?)`
		args = append(args, like, like, like)
	}

	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*)`+where, args...).Scan(&total); err != nil {
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
	offset := (page - 1) * perPage

	rows, err := d.QueryContext(ctx, `SELECT r.id`+where+` ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?`,
		append(args, perPage, offset)...)
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

	out := make([]*StockRequest, 0, len(ids))
	for _, id := range ids {
		request, err := loadStockRequestFlagged(ctx, d, agentID, id, true)
		if err != nil {
			return nil, 0, err
		}
		if err := AttachCurrentStock(ctx, d, request); err != nil {
			return nil, 0, err
		}
		out = append(out, request)
	}
	return out, total, nil
}
