package api

import (
	"encoding/json"
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/promo"
)

// writePromoError maps a promo-domain error onto the envelope.
func writePromoError(w http.ResponseWriter, r *http.Request, err error) {
	ctx := r.Context()
	var de *promo.DomainError
	if errors.As(err, &de) {
		httpx.Failure(w, de.Status, de.Message, nil)
		return
	}
	if errors.Is(err, promo.ErrNotFound) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	writeError(w, r, err)
}

// promoActor builds the promo actor from the authenticated user.
func promoActor(u *auth.User) promo.Actor {
	a := promo.Actor{ID: u.ID, Role: u.Role}
	if u.AgentID != nil {
		a.AgentID = *u.AgentID
	}
	return a
}

// remarshal re-decodes a generic body into a typed payload (the raw map is needed to know which keys were
// SENT, which is what Laravel's `sometimes` rules depend on).
func remarshal(raw map[string]any, dst any) error {
	buf, err := json.Marshal(raw)
	if err != nil {
		return err
	}
	if err := json.Unmarshal(buf, dst); err != nil {
		return httpx.Validation(map[string][]string{"body": {"invalid"}})
	}
	return nil
}

type voucherPayload struct {
	AgentID     *int64   `json:"agent_id"`
	Code        *string  `json:"code"`
	Name        *string  `json:"name"`
	Type        *string  `json:"type"`
	Value       *float64 `json:"value"`
	IsActive    *bool    `json:"is_active"`
	ValidFrom   *string  `json:"valid_from"`
	ValidUntil  *string  `json:"valid_until"`
	ProductID   *int64   `json:"product_id"`
	VariationID *int64   `json:"product_variation_id"`
	MaxUses     *int64   `json:"max_uses"`
}

// present reports whether a JSON key was supplied (needed for the "sometimes" update rules).
func (p voucherPayload) present(raw map[string]any) map[string]bool {
	out := map[string]bool{}
	for k := range raw {
		out[k] = true
	}
	return out
}

// validateVoucher reproduces VoucherController::validate / validateUpdate.
func validateVoucher(r *http.Request, p voucherPayload, fields map[string]bool, partial bool) map[string][]string {
	ctx := r.Context()
	out := map[string][]string{}
	sent := func(name string) bool { return !partial || fields[name] }

	if sent("code") {
		if p.Code == nil || (partial && *p.Code == "") {
			if !partial {
				out["code"] = requiredField(ctx, "code")
			}
		} else if len(*p.Code) > 60 {
			out["code"] = maxString(ctx, "code", 60)
		}
	}
	if sent("name") {
		if p.Name == nil || (partial && *p.Name == "") {
			if !partial {
				out["name"] = requiredField(ctx, "name")
			}
		} else if len(*p.Name) > 120 {
			out["name"] = maxString(ctx, "name", 120)
		}
	}
	if sent("type") {
		if !partial || p.Type != nil {
			if p.Type == nil || (*p.Type != "percentage" && *p.Type != "fixed") {
				out["type"] = invalidChoice(ctx, "type")
			}
		}
	}
	if sent("value") {
		if !partial || p.Value != nil {
			if p.Value == nil || *p.Value < 0.01 {
				out["value"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", i18n.AttributeName(LocaleFrom(ctx), "value"), "min", "0.01")}
			}
		}
	}
	if sent("max_uses") && p.MaxUses != nil && *p.MaxUses < 1 {
		out["max_uses"] = minNumeric(ctx, "max_uses", 1)
	}
	return out
}

// handleVoucherIndex is GET /promo/vouchers.
func (s *Server) handleVoucherIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	explicit := optionalInt(r, "agent_id")
	rows, page, err := promo.ListVouchers(ctx, s.database, promoActor(u),
		explicit, httpx.QueryInt(r, "page", 1, 1), httpx.QueryInt(r, "per_page", 20, 1))
	if err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", page)
}

// handleVoucherStore is POST /promo/vouchers.
func (s *Server) handleVoucherStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	raw := map[string]any{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var p voucherPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
		return
	}
	fields := p.present(raw)
	if v := validateVoucher(r, p, fields, false); len(v) > 0 {
		writeError(w, r, httpx.Validation(v))
		return
	}
	row, err := promo.CreateVoucher(ctx, s.database, promoActor(u),
		agentParam(r, p.AgentID), promo.VoucherInput{
			Code: p.Code, Name: p.Name, Type: p.Type, Value: p.Value, IsActive: p.IsActive,
			ValidFrom: p.ValidFrom, ValidUntil: p.ValidUntil, ProductID: p.ProductID,
			VariationID: p.VariationID, MaxUses: p.MaxUses,
		})
	if err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.Created(w, row, "Created")
}

