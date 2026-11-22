package api

import (
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// handleCsrfCookie is Sanctum's GET /sanctum/csrf-cookie.
//
// It guarantees the browser holds a session with a CSRF token, then answers 204 with no body — the SPA calls
// it before its first mutation and never reads the body (axios only needs the XSRF-TOKEN cookie).
func (s *Server) handleCsrfCookie(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	session := SessionFrom(ctx)
	if session == nil {
		created, err := s.sessions.Create(ctx, r, nil)
		if err != nil {
			writeError(w, r, err)
			return
		}
		session = created
		stateFrom(ctx).Session = created
	}
	s.sessions.WriteCookies(w, session)
	w.Header().Set("Cache-Control", "no-store, private")
	w.WriteHeader(http.StatusNoContent)
}

type loginRequest struct {
	Identifier string `json:"identifier"`
	Password   string `json:"password"`
}

// handleLogin mirrors AuthController::login.
func (s *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body loginRequest
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.Identifier == "" {
		fields["identifier"] = []string{msg(ctx, "system.field_required")}
	}
	if body.Password == "" {
		fields["password"] = []string{msg(ctx, "system.field_required")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	identity := s.identity.WithLocale(LocaleFrom(ctx))
	user, err := identity.Login(ctx, body.Identifier, body.Password, clientMetaFrom(ctx))
	if err != nil {
		writeError(w, r, err)
		return
	}

	// Session fixation defence: a fresh id + token for the authenticated session, then the cookies.
	session, err := s.sessions.Rotate(ctx, SessionFrom(ctx), &user.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	s.sessions.WriteCookies(w, session)
	stateFrom(ctx).Session = session
	stateFrom(ctx).User = user

	payload, err := user.AuthPayload(ctx, s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, payload, msg(ctx, "auth.login_success"), nil)
}

type registerRequest struct {
	Name                 string `json:"name"`
	Email                string `json:"email"`
	Phone                string `json:"phone"`
	Password             string `json:"password"`
	PasswordConfirmation string `json:"password_confirmation"`
	ReferralCode         string `json:"referral_code"`
}

// handleRegister mirrors AuthController::register (public konsumen registration).
func (s *Server) handleRegister(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body registerRequest
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	identity := s.identity.WithLocale(LocaleFrom(ctx))
	user, err := identity.Register(ctx, auth.RegisterInput{
		Name:         body.Name,
		Email:        body.Email,
		Phone:        body.Phone,
		Password:     body.Password,
		PasswordConf: body.PasswordConfirmation,
		ReferralCode: body.ReferralCode,
	}, clientMetaFrom(ctx))
	if err != nil {
		writeError(w, r, err)
		return
	}

	session, err := s.sessions.Rotate(ctx, SessionFrom(ctx), &user.ID)
	if err != nil {
		writeError(w, r, err)
		return
	}
	s.sessions.WriteCookies(w, session)
	stateFrom(ctx).Session = session
	stateFrom(ctx).User = user

	payload, err := user.AuthPayload(ctx, s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, payload, msg(ctx, "auth.register_success"))
}

// handleLogout mirrors AuthController::logout: audit, invalidate the session, rotate the token, clear cookies.
func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user := UserFrom(ctx)
	identity := s.identity.WithLocale(LocaleFrom(ctx))
	if err := identity.Logout(ctx, user, clientMetaFrom(ctx)); err != nil {
		// The audit write must not prevent the user from ending their session; log it and continue.
		s.logger.WarnContext(ctx, "logout audit failed", "error", err)
	}
	if err := s.sessions.Destroy(ctx, SessionFrom(ctx)); err != nil {
		writeError(w, r, err)
		return
	}
	s.sessions.ClearCookies(w)
	stateFrom(ctx).Session = nil
	stateFrom(ctx).User = nil
	httpx.OK(w, nil, msg(ctx, "auth.logout_success"), nil)
}

// handleMe mirrors AuthController::me.
func (s *Server) handleMe(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	payload, err := user.AuthPayload(ctx, s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, payload, "OK", nil)
}

type passwordRequest struct {
	CurrentPassword      string `json:"current_password"`
	Password             string `json:"password"`
	PasswordConfirmation string `json:"password_confirmation"`
}

// handleUpdatePassword mirrors ProfileController::updatePassword.
func (s *Server) handleUpdatePassword(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	var body passwordRequest
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.CurrentPassword == "" {
		fields["current_password"] = []string{msg(ctx, "system.field_required")}
	}
	if len(body.Password) < 8 {
		fields["password"] = []string{msg(ctx, "system.field_invalid")}
	}
	if body.Password != body.PasswordConfirmation {
		fields["password"] = []string{msg(ctx, "system.field_invalid")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if err := auth.VerifyPassword(user.PasswordHash, body.CurrentPassword); err != nil {
		// Laravel answers 422 with the message on the current_password field (not a 401).
		incorrect := msg(ctx, "profile.current_password_incorrect")
		writeError(w, r, &httpx.Error{
			Status:  http.StatusUnprocessableEntity,
			Message: incorrect,
			Fields:  map[string][]string{"current_password": {incorrect}},
		})
		return
	}
	hash, err := auth.HashPassword(body.Password)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if _, err := s.database.ExecContext(ctx, `UPDATE users SET password = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		hash, user.ID); err != nil {
		writeError(w, r, err)
		return
	}
	if err := s.identity.WithLocale(LocaleFrom(ctx)).Logout(ctx, nil, clientMetaFrom(ctx)); err != nil {
		_ = err // audit is best-effort here; the password change already committed
	}
	httpx.OK(w, nil, msg(ctx, "profile.password_updated"), nil)
}
