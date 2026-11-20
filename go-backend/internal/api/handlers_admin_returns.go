package api

import (
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func (s *Server) handleAdminReturnsIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	perPage := queryInteger(r, "per_page", 15)
	if perPage <= 0 {
		writeError(w, r, httpx.NewError(http.StatusInternalServerError, msg(ctx, "system.server_error")))
		return
	}
	page := queryInteger(r, "page", 1)
	if page < 1 {
		page = 1
	}
	offset := int64(page-1) * int64(perPage)

	conditions := []string{}
	args := []any{}
	if actor.Role != "super_admin" {
		conditions = append(conditions, "o.agent_id=?")
		args = append(args, derefInt(actor.AgentID))
	} else if raw := strings.TrimSpace(r.URL.Query().Get("agent_id")); raw != "" {
		conditions = append(conditions, "o.agent_id=?")
		args = append(args, queryInteger(r, "agent_id", 0))
	}
	if raw := strings.TrimSpace(r.URL.Query().Get("status")); raw != "" {
		statuses := []string{}
		for _, status := range strings.Split(raw, ",") {
			status = strings.TrimSpace(status)
			if status != "" && status != "0" {
				statuses = append(statuses, status)
			}
		}
		if len(statuses) == 0 {
			conditions = append(conditions, "1=0")
		} else {
			markers := make([]string, len(statuses))
			for i, status := range statuses {
				markers[i] = "?"
				args = append(args, status)
			}
			conditions = append(conditions, "r.status IN ("+strings.Join(markers, ",")+")")
		}
	}
	where := ""
	if len(conditions) > 0 {
		where = " WHERE " + strings.Join(conditions, " AND ")
	}
	var total int64
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM returns r JOIN orders o ON o.id=r.order_id`+where, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	queryArgs := append(append([]any{}, args...), perPage, offset)
	rows, err := s.database.QueryContext(ctx, `SELECT r.id FROM returns r JOIN orders o ON o.id=r.order_id`+where+
		` ORDER BY r.created_at DESC LIMIT ? OFFSET ?`, queryArgs...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	ids := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		writeError(w, r, err)
		return
	}
	if err := rows.Close(); err != nil {
		writeError(w, r, err)
		return
	}
	data := make([]map[string]any, 0, len(ids))
	for _, id := range ids {
		resource, err := orders.LoadReturnRequestResource(ctx, s.database, id)
		if err != nil {
			writeError(w, r, err)
			return
		}
		data = append(data, resource)
	}
	lastPage := (total + int64(perPage) - 1) / int64(perPage)
	if lastPage < 1 {
		lastPage = 1
	}
	httpx.OK(w, data, "OK", map[string]any{"current_page": page, "last_page": lastPage, "total": total})
}

func queryInteger(r *http.Request, key string, fallback int) int {
	raw := strings.TrimSpace(r.URL.Query().Get(key))
	if raw == "" {
		return fallback
	}
	value, err := strconv.Atoi(raw)
	if err != nil {
		return fallback
	}
	return value
}
