package api

import (
	"errors"
	"net/http"
	"net/url"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/crm"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/reporting"
)

func reportingActor(r *http.Request) reporting.Actor {
	u := UserFrom(r.Context())
	return reporting.Actor{ID: u.ID, Role: u.Role, AgentID: u.AgentID}
}

func crmActorFrom(r *http.Request) crm.Actor {
	u := UserFrom(r.Context())
	return crm.Actor{ID: u.ID, Role: u.Role, AgentID: u.AgentID}
}

func writeDomainReadError(w http.ResponseWriter, r *http.Request, err error) {
	switch {
	case errors.Is(err, crm.ErrNotFound):
		// Route-model binding miss (missing id / foreign-agent row) renders the endpoint-not-found
		// answer, verified against the reference (GLM, 2026-11-20).
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(r.Context(), "system.endpoint_not_found")))
	case errors.Is(err, crm.ErrForbidden), errors.Is(err, reporting.ErrForbidden):
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(r.Context(), "system.unauthorized_action")))
	default:
		writeError(w, r, err)
	}
}

func (s *Server) crmService() *crm.Service             { return crm.New(s.database) }
func (s *Server) reportingService() *reporting.Service { return reporting.New(s.database) }

func (s *Server) handleCRMCustomerIndex(w http.ResponseWriter, r *http.Request) {
	actor := crmActorFrom(r)
	page, _ := strconv.Atoi(r.URL.Query().Get("page"))
	perPage, _ := strconv.Atoi(r.URL.Query().Get("per_page"))
	rows, err := s.crmService().List(r.Context(), actor, r.URL.Query().Get("archived") == "1" || r.URL.Query().Get("archived") == "true",
		r.URL.Query().Get("stage"), r.URL.Query().Get("search"), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	// Laravel answers `$query->paginate(min(per_page,100))` — a LengthAwarePaginator, whose toArray shape
	// (including the links array) the SPA consumes directly. Replicate it, not a bare list.
	if perPage < 1 {
		perPage = 25
	}
	if perPage > 100 {
		perPage = 100
	}
	httpx.OK(w, crmPaginator(r, rows.Rows, rows.CurrentPage, rows.LastPage, rows.Total, perPage), "OK", nil)
}

func (s *Server) handleCRMCustomerShow(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	row, err := s.crmService().Show(r.Context(), crmActorFrom(r), id)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

func (s *Server) handleCRMCustomerVisits(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	rows, err := s.crmService().Visits(r.Context(), crmActorFrom(r), id)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleCRMFollowUps(w http.ResponseWriter, r *http.Request) {
	rows, err := s.crmService().FollowUps(r.Context(), crmActorFrom(r), r.URL.Query().Get("until"))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleCommissionIndex(w http.ResponseWriter, r *http.Request) {
	rows, meta, err := s.reportingService().CommissionIndex(r.Context(), reportingActor(r), r.URL.Query())
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}

func (s *Server) handleCommissionSummary(w http.ResponseWriter, r *http.Request) {
	result, err := s.reportingService().CommissionSummary(r.Context(), reportingActor(r), r.URL.Query())
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleReportTransactions(w http.ResponseWriter, r *http.Request) {
	rows, meta, err := s.reportingService().Transactions(r.Context(), reportingActor(r), r.URL.Query())
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}

// Keep URL query values typed consistently when report handlers add validated options.
func queryValues(r *http.Request) url.Values { return r.URL.Query() }

// crmPaginator is LengthAwarePaginator::toArray for the CRM list.
func crmPaginator(r *http.Request, data any, currentPage, lastPage, total, perPage int) map[string]any {
	scheme := r.URL.Scheme
	if scheme == "" {
		scheme = "http"
	}
	path := scheme + "://"
	if r.URL.Host != "" {
		path += r.URL.Host
	} else {
		path += r.Host
	}
	path += r.URL.Path
	pageURL := func(number int) string { return path + "?page=" + strconv.Itoa(number) }
	from := any(nil)
	if len(toSlice(data)) > 0 {
		from = (currentPage-1)*perPage + 1
	}
	to := any(nil)
	if n := len(toSlice(data)); n > 0 {
		to = (currentPage-1)*perPage + n
	}
	links := []any{
		map[string]any{"url": pageLink(currentPage > 1, pageURL(currentPage-1)), "label": "&laquo; Previous", "active": false},
	}
	for number := 1; number <= lastPage; number++ {
		links = append(links, map[string]any{"url": pageURL(number), "label": strconv.Itoa(number), "active": number == currentPage})
	}
	links = append(links, map[string]any{"url": pageLink(currentPage < lastPage, pageURL(currentPage+1)), "label": "Next &raquo;", "active": false})
	var next, prev any
	if currentPage < lastPage {
		next = pageURL(currentPage + 1)
	}
	if currentPage > 1 {
		prev = pageURL(currentPage - 1)
	}
	return map[string]any{
		"current_page":   currentPage,
		"data":           data,
		"first_page_url": pageURL(1),
		"from":           from,
		"last_page":      lastPage,
		"last_page_url":  pageURL(lastPage),
		"links":          links,
		"next_page_url":  next,
		"path":           path,
		"per_page":       perPage,
		"prev_page_url":  prev,
		"to":             to,
		"total":          total,
	}
}

func pageLink(active bool, url string) any {
	if !active {
		return nil
	}
	return url
}

// toSlice treats the CRM rows (an any) as its slice length; rows is always a JSON array here.
func toSlice(v any) []any {
	if slice, ok := v.([]any); ok {
		return slice
	}
	return nil
}

// matchesDomainEReadRoute is the implementsRoute entry for the CRM/commission/report read surface.
func matchesDomainEReadRoute(method, path string) bool {
	if method != http.MethodGet {
		return false
	}
	rest := strings.TrimPrefix(path, "/api/v1/")
	parts := strings.Split(rest, "/")
	switch parts[0] {
	case "bestsellers":
		return len(parts) == 1
	case "dashboard":
		return len(parts) == 2 && parts[1] == "summary"
	case "crm":
		if len(parts) == 2 && parts[1] == "follow-ups" {
			return true
		}
		if len(parts) == 2 && parts[1] == "customers" {
			return true
		}
		if len(parts) == 3 && parts[1] == "customers" && parts[2] != "" {
			return true
		}
		if len(parts) == 4 && parts[1] == "customers" && parts[2] != "" && parts[3] == "visits" {
			return true
		}
	case "commissions":
		return len(parts) == 1 || (len(parts) == 2 && parts[1] == "summary")
	case "reports":
		return len(parts) == 2 && parts[1] == "transactions"
	}
	return false
}
