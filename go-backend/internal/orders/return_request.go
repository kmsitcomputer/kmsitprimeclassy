package orders

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
)

type ReturnLine struct {
	Index       int
	OrderItemID int64
	Quantity    int64
	Restock     bool
}

type ReturnRequestProblem struct {
	Status int
	Code   string
}

func (e *ReturnRequestProblem) Error() string { return e.Code }

type ReturnRequestService struct{ Database *db.DB }

func (s *ReturnRequestService) Request(ctx context.Context, actorID int64, actorRole string, orderID int64,
	lines []ReturnLine, reason string, evidence []byte, extension, ipAddress, userAgent string) (map[string]any, error) {
	if len(lines) == 0 {
		return nil, &ReturnRequestProblem{Status: 422, Code: "order.empty_items"}
	}
	var returnID int64
	err := s.Database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		fileID, err := randomReturnHex(20)
		if err != nil {
			return err
		}
		path := "returns/evidence/" + fileID + extension
		absolute := filepath.Join(media.Root, filepath.FromSlash(path))
		if err := os.MkdirAll(filepath.Dir(absolute), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(absolute, evidence, 0o644); err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO returns
			(order_id,requested_by,reason,evidence_path,status,total_refund_amount,created_at,updated_at)
			VALUES (?,?,?,?,'requested',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, orderID, actorID, reason, path)
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
			var unitPrice string
			if err := tx.QueryRowContext(ctx, `SELECT status,fulfilled_quantity,returned_quantity,CAST(unit_price_snapshot AS CHAR)
				FROM order_items WHERE order_id=? AND id=? FOR UPDATE`, orderID, line.OrderItemID).
				Scan(&status, &fulfilled, &returned, &unitPrice); err != nil {
				if errors.Is(err, sql.ErrNoRows) {
					return &ReturnRequestProblem{Status: 404, Code: "system.endpoint_not_found"}
				}
				return err
			}
			if status != "terkirim" {
				return &ReturnRequestProblem{Status: 422, Code: "return.item_not_delivered"}
			}
			if line.Quantity < 1 || line.Quantity > fulfilled-returned {
				return &ReturnRequestProblem{Status: 422, Code: "return.invalid_quantity"}
			}
			refund := money.Round2(parseReturnAmount(unitPrice) * float64(line.Quantity))
			total += refund
			if _, err := tx.ExecContext(ctx, `INSERT INTO return_items
				(return_id,order_item_id,quantity_returned,refund_amount,restock,status,refund_status,created_at,updated_at)
				VALUES (?,?,?,?,?,'pending','not_required',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
				returnID, line.OrderItemID, line.Quantity, money.String(refund), line.Restock); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE order_items SET returned_quantity=returned_quantity+?,status='pengembalian',updated_at=UTC_TIMESTAMP(6) WHERE id=?`, line.Quantity, line.OrderItemID); err != nil {
				return err
			}
		}
		total = money.Round2(total)
		if _, err := tx.ExecContext(ctx, `UPDATE returns SET total_refund_amount=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, money.String(total), returnID); err != nil {
			return err
		}
		properties, _ := json.Marshal(map[string]any{"order_id": orderID, "actor_role": actorRole, "total_refund_amount": total})
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?, 'App\\Models\\ReturnRequest', ?, 'return.requested', ?, ?, NULLIF(?,''), NULLIF(?,''), UTC_TIMESTAMP(6))`,
			actorID, returnID, reason, string(properties), ipAddress, userAgent)
		return err
	})
	if err != nil {
		return nil, err
	}
	return loadReturnRequestResource(ctx, s.Database, returnID)
}

