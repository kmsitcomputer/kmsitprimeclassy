package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

type adminShippingProvider struct {
	ID       int64  `json:"id"`
	Code     string `json:"code"`
	Name     string `json:"name"`
	IsActive bool   `json:"is_active"`
}

type adminShippingCourier struct {
	ID       int64  `json:"id"`
	Code     string `json:"code"`
	Name     string `json:"name"`
	IsActive bool   `json:"is_active"`
}

func (s *Server) handleAdminShippingCouriers(w http.ResponseWriter, r *http.Request) {
	rows, err := s.database.QueryContext(r.Context(), `SELECT id,code,name,is_active FROM shipping_couriers ORDER BY name`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	couriers := make([]adminShippingCourier, 0)
	for rows.Next() {
		var courier adminShippingCourier
		if err := rows.Scan(&courier.ID, &courier.Code, &courier.Name, &courier.IsActive); err != nil {
			writeError(w, r, err)
			return
		}
		couriers = append(couriers, courier)
	}
	if err := rows.Err(); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, couriers, "OK", nil)
}

func (s *Server) handleAdminShippingProviders(w http.ResponseWriter, r *http.Request) {
	rows, err := s.database.QueryContext(r.Context(), `SELECT id,code,name,is_active FROM shipping_providers
		WHERE code IN ('rajaongkir','openroute','kmsit') ORDER BY id`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	providers := make([]adminShippingProvider, 0, 3)
	for rows.Next() {
		var provider adminShippingProvider
		if err := rows.Scan(&provider.ID, &provider.Code, &provider.Name, &provider.IsActive); err != nil {
			writeError(w, r, err)
			return
		}
		providers = append(providers, provider)
	}
	if err := rows.Err(); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, providers, "OK", nil)
}

func (s *Server) handleAdminShippingProviderToggle(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "provider")
	if !ok {
		return
	}
	var provider adminShippingProvider
	var actorID int64
	if actor := UserFrom(r.Context()); actor != nil {
		actorID = actor.ID
	}
	err := s.database.Tx(r.Context(), func(ctx context.Context, tx *sql.Tx) error {
		if err := tx.QueryRowContext(ctx, `SELECT id,code,name,is_active FROM shipping_providers WHERE id=? FOR UPDATE`, id).
			Scan(&provider.ID, &provider.Code, &provider.Name, &provider.IsActive); err != nil {
			return err
		}
		provider.IsActive = !provider.IsActive
		if _, err := tx.ExecContext(ctx, `UPDATE shipping_providers SET is_active=?,updated_at=UTC_TIMESTAMP() WHERE id=?`, provider.IsActive, id); err != nil {
			return err
		}
		properties, err := json.Marshal(map[string]bool{"is_active": provider.IsActive})
		if err != nil {
			return err
		}
		userAgent := r.UserAgent()
		if len(userAgent) > 500 {
			userAgent = userAgent[:500]
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
			(causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at)
			VALUES (?, 'App\\Models\\ShippingProvider', ?, 'shipping_provider.toggled', NULL, ?, ?, ?, UTC_TIMESTAMP())`,
			actorID, id, string(properties), clientIP(r), userAgent)
		return err
	})
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, r, notFoundError(r.Context()))
		} else {
			writeError(w, r, err)
		}
		return
	}
	locale := LocaleFrom(r.Context())
	httpx.OK(w, provider, i18n.Translate(locale, "shipping.provider_toggled"), nil)
}
