package catalog

import (
	"context"
	"fmt"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

var ExportHeaders = []string{"type", "sku", "product_slug", "product_name", "category_id", "status", "price", "weight_grams", "attributes", "stock_target"}

type ExportStock struct {
	AgentID    int64
	Type       string
	LocationID *int64
}

// ExportRows mirrors CatalogImportExportController::export row order and bucket semantics.
func ExportRows(ctx context.Context, d *db.DB, stock *ExportStock) ([][]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id,COALESCE(category_id,0),name,slug,has_variations,COALESCE(CAST(base_price AS CHAR),''),COALESCE(weight_grams,0),status,COALESCE(sku,'') FROM products WHERE deleted_at IS NULL ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := [][]any{}
	for rows.Next() {
		var id, category, weight int64
		var name, slug, price, status, sku string
		var variants bool
		if err := rows.Scan(&id, &category, &name, &slug, &variants, &price, &weight, &status, &sku); err != nil {
			return nil, err
		}
		if !variants {
			count, err := exportStockCount(ctx, d, stock, id, nil)
			if err != nil {
				return nil, err
			}
			out = append(out, []any{"simple", sku, slug, name, nullableCategory(category), status, price, weight, "", count})
			continue
		}
		vs, err := d.QueryContext(ctx, `SELECT id,sku,CAST(price AS CHAR),weight_grams,is_active FROM product_variations WHERE product_id=? AND deleted_at IS NULL ORDER BY id`, id)
		if err != nil {
			return nil, err
		}
		type variation struct {
			id         int64
			sku, price string
			weight     int64
			active     bool
		}
		all := []variation{}
		for vs.Next() {
			var v variation
			if err := vs.Scan(&v.id, &v.sku, &v.price, &v.weight, &v.active); err != nil {
				vs.Close()
				return nil, err
			}
			all = append(all, v)
		}
		if err := vs.Err(); err != nil {
			vs.Close()
			return nil, err
		}
		vs.Close()
		for _, v := range all {
			attrs, err := exportVariationAttrs(ctx, d, v.id)
			if err != nil {
				return nil, err
			}
			count, err := exportStockCount(ctx, d, stock, 0, &v.id)
			if err != nil {
				return nil, err
			}
			state := "inactive"
			if v.active {
				state = "active"
			}
			out = append(out, []any{"variant", v.sku, slug, name, nullableCategory(category), state, v.price, v.weight, attrs, count})
		}
	}
	return out, rows.Err()
}

func nullableCategory(id int64) any {
	if id == 0 {
		return ""
	}
	return id
}
func exportStockCount(ctx context.Context, d *db.DB, scope *ExportStock, productID int64, variationID *int64) (any, error) {
	if scope == nil {
		return "", nil
	}
	var quantity int
	var err error
	if productID > 0 {
		err = d.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks WHERE agent_id=? AND stock_type=? AND sub_location_id <=> ? AND product_id=? AND product_variation_id IS NULL`, scope.AgentID, scope.Type, scope.LocationID, productID).Scan(&quantity)
	} else {
		err = d.QueryRowContext(ctx, `SELECT COALESCE(SUM(quantity),0) FROM warehouse_stocks WHERE agent_id=? AND stock_type=? AND sub_location_id <=> ? AND product_variation_id=? AND product_id IS NULL`, scope.AgentID, scope.Type, scope.LocationID, *variationID).Scan(&quantity)
	}
	return quantity, err
}
func exportVariationAttrs(ctx context.Context, d *db.DB, id int64) (string, error) {
	rows, err := d.QueryContext(ctx, `SELECT a.name,o.value FROM product_variation_compositions c JOIN product_variation_attributes a ON a.id=c.product_variation_attribute_id JOIN product_variation_attribute_options o ON o.id=c.product_variation_attribute_option_id WHERE c.product_variation_id=? ORDER BY c.id`, id)
	if err != nil {
		return "", err
	}
	defer rows.Close()
	parts := []string{}
	for rows.Next() {
		var name, value string
		if err := rows.Scan(&name, &value); err != nil {
			return "", err
		}
		parts = append(parts, name+"="+value)
	}
	if err := rows.Err(); err != nil {
		return "", err
	}
	return strings.Join(parts, ";"), nil
}

func ExportCSV(rows [][]any) []byte {
	var b strings.Builder
	b.Write([]byte{0xef, 0xbb, 0xbf})
	record := func(values []any) {
		for i, v := range values {
			if i > 0 {
				b.WriteByte(',')
			}
			s := fmt.Sprint(v)
			if s == "<nil>" {
				s = ""
			}
			if s != "" && strings.ContainsRune("=+-@\t\r", rune(s[0])) {
				s = "'" + s
			}
			if strings.ContainsAny(s, ",\"\r\n") {
				s = "\"" + strings.ReplaceAll(s, "\"", "\"\"") + "\""
			}
			b.WriteString(s)
		}
		b.WriteString("\r\n")
	}
	head := make([]any, len(ExportHeaders))
	for i, h := range ExportHeaders {
		head[i] = h
	}
	record(head)
	for _, row := range rows {
		record(row)
	}
	return []byte(b.String())
}

func ExportString(v any) string {
	if v == nil {
		return ""
	}
	s := fmt.Sprint(v)
	if s != "" && strings.ContainsRune("=+-@\t\r", rune(s[0])) {
		return "'" + s
	}
	return s
}
