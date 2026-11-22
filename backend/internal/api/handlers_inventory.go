package api

import (
	"context"
	"database/sql"
	"errors"
	"net/http"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// viewedAgentID resolves the branch whose stock is being read (StockController::resolveViewedAgentId).
//
//   - super_admin MUST name an Agent explicitly (`?agent_id=`); there is no default. Missing → 422
//     `stock.agent_id_required`, non-Agent → 422 `stock.invalid_agent`, mirroring the Laravel messages.
//   - everyone else is confined to their own branch. The agent-linked middleware has already rejected a
//     non-super_admin without an agent link, so AgentID is non-nil here.
func (s *Server) viewedAgentID(w http.ResponseWriter, r *http.Request) (int64, bool) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return 0, false
	}
	if !u.IsRole(auth.RoleSuperAdmin) {
		if u.AgentID == nil {
			writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.agent_not_linked")))
			return 0, false
		}
		return *u.AgentID, true
	}

	raw := httpx.QueryString(r, "agent_id")
	if raw == "" {
		writeError(w, r, &httpx.Error{
			Status:  http.StatusUnprocessableEntity,
			Message: msg(ctx, "stock.agent_id_required"),
			Fields:  map[string][]string{"agent_id": {msg(ctx, "system.field_required")}},
		})
		return 0, false
	}
	id, err := strconv.ParseInt(raw, 10, 64)
	if err != nil {
		writeError(w, r, invalidAgent(ctx))
		return 0, false
	}
	var isAgent bool
	if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(
		SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND r.slug = 'agen' AND u.deleted_at IS NULL)`, id).Scan(&isAgent); err != nil {
		writeError(w, r, err)
		return 0, false
	}
	if !isAgent {
		writeError(w, r, invalidAgent(ctx))
		return 0, false
	}
	return id, true
}

func invalidAgent(ctx context.Context) *httpx.Error {
	return &httpx.Error{
		Status:  http.StatusUnprocessableEntity,
		Message: msg(ctx, "stock.invalid_agent"),
		Fields:  map[string][]string{"agent_id": {msg(ctx, "system.field_invalid")}},
	}
}

// stockPage reads the pagination inputs shared by these endpoints (per_page default 15, min 1).
func stockPage(r *http.Request, defaultPerPage int) (page, perPage int) {
	return httpx.QueryInt(r, "page", 1, 1), httpx.QueryInt(r, "per_page", defaultPerPage, 1)
}

// handleStockProducts is GET /stock/products.
func (s *Server) handleStockProducts(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.viewedAgentID(w, r)
	if !ok {
		return
	}
	page, perPage := stockPage(r, 15)
	result, err := inventory.ListStockProducts(ctx, s.database, agentID, page, perPage)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Rows, "OK", result.Page)
}

// handleStockVariations is GET /stock/variations.
func (s *Server) handleStockVariations(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.viewedAgentID(w, r)
	if !ok {
		return
	}
	page, perPage := stockPage(r, 15)
	result, err := inventory.ListStockVariations(ctx, s.database, agentID, page, perPage)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Rows, "OK", result.Page)
}

// handleWarehouseStock is GET /warehouse-stock.
func (s *Server) handleWarehouseStock(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.viewedAgentID(w, r)
	if !ok {
		return
	}
	page, perPage := stockPage(r, 30)
	result, err := inventory.ListWarehouseStock(ctx, s.database, agentID, inventory.WarehouseStockFilter{
		StockType: httpx.QueryString(r, "stock_type"),
		Search:    httpx.QueryString(r, "search"),
		Page:      page,
		PerPage:   perPage,
	})
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Rows, "OK", result.Page)
}

// handleWarehouseSellable is GET /warehouse/sellable — the canonical availability projection for one target.
//
// PARITY NOTE: Laravel answers 500 ("Undefined array key \"product_id\"") when neither parameter is given,
// because `$request->validate()` only returns the keys that were present and the controller then indexes the
// missing one. That is an upstream defect, not a contract: this implementation validates the pair properly and
// answers 422. The difference is recorded in docs/21 §5 and is the ONLY place where Go is intentionally
// stricter than the reference.
func (s *Server) handleWarehouseSellable(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	productRaw := httpx.QueryString(r, "product_id")
	variationRaw := httpx.QueryString(r, "variation_id")
	if productRaw == "" && variationRaw == "" {
		writeError(w, r, httpx.Validation(map[string][]string{"product_id": requiredField(ctx, "product_id")}))
		return
	}

	var result inventory.Sellable
	var err error
	if variationRaw != "" {
		id, parseErr := strconv.ParseInt(variationRaw, 10, 64)
		if parseErr != nil {
			writeError(w, r, httpx.Validation(map[string][]string{"variation_id": invalidField(ctx, "variation_id")}))
			return
		}
		result, err = inventory.ForVariation(ctx, s.database, *u.AgentID, id)
	} else {
		id, parseErr := strconv.ParseInt(productRaw, 10, 64)
		if parseErr != nil {
			writeError(w, r, httpx.Validation(map[string][]string{"product_id": invalidField(ctx, "product_id")}))
			return
		}
		result, err = inventory.ForProduct(ctx, s.database, *u.AgentID, id)
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

// handleWarehouseSettings is GET /warehouse/settings.
//
// Laravel's `firstOrCreate` means this READ creates the row on first access. That side effect is reproduced
// (it is idempotent and it is the only way the response can carry id/created_at), and documented in docs/21.
func (s *Server) handleWarehouseSettings(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil {
		writeError(w, r, forbiddenError(ctx))
		return
	}

	var (
		id         int64
		agentID    int64
		enabled    int
		createdRaw sql.NullString
		updatedRaw sql.NullString
	)
	err := s.database.QueryRowContext(ctx, `SELECT id, agent_id, factory_plan_enabled,
			CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
		FROM warehouse_settings WHERE agent_id = ? LIMIT 1`, *u.AgentID).
		Scan(&id, &agentID, &enabled, &createdRaw, &updatedRaw)
	if errors.Is(err, sql.ErrNoRows) {
		res, insErr := s.database.ExecContext(ctx, `INSERT INTO warehouse_settings
			(agent_id, factory_plan_enabled, created_at, updated_at)
			VALUES (?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, *u.AgentID)
		if insErr != nil {
			writeError(w, r, insErr)
			return
		}
		id, _ = res.LastInsertId()
		agentID, enabled = *u.AgentID, 0
		if err := s.database.QueryRowContext(ctx, `SELECT CAST(created_at AS CHAR), CAST(updated_at AS CHAR)
			FROM warehouse_settings WHERE id = ?`, id).Scan(&createdRaw, &updatedRaw); err != nil {
			writeError(w, r, err)
			return
		}
	} else if err != nil {
		writeError(w, r, err)
		return
	}

	httpx.OK(w, map[string]any{
		"id":                   id,
		"agent_id":             agentID,
		"factory_plan_enabled": enabled == 1,
		"created_at":           formatMySQLTime(createdRaw),
		"updated_at":           formatMySQLTime(updatedRaw),
	}, "OK", nil)
}

