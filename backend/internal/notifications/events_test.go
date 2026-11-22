package notifications

import "testing"

func TestGroupedRupiah(t *testing.T) {
	for _, test := range []struct {
		value int64
		want  string
	}{{0, "0"}, {1000, "1.000"}, {1234567, "1.234.567"}, {-1234, "-1.234"}} {
		t.Run(test.want, func(t *testing.T) {
			if got := groupedRupiah(test.value); got != test.want {
				t.Fatalf("groupedRupiah(%d)=%q, want %q", test.value, got, test.want)
			}
		})
	}
}
