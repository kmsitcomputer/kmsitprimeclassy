package api

import (
	"database/sql"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

// handleDispatchHistory ports DispatchController::history and its resource projection. The
// endpoint is intentionally operational-only: it never selects financial or street-address data.
func (s *Server) handleDispatchHistory(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	q := r.URL.Query()
	actor := UserFrom(ctx)
	agentID := derefInt(actor.AgentID)
	if actor.Role == "super_admin" {
		selected, err := strconv.ParseInt(strings.TrimSpace(q.Get("agent_id")), 10, 64)
		if err != nil || selected <= 0 {
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "agent_id wajib diisi untuk super_admin."))
			return
		}
		var exists bool
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles ro ON ro.id=u.role_id WHERE u.id=? AND ro.slug='agen' AND u.deleted_at IS NULL)`, selected).Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if !exists {
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Agen tidak ditemukan."))
			return
		}
		agentID = selected
	}

	fields := map[string][]string{}
	for _, key := range []string{"delivered_from", "delivered_to"} {
		if raw := strings.TrimSpace(q.Get(key)); raw != "" {
			if _, err := time.Parse("2006-01-02", raw); err != nil {
				fields[key] = []string{strings.ReplaceAll(strings.ReplaceAll(msg(ctx, "validation.date_format"), ":attribute", strings.ReplaceAll(key, "_", " ")), ":format", "Y-m-d")}
			}
		}
	}
	if raw := strings.TrimSpace(q.Get("courier_id")); raw != "" {
		if n, err := strconv.ParseInt(raw, 10, 64); err != nil {
			fields["courier_id"] = []string{strings.ReplaceAll(msg(ctx, "validation.integer"), ":attribute", "courier id")}
		} else if n < 1 {
			fields["courier_id"] = []string{strings.ReplaceAll(msg(ctx, "validation.min.numeric"), ":attribute", "courier id")}
		}
	}
	if raw := q.Get("search"); len([]rune(raw)) > 60 {
		fields["search"] = []string{strings.ReplaceAll(strings.ReplaceAll(msg(ctx, "validation.max.string"), ":attribute", "search"), ":max", "60")}
	}
	if raw := strings.TrimSpace(q.Get("per_page")); raw != "" {
		if n, err := strconv.Atoi(raw); err != nil {
			fields["per_page"] = []string{strings.ReplaceAll(msg(ctx, "validation.integer"), ":attribute", "per page")}
		} else if n < 1 {
			fields["per_page"] = []string{strings.ReplaceAll(strings.ReplaceAll(msg(ctx, "validation.min.numeric"), ":attribute", "per page"), ":min", "1")}
		}
	}
	if len(fields) != 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}

	perPage := 15
	if raw := q.Get("per_page"); raw != "" {
		perPage, _ = strconv.Atoi(raw)
		if perPage > 50 {
			perPage = 50
		}
	}
	page := httpx.QueryInt(r, "page", 1, 1)
	args := []any{agentID}
	where := []string{"sh.status='delivered'", "sh.delivered_at IS NOT NULL", "o.agent_id=?"}
	for _, region := range []string{"province_id", "regency_id", "district_id", "village_id"} {
		if raw := strings.TrimSpace(q.Get(region)); raw != "" {
			where = append(where, "o."+region+"=?")
			args = append(args, raw)
		}
	}
	if search := q.Get("search"); search != "" {
		// Laravel escapes SQL LIKE metacharacters before adding its contains wildcards.
		search = strings.NewReplacer("%", "\\%", "_", "\\_").Replace(search)
		where = append(where, "o.order_no LIKE ?")
		args = append(args, "%"+search+"%")
	}
	jakarta, err := time.LoadLocation("Asia/Jakarta")
	if err != nil {
		writeError(w, r, err)
		return
	}
	if raw := q.Get("delivered_from"); raw != "" {
		day, _ := time.ParseInLocation("2006-01-02", raw, jakarta)
		where = append(where, "sh.delivered_at>=?")
		args = append(args, day.UTC())
	}
	if raw := q.Get("delivered_to"); raw != "" {
		day, _ := time.ParseInLocation("2006-01-02", raw, jakarta)
		where = append(where, "sh.delivered_at<?")
		args = append(args, day.AddDate(0, 0, 1).UTC())
	}
	if raw := q.Get("courier_id"); raw != "" {
		id, _ := strconv.ParseInt(raw, 10, 64)
		where = append(where, "sh.courier_id=?")
		args = append(args, id)
	}
	filter := strings.Join(where, " AND ")
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE `+filter, args...).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	args = append(append([]any{}, args...), perPage, (page-1)*perPage)
	rows, err := s.database.QueryContext(ctx, `SELECT sh.id, sh.order_id, o.order_no, sh.status, sh.delivery_mode,
		sh.courier_id, c.name, sh.self_delivered_by_user_id, su.name, sh.delivered_at,
		o.recipient_name_snapshot, o.village_snapshot, o.district_snapshot, o.regency_snapshot, o.province_snapshot,
		sh.proof_media_id, m.disk, m.path
		FROM shipments sh JOIN orders o ON o.id=sh.order_id
		LEFT JOIN couriers c ON c.id=sh.courier_id
		LEFT JOIN users su ON su.id=sh.self_delivered_by_user_id
		LEFT JOIN media m ON m.id=sh.proof_media_id
		WHERE `+filter+` ORDER BY sh.delivered_at DESC, sh.id DESC LIMIT ? OFFSET ?`, args...)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, orderID int64
		var orderNo, status, mode string
		var courierID, selfID, proofID sql.NullInt64
		var courierName, selfName, recipient, village, district, regency, province sql.NullString
		var delivered sql.NullTime
		var disk, path sql.NullString
		if err := rows.Scan(&id, &orderID, &orderNo, &status, &mode, &courierID, &courierName, &selfID, &selfName,
			&delivered, &recipient, &village, &district, &regency, &province, &proofID, &disk, &path); err != nil {
			writeError(w, r, err)
			return
		}
		var executor map[string]any
		if mode == "self_sub" {
			if selfID.Valid && selfName.Valid {
				executor = map[string]any{"type": "sks", "name": selfName.String}
			} else {
				executor = map[string]any{"type": "unavailable", "name": nil}
			}
		} else if courierID.Valid && courierName.Valid {
			executor = map[string]any{"type": "courier", "name": courierName.String, "courier_id": courierID.Int64}
		} else {
			executor = map[string]any{"type": "unavailable", "name": nil}
		}
		var proofURL any
		if proofID.Valid && disk.Valid && path.Valid {
			if disk.String == "public" {
				proofURL = strings.TrimRight(s.cfg.PublicURL, "/") + media.URL(path.String)
			} else {
				proofURL = "/storage/" + strings.TrimLeft(path.String, "/")
			}
		}
		data = append(data, map[string]any{
			"id": id, "shipment_id": id, "order_id": orderID, "order_no": orderNo, "status": status,
			"delivery_mode": mode, "executor": executor, "delivered_at": delivered.Time.UTC().Format("2006-01-02T15:04:05.000000Z"),
			"recipient_name": dispatchHistoryNullString(recipient), "village_name": dispatchHistoryNullString(village),
			"district_name": dispatchHistoryNullString(district), "regency_name": dispatchHistoryNullString(regency), "province_name": dispatchHistoryNullString(province),
			"proof_available": proofID.Valid, "proof_url": proofURL,
		})
	}
	if err := rows.Err(); err != nil {
		writeError(w, r, err)
		return
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	httpx.OK(w, data, "OK", map[string]any{"current_page": page, "last_page": lastPage, "total": total, "per_page": perPage})
}

func dispatchHistoryNullString(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}
