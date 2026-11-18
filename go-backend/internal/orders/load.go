package orders

// loadOrder reads a persisted order back for rendering. It runs OUTSIDE the creation transaction (after commit)
// because the response must reflect committed state, exactly like `$order->fresh([...])` in the reference.

import (
	"context"
	"database/sql"
	"errors"
	"time"
)

func (s *Service) loadOrder(ctx context.Context, orderID, paymentTxnID int64) (*Order, error) {
	var (
		o                               Order
		subtotal, shippingFee, adminFee string
		discount, total, dp, paid       string
		remaining                       string
		villageSnap, districtSnap       sql.NullString
		regencySnap, provinceSnap       sql.NullString
		lat, lng                        sql.NullString
		provinceID, regencyID           sql.NullString
		districtID, villageID           sql.NullString
		postalCode                      sql.NullString
		deliveryDate                    sql.NullString
		deliveryActual                  sql.NullTime
		cancellationReason              sql.NullString
		invoiceIssuedAt                 sql.NullTime
		dueDate                         sql.NullString
		konsumenID, salesID, korsalID   sql.NullInt64
		placedBy                        sql.NullInt64
		buyerName, buyerPhone           sql.NullString
		crmCustomerID                   sql.NullInt64
		voucherID                       sql.NullInt64
		paymentMethodID                 sql.NullInt64
	)
	err := s.Database.QueryRowContext(ctx, `SELECT id, order_no, status, payment_status,
			CAST(subtotal_amount AS CHAR), CAST(shipping_fee_amount AS CHAR), CAST(admin_fee_amount AS CHAR),
			CAST(discount_amount AS CHAR), CAST(total_amount AS CHAR), CAST(dp_amount AS CHAR),
			CAST(paid_amount AS CHAR), CAST(remaining_amount AS CHAR), voucher_id,
			recipient_name_snapshot, recipient_phone_snapshot, address_snapshot,
			village_snapshot, district_snapshot, regency_snapshot, province_snapshot,
			CAST(latitude_snapshot AS CHAR), CAST(longitude_snapshot AS CHAR),
			province_id, regency_id, district_id, village_id, postal_code,
			CAST(delivery_date_estimate AS CHAR), delivery_date_actual, cancellation_reason,
			invoice_issued_at, CAST(due_date AS CHAR), konsumen_id, agent_id, sales_id, korsal_id,
			placed_by_user_id, buyer_name_snapshot, buyer_phone_snapshot, crm_customer_id,
			payment_method_id, created_at
		FROM orders WHERE id = ?`, orderID).
		Scan(&o.ID, &o.OrderNo, &o.Status, &o.PaymentStatus,
			&subtotal, &shippingFee, &adminFee, &discount, &total, &dp, &paid, &remaining, &voucherID,
			&o.RecipientName, &o.RecipientPhone, &o.Address,
			&villageSnap, &districtSnap, &regencySnap, &provinceSnap, &lat, &lng,
			&provinceID, &regencyID, &districtID, &villageID, &postalCode,
			&deliveryDate, &deliveryActual, &cancellationReason,
			&invoiceIssuedAt, &dueDate, &konsumenID, &o.AgentID, &salesID, &korsalID,
			&placedBy, &buyerName, &buyerPhone, &crmCustomerID,
			&paymentMethodID, &o.CreatedAt)
	if err != nil {
		return nil, err
	}

	o.Subtotal = parseString(subtotal)
	o.ShippingFee = parseString(shippingFee)
	o.AdminFee = parseString(adminFee)
	o.DiscountAmount = parseString(discount)
	o.Total = parseString(total)
	o.DPAmount = parseString(dp)
	o.PaidAmount = parseString(paid)
	o.RemainingAmount = parseString(remaining)
	o.VoucherID = nullIntPtr(voucherID)
	o.VillageSnapshot = nullStringPtr(villageSnap)
	o.DistrictSnapshot = nullStringPtr(districtSnap)
	o.RegencySnapshot = nullStringPtr(regencySnap)
	o.ProvinceSnapshot = nullStringPtr(provinceSnap)
	o.Latitude = nullFloatPtr(lat)
	o.Longitude = nullFloatPtr(lng)
	o.ProvinceID = nullStringPtr(provinceID)
	o.RegencyID = nullStringPtr(regencyID)
	o.DistrictID = nullStringPtr(districtID)
	o.VillageID = nullStringPtr(villageID)
	o.PostalCode = nullStringPtr(postalCode)
	o.DeliveryDate = nullStringPtr(deliveryDate)
	if deliveryActual.Valid {
		t := deliveryActual.Time
		o.DeliveryActual = &t
	}
	o.CancellationReason = nullStringPtr(cancellationReason)
	if invoiceIssuedAt.Valid {
		t := invoiceIssuedAt.Time
		o.InvoiceIssuedAt = &t
	}
	o.DueDate = nullStringPtr(dueDate)
	o.KonsumenID = nullIntPtr(konsumenID)
	o.SalesID = nullIntPtr(salesID)
	o.KorsalID = nullIntPtr(korsalID)
	o.PlacedByUserID = nullIntPtr(placedBy)
	o.BuyerNameSnapshot = nullStringPtr(buyerName)
	o.BuyerPhoneSnapshot = nullStringPtr(buyerPhone)
	o.CrmCustomerID = nullIntPtr(crmCustomerID)

	if paymentMethodID.Valid {
		var m PaymentMethod
		if err := s.Database.QueryRowContext(ctx, `SELECT id, code, name, type FROM payment_methods WHERE id = ?`,
			paymentMethodID.Int64).Scan(&m.ID, &m.Code, &m.Name, &m.Type); err == nil {
			o.PaymentMethod = &m
		}
	}

	items, err := s.loadItems(ctx, orderID)
	if err != nil {
		return nil, err
	}
	o.Items = items

	shipments, err := s.loadShipments(ctx, orderID)
	if err != nil {
		return nil, err
	}
	o.Shipments = shipments

	if paymentTxnID > 0 {
		var p PaymentTransaction
		var amount sql.NullString
		var reference sql.NullString
		err := s.Database.QueryRowContext(ctx, `SELECT id, type, CAST(amount AS CHAR), status, gateway_reference
			FROM payment_transactions WHERE id = ?`, paymentTxnID).
			Scan(&p.ID, &p.Type, &amount, &p.Status, &reference)
		if err == nil {
			p.Amount = parseString(amount.String)
			p.GatewayReference = nullStringPtr(reference)
			o.Payment = &p
		} else if !errors.Is(err, sql.ErrNoRows) {
			return nil, err
		}
	}
	return &o, nil
}

