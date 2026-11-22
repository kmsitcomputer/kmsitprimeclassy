package api

import "testing"

func TestNominatimAddressLabelsKeepAdministrativeLevelsDistinct(t *testing.T) {
	got := nominatimAddressLabels(map[string]any{
		"state": "Jawa Barat", "county": "Kabupaten Bandung", "city_district": "Kecamatan Cimenyan",
		"suburb": "Desa Mekarjaya", "postcode": "40100",
	})
	want := map[string]string{
		"province": "Jawa Barat", "regency": "Kabupaten Bandung", "district": "Kecamatan Cimenyan",
		"village": "Desa Mekarjaya", "postal_code": "40100",
	}
	for key, value := range want {
		if got[key] != value {
			t.Errorf("%s = %q, want %q", key, got[key], value)
		}
	}
}

func TestNominatimAddressLabelsUseProviderKeyVariants(t *testing.T) {
	got := nominatimAddressLabels(map[string]any{
		"province": "Jawa Barat", "city": "Kota Bandung", "district": "Coblong", "village": "Dago",
	})
	if got["province"] != "Jawa Barat" || got["regency"] != "Kota Bandung" || got["district"] != "Coblong" || got["village"] != "Dago" {
		t.Fatalf("unexpected normalized labels: %#v", got)
	}
}
