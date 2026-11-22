package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/document"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/money"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleOrderInvoice(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	orderID, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	actor := UserFrom(ctx)
	service := s.orderService()
	order, err := service.LoadOrderForRead(ctx, orderID, true)
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	allowed, err := service.CanView(ctx, orders.RoleScopes{Role: actor.Role, UserID: actor.ID, AgentID: actor.AgentID}, orderID)
	if err != nil || !allowed {
		if err != nil {
			writeError(w, r, orderError(ctx, err))
		} else {
			writeError(w, r, forbiddenError(ctx))
		}
		return
	}
	isOwner := order.KonsumenID != nil && *order.KonsumenID == actor.ID
	if !isOwner && actor.Role != "super_admin" && actor.Role != "agen" && actor.Role != "admin" && actor.Role != "keuangan" && actor.Role != "sales" && actor.Role != "korsal" {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	issued, err := service.EnsureInvoiceIssued(ctx, orderID, actor.ID)
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	if !issued {
		writeError(w, r, httpx.NewError(http.StatusConflict, msg(ctx, "payment.invoice_not_issued")))
		return
	}
	order, err = service.LoadOrderForRead(ctx, orderID, true)
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	data, err := s.buildInvoiceDocument(ctx, order, isOwner)
	if err != nil {
		writeError(w, r, err)
		return
	}
	pdf, err := document.InvoicePDF(data)
	if err != nil {
		writeError(w, r, err)
		return
	}
	w.Header().Set("Content-Type", "application/pdf")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`inline; filename="invoice-%s.pdf"`, order.OrderNo))
	w.Header().Set("Cache-Control", "no-store, private")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(pdf)
}

func (s *Server) buildInvoiceDocument(ctx context.Context, order *orders.Order, isOwner bool) (document.Invoice, error) {
	config, err := s.invoiceConfig(ctx, order.AgentID)
	if err != nil {
		return document.Invoice{}, err
	}
	status := "Belum Dibayar"
	if order.PaymentStatus == "paid" {
		status = "LUNAS"
	} else if order.PaidAmount > 0 {
		status = "Dibayar Sebagian"
	} else if order.PaymentStatus == "pending_verification" {
		status = "Menunggu Verifikasi"
	}
	items := make([]document.InvoiceItem, 0, len(order.Items))
	for _, item := range order.Items {
		qty := item.FulfilledQuantity
		items = append(items, document.InvoiceItem{Name: item.ProductName, Variation: invoicePtrString(item.VariationLabel), Quantity: qty,
			Price: invoiceRupiah(item.UnitPrice), Total: invoiceRupiah(item.UnitPrice * float64(qty))})
	}
	paymentMethod := ""
	if order.PaymentMethod != nil {
		paymentMethod = order.PaymentMethod.Name
	}
	paymentStatus := status
	if order.PaymentMethod != nil && order.PaymentMethod.Type == "cod" && order.PaymentStatus != "paid" {
		if order.PaidAmount > 0 {
			paymentStatus = "Dibayar Sebagian"
		} else {
			paymentStatus = "Belum Dibayar (dibayar saat barang diterima)"
		}
	}
	dp := ""
	if order.DPAmount > 0 {
		amount := order.DPAmount
		if amount > order.PaidAmount {
			amount = order.PaidAmount
		}
		dp = invoiceRupiah(amount)
	}
	var salesName, korsalName string
	if !isOwner && order.SalesID != nil {
		_ = s.database.QueryRowContext(ctx, `SELECT name FROM users WHERE id=?`, *order.SalesID).Scan(&salesName)
	}
	if !isOwner && order.KorsalID != nil {
		_ = s.database.QueryRowContext(ctx, `SELECT name FROM users WHERE id=?`, *order.KorsalID).Scan(&korsalName)
	}
	logoBytes, logoType := s.invoiceLogo(ctx, config, order.AgentID)
	return document.Invoice{Number: order.OrderNo, IssuedAt: formatInvoiceTime(order.InvoiceIssuedAt),
		RecipientName: order.RecipientName, RecipientPhone: order.RecipientPhone, RecipientAddress: invoiceAddress(order),
		Subtotal: invoiceRupiah(order.Subtotal), Shipping: invoiceRupiah(order.ShippingFee), AdminFee: invoiceRupiah(order.AdminFee),
		Voucher: invoiceRupiah(order.DiscountAmount), Total: invoiceRupiah(order.Total), Paid: invoiceRupiah(order.PaidAmount), Remaining: invoiceRupiah(order.RemainingAmount),
		CompanyName: invoiceString(config, "company_name", "PrimeClassy"), Title: invoiceString(config, "title", "INVOICE"),
		Contact: invoiceString(config, "contact", ""), CompanyAddress: invoiceString(config, "address", ""), Footer: invoiceString(config, "footer", "Terima kasih atas kepercayaan Anda."),
		Notes: invoiceString(config, "notes", ""), OrderDate: order.CreatedAt.In(time.FixedZone("WIB", 7*3600)).Format("02-01-2006 15:04"), Status: status,
		PaymentMethod: paymentMethod, PaymentStatus: paymentStatus, ShowRecipient: invoiceBool(config, "show_konsumen", true),
		ShowItems: invoiceBool(config, "show_items", true), ShowPayment: invoiceBool(config, "show_payment_summary", true),
		ShowInternalReferral: !isOwner, ShowSales: invoiceBool(config, "show_sales", true), ShowKorsal: invoiceBool(config, "show_korsal", true),
		SalesName: salesName, KorsalName: korsalName, LogoBytes: logoBytes, LogoType: logoType, DP: dp,
		ShowVoucher: invoiceBool(config, "show_voucher", true), VoucherLabel: func() string {
			if order.VoucherID != nil && order.DiscountAmount > 0 {
				return "Voucher diterapkan: diskon " + invoiceRupiah(order.DiscountAmount)
			}
			return ""
		}(), Items: items,
	}, nil
}

