package auth

import "strings"

// Role slugs. These mirror App\Models\Role exactly — the 11 locked roles from docs/05.
const (
	RoleSuperAdmin      = "super_admin"
	RoleAgen            = "agen"
	RoleKorsal          = "korsal"
	RoleSales           = "sales"
	RoleSalesKurirSub   = "sales-kurir-sub"
	RoleKonsumen        = "konsumen"
	RoleAdmin           = "admin"
	RoleKeuangan        = "keuangan"
	RoleKurir           = "kurir"
	RoleKoordinatorKurir = "koordinator-kurir"
	RoleGudang          = "gudang"

	// legacySalesKurir is the pre-rename spelling still present in old rows.
	legacySalesKurir = "sales-kurir"
)

// AllRoles is the locked role set (docs/05 §1). Used by the parity test to prove the Go and Laravel
// role vocabularies have not diverged.
var AllRoles = []string{
	RoleSuperAdmin, RoleAgen, RoleKorsal, RoleSales, RoleSalesKurirSub, RoleKonsumen,
	RoleAdmin, RoleKeuangan, RoleKurir, RoleKoordinatorKurir, RoleGudang,
}

// CanonicalSlug maps a stored slug to its canonical spelling (Role::canonicalSlug).
func CanonicalSlug(slug string) string {
	if slug == legacySalesKurir {
		return RoleSalesKurirSub
	}
	return slug
}

// SlugsFor returns every stored spelling of a canonical slug, for `IN (...)` queries that must keep
// matching during the rename window (Role::slugsFor).
func SlugsFor(canonical string) []string {
	if canonical == RoleSalesKurirSub {
		return []string{RoleSalesKurirSub, legacySalesKurir}
	}
	return []string{canonical}
}

// SellerSlugs is every slug that counts as a Sales seller (Sales-Kurir-Sub inherits all Sales rights).
func SellerSlugs() []string {
	return append([]string{RoleSales}, SlugsFor(RoleSalesKurirSub)...)
}

// IsRole reports whether the user's canonical role matches any of the given slugs.
func (u *User) IsRole(slugs ...string) bool {
	mine := CanonicalSlug(u.Role)
	if mine == "" {
		return false
	}
	for _, s := range slugs {
		if CanonicalSlug(s) == mine {
			return true
		}
	}
	return false
}

// HasAnyRole is the middleware-level check used by `role:` route groups (EnsureRole).
func (u *User) HasAnyRole(slugs ...string) bool { return u.IsRole(slugs...) }

// Permissions is the frontend capability-hint map (App\Support\PermissionMap).
//
// These are UX hints only. Every capability here is independently enforced server-side by the route's
// role group and by ownership checks in the domain layer — the map is never a security boundary.
func Permissions(roleSlug string) []string {
	var caps []string
	switch CanonicalSlug(roleSlug) {
	case RoleSuperAdmin:
		caps = []string{
			"system.config.manage", "system.payment.manage", "system.shipping.manage", "system.cms.manage",
			"users.view.all", "orders.view.all", "orders.manage.status", "orders.manage.payment",
			"orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel",
		}
	case RoleAgen:
		caps = []string{
			"users.view.network", "stock.view.own", "stock.manage",
			"orders.view.network", "orders.manage.status", "orders.manage.payment",
			"orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel", "orders.create",
		}
	case RoleKorsal, RoleSales:
		caps = []string{"users.view.network", "orders.view.network", "orders.create"}
	case RoleKonsumen:
		caps = []string{"orders.view.own", "orders.create"}
	case RoleAdmin:
		caps = []string{
			"users.view.network", "stock.view.own", "stock.manage",
			"orders.view.network", "orders.manage.status",
			"orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel",
		}
	case RoleKeuangan:
		// Separation of duties: financial operations only, never operational progression and never
		// system configuration (docs/05 §1).
		caps = []string{
			"users.view.network", "orders.view.network",
			"finance.view", "finance.payment.verify", "finance.cod.settle",
			"finance.dp.verify", "finance.dp.settle",
			"finance.refund.manage", "finance.additional.manage", "finance.report.view",
		}
	case RoleKurir:
		caps = []string{"orders.view.assigned", "orders.manage.shipment"}
	case RoleKoordinatorKurir:
		caps = []string{"users.view.network", "orders.view.network", "orders.manage.shipment"}
	case RoleGudang:
		caps = []string{"stock.view.own", "orders.view.assigned"}
	case RoleSalesKurirSub:
		caps = []string{"users.view.network", "orders.create", "orders.view.assigned", "orders.manage.shipment"}
	}
	switch CanonicalSlug(roleSlug) {
	case RoleSuperAdmin, RoleAgen, RoleAdmin:
		caps = append(caps, "sheets.manage")
	}
	return caps
}

// ParseRoleList parses the `role:a,b,c` middleware argument.
func ParseRoleList(param string) []string {
	var out []string
	for _, part := range strings.Split(param, ",") {
		if p := strings.TrimSpace(part); p != "" {
			out = append(out, p)
		}
	}
	return out
}
