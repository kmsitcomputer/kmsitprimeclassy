package api

import (
	"context"
	"database/sql"
	"errors"
	"log/slog"
	"net/http"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// Server is the HTTP application: configuration, the database, the session manager and the router.
//
// It is a modular monolith: each domain lives in its own package (catalog, cms, site, auth) and is wired here.
// There is no service-to-service hop and no second datastore — the Go service reads the same MySQL schema the
// Laravel app owns.
type Server struct {
	cfg         config.Config
	database    *db.DB
	sessions    *auth.Manager
	identity    *auth.Service
	limiter     *limiter
	mux         *http.ServeMux
	handler     http.Handler
	startedAt   time.Time
	logger      *slog.Logger
	installLock string
}

// Options carries the pieces a caller can override (tests use it for the install-lock path).
type Options struct {
	InstallLockPath string
	Logger          *slog.Logger
}

// New builds the server and its full middleware chain.
func New(cfg config.Config, database *db.DB, opts Options) *Server {
	logger := opts.Logger
	if logger == nil {
		logger = slog.Default()
	}
	auth.SetPublicBaseURL(cfg.PublicURL)
	s := &Server{
		cfg:         cfg,
		database:    database,
		sessions:    auth.NewManager(database, cfg.Session),
		identity:    auth.NewService(database),
		limiter:     newLimiter(),
		mux:         http.NewServeMux(),
		startedAt:   time.Now().UTC(),
		logger:      logger,
		installLock: opts.InstallLockPath,
	}
	s.routes()

	// Global chain, mirroring bootstrap/app.php:
	//   ReflectOriginBeforeInstall → PreventApiResponseCaching (always no-store) → SetLocale → stateful auth.
	stateful := cfg.Session.StatefulDomains
	s.handler = chain(s.mux,
		RequestState,
		Recover,
		AccessLog,
		CORS(stateful),
		s.LoadSession,
		ValidateCSRF,
	)
	return s
}

// Handler returns the root handler.
func (s *Server) Handler() http.Handler { return s.handler }

// ---- routing ---------------------------------------------------------------------------------------

func (s *Server) routes() {
	// Health: /up is the Laravel health endpoint the deployment bridge exposes; /healthz adds readiness.
	s.mux.HandleFunc("GET /up", s.handleUp)
	s.mux.HandleFunc("GET /healthz", s.handleHealthz)

	// Sanctum-compatible CSRF bootstrap.
	s.mux.HandleFunc("GET /sanctum/csrf-cookie", s.handleCsrfCookie)

	// Identity.
	s.mux.Handle("POST /api/v1/auth/login", chain(http.HandlerFunc(s.handleLogin), s.Throttle(10, time.Minute)))
	s.mux.Handle("POST /api/v1/auth/register", chain(http.HandlerFunc(s.handleRegister), s.Throttle(5, time.Minute)))
	s.mux.Handle("POST /api/v1/auth/logout", chain(http.HandlerFunc(s.handleLogout), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("GET /api/v1/auth/me", chain(http.HandlerFunc(s.handleMe), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("PATCH /api/v1/profile/password", chain(http.HandlerFunc(s.handleUpdatePassword), RequireAuth, s.RequireAgentLinked))

	// Public site configuration.
	s.mux.HandleFunc("GET /api/v1/install/status", s.handleInstallStatus)
	s.mux.HandleFunc("GET /api/v1/settings", s.handleSettings)
	s.mux.HandleFunc("GET /api/v1/languages", s.handleLanguages)
	s.mux.HandleFunc("GET /api/v1/agents", s.handleAgents)
	s.mux.HandleFunc("GET /api/v1/regions/provinces", s.regionHandler("provinces"))
	s.mux.HandleFunc("GET /api/v1/regions/regencies", s.regionHandler("regencies"))
	s.mux.HandleFunc("GET /api/v1/regions/districts", s.regionHandler("districts"))
	s.mux.HandleFunc("GET /api/v1/regions/villages", s.regionHandler("villages"))

	// Public catalog and content.
	s.mux.HandleFunc("GET /api/v1/categories", s.handleCategories)
	s.mux.HandleFunc("GET /api/v1/products", s.handleProducts)
	s.mux.HandleFunc("GET /api/v1/products/{slug}", s.handleProductDetail)
	s.mux.HandleFunc("GET /api/v1/homepage", s.handleHomepage)
	s.mux.HandleFunc("GET /api/v1/articles", s.handleArticles)
	s.mux.HandleFunc("GET /api/v1/articles/{slug}", s.handleArticleDetail)
	s.mux.HandleFunc("GET /api/v1/pages/{slug}", s.handlePageDetail)

	// Inventory (agent/warehouse read surfaces). Role gates mirror the Laravel route groups exactly:
	//   stock/*            role:super_admin,agen,admin,gudang
	//   warehouse-stock    role:super_admin,agen,admin,gudang
	//   warehouse/sellable role:super_admin,agen,admin,gudang
	//   warehouse/settings role:super_admin,agen,admin,gudang
	//   warehouse/sub-locations (list/show/stocks) role:super_admin,agen,admin,gudang
	//   warehouse/stock-cards                     role:admin,gudang
	//   warehouse/sub-locations/{id}/stock-cards  role:admin,gudang
	//   sub-stock/my, sub-stock/replenishment-targets role:sales-kurir-sub
	stockRead := "super_admin,agen,admin,gudang"
	s.mux.Handle("GET /api/v1/stock/products", chain(http.HandlerFunc(s.handleStockProducts), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/stock/variations", chain(http.HandlerFunc(s.handleStockVariations), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse-stock", chain(http.HandlerFunc(s.handleWarehouseStock), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse/sellable", chain(http.HandlerFunc(s.handleWarehouseSellable), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse/settings", chain(http.HandlerFunc(s.handleWarehouseSettings), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse/sub-locations", chain(http.HandlerFunc(s.handleSubLocations), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse/sub-locations/{subLocation}", chain(http.HandlerFunc(s.handleSubLocationShow), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse/sub-locations/{subLocation}/stocks", chain(http.HandlerFunc(s.handleSubLocationStocks), RequireAuth, s.RequireAgentLinked, RequireRole(stockRead)))
	s.mux.Handle("GET /api/v1/warehouse/stock-cards", chain(http.HandlerFunc(s.handleStockCards), RequireAuth, s.RequireAgentLinked, RequireRole("admin,gudang")))
	s.mux.Handle("GET /api/v1/warehouse/sub-locations/{subLocation}/stock-cards", chain(http.HandlerFunc(s.handleSubLocationCards), RequireAuth, s.RequireAgentLinked, RequireRole("admin,gudang")))
	s.mux.Handle("GET /api/v1/sub-stock/my", chain(http.HandlerFunc(s.handleSubStockMy), RequireAuth, s.RequireAgentLinked, RequireRole("sales-kurir-sub")))
	s.mux.Handle("GET /api/v1/sub-stock/replenishment-targets", chain(http.HandlerFunc(s.handleSubStockReplenishment), RequireAuth, s.RequireAgentLinked, RequireRole("sales-kurir-sub")))
	s.mux.Handle("GET /api/v1/sub-stock/requests", chain(http.HandlerFunc(s.handleSubStockRequests), RequireAuth, s.RequireAgentLinked, RequireRole("sales-kurir-sub,agen,admin,gudang")))

	// Machine-readable parity report (Go-only, additive, underscore-prefixed so it can never collide with a
	// Laravel route).
	s.mux.HandleFunc("GET /api/v1/_parity", s.handleParity)

	// Everything else: 501 for a real Laravel route this build does not serve, 404 for an unknown path —
	// exactly the distinction the frontend needs to tell "not migrated yet" from "does not exist".
	s.mux.HandleFunc("/", s.handleFallback)
}

// ---- generic handlers ------------------------------------------------------------------------------

func (s *Server) handleFallback(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	if known, ok := knownRoute(r.Method, path); ok && !implementsRoute(r.Method, path) {
		slog.DebugContext(r.Context(), "route not implemented in Go yet",
			"method", r.Method, "path", path, "laravel_action", known.Action)
		writeError(w, r, notImplementedError(r.Context()))
		return
	}
	writeError(w, r, httpx.NewError(http.StatusNotFound, msg(r.Context(), "system.endpoint_not_found")))
}

func (s *Server) handleUp(w http.ResponseWriter, r *http.Request) {
	// Laravel's /up returns a plain 200 with an HTML body; the deployment health check only inspects status.
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store, private")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte("<html><body>Application up</body></html>"))
}

func (s *Server) handleHealthz(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()
	status := map[string]any{
		"status":   "ok",
		"service":  "primeclassy-go",
		"env":      string(s.cfg.Env),
		"uptime_s": int(time.Since(s.startedAt).Seconds()),
	}
	code := http.StatusOK
	if err := s.database.PingContext(ctx); err != nil {
		status["status"] = "degraded"
		status["database"] = "unreachable"
		code = http.StatusServiceUnavailable
	} else {
		status["database"] = "ok"
	}
	httpx.WriteJSON(w, code, status)
}

// ---- parity ----------------------------------------------------------------------------------------

type parityReport struct {
	Service           string         `json:"service"`
	LaravelRoutes     int            `json:"laravel_routes_total"`
	Implemented       int            `json:"implemented_total"`
	CoveragePercent   float64        `json:"coverage_percent"`
	ImplementedRoute  []string       `json:"implemented_routes"`
	OutstandingByArea map[string]int `json:"outstanding_by_area"`
}

// handleParity reports exactly which Laravel API routes this build serves.
//
// It is computed from the same two sources the router uses (the embedded inventory and implementsRoute), so
// it cannot drift from real behaviour: a route counts as implemented only if the router would serve it.
func (s *Server) handleParity(w http.ResponseWriter, r *http.Request) {
	routes, err := loadInventory()
	if err != nil {
		writeError(w, r, err)
		return
	}
	implemented := []string{}
	byArea := map[string]int{}
	count := 0
	for _, rt := range routes {
		if implementsRoute(rt.Method, rt.URI) {
			count++
			implemented = append(implemented, rt.Method+" "+rt.URI)
			continue
		}
		byArea[areaOf(rt.URI)]++
	}
	report := parityReport{
		Service:           "primeclassy-go",
		LaravelRoutes:     len(routes),
		Implemented:       count,
		ImplementedRoute:  implemented,
		OutstandingByArea: byArea,
	}
	if len(routes) > 0 {
		report.CoveragePercent = float64(count) / float64(len(routes)) * 100
	}
	httpx.OK(w, report, "OK", nil)
}

// areaOf groups a URI by its second path segment, which is the API's own functional area
// (/api/v1/<area>/…). It is only used for reporting.
func areaOf(uri string) string {
	parts := splitPath(uri)
	if len(parts) >= 3 {
		return parts[2]
	}
	return "(root)"
}

func splitPath(p string) []string {
	out := []string{}
	cur := ""
	for _, r := range p {
		if r == '/' {
			if cur != "" {
				out = append(out, cur)
				cur = ""
			}
			continue
		}
		cur += string(r)
	}
	if cur != "" {
		out = append(out, cur)
	}
	return out
}

// notUsed keeps the compiler honest if a helper is temporarily unused during a refactor.
var _ = errors.Is
var _ = sql.ErrNoRows
