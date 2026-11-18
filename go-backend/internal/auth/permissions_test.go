package auth

import (
	"reflect"
	"testing"
)

// TestCanonicalSlugMatchesLaravel checks the legacy rename window: `sales-kurir` is still in old rows and must
// resolve to `sales-kurir-sub` (App\Models\Role::canonicalSlug).
func TestCanonicalSlugMatchesLaravel(t *testing.T) {
	if got := CanonicalSlug("sales-kurir"); got != RoleSalesKurirSub {
		t.Errorf("legacy slug = %q, want %q", got, RoleSalesKurirSub)
	}
	for _, slug := range AllRoles {
		if got := CanonicalSlug(slug); got != slug {
			t.Errorf("canonical slug %q changed to %q", slug, got)
		}
	}
	if got := SlugsFor(RoleSalesKurirSub); !reflect.DeepEqual(got, []string{"sales-kurir-sub", "sales-kurir"}) {
		t.Errorf("SlugsFor = %v", got)
	}
	if got := SlugsFor(RoleSales); !reflect.DeepEqual(got, []string{"sales"}) {
		t.Errorf("SlugsFor(sales) = %v", got)
	}
	// Sales-Kurir-Sub inherits every Sales right, so both spellings count as sellers.
	if got := SellerSlugs(); !reflect.DeepEqual(got, []string{"sales", "sales-kurir-sub", "sales-kurir"}) {
		t.Errorf("SellerSlugs = %v", got)
	}
}

// TestLockedRoleSet proves the 11-role vocabulary from docs/05 §1 has not drifted.
func TestLockedRoleSet(t *testing.T) {
	want := []string{"super_admin", "agen", "korsal", "sales", "sales-kurir-sub", "konsumen", "admin", "keuangan", "kurir", "koordinator-kurir", "gudang"}
	if len(AllRoles) != 11 {
		t.Fatalf("expected 11 roles, got %d: %v", len(AllRoles), AllRoles)
	}
	seen := map[string]bool{}
	for _, r := range AllRoles {
		seen[r] = true
	}
	for _, w := range want {
		if !seen[w] {
			t.Errorf("locked role %q missing", w)
		}
	}
}

func TestIsRoleCanonicalises(t *testing.T) {
	u := &User{Role: "sales-kurir"}
	if !u.IsRole(RoleSalesKurirSub) {
		t.Error("a legacy row must satisfy the canonical role check")
	}
	if u.IsRole(RoleSales) {
		t.Error("sales-kurir must not be treated as plain sales")
	}
	u = &User{Role: "konsumen"}
	if u.IsRole(RoleAgen, RoleAdmin) {
		t.Error("konsumen must not match agen/admin")
	}
	if !u.IsRole(RoleKonsumen) {
		t.Error("konsumen must match itself")
	}
	if (&User{}).IsRole(RoleKonsumen) {
		t.Error("an empty role must match nothing")
	}
}

// TestPermissionMapMatchesLaravel mirrors App\Support\PermissionMap::forRole plus the sheets.manage addendum.
func TestPermissionMapMatchesLaravel(t *testing.T) {
	cases := map[string][]string{
		RoleSuperAdmin: {
			"system.config.manage", "system.payment.manage", "system.shipping.manage", "system.cms.manage",
			"users.view.all", "orders.view.all", "orders.manage.status", "orders.manage.payment",
			"orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel", "sheets.manage",
		},
		RoleAgen: {
			"users.view.network", "stock.view.own", "stock.manage",
			"orders.view.network", "orders.manage.status", "orders.manage.payment",
			"orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel", "orders.create", "sheets.manage",
		},
		RoleKorsal:  {"users.view.network", "orders.view.network", "orders.create"},
		RoleSales:   {"users.view.network", "orders.view.network", "orders.create"},
		RoleKonsumen: {"orders.view.own", "orders.create"},
		RoleAdmin: {
			"users.view.network", "stock.view.own", "stock.manage",
			"orders.view.network", "orders.manage.status",
			"orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel", "sheets.manage",
		},
		RoleKeuangan: {
			"users.view.network", "orders.view.network",
			"finance.view", "finance.payment.verify", "finance.cod.settle",
			"finance.dp.verify", "finance.dp.settle",
			"finance.refund.manage", "finance.additional.manage", "finance.report.view",
		},
		RoleKurir:             {"orders.view.assigned", "orders.manage.shipment"},
		RoleKoordinatorKurir:  {"users.view.network", "orders.view.network", "orders.manage.shipment"},
		RoleGudang:            {"stock.view.own", "orders.view.assigned"},
		RoleSalesKurirSub:     {"users.view.network", "orders.create", "orders.view.assigned", "orders.manage.shipment"},
		"unknown":             {},
	}
	for role, want := range cases {
		got := Permissions(role)
		if len(got) == 0 && len(want) == 0 {
			continue
		}
		if !reflect.DeepEqual(got, want) {
			t.Errorf("Permissions(%q)\n got %v\nwant %v", role, got, want)
		}
	}
}

// TestKeuanganHasNoOperationalCapabilities encodes the separation-of-duties rule: finance may verify and settle
// money but must never gain status/fulfillment/cancel or system configuration.
func TestKeuanganHasNoOperationalCapabilities(t *testing.T) {
	forbidden := []string{
		"orders.manage.status", "orders.manage.fulfillment", "orders.manage.shipment", "orders.cancel",
		"system.config.manage", "system.payment.manage", "stock.manage", "sheets.manage",
	}
	got := Permissions(RoleKeuangan)
	for _, f := range forbidden {
		for _, g := range got {
			if g == f {
				t.Errorf("keuangan must not have %q", f)
			}
		}
	}
}

func TestParseRoleList(t *testing.T) {
	got := ParseRoleList("super_admin, agen ,,admin")
	want := []string{"super_admin", "agen", "admin"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("ParseRoleList = %v, want %v", got, want)
	}
}

// TestNormalizePhone mirrors App\Support\PhoneNormalizer.
func TestNormalizePhone(t *testing.T) {
	cases := map[string]string{
		"081234567890":      "081234567890",
		"+6281234567890":    "081234567890",
		"6281234567890":     "081234567890",
		"0812-3456.7890":    "081234567890",
		" 0812 3456 7890 ":  "081234567890",
		"(0812) 3456 7890":  "081234567890",
		"12345":             "12345", // unknown shape is preserved verbatim
		"":                  "",
	}
	for in, want := range cases {
		if got := NormalizePhone(in); got != want {
			t.Errorf("NormalizePhone(%q) = %q, want %q", in, got, want)
		}
	}
}

// TestLooksLikeEmail mirrors filter_var(..., FILTER_VALIDATE_EMAIL) for the shapes the login endpoint accepts.
func TestLooksLikeEmail(t *testing.T) {
	for _, email := range []string{"a@b.co", "user.name+tag@example.test"} {
		if !LooksLikeEmail(email) {
			t.Errorf("%q should look like an email", email)
		}
	}
	for _, not := range []string{"", "0812345678", "a@b", "@b.co", "a b@c.co", "a@b."} {
		if LooksLikeEmail(not) {
			t.Errorf("%q should not look like an email", not)
		}
	}
}
