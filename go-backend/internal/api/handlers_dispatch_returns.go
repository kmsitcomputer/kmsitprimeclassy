package api

import (
	"database/sql"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleDispatchReturns(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	agentID := int64(0)
	if actor.Role == "super_admin" {
		selected := queryInteger(r, "agent_id", 0)
		if selected <= 0 {
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "agent_id wajib diisi untuk super_admin."))
			return
		}
		var exists bool
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(
			SELECT 1 FROM users u JOIN roles role ON role.id=u.role_id WHERE u.id=? AND role.slug='agen')`, selected).Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if !exists {
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Agen tidak ditemukan."))
			return
		}
		agentID = int64(selected)
	} else if actor.AgentID != nil {
		agentID = *actor.AgentID
	}

	perPage := 15
	if raw := strings.TrimSpace(r.URL.Query().Get("per_page")); raw != "" {
		perPage, _ = strconv.Atoi(raw)
	}
	if perPage < 1 {
		perPage = 1
	} else if perPage > 100 {
		perPage = 100
	}
	page := queryInteger(r, "page", 1)
	if page < 1 {
		page = 1
	}
	offset := int64(page-1) * int64(perPage)
	const base = ` FROM return_items ri JOIN order_items oi ON oi.id=ri.order_item_id
		JOIN orders o ON o.id=oi.order_id LEFT JOIN couriers c ON c.id=ri.courier_id
		WHERE ri.status IN ('pending','approved') AND oi.status='pengembalian' AND o.agent_id=?`
	var total int64
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*)`+base, agentID).Scan(&total); err != nil {
		writeError(w, r, err)
		return
	}
	rows, err := s.database.QueryContext(ctx, `SELECT ri.id,oi.order_id,o.order_no,oi.product_name_snapshot,
		ri.quantity_returned,CAST(ri.pickup_scheduled_for AS CHAR),ri.courier_id,c.name`+base+
		` ORDER BY ri.id LIMIT ? OFFSET ?`, agentID, perPage, offset)
	if err != nil {
		writeError(w, r, err)
		return
	}
	data := []map[string]any{}
	for rows.Next() {
		var id, orderID, quantity int64
		var orderNo, productName string
		var scheduled sql.NullString
		var courierID sql.NullInt64
		var courierName sql.NullString
		if err := rows.Scan(&id, &orderID, &orderNo, &productName, &quantity, &scheduled, &courierID, &courierName); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		var pickupDate, name any
		if scheduled.Valid {
			pickupDate = strings.TrimSpace(scheduled.String)
		}
		if courierName.Valid {
			name = courierName.String
		}
		data = append(data, map[string]any{
			"id": id, "order_id": orderID, "order_no": orderNo, "product_name": productName,
			"quantity": quantity, "pickup_scheduled_for": pickupDate, "courier_id": nullableInt64Value(courierID),
			"courier_name": name, "assignment_status": map[bool]string{true: "assigned", false: "unassigned"}[courierID.Valid],
		})
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
	lastPage := (total + int64(perPage) - 1) / int64(perPage)
	if lastPage < 1 {
		lastPage = 1
	}
	httpx.OK(w, data, "OK", map[string]any{"current_page": page, "last_page": lastPage, "total": total})
}
