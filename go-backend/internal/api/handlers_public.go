package api

import (
	"context"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/catalog"
	"github.com/kmsitcomputer/primeclassy-go/internal/cms"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
	"github.com/kmsitcomputer/primeclassy-go/internal/site"
)

// ---- catalog ---------------------------------------------------------------------------------------

func (s *Server) handleBestsellers(w http.ResponseWriter, r *http.Request) {
	ctx, locale := r.Context(), LocaleFrom(r.Context())
	q := r.URL.Query()
	period := q.Get("period")
	if period == "" {
		period = "30"
	}
	fields := map[string][]string{}
	if period != "7" && period != "30" && period != "90" && period != "all" {
		fields["period"] = []string{i18n.ValidationMessage(locale, "in", "period", "values", "7, 30, 90, all")}
	}
	limit := 8
	if raw := q.Get("limit"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil {
			fields["limit"] = []string{i18n.ValidationMessage(locale, "integer", "limit")}
		} else if n != 4 && n != 8 && n != 12 {
			fields["limit"] = []string{i18n.ValidationMessage(locale, "in", "limit", "values", "4, 8, 12")}
		} else {
			limit = n
		}
	}
	var explicit *int64
	if raw, ok := q["agent_id"]; ok && len(raw) > 0 && raw[0] != "" {
		id, err := strconv.ParseInt(raw[0], 10, 64)
		if err != nil {
			fields["agent_id"] = []string{i18n.ValidationMessage(locale, "integer", "agent_id")}
		} else {
			explicit = &id
		}
	}
	ref := q.Get("ref")
	if utf8.RuneCountInString(ref) > 40 {
		fields["ref"] = []string{i18n.ValidationMessage(locale, "max.string", "ref", "max", "40")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	var userID int64
	var role string
	var agentID *int64
	if user := UserFrom(ctx); user != nil {
		userID, role, agentID = user.ID, user.Role, user.AgentID
	}
	scope, err := catalog.BestsellerAgent(ctx, s.database, userID, role, agentID, explicit, ref)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if scope == nil {
		httpx.OK(w, []map[string]any{}, "OK", map[string]any{"hidden": true})
		return
	}
	items, hidden, err := catalog.Bestsellers(ctx, s.database, *scope, period, limit)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, items, "OK", map[string]any{"hidden": hidden})
}

// handleProducts mirrors Catalog\ProductController::index for anonymous and authenticated visitors,
// including the optional Agent/Sub availability projection.
func (s *Server) handleProducts(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	query := catalog.Query{
		Search:   httpx.QueryString(r, "search"),
		Category: httpx.QueryString(r, "category"),
		Sort:     httpx.QueryString(r, "sort"),
		Random:   httpx.QueryBool(r, "random"),
		Page:     httpx.QueryInt(r, "page", 1, 1),
		PerPage:  httpx.QueryInt(r, "per_page", 15, 1),
	}
	if v := httpx.QueryString(r, "min_price"); v != "" {
		if f, err := strconv.ParseFloat(v, 64); err == nil {
			query.MinPrice = &f
		}
	}
	if v := httpx.QueryString(r, "max_price"); v != "" {
		if f, err := strconv.ParseFloat(v, 64); err == nil {
			query.MaxPrice = &f
		}
	}
	if v := httpx.QueryString(r, "exclude"); v != "" {
		if n, err := strconv.ParseInt(v, 10, 64); err == nil {
			query.ExcludeID = &n
		}
	}

	result, err := catalog.ListProducts(ctx, s.database, query)
	if err != nil {
		if db.IsMissingSchema(err) {
			// Fresh, unmigrated deployment: same empty page the SafeSchema guard produces.
			httpx.OK(w, []map[string]any{}, "OK", httpx.NewMeta(1, 1, 0))
			return
		}
		writeError(w, r, err)
		return
	}
	if err := s.attachProductAvailability(ctx, r, result.Items); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Items, "OK", httpx.NewMeta(result.Page, result.LastPage, result.Total))
}

