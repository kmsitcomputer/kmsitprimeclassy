package orders

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

type ReceiptAllocation struct {
	ReceiptID int64
	Quantity  int64
}

type ReceiptAllocationActor struct {
	ID      int64
	Role    string
	AgentID int64
}

type ReceiptAllocationProblem struct {
	Status  int
	Message string
}

func (e *ReceiptAllocationProblem) Error() string { return e.Message }

type ReceiptAllocationService struct{ Database *db.DB }

func (s *ReceiptAllocationService) Record(ctx context.Context, returnItemID int64, actor ReceiptAllocationActor,
	allocations []ReceiptAllocation, note *string, ipAddress, userAgent string) ([]map[string]any, error) {
	var result []map[string]any
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var orderItemID, received int64
		var status string
		var inspected sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT order_item_id,status,inspected_at,quantity_received
			FROM return_items WHERE id=? FOR UPDATE`, returnItemID).Scan(&orderItemID, &status, &inspected, &received); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &ReceiptAllocationProblem{Status: 404, Message: "Endpoint tidak ditemukan."}
			}
			return err
		}
		var orderID int64
		if err := tx.QueryRowContext(ctx, `SELECT order_id FROM order_items WHERE id=? FOR UPDATE`, orderItemID).Scan(&orderID); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &ReceiptAllocationProblem{Status: 404, Message: "Endpoint tidak ditemukan."}
			}
			return err
		}
		var agentID int64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &ReceiptAllocationProblem{Status: 404, Message: "Endpoint tidak ditemukan."}
			}
			return err
		}
		if actor.Role != "super_admin" && (actor.Role != "admin" || actor.AgentID != agentID) {
			return &ReceiptAllocationProblem{Status: 403, Message: "Tidak berwenang mengalokasikan retur ini."}
		}
		if status != "approved" || !inspected.Valid || received < 1 {
			return &ReceiptAllocationProblem{Status: 422, Message: "Hanya retur fisik yang sudah diterima/diinspeksi yang dapat dialokasikan."}
		}

		normalized := make(map[int64]int64, len(allocations))
		var total int64
		for _, allocation := range allocations {
			if allocation.Quantity < 1 || allocation.ReceiptID < 1 {
				return &ReceiptAllocationProblem{Status: 422, Message: "Alokasi tidak valid."}
			}
			if _, exists := normalized[allocation.ReceiptID]; exists {
				return &ReceiptAllocationProblem{Status: 422, Message: "Alokasi tidak valid."}
			}
			normalized[allocation.ReceiptID] = allocation.Quantity
			if allocation.Quantity > received-total {
				return &ReceiptAllocationProblem{Status: 422, Message: "Total alokasi harus sama dengan jumlah retur fisik yang diterima."}
			}
			total += allocation.Quantity
		}
		if total != received {
			return &ReceiptAllocationProblem{Status: 422, Message: "Total alokasi harus sama dengan jumlah retur fisik yang diterima."}
		}

		rows, err := tx.QueryContext(ctx, `SELECT order_item_receipt_id,quantity FROM return_item_receipt_allocations
			WHERE return_item_id=? ORDER BY id`, returnItemID)
		if err != nil {
			return err
		}
		existing := make(map[int64]int64)
		for rows.Next() {
			var receiptID, quantity int64
			if err := rows.Scan(&receiptID, &quantity); err != nil {
				rows.Close()
				return err
			}
			existing[receiptID] = quantity
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return err
		}
		if err := rows.Close(); err != nil {
			return err
		}
		if len(existing) > 0 {
			if equalReceiptAllocations(existing, normalized) {
				result, err = loadReceiptAllocationResponse(ctx, tx, returnItemID)
				return err
			}
			return &ReceiptAllocationProblem{Status: 409, Message: "Retur ini sudah dialokasikan dengan data berbeda."}
		}

		receiptIDs := make([]int64, 0, len(normalized))
		for id := range normalized {
			receiptIDs = append(receiptIDs, id)
		}
		sort.Slice(receiptIDs, func(i, j int) bool { return receiptIDs[i] < receiptIDs[j] })
		for _, receiptID := range receiptIDs {
			var receiptQuantity int64
			if err := tx.QueryRowContext(ctx, `SELECT quantity FROM order_item_receipts WHERE id=? AND order_item_id=? FOR UPDATE`,
				receiptID, orderItemID).Scan(&receiptQuantity); err != nil {
				if errors.Is(err, sql.ErrNoRows) {
					return &ReceiptAllocationProblem{Status: 422, Message: "Receipt bukan milik item order ini."}
				}
				return err
			}
			allocationRows, err := tx.QueryContext(ctx, `SELECT quantity FROM return_item_receipt_allocations WHERE order_item_receipt_id=? FOR UPDATE`, receiptID)
			if err != nil {
				return err
			}
			var used int64
			for allocationRows.Next() {
				var quantity int64
				if err := allocationRows.Scan(&quantity); err != nil {
					allocationRows.Close()
					return err
				}
				used += quantity
			}
			if err := allocationRows.Err(); err != nil {
				allocationRows.Close()
				return err
			}
			if err := allocationRows.Close(); err != nil {
				return err
			}
			if used+normalized[receiptID] > receiptQuantity {
				return &ReceiptAllocationProblem{Status: 422, Message: "Alokasi melebihi kuantitas penerimaan."}
			}
		}

		properties := make(map[string]int64, len(normalized))
		for _, receiptID := range receiptIDs {
			quantity := normalized[receiptID]
			var noteValue any
			if note != nil && strings.TrimSpace(*note) != "" {
				noteValue = strings.TrimSpace(*note)
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO return_item_receipt_allocations
				(return_item_id,order_item_receipt_id,quantity,allocated_by,allocated_at,evidence_note,created_at,updated_at)
				VALUES (?,?,?,?,UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, returnItemID, receiptID, quantity, actor.ID, noteValue); err != nil {
				return err
			}
			properties[fmt.Sprint(receiptID)] = quantity
		}
		propertiesJSON, _ := json.Marshal(map[string]any{"return_item_id": returnItemID, "allocations": properties, "actor_role": actor.Role})
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs
			(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?, 'App\\Models\\Order', ?, 'return_item.receipt_allocated', NULL, ?, NULLIF(?,''), NULLIF(?,''), UTC_TIMESTAMP(6))`,
			actor.ID, orderID, string(propertiesJSON), ipAddress, userAgent); err != nil {
			return err
		}
		result, err = loadReceiptAllocationResponse(ctx, tx, returnItemID)
		return err
	})
	return result, err
}

