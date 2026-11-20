package crm

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

type AssistedReturnLine struct {
	Index       int
	OrderItemID int64
	Quantity    int64
	Restock     bool
}

type AssistedReturnProblem struct {
	Status int
	Code   string
	Fields map[string][]string
}

func (e *AssistedReturnProblem) Error() string { return e.Code }

// InitiateAssistedReturn ports AssistedReturnService::initiate and the existing ReturnService request path.
// Consent is serialized before quota reads; order-item locks and all return writes share one transaction.
func (s *Service) InitiateAssistedReturn(ctx context.Context, actor Actor, orderID, consentID int64,
	lines []AssistedReturnLine, reason string, evidence []byte, extension, ipAddress, userAgent string) (map[string]any, error) {
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		return nil, &AssistedReturnProblem{Status: 403, Code: "system.unauthorized_action"}
	}
	var orderAgent int64
	var crmCustomer sql.NullInt64
	query := `SELECT agent_id,crm_customer_id FROM orders WHERE id=?`
	args := []any{orderID}
	if actor.Role != "super_admin" {
		if actor.AgentID == nil {
			return nil, &AssistedReturnProblem{Status: 404, Code: "system.endpoint_not_found"}
		}
		query += ` AND agent_id=?`
		args = append(args, *actor.AgentID)
	}
	if err := s.database.QueryRowContext(ctx, query, args...).Scan(&orderAgent, &crmCustomer); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, &AssistedReturnProblem{Status: 404, Code: "system.endpoint_not_found"}
		}
		return nil, err
	}
	if !crmCustomer.Valid {
		return nil, &AssistedReturnProblem{Status: 422, Code: "crm.not_crm_order"}
	}
	var ownerID, customerAgent int64
	if err := s.database.QueryRowContext(ctx, `SELECT owner_user_id,agent_id FROM crm_customers WHERE id=?`, crmCustomer.Int64).Scan(&ownerID, &customerAgent); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, &AssistedReturnProblem{Status: 404, Code: "system.endpoint_not_found"}
		}
		return nil, err
	}
	if ownerID != actor.ID || actor.AgentID == nil || customerAgent != *actor.AgentID {
		return nil, &AssistedReturnProblem{Status: 403, Code: "system.unauthorized_action"}
	}
	if len(lines) == 0 {
		return nil, &AssistedReturnProblem{Status: 422, Code: "order.empty_items"}
	}

	var returnID int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var rootEvent sql.NullInt64
		var eventType, purpose string
		var rootCRM sql.NullInt64
		var rootOrder int64
		var rawScope sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT root_event_id,event_type,purpose,crm_customer_id,order_id,scope
			FROM consent_events WHERE id=? FOR UPDATE`, consentID).
			Scan(&rootEvent, &eventType, &purpose, &rootCRM, &rootOrder, &rawScope); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &AssistedReturnProblem{Status: 404, Code: "system.endpoint_not_found"}
			}
			return err
		}
		var latest string
		if err := tx.QueryRowContext(ctx, `SELECT event_type FROM consent_events WHERE id=? OR root_event_id=? ORDER BY id DESC LIMIT 1`, consentID, consentID).Scan(&latest); err != nil {
			return err
		}
		scope, err := decodeConsentScope(rawScope)
		if err != nil {
			return err
		}
		if rootEvent.Valid || eventType != "consent_captured" || !rootCRM.Valid || rootCRM.Int64 != crmCustomer.Int64 ||
			rootOrder != orderID || purpose != "assisted_return" || (latest != "consent_captured" && latest != "consent_reinstated") {
			return &AssistedReturnProblem{Status: 422, Code: "crm.consent_required"}
		}
		for _, line := range lines {
			granted := scope[line.OrderItemID]
			var used int64
			if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(ri.quantity_returned),0) FROM return_items ri
				JOIN returns r ON r.id=ri.return_id WHERE r.consent_event_id=? AND ri.order_item_id=? AND ri.status<>'rejected'`,
				consentID, line.OrderItemID).Scan(&used); err != nil {
				return err
			}
			if line.Quantity > granted-used {
				return &AssistedReturnProblem{Status: 422, Code: "crm.consent_scope_exceeded", Fields: map[string][]string{"items": {"crm.consent_scope_exceeded"}}}
			}
		}

		path := "returns/evidence/" + randomHex(20) + extension
		absolute := filepath.Join(media.Root, filepath.FromSlash(path))
		if err := os.MkdirAll(filepath.Dir(absolute), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(absolute, evidence, 0o644); err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO returns
			(order_id,requested_by,reason,evidence_path,status,total_refund_amount,consent_event_id,created_at,updated_at)
			VALUES (?,?,?,?,'requested',0,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, orderID, actor.ID, reason, path, consentID)
		if err != nil {
			return err
		}
		returnID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		var total float64
		for _, line := range lines {
			var status string
			var fulfilled, returned int64
			var unitPriceRaw string
			if err := tx.QueryRowContext(ctx, `SELECT status,fulfilled_quantity,returned_quantity,CAST(unit_price_snapshot AS CHAR)
				FROM order_items WHERE order_id=? AND id=? FOR UPDATE`, orderID, line.OrderItemID).
				Scan(&status, &fulfilled, &returned, &unitPriceRaw); err != nil {
				if errors.Is(err, sql.ErrNoRows) {
					return &AssistedReturnProblem{Status: 404, Code: "system.endpoint_not_found"}
				}
				return err
			}
			if status != "terkirim" {
				return &AssistedReturnProblem{Status: 422, Code: "return.item_not_delivered"}
			}
			if line.Quantity < 1 || line.Quantity > fulfilled-returned {
				return &AssistedReturnProblem{Status: 422, Code: "return.invalid_quantity"}
			}
			refund := money.Round2(parseAmount(unitPriceRaw) * float64(line.Quantity))
			total = money.Round2(total + refund)
			if _, err := tx.ExecContext(ctx, `INSERT INTO return_items
				(return_id,order_item_id,quantity_returned,refund_amount,restock,status,refund_status,created_at,updated_at)
				VALUES (?,?,?,?,?,'pending','not_required',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, returnID, line.OrderItemID, line.Quantity, money.String(refund), line.Restock); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE order_items SET returned_quantity=returned_quantity+?,status='pengembalian',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, line.Quantity, line.OrderItemID); err != nil {
				return err
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE returns SET total_refund_amount=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, money.String(total), returnID); err != nil {
			return err
		}
		properties, _ := json.Marshal(map[string]any{"order_id": orderID, "actor_role": actor.Role, "total_refund_amount": total})
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?, 'App\\Models\\ReturnRequest', ?, 'return.requested', ?, ?, NULLIF(?,''), NULLIF(?,''), UTC_TIMESTAMP(6))`, actor.ID, returnID, reason, string(properties), ipAddress, userAgent)
		return err
	})
	if err != nil {
		return nil, err
	}
	return s.loadAssistedReturn(ctx, returnID)
}

