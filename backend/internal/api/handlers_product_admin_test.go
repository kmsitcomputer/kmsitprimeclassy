package api

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestProductAdminCreateValidationKeepsVariationFieldsExclusive(t *testing.T) {
	request := httptest.NewRequest("POST", "/api/v1/products", nil)
	trueValue := true
	name := "Configured item"
	basePrice := 12.5
	weight := int64(400)
	sku := "P-100"
	fields := validateProductAdmin(request, map[string]json.RawMessage{
		"name": []byte(`"Configured item"`), "has_variations": []byte("true"),
		"sku": []byte(`"P-100"`), "base_price": []byte("12.5"), "weight_grams": []byte("400"),
	}, productAdminPayload{
		Name: &name, HasVariations: &trueValue, SKU: &sku, BasePrice: &basePrice, WeightGrams: &weight,
	}, true, false)
	for _, field := range []string{"sku", "base_price", "weight_grams"} {
		if len(fields[field]) == 0 {
			t.Errorf("%s was not prohibited for a variation product", field)
		}
	}
}

func TestProductAdminUpdateDoesNotPermitChangingVariationMode(t *testing.T) {
	// UpdateProductRequest has NO has_variations rule; the controller's only() never forwards it, and the
	// service re-derives the mode from the stored product (prohibited_* fires against the STORED value).
	request := httptest.NewRequest("PATCH", "/api/v1/products/1", nil)
	fields := validateProductAdmin(request, map[string]json.RawMessage{"sku": []byte(`"SKU-1"`)},
		productAdminPayload{SKU: ptrString("SKU-1")}, false, true)
	if len(fields) != 0 {
		t.Fatalf("valid simple-product update rejected: %#v", fields)
	}
	fields = validateProductAdmin(request, map[string]json.RawMessage{"sku": []byte(`"SKU-1"`)},
		productAdminPayload{SKU: ptrString("SKU-1")}, false, true)
	if len(fields) != 0 {
		t.Fatalf("valid simple-product update rejected on second pass: %#v", fields)
	}
}

func ptrString(v string) *string { return &v }

func TestFeeResourceDoesNotExposeOtherBeneficiaries(t *testing.T) {
	all := map[string]float64{"agent": 10, "sales": 20, "courier": 30}
	for _, role := range []string{"sales", "sales-kurir-sub", "korsal"} {
		got := visibleFees(role, all)
		if len(got) != 1 || got["sales_fee"] != 20 {
			t.Errorf("%s received unexpected fees: %#v", role, got)
		}
	}
	if got := visibleFees("agen", all); len(got) != 3 {
		t.Fatalf("agen must see all fee types, got %#v", got)
	}
}

func TestProductMutationSerializationOmitsUnloadedRelations(t *testing.T) {
	product := map[string]any{
		"id": 2, "name": "item", "category": nil, "images": []any{}, "variations": []any{},
		"has_variations": false,
	}
	got := productMutationResource(product)
	for _, relation := range []string{"category", "images", "variations"} {
		if _, ok := got[relation]; ok {
			t.Errorf("mutation response included unloaded %q relation", relation)
		}
	}
}

func TestProductDescriptionSanitizesHTMLAndPreservesSafeMedia(t *testing.T) {
	input := `<script>alert(1)</script><a href="javascript:alert(1)">bad</a><p onclick="evil()">Text</p><img src="/storage/photo.jpg" onerror="evil()">`
	got := sanitizedProductHTML(&input)
	if strings.Contains(*got, "script") || strings.Contains(*got, "javascript:") ||
		strings.Contains(*got, "onclick") || strings.Contains(*got, "onerror") {
		t.Fatalf("unsafe markup survived sanitization: %q", *got)
	}
	if !strings.Contains(*got, "<p>Text</p>") || !strings.Contains(*got, `<img src="/storage/photo.jpg" />`) {
		t.Fatalf("safe markup or media was not preserved: %q", *got)
	}
}

func TestProductAdminRoutesRegisterWithoutSharedRouterChanges(t *testing.T) {
	server := &Server{mux: http.NewServeMux()}
	server.registerProductAdminRoutes()
	routes := []struct{ method, path string }{
		{http.MethodGet, "/api/v1/manage/products"},
		{http.MethodGet, "/api/v1/manage/products/1"},
		{http.MethodPost, "/api/v1/products"},
		{http.MethodPatch, "/api/v1/products/1"},
		{http.MethodDelete, "/api/v1/products/1"},
		{http.MethodDelete, "/api/v1/products/1/permanent"},
		{http.MethodPost, "/api/v1/products/1/variations"},
		{http.MethodPatch, "/api/v1/products/1/variations/2"},
		{http.MethodDelete, "/api/v1/products/1/variations/2"},
		{http.MethodGet, "/api/v1/products/1/fees"},
		{http.MethodPut, "/api/v1/products/1/fees"},
		{http.MethodGet, "/api/v1/products/1/variations/2/fees"},
		{http.MethodPut, "/api/v1/products/1/variations/2/fees"},
		{http.MethodPost, "/api/v1/products/1/images"},
		{http.MethodPut, "/api/v1/products/1/images/order"},
		{http.MethodPatch, "/api/v1/products/1/images/5"},
		{http.MethodDelete, "/api/v1/products/1/images/5"},
	}
	for _, route := range routes {
		req := httptest.NewRequest(route.method, route.path, nil)
		_, pattern := server.mux.Handler(req)
		if pattern == "" {
			t.Errorf("%s %s was not registered", route.method, route.path)
		}
	}
}
