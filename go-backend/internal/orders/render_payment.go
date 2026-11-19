package orders

// Payment-response projections that the shared OrderResource renderer needs but that do not belong to the
// creation path: the invoice-download capability, the Admin delivery-verification history, and the
// "PEMENUHAN KURANG" shortage read model (FulfillmentShortageService).

import (
	"context"
	"database/sql"
	"errors"
)

// viewerCanDownloadInvoice is OrderResource::viewerCanDownloadInvoice: OrderPolicy::viewInvoice
// (view() AND the invoice-audience role list) AND (the invoice is issued OR issuance is eligible).
func (s *Service) viewerCanDownloadInvoice(ctx context.Context, viewer Viewer, order *Order) (bool, error) {
	allowed, err := s.CanView(ctx, RoleScopes{Role: viewer.Role, UserID: viewer.ID, AgentID: viewer.AgentID}, order.ID)
	if err != nil {
		return false, err
	}
	if !allowed {
		return false, nil
	}
	if !viewer.hasRole("konsumen", "super_admin", "agen", "admin", "keuangan", "sales", "korsal") {
		return false, nil
	}
	if !(order.KonsumenID != nil && viewer.ID == *order.KonsumenID || viewer.hasRole("super_admin", "agen", "admin", "keuangan", "sales", "korsal")) {
		return false, nil
	}
	if order.InvoiceIssuedAt != nil {
		return true, nil
	}
	return s.invoiceEligibleUnlocked(ctx, order)
}

// invoiceEligibleUnlocked is InvoiceIssuanceService::isEligible outside a transaction.
func (s *Service) invoiceEligibleUnlocked(ctx context.Context, order *Order) (bool, error) {
	if order.Status == "dibatalkan" && order.PaidAmount <= 0 {
		return false, nil
	}
	paymentType := ""
	if order.PaymentMethod != nil {
		paymentType = order.PaymentMethod.Type
	}
	if paymentType == "cod" {
		return true, nil
	}
	if order.GrossReceived > 0 || order.PaidAmount > 0 {
		return true, nil
	}
	var verified bool
	if err := s.Database.QueryRowContext(ctx, `SELECT EXISTS(
			SELECT 1 FROM bank_transfer_verifications v
			JOIN payment_transactions t ON t.id = v.payment_transaction_id
			WHERE t.order_id = ? AND v.status = 'verified')`, order.ID).Scan(&verified); err != nil {
		return false, err
	}
	return verified, nil
}

// loadDeliveryVerifications is OrderResource::delivery_verifications — DeliveryVerificationResource::collection
// over the order's shipment-level history, oldest first (hasManyThrough, natural key order).
func (s *Service) loadDeliveryVerifications(ctx context.Context, orderID int64) ([]any, error) {
	rows, err := s.Database.QueryContext(ctx, `SELECT dv.id, dv.shipment_id, dv.outcome, dv.note,
			dv.verified_at, dv.verified_by, dv.created_at
		FROM delivery_verifications dv
		JOIN shipments sh ON sh.id = dv.shipment_id
		WHERE sh.order_id = ? ORDER BY dv.id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []any{}
	for rows.Next() {
		var (
			id, shipmentID, verifiedBy int64
			outcome                    string
			note                       sql.NullString
			verifiedAt, createdAt      sql.NullTime
		)
		if err := rows.Scan(&id, &shipmentID, &outcome, &note, &verifiedAt, &verifiedBy, &createdAt); err != nil {
			return nil, err
		}
		verifier, err := s.tinyUserAny(ctx, &verifiedBy)
		if err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id":          id,
			"shipment_id": shipmentID,
			"outcome":     outcome,
			"note":        nullableStringRow(note),
			"verified_at": renderNullTime(verifiedAt),
			"verified_by": verifier,
			"created_at":  renderNullTime(createdAt),
		})
	}
	return out, rows.Err()
}

// tinyUserAny renders the {id, name} projection for a nullable user reference.
func (s *Service) tinyUserAny(ctx context.Context, userID *int64) (any, error) {
	if userID == nil {
		return nil, nil
	}
	var name sql.NullString
	if err := s.Database.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, *userID).Scan(&name); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	return map[string]any{"id": *userID, "name": nullableStringRow(name)}, nil
}

func renderNullTime(t sql.NullTime) any {
	if !t.Valid {
		return nil
	}
	return renderTime(t.Time)
}
