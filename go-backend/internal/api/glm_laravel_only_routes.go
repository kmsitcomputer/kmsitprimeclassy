package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/dispatch"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/notifications"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

// GLM IMP-010 — Laravel-only endpoint slice. Kept out of server.go so the shared router file
// stays a one-line registration, following the Domain E pattern.
//
// Operator in-app notification centre (AdminNotificationController, routes api_v1.php:167).
// Laravel contract: role:super_admin,agen,admin,keuangan; every query is scoped to the CALLER's own
// rows (recipient_user_id); "not yours" and "does not exist" are the same 404 (no existence oracle).
func (s *Server) registerGLMLaravelOnlyRoutes() {
	notifRoles := "super_admin,agen,admin,keuangan"
	s.mux.Handle("GET /api/v1/notifications/admin", chain(http.HandlerFunc(s.handleNotificationsAdminIndex), RequireAuth, s.RequireAgentLinked, RequireRole(notifRoles)))
	s.mux.Handle("GET /api/v1/notifications/admin/unread-count", chain(http.HandlerFunc(s.handleNotificationsAdminUnreadCount), RequireAuth, s.RequireAgentLinked, RequireRole(notifRoles)))
	s.mux.Handle("POST /api/v1/notifications/admin/read-all", chain(http.HandlerFunc(s.handleNotificationsAdminReadAll), RequireAuth, s.RequireAgentLinked, RequireRole(notifRoles)))
	s.mux.Handle("POST /api/v1/notifications/admin/{id}/read", chain(http.HandlerFunc(s.handleNotificationsAdminMarkRead), RequireAuth, s.RequireAgentLinked, RequireRole(notifRoles)))

	// Media — POST/DELETE are auth-only at the ROUTE level on purpose (MediaPolicy decides per
	// collection: 'user_avatar' is open to every authenticated role); GET /media lives inside the
	// super_admin-only CMS group (verified against the reference: agen gets 403 even though the
	// policy itself would allow it — the route gate is deliberately narrower than the policy).
	s.mux.Handle("GET /api/v1/media", chain(http.HandlerFunc(s.handleMediaIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/media", chain(http.HandlerFunc(s.handleMediaStore), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("DELETE /api/v1/media/{media}", chain(http.HandlerFunc(s.handleMediaDestroy), RequireAuth, s.RequireAgentLinked))

	// Admin agents (Kontak Agen) — every route sits inside the super_admin admin group; the controller
	// Gate::authorize('manage-system-config') is the same super_admin check.
	adminAgents := RequireRole("super_admin")
	s.mux.Handle("GET /api/v1/admin/agents", chain(http.HandlerFunc(s.handleAdminAgentsIndex), RequireAuth, s.RequireAgentLinked, adminAgents))
	s.mux.Handle("POST /api/v1/admin/agents", chain(http.HandlerFunc(s.handleAdminAgentsStore), RequireAuth, s.RequireAgentLinked, adminAgents))
	s.mux.Handle("PATCH /api/v1/admin/agents/{agentProfile}", chain(http.HandlerFunc(s.handleAdminAgentsUpdate), RequireAuth, s.RequireAgentLinked, adminAgents))
	s.mux.Handle("DELETE /api/v1/admin/agents/{agentProfile}", chain(http.HandlerFunc(s.handleAdminAgentsDestroy), RequireAuth, s.RequireAgentLinked, adminAgents))
	s.mux.Handle("PATCH /api/v1/admin/agents/{agent}/toggle-status", chain(http.HandlerFunc(s.handleAdminAgentsToggleStatus), RequireAuth, s.RequireAgentLinked, adminAgents))

	// Dispatch workspace reads — verified Laravel role split (api_v1.php:316-349):
	//   couriers/regions: super_admin,agen,admin,koordinator-kurir
	//   expeditions + refusals: koordinator-kurir ONLY (api_v1.php:320-321)
	dispatchStaff := RequireRole("super_admin,agen,admin,koordinator-kurir")
	s.mux.Handle("GET /api/v1/dispatch/couriers", chain(http.HandlerFunc(s.handleDispatchCouriers), RequireAuth, s.RequireAgentLinked, dispatchStaff))
	s.mux.Handle("GET /api/v1/dispatch/regions", chain(http.HandlerFunc(s.handleDispatchRegions), RequireAuth, s.RequireAgentLinked, dispatchStaff))
	s.mux.Handle("GET /api/v1/dispatch/expeditions", chain(http.HandlerFunc(s.handleDispatchExpeditions), RequireAuth, s.RequireAgentLinked, RequireRole("koordinator-kurir")))
	s.mux.Handle("GET /api/v1/dispatch/refusals", chain(http.HandlerFunc(s.handleDispatchRefusals), RequireAuth, s.RequireAgentLinked, RequireRole("koordinator-kurir")))

	// Delivery-refusal write paths (REM-003 reverse custody): hand-back is the shipment EXECUTOR's;
	// receive + disposition are the branch Koordinator's. Verified Laravel split (api_v1.php:316-326).
	s.mux.Handle("POST /api/v1/delivery-refusals/{refusal}/hand-back", chain(http.HandlerFunc(s.handleRefusalHandBack), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,kurir,sales-kurir-sub,koordinator-kurir")))
	s.mux.Handle("POST /api/v1/delivery-refusals/{refusal}/receive", chain(http.HandlerFunc(s.handleRefusalReceive), RequireAuth, s.RequireAgentLinked, RequireRole("koordinator-kurir")))
	s.mux.Handle("POST /api/v1/delivery-refusals/{refusal}/disposition", chain(http.HandlerFunc(s.handleRefusalDispose), RequireAuth, s.RequireAgentLinked, RequireRole("koordinator-kurir")))

	// Shipment status transitions — route gate super_admin,agen,admin,kurir,sales-kurir-sub,
	// koordinator-kurir; ShipmentPolicy::updateStatus executor rules live in the service.
	shipGate := RequireRole("super_admin,agen,admin,kurir,sales-kurir-sub,koordinator-kurir")
	koordGate := RequireRole("koordinator-kurir")
	s.mux.Handle("PATCH /api/v1/shipments/{shipment}/expedition-handover", chain(http.HandlerFunc(s.handleExpeditionHandover), RequireAuth, s.RequireAgentLinked, koordGate))
	s.mux.Handle("POST /api/v1/shipments/{shipment}/expedition-handover", chain(http.HandlerFunc(s.handleExpeditionHandoverSpoofed), RequireAuth, s.RequireAgentLinked, koordGate))
	receiptGate := RequireRole("super_admin,agen,admin,kurir,sales-kurir-sub,koordinator-kurir")
	s.mux.Handle("GET /api/v1/shipments/{shipment}/receipt", chain(http.HandlerFunc(s.handleShipmentReceipt), RequireAuth, s.RequireAgentLinked, receiptGate))
	dvGate := RequireRole("super_admin,admin")
	s.mux.Handle("GET /api/v1/shipments/{shipment}/delivery-verifications", chain(http.HandlerFunc(s.handleDeliveryVerifications), RequireAuth, s.RequireAgentLinked, dvGate))
	s.mux.Handle("POST /api/v1/shipments/{shipment}/delivery-verifications", chain(http.HandlerFunc(s.handleDeliveryVerificationStore), RequireAuth, s.RequireAgentLinked, dvGate))
	s.mux.Handle("POST /api/v1/dispatch/order-items/{orderItem}/split-batch", chain(http.HandlerFunc(s.handleDispatchSplitBatch), RequireAuth, s.RequireAgentLinked, koordGate))
	s.mux.Handle("POST /api/v1/dispatch/orders/{order}/request-fulfillment", chain(http.HandlerFunc(s.handleDispatchRequestFulfillment), RequireAuth, s.RequireAgentLinked, koordGate))
	retGate := RequireRole("super_admin,agen,admin,koordinator-kurir")
	s.mux.Handle("PATCH /api/v1/return-items/{item}/courier", chain(http.HandlerFunc(s.handleReturnItemAssignCourier), RequireAuth, s.RequireAgentLinked, retGate))
	s.mux.Handle("PATCH /api/v1/shipments/{shipment}/courier", chain(http.HandlerFunc(s.handleShipmentAssignCourier), RequireAuth, s.RequireAgentLinked, koordGate))
	s.mux.Handle("GET /api/v1/shipments/{shipment}/eligible-couriers", chain(http.HandlerFunc(s.handleShipmentEligibleCouriers), RequireAuth, s.RequireAgentLinked, koordGate))
	s.mux.Handle("PATCH /api/v1/shipments/{shipment}/status", chain(http.HandlerFunc(s.handleShipmentStatus), RequireAuth, s.RequireAgentLinked, shipGate))
	s.mux.Handle("PATCH /api/v1/shipments/{shipment}/items/{orderItem}/status", chain(http.HandlerFunc(s.handleShipmentItemStatus), RequireAuth, s.RequireAgentLinked, shipGate))

	// Management reports (ReportController) — read-only, role-gated per route below.
	// Each route mirrors its Laravel route/middleware gate exactly (see backend/routes/api_v1.php).
	s.mux.Handle("GET /api/v1/reports/cancellations-refunds", chain(http.HandlerFunc(s.handleReportCancellationsRefunds), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/couriers", chain(http.HandlerFunc(s.handleReportCouriers), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/couriers-per-agent", chain(http.HandlerFunc(s.handleReportCouriersPerAgent), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/crm-visits", chain(http.HandlerFunc(s.handleReportCrmVisits), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,korsal,sales,sales-kurir-sub,super_admin")))
	s.mux.Handle("GET /api/v1/reports/customers", chain(http.HandlerFunc(s.handleReportCustomers), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/fees/agent", chain(http.HandlerFunc(s.handleReportAgentFees), RequireAuth, s.RequireAgentLinked, RequireRole("agen,super_admin")))
	s.mux.Handle("GET /api/v1/reports/fees/courier", chain(http.HandlerFunc(s.handleReportCourierFees), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/fees/sales-korsal", chain(http.HandlerFunc(s.handleReportSalesKorsalFees), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/finance-orders", chain(http.HandlerFunc(s.handleReportFinanceOrders), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/finance-summary", chain(http.HandlerFunc(s.handleReportFinanceSummary), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/korsal", chain(http.HandlerFunc(s.handleReportKorsal), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/my-customers", chain(http.HandlerFunc(s.handleReportMyCustomers), RequireAuth, s.RequireAgentLinked, RequireRole("korsal,sales,sales-kurir-sub")))
	s.mux.Handle("GET /api/v1/reports/network-summary", chain(http.HandlerFunc(s.handleReportNetworkSummary), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/network-transactions", chain(http.HandlerFunc(s.handleReportNetworkTransactions), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,korsal,sales,sales-kurir-sub,super_admin")))
	s.mux.Handle("GET /api/v1/reports/payment-status", chain(http.HandlerFunc(s.handleReportPaymentStatus), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/reports/sales", chain(http.HandlerFunc(s.handleReportSales), RequireAuth, s.RequireAgentLinked, RequireRole("admin,agen,korsal,super_admin")))

	// Audit logs (AuditLogController) — read-only activity browse, super_admin only.
	s.mux.Handle("GET /api/v1/admin/audit-logs", chain(http.HandlerFunc(s.handleReportAuditLogs), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
}

// matchesGLMLaravelOnlyRoute is consulted by implementsRoute (inventory.go) so the 501-vs-404
// decision and the /api/v1/_parity report stay derived from this single slice.
func matchesGLMLaravelOnlyRoute(method, path string) bool {
	switch method + " " + path {
	case
		"GET /api/v1/notifications/admin",
		"GET /api/v1/notifications/admin/unread-count",
		"POST /api/v1/notifications/admin/read-all",
		"GET /api/v1/media",
		"POST /api/v1/media",
		"GET /api/v1/admin/agents",
		"POST /api/v1/admin/agents",
		"GET /api/v1/dispatch/couriers",
		"GET /api/v1/dispatch/regions",
		"GET /api/v1/dispatch/expeditions",
		"GET /api/v1/dispatch/refusals",
		"POST /api/v1/delivery-refusals/{refusal}/hand-back",
		"POST /api/v1/delivery-refusals/{refusal}/receive",
		"POST /api/v1/delivery-refusals/{refusal}/disposition",
		"PATCH /api/v1/shipments/{shipment}/courier",
		"PATCH /api/v1/shipments/{shipment}/expedition-handover",
		"GET /api/v1/shipments/{shipment}/delivery-verifications",
		"POST /api/v1/dispatch/order-items/{orderItem}/split-batch",
		"PATCH /api/v1/return-items/{item}/courier",
		"POST /api/v1/dispatch/orders/{order}/request-fulfillment",
		"POST /api/v1/shipments/{shipment}/delivery-verifications",
		"GET /api/v1/shipments/{shipment}/receipt",
		"GET /api/v1/shipments/{shipment}/eligible-couriers",
		"PATCH /api/v1/shipments/{shipment}/status",
		"PATCH /api/v1/shipments/{shipment}/items/{orderItem}/status",
		"GET /api/v1/reports/cancellations-refunds",
		"GET /api/v1/reports/couriers",
		"GET /api/v1/reports/couriers-per-agent",
		"GET /api/v1/reports/crm-visits",
		"GET /api/v1/reports/customers",
		"GET /api/v1/reports/fees/agent",
		"GET /api/v1/reports/fees/courier",
		"GET /api/v1/reports/fees/sales-korsal",
		"GET /api/v1/reports/finance-orders",
		"GET /api/v1/reports/finance-summary",
		"GET /api/v1/reports/korsal",
		"GET /api/v1/reports/my-customers",
		"GET /api/v1/reports/network-summary",
		"GET /api/v1/reports/network-transactions",
		"GET /api/v1/reports/payment-status",
		"GET /api/v1/reports/sales",
		"GET /api/v1/admin/audit-logs",
		"POST /api/v1/crm/customers",
		"GET /api/v1/crm/link-candidates":
		return true
	}
	// CRM write routes with path parameters (binding-style: any last segment shape is the route).
	if strings.HasPrefix(path, "/api/v1/crm/customers/") && len(path) > len("/api/v1/crm/customers/") &&
		(method == http.MethodPatch || method == http.MethodPost) {
		return true
	}
	if method == http.MethodPatch && strings.HasPrefix(path, "/api/v1/crm/visits/") && len(path) > len("/api/v1/crm/visits/") {
		return true
	}
	// PATCH/DELETE /api/v1/admin/agents/{id}[...] — route model binding: any shape of the last
	// segments is the route; a missing row is a 404 (binding miss), a non-numeric segment also 404s.
	if strings.HasPrefix(path, "/api/v1/admin/agents/") && len(path) > len("/api/v1/admin/agents/") &&
		(method == http.MethodPatch || method == http.MethodDelete) {
		return true
	}
	// DELETE /api/v1/media/{media} — Laravel route model binding: any last segment is the route; a
	// non-existent id is a 404 (binding miss → ModelNotFoundException), a non-numeric segment also
	// 404s (verified against the reference).
	if method == http.MethodDelete && strings.HasPrefix(path, "/api/v1/media/") && len(path) > len("/api/v1/media/") {
		return true
	}
	// POST /api/v1/notifications/admin/{id}/read — Laravel declares ->whereNumber('id'): any non-numeric
	// last segment falls out of the route into the 404 answer (verified: "Endpoint not found.", not 501).
	// pathID renders exactly that, so the slice claims every last segment except the read-all literal.
	const prefix = "/api/v1/notifications/admin/"
	if method == http.MethodPost && len(path) > len(prefix) && path[:len(prefix)] == prefix {
		rest := path[len(prefix):]
		if rest == "read-all" {
			return false
		}
		return true
	}
	return false
}

func (s *Server) notificationsService() *notifications.Store { return notifications.New(s.database) }

// laravelBoolean mirrors Laravel's `boolean` rule, which is STRICT: is_bool($v) || in_array($v,
// [0, 1, '0', '1'], true) — verified against the reference: the string "true" is REJECTED (422).
func laravelBoolean(v string) (bool, bool) {
	switch v {
	case "1":
		return true, true
	case "0":
		return false, true
	}
	return false, false
}

// validateNotificationsIndexQuery ports $request->validate(['unread' => 'sometimes|boolean',
// 'per_page' => 'sometimes|integer|between:1,50']) — rules fail independently and accumulate,
// per_page messages in rule order (integer, then between.numeric), exactly as the reference renders them.
// Verified against the reference: this API validates any key PRESENT in the query string INCLUDING an
// empty value (?per_page → 422 with both integer and between messages) — ConvertEmptyStringsToNull does
// NOT shield query input here; `sometimes` skips only a key that is absent entirely.
// between:1,50 uses getSize() semantics: numeric values compare numerically, strings compare by LENGTH
// (so "abc" passes between via strlen 3 and fails only the integer rule).
func validateNotificationsIndexQuery(ctx context.Context, unreadPresent, perPagePresent bool, unreadRaw, perPageRaw string) (unread bool, perPage int, fields map[string][]string) {
	fields = map[string][]string{}
	if unreadPresent {
		b, ok := laravelBoolean(unreadRaw)
		if !ok {
			fields["unread"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "boolean", "unread")}
		} else {
			unread = b
		}
	}
	if perPagePresent {
		n, err := strconv.ParseInt(perPageRaw, 10, 64)
		if err == nil {
			perPage = int(n)
		} else {
			fields["per_page"] = append(fields["per_page"], i18n.ValidationMessage(LocaleFrom(ctx), "integer", "per_page"))
		}
		size, _ := notificationsSize(perPageRaw)
		if size < 1 || size > 50 {
			fields["per_page"] = append(fields["per_page"], i18n.ValidationMessage(LocaleFrom(ctx), "between.numeric", "per_page", "min", "1", "max", "50"))
		}
	}
	return unread, perPage, fields
}

// notificationsSize ports the getSize() branch used by between.numeric: a numeric string contributes its
// numeric value, anything else its byte length.
func notificationsSize(raw string) (float64, bool) {
	if f, err := strconv.ParseFloat(raw, 64); err == nil {
		return f, true
	}
	return float64(len(raw)), false
}

func (s *Server) handleNotificationsAdminIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	q := r.URL.Query()
	unread, perPage, fields := validateNotificationsIndexQuery(ctx, q.Has("unread"), q.Has("per_page"), q.Get("unread"), q.Get("per_page"))
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	store := s.notificationsService()
	if !store.TableReady(ctx) {
		httpx.OK(w, []any{}, "OK", map[string]any{"unread_count": 0, "total": 0, "migration_pending": true})
		return
	}
	// Laravel's $q->paginate($perPage) reads the current page from the `page` query parameter.
	pageNo := 1
	if raw := q.Get("page"); raw != "" {
		if n, err := strconv.Atoi(raw); err == nil && n > 0 {
			pageNo = n
		}
	}
	page, err := store.List(ctx, UserFrom(ctx).ID, unread, pageNo, perPage)
	if err != nil {
		writeError(w, r, err)
		return
	}
	items := make([]any, 0, len(page.Items))
	for _, n := range page.Items {
		items = append(items, notificationsItemJSON(n))
	}
	// The controller projects each row explicitly; meta carries unread_count/total/current_page/last_page only.
	httpx.OK(w, items, "OK", map[string]any{
		"unread_count": page.UnreadCount,
		"total":        page.Total,
		"current_page": page.CurrentPage,
		"last_page":    page.LastPage,
	})
}

// notificationsItemJSON renders the exact Laravel projection: read_at/created_at via Carbon
// toIso8601String (microsecond UTC), order_id mapped unconditionally (null serialises as JSON null).
func notificationsItemJSON(n notifications.Item) map[string]any {
	item := map[string]any{
		"id":         n.ID,
		"type":       n.Type,
		"severity":   n.Severity,
		"title":      n.Title,
		"body":       n.Body,
		"order_id":   nil,
		"read_at":    nil,
		"created_at": n.CreatedAt.UTC().Format("2006-01-02T15:04:05+00:00"),
	}
	if n.OrderID != nil {
		item["order_id"] = *n.OrderID
	}
	if n.ReadAt != nil {
		item["read_at"] = n.ReadAt.UTC().Format("2006-01-02T15:04:05+00:00")
	}
	return item
}

func (s *Server) handleNotificationsAdminUnreadCount(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	count := 0
	if s.notificationsService().TableReady(ctx) {
		n, err := s.notificationsService().UnreadCount(ctx, UserFrom(ctx).ID)
		if err != nil {
			writeError(w, r, err)
			return
		}
		count = n
	}
	httpx.OK(w, map[string]any{"unread_count": count}, "OK", nil)
}

func (s *Server) handleNotificationsAdminReadAll(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	updated := int64(0)
	if s.notificationsService().TableReady(ctx) {
		n, err := s.notificationsService().MarkAllRead(ctx, UserFrom(ctx).ID)
		if err != nil {
			writeError(w, r, err)
			return
		}
		updated = n
	}
	httpx.OK(w, map[string]any{"updated": updated}, "OK", nil)
}

// ---- GLM media (Laravel-only slice) ---------------------------------------------------------------

// mediaResource renders Laravel's MediaResource projection.
func mediaResource(m *media.Media, base string) map[string]any {
	url := media.URL(m.Path)
	if base != "" {
		url = base + url
	}
	item := map[string]any{
		"id":         m.ID,
		"url":        url,
		"collection": m.Collection,
		"mime_type":  m.MimeType,
		"width":      nil,
		"height":     nil,
		"created_at": m.CreatedAt.UTC().Format("2006-01-02T15:04:05+00:00"),
	}
	if m.OriginalFilename != nil {
		item["original_filename"] = *m.OriginalFilename
	} else {
		item["original_filename"] = nil
	}
	if m.Size != nil {
		item["size"] = *m.Size
	} else {
		item["size"] = nil
	}
	if m.Width != nil {
		item["width"] = *m.Width
	}
	if m.Height != nil {
		item["height"] = *m.Height
	}
	return item
}

// mediaPolicyCreate ports MediaPolicy::create: 'user_avatar' is open to every authenticated role,
// every other collection is super_admin/agen only.
func mediaPolicyCreate(role, collection string) bool {
	if collection == "user_avatar" {
		return true
	}
	return role == "super_admin" || role == "agen"
}

// mediaPolicyDelete ports MediaPolicy::delete: a user_avatar row may be deleted by its owner only,
// any other collection by super_admin/agen.
func mediaPolicyDelete(role, collection string, createdBy, userID int64) bool {
	if collection == "user_avatar" {
		return createdBy == userID
	}
	return role == "super_admin" || role == "agen"
}

func (s *Server) handleMediaIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	// Laravel: $this->authorize('create', [Media::class, null]) → super_admin/agen only.
	if !mediaPolicyCreate(u.Role, "") {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	perPage, _ := strconv.Atoi(httpx.QueryString(r, "per_page"))
	if perPage < 1 {
		perPage = 30
	}
	page := 1
	if raw := httpx.QueryString(r, "page"); raw != "" {
		if n, err := strconv.Atoi(raw); err == nil && n > 0 {
			page = n
		}
	}
	// Laravel: where('collection','!=','user_avatar'), ->latest() (created_at DESC, ties undefined —
	// Go orders by id DESC; D-1-class deviation, compared as a set by the harness), optional collection filter.
	query := `FROM media WHERE collection != 'user_avatar'`
	args := []any{}
	if filter := httpx.QueryString(r, "collection"); filter != "" {
		query += ` AND collection = ?`
		args = append(args, filter)
	}
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) `+query, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	args = append(args, perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT id,path,collection,original_filename,mime_type,size,width,height,created_at `+
		query+` ORDER BY id DESC LIMIT ? OFFSET ?`, args...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	items := []any{}
	for rows.Next() {
		m, serr := scanMedia(rows)
		if serr != nil {
			writeError(w, r, serr)
			return
		}
		items = append(items, mediaResource(m, s.cfg.PublicURL))
	}
	if err := rows.Err(); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, items, "OK", map[string]any{"current_page": page, "last_page": lastPage, "total": total})
}

func scanMedia(rows *sql.Rows) (*media.Media, error) {
	var m media.Media
	var filename sql.NullString
	var size sql.NullInt64
	var width, height sql.NullInt64
	if err := rows.Scan(&m.ID, &m.Path, &m.Collection, &filename, &m.MimeType, &size, &width, &height, &m.CreatedAt); err != nil {
		return nil, err
	}
	if filename.Valid {
		m.OriginalFilename = &filename.String
	}
	if size.Valid {
		v := size.Int64
		m.Size = &v
	}
	if width.Valid {
		v := int(width.Int64)
		m.Width = &v
	}
	if height.Valid {
		v := int(height.Int64)
		m.Height = &v
	}
	return &m, nil
}

func (s *Server) handleMediaStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	// Laravel: FormRequest validation runs FIRST (its authorize() is true), then MediaPolicy::create.
	if err := r.ParseMultipartForm(12 << 20); err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{
			"file":       {i18n.ValidationMessage(LocaleFrom(ctx), "required", "file")},
			"collection": {i18n.ValidationMessage(LocaleFrom(ctx), "required", "collection")},
		}))
		return
	}
	fields := map[string][]string{}
	var content []byte
	var filename string
	file, header, ferr := r.FormFile("file")
	if ferr != nil {
		fields["file"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required", "file")}
	} else {
		content, ferr = io.ReadAll(file)
		_ = file.Close()
		if ferr != nil {
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, msg(ctx, "media.upload_failed")))
			return
		}
		filename = header.Filename
	}
	collection := r.FormValue("collection")
	if collection == "" {
		fields["collection"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required", "collection")}
	} else if _, ok := media.Collections[collection]; !ok {
		fields["collection"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "in", "collection")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	u := UserFrom(ctx)
	if !mediaPolicyCreate(u.Role, collection) {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	var created *media.Media
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		m, err := media.Store(ctx, tx, collection, media.Upload{Filename: filename, Content: content}, nil, nil, &u.ID)
		created = m
		return err
	})
	if err != nil {
		var p *media.Problem
		if errors.As(err, &p) {
			writeError(w, r, httpx.NewError(p.Status, msg(ctx, p.Message)))
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.Created(w, mediaResource(created, s.cfg.PublicURL), msg(ctx, "media.uploaded"))
}

func (s *Server) handleMediaDestroy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "media"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	var collection string
	var path string
	var createdBy sql.NullInt64
	qerr := s.database.QueryRowContext(ctx,
		`SELECT collection, path, created_by FROM media WHERE id = ?`, id).
		Scan(&collection, &path, &createdBy)
	if qerr != nil {
		if errors.Is(qerr, sql.ErrNoRows) {
			// Laravel route-model binding miss → ModelNotFoundException → localized not_found.
			writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.not_found")))
			return
		}
		writeError(w, r, qerr)
		return
	}
	u := UserFrom(ctx)
	if !mediaPolicyDelete(u.Role, collection, createdBy.Int64, u.ID) {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	if _, derr := s.database.ExecContext(ctx, `DELETE FROM media WHERE id = ?`, id); derr != nil {
		writeError(w, r, derr)
		return
	}
	_ = os.Remove(filepath.Join(media.Root, filepath.FromSlash(path)))
	httpx.OK(w, nil, msg(ctx, "media.deleted"), nil)
}

// ---- GLM admin agents (Laravel-only slice) --------------------------------------------------------

// agentDirectoryRow renders Laravel's AgentDirectoryResource for one user row.
func agentDirectoryRow(userID int64, name, email, phone, referralCode, status string, profile *map[string]any) map[string]any {
	row := map[string]any{
		"user_id":       userID,
		"name":          name,
		"email":         email,
		"phone":         nil,
		"referral_code": nil,
		"status":        status,
		"profile":       nil,
	}
	if phone != "" {
		row["phone"] = phone
	}
	if referralCode != "" {
		row["referral_code"] = referralCode
	}
	if profile != nil {
		row["profile"] = *profile
	}
	return row
}

// scanAgentDirectoryRows runs the shared admin-directory query and renders the resource rows.
func (s *Server) scanAgentDirectoryRows(ctx context.Context, query string, args ...any) ([]any, error) {
	rows, err := s.database.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []any{}
	for rows.Next() {
		var userID int64
		var name, email, status string
		var phone, referralCode sql.NullString
		var pID sql.NullInt64
		var pStore, pAddress, pPhone sql.NullString
		var pLat, pLon sql.NullString
		if err := rows.Scan(&userID, &name, &email, &phone, &referralCode, &status,
			&pID, &pStore, &pAddress, &pPhone, &pLat, &pLon); err != nil {
			return nil, err
		}
		var profile *map[string]any
		if pID.Valid {
			profile = &map[string]any{
				"id":         pID.Int64,
				"store_name": pStore.String,
				"address":    pAddress.String,
				"phone":      nil,
				"latitude":   pLat.String,
				"longitude":  pLon.String,
			}
			if pPhone.Valid {
				(*profile)["phone"] = pPhone.String
			}
		}
		items = append(items, agentDirectoryRow(userID, name, email, phone.String, referralCode.String, status, profile))
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return items, nil
}

const agentDirectoryQuery = `SELECT u.id,u.name,u.email,u.phone,u.referral_code,u.status,
	p.id,p.store_name,p.address,p.phone,p.latitude,p.longitude
	FROM users u JOIN roles r ON u.role_id=r.id AND r.slug='agen'
	LEFT JOIN agent_profiles p ON p.user_id=u.id
	WHERE u.deleted_at IS NULL `

func (s *Server) handleAdminAgentsIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	// Laravel orders by name only (ties undefined — D-1 class: the harness compares the SET).
	items, err := s.scanAgentDirectoryRows(ctx, agentDirectoryQuery+`ORDER BY u.name`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, items, "OK", nil)
}

func (s *Server) writeActivityLog(ctx context.Context, causerID int64, subjectType string, subjectID int64, event string, props map[string]any) error {
	var payload any
	if len(props) > 0 {
		buf, err := json.Marshal(props)
		if err != nil {
			return err
		}
		payload = string(buf)
	}
	_, err := s.database.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, created_at)
		VALUES (?, ?, ?, ?, NULL, ?, UTC_TIMESTAMP(6))`,
		causerID, subjectType, subjectID, event, payload)
	return err
}

// writeActivityLogWithDescription ports ActivityLogger::log with a non-null description (unlink).
func (s *Server) writeActivityLogWithDescription(ctx context.Context, causerID int64, subjectType string, subjectID int64, event, description string, props map[string]any) error {
	var payload any
	if len(props) > 0 {
		buf, err := json.Marshal(props)
		if err != nil {
			return err
		}
		payload = string(buf)
	}
	_, err := s.database.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, created_at)
		VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))`,
		causerID, subjectType, subjectID, event, description, payload)
	return err
}

// agentProfileStoreValidate ports StoreAgentProfileRequest::rules (authorize() is true → pure validation).
func agentProfileStoreValidate(ctx context.Context, d *db.DB, body map[string]json.RawMessage) (userID int64, storeName, address string, phone *string, lat, lon *float64, fields map[string][]string) {
	fields = map[string][]string{}
	locale := LocaleFrom(ctx)
	str := func(key string) (string, bool) {
		raw, ok := body[key]
		if !ok {
			return "", false
		}
		var v string
		if err := json.Unmarshal(raw, &v); err != nil {
			return "", false
		}
		return v, true
	}
	// user_id: required integer exists
	rawUID, ok := body["user_id"]
	if !ok {
		fields["user_id"] = []string{i18n.ValidationMessage(locale, "required", "user_id")}
	} else if err := json.Unmarshal(rawUID, &userID); err != nil {
		fields["user_id"] = []string{i18n.ValidationMessage(locale, "integer", "user_id")}
	} else {
		var n int
		if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM users WHERE id=?`, userID).Scan(&n); err != nil || n == 0 {
			fields["user_id"] = []string{i18n.ValidationMessage(locale, "exists", "user_id")}
		}
	}
	// store_name: required string max:150
	v, ok := str("store_name")
	switch {
	case !ok:
		fields["store_name"] = []string{i18n.ValidationMessage(locale, "required", "store_name")}
	case len(v) > 150:
		fields["store_name"] = []string{i18n.ValidationMessage(locale, "max.string", "store_name", "max", "150")}
	default:
		storeName = v
	}
	// address: required string
	v, ok = str("address")
	if !ok {
		fields["address"] = []string{i18n.ValidationMessage(locale, "required", "address")}
	} else {
		address = v
	}
	// phone: nullable string max:20
	if raw, present := body["phone"]; present && string(raw) != "null" {
		var p string
		if err := json.Unmarshal(raw, &p); err == nil {
			if len(p) > 20 {
				fields["phone"] = []string{i18n.ValidationMessage(locale, "max.string", "phone", "max", "20")}
			} else {
				phone = &p
			}
		}
	}
	// latitude/longitude: required numeric between
	coord := func(key string, min, max float64) *float64 {
		raw, ok := body[key]
		if !ok {
			fields[key] = []string{i18n.ValidationMessage(locale, "required", key)}
			return nil
		}
		var f float64
		if err := json.Unmarshal(raw, &f); err != nil {
			fields[key] = []string{i18n.ValidationMessage(locale, "numeric", key)}
			return nil
		}
		if f < min || f > max {
			fields[key] = []string{i18n.ValidationMessage(locale, "between.numeric", key, "min", strconv.FormatFloat(min, 'f', -1, 64), "max", strconv.FormatFloat(max, 'f', -1, 64))}
			return nil
		}
		return &f
	}
	lat = coord("latitude", -90, 90)
	lon = coord("longitude", -180, 180)
	return userID, storeName, address, phone, lat, lon, fields
}

func (s *Server) handleAdminAgentsStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	// Laravel: FormRequest validation (authorize() true) runs during injection, BEFORE the controller
	// body's Gate::authorize — so an invalid payload is a 422 even for an unauthorized role, while the
	// route middleware role:super_admin already refused non-super_admin callers with 403 earlier.
	userID, storeName, address, phone, lat, lon, fields := agentProfileStoreValidate(ctx, s.database, body)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	u := UserFrom(ctx)
	var roleSlug string
	var status string
	qerr := s.database.QueryRowContext(ctx,
		`SELECT r.slug,u.status FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=? AND u.deleted_at IS NULL`, userID).
		Scan(&roleSlug, &status)
	if qerr != nil {
		// Validation's exists:users already covers missing ids; a race lands here as the 404 findOrFail renders.
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.not_found")))
		return
	}
	if roleSlug != "agen" {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, msg(ctx, "agent.not_an_agent")))
		return
	}
	var exists int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM agent_profiles WHERE user_id=?`, userID).Scan(&exists); err != nil {
		writeError(w, r, err)
		return
	}
	if exists > 0 {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, msg(ctx, "agent.profile_already_exists")))
		return
	}
	var profileID int64
	var phoneArg any
	if phone != nil {
		phoneArg = *phone
	}
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		res, err := tx.ExecContext(ctx, `INSERT INTO agent_profiles (user_id,store_name,address,phone,latitude,longitude,created_at,updated_at)
			VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			userID, storeName, address, phoneArg, *lat, *lon)
		if err != nil {
			return err
		}
		profileID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		return s.writeActivityLog(ctx, u.ID, `App\Models\AgentProfile`, profileID, "agent_profile.created",
			map[string]any{"agent_user_id": userID, "actor_role": u.Role})
	})
	if err != nil {
		writeError(w, r, err)
		return
	}
	items, err := s.scanAgentDirectoryRows(ctx, agentDirectoryQuery+` AND u.id=?`, userID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, items[0], "Created")
}

// agentProfileUpdateValidate ports UpdateAgentProfileRequest::rules (sometimes → ABSENT keys skip).
func agentProfileUpdateValidate(ctx context.Context, body map[string]json.RawMessage) (storeName, address string, phone *string, lat, lon *float64, set map[string]bool, fields map[string][]string) {
	fields = map[string][]string{}
	set = map[string]bool{}
	locale := LocaleFrom(ctx)
	// store_name: sometimes|string|max:150 ; address: sometimes|string
	for _, key := range []string{"store_name", "address"} {
		raw, ok := body[key]
		if !ok {
			continue
		}
		set[key] = true
		var v string
		if err := json.Unmarshal(raw, &v); err != nil {
			fields[key] = []string{i18n.ValidationMessage(locale, "string", key)}
			continue
		}
		if key == "store_name" && len(v) > 150 {
			fields["store_name"] = []string{i18n.ValidationMessage(locale, "max.string", "store_name", "max", "150")}
			continue
		}
		if key == "store_name" {
			storeName = v
		} else {
			address = v
		}
	}
	// phone: sometimes|nullable|string|max:20
	if raw, ok := body["phone"]; ok {
		set["phone"] = true
		if string(raw) != "null" {
			var p string
			if err := json.Unmarshal(raw, &p); err != nil {
				fields["phone"] = []string{i18n.ValidationMessage(locale, "string", "phone")}
			} else if len(p) > 20 {
				fields["phone"] = []string{i18n.ValidationMessage(locale, "max.string", "phone", "max", "20")}
			} else {
				phone = &p
			}
		}
	}
	// latitude/longitude: sometimes|numeric|between
	coord := func(key string, min, max float64) {
		raw, ok := body[key]
		if !ok {
			return
		}
		set[key] = true
		var f float64
		if err := json.Unmarshal(raw, &f); err != nil {
			fields[key] = []string{i18n.ValidationMessage(locale, "numeric", key)}
			return
		}
		if f < min || f > max {
			fields[key] = []string{i18n.ValidationMessage(locale, "between.numeric", key, "min", strconv.FormatFloat(min, 'f', -1, 64), "max", strconv.FormatFloat(max, 'f', -1, 64))}
			return
		}
		if key == "latitude" {
			lat = &f
		} else {
			lon = &f
		}
	}
	coord("latitude", -90, 90)
	coord("longitude", -180, 180)
	return storeName, address, phone, lat, lon, set, fields
}

func (s *Server) handleAdminAgentsUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "agentProfile"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	var body map[string]json.RawMessage
	if derr := httpx.DecodeJSON(r, &body); derr != nil {
		writeError(w, r, derr)
		return
	}
	storeName, address, phone, lat, lon, set, fields := agentProfileUpdateValidate(ctx, body)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	u := UserFrom(ctx)
	// Laravel: route-model binding FIRST (404 for a missing profile), then Gate, then validation.
	var pUserID int64
	qerr := s.database.QueryRowContext(ctx, `SELECT user_id FROM agent_profiles WHERE id=?`, id).Scan(&pUserID)
	if qerr != nil {
		// Verified: the implicit route-model binding miss escapes as NotFoundHttpException, so the LOCALIZED
		// endpoint_not_found renders (not the manual-find not_found other endpoints use).
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var oldStore, oldAddress, oldPhone, oldLat, oldLon sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT store_name,address,phone,latitude,longitude FROM agent_profiles WHERE id=? FOR UPDATE`, id).
			Scan(&oldStore, &oldAddress, &oldPhone, &oldLat, &oldLon); err != nil {
			return err
		}
		newStore, newAddress := oldStore.String, oldAddress.String
		var newPhone *string
		if oldPhone.Valid {
			v := oldPhone.String
			newPhone = &v
		}
		newLat, newLon := oldLat.String, oldLon.String
		if set["store_name"] {
			newStore = storeName
		}
		if set["address"] {
			newAddress = address
		}
		if set["phone"] {
			newPhone = phone
		}
		if set["latitude"] {
			newLat = strconv.FormatFloat(*lat, 'f', 7, 64)
		}
		if set["longitude"] {
			newLon = strconv.FormatFloat(*lon, 'f', 7, 64)
		}
		if _, err := tx.ExecContext(ctx, `UPDATE agent_profiles SET store_name=?,address=?,phone=?,latitude=?,longitude=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`,
			newStore, newAddress, newPhone, newLat, newLon, id); err != nil {
			return err
		}
		old := map[string]any{"store_name": oldStore.String, "address": oldAddress.String, "latitude": oldLat.String, "longitude": oldLon.String, "phone": nil}
		if oldPhone.Valid {
			old["phone"] = oldPhone.String
		}
		new := map[string]any{"store_name": newStore, "address": newAddress, "latitude": newLat, "longitude": newLon, "phone": nil}
		if newPhone != nil {
			new["phone"] = *newPhone
		}
		return s.writeActivityLog(ctx, u.ID, `App\Models\AgentProfile`, id, "agent_profile.updated",
			map[string]any{"actor_role": u.Role, "old": old, "new": new})
	})
	if err != nil {
		writeError(w, r, err)
		return
	}
	items, err := s.scanAgentDirectoryRows(ctx, agentDirectoryQuery+` AND p.id=?`, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, items[0], "OK", nil)
}

func (s *Server) handleAdminAgentsDestroy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "agentProfile"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	u := UserFrom(ctx)
	var pUserID int64
	qerr := s.database.QueryRowContext(ctx, `SELECT user_id FROM agent_profiles WHERE id=?`, id).Scan(&pUserID)
	if qerr != nil {
		// Verified: the implicit route-model binding miss escapes as NotFoundHttpException, so the LOCALIZED
		// endpoint_not_found renders (not the manual-find not_found other endpoints use).
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, `DELETE FROM agent_profiles WHERE id=?`, id); err != nil {
			return err
		}
		// Laravel: ActivityLogger::log(..., $request->user(), ...) — the SUBJECT is the acting user here.
		return s.writeActivityLog(ctx, u.ID, `App\Models\User`, u.ID, "agent_profile.deleted",
			map[string]any{"agent_user_id": pUserID, "actor_role": u.Role})
	})
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(ctx, "agent.profile_deleted"), nil)
}

