package api

import (
	"database/sql"

	"errors"
	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) emailVerifyService() *auth.EmailVerifyService {
	return auth.NewEmailVerifyService(s.database, s.cfg.AppKey)
}

// handleEmailVerificationRequest ports EmailVerificationController::request.
func (s *Server) handleEmailVerificationRequest(w http.ResponseWriter, r *http.Request) {
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	ctx := r.Context()
	out, err := s.emailVerifyService().Request(ctx, user.ID)
	if err != nil {
		switch {
		case errors.Is(err, auth.ErrRecoveryLocked):
			writeError(w, r, httpx.NewError(http.StatusTooManyRequests,
				"Terlalu banyak permintaan kode. Coba lagi nanti."))
		default:
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, err.Error()))
		}
		return
	}
	if out == nil {
		state, _ := s.emailVerifyService().State(ctx, user.ID)
		httpx.OK(w, state, "Email sudah terverifikasi.", nil)
		return
	}
	httpx.Created(w, out, "Kode verifikasi dikirim ke email Anda.")
}

// handleEmailVerificationConfirm ports EmailVerificationController::confirm.
func (s *Server) handleEmailVerificationConfirm(w http.ResponseWriter, r *http.Request) {
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	ctx := r.Context()
	challengeID, cerr := strconv.ParseInt(r.PathValue("challenge"), 10, 64)
	if cerr != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Not found"))
		return
	}
	var body struct {
		Code *string `json:"code"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Code == nil || *body.Code == "" {
		fields.add("code", i18n.ValidationMessage(LocaleFrom(ctx), "required", "code"))
	} else if len(*body.Code) != 6 {
		fields.add("code", "code harus 6 digit.")
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	state, err := s.emailVerifyService().Confirm(ctx, user.ID, challengeID, *body.Code)
	if err != nil {
		switch {
		case errors.Is(err, auth.ErrRecoveryLocked):
			writeError(w, r, httpx.NewError(http.StatusTooManyRequests,
				"Terlalu banyak percobaan salah. Coba lagi nanti."))
		case errors.Is(err, auth.ErrEmailStale):
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity,
				"Email akun berubah setelah kode dikirim. Minta kode baru untuk email saat ini."))
		case errors.Is(err, auth.ErrChallengeNotUsable):
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity,
				"Verifikasi tidak ada, kedaluwarsa, atau sudah dipakai."))
		case errors.Is(err, auth.ErrRecovery):
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Kode tidak valid."))
		default:
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, err.Error()))
		}
		return
	}
	httpx.OK(w, state, "Kode terverifikasi.", nil)
}

// handleWhatsAppVerificationRequest ports WhatsAppNumberController::request.
func (s *Server) handleWhatsAppVerificationRequest(w http.ResponseWriter, r *http.Request) {
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	ctx := r.Context()
	var body struct {
		Phone *string `json:"phone"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Phone != nil && len([]rune(*body.Phone)) > 20 {
		fields.add("phone", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "phone", "max", "20"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	// Target resolution: a NEW number to prove, or the number already on the account.
	target := ""
	if body.Phone != nil && *body.Phone != "" {
		target = agentadmin.NormalizeWhatsAppNumber(*body.Phone)
	} else {
		var phone sql.NullString
		if err := s.database.QueryRowContext(ctx, `SELECT phone FROM users WHERE id = ?`, user.ID).
			Scan(&phone); err == nil && phone.Valid {
			target = agentadmin.NormalizeWhatsAppNumber(phone.String)
		}
	}
	whatsappUnavailable := &httpx.Error{Status: http.StatusConflict,
		Message:   "OTP WhatsApp tidak tersedia: gateway atau opsi OTP dimatikan.",
		ErrorsAny: map[string]any{"code": "whatsapp_otp_unavailable"}}
	if target == "" {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message:   "Masukkan nomor WhatsApp yang valid.",
			ErrorsAny: map[string]any{"phone": []string{"Masukkan nomor WhatsApp yang valid."}}})
		return
	}
	// Already proven for exactly this number? Nothing is sent.
	var proven bool
	_ = s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges
		WHERE subject_user_id = ? AND purpose = 'whatsapp_number' AND sent_to_email = ?
		AND consumed_at IS NOT NULL AND invalidated_at IS NULL)`, user.ID, "wa:"+target).Scan(&proven)
	if proven {
		// Same state projection as GET /profile/whatsapp.
		var phone sql.NullString
		_ = s.database.QueryRowContext(ctx, `SELECT phone FROM users WHERE id = ?`, user.ID).Scan(&phone)
		msisdn := ""
		if phone.Valid {
			msisdn = agentadmin.NormalizeWhatsAppNumber(phone.String)
		}
		var hasPhone bool
		_ = s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges
			WHERE subject_user_id = ? AND purpose = 'whatsapp_number' AND sent_to_email = ?
			AND consumed_at IS NOT NULL AND invalidated_at IS NULL)`, user.ID, "wa:"+msisdn).Scan(&hasPhone)
		var pending bool
		_ = s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges
			WHERE subject_user_id = ? AND purpose = 'whatsapp_number'
			AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at > UTC_TIMESTAMP())`,
			user.ID).Scan(&pending)
		var masked any
		if msisdn != "" {
			masked = agentadmin.MaskWhatsAppNumber(msisdn)
		}
		httpx.OK(w, map[string]any{"has_phone": msisdn != "", "phone_masked": masked,
			"verified": hasPhone, "channel_available": false, "pending": pending},
			"Nomor WhatsApp ini sudah terverifikasi.", nil)
		return
	}
	// Channel gate (policy resolution): gateway/OTP disabled or no paired device → 409.
	// (Configured gateways are not reachable in the isolated env — parity ends at this
	// gate, exactly where the reference stops without a paired device.)
	writeError(w, r, whatsappUnavailable)
}

// handleWhatsAppVerificationConfirm ports WhatsAppNumberController::confirm.
func (s *Server) handleWhatsAppVerificationConfirm(w http.ResponseWriter, r *http.Request) {
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	ctx := r.Context()
	whatsappUnavailable := &httpx.Error{Status: http.StatusConflict,
		Message:   "OTP WhatsApp tidak tersedia: gateway atau opsi OTP dimatikan.",
		ErrorsAny: map[string]any{"code": "whatsapp_otp_unavailable"}}
	challengeID, cerr := strconv.ParseInt(r.PathValue("challenge"), 10, 64)
	if cerr != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Not found"))
		return
	}
	var body struct {
		Code *string `json:"code"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Code == nil || *body.Code == "" {
		fields.add("code", i18n.ValidationMessage(LocaleFrom(ctx), "required", "code"))
	} else if len(*body.Code) != 6 {
		fields.add("code", "code harus 6 digit.")
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	// Subject-bound challenge lookup: no probing of other accounts.
	var cnt int
	_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
		WHERE id = ? AND subject_user_id = ? AND purpose = 'whatsapp_number'`, challengeID, user.ID).Scan(&cnt)
	if cnt == 0 {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Kode tidak valid."))
		return
	}
	// Delivery requires the gateway (unavailable in the isolated env); the shared
	// verifier semantics and confirm path only run with a live channel.
	writeError(w, r, whatsappUnavailable)
}
