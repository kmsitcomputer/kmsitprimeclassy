package api

import (
	"bytes"
	"mime/multipart"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

func TestDecodeHomepageBlockMultipartPreservesLaravelFormSemantics(t *testing.T) {
	var body bytes.Buffer
	w := multipart.NewWriter(&body)
	for k, v := range map[string]string{"type": "category", "content[category_ids][0]": "4", "content[category_ids][1]": "4", "sort_order": "4", "is_active": "0"} {
		if err := w.WriteField(k, v); err != nil {
			t.Fatal(err)
		}
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	r := httptest.NewRequest("POST", "/api/v1/cms/homepage-blocks", &body)
	r.Header.Set("Content-Type", w.FormDataContentType())
	input, _, err := decodeHomepageBlockMultipart(r)
	if err != nil {
		t.Fatal(err)
	}
	ids, ok := input.Content["category_ids"].([]any)
	if !ok || len(ids) != 1 || ids[0] != float64(4) {
		t.Fatalf("normalized ids = %#v", input.Content["category_ids"])
	}
	if input.SortOrder == nil || *input.SortOrder != 4 {
		t.Fatalf("sort order = %#v", input.SortOrder)
	}
	if input.IsActive == nil || *input.IsActive {
		t.Fatalf("is_active = %#v", input.IsActive)
	}
}

func TestValidateHomepageBlockUploadEnforcesTenMiBLaravelRule(t *testing.T) {
	data := append([]byte("\x89PNG\r\n\x1a\n"), bytes.Repeat([]byte{0}, (10<<20)+1)...)
	errs := fieldErrors{}
	validateHomepageBlockUpload(t.Context(), errs, "image", &media.Upload{Filename: "large.png", Content: data})
	if !strings.Contains(strings.Join(errs["image"], " "), "10240") {
		t.Fatalf("errors = %#v", errs["image"])
	}
}