func (s *Server) handleAdminAgentsToggleStatus(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "agent"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	u := UserFrom(ctx)
	var roleSlug, status string
	qerr := s.database.QueryRowContext(ctx,
		`SELECT r.slug,u.status FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=? AND u.deleted_at IS NULL`, id).
		Scan(&roleSlug, &status)
	if qerr != nil {
		// Verified: the user route-model binding miss renders the LOCALIZED endpoint_not_found → localized not_found.
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	if roleSlug != "agen" {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, msg(ctx, "agent.not_an_agent")))
		return
	}
	newStatus := "inactive"
	if status != "active" {
		newStatus = "active"
	}
	if _, err := s.database.ExecContext(ctx, `UPDATE users SET status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, newStatus, id); err != nil {
		writeError(w, r, err)
		return
	}
	if err := s.writeActivityLog(ctx, u.ID, `App\Models\User`, id, "agent.status_changed",
		map[string]any{"status": newStatus, "actor_role": u.Role}); err != nil {
		writeError(w, r, err)
		return
	}
	items, err := s.scanAgentDirectoryRows(ctx, agentDirectoryQuery+` AND u.id=?`, id)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, items[0], "OK", nil)
}

// ---- GLM dispatch (Laravel-only slice) ------------------------------------------------------------

func dispatchActor(r *http.Request) dispatch.Actor {
	u := UserFrom(r.Context())
	return dispatch.Actor{ID: u.ID, Role: u.Role, AgentID: derefInt(u.AgentID), IsSuper: u.Role == "super_admin", Status: u.Status}
}

func derefInt(v *int64) int64 {
	if v == nil {
		return 0
	}
	return *v
}

func (s *Server) handleDispatchCouriers(w http.ResponseWriter, r *http.Request) {
	rows, err := s.dispatchService().Couriers(r.Context(), dispatchActor(r), r.URL.Query())
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleDispatchExpeditions(w http.ResponseWriter, r *http.Request) {
	result, err := s.dispatchService().Expeditions(r.Context(), dispatchActor(r), r.URL.Query())
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result["data"], "OK", result["meta"])
}

func (s *Server) handleDispatchRefusals(w http.ResponseWriter, r *http.Request) {
	result, err := s.dispatchService().Refusals(r.Context(), dispatchActor(r), r.URL.Query())
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result["data"], "OK", result["meta"])
}

func (s *Server) handleDispatchRegions(w http.ResponseWriter, r *http.Request) {
	result, err := s.dispatchService().Regions(r.Context(), dispatchActor(r), r.URL.Query())
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) dispatchService() *dispatch.Service { return dispatch.New(s.database) }

func writeDispatchError(w http.ResponseWriter, r *http.Request, err error) {
	var de *dispatch.DomainError
	if errors.As(err, &de) {
		m := de.Message
		if de.Status == http.StatusNotFound {
			// Laravel model binding renders ModelNotFoundException as the generic route-not-found message.
			m = msg(r.Context(), "system.endpoint_not_found")
		}
		writeError(w, r, httpx.NewError(de.Status, m))
		return
	}
	writeError(w, r, err)
}

func (s *Server) handleRefusalHandBack(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(httpx.PathValue(r, "refusal"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	result, err := s.dispatchService().HandBack(r.Context(), dispatchActor(r), id)
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleRefusalReceive(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(httpx.PathValue(r, "refusal"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	result, err := s.dispatchService().Receive(r.Context(), dispatchActor(r), id)
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleRefusalDispose(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "refusal"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	var body struct {
		Action string  `json:"action"`
		Note   *string `json:"note"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.Action == "" {
		fields["action"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required", "action")}
	} else if body.Action != "redelivery" && body.Action != "return_to_warehouse" {
		fields["action"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "in", "action")}
	}
	if body.Note != nil && len(*body.Note) > 255 {
		fields["note"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "255")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	note := ""
	if body.Note != nil {
		note = *body.Note
	}
	result, err := s.dispatchService().Dispose(ctx, dispatchActor(r), id, body.Action, note)
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

var trackingRe = regexp.MustCompile(`^[A-Za-z0-9\-]+$`)

func (s *Server) handleExpeditionHandover(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var carrier, tracking string
	var evidence *media.Upload
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		// PHP does not populate $_POST for multipart PATCH bodies, so Laravel's validate()
		// sees an empty request and fails every required rule. The success path is only
		// reachable through Laravel form-method spoofing (POST + _method=PATCH), handled
		// by handleExpeditionHandoverSpoofed. Mirror the reference exactly.
		if r.URL.Query().Get("_method") != "POST" {
			_ = r.ParseMultipartForm(8 << 20)
		}
	} else {
		var body struct {
			Carrier        string `json:"carrier"`
			TrackingNumber string `json:"tracking_number"`
		}
		_ = httpx.DecodeJSON(r, &body)
		carrier = body.Carrier
		tracking = body.TrackingNumber
	}
	fields := fieldErrors{}
	if carrier == "" {
		fields.add("carrier", i18n.ValidationMessage(LocaleFrom(ctx), "required", "carrier"))
	}
	if tracking == "" {
		fields.add("tracking_number", i18n.ValidationMessage(LocaleFrom(ctx), "required", "tracking_number"))
	}
	if evidence == nil {
		fields.add("evidence", i18n.ValidationMessage(LocaleFrom(ctx), "required", "evidence"))
	}
	if carrier != "" && len([]rune(carrier)) > 30 {
		fields.add("carrier", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "carrier"))
	}
	if tracking != "" {
		if len([]rune(tracking)) < 6 {
			fields.add("tracking_number", i18n.ValidationMessage(LocaleFrom(ctx), "min.string", "tracking_number"))
		}
		if len([]rune(tracking)) > 40 {
			fields.add("tracking_number", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "tracking_number"))
		}
		if !trackingRe.MatchString(tracking) {
			fields.add("tracking_number", i18n.ValidationMessage(LocaleFrom(ctx), "regex", "tracking_number"))
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	if err := s.dispatchService().ExpeditionHandover(ctx, dispatchActor(r), id, carrier, tracking, evidence); err != nil {
		var de *dispatch.DomainError
		if errors.As(err, &de) {
			m := de.Message
			if de.Status == http.StatusNotFound || de.Message == "" {
				m = msg(ctx, "system.endpoint_not_found")
			}
			writeError(w, r, httpx.NewError(de.Status, m))
			return
		}
		writeError(w, r, err)
		return
	}
	orderID, oerr := s.dispatchService().ShipmentOrderID(ctx, id)
	if oerr != nil {
		writeError(w, r, oerr)
		return
	}
	s.renderOrderOK(w, r, orderID, msg(ctx, "courier.assigned"))
}

// handleExpeditionHandoverSpoofed mirrors Laravel form-method spoofing: a multipart POST with
// _method=PATCH is routed to the PATCH handler by the reference framework; the multipart body
// IS parsed for POST (PHP fills $_POST), making the success path reachable.
func (s *Server) handleExpeditionHandoverSpoofed(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseMultipartForm(8 << 20); err != nil {
		writeError(w, r, httpx.NewError(http.StatusBadRequest, msg(r.Context(), "system.generic_error")))
		return
	}
	if strings.ToUpper(r.FormValue("_method")) != "PATCH" {
		writeError(w, r, httpx.NewError(http.StatusMethodNotAllowed, msg(r.Context(), "system.generic_error")))
		return
	}
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	carrier := r.FormValue("carrier")
	tracking := r.FormValue("tracking_number")
	var evidence *media.Upload
	if files, ok := r.MultipartForm.File["evidence"]; ok && len(files) > 0 {
		handle, oerr := files[0].Open()
		if oerr == nil {
			content, rerr := io.ReadAll(io.LimitReader(handle, 12<<20))
			handle.Close()
			if rerr == nil {
				evidence = &media.Upload{Filename: files[0].Filename, Content: content}
			}
		}
	}
	fields := fieldErrors{}
	if carrier == "" {
		fields.add("carrier", i18n.ValidationMessage(LocaleFrom(ctx), "required", "carrier"))
	}
	if tracking == "" {
		fields.add("tracking_number", i18n.ValidationMessage(LocaleFrom(ctx), "required", "tracking_number"))
	}
	if evidence == nil {
		fields.add("evidence", i18n.ValidationMessage(LocaleFrom(ctx), "required", "evidence"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	if err := s.dispatchService().ExpeditionHandover(ctx, dispatchActor(r), id, carrier, tracking, evidence); err != nil {
		var de *dispatch.DomainError
		if errors.As(err, &de) {
			m := de.Message
			if de.Status == http.StatusNotFound || de.Message == "" {
				m = msg(ctx, "system.endpoint_not_found")
			}
			writeError(w, r, httpx.NewError(de.Status, m))
			return
		}
		writeError(w, r, err)
		return
	}
	orderID, oerr := s.dispatchService().ShipmentOrderID(ctx, id)
	if oerr != nil {
		writeError(w, r, oerr)
		return
	}
	s.renderOrderOK(w, r, orderID, msg(ctx, "courier.assigned"))
}

func (s *Server) handleShipmentReceipt(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	receipt, aerr := s.dispatchService().Receipt(ctx, dispatchActor(r), id)
	if aerr != nil {
		var de *dispatch.DomainError
		if errors.As(aerr, &de) {
			m := de.Message
			if de.Status == http.StatusNotFound || de.Message == "" {
				m = msg(ctx, "system.endpoint_not_found")
			}
			writeError(w, r, httpx.NewError(de.Status, m))
			return
		}
		writeError(w, r, aerr)
		return
	}
	if err := s.dispatchService().RecordReceiptPrint(ctx, dispatchActor(r), id); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, receipt, "OK", nil)
}

func (s *Server) handleDispatchSplitBatch(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "orderItem"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		Quantity *int64  `json:"quantity"`
		Note     *string `json:"note"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Quantity == nil {
		fields.add("quantity", i18n.ValidationMessage(LocaleFrom(ctx), "required", "quantity"))
	} else if *body.Quantity < 1 {
		fields.add("quantity", i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", "quantity", "min", "1"))
	}
	if body.Note != nil && len([]rune(*body.Note)) > 255 {
		fields.add("note", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "255"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	note := ""
	if body.Note != nil {
		note = *body.Note
	}
	result, aerr := s.dispatchService().SplitBatch(ctx, dispatchActor(r), id, *body.Quantity, note)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.Created(w, result, "Created")
}

func (s *Server) handleDispatchRequestFulfillment(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "order"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		Note *string `json:"note"`
	}
	_ = httpx.DecodeJSON(r, &body)
	if body.Note != nil && len([]rune(*body.Note)) > 255 {
		writeError(w, r, httpx.Validation(map[string][]string{"note": {
			i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "255")}}))
		return
	}
	note := ""
	if body.Note != nil {
		note = *body.Note
	}
	result, aerr := s.dispatchService().RequestFulfillment(ctx, dispatchActor(r), id, note)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleReturnItemAssignCourier(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "item"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		CourierID         *int64 `json:"courier_id"`
		ExpectedCourierID *int64 `json:"expected_courier_id"`
	}
	_ = httpx.DecodeJSON(r, &body)
	if body.CourierID == nil {
		writeError(w, r, httpx.Validation(map[string][]string{"courier_id": {
			i18n.ValidationMessage(LocaleFrom(ctx), "required", "courier_id")}}))
		return
	}
	var exists bool
	if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM couriers WHERE id = ?)`, *body.CourierID).
		Scan(&exists); err == nil && !exists {
		writeError(w, r, httpx.Validation(map[string][]string{"courier_id": {
			i18n.ValidationMessage(LocaleFrom(ctx), "exists", "courier_id")}}))
		return
	}
	var expected sql.NullInt64
	if body.ExpectedCourierID != nil {
		expected = sql.NullInt64{Int64: *body.ExpectedCourierID, Valid: true}
	}
	result, aerr := s.dispatchService().AssignReturnPickupCourier(ctx, dispatchActor(r), id, *body.CourierID, expected)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.OK(w, result, msg(ctx, "courier.assigned"), nil)
}

func (s *Server) handleDeliveryVerifications(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	rows, aerr := s.dispatchService().DeliveryVerifications(ctx, dispatchActor(r), id)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleDeliveryVerificationStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		Outcome string  `json:"outcome"`
		Note    *string `json:"note"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Outcome == "" {
		fields.add("outcome", i18n.ValidationMessage(LocaleFrom(ctx), "required", "outcome"))
	} else if body.Outcome != "received" && body.Outcome != "not_received" && body.Outcome != "return" {
		fields.add("outcome", i18n.ValidationMessage(LocaleFrom(ctx), "in", "outcome"))
	}
	if body.Note != nil && len([]rune(*body.Note)) > 2000 {
		fields.add("note", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "2000"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	// R-03: the Idempotency-Key header is mandatory.
	key := strings.TrimSpace(r.Header.Get("Idempotency-Key"))
	if key == "" {
		writeError(w, r, &httpx.Error{Status: 422, Message: "Header Idempotency-Key wajib diisi.",
			ErrorsAny: map[string]any{"idempotency_key": msg(ctx, "system.field_required")}})
		return
	}
	if len(key) > 100 {
		writeError(w, r, &httpx.Error{Status: 422, Message: "Header Idempotency-Key tidak valid (maksimal 100 karakter).",
			ErrorsAny: map[string]any{"idempotency_key": msg(ctx, "system.field_invalid")}})
		return
	}
	note := ""
	if body.Note != nil {
		note = *body.Note
	}
	record, aerr := s.dispatchService().RecordDeliveryVerification(ctx, dispatchActor(r), id, body.Outcome, note, key)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.Created(w, record, "Verifikasi pengiriman berhasil dicatat.")
}

func (s *Server) handleShipmentAssignCourier(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		CourierID      *int64 `json:"courier_id"`
		ExecutorUserID *int64 `json:"executor_user_id"`
	}
	_ = httpx.DecodeJSON(r, &body)
	if body.ExecutorUserID == nil && body.CourierID == nil {
		writeError(w, r, httpx.Validation(map[string][]string{"courier_id": {i18n.ValidationMessage(LocaleFrom(ctx), "required", "courier_id")}}))
		return
	}
	var courierID, executorID int64
	if body.CourierID != nil {
		courierID = *body.CourierID
	}
	if body.ExecutorUserID != nil {
		executorID = *body.ExecutorUserID
		if body.CourierID != nil {
			writeError(w, r, httpx.Validation(map[string][]string{"courier_id": {
				"The courier id field prohibits executor user id from being present."}}))
			return
		}
	}
	// Laravel validates exists:couriers,id in the FormRequest (422) before the controller.
	if courierID > 0 && executorID == 0 {
		var exists bool
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM couriers WHERE id = ?)`, courierID).
			Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if !exists {
			writeError(w, r, httpx.Validation(map[string][]string{"courier_id": {
				i18n.ValidationMessage(LocaleFrom(ctx), "exists", "courier_id")}}))
			return
		}
	}
	orderID, aerr := s.dispatchService().AssignCourier(ctx, dispatchActor(r), id, courierID, executorID)
	if aerr != nil {
		var de *dispatch.DomainError
		if errors.As(aerr, &de) {
			m := de.Message
			if de.Status == http.StatusNotFound {
				m = msg(ctx, "system.endpoint_not_found")
			}
			writeError(w, r, httpx.NewError(de.Status, m))
			return
		}
		writeError(w, r, aerr)
		return
	}
	s.renderOrderOK(w, r, orderID, msg(ctx, "courier.assigned"))
}

