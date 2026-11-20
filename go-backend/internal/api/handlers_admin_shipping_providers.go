package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"regexp"
	"strings"
	"time"

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
	ID        int64   `json:"id"`
	Code      string  `json:"code"`
	Name      string  `json:"name"`
	IsActive  bool    `json:"is_active"`
	CreatedAt *string `json:"created_at,omitempty"`
	UpdatedAt *string `json:"updated_at,omitempty"`
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

func (s *Server) handleAdminShippingCouriersStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var input struct {
		Code *string `json:"code"`
		Name *string `json:"name"`
	}
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	locale := LocaleFrom(ctx)
	fields := map[string][]string{}
	if input.Code == nil || *input.Code == "" {
		fields["code"] = append(fields["code"], i18n.ValidationMessage(locale, "required", "code"))
	} else if len(*input.Code) > 30 {
		fields["code"] = append(fields["code"], i18n.ValidationMessage(locale, "max.string", "code", "max", "30"))
	} else if !regexp.MustCompile(`^[a-z0-9_-]+$`).MatchString(*input.Code) {
		fields["code"] = append(fields["code"], i18n.ValidationMessage(locale, "regex", "code"))
	} else {
		var exists bool
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM shipping_couriers WHERE code=?)`, *input.Code).Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if exists {
			fields["code"] = append(fields["code"], i18n.ValidationMessage(locale, "unique", "code"))
		}
	}
	if input.Name == nil || *input.Name == "" {
		fields["name"] = append(fields["name"], i18n.ValidationMessage(locale, "required", "name"))
	} else if len(*input.Name) > 100 {
		fields["name"] = append(fields["name"], i18n.ValidationMessage(locale, "max.string", "name", "max", "100"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	code := strings.ToLower(*input.Code)
	var provider adminShippingCourier
	var created, updated time.Time
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		result, err := tx.ExecContext(ctx, `INSERT INTO shipping_couriers(code,name,is_active,created_at,updated_at) VALUES (?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, code, *input.Name)
		if err != nil {
			return err
		}
		provider.ID, err = result.LastInsertId()
		if err != nil {
			return err
		}
		if err = tx.QueryRowContext(ctx, `SELECT created_at,updated_at FROM shipping_couriers WHERE id=?`, provider.ID).Scan(&created, &updated); err != nil {
			return err
		}
		provider.Code, provider.Name, provider.IsActive = code, *input.Name, true
		properties, err := json.Marshal(map[string]string{"code": code, "name": *input.Name})
		if err != nil {
			return err
		}
		userAgent := r.UserAgent()
		if len(userAgent) > 500 {
			userAgent = userAgent[:500]
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?, 'App\\Models\\ShippingCourier', ?, 'shipping_courier.created', NULL, ?, ?, ?, UTC_TIMESTAMP())`, UserFrom(ctx).ID, provider.ID, string(properties), clientIP(r), userAgent)
		return err
	})
	if err != nil {
		writeError(w, r, err)
		return
	}
	createdAt, updatedAt := created.UTC().Format(time.RFC3339Nano), updated.UTC().Format(time.RFC3339Nano)
	provider.CreatedAt, provider.UpdatedAt = &createdAt, &updatedAt
	httpx.Created(w, provider, "Created")
}

func (s *Server) handleAdminShippingCourierToggle(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "shippingCourier")
	if !ok {
		return
	}
	var courier adminShippingCourier
	var created, updated time.Time
	err := s.database.Tx(r.Context(), func(ctx context.Context, tx *sql.Tx) error {
		if err := tx.QueryRowContext(ctx, `SELECT id,code,name,is_active,created_at,updated_at FROM shipping_couriers WHERE id=? FOR UPDATE`, id).
			Scan(&courier.ID, &courier.Code, &courier.Name, &courier.IsActive, &created, &updated); err != nil {
			return err
		}
		courier.IsActive = !courier.IsActive
		if _, err := tx.ExecContext(ctx, `UPDATE shipping_couriers SET is_active=?,updated_at=UTC_TIMESTAMP() WHERE id=?`, courier.IsActive, id); err != nil {
			return err
		}
		actor := UserFrom(ctx)
		properties, err := json.Marshal(map[string]bool{"is_active": courier.IsActive})
		if err != nil {
			return err
		}
		userAgent := r.UserAgent()
		if len(userAgent) > 500 {
			userAgent = userAgent[:500]
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?, 'App\\Models\\ShippingCourier', ?, 'shipping_courier.toggled', NULL, ?, ?, ?, UTC_TIMESTAMP())`, actor.ID, id, string(properties), clientIP(r), userAgent)
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
	var latestUpdated time.Time
	if err := s.database.QueryRowContext(r.Context(), `SELECT updated_at FROM shipping_couriers WHERE id=?`, id).Scan(&latestUpdated); err != nil {
		writeError(w, r, err)
		return
	}
	createdAt, updatedAt := created.UTC().Format(time.RFC3339Nano), latestUpdated.UTC().Format(time.RFC3339Nano)
	courier.CreatedAt, courier.UpdatedAt = &createdAt, &updatedAt
	httpx.OK(w, courier, "OK", nil)
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