func (s *Server) invoiceLogo(ctx context.Context, config map[string]any, agentID int64) ([]byte, string) {
	var mediaID int64
	switch v := config["logo_media_id"].(type) {
	case float64:
		mediaID = int64(v)
	case int64:
		mediaID = v
	case int:
		mediaID = int64(v)
	}
	if mediaID <= 0 || s.cfg.MediaRoot == "" {
		return nil, ""
	}
	var disk, path, mimeType, role string
	var uploaderAgent sql.NullInt64
	err := s.database.QueryRowContext(ctx, `SELECT m.disk,m.path,m.mime_type,r.slug,u.agent_id FROM media m LEFT JOIN users u ON u.id=m.created_by LEFT JOIN roles r ON r.id=u.role_id WHERE m.id=?`, mediaID).Scan(&disk, &path, &mimeType, &role, &uploaderAgent)
	if err != nil || disk != "public" || !invoiceContainsString([]string{"image/png", "image/jpeg", "image/gif"}, mimeType) {
		return nil, ""
	}
	if role != "super_admin" && (role != "agen" || !uploaderAgent.Valid || uploaderAgent.Int64 != agentID) {
		return nil, ""
	}
	clean := filepath.Clean(path)
	if filepath.IsAbs(clean) || clean == ".." || strings.HasPrefix(clean, ".."+string(filepath.Separator)) {
		return nil, ""
	}
	full := filepath.Join(s.cfg.MediaRoot, clean)
	raw, err := os.ReadFile(full)
	if err != nil {
		return nil, ""
	}
	actual := http.DetectContentType(raw)
	parsed, _, _ := mime.ParseMediaType(actual)
	if parsed != mimeType {
		return nil, ""
	}
	typeName := map[string]string{"image/png": "png", "image/jpeg": "jpeg", "image/gif": "gif"}[mimeType]
	return raw, typeName
}

func invoiceContainsString(values []string, target string) bool {
	for _, v := range values {
		if v == target {
			return true
		}
	}
	return false
}

func (s *Server) invoiceConfig(ctx context.Context, agentID int64) (map[string]any, error) {
	defaults := map[string]any{"company_name": "PrimeClassy", "title": "INVOICE", "contact": "", "address": "", "footer": "Terima kasih atas kepercayaan Anda.", "notes": "", "show_items": true, "show_konsumen": true, "show_sales": true, "show_korsal": true, "show_payment_summary": true, "show_voucher": true, "logo_media_id": nil}
	for _, id := range []int64{0, agentID} {
		var raw sql.NullString
		err := s.database.QueryRowContext(ctx, `SELECT CAST(config AS CHAR) FROM invoice_configs WHERE agent_id=?`, id).Scan(&raw)
		if errors.Is(err, sql.ErrNoRows) {
			continue
		}
		if err != nil {
			return nil, err
		}
		if raw.Valid {
			var value map[string]any
			if json.Unmarshal([]byte(raw.String), &value) == nil {
				for k, v := range value {
					defaults[k] = v
				}
			}
		}
	}
	return defaults, nil
}

func (s *Server) handleInvoiceConfigShow(w http.ResponseWriter, r *http.Request) {
	actor := UserFrom(r.Context())
	scope := int64(0)
	if actor.Role != "super_admin" {
		if actor.AgentID == nil {
			writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "Tidak diizinkan."})
			return
		}
		scope = *actor.AgentID
	}
	config, err := s.invoiceConfig(r.Context(), scope)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, config, "OK", nil)
}

