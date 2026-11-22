package catalog

import "testing"

func TestProductSlugMatchesSlugStyleAndEscapesEmpty(t *testing.T) {
	tests := []struct {
		name, want string
	}{
		{"Kopi Susu & Roti", "kopi-susu-roti"},
		{"  Dua---Kata  ", "dua-kata"},
		{"產品", "產品"},
		{"🌱", ""},
	}
	for _, tt := range tests {
		if got := productSlug(tt.name); got != tt.want {
			t.Errorf("productSlug(%q) = %q, want %q", tt.name, got, tt.want)
		}
	}
}

func TestEscapeLikeTreatsUserWildcardsLiterally(t *testing.T) {
	if got, want := escapeLike(`50%_off\`), `50\%\_off\\`; got != want {
		t.Fatalf("escapeLike() = %q, want %q", got, want)
	}
}

func TestImageReorderRequiresTheExactSet(t *testing.T) {
	if !sameIDs([]int64{2, 4, 9}, []int64{9, 2, 4}) {
		t.Fatal("ordering may change while the gallery membership stays exact")
	}
	for _, ids := range [][]int64{{2, 4}, {2, 4, 9, 10}, {2, 4, 4}} {
		if sameIDs([]int64{2, 4, 9}, ids) {
			t.Fatalf("sameIDs accepted non-exact gallery ids %v", ids)
		}
	}
}

func TestVariationCompositionMapComparison(t *testing.T) {
	if !sameIntMap(map[int64]int64{2: 8, 4: 9}, map[int64]int64{4: 9, 2: 8}) {
		t.Fatal("map key iteration order must not affect duplicate composition checks")
	}
	if sameIntMap(map[int64]int64{2: 8}, map[int64]int64{2: 9}) {
		t.Fatal("different chosen options must not be treated as the same composition")
	}
}
