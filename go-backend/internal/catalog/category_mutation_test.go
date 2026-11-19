package catalog

import "testing"

func TestNormalizeCategoryName(t *testing.T) {
	if got := NormalizeCategoryName("  Kue\u200b   Lumpur  "); got != "Kue Lumpur" {
		t.Fatalf("NormalizeCategoryName = %q, want %q", got, "Kue Lumpur")
	}
	if got := categoryNameKey("  KUE Lumpur "); got != "kue lumpur" {
		t.Fatalf("categoryNameKey = %q, want %q", got, "kue lumpur")
	}
}
