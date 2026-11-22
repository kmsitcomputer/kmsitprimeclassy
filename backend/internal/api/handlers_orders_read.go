package api

// Order read surface handlers.
//
//	GET /orders           role-scoped list
//	GET /orders/regions   region facets derived from the caller's own scoped orders
//	GET /orders/{order}   policy-gated detail

import (
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// roleScopesOf reduces the authenticated user to the read-surface scope.
func roleScopesOf(u *auth.User) orders.RoleScopes {
	return orders.RoleScopes{Role: u.Role, UserID: u.ID, AgentID: u.AgentID}
}

// handleOrderIndex is GET /orders.
func (s *Server) handleOrderIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	scope := roleScopesOf(actor)

	perPage, _ := strconv.Atoi(httpx.QueryString(r, "per_page"))
	page, _ := strconv.Atoi(httpx.QueryString(r, "page"))
	filter := orders.OrderFilter{
		Status:        httpx.QueryString(r, "status"),
		PaymentStatus: httpx.QueryString(r, "payment_status"),
		Search:        httpx.QueryString(r, "search"),
		ProvinceID:    httpx.QueryString(r, "province_id"),
		RegencyID:     httpx.QueryString(r, "regency_id"),
		DistrictID:    httpx.QueryString(r, "district_id"),
		VillageID:     httpx.QueryString(r, "village_id"),
		DeliveryDate:  httpx.QueryString(r, "delivery_date"),
		PerPage:       perPage,
		Page:          page,
	}
	// Operational roles never get the money bucket (the reference removes the param).
	if paidRaw := httpx.QueryString(r, "paid"); paidRaw != "" && scope.SeesFinancials() {
		paid := paidRaw == "paid"
		filter.Paid = &paid
	}

	service := s.orderService()
	var (
		ids   []int64
		total int
		err   error
	)
	if scope.Role == "super_admin" && httpx.QueryString(r, "agent_id") != "" {
		agentID, aerr := strconv.ParseInt(httpx.QueryString(r, "agent_id"), 10, 64)
		if aerr == nil {
			ids, total, err = service.ListOrdersScopedToAgent(ctx, scope, filter, agentID)
		}
	} else {
		ids, total, err = service.ListOrders(ctx, scope, filter)
	}
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}

	data := make([]any, 0, len(ids))
	viewer := viewerOf(actor)
	for _, id := range ids {
		order, lerr := service.LoadOrderForRead(ctx, id, false)
		if lerr != nil {
			writeError(w, r, orderError(ctx, lerr))
			return
		}
		rendered, rerr := orders.Render(ctx, service, order, viewer)
		if rerr != nil {
			writeError(w, r, rerr)
			return
		}
		data = append(data, rendered)
	}
	if perPage < 1 {
		perPage = 15
	}
	current := page
	if current < 1 {
		current = 1
	}
	last := (total + perPage - 1) / perPage
	if last < 1 {
		last = 1
	}
	httpx.OK(w, data, "OK", httpx.NewMeta(current, last, total))
}

// handleOrderShow is GET /orders/{order}.
func (s *Server) handleOrderShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "order")
	if !ok {
		return
	}
	service := s.orderService()
	allowed, err := service.CanView(ctx, roleScopesOf(actor), id)
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	if !allowed {
		// Model binding 404: a policy denial is indistinguishable from "not found".
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		return
	}
	order, err := service.LoadOrderForRead(ctx, id, true)
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	rendered, rerr := orders.Render(ctx, service, order, viewerOf(actor))
	if rerr != nil {
		writeError(w, r, rerr)
		return
	}
	httpx.OK(w, rendered, "OK", nil)
}

// handleOrderRegions is GET /orders/regions.
func (s *Server) handleOrderRegions(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	options, err := s.orderService().OrderRegions(ctx, roleScopesOf(actor),
		httpx.QueryString(r, "province_id"), httpx.QueryString(r, "regency_id"), httpx.QueryString(r, "district_id"))
	if err != nil {
		writeError(w, r, orderError(ctx, err))
		return
	}
	if options.Regencies == nil {
		options.Regencies = []orders.RegionOption{}
	}
	if options.Districts == nil {
		options.Districts = []orders.RegionOption{}
	}
	if options.Villages == nil {
		options.Villages = []orders.RegionOption{}
	}
	httpx.OK(w, map[string]any{
		"provinces": options.Provinces,
		"regencies": options.Regencies,
		"districts": options.Districts,
		"villages":  options.Villages,
	}, "OK", nil)
}