// handleProductDetail mirrors ProductController::show.
func (s *Server) handleProductDetail(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	slug := httpx.PathValue(r, "slug")
	product, err := catalog.ProductBySlug(ctx, s.database, slug)
	if errors.Is(err, catalog.ErrProductNotFound) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err != nil {
		if db.IsMissingSchema(err) {
			writeError(w, r, notFoundError(ctx))
			return
		}
		writeError(w, r, err)
		return
	}
	if err := s.attachProductAvailability(ctx, r, []map[string]any{product}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, product, "OK", nil)
}

func catalogSubLocation(ctx context.Context, d *db.DB, ownerID int64, agentID *int64) (*inventory.SubLocationRef, error) {
	if agentID == nil {
		return nil, inventory.ErrNotFound
	}
	return inventory.SubLocationForOwner(ctx, d, *agentID, ownerID)
}

func (s *Server) attachProductAvailability(ctx context.Context, r *http.Request, products []map[string]any) error {
	stockSource := httpx.QueryString(r, "stock_source")
	if stockSource != "" && stockSource != "agent" && stockSource != "sub" {
		return httpx.Validation(map[string][]string{"stock_source": invalidChoice(ctx, "stock_source")})
	}
	var requestedSubLocationID *int64
	if rawSubLocationID := httpx.QueryString(r, "sub_location_id"); rawSubLocationID != "" {
		id, err := strconv.ParseInt(rawSubLocationID, 10, 64)
		if err != nil {
			return httpx.Validation(map[string][]string{"sub_location_id": notInteger(ctx, "sub_location_id")})
		}
		requestedSubLocationID = &id
	}
	u := UserFrom(ctx)
	var subLocationID *int64
	if stockSource == "sub" || requestedSubLocationID != nil {
		if u == nil {
			return httpx.NewError(http.StatusForbidden, "Login diperlukan untuk memilih sumber stok.")
		}
		if stockSource != "sub" {
			return &httpx.Error{Status: http.StatusUnprocessableEntity,
				Message:      "sub_location_id hanya berlaku untuk sumber stok Sub.",
				FieldStrings: map[string]string{"sub_location_id": "Tidak valid untuk sumber stok Agent."}}
		}
		if !u.IsRole("sales-kurir-sub") {
			return httpx.NewError(http.StatusForbidden, "Hanya Sales-Kurir-Sub yang dapat memakai stok Sub.")
		}
		location, err := catalogSubLocation(ctx, s.database, u.ID, u.AgentID)
		if err != nil {
			if errors.Is(err, inventory.ErrNotFound) {
				return &httpx.Error{Status: http.StatusUnprocessableEntity,
					Message:      "Anda belum memiliki Sub Location aktif.",
					FieldStrings: map[string]string{"stock_source": "Sub Location tidak tersedia."}}
			}
			return err
		}
		if requestedSubLocationID != nil && *requestedSubLocationID != location.ID {
			return httpx.NewError(http.StatusForbidden, "Sub Location bukan milik Anda.")
		}
		subLocationID = &location.ID
	}
	if u == nil || u.AgentID == nil {
		return nil
	}
	for _, product := range products {
		if err := catalog.AttachPublicAvailability(ctx, s.database, *u.AgentID, subLocationID, product); err != nil {
			return err
		}
	}
	return nil
}

