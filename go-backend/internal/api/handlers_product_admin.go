package api

import (
	"bytes"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"math"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/catalog"
	"github.com/kmsitcomputer/primeclassy-go/internal/cms"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

type productAdminPayload struct {
	Name             *string           `json:"name"`
	Slug             *string           `json:"slug"`
	SKU              *string           `json:"sku"`
	CategoryID       *int64            `json:"category_id"`
	Description      *string           `json:"description"`
	ShortDescription *string           `json:"short_description"`
	HasVariations    *bool             `json:"has_variations"`
	BasePrice        *float64          `json:"base_price"`
	WeightGrams      *int64            `json:"weight_grams"`
	Status           *string           `json:"status"`
	Attributes       map[string]string `json:"attributes"`
	Price            *float64          `json:"price"`
	IsActive         *bool             `json:"is_active"`
}

// registerProductAdminRoutes is intentionally separate from routes(): the active
// migration task forbids edits to server.go, where the application router lives.
func (s *Server) registerProductAdminRoutes() {
	manage := "super_admin,agen,admin"
	s.mux.Handle("GET /api/v1/manage/products", chain(http.HandlerFunc(s.handleManageProducts), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("GET /api/v1/manage/products/{product}", chain(http.HandlerFunc(s.handleManageProduct), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("POST /api/v1/products", chain(http.HandlerFunc(s.handleProductCreate), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("PATCH /api/v1/products/{product}", chain(http.HandlerFunc(s.handleProductUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("DELETE /api/v1/products/{product}", chain(http.HandlerFunc(s.handleProductDelete), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen")))
	s.mux.Handle("DELETE /api/v1/products/{id}/permanent", chain(http.HandlerFunc(s.handleProductHardDelete), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen")))
	s.mux.Handle("POST /api/v1/products/{product}/variations", chain(http.HandlerFunc(s.handleVariationCreate), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("PATCH /api/v1/products/{product}/variations/{variation}", chain(http.HandlerFunc(s.handleVariationUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("DELETE /api/v1/products/{product}/variations/{variation}", chain(http.HandlerFunc(s.handleVariationDelete), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen")))
	s.mux.Handle("GET /api/v1/products/{product}/fees", chain(http.HandlerFunc(s.handleProductFeesGet), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,sales,sales-kurir-sub,korsal")))
	s.mux.Handle("PUT /api/v1/products/{product}/fees", chain(http.HandlerFunc(s.handleProductFeesSet), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen")))
	s.mux.Handle("GET /api/v1/products/{product}/variations/{variation}/fees", chain(http.HandlerFunc(s.handleVariationFeesGet), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,sales,sales-kurir-sub,korsal")))
	s.mux.Handle("PUT /api/v1/products/{product}/variations/{variation}/fees", chain(http.HandlerFunc(s.handleVariationFeesSet), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen")))
	s.mux.Handle("POST /api/v1/products/{product}/images", chain(http.HandlerFunc(s.handleProductImageCreate), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("PUT /api/v1/products/{product}/images/order", chain(http.HandlerFunc(s.handleProductImagesReorder), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("PATCH /api/v1/products/{product}/images/{image}", chain(http.HandlerFunc(s.handleProductImageUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
	s.mux.Handle("DELETE /api/v1/products/{product}/images/{image}", chain(http.HandlerFunc(s.handleProductImageDelete), RequireAuth, s.RequireAgentLinked, RequireRole(manage)))
}

func decodeProductAdmin(r *http.Request) (map[string]json.RawMessage, productAdminPayload, error) {
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		return nil, productAdminPayload{}, err
	}
	// StoreProductVariationRequest::attributes is validated as `array|min:1` with `attributes.*` strings —
	// a JSON LIST is legal: PHP's foreach then names each attribute by its numeric index. Normalise a list
	// to {"0": v, "1": v, ...} so the map-based flow below sees exactly what Laravel stores.
	if list, ok := raw["attributes"]; ok && len(list) > 0 && list[0] == '[' {
		var values []json.RawMessage
		if json.Unmarshal(list, &values) == nil {
			normalised := map[string]json.RawMessage{}
			for i, value := range values {
				normalised[strconv.Itoa(i)] = value
			}
			if encoded, err := json.Marshal(normalised); err == nil {
				raw["attributes"] = encoded
			}
		}
	}
	var p productAdminPayload
	b, err := json.Marshal(raw)
	if err != nil {
		return nil, p, err
	}
	err = json.Unmarshal(b, &p)
	if err != nil {
		var typeErr *json.UnmarshalTypeError
		if errors.As(err, &typeErr) {
			field := strings.Split(typeErr.Field, ".")[0]
			field = map[string]string{
				"Name": "name", "Slug": "slug", "SKU": "sku", "CategoryID": "category_id",
				"Description": "description", "ShortDescription": "short_description",
				"HasVariations": "has_variations", "BasePrice": "base_price", "WeightGrams": "weight_grams",
				"Status": "status", "Attributes": "attributes", "Price": "price", "IsActive": "is_active",
			}[field]
			if field == "" {
				field = "body"
			}
			message := msg(r.Context(), "system.field_invalid")
			switch field {
			case "category_id", "weight_grams":
				return raw, p, httpx.Validation(map[string][]string{field: notInteger(r.Context(), field)})
			case "base_price", "price":
				return raw, p, httpx.Validation(map[string][]string{field: {msg(r.Context(), "system.field_invalid")}})
			default:
				return raw, p, httpx.Validation(map[string][]string{field: {message}})
			}
		}
		return raw, p, httpx.Validation(map[string][]string{"body": {"The body must be valid JSON."}})
	}
	return raw, p, err
}

func productAdminInput(raw map[string]json.RawMessage, p productAdminPayload) catalog.ProductAdminInput {
	_, setCategory := raw["category_id"]
	_, setDescription := raw["description"]
	_, setShortDescription := raw["short_description"]
	_, setBasePrice := raw["base_price"]
	_, setWeightGrams := raw["weight_grams"]
	return catalog.ProductAdminInput{
		Name: p.Name, Slug: p.Slug, SKU: p.SKU, CategoryID: p.CategoryID,
		SetCategory: setCategory, Description: p.Description, SetDescription: setDescription,
		ShortDescription: p.ShortDescription, SetShortDescription: setShortDescription,
		HasVariations: p.HasVariations, BasePrice: p.BasePrice, SetBasePrice: setBasePrice,
		WeightGrams: p.WeightGrams, SetWeightGrams: setWeightGrams, Status: p.Status,
	}
}

func (s *Server) handleManageProducts(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if _, ok := requireUser(w, r); !ok {
		return
	}
	status, search := httpx.QueryString(r, "status"), httpx.QueryString(r, "search")
	fields := map[string][]string{}
	if status != "" && status != "all" && status != "active" && status != "draft" && status != "inactive" {
		fields["status"] = invalidChoice(ctx, "status")
	}
	if utf8.RuneCountInString(search) > 100 {
		fields["search"] = maxString(ctx, "search", 100)
	}
	perPage := r.URL.Query().Get("per_page")
	if perPage != "" {
		n, err := strconv.Atoi(perPage)
		if err != nil || n < 1 {
			fields["per_page"] = minNumeric(ctx, "per_page", 1)
		} else if n > 100 {
			fields["per_page"] = maxNumeric(ctx, "per_page", 100)
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	categoryID, err := optionalPositiveInt(r, "category_id")
	if err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"category_id": notInteger(ctx, "category_id")}))
		return
	}
	list, err := catalog.ProductAdminList(ctx, s.database, catalog.AdminListQuery{
		Status: status, Search: search,
		CategoryID: categoryID, Page: httpx.QueryInt(r, "page", 1, 1),
		PerPage: httpx.QueryInt(r, "per_page", 25, 1),
	})
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	meta := map[string]any{"current_page": list.Page, "last_page": list.LastPage, "total": list.Total, "status_filter": status}
	if status == "" {
		meta["status_filter"] = "all"
	}
	// attachAgentAvailability: the viewer's own agent availability + sellable stock per target.
	if u := UserFrom(ctx); u != nil && u.AgentID != nil {
		for _, item := range list.Items {
			variations, _ := item["variations"].([]map[string]any)
			catalog.AttachSellableRow(ctx, s.database, *u.AgentID, item, variations)
		}
	}
	httpx.OK(w, list.Items, "OK", meta)
}

func (s *Server) handleManageProduct(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	row, err := catalog.ProductAdminByID(r.Context(), s.database, id)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	if u := UserFrom(r.Context()); u != nil && u.AgentID != nil {
		variations, _ := row["variations"].([]map[string]any)
		catalog.AttachSellableRow(r.Context(), s.database, *u.AgentID, row, variations)
	}
	httpx.OK(w, row, "OK", nil)
}

func (s *Server) handleProductCreate(w http.ResponseWriter, r *http.Request) {
	u, ok := requireUser(w, r)
	if !ok {
		return
	}
	raw, p, err := decodeProductAdmin(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	fields := validateProductAdmin(r, raw, p, true, false)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if p.Description != nil {
		p.Description = sanitizedProductHTML(p.Description)
	}
	in := productAdminInput(raw, p)
	in.ActorRole = u.Role
	row, err := catalog.CreateProductAdmin(r.Context(), s.database, u.ID, in)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.Created(w, productMutationResource(row), "Created")
}

func (s *Server) handleProductUpdate(w http.ResponseWriter, r *http.Request) {
	u, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	raw, p, err := decodeProductAdmin(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	current, err := catalog.ProductAdminByID(r.Context(), s.database, id)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	hasVariations, _ := current["has_variations"].(bool)
	fields := validateProductAdmin(r, raw, p, false, hasVariations)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if p.Description != nil {
		p.Description = sanitizedProductHTML(p.Description)
	}
	in := productAdminInput(raw, p)
	in.ActorID = u.ID
	in.ActorRole = u.Role
	row, err := catalog.UpdateProductAdmin(r.Context(), s.database, id, in)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, productMutationResource(row), "OK", nil)
}

func (s *Server) handleProductDelete(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	u, ok := requireUser(w, r)
	if !ok {
		return
	}
	var exists int
	if err := s.database.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM products WHERE id = ?", id).Scan(&exists); err != nil {
		writeError(w, r, err)
		return
	}
	if exists == 0 {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err := catalog.SoftDeleteProductAdmin(r.Context(), s.database, id, u.ID, u.Role); err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "product.deleted"), nil)
}

func (s *Server) handleProductHardDelete(w http.ResponseWriter, r *http.Request) {
	u, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "id")
	if !ok {
		return
	}
	var p struct {
		Confirm json.RawMessage `json:"confirm"`
	}
	if err := httpx.DecodeJSON(r, &p); err != nil {
		writeError(w, r, err)
		return
	}
	accepted := string(p.Confirm) == "true" || string(p.Confirm) == "1" || string(p.Confirm) == `"1"` ||
		string(p.Confirm) == `"yes"` || string(p.Confirm) == `"on"`
	if !accepted {
		// The `accepted` rule fails for BOTH missing and false with the SAME sentence.
		writeError(w, r, httpx.Validation(map[string][]string{"confirm": {i18n.ValidationMessage(LocaleFrom(r.Context()), "accepted", "confirm")}}))
		return
	}
	if err := catalog.HardDeleteProductAdmin(r.Context(), s.database, id, u.ID, u.Role); err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "product.deleted"), nil)
}

func (s *Server) handleVariationCreate(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	// Route-model binding for {product} happens BEFORE FormRequest validation in Laravel — a missing
	// product answers 404 even with an invalid body.
	var hasVariations bool
	var productName string
	// COUNT(*) over an empty set has no row to drive has_variations; select through scalar subqueries so a
	// missing product still yields one row with COALESCEd placeholders (then the 404 binding fires).
	var exists int
	err := s.database.QueryRowContext(r.Context(), `SELECT
			(SELECT COUNT(*) FROM products WHERE id = ? AND deleted_at IS NULL),
			COALESCE((SELECT has_variations FROM products WHERE id = ? AND deleted_at IS NULL), 0),
			COALESCE((SELECT name FROM products WHERE id = ? AND deleted_at IS NULL), '')`,
		productID, productID, productID).
		Scan(&exists, &hasVariations, &productName)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	if exists == 0 {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	raw, p, err := decodeProductAdmin(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	fields := validateVariationAdmin(r, raw, p, true)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	if !hasVariations {
		// ProductVariationService::create — the service-level refusal with the product name interpolated.
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
			Message: message(r.Context(), "product.variation_not_enabled", "name", productName)})
		return
	}
	row, err := catalog.CreateProductVariationAdmin(r.Context(), s.database, productID, catalog.VariationAdminInput{
		SKU: p.SKU, Price: p.Price, WeightGrams: p.WeightGrams, IsActive: p.IsActive, Attributes: p.Attributes,
		AttributeOrder: objectKeyOrder(raw["attributes"]), SetAttrs: true,
	})
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	delete(row, "attributes")
	httpx.Created(w, row, "Created")
}

func (s *Server) handleVariationUpdate(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	variationID, ok := pathID(w, r, "variation")
	if !ok {
		return
	}
	raw, p, err := decodeProductAdmin(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	fields := validateVariationAdmin(r, raw, p, false)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	_, setAttrs := raw["attributes"]
	row, err := catalog.UpdateProductVariationAdmin(r.Context(), s.database, productID, variationID, catalog.VariationAdminInput{
		SKU: p.SKU, Price: p.Price, WeightGrams: p.WeightGrams, IsActive: p.IsActive, Attributes: p.Attributes,
		AttributeOrder: objectKeyOrder(raw["attributes"]), SetAttrs: setAttrs,
	})
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

func (s *Server) handleVariationDelete(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	variationID, ok := pathID(w, r, "variation")
	if !ok {
		return
	}
	if err := catalog.SoftDeleteProductVariationAdmin(r.Context(), s.database, productID, variationID); err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "product.variation_deleted"), nil)
}

func (s *Server) handleProductImageCreate(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	form, err := readPaymentForm(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	upload := form.files["image"]
	if upload == nil {
		writeError(w, r, httpx.Validation(map[string][]string{"image": requiredField(r.Context(), "image")}))
		return
	}
	ext, err := media.Validate("product_image", *upload)
	if err != nil {
		var problem *media.Problem
		if !errors.As(err, &problem) || problem.Message != media.MsgDimensions {
			writeMediaUploadError(w, r, "image", err)
			return
		}
		ext = strings.TrimPrefix(filepath.Ext(upload.Filename), ".")
		if ext == "" {
			exts, _ := mime.ExtensionsByType(http.DetectContentType(upload.Content))
			if len(exts) == 0 {
				writeMediaUploadError(w, r, "image", err)
				return
			}
			ext = strings.TrimPrefix(exts[0], ".")
		}
	}
	variationID, err := optionalFormID(form, "product_variation_id")
	if err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"product_variation_id": notInteger(r.Context(), "product_variation_id")}))
		return
	}
	if _, present := form.fields["product_variation_id"]; present && variationID < 1 {
		writeError(w, r, httpx.Validation(map[string][]string{"product_variation_id": minNumeric(r.Context(), "product_variation_id", 1)}))
		return
	}
	primary, err := optionalFormBool(form, "is_primary", false)
	if err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"is_primary": {msg(r.Context(), "system.field_invalid")}}))
		return
	}
	sortOrder, err := optionalFormID(form, "sort_order")
	if err != nil || sortOrder < 0 {
		writeError(w, r, httpx.Validation(map[string][]string{"sort_order": minNumeric(r.Context(), "sort_order", 0)}))
		return
	}
	random := make([]byte, 16)
	if _, err := rand.Read(random); err != nil {
		writeError(w, r, err)
		return
	}
	path := "products/" + hex.EncodeToString(random) + "." + ext
	absolute := filepath.Join(media.Root, filepath.FromSlash(path))
	if err := os.MkdirAll(filepath.Dir(absolute), 0o755); err != nil {
		writeError(w, r, err)
		return
	}
	if err := os.WriteFile(absolute, upload.Content, 0o644); err != nil {
		writeError(w, r, err)
		return
	}
	image, err := catalog.CreateProductImageAdmin(r.Context(), s.database, productID, optionalIntPtr(variationID), path, primary, sortOrder)
	if err != nil {
		_ = os.Remove(absolute)
		writeProductAdminError(w, r, err)
		return
	}
	httpx.Created(w, image, "Created")
}

func (s *Server) handleProductImageUpdate(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	imageID, ok := pathID(w, r, "image")
	if !ok {
		return
	}
	var p struct {
		Primary *bool `json:"is_primary"`
	}
	if err := httpx.DecodeJSON(r, &p); err != nil {
		writeError(w, r, err)
		return
	}
	if p.Primary == nil {
		writeError(w, r, httpx.Validation(map[string][]string{"is_primary": requiredField(r.Context(), "is_primary")}))
		return
	}
	image, err := catalog.SetProductImagePrimaryAdmin(r.Context(), s.database, productID, imageID, *p.Primary)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, image, "OK", nil)
}

func (s *Server) handleProductImageDelete(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	imageID, ok := pathID(w, r, "image")
	if !ok {
		return
	}
	if _, err := catalog.DeleteProductImageAdmin(r.Context(), s.database, productID, imageID); err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "product.image_deleted"), nil)
}

func (s *Server) handleProductImagesReorder(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	var p struct {
		VariationID *int64  `json:"product_variation_id"`
		IDs         []int64 `json:"ids"`
	}
	if err := httpx.DecodeJSON(r, &p); err != nil {
		writeError(w, r, err)
		return
	}
	if len(p.IDs) == 0 || len(p.IDs) > 100 {
		writeError(w, r, httpx.Validation(map[string][]string{"ids": requiredField(r.Context(), "ids")}))
		return
	}
	imageList, err := catalog.ReorderProductImagesAdmin(r.Context(), s.database, productID, p.VariationID, p.IDs)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, imageList, "OK", nil)
}

func (s *Server) handleProductFeesGet(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	fees, err := catalog.GetProductFees(r.Context(), s.database, productID, nil)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, visibleFees(UserFrom(r.Context()).Role, fees), "OK", nil)
}

func (s *Server) handleVariationFeesGet(w http.ResponseWriter, r *http.Request) {
	_, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	variationID, ok := pathID(w, r, "variation")
	if !ok {
		return
	}
	// Laravel binds {variation} by ID alone — a mismatched product/variation pair still resolves
	// (FeeController::showForVariation never consults the owning product).
	fees, err := catalog.GetProductFees(r.Context(), s.database, 0, &variationID)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, visibleFees(UserFrom(r.Context()).Role, fees), "OK", nil)
}

func (s *Server) handleProductFeesSet(w http.ResponseWriter, r *http.Request) {
	productID, ok := pathID(w, r, "product")
	if !ok {
		return
	}
	fees, ok := decodeFees(w, r)
	if !ok {
		return
	}
	result, err := catalog.SetProductFees(r.Context(), s.database, productID, nil, fees)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, visibleFees(UserFrom(r.Context()).Role, result), msg(r.Context(), "fee.updated_product"), nil)
}

func (s *Server) handleVariationFeesSet(w http.ResponseWriter, r *http.Request) {
	variationID, ok := pathID(w, r, "variation")
	if !ok {
		return
	}
	fees, ok := decodeFees(w, r)
	if !ok {
		return
	}
	// Same binding-only rule as showForVariation: {variation} resolves by ID alone.
	result, err := catalog.SetProductFees(r.Context(), s.database, 0, &variationID, fees)
	if err != nil {
		writeProductAdminError(w, r, err)
		return
	}
	httpx.OK(w, visibleFees(UserFrom(r.Context()).Role, result), msg(r.Context(), "fee.updated_variation"), nil)
}

func decodeFees(w http.ResponseWriter, r *http.Request) (map[string]float64, bool) {
	var raw map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return nil, false
	}
	fields := map[string][]string{}
	fees := map[string]float64{}
	for _, field := range []string{"agent_fee", "sales_fee", "courier_fee"} {
		var value float64
		err := json.Unmarshal(raw[field], &value)
		if err != nil {
			var text string
			if json.Unmarshal(raw[field], &text) == nil {
				value, err = strconv.ParseFloat(strings.TrimSpace(text), 64)
			}
		}
		if err != nil {
			fields[field] = requiredField(r.Context(), field)
			continue
		}
		if math.IsNaN(value) || math.IsInf(value, 0) || value < 0 {
			fields[field] = minNumeric(r.Context(), field, 0)
			continue
		}
		role := strings.TrimSuffix(field, "_fee")
		fees[role] = value
	}
	if len(fields) != 0 {
		writeError(w, r, httpx.Validation(fields))
		return nil, false
	}
	return fees, true
}

func validateProductAdmin(r *http.Request, raw map[string]json.RawMessage, p productAdminPayload, create, hasVariations bool) map[string][]string {
	ctx := r.Context()
	fields := map[string][]string{}
	if create && p.Name == nil || p.Name != nil && strings.TrimSpace(*p.Name) == "" {
		fields["name"] = requiredField(ctx, "name")
	} else if p.Name != nil && utf8.RuneCountInString(*p.Name) > 150 {
		fields["name"] = maxString(ctx, "name", 150)
	}
	if !create {
		for _, field := range []string{"name", "slug", "sku", "status"} {
			if _, present := raw[field]; present && productFieldIsNil(field, p) {
				fields[field] = requiredField(ctx, field)
			}
		}
	}
	// required_if:has_variations,false / prohibited_if:has_variations,true — Laravel's dependent rules
	// evaluate ONLY when the condition field is PRESENT (Arr::has). An absent has_variations skips both,
	// which is why an empty store payload reports ONLY name + has_variations.
	// On UPDATE the field is not a rule at all (the controller's only() never forwards it).
	if create && p.HasVariations == nil {
		fields["has_variations"] = requiredField(ctx, "has_variations")
	}
	conditionPresent := create
	if rawHas, present := raw["has_variations"]; present {
		conditionPresent = true
		_ = rawHas
	}
	if conditionPresent {
		if p.HasVariations != nil && !*p.HasVariations {
			for _, field := range []string{"sku", "base_price", "weight_grams"} {
				if _, present := raw[field]; !present || productFieldIsNil(field, p) {
					fields[field] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required_if", field,
						"other", i18n.AttributeName(LocaleFrom(ctx), "has_variations"), "value", "false")}
				}
			}
		}
		if (p.HasVariations != nil && *p.HasVariations) || (!create && hasVariations) {
			for _, field := range []string{"sku", "base_price", "weight_grams"} {
				if _, present := raw[field]; present && !productFieldIsNil(field, p) {
					fields[field] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "prohibited_if", field,
						"other", i18n.AttributeName(LocaleFrom(ctx), "has_variations"), "value", "true")}
				}
			}
		}
	}
	if p.Slug != nil && utf8.RuneCountInString(*p.Slug) > 180 {
		fields["slug"] = maxString(ctx, "slug", 180)
	}
	if p.SKU != nil && strings.TrimSpace(*p.SKU) == "" && !create {
		fields["sku"] = requiredField(ctx, "sku")
	} else if p.SKU != nil && utf8.RuneCountInString(*p.SKU) > 60 {
		fields["sku"] = maxString(ctx, "sku", 60)
	}
	if p.ShortDescription != nil && utf8.RuneCountInString(*p.ShortDescription) > 300 {
		fields["short_description"] = maxString(ctx, "short_description", 300)
	}
	if p.Status != nil && *p.Status != "draft" && *p.Status != "active" && *p.Status != "inactive" {
		fields["status"] = invalidChoice(ctx, "status")
	}
	if p.BasePrice != nil && (math.IsNaN(*p.BasePrice) || math.IsInf(*p.BasePrice, 0) || *p.BasePrice < 0) {
		fields["base_price"] = minNumeric(ctx, "base_price", 0)
	}
	if p.WeightGrams != nil && *p.WeightGrams < 1 {
		fields["weight_grams"] = minNumeric(ctx, "weight_grams", 1)
	}
	if _, present := raw["category_id"]; present && len(raw["category_id"]) > 0 && string(raw["category_id"]) != "null" {
		if p.CategoryID == nil || *p.CategoryID < 1 {
			fields["category_id"] = notInteger(ctx, "category_id")
		}
	}
	return fields
}

func validateVariationAdmin(r *http.Request, raw map[string]json.RawMessage, p productAdminPayload, create bool) map[string][]string {
	ctx := r.Context()
	fields := map[string][]string{}
	for _, field := range []string{"sku", "price", "weight_grams", "is_active", "attributes"} {
		if _, present := raw[field]; present && string(raw[field]) == "null" {
			fields[field] = requiredField(ctx, field)
		}
	}
	if create && (p.SKU == nil || strings.TrimSpace(*p.SKU) == "") {
		fields["sku"] = requiredField(ctx, "sku")
	}
	if p.SKU != nil && (strings.TrimSpace(*p.SKU) == "" || utf8.RuneCountInString(*p.SKU) > 60) {
		if strings.TrimSpace(*p.SKU) == "" {
			fields["sku"] = requiredField(ctx, "sku")
		} else {
			fields["sku"] = maxString(ctx, "sku", 60)
		}
	}
	if create && p.Price == nil {
		fields["price"] = requiredField(ctx, "price")
	}
	if p.Price != nil && (math.IsNaN(*p.Price) || math.IsInf(*p.Price, 0) || *p.Price < 0) {
		fields["price"] = minNumeric(ctx, "price", 0)
	}
	if create && p.WeightGrams == nil {
		fields["weight_grams"] = requiredField(ctx, "weight_grams")
	}
	if p.WeightGrams != nil && *p.WeightGrams < 1 {
		fields["weight_grams"] = minNumeric(ctx, "weight_grams", 1)
	}
	if create && len(p.Attributes) == 0 {
		fields["attributes"] = requiredField(ctx, "attributes")
	}
	for name, value := range p.Attributes {
		if strings.TrimSpace(name) == "" || strings.TrimSpace(value) == "" || utf8.RuneCountInString(name) > 100 || utf8.RuneCountInString(value) > 100 {
			fields["attributes"] = []string{msg(ctx, "system.field_invalid")}
			break
		}
	}
	return fields
}

func productFieldIsNil(field string, p productAdminPayload) bool {
	switch field {
	case "name":
		return p.Name == nil
	case "slug":
		return p.Slug == nil
	case "sku":
		return p.SKU == nil
	case "status":
		return p.Status == nil
	default:
		return false
	}
}

func objectKeyOrder(raw json.RawMessage) []string {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	token, err := decoder.Token()
	if err != nil || token != json.Delim('{') {
		return nil
	}
	keys := []string{}
	for decoder.More() {
		token, err := decoder.Token()
		if err != nil {
			return nil
		}
		key, ok := token.(string)
		if !ok {
			return nil
		}
		keys = append(keys, key)
		var value json.RawMessage
		if err := decoder.Decode(&value); err != nil {
			return nil
		}
	}
	return keys
}

func productMutationResource(product map[string]any) map[string]any {
	out := make(map[string]any, len(product))
	for key, value := range product {
		if key != "category" && key != "images" && key != "variations" {
			out[key] = value
		}
	}
	return out
}

func sanitizedProductHTML(value *string) *string {
	safe := SanitizeLaravelHTML(*value)
	return &safe
}

var (
	// HTMLPurifier writes style values with a TRAILING SEMICOLON; bluemonday doesn't.
	htmlStyleTail = regexp.MustCompile(`style="([^"]*?)"`)
	// HTMLPurifier's TargetBlank emits rel="noreferrer noopener" in that exact order.
	htmlRelOrder = regexp.MustCompile(`rel="([^"]*)"`)
	// HTMLPurifier serialises void elements XHTML-style (`<img ... />`, `<br />`); bluemonday emits `<img ...>`.
	htmlVoidTag = regexp.MustCompile(`<(img|br|hr|input)((?:[^>"']|"[^"]*")*)>`)
)

// SanitizeLaravelHTML reproduces HtmlSanitizerService byte-for-byte: the bluemonday allowlist (the same
// CKEditor vocabulary) plus the three serialisation differences observed against the running reference
// (golden fixtures in tools/parity probes).
func SanitizeLaravelHTML(input string) string {
	out := sanitizeAnchorHrefs(input)
	out = cms.SanitizeHTML(out)
	// HTMLPurifier keeps no space after the colon inside style values; bluemonday normalises to "a: b".
	out = htmlStyleTail.ReplaceAllString(out, `style="$1;"`)
	out = strings.ReplaceAll(out, `: `, `:`)
	// TargetBlank semantics (HTMLPurifier): EVERY absolute http(s) link gets target="_blank" + rel
	// ("noreferrer noopener"); relative links and mailto keep NO rel; a dropped href keeps a bare <a>.
	out = htmlAnchorRewrite.ReplaceAllStringFunc(out, func(tag string) string {
		attrs := parseHTMLAttrs(tag)
		inner := htmlInner(tag)
		href, hasHref := attrs["href"]
		if !hasHref || href == "" || href == "#pc-bare-anchor" {
			// Either bluemonday dropped the href, or the pre-strip marked a scheme the reference
			// reduces to a bare <a> (javascript: etc.) — HTMLPurifier keeps the element itself.
			return "<a>" + inner + "</a>"
		}
		external := strings.HasPrefix(href, "http://") || strings.HasPrefix(href, "https://")
		return rebuildAnchor(attrs, inner, external)
	})
	out = htmlVoidTag.ReplaceAllString(out, "<$1$2 />")
	return out
}

// sanitizeAnchorHrefs pre-strips the href attribute bluemonday would reject by DELETING THE WHOLE
// ELEMENT (observed: `<a href="javascript:...">x</a>` collapses to `x`, while HTMLPurifier keeps
// `<a>x</a>`). An href that is neither an allowed scheme nor relative loses ONLY the attribute here,
// so bluemonday then keeps the bare <a> exactly like the purifier.
func sanitizeAnchorHrefs(input string) string {
	return htmlAnchorTag.ReplaceAllStringFunc(input, func(tag string) string {
		attrs := parseHTMLAttrs(tag)
		href, hasHref := attrs["href"]
		if !hasHref {
			return tag
		}
		if strings.HasPrefix(href, "http://") || strings.HasPrefix(href, "https://") ||
			strings.HasPrefix(href, "mailto:") || strings.HasPrefix(href, "/") || strings.HasPrefix(href, "#") {
			return tag
		}
		cleaned := htmlHrefAttr.ReplaceAllString(tag, ` href="#pc-bare-anchor"`)
		return cleaned
	})
}

// parseHTMLAttrs extracts name="value" pairs from a full tag.
func parseHTMLAttrs(tag string) map[string]string {
	out := map[string]string{}
	for _, m := range htmlAttrExtract.FindAllStringSubmatch(tag, -1) {
		out[m[1]] = m[2]
	}
	return out
}

// rebuildAnchor emits the attributes in HTMLPurifier's serialisation order (href, title, target, rel);
// a non-external link keeps neither target nor rel.
func rebuildAnchor(attrs map[string]string, inner string, external bool) string {
	parts := []string{"a"}
	if href := attrs["href"]; href != "" {
		parts = append(parts, `href="`+href+`"`)
	}
	if title := attrs["title"]; title != "" {
		parts = append(parts, `title="`+title+`"`)
	}
	if external {
		parts = append(parts, `target="_blank"`, `rel="noreferrer noopener"`)
	}
	return "<" + strings.Join(parts, " ") + ">" + inner + "</a>"
}

// htmlInner extracts the text between the opening <a ...> and its closing </a>.
func htmlInner(tag string) string {
	open := strings.Index(tag, ">")
	close := strings.LastIndex(tag, "</a>")
	if open < 0 || close <= open {
		return ""
	}
	return tag[open+1 : close]
}

var (
	htmlAnchorRewrite = regexp.MustCompile(`(?s)<a\b[^>]*>.*?</a>`)
	htmlAnchorTag     = regexp.MustCompile(`(?s)<a\b[^>]*>`)
	htmlHrefAttr      = regexp.MustCompile(`\s*href="[^"]*"`)
	htmlAttrExtract   = regexp.MustCompile(`([a-z-]+)="([^"]*)"`)
)

func visibleFees(role string, amounts map[string]float64) map[string]float64 {
	out := map[string]float64{"sales_fee": amounts["sales"]}
	if role == "super_admin" || role == "agen" {
		out["agent_fee"] = amounts["agent"]
		out["courier_fee"] = amounts["courier"]
	}
	return out
}

func optionalFormID(form *paymentForm, key string) (int64, error) {
	value, present := form.text(key)
	if !present {
		return 0, nil
	}
	id, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		return 0, err
	}
	return id, nil
}

func optionalFormBool(form *paymentForm, key string, fallback bool) (bool, error) {
	value, present := form.fields[key]
	if !present {
		return fallback, nil
	}
	parsed, ok := laravelBool(value)
	if !ok {
		return false, errors.New("invalid boolean")
	}
	return parsed, nil
}

func optionalIntPtr(id int64) *int64 {
	if id == 0 {
		return nil
	}
	return &id
}

func writeMediaUploadError(w http.ResponseWriter, r *http.Request, field string, err error) {
	var problem *media.Problem
	if errors.As(err, &problem) {
		message := msg(r.Context(), problem.Message)
		writeError(w, r, httpx.Validation(map[string][]string{field: {message}}))
		return
	}
	writeError(w, r, err)
}

func optionalPositiveInt(r *http.Request, key string) (int64, error) {
	value := httpx.QueryString(r, key)
	if value == "" {
		return 0, nil
	}
	n, err := strconv.ParseInt(value, 10, 64)
	if err != nil || n < 1 {
		return 0, errors.New("invalid id")
	}
	return n, nil
}

func writeProductAdminError(w http.ResponseWriter, r *http.Request, err error) {
	// ProductHardDeleteService's guarded 409: message + errors {references: {table: count}}.
	var blockers *catalog.ProductDeleteBlockers
	if errors.As(err, &blockers) {
		writeError(w, r, &httpx.Error{Status: http.StatusConflict, Message: blockers.Error(),
			ErrorsAny: map[string]any{"references": blockers.Blockers}})
		return
	}
	switch {
	case errors.Is(err, catalog.ErrProductAdminNotFound), errors.Is(err, catalog.ErrVariationNotFound):
		writeError(w, r, notFoundError(r.Context()))
	case errors.Is(err, catalog.ErrProductImageNotFound):
		writeError(w, r, notFoundError(r.Context()))
	case errors.Is(err, catalog.ErrProductImageVariation):
		writeError(w, r, httpx.Validation(map[string][]string{"product_variation_id": {"Varian bukan milik produk ini."}}))
	case errors.Is(err, catalog.ErrProductImageOrder):
		writeError(w, r, httpx.Validation(map[string][]string{"ids": {"Daftar gambar harus persis sama dengan seluruh gambar galeri ini."}}))
	case errors.Is(err, catalog.ErrProductSKU):
		// App\Rules\GlobalSku fails with its own literal, not the validator's unique sentence.
		writeError(w, r, httpx.Validation(map[string][]string{"sku": {"SKU sudah digunakan pada katalog."}}))
	case errors.Is(err, catalog.ErrProductSlug):
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
	case errors.Is(err, catalog.ErrProductCategory):
		writeError(w, r, httpx.Validation(map[string][]string{"category_id": unknownChoice(r.Context(), "category_id")}))
	case errors.Is(err, catalog.ErrProductMode), errors.Is(err, catalog.ErrVariationConflict):
		writeError(w, r, httpx.Validation(map[string][]string{"attributes": {msg(r.Context(), "system.field_invalid")}}))

	default:
		writeError(w, r, err)
	}
}
