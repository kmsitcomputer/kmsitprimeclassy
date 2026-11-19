package users

import (
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
)

func TestCanCreateMirrorsAllowedRoleMatrix(t *testing.T) {
	tests := []struct {
		actor, target string
		want          bool
	}{
		{auth.RoleSuperAdmin, auth.RoleAgen, true},
		{auth.RoleSuperAdmin, auth.RoleAdmin, false},
		{auth.RoleAgen, auth.RoleSalesKurirSub, true},
		{auth.RoleAgen, auth.RoleKonsumen, false},
		{auth.RoleKorsal, auth.RoleSales, true},
		{auth.RoleKorsal, auth.RoleAdmin, false},
		{auth.RoleKoordinatorKurir, auth.RoleKurir, true},
		{auth.RoleKoordinatorKurir, auth.RoleSales, false},
	}
	for _, tt := range tests {
		t.Run(tt.actor+"_"+tt.target, func(t *testing.T) {
			actor := &auth.User{Role: tt.actor, ID: 11}
			if got := CanCreate(actor, tt.target); got != tt.want {
				t.Fatalf("CanCreate(%s,%s)=%v, want %v", tt.actor, tt.target, got, tt.want)
			}
		})
	}
}

func TestVisibilityAndDeletionRemainBranchScoped(t *testing.T) {
	branchA, branchB := int64(4), int64(8)
	korsal := &auth.User{ID: 10, Role: auth.RoleKorsal, AgentID: &branchA}
	ownSales := &auth.User{ID: 11, Role: auth.RoleSales, AgentID: &branchA, KorsalID: intPtr(10)}
	foreignSales := &auth.User{ID: 12, Role: auth.RoleSales, AgentID: &branchB, KorsalID: intPtr(20)}
	if !CanView(korsal, ownSales) || CanView(korsal, foreignSales) {
		t.Fatal("Korsal visibility escaped its assigned downline")
	}
	agen := &auth.User{ID: 4, Role: auth.RoleAgen, AgentID: &branchA}
	downline := &auth.User{ID: 15, Role: auth.RoleAdmin, AgentID: &branchA}
	other := &auth.User{ID: 18, Role: auth.RoleAdmin, AgentID: &branchB}
	self := &auth.User{ID: 4, Role: auth.RoleAgen, AgentID: &branchA}
	if !CanDelete(agen, downline) || CanDelete(agen, other) || CanDelete(agen, self) {
		t.Fatal("Agen deletion scope or self-delete guard changed")
	}
}

func TestCreateValidationRequiresMatchingPasswordAndKorsalData(t *testing.T) {
	err := validateCreate(CreateInput{Name: "User", Email: "bad", Phone: "0812", Password: "password1", Confirmation: "password2"})
	domain, ok := err.(*Error)
	if !ok || domain.Status != 422 || domain.Fields["email"] == nil || domain.Fields["password"] == nil {
		t.Fatalf("invalid create request error = %#v", err)
	}
	if err := validateCreate(CreateInput{Name: "User", Email: "u@example.test", Phone: "08123456789", Password: "password1", Confirmation: "password1"}); err != nil {
		t.Fatalf("valid create request rejected: %v", err)
	}
}

func TestPhoneNormalizerMatchesIdentityNormalizer(t *testing.T) {
	for input, want := range map[string]string{
		"+62 812-3456-7890": "081234567890",
		"6281234567890":     "081234567890",
		"08 (123) 456-7890": "081234567890",
	} {
		if got := NormalizePhone(input); got != want {
			t.Errorf("NormalizePhone(%q)=%q want %q", input, got, want)
		}
	}
}

func intPtr(v int64) *int64 { return &v }