func equalReceiptAllocations(a, b map[int64]int64) bool {
	if len(a) != len(b) {
		return false
	}
	for id, quantity := range a {
		if b[id] != quantity {
			return false
		}
	}
	return true
}

func loadReceiptAllocationResponse(ctx context.Context, tx *sql.Tx, returnItemID int64) ([]map[string]any, error) {
	rows, err := tx.QueryContext(ctx, `SELECT order_item_receipt_id,quantity FROM return_item_receipt_allocations
		WHERE return_item_id=? ORDER BY id`, returnItemID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	result := make([]map[string]any, 0)
	for rows.Next() {
		var receiptID, quantity int64
		if err := rows.Scan(&receiptID, &quantity); err != nil {
			return nil, err
		}
		result = append(result, map[string]any{"receipt_id": receiptID, "quantity": quantity})
	}
	return result, rows.Err()
}

func parseReceiptInteger(raw json.RawMessage) (int64, bool) {
	var value int64
	if err := json.Unmarshal(raw, &value); err == nil {
		return value, true
	}
	var text string
	if err := json.Unmarshal(raw, &text); err != nil || strings.TrimSpace(text) == "" {
		return 0, false
	}
	value, err := strconv.ParseInt(strings.TrimSpace(text), 10, 64)
	return value, err == nil
}