func (s *Server) handleShipmentEligibleCouriers(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	rows, aerr := s.dispatchService().EligibleCouriers(ctx, dispatchActor(r), id)
	if aerr != nil {
		var de *dispatch.DomainError
		if errors.As(aerr, &de) {
			m := de.Message
			if de.Status == http.StatusNotFound || de.Message == "" {
				m = msg(ctx, "system.endpoint_not_found")
			}
			writeError(w, r, httpx.NewError(de.Status, m))
			return
		}
		writeError(w, r, aerr)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

// renderOrderOK loads the order and renders the OrderResource projection Laravel returns.
func (s *Server) renderOrderOK(w http.ResponseWriter, r *http.Request, orderID int64, message string) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	svc := s.orderService()
	order, lerr := svc.LoadOrderForRead(ctx, orderID, false)
	if lerr != nil {
		writeError(w, r, orderError(ctx, lerr))
		return
	}
	// The reference's assign/handover responses load ONLY items + shipments.courier.user —
	// konsumen/sales/korsal/returns/payment stay whenLoaded-absent, exactly like Laravel.
	order.LoadItemShipment = false
	order.LoadActors = false
	order.LoadPaymentTransactions = false
	order.LoadReturns = false
	order.LoadDeliveryVerifications = false
	order.Payment = nil
	rendered, rerr := orders.Render(ctx, svc, order, viewerOf(actor))
	if rerr != nil {
		writeError(w, r, rerr)
		return
	}
	httpx.OK(w, rendered, message, nil)
}
func (s *Server) handleShipmentStatus(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	var body struct {
		Status string `json:"status"`
	}
	_ = httpx.DecodeJSON(r, &body)
	status := body.Status
	if status == "" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": {i18n.ValidationMessage(LocaleFrom(ctx), "required", "status")}}))
		return
	}
	if status != "dikirim" && status != "terkirim" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": {i18n.ValidationMessage(LocaleFrom(ctx), "in", "status")}}))
		return
	}
	if err := s.dispatchService().TransitionShipment(ctx, dispatchActor(r), id, status); err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(ctx, "order.status_updated"), nil)
}

