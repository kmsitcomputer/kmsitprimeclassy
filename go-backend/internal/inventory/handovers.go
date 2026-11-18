package inventory

// Handover read/print surface — the two routes over stock_handovers rows the transfer approval already writes.
//
//	GET /warehouse/handovers/{handover}       → JSON (the model with transfer.items.product/variation, both actors)
//	GET /warehouse/handovers/{handover}/print → the A4 print page (HTML)
//
// Both are agent-scoped by the policy (super_admin, or agen/admin/gudang of the SAME branch); a foreign id is a
// 404, never a 403.

import (
	"context"
	"database/sql"
	"errors"
	"strings"
	"time"
)

// Handover is one stock_handovers row with its rendered relations.
type Handover struct {
	ID              int64
	AgentID         int64
	StockTransferID int64
	HandoverNumber  *string
	HandedOverBy    *int64
	HandedOverUser  *map[string]any
	ReceivedBy      *int64
	ReceivedUser    *map[string]any
	Status          string
	HandedOverAt    *time.Time
	ReceivedAt      *time.Time
	Note            *string
	CreatedAt       time.Time
	UpdatedAt       time.Time

	Transfer *map[string]any
}

// LoadHandover reads one handover with every relation both routes render.
func LoadHandover(ctx context.Context, q queryExecer, agentID, handoverID int64) (*Handover, error) {
	var (
		h               Handover
		number, note    sql.NullString
		handedBy, gotBy sql.NullInt64
		handedAt, gotAt sql.NullTime
		agentName       string
	)
	err := q.QueryRowContext(ctx, `SELECT h.id, h.agent_id, h.stock_transfer_id, h.handover_number,
			h.handed_over_by, h.received_by, h.status, h.handed_over_at, h.received_at, h.note,
			h.created_at, h.updated_at, u.name
		FROM stock_handovers h
		LEFT JOIN users u ON u.id = h.agent_id
		WHERE h.id = ? AND h.agent_id = ?`, handoverID, agentID).
		Scan(&h.ID, &h.AgentID, &h.StockTransferID, &number, &handedBy, &gotBy, &h.Status,
			&handedAt, &gotAt, &note, &h.CreatedAt, &h.UpdatedAt, &agentName)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}
	h.HandoverNumber = nullStringPtrLocal(number)
	h.Note = nullStringPtrLocal(note)
	h.HandedOverBy = nullInt64Ptr(handedBy)
	h.ReceivedBy = nullInt64Ptr(gotBy)
	if handedAt.Valid {
		t := handedAt.Time
		h.HandedOverAt = &t
	}
	if gotAt.Valid {
		t := gotAt.Time
		h.ReceivedAt = &t
	}

	if handedBy.Valid {
		user, uerr := serializeUserRow(ctx, q, handedBy.Int64)
		if uerr != nil {
			return nil, uerr
		}
		h.HandedOverUser = user
	}
	if gotBy.Valid {
		user, uerr := serializeUserRow(ctx, q, gotBy.Int64)
		if uerr != nil {
			return nil, uerr
		}
		h.ReceivedUser = user
	}

	transfer, terr := serializeTransferForHandover(ctx, q, h.StockTransferID)
	if terr != nil {
		return nil, terr
	}
	h.Transfer = transfer
	return &h, nil
}

// HandoverPrint is the data the print page renders.
type HandoverPrint struct {
	HandoverNumber string
	AgentName      string
	TransferNumber string
	Source         string
	Destination    string
	HandedOverName string
	ReceivedName   string
	Note           string
	Items          []HandoverPrintItem
	PrintedAt      string
}

// HandoverPrintItem is one table row.
type HandoverPrintItem struct {
	SKU      string
	Label    string
	Quantity int
}

// BuildHandoverPrint assembles the print-page data (the HTML template lives in the api package).
func BuildHandoverPrint(ctx context.Context, q queryExecer, agentID, handoverID int64) (*HandoverPrint, error) {
	h, err := LoadHandover(ctx, q, agentID, handoverID)
	if err != nil {
		return nil, err
	}
	transfer := *h.Transfer
	out := &HandoverPrint{
		HandoverNumber: derefString(h.HandoverNumber),
		Note:           derefOrDash(h.Note),
		ReceivedName:   "-",
		PrintedAt:      time.Now().UTC().Format("2006-01-02 15:04"),
	}
	if name, ok := transfer["__agent_name"].(string); ok {
		out.AgentName = name
	}
	out.TransferNumber, _ = transfer["transfer_number"].(string)
	if source, ok := transfer["__source_label"].(string); ok {
		out.Source = source
	}
	if destination, ok := transfer["__destination_label"].(string); ok {
		out.Destination = destination
	}
	if h.HandedOverUser != nil {
		if name, ok := (*h.HandedOverUser)["name"].(string); ok {
			out.HandedOverName = name
		}
	}
	if h.ReceivedUser != nil {
		if name, ok := (*h.ReceivedUser)["name"].(string); ok {
			out.ReceivedName = name
		}
	}

	items, ok := transfer["items"].([]any)
	if ok {
		for _, raw := range items {
			item, alright := raw.(map[string]any)
			if !alright {
				continue
			}
			row := HandoverPrintItem{
				SKU:      "-",
				Quantity: 0,
			}
			if sku, present := item["__sku"].(string); present && sku != "" {
				row.SKU = sku
			}
			if label, present := item["__label"].(string); present {
				row.Label = label
			}
			switch quantity := item["quantity"].(type) {
			case int64:
				row.Quantity = int(quantity)
			case int:
				row.Quantity = quantity
			case float64:
				row.Quantity = int(quantity)
			}
			out.Items = append(out.Items, row)
		}
	}
	return out, nil
}