func decodeConsentScope(raw sql.NullString) (map[int64]int64, error) {
	if !raw.Valid || strings.TrimSpace(raw.String) == "" || raw.String == "null" {
		return map[int64]int64{}, nil
	}
	var entries []struct {
		OrderItemID int64 `json:"order_item_id"`
		Quantity    int64 `json:"quantity"`
	}
	if err := json.Unmarshal([]byte(raw.String), &entries); err != nil {
		return nil, fmt.Errorf("decode consent scope: %w", err)
	}
	scope := make(map[int64]int64, len(entries))
	for _, entry := range entries {
		if entry.OrderItemID <= 0 || entry.Quantity < 0 {
			return nil, fmt.Errorf("invalid consent scope row")
		}
		scope[entry.OrderItemID] = entry.Quantity
	}
	return scope, nil
}

func parseAmount(raw string) float64 {
	var value float64
	_, _ = fmt.Sscan(strings.TrimSpace(raw), &value)
	return value
}

func (s *Service) loadAssistedReturn(ctx context.Context, id int64) (map[string]any, error) {
	var orderID, requestedBy int64
	var orderNo, buyer, reason, status, requestedName string
	var evidence sql.NullString
	var reviewedAt sql.NullTime
	var reviewedBy sql.NullInt64
	var total, created string
	err := s.database.QueryRowContext(ctx, `SELECT r.order_id,r.requested_by,r.reason,r.evidence_path,r.status,
		CAST(r.total_refund_amount AS CHAR),CAST(r.created_at AS CHAR),r.reviewed_at,r.reviewed_by,o.order_no,
		COALESCE(u.name,o.buyer_name_snapshot),requester.name
		FROM returns r JOIN orders o ON o.id=r.order_id LEFT JOIN users u ON u.id=o.konsumen_id
		JOIN users requester ON requester.id=r.requested_by WHERE r.id=?`, id).
		Scan(&orderID, &requestedBy, &reason, &evidence, &status, &total, &created, &reviewedAt, &reviewedBy, &orderNo, &buyer, &requestedName)
	if err != nil {
		return nil, err
	}
	rows, err := s.database.QueryContext(ctx, `SELECT ri.id,ri.order_item_id,oi.product_name_snapshot,oi.variation_label_snapshot,
		oi.sku_snapshot,ri.quantity_returned,CAST(ri.refund_amount AS CHAR),ri.restock,ri.status,ri.refund_status,
		ri.condition_note,ri.quantity_received,ri.good_quantity,ri.damaged_quantity,ri.condition_status,ri.disposition_status,
		ri.inspected_at,ri.pickup_scheduled_for FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id
		WHERE ri.return_id=? ORDER BY ri.id`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]map[string]any, 0)
	for rows.Next() {
		var itemID, orderItemID int64
		var product, conditionStatus, disposition, refundStatus, itemStatus string
		var variation, sku, note sql.NullString
		var qty, received, good, damaged int64
		var refund string
		var restock bool
		var inspected, pickup sql.NullTime
		if err := rows.Scan(&itemID, &orderItemID, &product, &variation, &sku, &qty, &refund, &restock, &itemStatus, &refundStatus,
			&note, &received, &good, &damaged, &conditionStatus, &disposition, &inspected, &pickup); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"id": itemID, "order_item_id": orderItemID, "product_name": product,
			"variation_label": nullStringValue(variation), "sku": nullStringValue(sku), "quantity_returned": qty,
			"refund_amount": money.String(parseAmount(refund)), "restock": restock, "status": itemStatus, "refund_status": refundStatus,
			"condition_note": nullStringValue(note), "quantity_received": received, "good_quantity": good, "damaged_quantity": damaged,
			"condition_status": conditionStatus, "disposition_status": disposition, "inspected_at": timeValue(inspected),
			"pickup_scheduled_for": dateValue(pickup)})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	var reviewed, createdAt any
	if reviewedBy.Valid {
		var name string
		if err := s.database.QueryRowContext(ctx, `SELECT name FROM users WHERE id=?`, reviewedBy.Int64).Scan(&name); err != nil {
			return nil, err
		}
		reviewed = name
	}
	parsedCreated, err := parseDatabaseTime(created)
	if err != nil {
		return nil, err
	}
	createdAt = parsedCreated
	var evidenceURL any
	if evidence.Valid {
		evidenceURL = media.URL(evidence.String)
	}
	return map[string]any{"id": id, "order_id": orderID, "order_no": orderNo, "customer_name": buyer, "reason": reason,
		"evidence_url": evidenceURL, "status": status, "total_refund_amount": money.String(parseAmount(total)),
		"requested_by": requestedName, "reviewed_by": reviewed, "reviewed_at": reviewedAtValue(reviewedAt), "items": items, "created_at": createdAt}, nil
}

func nullStringValue(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}
func timeValue(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}
func dateValue(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.Format("2006-01-02")
}
func reviewedAtValue(v sql.NullTime) any { return timeValue(v) }
func parseDatabaseTime(raw string) (any, error) {
	for _, layout := range []string{"2006-01-02 15:04:05.000000", "2006-01-02 15:04:05"} {
		if t, err := time.ParseInLocation(layout, raw, time.UTC); err == nil {
			return t.Format("2006-01-02T15:04:05.000000Z"), nil
		}
	}
	return nil, fmt.Errorf("invalid database timestamp %q", raw)
}
