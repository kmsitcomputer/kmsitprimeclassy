package api

import (
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) recoveryService() *auth.RecoveryService {
	return auth.NewRecoveryService(s.database, s.cfg.AppKey)
}

func (s *Server) handleForgotPassword(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body struct {
		Identifier *string `json:"identifier"`
		Channel    *string `json:"channel"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Identifier == nil || *body.Identifier == "" {
		fields.add("identifier", i18n.ValidationMessage(LocaleFrom(ctx), "required", "identifier"))
	} else if len([]rune(*body.Identifier)) > 255 {
		fields.add("identifier", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "identifier", "max", "255"))
	}
	if body.Channel != nil && *body.Channel != "email" && *body.Channel != "whatsapp" {
		fields.add("channel", i18n.ValidationMessage(LocaleFrom(ctx), "in", "channel"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	channel := ""
	if body.Channel != nil {
		channel = *body.Channel
	}
	// Enumeration-resistant: the answer is byte-identical for every outcome.
	s.recoveryService().Request(ctx, *body.Identifier, channel)
	httpx.OK(w, nil, "Jika akun dengan identitas tersebut ada, instruksi pemulihan telah dikirim.", nil)
}

func (s *Server) handleResetPassword(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body struct {
		Identifier      *string `json:"identifier"`
		Code            *string `json:"code"`
		Password        *string `json:"password"`
		PasswordConfirm *string `json:"password_confirmation"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Identifier == nil || *body.Identifier == "" {
		fields.add("identifier", i18n.ValidationMessage(LocaleFrom(ctx), "required", "identifier"))
	} else if len([]rune(*body.Identifier)) > 255 {
		fields.add("identifier", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "identifier", "max", "255"))
	}
	if body.Code == nil || *body.Code == "" {
		fields.add("code", i18n.ValidationMessage(LocaleFrom(ctx), "required", "code"))
	} else if len(*body.Code) != 6 {
		fields.add("code", "The code must be 6 digits.")
	}
	if body.Password == nil || *body.Password == "" {
		fields.add("password", i18n.ValidationMessage(LocaleFrom(ctx), "required", "password"))
	} else if len([]rune(*body.Password)) < 8 {
		fields.add("password", i18n.ValidationMessage(LocaleFrom(ctx), "min.string", "password", "min", "8"))
		// Laravel's confirmed rule: only evaluated when the base field is present.
		if body.PasswordConfirm == nil || *body.PasswordConfirm == "" || *body.PasswordConfirm != *body.Password {
			fields.add("password_confirmation", i18n.ValidationMessage(LocaleFrom(ctx), "confirmed", "password"))
		}
	} else if body.PasswordConfirm == nil || *body.PasswordConfirm == "" || *body.PasswordConfirm != *body.Password {
		fields.add("password_confirmation", i18n.ValidationMessage(LocaleFrom(ctx), "confirmed", "password"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	err := s.recoveryService().Reset(ctx, *body.Identifier, *body.Code, *body.Password)
	if err != nil {
		switch {
		case errors.Is(err, auth.ErrRecoveryExpired):
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity,
				"Kode pemulihan kedaluwarsa. Minta kode baru."))
		case errors.Is(err, auth.ErrRecoveryLocked):
			writeError(w, r, httpx.NewError(http.StatusTooManyRequests,
				"Terlalu banyak percobaan salah. Minta kode baru."))
		default:
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Kode pemulihan tidak valid."))
		}
		return
	}
	httpx.OK(w, nil, "Kata sandi berhasil diubah. Silakan masuk dengan kata sandi baru.", nil)
}