func loadReturnRequestResource(ctx context.Context, database *db.DB, id int64) (map[string]any, error) {
	var orderID, requestedBy int64
	var orderNo, reason, status, requesterName string
	var buyerName, evidence sql.NullString
	var total, created string
	var reviewedAt sql.NullTime
	var reviewedBy sql.NullInt64
	err := database.QueryRowContext(ctx, `SELECT r.order_id,r.requested_by,r.reason,r.evidence_path,r.status,
		CAST(r.total_refund_amount AS CHAR),CAST(r.created_at AS CHAR),r.reviewed_at,r.reviewed_by,o.order_no,
		COALESCE(u.name,o.buyer_name_snapshot),requester.name
		FROM returns r JOIN orders o ON o.id=r.order_id LEFT JOIN users u ON u.id=o.konsumen_id
		JOIN users requester ON requester.id=r.requested_by WHERE r.id=?`, id).
		Scan(&orderID, &requestedBy, &reason, &evidence, &status, &total, &created, &reviewedAt, &reviewedBy, &orderNo, &buyerName, &requesterName)
	if err != nil {
		return nil, err
	}
	rows, err := database.QueryContext(ctx, `SELECT ri.id,ri.order_item_id,oi.product_name_snapshot,oi.variation_label_snapshot,
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
		var product, itemStatus, refundStatus, conditionStatus, disposition string
		var variation, sku, note sql.NullString
		var quantity, received, good, damaged int64
		var refund string
		var restock bool
		var inspected, pickup sql.NullTime
		if err := rows.Scan(&itemID, &orderItemID, &product, &variation, &sku, &quantity, &refund, &restock,
			&itemStatus, &refundStatus, &note, &received, &good, &damaged, &conditionStatus, &disposition, &inspected, &pickup); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"id": itemID, "order_item_id": orderItemID, "product_name": product,
			"variation_label": nullableReturnString(variation), "sku": nullableReturnString(sku), "quantity_returned": quantity,
			"refund_amount": refund, "restock": restock, "status": itemStatus, "refund_status": refundStatus,
			"condition_note": nullableReturnString(note), "quantity_received": received, "good_quantity": good,
			"damaged_quantity": damaged, "condition_status": conditionStatus, "disposition_status": disposition,
			"inspected_at": nullableReturnTime(inspected), "pickup_scheduled_for": nullableReturnDate(pickup)})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	var reviewed, reviewedAtValue any
	if reviewedBy.Valid {
		var name string
		if err := database.QueryRowContext(ctx, `SELECT name FROM users WHERE id=?`, reviewedBy.Int64).Scan(&name); err != nil {
			return nil, err
		}
		reviewed = name
	}
	if reviewedAt.Valid {
		reviewedAtValue = reviewedAt.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
	}
	createdAt, err := parseReturnDatabaseTime(created)
	if err != nil {
		return nil, err
	}
	var evidenceURL, customer any
	if evidence.Valid {
		evidenceURL = media.URL(evidence.String)
	}
	if buyerName.Valid {
		customer = buyerName.String
	}
	return map[string]any{"id": id, "order_id": orderID, "order_no": orderNo, "customer_name": customer,
		"reason": reason, "evidence_url": evidenceURL, "status": status, "total_refund_amount": total,
		"requested_by": requesterName, "reviewed_by": reviewed, "reviewed_at": reviewedAtValue,
		"items": items, "created_at": createdAt}, nil
}

func randomReturnHex(size int) (string, error) {
	buf := make([]byte, size)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return hex.EncodeToString(buf), nil
}

func parseReturnAmount(raw string) float64 {
	var value float64
	_, _ = fmt.Sscan(strings.TrimSpace(raw), &value)
	return value
}

func nullableReturnString(value sql.NullString) any {
	if !value.Valid {
		return nil
	}
	return value.String
}

func nullableReturnTime(value sql.NullTime) any {
	if !value.Valid {
		return nil
	}
	return value.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

func nullableReturnDate(value sql.NullTime) any {
	if !value.Valid {
		return nil
	}
	return value.Time.Format("2006-01-02")
}

func parseReturnDatabaseTime(raw string) (string, error) {
	for _, layout := range []string{"2006-01-02 15:04:05.000000", "2006-01-02 15:04:05"} {
		if value, err := time.ParseInLocation(layout, raw, time.UTC); err == nil {
			return value.Format("2006-01-02T15:04:05.000000Z"), nil
		}
	}
	return "", fmt.Errorf("invalid return created_at %q", raw)
}
