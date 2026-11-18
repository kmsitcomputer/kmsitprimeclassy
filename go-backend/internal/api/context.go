package api

import (
	"context"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
)

// ctxKey is the private key type for request-scoped values.
type ctxKey int

const (
	ctxKeySession ctxKey = iota
	ctxKeyUser
	ctxKeyLocale
	ctxKeyRequestID
)

// requestState is the per-request mutable holder placed in the context at the start of the chain. A
// pointer is used because middleware fills it in progressively (session → user) and handlers read it later.
type requestState struct {
	Session    *auth.Session
	User       *auth.User
	Locale     string
	RequestID  string
	// IP / UserAgent are captured once from the (possibly proxied) request for audit rows.
	IP        string
	UserAgent string
}

func stateFrom(ctx context.Context) *requestState {
	if st, ok := ctx.Value(ctxKeySession).(*requestState); ok {
		return st
	}
	return &requestState{Locale: "id"}
}

// withState attaches a fresh state holder.
func withState(ctx context.Context, st *requestState) context.Context {
	return context.WithValue(ctx, ctxKeySession, st)
}

// SessionFrom returns the request session (nil when anonymous).
func SessionFrom(ctx context.Context) *auth.Session { return stateFrom(ctx).Session }

// UserFrom returns the authenticated user (nil when anonymous).
func UserFrom(ctx context.Context) *auth.User { return stateFrom(ctx).User }

// LocaleFrom returns the resolved request locale.
func LocaleFrom(ctx context.Context) string { return stateFrom(ctx).Locale }

// RequestIDFrom returns the correlation id.
func RequestIDFrom(ctx context.Context) string { return stateFrom(ctx).RequestID }

// clientMetaFrom builds the audit metadata for the identity service.
func clientMetaFrom(ctx context.Context) auth.ClientMeta {
	st := stateFrom(ctx)
	return auth.ClientMeta{IP: st.IP, UserAgent: st.UserAgent}
}

// requireUser is the handler-side guard used after RequireAuth has run.
func requireUser(w http.ResponseWriter, r *http.Request) (*auth.User, bool) {
	u := UserFrom(r.Context())
	if u == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return nil, false
	}
	return u, true
}