// handleCategories mirrors ProductCategoryController::index.
func (s *Server) handleCategories(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	categories, err := catalog.Categories(ctx, s.database)
	if err != nil {
		if db.IsMissingSchema(err) {
			httpx.OK(w, []map[string]any{}, "OK", nil)
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.OK(w, categories, "OK", nil)
}

// ---- content ---------------------------------------------------------------------------------------

// handleHomepage mirrors Cms\HomepageController::index for an anonymous visitor.
func (s *Server) handleHomepage(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if ref := httpx.QueryString(r, "ref"); len(ref) > 40 {
		writeError(w, r, httpx.Validation(map[string][]string{"ref": {msg(ctx, "system.field_invalid")}}))
		return
	}
	blocks, err := cms.Homepage(ctx, s.database)
	if err != nil {
		if db.IsMissingSchema(err) {
			httpx.OK(w, []map[string]any{}, "OK", nil)
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.OK(w, blocks, "OK", nil)
}

// handleArticles mirrors ArticleController::index.
func (s *Server) handleArticles(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	typ := httpx.QueryString(r, "type")
	if typ != "" && typ != "article" && typ != "news" {
		writeError(w, r, httpx.Validation(map[string][]string{"type": {msg(ctx, "system.field_invalid")}}))
		return
	}
	list, err := cms.ListArticles(ctx, s.database, typ, httpx.QueryInt(r, "page", 1, 1), httpx.QueryInt(r, "per_page", 15, 1))
	if err != nil {
		if db.IsMissingSchema(err) {
			httpx.OK(w, []map[string]any{}, "OK", httpx.NewMeta(1, 1, 0))
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.OK(w, list.Items, "OK", httpx.NewMeta(list.Page, list.LastPage, list.Total))
}

// handleArticleDetail mirrors ArticleController::show.
func (s *Server) handleArticleDetail(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	article, err := cms.ArticleBySlug(ctx, s.database, httpx.PathValue(r, "slug"))
	if errors.Is(err, cms.ErrNotFound) || (err != nil && db.IsMissingSchema(err)) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, article, "OK", nil)
}

// handlePageDetail mirrors PageController::show.
func (s *Server) handlePageDetail(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	page, err := cms.PageBySlug(ctx, s.database, httpx.PathValue(r, "slug"))
	if errors.Is(err, cms.ErrNotFound) || (err != nil && db.IsMissingSchema(err)) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, page, "OK", nil)
}

// ---- site configuration ----------------------------------------------------------------------------

// handleSettings mirrors WebsiteSettingController::show (public, cached in Laravel, always fresh here).
func (s *Server) handleSettings(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	settings, err := site.PublicSettings(ctx, s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, settings, "OK", nil)
}

// handleLanguages mirrors LanguageController::index.
func (s *Server) handleLanguages(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	languages, err := site.Languages(ctx, s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, languages, "OK", nil)
}

// handleAgents mirrors AgentContactController::index (public directory).
func (s *Server) handleAgents(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	agents, err := site.Agents(ctx, s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, agents, "OK", nil)
}

// regionHandler builds the cascade handler for one level.
func (s *Server) regionHandler(level string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		ctx := r.Context()
		var parent string
		switch level {
		case "regencies":
			parent = httpx.QueryString(r, "province_id")
		case "districts":
			parent = httpx.QueryString(r, "regency_id")
		case "villages":
			parent = httpx.QueryString(r, "district_id")
		}
		rows, err := site.Regions(ctx, s.database, level, parent)
		if err != nil {
			var parentErr *site.ParentError
			if errors.As(err, &parentErr) {
				// A missing value is a `required` failure, an unknown one an `exists` failure — the same two
				// rules (and therefore sentences) the Laravel controller applies.
				message := unknownChoice(ctx, parentErr.Field)
				if parentErr.Missing {
					message = requiredField(ctx, parentErr.Field)
				}
				writeError(w, r, httpx.Validation(map[string][]string{parentErr.Field: message}))
				return
			}
			writeError(w, r, err)
			return
		}
		httpx.OK(w, rows, "OK", nil)
	}
}

// handleInstallStatus mirrors InstallController::status.
func (s *Server) handleInstallStatus(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	status, err := site.Status(ctx, s.database, s.installLock)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, status, "OK", nil)
}

// asTrimmedList is a small shared helper used by tests and the parity endpoint.
func asTrimmedList(values []string) []string {
	out := make([]string, 0, len(values))
	for _, v := range values {
		if t := strings.TrimSpace(v); t != "" {
			out = append(out, t)
		}
	}
	return out
}