// handleSubLocations is GET /warehouse/sub-locations.
func (s *Server) handleSubLocations(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.viewedAgentID(w, r)
	if !ok {
		return
	}
	rows, err := inventory.ListSubLocations(ctx, s.database, agentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

// handleSubLocationShow is GET /warehouse/sub-locations/{subLocation}.
func (s *Server) handleSubLocationShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.viewedAgentID(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "subLocation")
	if !ok {
		return
	}
	row, err := inventory.SubLocationByID(ctx, s.database, agentID, id)
	if errors.Is(err, inventory.ErrNotFound) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleSubLocationStocks is GET /warehouse/sub-locations/{subLocation}/stocks.
func (s *Server) handleSubLocationStocks(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agentID, ok := s.viewedAgentID(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "subLocation")
	if !ok {
		return
	}
	rows, err := inventory.SubLocationStocks(ctx, s.database, agentID, id)
	if errors.Is(err, inventory.ErrNotFound) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

// handleStockCards is GET /warehouse/stock-cards.
func (s *Server) handleStockCards(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	page := httpx.QueryInt(r, "page", 1, 1)
	perPage := httpx.QueryInt(r, "per_page", 12, 1)
	if perPage > 50 {
		perPage = 50
	}
	result, err := inventory.StockCards(ctx, s.database, *u.AgentID, httpx.QueryString(r, "search"), page, perPage)
	if err != nil {
		writeError(w, r, err)
		return
	}
	// This endpoint's meta DOES include per_page (unlike the stock lists) — verified against the reference.
	httpx.OK(w, result.Cards, "OK", map[string]any{
		"current_page": result.Page.CurrentPage,
		"last_page":    result.Page.LastPage,
		"total":        result.Page.Total,
		"per_page":     result.PerPage,
	})
}

// handleSubLocationCards is GET /warehouse/sub-locations/{subLocation}/stock-cards.
func (s *Server) handleSubLocationCards(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	id, ok := pathID(w, r, "subLocation")
	if !ok {
		return
	}
	page := httpx.QueryInt(r, "page", 1, 1)
	perPage := httpx.QueryInt(r, "per_page", 12, 1)
	if perPage > 50 {
		perPage = 50
	}
	cards, pageMeta, err := inventory.SubLocationCards(ctx, s.database, *u.AgentID, id, httpx.QueryString(r, "search"), page, perPage)
	if errors.Is(err, inventory.ErrNotFound) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, cards, "OK", map[string]any{
		"current_page": pageMeta.CurrentPage,
		"last_page":    pageMeta.LastPage,
		"total":        pageMeta.Total,
		"per_page":     perPage,
	})
}

// handleSubStockMy is GET /sub-stock/my.
func (s *Server) handleSubStockMy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	location, err := inventory.SubLocationForOwner(ctx, s.database, *u.AgentID, u.ID)
	if errors.Is(err, inventory.ErrNotFound) {
		// Laravel uses this exact sentence for a missing location (ApiException, 404).
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Anda belum memiliki Sub Location aktif."))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	stocks, err := inventory.MySubStock(ctx, s.database, *u.AgentID, *location)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"sub_location": location.JSON(), "stocks": stocks}, "OK", nil)
}

// handleSubStockReplenishment is GET /sub-stock/replenishment-targets.
func (s *Server) handleSubStockReplenishment(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil || !u.IsRole(auth.RoleSalesKurirSub) {
		writeError(w, r, httpx.NewError(http.StatusForbidden, "Hanya Sales-Kurir-Sub yang dapat melihat target pengisian stok."))
		return
	}
	targets, err := inventory.ReplenishmentTargets(ctx, s.database, *u.AgentID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, targets, "OK", nil)
}

// handleSubStockRequests is GET /sub-stock/requests.
//
// The Sales-Kurir-Sub role sees only its OWN requests; every other allowed role sees the branch. The scope is
// applied server-side from the authenticated role — never from a client-supplied filter.
func (s *Server) handleSubStockRequests(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil || u.AgentID == nil {
		writeError(w, r, forbiddenError(ctx))
		return
	}
	filter := inventory.SubStockRequestFilter{
		Status:    httpx.QueryString(r, "status"),
		Direction: httpx.QueryString(r, "direction"),
		Page:      httpx.QueryInt(r, "page", 1, 1),
		PerPage:   httpx.QueryInt(r, "per_page", 15, 1),
	}
	if u.IsRole(auth.RoleSalesKurirSub) {
		filter.RequestedBy = u.ID
	}
	rows, page, err := inventory.ListSubStockRequests(ctx, s.database, *u.AgentID, filter)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", page)
}

// pathID parses a numeric path parameter.
//
// A non-numeric value means the {param} pattern swallowed a LITERAL sibling route (for example
// `/warehouse/sub-locations/eligible-owners`). Two outcomes, both correct:
//   - if that literal is a real Laravel route this build does not serve → 501 not-implemented, never a 404
//     that would look like "no such endpoint";
//   - otherwise → the normal 404.
func pathID(w http.ResponseWriter, r *http.Request, name string) (int64, bool) {
	raw := httpx.PathValue(r, name)
	id, err := strconv.ParseInt(raw, 10, 64)
	if err != nil {
		if _, known := knownRoute(r.Method, r.URL.Path); known && !implementsRoute(r.Method, r.URL.Path) {
			writeError(w, r, notImplementedError(r.Context()))
			return 0, false
		}
		writeError(w, r, notFoundError(r.Context()))
		return 0, false
	}
	return id, true
}

// invalidField renders the Laravel `integer`/`in` failure sentence for a field.
func invalidField(ctx context.Context, field string) []string {
	return invalidChoice(ctx, field)
}

// formatMySQLTime renders a MySQL DATETIME/TIMESTAMP read as text in Eloquent's JSON form.
//
// Reading the column as text (CAST(... AS CHAR)) avoids driver time-zone interpretation entirely: the value is
// stored in UTC and rendered as UTC, exactly like Carbon with the app's UTC timezone.
func formatMySQLTime(raw sql.NullString) any {
	if !raw.Valid || raw.String == "" {
		return nil
	}
	for _, layout := range []string{"2006-01-02 15:04:05.000000", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, raw.String); err == nil {
			return t.UTC().Format("2006-01-02T15:04:05.000000Z")
		}
	}
	return raw.String
}
