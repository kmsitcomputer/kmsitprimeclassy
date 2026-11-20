package catalog

import (
	"context"
	"database/sql"
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

type Bestseller struct {
	ProductID int64
	Units     int64
	Last      string
}

// Bestsellers ranks item-receipt evidence for one validated Agent. It deliberately ignores
// shipment-delivery projections and unresolved multi-receipt returns, matching BestsellerService.
func Bestsellers(ctx context.Context, d *db.DB, agentID int64, period string, limit int) ([]map[string]any, bool, error) {
	loc, err := time.LoadLocation("Asia/Jakarta")
	if err != nil {
		return nil, true, err
	}
	today := time.Now().In(loc).Format("2006-01-02")
	from := ""
	if period != "all" {
		days := map[string]int{"7": 7, "30": 30, "90": 90}[period]
		t, _ := time.Parse("2006-01-02", today)
		from = t.AddDate(0, 0, -(days - 1)).Format("2006-01-02")
	}
	rows, err := d.QueryContext(ctx, `SELECT r.id,r.order_item_id,oi.product_id,r.quantity,CAST(r.received_on AS CHAR)
		FROM order_item_receipts r JOIN orders o ON o.id=r.order_id JOIN order_items oi ON oi.id=r.order_item_id
		WHERE r.agent_id=? AND o.agent_id=? AND o.payment_status='paid' AND o.remaining_amount<=0 AND o.status<>'dibatalkan'`, agentID, agentID)
	if err != nil {
		return nil, true, err
	}
	type receipt struct {
		id, item, product int64
		quantity          int64
		date              string
	}
	events := []receipt{}
	items := map[int64]bool{}
	for rows.Next() {
		var e receipt
		if err := rows.Scan(&e.id, &e.item, &e.product, &e.quantity, &e.date); err != nil {
			rows.Close()
			return nil, true, err
		}
		events = append(events, e)
		items[e.item] = true
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, true, err
	}
	rows.Close()
	if len(events) == 0 {
		return []map[string]any{}, true, nil
	}
	place := func(n int) string { return strings.TrimRight(strings.Repeat("?,", n), ",") }
	itemArgs := make([]any, 0, len(items))
	for id := range items {
		itemArgs = append(itemArgs, id)
	}
	retRows, err := d.QueryContext(ctx, `SELECT order_item_id,COALESCE(SUM(quantity_received),0) FROM return_items WHERE status='approved' AND inspected_at IS NOT NULL AND order_item_id IN (`+place(len(itemArgs))+`) GROUP BY order_item_id`, itemArgs...)
	if err != nil {
		return nil, true, err
	}
	returns := map[int64]int64{}
	for retRows.Next() {
		var id, n int64
		if err := retRows.Scan(&id, &n); err != nil {
			retRows.Close()
			return nil, true, err
		}
		returns[id] = n
	}
	if err := retRows.Err(); err != nil {
		retRows.Close()
		return nil, true, err
	}
	retRows.Close()
	receiptIDs := make([]any, 0, len(events))
	for _, e := range events {
		receiptIDs = append(receiptIDs, e.id)
	}
	allocRows, err := d.QueryContext(ctx, `SELECT order_item_receipt_id,COALESCE(SUM(quantity),0) FROM return_item_receipt_allocations WHERE order_item_receipt_id IN (`+place(len(receiptIDs))+`) GROUP BY order_item_receipt_id`, receiptIDs...)
	if err != nil {
		return nil, true, err
	}
	alloc := map[int64]int64{}
	for allocRows.Next() {
		var id, n int64
		if err := allocRows.Scan(&id, &n); err != nil {
			allocRows.Close()
			return nil, true, err
		}
		alloc[id] = n
	}
	if err := allocRows.Err(); err != nil {
		allocRows.Close()
		return nil, true, err
	}
	allocRows.Close()
	byItem := map[int64][]receipt{}
	allocatedByItem := map[int64]int64{}
	for _, e := range events {
		byItem[e.item] = append(byItem[e.item], e)
		allocatedByItem[e.item] += alloc[e.id]
	}
	type total struct {
		units int64
		last  string
	}
	byProduct := map[int64]total{}
	for item, evs := range byItem {
		ret := returns[item]
		if len(evs) > 1 && ret > 0 && allocatedByItem[item] != ret {
			continue
		}
		for _, e := range evs {
			if e.date > today || (from != "" && e.date < from) {
				continue
			}
			deduct := ret
			if len(evs) > 1 {
				deduct = alloc[e.id]
			}
			net := e.quantity - deduct
			if net <= 0 {
				continue
			}
			x := byProduct[e.product]
			x.units += net
			if e.date > x.last {
				x.last = e.date
			}
			byProduct[e.product] = x
		}
	}
	if len(byProduct) == 0 {
		return []map[string]any{}, true, nil
	}
	productArgs := make([]any, 0, len(byProduct))
	for id := range byProduct {
		productArgs = append(productArgs, id)
	}
	ps, err := d.QueryContext(ctx, `SELECT id,slug FROM products WHERE status='active' AND deleted_at IS NULL AND id IN (`+place(len(productArgs))+`)`, productArgs...)
	if err != nil {
		return nil, true, err
	}
	slugs := map[int64]string{}
	for ps.Next() {
		var id int64
		var slug string
		if err := ps.Scan(&id, &slug); err != nil {
			ps.Close()
			return nil, true, err
		}
		slugs[id] = slug
	}
	if err := ps.Err(); err != nil {
		ps.Close()
		return nil, true, err
	}
	ps.Close()
	ranked := []Bestseller{}
	for id, x := range byProduct {
		if _, ok := slugs[id]; ok {
			ranked = append(ranked, Bestseller{ProductID: id, Units: x.units, Last: x.last})
		}
	}
	sort.Slice(ranked, func(i, j int) bool {
		a, b := ranked[i], ranked[j]
		if a.Units != b.Units {
			return a.Units > b.Units
		}
		if a.Last != b.Last {
			return a.Last > b.Last
		}
		return a.ProductID < b.ProductID
	})
	if len(ranked) > limit {
		ranked = ranked[:limit]
	}
	out := make([]map[string]any, 0, len(ranked))
	for _, item := range ranked {
		product, err := ProductBySlug(ctx, d, slugs[item.ProductID])
		if err != nil {
			if err == ErrProductNotFound {
				continue
			}
			return nil, true, fmt.Errorf("load bestseller product: %w", err)
		}
		out = append(out, product)
	}
	return out, len(out) == 0, nil
}

func BestsellerAgent(ctx context.Context, d *db.DB, userID int64, role string, agentID *int64, explicit *int64, ref string) (*int64, error) {
	if userID != 0 {
		if role == "super_admin" {
			if explicit == nil {
				return nil, nil
			}
			if valid, err := validBestsellerAgent(ctx, d, *explicit); err != nil {
				return nil, err
			} else if !valid {
				return nil, nil
			}
			return explicit, nil
		}
		if agentID == nil {
			return nil, nil
		}
		if valid, err := validBestsellerAgent(ctx, d, *agentID); err != nil {
			return nil, err
		} else if !valid {
			return nil, nil
		}
		v := *agentID
		return &v, nil
	}
	if strings.TrimSpace(ref) == "" {
		return nil, nil
	}
	var id int64
	err := d.QueryRowContext(ctx, `SELECT u.agent_id FROM users u JOIN roles r ON r.id=u.role_id WHERE UPPER(u.referral_code)=? AND u.status='active' AND u.deleted_at IS NULL AND r.slug IN ('agen','korsal','sales','sales-kurir-sub') AND u.agent_id IS NOT NULL LIMIT 1`, strings.ToUpper(strings.TrimSpace(ref))).Scan(&id)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		return nil, err
	}
	if valid, err := validBestsellerAgent(ctx, d, id); err != nil {
		return nil, err
	} else if !valid {
		return nil, nil
	}
	return &id, nil
}

func validBestsellerAgent(ctx context.Context, d *db.DB, id int64) (bool, error) {
	var ok int
	err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' AND u.deleted_at IS NULL AND r.slug='agen'`, id).Scan(&ok)
	return ok > 0, err
}
