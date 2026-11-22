package httpx

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// TestEnvelopeMatchesLaravel is the contract guard: App\Support\ApiResponse always emits exactly these five
// keys, in this order, with data/errors/meta nullable. The SPA and the Nuxt storefront read `data`
// unconditionally, so a missing key is a breaking change.
func TestEnvelopeMatchesLaravel(t *testing.T) {
	rec := httptest.NewRecorder()
	OK(rec, map[string]any{"id": 1}, "OK", NewMeta(2, 5, 70))

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "application/json; charset=utf-8" {
		t.Errorf("content-type = %q", ct)
	}
	if cc := rec.Header().Get("Cache-Control"); !strings.Contains(cc, "no-store") {
		t.Errorf("cache-control must be no-store, got %q", cc)
	}

	var body map[string]json.RawMessage
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("invalid json: %v", err)
	}
	for _, key := range []string{"success", "message", "data", "errors", "meta"} {
		if _, ok := body[key]; !ok {
			t.Errorf("envelope missing key %q", key)
		}
	}
	if len(body) != 5 {
		t.Errorf("envelope has %d keys, want exactly 5: %v", len(body), keysOf(body))
	}

	var meta Meta
	if err := json.Unmarshal(body["meta"], &meta); err != nil {
		t.Fatal(err)
	}
	if meta != (Meta{CurrentPage: 2, LastPage: 5, Total: 70}) {
		t.Errorf("meta = %+v", meta)
	}
	if string(body["errors"]) != "null" {
		t.Errorf("errors should be null on success, got %s", body["errors"])
	}
}

func TestFailureEnvelope(t *testing.T) {
	rec := httptest.NewRecorder()
	Failure(rec, http.StatusForbidden, "nope", nil)

	var body map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body["success"] != false {
		t.Errorf("success = %v", body["success"])
	}
	if body["data"] != nil {
		t.Errorf("data must be null on failure, got %v", body["data"])
	}
	if rec.Code != http.StatusForbidden {
		t.Errorf("status = %d", rec.Code)
	}
}

func TestErrorPayloadShapes(t *testing.T) {
	// Plain error: no errors member.
	plain := NewError(http.StatusNotFound, "gone")
	if plain.ErrorsPayload() != nil {
		t.Errorf("plain error must render errors:null, got %#v", plain.ErrorsPayload())
	}

	// Validation: field -> messages.
	v := Validation(map[string][]string{"email": {"Invalid."}})
	payload, ok := v.ErrorsPayload().(map[string]any)
	if !ok {
		t.Fatalf("validation payload type = %T", v.ErrorsPayload())
	}
	if _, exists := payload["email"]; !exists {
		t.Error("validation payload must carry the field key")
	}

	// Machine-readable code (registration_unavailable): errors.code = ["..."].
	coded := &Error{Status: 503, Message: "unavailable", Code: "registration_unavailable"}
	codedPayload, ok := coded.ErrorsPayload().(map[string]any)
	if !ok {
		t.Fatal("coded payload type")
	}
	codeVals, ok := codedPayload["code"].([]string)
	if !ok || len(codeVals) != 1 || codeVals[0] != "registration_unavailable" {
		t.Errorf("code payload = %#v", codedPayload["code"])
	}
}

func TestNewMetaClampsToAtLeastOne(t *testing.T) {
	m := NewMeta(0, 0, 0)
	if m.CurrentPage != 1 || m.LastPage != 1 {
		t.Errorf("meta must clamp to 1: %+v", m)
	}
}

func TestQueryHelpersMirrorLaravel(t *testing.T) {
	r := httptest.NewRequest(http.MethodGet, "/x?page=3&per_page=0&random=1&on=yes&no=false&search=%20kue%20", nil)

	if got := QueryInt(r, "page", 1, 1); got != 3 {
		t.Errorf("page = %d", got)
	}
	if got := QueryInt(r, "per_page", 15, 1); got != 15 {
		t.Errorf("per_page below the floor must fall back, got %d", got)
	}
	if got := QueryInt(r, "missing", 7, 1); got != 7 {
		t.Errorf("missing default = %d", got)
	}
	for _, name := range []string{"random", "on"} {
		if !QueryBool(r, name) {
			t.Errorf("%s should parse as true", name)
		}
	}
	if QueryBool(r, "no") {
		t.Error("false should parse as false")
	}
	if got := QueryString(r, "search"); got != "kue" {
		t.Errorf("search must be trimmed, got %q", got)
	}
}

func TestDecodeJSONRejectsMalformedBody(t *testing.T) {
	r := httptest.NewRequest(http.MethodPost, "/x", strings.NewReader("{not json"))
	var dst map[string]any
	err := DecodeJSON(r, &dst)
	if err == nil {
		t.Fatal("expected a validation error for malformed JSON")
	}
	apiErr, ok := err.(*Error)
	if !ok || apiErr.Status != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 *Error, got %#v", err)
	}

	// An empty body is valid (several endpoints take no payload).
	empty := httptest.NewRequest(http.MethodPost, "/x", nil)
	if err := DecodeJSON(empty, &dst); err != nil {
		t.Errorf("empty body must decode cleanly: %v", err)
	}
}

func keysOf(m map[string]json.RawMessage) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
