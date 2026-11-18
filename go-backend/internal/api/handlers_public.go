package api

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/catalog"
	"github.com/kmsitcomputer/primeclassy-go/internal/cms"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/site"
)

// ---- catalog ---------------------------------------------------------------------------------------

// handleProducts mirrors Catalog\ProductController::index for an ANONYMOUS visitor.
//
// The `stock_source`/`sub_location_id` validation is reproduced because it runs before the query in Laravel:
// an invalid value is a 422 even for a public request, and the SPA relies on that.
func (s *Server) handleProducts(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	stockSource := httpx.QueryString(r, "stock_source")
	if stockSource != "" && stockSource != "agent" && stockSource != "sub" {
		writeError(w, r, httpx.Validation(map[string][]string{"stock_source": invalidChoice(ctx, "stock_source")}))
		return
	}
	if stockSource == "sub" || httpx.QueryString(r, "sub_location_id") != "" {
		// Selecting a Sub stock source needs an authenticated actor (StockSourceResolver); the anonymous
		// catalog cannot honour it, and Laravel refuses exactly the same way.
		if UserFrom(ctx) == nil {
			writeError(w, r, httpx.NewError(http.StatusForbidden, "Login diperlukan untuk memilih sumber stok."))
			return
		}
	}

	query := catalog.Query{
		Search:    httpx.QueryString(r, "search"),
		Category:  httpx.QueryString(r, "category"),
		Sort:      httpx.QueryString(r, "sort"),
		Random:    httpx.QueryBool(r, "random"),
		Page:      httpx.QueryInt(r, "page", 1, 1),
		PerPage:   httpx.QueryInt(r, "per_page", 15, 1),
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
	httpx.OK(w, product, "OK", nil)
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
