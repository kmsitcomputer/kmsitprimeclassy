package profile

import (
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
)

func TestMaskEmailDoesNotExposeFullLocalPart(t *testing.T) {
	for input, want := range map[string]string{
		"alice@example.test": "al••••@example.test",
		"x@example.test":     "x••••@example.test",
	} {
		if got := maskEmail(input); got != want {
			t.Errorf("maskEmail(%q) = %q, want %q", input, got, want)
		}
	}
}

func TestReferralCodeRoleScopeAndFormat(t *testing.T) {
	for _, role := range []string{auth.RoleAgen, auth.RoleKorsal, auth.RoleSales, auth.RoleSalesKurirSub} {
		if !ownsReferralCode(role) {
			t.Errorf("ownsReferralCode(%q) = false", role)
		}
	}
	for _, role := range []string{auth.RoleKonsumen, auth.RoleAdmin, auth.RoleKurir, auth.RoleSuperAdmin} {
		if ownsReferralCode(role) {
			t.Errorf("ownsReferralCode(%q) = true", role)
		}
	}
	for _, code := range []string{"AG-ABC123", "SA-1-Z"} {
		if !referralCodePattern.MatchString(code) {
			t.Errorf("canonical referral code %q rejected", code)
		}
	}
	for _, code := range []string{"lowercase", "SPACE CODE", "x/y"} {
		if referralCodePattern.MatchString(code) {
			t.Errorf("invalid referral code %q accepted", code)
		}
	}
}
