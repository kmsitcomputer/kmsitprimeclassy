package api

import (
	"errors"
	"net/http"
	"net/url"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/crm"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/reporting"
)

func crmActor(uID int64) crm.Actor {
	u := UserFromIDPlaceholder
	_ = u
	return crm.Actor{ID: uID}
}

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
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(r.Context(), "system.not_found")))
	case errors.Is(err, crm.ErrForbidden), errors.Is(err, reporting.ErrForbidden):
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(r.Context(), "system.unauthorized_action")))
	default:
		writeError(w, r, err)
	}
}

func (s *Server) crmService() *crm.Service { return crm.New(s.database) }
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
	httpx.OK(w, rows.Rows, "OK", map[string]int{"current_page": rows.CurrentPage, "last_page": rows.LastPage, "total": rows.Total})
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

func (s *Server) handleDashboardSummary(w http.ResponseWriter, r *http.Request) {
	result, err := s.reportingService().DashboardSummary(r.Context(), reportingActor(r), r.URL.Query())
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

// Keep URL query values typed consistently when report handlers add validated options.
func queryValues(r *http.Request) url.Values { return r.URL.Query() }