// serializeUserRow renders one users row the way Eloquent does (every column, timestamps as ISO strings).
//
// The reference's User model has NO $hidden, so the handover payload includes email and phone. That is the
// observed contract for this endpoint's authenticated warehouse audience.
func serializeUserRow(ctx context.Context, q queryExecer, userID int64) (*map[string]any, error) {
	var (
		id, roleID                int64
		korsalID, salesID         sql.NullInt64
		parentID, agentID         sql.NullInt64
		name, phone, referralCode sql.NullString
		email                     sql.NullString
		avatarID                  sql.NullInt64
		status                    string
		createdAt, updatedAt      sql.NullTime
		deletedAt                 sql.NullTime
		emailVerified             sql.NullTime
	)
	err := q.QueryRowContext(ctx, `SELECT u.id, u.role_id, u.parent_id, u.agent_id, u.korsal_id, u.sales_id,
			u.name, u.email, u.phone, u.avatar_media_id, u.referral_code, u.status,
			u.email_verified_at, u.created_at, u.updated_at, u.deleted_at
		FROM users u WHERE u.id = ?`, userID).
		Scan(&id, &roleID, &parentID, &agentID, &korsalID, &salesID, &name, &email, &phone,
			&avatarID, &referralCode, &status, &emailVerified, &createdAt, &updatedAt, &deletedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{
		"id":                id,
		"role_id":           roleID,
		"parent_id":         nullableInt(nullInt64Ptr(parentID)),
		"agent_id":          nullableInt(nullInt64Ptr(agentID)),
		"korsal_id":         nullableInt(nullInt64Ptr(korsalID)),
		"sales_id":          nullableInt(nullInt64Ptr(salesID)),
		"name":              name.String,
		"email":             nullableStringRow(email),
		"phone":             nullableStringRow(phone),
		"avatar_media_id":   nullableInt(nullInt64Ptr(avatarID)),
		"referral_code":     nullableStringRow(referralCode),
		"status":            status,
		"email_verified_at": renderJSONTime(emailVerified),
		"created_at":        renderJSONTime(createdAt),
		"updated_at":        renderJSONTime(updatedAt),
		"deleted_at":        renderJSONTime(deletedAt),
	}
	return &out, nil
}

// derefOrDash renders Blade's `{{ $x ?? '-' }}`: an absent value becomes a dash.
func derefOrDash(v *string) string {
	if v == nil || *v == "" {
		return "-"
	}
	return *v
}

func nullableStringRow(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

// serializeTransferForHandover renders the parent transfer with its items and products, plus internal
// `__`-prefixed helper keys the print builder consumes (removed from the JSON shape by the renderer).
func serializeTransferForHandover(ctx context.Context, q queryExecer, transferID int64) (*map[string]any, error) {
	var (
		t                                  serializedTransferRow
		sourceSub, destinationSub          sql.NullInt64
		reference, note, rejection         sql.NullString
		createdBy, completedBy, rejectedBy sql.NullInt64
		completedAt, rejectedAt            sql.NullTime
	)
	err := q.QueryRowContext(ctx, `SELECT t.id, t.agent_id, t.transfer_number, t.source_stock_type,
			t.source_sub_location_id, t.destination_stock_type, t.destination_sub_location_id, t.status,
			t.reference, t.note, t.created_by, t.completed_by, t.rejected_by, t.rejected_at,
			t.rejection_reason, t.completed_at, t.created_at, t.updated_at
		FROM stock_transfers t WHERE t.id = ?`, transferID).
		Scan(&t.ID, &t.AgentID, &t.TransferNumber, &t.SourceStockType, &sourceSub, &t.DestinationStockType,
			&destinationSub, &t.Status, &reference, &note, &createdBy, &completedBy, &rejectedBy,
			&rejectedAt, &rejection, &completedAt, &t.CreatedAt, &t.UpdatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrStockRequestNotFound
	}
	if err != nil {
		return nil, err
	}

	out := map[string]any{
		"id":                          t.ID,
		"agent_id":                    t.AgentID,
		"transfer_number":             t.TransferNumber,
		"source_stock_type":           t.SourceStockType,
		"source_sub_location_id":      nullableInt(nullInt64Ptr(sourceSub)),
		"destination_stock_type":      t.DestinationStockType,
		"destination_sub_location_id": nullableInt(nullInt64Ptr(destinationSub)),
		"status":                      t.Status,
		"reference":                   nullableStringRow(reference),
		"note":                        nullableStringRow(note),
		"created_by":                  nullableInt(nullInt64Ptr(createdBy)),
		"completed_by":                nullableInt(nullInt64Ptr(completedBy)),
		"rejected_by":                 nullableInt(nullInt64Ptr(rejectedBy)),
		"rejected_at":                 renderJSONTime(rejectedAt),
		"rejection_reason":            nullableStringRow(rejection),
		"completed_at":                renderJSONTime(completedAt),
		"created_at":                  renderJSONTime(sql.NullTime{Time: t.CreatedAt, Valid: true}),
		"updated_at":                  renderJSONTime(sql.NullTime{Time: t.UpdatedAt, Valid: true}),
	}

	// The agent's name (the print header) and the source/destination labels.
	var agentName sql.NullString
	if err := q.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, t.AgentID).Scan(&agentName); err == nil {
		out["__agent_name"] = agentName.String
	}
	for _, pair := range []struct {
		key    string
		bucket string
		id     sql.NullInt64
	}{
		{"__source_label", t.SourceStockType, sourceSub},
		{"__destination_label", t.DestinationStockType, destinationSub},
	} {
		label := pair.bucket
		if pair.id.Valid {
			var subName, subCode sql.NullString
			if err := q.QueryRowContext(ctx, `SELECT name, code FROM warehouse_sub_locations WHERE id = ?`,
				pair.id.Int64).Scan(&subName, &subCode); err == nil {
				label = pair.bucket + " — " + subName.String + " (" + subCode.String + ")"
			}
		}
		out[pair.key] = label
	}

	// Items with product/variation models.
	rows, err := q.QueryContext(ctx, `SELECT i.id, i.stock_transfer_id, i.product_id, i.product_variation_id,
			i.quantity, i.created_at, i.updated_at
		FROM stock_transfer_items i WHERE i.stock_transfer_id = ? ORDER BY i.id`, transferID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]any, 0)
	for rows.Next() {
		var (
			id, transferID         int64
			productID, variationID sql.NullInt64
			quantity               int64
			createdAt, updatedAt   sql.NullTime
		)
		if err := rows.Scan(&id, &transferID, &productID, &variationID, &quantity, &createdAt, &updatedAt); err != nil {
			return nil, err
		}
		item := map[string]any{
			"id":                   id,
			"stock_transfer_id":    transferID,
			"product_id":           nullableInt(nullInt64Ptr(productID)),
			"product_variation_id": nullableInt(nullInt64Ptr(variationID)),
			"quantity":             quantity,
			"created_at":           renderJSONTime(createdAt),
			"updated_at":           renderJSONTime(updatedAt),
			"product":              nil,
			"variation":            nil,
		}
		if productID.Valid {
			product, perr := serializeProduct(ctx, q, productID.Int64, false)
			if perr != nil {
				return nil, perr
			}
			delete(product, "__image_path")
			item["product"] = product
			if path := primaryImagePath(product); path != "" {
				_ = path
			}
			if name, ok := product["name"].(string); ok {
				item["__label"] = name
			}
			if sku, ok := product["sku"].(string); ok && sku != "" {
				item["__sku"] = sku
			}
		}
		if variationID.Valid {
			variation, verr := serializeVariation(ctx, q, variationID.Int64)
			if verr != nil {
				return nil, verr
			}
			delete(variation, "__label")
			item["variation"] = variation
			if sku, ok := variation["sku"].(string); ok && sku != "" {
				item["__sku"] = sku
			}
			if label, ok := variation["__label"].(string); ok {
				item["__label"] = label
			}
		}
		items = append(items, item)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	out["items"] = items
	return &out, nil
}

type serializedTransferRow struct {
	ID                   int64
	AgentID              int64
	TransferNumber       string
	SourceStockType      string
	DestinationStockType string
	Status               string
	CreatedAt            time.Time
	UpdatedAt            time.Time
}

// StripPrintHelpers removes the internal `__`-prefixed keys before a JSON render.
func StripPrintHelpers(m map[string]any) {
	for key := range m {
		if strings.HasPrefix(key, "__") {
			delete(m, key)
		}
	}
	if items, ok := m["items"].([]any); ok {
		for _, raw := range items {
			if item, alright := raw.(map[string]any); alright {
				StripPrintHelpers(item)
			}
		}
	}
}
