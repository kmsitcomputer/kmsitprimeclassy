// Package payment owns the canonical "which payment methods can this order actually use" rule.
//
// Ported from Services/Payment/AvailablePaymentMethodService — the SAME filter feeds the checkout wizard
// (GET /checkout/steps) and, later, order creation. Keeping it in one package is the point: the reference's
// docblock says this must never be re-implemented inline, and the same rule decides both UX and authority.
//
// The two filters that are easy to miss:
//   - a KONSUMEN checking out for themselves only ever sees `bank_transfer` (Owner rule, Improvement 04) —
//     no COD, no DP;
//   - shipping_method `rajaongkir` (Ekspedisi) allows ONLY `bank_transfer`, because an expedition courier is a
//     third party the agent does not control, so COD/DP collection assumptions do not hold.
//
// `gateway` methods are never listed here; they are a payment sub-flow, not a checkout choice.
package payment

import (
	"context"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// Method is one payment_methods row, rendered as the checkout payload's `payment_methods[]` entry.
type Method struct {
	ID   int64  `json:"id"`
	Code string `json:"code"`
	Name string `json:"name"`
	Type string `json:"type"`
}

// expeditionAllowedCodes matches EXPEDITION_ALLOWED_CODES.
var expeditionAllowedCodes = map[string]bool{"bank_transfer": true}

// konsumenAllowedCodes matches KONSUMEN_ALLOWED_CODES.
var konsumenAllowedCodes = map[string]bool{"bank_transfer": true}

// AvailableMethods is AvailablePaymentMethodService::availableMethods.
//
// Ordering: `ORDER BY id`, so the wizard's list is stable and identical across runtimes.
func AvailableMethods(ctx context.Context, d *db.DB, agentID *int64, shippingMethod *string, actorIsKonsumen bool) ([]Method, error) {
	const base = `SELECT id, code, name, type FROM payment_methods
		WHERE is_active = 1 AND type <> 'gateway'`

	var (
		methods []Method
		err     error
	)
	if agentID != nil {
		rows, qerr := d.QueryContext(ctx, base+` AND id NOT IN (
			SELECT payment_method_id FROM agent_payment_method_settings WHERE agent_id = ? AND is_active = 0)
			ORDER BY id`, *agentID)
		if qerr != nil {
			if db.IsMissingSchema(qerr) {
				return []Method{}, nil
			}
			return nil, qerr
		}
		defer rows.Close()
		for rows.Next() {
			var m Method
			if err := rows.Scan(&m.ID, &m.Code, &m.Name, &m.Type); err != nil {
				return nil, err
			}
			methods = append(methods, m)
		}
		if err = rows.Err(); err != nil {
			return nil, err
		}
	} else {
		rows, qerr := d.QueryContext(ctx, base+` ORDER BY id`)
		if qerr != nil {
			if db.IsMissingSchema(qerr) {
				return []Method{}, nil
			}
			return nil, qerr
		}
		defer rows.Close()
		for rows.Next() {
			var m Method
			if err := rows.Scan(&m.ID, &m.Code, &m.Name, &m.Type); err != nil {
				return nil, err
			}
			methods = append(methods, m)
		}
		if err = rows.Err(); err != nil {
			return nil, err
		}
	}

	if actorIsKonsumen {
		methods = filterCodes(methods, konsumenAllowedCodes)
	}
	// Expedition compatibility is applied LAST and is authoritative: it narrows whatever the agent's own
	// configuration allowed down to bank_transfer. The reference also applies it last (after the konsumen
	// filter), and both filters agree on bank_transfer, so ordering only matters for documentation clarity.
	if shippingMethod != nil && *shippingMethod == "rajaongkir" {
		return filterCodes(methods, expeditionAllowedCodes), nil
	}
	if methods == nil {
		return []Method{}, nil
	}
	return methods, nil
}

func filterCodes(in []Method, allowed map[string]bool) []Method {
	out := make([]Method, 0, len(in))
	for _, m := range in {
		if allowed[m.Code] {
			out = append(out, m)
		}
	}
	return out
}
