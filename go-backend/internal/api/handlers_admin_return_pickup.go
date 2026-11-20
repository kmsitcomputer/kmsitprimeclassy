package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleAdminReturnPickupSchedule(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	returnID, ok := pathID(w, r, "return")
	if !ok {
		return
	}
	var orderID int64
	if err := s.database.QueryRowContext(ctx, `SELECT order_id FROM returns WHERE id=?`, returnID).Scan(&orderID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, notFoundError(ctx))
		} else {
			writeError(w, r, err)
		}
		return
	}
	actor := UserFrom(ctx)
	if actor.Role != "super_admin" {
		var agentID sql.NullInt64
		if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id=?`, orderID).Scan(&agentID); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				writeError(w, r, notFoundError(ctx))
			} else {
				writeError(w, r, err)
			}
			return
		}
		if !agentID.Valid || actor.AgentID == nil || agentID.Int64 != *actor.AgentID {
			writeError(w, r, forbiddenError(ctx))
			return
		}
	}

	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	locale := LocaleFrom(ctx)
	fields := map[string][]string{}
	raw, present := body["pickup_scheduled_for"]
	value := ""
	if present && strings.TrimSpace(string(raw)) != "null" {
		if err := json.Unmarshal(raw, &value); err != nil {
			value = strings.Trim(string(raw), "\"")
		}
	}
	if !present || strings.TrimSpace(string(raw)) == "null" || value == "" {
		fields["pickup_scheduled_for"] = []string{i18n.ValidationMessage(locale, "required", "pickup_scheduled_for")}
	} else {
		parsed, err := time.Parse("2006-01-02", value)
		if err != nil || parsed.Format("2006-01-02") != value {
			fields["pickup_scheduled_for"] = []string{i18n.ValidationMessage(locale, "date_format", "pickup_scheduled_for", "format", "Y-m-d")}
		} else {
			loc, err := time.LoadLocation("Asia/Jakarta")
			if err != nil {
				writeError(w, r, err)
				return
			}
			today := time.Now().In(loc).Format("2006-01-02")
			if value < today {
				fields["pickup_scheduled_for"] = []string{i18n.ValidationMessage(locale, "after_or_equal", "pickup_scheduled_for", "date", today)}
			}
		}
	}
	if len(fields) != 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	rows, err := s.database.QueryContext(ctx, `SELECT id FROM return_items WHERE return_id=? AND status IN ('pending','approved')`, returnID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	itemIDs := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		itemIDs = append(itemIDs, id)
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
	if len(itemIDs) == 0 {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Tidak ada item retur yang masih menunggu penjemputan."))
		return
	}
	for _, itemID := range itemIDs {
		if _, err := s.database.ExecContext(ctx, `UPDATE return_items SET pickup_scheduled_for=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, value, itemID); err != nil {
			writeError(w, r, err)
			return
		}
	}
	properties, err := json.Marshal(map[string]any{"return_id": returnID, "scheduled_for": value, "items": len(itemIDs)})
	if err != nil {
		writeError(w, r, err)
		return
	}
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	_, err = s.database.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
		VALUES (?,'App\\Models\\ReturnRequest',?,'return.pickup_scheduled',NULL,?,NULLIF(?,''),NULLIF(?,''),UTC_TIMESTAMP(6))`,
		actor.ID, returnID, string(properties), clientIP(r), userAgent)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"return_id": returnID, "pickup_scheduled_for": value, "items": len(itemIDs)}, "OK", nil)
}
