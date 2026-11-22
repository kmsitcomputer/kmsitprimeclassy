package shipping

import "testing"

func TestSameRegencyNormalizesProviderAndMasterPrefixes(t *testing.T) {
	for _, pair := range [][2]string{
		{"Kabupaten Bandung", "Bandung"},
		{"Kota Bandung", "Kota Bandung"},
		{"Bandung", "Kabupaten Bandung"},
	} {
		if !sameRegency(pair[0], pair[1]) {
			t.Errorf("expected %q and %q to match", pair[0], pair[1])
		}
	}
}
