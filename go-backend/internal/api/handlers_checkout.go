package api

import (
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/checkout"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// handleCheckoutSteps is GET /api/v1/checkout/steps.
//
// PUBLIC by design (routes/api_v1.php keeps it outside the auth group) so a guest starting checkout still
// sees the full dynamic step list. `$request->user()` is still resolved when a session cookie is present,
// which is what makes the same endpoint role-aware without requiring authentication.
//
// `shipping_method` is only forwarded for the two real values — exactly like the controller's
// `in_array(..., ['rajaongkir','openroute'], true)` guard. An unrecognised value means "not chosen yet", so
// payment_methods stays unrestricted rather than silently filtering everything out.
func (s *Server) handleCheckoutSteps(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	var shippingMethod *string
	if raw := httpx.QueryString(r, "shipping_method"); raw == "rajaongkir" || raw == "openroute" {
		shippingMethod = &raw
	}

	viewer := checkout.Viewer{}
	if u := UserFrom(ctx); u != nil {
		viewer.Authenticated = true
		viewer.IsKonsumen = u.IsRole(auth.RoleKonsumen)
		viewer.AgentID = u.AgentID
	}

	resolved, err := checkout.Resolve(ctx, s.database, viewer, shippingMethod)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, resolved, "OK", nil)
}