// handleVoucherUpdate is PATCH /promo/vouchers/{voucher}.
func (s *Server) handleVoucherUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "voucher")
	if !ok {
		return
	}
	raw := map[string]any{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var p voucherPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
		return
	}
	fields := p.present(raw)
	if v := validateVoucher(r, p, fields, true); len(v) > 0 {
		writeError(w, r, httpx.Validation(v))
		return
	}
	row, err := promo.UpdateVoucher(ctx, s.database, promoActor(u),
		id, agentParam(r, p.AgentID), promo.VoucherInput{
			Code: p.Code, Name: p.Name, Type: p.Type, Value: p.Value, IsActive: p.IsActive,
			ValidFrom: p.ValidFrom, ValidUntil: p.ValidUntil, ProductID: p.ProductID,
			VariationID: p.VariationID, MaxUses: p.MaxUses,
			SetProduct: fields["product_id"], SetVariation: fields["product_variation_id"],
			SetMaxUses: fields["max_uses"], SetValidFrom: fields["valid_from"],
			SetValidUntil: fields["valid_until"], SetIsActive: fields["is_active"],
		})
	if err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleVoucherDestroy is DELETE /promo/vouchers/{voucher}.
func (s *Server) handleVoucherDestroy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "voucher")
	if !ok {
		return
	}
	if err := promo.DeleteVoucher(ctx, s.database, promoActor(u),
		id, optionalInt(r, "agent_id")); err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.OK(w, nil, "Voucher dihapus.", nil)
}

type discountPayload struct {
	AgentID     *int64  `json:"agent_id"`
	Name        *string `json:"name"`
	ProductID   *int64  `json:"product_id"`
	VariationID *int64  `json:"product_variation_id"`
	Percentage  *int64  `json:"percentage"`
	IsActive    *bool   `json:"is_active"`
	StartsAt    *string `json:"starts_at"`
	EndsAt      *string `json:"ends_at"`
}

// handleDiscountIndex is GET /promo/discounts.
func (s *Server) handleDiscountIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	rows, page, err := promo.ListDiscounts(ctx, s.database,
		promoActor(u), optionalInt(r, "agent_id"),
		httpx.QueryInt(r, "page", 1, 1), httpx.QueryInt(r, "per_page", 20, 1))
	if err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", page)
}

// handleDiscountStore is POST /promo/discounts.
func (s *Server) handleDiscountStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	var p discountPayload
	if err := httpx.DecodeJSON(r, &p); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if p.Name == nil || *p.Name == "" {
		fields["name"] = requiredField(ctx, "name")
	} else if len(*p.Name) > 120 {
		fields["name"] = maxString(ctx, "name", 120)
	}
	if p.Percentage == nil {
		fields["percentage"] = requiredField(ctx, "percentage")
	} else if *p.Percentage < 1 {
		fields["percentage"] = minNumeric(ctx, "percentage", 1)
	} else if *p.Percentage > 100 {
		fields["percentage"] = maxNumeric(ctx, "percentage", 100)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := promo.CreateDiscount(ctx, s.database,
		promoActor(u), p.AgentID,
		promo.DiscountInput{Name: p.Name, ProductID: p.ProductID, VariationID: p.VariationID,
			Percentage: p.Percentage, IsActive: p.IsActive, StartsAt: p.StartsAt, EndsAt: p.EndsAt})
	if err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.Created(w, row, "Created")
}

// handleDiscountUpdate is PATCH /promo/discounts/{discount}.
func (s *Server) handleDiscountUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "discount")
	if !ok {
		return
	}
	raw := map[string]any{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var p discountPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if p.Percentage != nil && *p.Percentage < 1 {
		fields["percentage"] = minNumeric(ctx, "percentage", 1)
	} else if p.Percentage != nil && *p.Percentage > 100 {
		fields["percentage"] = maxNumeric(ctx, "percentage", 100)
	}
	if p.Name != nil && len(*p.Name) > 120 {
		fields["name"] = maxString(ctx, "name", 120)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := promo.UpdateDiscount(ctx, s.database,
		promoActor(u), id, nil,
		promo.DiscountInput{Name: p.Name, ProductID: p.ProductID, VariationID: p.VariationID,
			Percentage: p.Percentage, IsActive: p.IsActive, StartsAt: p.StartsAt, EndsAt: p.EndsAt,
			SetProduct: hasKey(raw, "product_id"), SetVariation: hasKey(raw, "product_variation_id"),
			SetIsActive: hasKey(raw, "is_active"), SetStartsAt: hasKey(raw, "starts_at"),
			SetEndsAt: hasKey(raw, "ends_at")})
	if err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleDiscountDestroy is DELETE /promo/discounts/{discount}.
func (s *Server) handleDiscountDestroy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "discount")
	if !ok {
		return
	}
	if err := promo.DeleteDiscount(ctx, s.database,
		promoActor(u), id, nil); err != nil {
		writePromoError(w, r, err)
		return
	}
	httpx.OK(w, nil, "Discount dihapus.", nil)
}

// ---- helpers ---------------------------------------------------------------------------------------

func hasKey(raw map[string]any, key string) bool {
	_, ok := raw[key]
	return ok
}

// optionalInt reads an integer query parameter.
func optionalInt(r *http.Request, name string) *int64 {
	v := httpx.QueryString(r, name)
	if v == "" {
		return nil
	}
	n, err := strconv.ParseInt(v, 10, 64)
	if err != nil {
		return nil
	}
	return &n
}

// agentParam resolves the agent_id the reference reads via $request->integer('agent_id'), which checks the
// request input first and the query string second.
func agentParam(r *http.Request, fromBody *int64) *int64 {
	if fromBody != nil && *fromBody > 0 {
		return fromBody
	}
	return optionalInt(r, "agent_id")
}
