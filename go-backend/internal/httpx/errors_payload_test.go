package httpx

import "testing"

// TestErrorsPayloadShapes pins the three `errors` shapes the reference produces, and the trap that made an
// ApiException-style 422 answer `"errors": null` while its fields were populated:
//
// a NIL map stored in an `any` is not a nil interface. `e.ErrorsAny != nil` is then TRUE, so a naive
// `if e.ErrorsAny != nil { return e.ErrorsAny }` returns an EMPTY map and silently swallows the FieldStrings
// branch. That defect would have hidden every field error on the order-creation endpoint.
func TestErrorsPayloadShapes(t *testing.T) {
	t.Run("validation exception: arrays", func(t *testing.T) {
		got, ok := (&Error{Fields: map[string][]string{"items": {"wajib diisi"}}}).ErrorsPayload().(map[string]any)
		if !ok {
			t.Fatalf("payload = %#v", got)
		}
		list, ok := got["items"].([]string)
		if !ok || len(list) != 1 || list[0] != "wajib diisi" {
			t.Errorf("items = %#v", got["items"])
		}
	})

	t.Run("api exception: single sentences", func(t *testing.T) {
		got, ok := (&Error{FieldStrings: map[string]string{"voucher_code": "tidak valid"}}).ErrorsPayload().(map[string]any)
		if !ok {
			t.Fatalf("payload = %#v", got)
		}
		if got["voucher_code"] != "tidak valid" {
			t.Errorf("voucher_code = %#v", got["voucher_code"])
		}
	})

	t.Run("insufficient stock: mixed typed payload", func(t *testing.T) {
		got, ok := (&Error{ErrorsAny: map[string]any{
			"item": "Kue Lumpur", "available": 0, "requested": 1,
		}}).ErrorsPayload().(map[string]any)
		if !ok {
			t.Fatalf("payload = %#v", got)
		}
		if got["available"] != 0 || got["requested"] != 1 || got["item"] != "Kue Lumpur" {
			t.Errorf("payload = %#v", got)
		}
	})

	t.Run("nil map must not shadow FieldStrings", func(t *testing.T) {
		var empty map[string]any
		err := &Error{FieldStrings: map[string]string{"dp_amount": "tidak valid"}, ErrorsAny: empty}
		got, ok := err.ErrorsPayload().(map[string]any)
		if !ok {
			t.Fatalf("payload = %#v — the empty map swallowed the field errors", err.ErrorsPayload())
		}
		if got["dp_amount"] != "tidak valid" || len(got) != 1 {
			t.Errorf("payload = %#v, want exactly the field error", got)
		}
	})

	t.Run("nothing attached is null", func(t *testing.T) {
		if payload := (&Error{}).ErrorsPayload(); payload != nil {
			t.Errorf("payload = %#v, want nil", payload)
		}
	})
}