func (s *Server) handleShipmentItemStatus(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	shipmentID, err := strconv.ParseInt(httpx.PathValue(r, "shipment"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	itemID, err := strconv.ParseInt(httpx.PathValue(r, "orderItem"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	var body struct {
		Status string `json:"status"`
	}
	_ = httpx.DecodeJSON(r, &body)
	status := body.Status
	if status == "" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": {i18n.ValidationMessage(LocaleFrom(ctx), "required", "status")}}))
		return
	}
	if status != "dikirim" && status != "terkirim" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": {i18n.ValidationMessage(LocaleFrom(ctx), "in", "status")}}))
		return
	}
	if err := s.dispatchService().TransitionOrderItem(ctx, dispatchActor(r), shipmentID, itemID, status); err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(ctx, "order.status_updated"), nil)
}

func (s *Server) handleNotificationsAdminMarkRead(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	raw := httpx.PathValue(r, "id")
	id, err := strconv.ParseInt(raw, 10, 64)
	if err != nil {
		// Laravel declares ->whereNumber('id'): a non-numeric id never matched the route, and Laravel
		// renders the route-not-found fallback BEFORE the api middleware group runs, so the message is
		// always in the app default locale (en) — verified against the reference, unlike the in-group
		// localized 404s below.
		writeError(w, r, httpx.NewError(http.StatusNotFound, i18n.Translate("en", "system.endpoint_not_found")))
		return
	}
	store := s.notificationsService()
	if !store.TableReady(ctx) {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.not_found")))
		return
	}
	item, err := store.MarkRead(ctx, UserFrom(ctx).ID, id)
	if err != nil {
		if errors.Is(err, notifications.ErrNotFound) {
			// Same answer for "not yours" and "does not exist": no existence oracle across accounts.
			writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.not_found")))
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"id": item.ID, "read_at": item.ReadAt.UTC().Format("2006-01-02T15:04:05+00:00")}, "OK", nil)
}
