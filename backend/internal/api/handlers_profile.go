package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/profile"
)

type profileUpdateRequest struct {
	Name        json.RawMessage `json:"name"`
	Email       json.RawMessage `json:"email"`
	Phone       json.RawMessage `json:"phone"`
	AvatarMedia json.RawMessage `json:"avatar_media_id"`
}

func profileStringField(raw json.RawMessage) (*string, bool) {
	if len(raw) == 0 {
		return nil, false
	}
	var value string
	if strings.TrimSpace(string(raw)) == "null" || json.Unmarshal(raw, &value) != nil {
		return nil, true
	}
	return &value, true
}

func (s *Server) handleProfileUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	var body profileUpdateRequest
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	name, nameSet := profileStringField(body.Name)
	email, emailSet := profileStringField(body.Email)
	phone, phoneSet := profileStringField(body.Phone)
	input := profile.UpdateInput{Name: name, NameSet: nameSet, Email: email, EmailSet: emailSet,
		Phone: phone, PhoneSet: phoneSet, AvatarSet: len(body.AvatarMedia) > 0}
	if input.AvatarSet && strings.TrimSpace(string(body.AvatarMedia)) != "null" {
		var id int64
		if err := json.Unmarshal(body.AvatarMedia, &id); err != nil {
			input.AvatarInvalid = true
		} else if id < 1 {
			input.AvatarInvalid = true
		} else {
			input.AvatarID = &id
		}
	}
	updated, err := profile.Update(ctx, s.database, user, input, clientMetaFrom(ctx))
	if errors.Is(err, profile.ErrEmailTaken) {
		writeError(w, r, httpx.Validation(map[string][]string{"email": uniqueField(ctx, "email")}))
		return
	}
	if errors.Is(err, profile.ErrPhoneTaken) {
		writeError(w, r, httpx.Validation(map[string][]string{"phone": uniqueField(ctx, "phone")}))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	payload, err := updated.UserJSON(ctx, s.database, updated.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, payload, "OK", nil)
}

func (s *Server) handleProfileReferralCodeUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	var body struct {
		Code string `json:"referral_code"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	updated, err := profile.SetReferralCode(ctx, s.database, user, body.Code, clientMetaFrom(ctx))
	if err != nil {
		writeProfileReferralError(w, r, err)
		return
	}
	payload, err := updated.UserJSON(ctx, s.database, updated.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, payload, msg(ctx, "profile.referral_code_updated"), nil)
}

func (s *Server) handleProfileReferralCodeRegenerate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	updated, err := profile.RegenerateReferralCode(ctx, s.database, user, clientMetaFrom(ctx))
	if err != nil {
		writeProfileReferralError(w, r, err)
		return
	}
	payload, err := updated.UserJSON(ctx, s.database, updated.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, payload, msg(ctx, "profile.referral_code_updated"), nil)
}

func (s *Server) handleProfileReferralCodeDelete(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	updated, err := profile.DeleteReferralCode(ctx, s.database, user, clientMetaFrom(ctx))
	if err != nil {
		writeProfileReferralError(w, r, err)
		return
	}
	payload, err := updated.UserJSON(ctx, s.database, updated.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, payload, msg(ctx, "profile.referral_code_updated"), nil)
}

func (s *Server) handleReferralPreview(w http.ResponseWriter, r *http.Request) {
	result, err := profile.ReferralPreview(r.Context(), s.database, httpx.PathValue(r, "code"))
	if errors.Is(err, profile.ErrReferralCodeNotFound) {
		// Verified upstream defect: backend/lang/{en,id}/messages.php declare 'referral' TWICE, so the
		// second (reassignment-only) array silently replaces the first and messages.referral.invalid_code /
		// messages.referral.code_not_found no longer resolve — Laravel emits the RAW KEYS in every locale
		// (asserted with X-Locale: id/en and tinker). The contract is the raw keys, string-valued.
		writeError(w, r, &httpx.Error{
			Status:       http.StatusUnprocessableEntity,
			Message:      "messages.referral.invalid_code",
			FieldStrings: map[string]string{"referral_code": "messages.referral.code_not_found"},
		})
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleEmailVerificationState(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	state, err := profile.EmailVerificationState(ctx, s.database, user.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, state, "OK", nil)
}

func (s *Server) handleProfileWhatsAppState(w http.ResponseWriter, r *http.Request) {
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	ctx := r.Context()
	var phone sql.NullString
	if err := s.database.QueryRowContext(ctx, `SELECT phone FROM users WHERE id=? AND deleted_at IS NULL`, user.ID).Scan(&phone); err != nil {
		writeError(w, r, err)
		return
	}
	msisdn := ""
	if phone.Valid {
		msisdn = agentadmin.NormalizeWhatsAppNumber(phone.String)
	}
	var verified, pending bool
	if msisdn != "" {
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges WHERE subject_user_id=? AND purpose='whatsapp_number' AND sent_to_email=? AND consumed_at IS NOT NULL AND invalidated_at IS NULL)`, user.ID, "wa:"+msisdn).Scan(&verified); err != nil {
			writeError(w, r, err)
			return
		}
	}
	if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges WHERE subject_user_id=? AND purpose='whatsapp_number' AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at>UTC_TIMESTAMP())`, user.ID).Scan(&pending); err != nil {
		writeError(w, r, err)
		return
	}
	settings := map[string]string{}
	rows, err := s.database.QueryContext(ctx, `SELECT `+"`key`"+`,value FROM settings WHERE `+"`group`"+`='integration.whatsapp' AND `+"`key`"+` IN ('wa.enabled','wa.base_url','wa.username','wa.password','wa.otp.enabled')`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	for rows.Next() {
		var k, v string
		if err = rows.Scan(&k, &v); err != nil {
			rows.Close()
			writeError(w, r, err)
			return
		}
		settings[k] = v
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeError(w, r, err)
		return
	}
	rows.Close()
	password := ""
	if cipher := settings["wa.password"]; cipher != "" {
		password, _ = cryptox.DecryptString(s.cfg.AppKey, cipher)
	}
	configured := truthy(settings["wa.enabled"]) && settings["wa.base_url"] != "" && settings["wa.username"] != "" && password != ""
	var agentID any = user.ID
	if user.AgentID != nil {
		agentID = *user.AgentID
	}
	var paired, messageTable, deviceTable bool
	if err = s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_messages')`).Scan(&messageTable); err != nil {
		writeError(w, r, err)
		return
	}
	if err = s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='agent_whatsapp_devices')`).Scan(&deviceTable); err != nil {
		writeError(w, r, err)
		return
	}
	if deviceTable {
		if err = s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM agent_whatsapp_devices WHERE agent_id=? AND paired_at IS NOT NULL)`, agentID).Scan(&paired); err != nil {
			writeError(w, r, err)
			return
		}
	}
	var masked any
	if msisdn != "" {
		masked = agentadmin.MaskWhatsAppNumber(msisdn)
	}
	httpx.OK(w, map[string]any{"has_phone": msisdn != "", "phone_masked": masked, "verified": verified, "channel_available": messageTable && configured && truthy(settings["wa.otp.enabled"]) && paired, "pending": pending}, "OK", nil)
}

func truthy(value string) bool {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "1", "true", "on", "yes":
		return true
	default:
		return false
	}
}

func writeProfileReferralError(w http.ResponseWriter, r *http.Request, err error) {
	var apiErr *httpx.Error
	if errors.As(err, &apiErr) && len(apiErr.Fields) > 0 {
		fields := map[string][]string{}
		for field, rules := range apiErr.Fields {
			for _, rule := range rules {
				switch rule {
				case "regex":
					fields[field] = append(fields[field], msg(r.Context(), "profile.referral_code_format"))
				case "max:30":
					fields[field] = append(fields[field], i18n.ValidationMessage(LocaleFrom(r.Context()), "max.string", field, "max", "30"))
				default:
					fields[field] = append(fields[field], i18n.ValidationMessage(LocaleFrom(r.Context()), rule, field))
				}
			}
		}
		writeError(w, r, httpx.Validation(fields))
		return
	}
	writeError(w, r, err)
}
