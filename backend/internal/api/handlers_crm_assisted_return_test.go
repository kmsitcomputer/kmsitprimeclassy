package api

import (
	"reflect"
	"testing"
)

func TestParseAssistedReturnItemsReportsEveryMissingRequiredField(t *testing.T) {
	lines, errors := parseAssistedReturnItems(map[string]string{
		"items[0][__present]": "1",
	}, "id")

	if len(lines) != 0 {
		t.Fatalf("lines = %#v, want no valid lines", lines)
	}
	want := map[string][]string{
		"items.0.order_item_id": {"items.0.order_item_id wajib diisi."},
		"items.0.quantity":      {"items.0.quantity wajib diisi."},
	}
	if !reflect.DeepEqual(errors, want) {
		t.Fatalf("errors = %#v, want %#v", errors, want)
	}
}
