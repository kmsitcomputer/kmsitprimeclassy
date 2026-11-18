package inventory

import (
	"context"
	"database/sql"
	"errors"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// DomainError is an inventory-domain failure that already carries its HTTP status and the exact message the
// reference implementation returns. Handlers map it straight onto the envelope so the comparison can assert
// the body, not just the status code.
type DomainError struct {
	Status  int
	Message string
	Fields  map[string]string
}

func (e *DomainError) Error() string { return e.Message }

// AdjustStock is StockService::adjustProduct / adjustVariation.
//
// LOCK ORDER: the Agent commitment row (product_stocks / product_variation_stocks) is the ONLY row locked, and
// it is locked before the row is created if absent. That is exactly what the reference does, and it is the same
// first lock reserveFor*() takes, so an adjustment serialises against a reservation on the same target.
//
// The `quantity_on_hand` result can never go below zero (locked business rule "stok tidak boleh negatif").
func AdjustStock(ctx context.Context, d *db.DB, actor Actor, productID, variationID *int64, delta int64, reason string) (map[string]any, error) {
	var out map[string]any

	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if variationID != nil {
			stockID, onHand, err := lockVariationStock(ctx, tx, actor.AgentID, *variationID)
			if err != nil {
				return err
			}
			if stockID == 0 {
				res, err := tx.ExecContext(ctx, `INSERT INTO product_variation_stocks
					(agent_id, product_variation_id, quantity_on_hand, quantity_reserved, created_at, updated_at)
					VALUES (?, ?, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, actor.AgentID, *variationID)
				if err != nil {
					return err
				}
				stockID, _ = res.LastInsertId()
				onHand = 0
			}
			resulting := onHand + delta
			if resulting < 0 {
				return &DomainError{
					Status:  422,
					Message: "Penyesuaian ini akan membuat stok menjadi negatif.",
					Fields:  map[string]string{"quantity_on_hand": itoa(onHand), "delta": itoa(delta)},
				}
			}
			if _, err := tx.ExecContext(ctx, `UPDATE product_variation_stocks SET quantity_on_hand = ?, updated_at = UTC_TIMESTAMP()
				WHERE id = ?`, resulting, stockID); err != nil {
				return err
			}
			if err := writeMovement(ctx, tx, movement{
				AgentID: actor.AgentID, VariationID: variationID, Type: "adjustment",
				Quantity: delta, CreatedBy: actor.ID, Note: reason,
			}); err != nil {
				return err
			}
			out, err = variationStockJSON(ctx, tx, stockID)
			return err
		}

		// product branch: a variation-bearing product cannot be adjusted as a product.
		var hasVariations bool
		var name string
		if err := tx.QueryRowContext(ctx, `SELECT name, has_variations FROM products WHERE id = ? AND deleted_at IS NULL`,
			*productID).Scan(&name, &hasVariations); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return &DomainError{Status: 404, Message: "Data yang diminta tidak ditemukan."}
			}
			return err
		}
		if hasVariations {
			return &DomainError{
				Status:  422,
				Message: name + " memiliki varian; stok diatur per varian.",
			}
		}

		stockID, onHand, err := lockProductStock(ctx, tx, actor.AgentID, *productID)
		if err != nil {
			return err
		}
		if stockID == 0 {
			res, err := tx.ExecContext(ctx, `INSERT INTO product_stocks
				(agent_id, product_id, quantity_on_hand, quantity_reserved, created_at, updated_at)
				VALUES (?, ?, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, actor.AgentID, *productID)
			if err != nil {
				return err
			}
			stockID, _ = res.LastInsertId()
			onHand = 0
		}
		resulting := onHand + delta
		if resulting < 0 {
			return &DomainError{
				Status:  422,
				Message: "Penyesuaian ini akan membuat stok menjadi negatif.",
				Fields:  map[string]string{"quantity_on_hand": itoa(onHand), "delta": itoa(delta)},
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE product_stocks SET quantity_on_hand = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, resulting, stockID); err != nil {
			return err
		}
		if err := writeMovement(ctx, tx, movement{
			AgentID: actor.AgentID, ProductID: productID, Type: "adjustment",
			Quantity: delta, CreatedBy: actor.ID, Note: reason,
		}); err != nil {
			return err
		}
		out, err = productStockJSON(ctx, tx, stockID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

// lockProductStock takes the canonical first lock and returns the row's id + on-hand (0/0 when absent).
func lockProductStock(ctx context.Context, tx *sql.Tx, agentID, productID int64) (int64, int64, error) {
	var id, onHand int64
	err := tx.QueryRowContext(ctx, `SELECT id, quantity_on_hand FROM product_stocks
		WHERE agent_id = ? AND product_id = ? FOR UPDATE`, agentID, productID).Scan(&id, &onHand)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, 0, nil
	}
	if err != nil {
		return 0, 0, err
	}
	return id, onHand, nil
}

// lockVariationStock is the variation equivalent.
func lockVariationStock(ctx context.Context, tx *sql.Tx, agentID, variationID int64) (int64, int64, error) {
	var id, onHand int64
	err := tx.QueryRowContext(ctx, `SELECT id, quantity_on_hand FROM product_variation_stocks
		WHERE agent_id = ? AND product_variation_id = ? FOR UPDATE`, agentID, variationID).Scan(&id, &onHand)
	if errors.Is(err, sql.ErrNoRows) {
		return 0, 0, nil
	}
	if err != nil {
		return 0, 0, err
	}
	return id, onHand, nil
}

// productStockJSON renders ProductStockResource (product relation loaded).
func productStockJSON(ctx context.Context, tx *sql.Tx, id int64) (map[string]any, error) {
	var (
		agentID, productID     int64
		onHand, reserved       int64
		createdRaw, updatedRaw sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT agent_id, product_id, quantity_on_hand, quantity_reserved,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM product_stocks WHERE id = ?`, id).Scan(&agentID, &productID, &onHand, &reserved, &createdRaw, &updatedRaw); err != nil {
		return nil, err
	}
	var (
		name, slug             string
		sku                    sql.NullString
		hasVariations          bool
		status                 string
		basePrice              sql.NullString
		weightGrams            sql.NullInt64
		categoryID             sql.NullInt64
		createdBy              int64
		pCreated, pUpdated     sql.NullString
		pDeleted               sql.NullString
		description, shortDesc sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT name, slug, sku, has_variations, status, base_price, weight_grams,
			category_id, created_by, description, short_description,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR), CAST(deleted_at AS CHAR)
		FROM products WHERE id = ?`, productID).Scan(&name, &slug, &sku, &hasVariations, &status, &basePrice,
		&weightGrams, &categoryID, &createdBy, &description, &shortDesc, &pCreated, &pUpdated, &pDeleted); err != nil {
		return nil, err
	}
	return map[string]any{
		"id":                id,
		"agent_id":          agentID,
		"product_id":        productID,
		"quantity_on_hand":  onHand,
		"quantity_reserved": reserved,
		"created_at":        formatMySQLString(createdRaw),
		"updated_at":        formatMySQLString(updatedRaw),
		"product": map[string]any{
			"id":                productID,
			"category_id":       nullIntPtr(categoryID),
			"created_by":        createdBy,
			"name":              name,
			"slug":              slug,
			"description":       nullStr(description),
			"short_description": nullStr(shortDesc),
			"has_variations":    hasVariations,
			"base_price":        nullStr(basePrice),
			"weight_grams":      nullIntPtr(weightGrams),
			"status":            status,
			"created_at":        formatMySQLString(pCreated),
			"updated_at":        formatMySQLString(pUpdated),
			"deleted_at":        formatMySQLString(pDeleted),
			"sku":               nullStr(sku),
		},
	}, nil
}

// variationStockJSON renders ProductVariationStockResource (variation relation loaded).
func variationStockJSON(ctx context.Context, tx *sql.Tx, id int64) (map[string]any, error) {
	var (
		agentID, variationID   int64
		onHand, reserved       int64
		createdRaw, updatedRaw sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT agent_id, product_variation_id, quantity_on_hand, quantity_reserved,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM product_variation_stocks WHERE id = ?`, id).Scan(&agentID, &variationID, &onHand, &reserved, &createdRaw, &updatedRaw); err != nil {
		return nil, err
	}
	var (
		productID          int64
		sku, price         string
		weight, sortOrder  int64
		isActive           int
		vCreated, vUpdated sql.NullString
		vDeleted           sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT product_id, sku, price, weight_grams, is_active, sort_order,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR), CAST(deleted_at AS CHAR)
		FROM product_variations WHERE id = ?`, variationID).Scan(&productID, &sku, &price, &weight, &isActive,
		&sortOrder, &vCreated, &vUpdated, &vDeleted); err != nil {
		return nil, err
	}
	return map[string]any{
		"id":                   id,
		"agent_id":             agentID,
		"product_variation_id": variationID,
		"quantity_on_hand":     onHand,
		"quantity_reserved":    reserved,
		"created_at":           formatMySQLString(createdRaw),
		"updated_at":           formatMySQLString(updatedRaw),
		"variation": map[string]any{
			"id":           variationID,
			"product_id":   productID,
			"sku":          sku,
			"price":        price,
			"weight_grams": weight,
			"is_active":    isActive == 1,
			"sort_order":   sortOrder,
			"created_at":   formatMySQLString(vCreated),
			"updated_at":   formatMySQLString(vUpdated),
			"deleted_at":   formatMySQLString(vDeleted),
		},
	}, nil
}
