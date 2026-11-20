package api

import (
	"net/http"
	"net/url"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// reportFilters extracts the shared read-only report filter whitelist from the request,
// mirroring ReportController::filters(). Values are passed through verbatim; the service
// applies them server-side so a caller can never escape its own agent branch.
func reportFilters(q url.Values) url.Values {
	f := url.Values{}
	for _, k := range []string{
		"from", "to", "delivery_date_from", "delivery_date_to",
		"sales_id", "korsal_id", "courier_id", "status", "item_status", "order_status", "search",
		"payment_method", "product_id",
		"receivable", "due_filter", "due_date_from", "due_date_to",
		"agent_id",
	} {
		if v := q.Get(k); v != "" {
			f.Set(k, v)
		}
	}
	return f
}

// reportPage reads pagination with Laravel's paginate($request->integer('per_page', 15)) semantics
// (paginate() itself applies no cap).
func reportPage(q url.Values) (page, perPage int) {
	return reportPageDefault(q, 15)
}

func reportPageDefault(q url.Values, def int) (page, perPage int) {
	page, _ = strconv.Atoi(q.Get("page"))
	perPage, _ = strconv.Atoi(q.Get("per_page"))
	if page < 1 {
		page = 1
	}
	if perPage < 1 {
		perPage = def
	}
	return
}

// ---- handlers ---------------------------------------------------------------

func (s *Server) handleReportCancellationsRefunds(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().CancellationsRefunds(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportCouriers(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	page, perPage := reportPage(r.URL.Query())
	rows, meta, err := s.reportingService().CourierRoster(r.Context(), actor, reportFilters(r.URL.Query()), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}

func (s *Server) handleReportCouriersPerAgent(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	rows, err := s.reportingService().CouriersPerAgent(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleReportCrmVisits(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().CrmVisits(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportCustomers(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	page, perPage := reportPage(r.URL.Query())
	rows, meta, err := s.reportingService().CustomersReport(r.Context(), actor, reportFilters(r.URL.Query()), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}
func (s *Server) handleReportAgentFees(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	rows, err := s.reportingService().AgentFees(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleReportCourierFees(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	rows, err := s.reportingService().CourierFees(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleReportSalesKorsalFees(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().SalesKorsalFees(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportFinanceOrders(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	q := reportFilters(r.URL.Query())
	perPage := httpx.QueryInt(r, "per_page", 15, 1)
	q.Set("per_page", strconv.Itoa(perPage))
	result, err := s.reportingService().FinanceOrders(r.Context(), actor, q)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, result["orders"], "OK", result["meta"])
}

func (s *Server) handleReportFinanceSummary(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().FinanceSummary(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportKorsal(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	page, perPage := reportPage(r.URL.Query())
	rows, meta, err := s.reportingService().KorsalRoster(r.Context(), actor, reportFilters(r.URL.Query()), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}

func (s *Server) handleReportMyCustomers(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	page, perPage := reportPage(r.URL.Query())
	rows, meta, feeTotal, err := s.reportingService().SalesCustomersReport(r.Context(), actor, reportFilters(r.URL.Query()), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	metaAny := map[string]any{"current_page": meta["current_page"], "last_page": meta["last_page"], "total": meta["total"], "total_fee": feeTotal}
	httpx.OK(w, rows, "OK", metaAny)
}

func (s *Server) handleReportNetworkSummary(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().NetworkSummary(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportDashboardSummary(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().DashboardSummary(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleDashboardKPIs(w http.ResponseWriter, r *http.Request) {
	data, err := s.reportingService().DashboardKPIs(r.Context(), reportingActor(r), r.URL.Query())
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportNetworkTransactions(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	data, err := s.reportingService().NetworkTransactions(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleReportPaymentStatus(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	rows, err := s.reportingService().PaymentStatus(r.Context(), actor, reportFilters(r.URL.Query()))
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleReportSales(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	page, perPage := reportPage(r.URL.Query())
	rows, meta, err := s.reportingService().SalesRoster(r.Context(), actor, reportFilters(r.URL.Query()), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}

func (s *Server) handleReportAuditLogs(w http.ResponseWriter, r *http.Request) {
	actor := reportingActor(r)
	// AuditLogController::index paginates with $request->integer('per_page', 25) — default 25, uncapped.
	page, perPage := reportPageDefault(r.URL.Query(), 25)
	rows, meta, err := s.reportingService().AuditLogs(r.Context(), actor, r.URL.Query(), page, perPage)
	if err != nil {
		writeDomainReadError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", meta)
}