func (s *Service) loadItems(ctx context.Context, orderID int64) ([]Item, error) {
	rows, err := s.Database.QueryContext(ctx, `SELECT id, product_id, product_variation_id, stock_source, sub_location_id,
			product_name_snapshot, variation_label_snapshot, sku_snapshot,
			CAST(unit_price_snapshot AS CHAR), CAST(agent_fee_amount AS CHAR), CAST(sales_fee_amount AS CHAR),
			CAST(courier_fee_amount AS CHAR), CAST(subtotal_snapshot AS CHAR),
			original_quantity, fulfilled_quantity, cancelled_quantity, returned_quantity, refund_quantity,
			additional_quantity, status, CAST(requested_delivery_date AS CHAR), shipment_id
		FROM order_items WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Item
	for rows.Next() {
		var (
			it                        Item
			variation, subLocation    sql.NullInt64
			label, sku, requestedDate sql.NullString
			unit, agentFee, salesFee  sql.NullString
			courierFee, subtotal      sql.NullString
			shipmentID                sql.NullInt64
		)
		if err := rows.Scan(&it.ID, &it.ProductID, &variation, &it.StockSource, &subLocation,
			&it.ProductName, &label, &sku, &unit, &agentFee, &salesFee, &courierFee, &subtotal,
			&it.OriginalQuantity, &it.FulfilledQuantity, &it.CancelledQuantity, &it.ReturnedQuantity, &it.RefundQuantity,
			&it.AdditionalQuantity, &it.Status, &requestedDate, &shipmentID); err != nil {
			return nil, err
		}
		it.ProductVariationID = nullIntPtr(variation)
		it.SubLocationID = nullIntPtr(subLocation)
		it.VariationLabel = nullStringPtr(label)
		it.SKU = nullStringPtr(sku)
		it.UnitPrice = parseString(unit.String)
		it.AgentFee = parseString(agentFee.String)
		it.SalesFee = parseString(salesFee.String)
		it.CourierFee = parseString(courierFee.String)
		it.Subtotal = parseString(subtotal.String)
		it.RequestedDate = nullStringPtr(requestedDate)
		it.ShipmentID = nullIntPtr(shipmentID)
		out = append(out, it)
	}
	return out, rows.Err()
}

func (s *Service) loadShipments(ctx context.Context, orderID int64) ([]Shipment, error) {
	rows, err := s.Database.QueryContext(ctx, `SELECT id, courier_id, shipping_provider_id, shipping_provider_code,
			status, delivery_mode, self_delivered_by_user_id, CAST(distance_km AS CHAR),
			CAST(rate_per_km AS CHAR), CAST(shipping_fee_snapshot AS CHAR)
		FROM shipments WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []Shipment
	for rows.Next() {
		var (
			sh                             Shipment
			courierID, providerID, selfDel sql.NullInt64
			providerCode, distance         sql.NullString
			ratePerKm, fee                 sql.NullString
		)
		if err := rows.Scan(&sh.ID, &courierID, &providerID, &providerCode, &sh.Status, &sh.DeliveryMode,
			&selfDel, &distance, &ratePerKm, &fee); err != nil {
			return nil, err
		}
		sh.CourierID = nullIntPtr(courierID)
		sh.ShippingProviderID = nullIntPtr(providerID)
		sh.ShippingProviderCode = nullStringPtr(providerCode)
		sh.SelfDeliveredBy = nullIntPtr(selfDel)
		sh.DistanceKm = nullFloatPtr(distance)
		sh.RatePerKm = nullFloatPtr(ratePerKm)
		sh.ShippingFeeSnapshot = parseString(fee.String)
		out = append(out, sh)
	}
	return out, rows.Err()
}

func nullFloatPtr(v sql.NullString) *float64 {
	if !v.Valid {
		return nil
	}
	f := parseString(v.String)
	return &f
}

var _ = time.Time{}