func (s *Server) handleInvoiceConfigUpdate(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	actor := UserFrom(ctx)
	scope := int64(0)
	if actor.Role != "super_admin" {
		if actor.AgentID == nil {
			writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: "Tidak diizinkan."})
			return
		}
		scope = *actor.AgentID
	}
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	clean := map[string]any{}
	fields := map[string][]string{}
	for _, f := range []struct {
		name string
		max  int
	}{{"company_name", 120}, {"title", 60}, {"contact", 255}, {"address", 500}, {"footer", 500}, {"notes", 500}} {
		raw, ok := body[f.name]
		if !ok {
			continue
		}
		if string(raw) == "null" {
			clean[f.name] = nil
			continue
		}
		var text string
		if json.Unmarshal(raw, &text) != nil {
			fields[f.name] = []string{i18n.ValidationMessage(locale, "string", f.name)}
			continue
		}
		if utf8.RuneCountInString(text) > f.max {
			fields[f.name] = []string{i18n.ValidationMessage(locale, "max.string", f.name, "max", strconv.Itoa(f.max))}
			continue
		}
		clean[f.name] = text
	}
	for _, name := range []string{"show_items", "show_konsumen", "show_sales", "show_korsal", "show_payment_summary", "show_voucher"} {
		raw, ok := body[name]
		if !ok {
			continue
		}
		if string(raw) == "null" {
			clean[name] = nil
			continue
		}
		var value any
		if json.Unmarshal(raw, &value) != nil {
			fields[name] = []string{i18n.ValidationMessage(locale, "boolean", name)}
			continue
		}
		valid := false
		switch v := value.(type) {
		case bool:
			valid = true
		case float64:
			valid = v == 0 || v == 1
		case string:
			valid = v == "0" || v == "1"
		}
		if !valid {
			fields[name] = []string{i18n.ValidationMessage(locale, "boolean", name)}
			continue
		}
		clean[name] = value
	}
	if raw, ok := body["logo_media_id"]; ok && string(raw) == "null" {
		clean["logo_media_id"] = nil
	} else if ok {
		var id int64
		var text string
		var err error
		if json.Unmarshal(raw, &text) == nil {
			id, err = strconv.ParseInt(strings.TrimSpace(text), 10, 64)
		} else {
			err = json.Unmarshal(raw, &id)
		}
		if err != nil {
			fields["logo_media_id"] = []string{i18n.ValidationMessage(locale, "integer", "logo_media_id")}
		} else {
			var exists int
			if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM media WHERE id=?`, id).Scan(&exists); err != nil {
				writeError(w, r, err)
				return
			}
			if exists == 0 {
				fields["logo_media_id"] = []string{i18n.ValidationMessage(locale, "exists", "logo_media_id")}
			} else {
				clean["logo_media_id"] = id
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	raw, err := json.Marshal(clean)
	if err != nil {
		writeError(w, r, err)
		return
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO invoice_configs(agent_id,config,created_at,updated_at) VALUES (?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE config=VALUES(config),updated_at=UTC_TIMESTAMP(6)`, scope, string(raw))
	if err != nil {
		writeError(w, r, err)
		return
	}
	config, err := s.invoiceConfig(ctx, scope)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, config, "OK", nil)
}

func invoiceString(c map[string]any, k, def string) string {
	if v, ok := c[k].(string); ok {
		return v
	}
	return def
}

func invoicePtrString(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}
func invoiceBool(c map[string]any, k string, def bool) bool {
	if v, ok := c[k].(bool); ok {
		return v
	}
	return def
}
func invoiceRupiah(v float64) string {
	n := int64(money.Round2(v))
	raw := strconv.FormatInt(n, 10)
	out := ""
	for i, ch := range raw {
		if i > 0 && (len(raw)-i)%3 == 0 {
			out += "."
		}
		out += string(ch)
	}
	return "Rp " + out
}
func formatInvoiceTime(v *time.Time) string {
	if v == nil {
		return ""
	}
	return v.In(time.FixedZone("WIB", 7*3600)).Format("02-01-2006 15:04")
}
func invoiceAddress(o *orders.Order) string {
	out := o.Address
	for _, v := range []*string{o.VillageSnapshot, o.DistrictSnapshot, o.RegencySnapshot, o.ProvinceSnapshot} {
		if v != nil && *v != "" {
			if out != "" {
				out += ", "
			}
			out += *v
		}
	}
	return out
}
