package api

import (
	"context"
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/notifications"
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
}

// matchesGLMLaravelOnlyRoute is consulted by implementsRoute (inventory.go) so the 501-vs-404
// decision and the /api/v1/_parity report stay derived from this single slice.
func matchesGLMLaravelOnlyRoute(method, path string) bool {
	switch method + " " + path {
	case
		"GET /api/v1/notifications/admin",
		"GET /api/v1/notifications/admin/unread-count",
		"POST /api/v1/notifications/admin/read-all":
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
