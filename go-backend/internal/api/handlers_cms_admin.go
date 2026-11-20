package api

import (
	"context"
	"encoding/json"
	"errors"
	"math"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/cms"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// registerCMSProfileRoutes is called by the Domain E route aggregator. The
// migration owner keeps server.go and generated route inventory changes separate.
func (s *Server) registerCMSProfileRoutes() {
	s.mux.HandleFunc("GET /api/v1/referral/{code}", s.handleReferralPreview)
	s.mux.Handle("PATCH /api/v1/profile", chain(http.HandlerFunc(s.handleProfileUpdate), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("GET /api/v1/profile/email/verification", chain(http.HandlerFunc(s.handleEmailVerificationState), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("GET /api/v1/profile/whatsapp", chain(http.HandlerFunc(s.handleProfileWhatsAppState), RequireAuth))
	s.mux.Handle("PATCH /api/v1/profile/referral-code", chain(http.HandlerFunc(s.handleProfileReferralCodeUpdate),
		RequireAuth, s.RequireAgentLinked, RequireRole("agen,korsal,sales,sales-kurir-sub")))
	s.mux.Handle("POST /api/v1/profile/referral-code/regenerate", chain(http.HandlerFunc(s.handleProfileReferralCodeRegenerate),
		RequireAuth, s.RequireAgentLinked, RequireRole("agen,korsal,sales,sales-kurir-sub")))
	s.mux.Handle("DELETE /api/v1/profile/referral-code", chain(http.HandlerFunc(s.handleProfileReferralCodeDelete),
		RequireAuth, s.RequireAgentLinked, RequireRole("agen,korsal,sales,sales-kurir-sub")))

	s.mux.Handle("GET /api/v1/cms/homepage-blocks", chain(http.HandlerFunc(s.handleCMSBlockIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/homepage-blocks", chain(http.HandlerFunc(s.handleCMSBlockStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/homepage-blocks/{block}", chain(http.HandlerFunc(s.handleCMSBlockUpdateMethodOverride), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/homepage-blocks/reorder", chain(http.HandlerFunc(s.handleCMSBlockReorder), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/homepage-blocks/{block}", chain(http.HandlerFunc(s.handleCMSBlockUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/homepage-blocks/{block}/toggle", chain(http.HandlerFunc(s.handleCMSBlockToggle), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/cms/homepage-blocks/{block}", chain(http.HandlerFunc(s.handleCMSBlockDestroy), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))

	s.mux.Handle("GET /api/v1/cms/articles", chain(http.HandlerFunc(s.handleCMSArticleIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/articles", chain(http.HandlerFunc(s.handleCMSArticleStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/articles/{article}", chain(http.HandlerFunc(s.handleCMSArticleUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/cms/articles/{article}", chain(http.HandlerFunc(s.handleCMSArticleDestroy), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))

	s.mux.Handle("GET /api/v1/cms/pages", chain(http.HandlerFunc(s.handleCMSPageIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/pages", chain(http.HandlerFunc(s.handleCMSPageStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/pages/{page}", chain(http.HandlerFunc(s.handleCMSPageUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/cms/pages/{page}", chain(http.HandlerFunc(s.handleCMSPageDestroy), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
}

func (s *Server) requireCMSAdmin(w http.ResponseWriter, r *http.Request) bool {
	u := UserFrom(r.Context())
	if u == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return false
	}
	if !u.IsRole(auth.RoleSuperAdmin) {
		writeError(w, r, forbiddenError(r.Context()))
		return false
	}
	return true
}

func (s *Server) handleCMSArticleIndex(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	ctx := r.Context()
	typ, status := httpx.QueryString(r, "type"), httpx.QueryString(r, "status")
	if typ != "" && typ != "article" && typ != "news" {
		writeError(w, r, httpx.Validation(map[string][]string{"type": invalidChoice(ctx, "type")}))
		return
	}
	if status != "" && status != "draft" && status != "published" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": invalidChoice(ctx, "status")}))
		return
	}
	page := httpx.QueryInt(r, "page", 1, 1)
	perPage := httpx.QueryInt(r, "per_page", 15, 1)
	result, err := cms.AdminArticles(ctx, s.database, typ, status, page, perPage)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Items, "OK", httpx.NewMeta(result.Page, result.LastPage, result.Total))
}

func (s *Server) handleCMSArticleStore(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	u, _ := requireUser(w, r)
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	fields, translations := validateCMSArticle(r.Context(), s, raw, true, 0)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	input := cms.ArticleInput{}
	if err := remarshalRaw(raw, &input); err != nil {
		writeError(w, r, err)
		return
	}
	list := make([]cms.ArticleTranslation, 0, len(translations))
	for _, entry := range translations {
		translation := cms.ArticleTranslation{}
		if entry.LanguageID != nil {
			translation.LanguageID = *entry.LanguageID
		}
		if entry.Title != nil {
			translation.Title = *entry.Title
		}
		if entry.Excerpt != nil {
			translation.Excerpt = entry.Excerpt
		}
		if entry.Body != nil {
			translation.Body = *entry.Body
		}
		list = append(list, translation)
	}
	input.Translations = &list
	result, err := cms.CreateArticle(r.Context(), s.database, input, u.ID)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func (s *Server) handleCMSArticleUpdate(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "article")
	if !ok {
		return
	}
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	fields, translations := validateCMSArticle(r.Context(), s, raw, false, id)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	input := cms.ArticleInput{}
	if err := remarshalRaw(raw, &input); err != nil {
		writeError(w, r, err)
		return
	}
	if _, present := raw["translations"]; present {
		list := make([]cms.ArticleTranslation, 0, len(translations))
		for _, entry := range translations {
			translation := cms.ArticleTranslation{}
			if entry.LanguageID != nil {
				translation.LanguageID = *entry.LanguageID
			}
			if entry.Title != nil {
				translation.Title = *entry.Title
			}
			if entry.Excerpt != nil {
				translation.Excerpt = entry.Excerpt
			}
			if entry.Body != nil {
				translation.Body = *entry.Body
			}
			list = append(list, translation)
		}
		input.Translations = &list
	}
	result, err := cms.UpdateArticle(r.Context(), s.database, id, input)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSArticleDestroy(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "article")
	if !ok {
		return
	}
	err := cms.DeleteArticle(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "cms.article_deleted"), nil)
}

func (s *Server) handleCMSPageIndex(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	ctx := r.Context()
	status := httpx.QueryString(r, "status")
	if status != "" && status != "draft" && status != "published" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": invalidChoice(ctx, "status")}))
		return
	}
	result, err := cms.AdminPages(ctx, s.database, status,
		httpx.QueryInt(r, "page", 1, 1), httpx.QueryInt(r, "per_page", 15, 1))
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Items, "OK", httpx.NewMeta(result.Page, result.LastPage, result.Total))
}

func (s *Server) handleCMSPageStore(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	fields, translations := validateCMSPage(r.Context(), s, raw, true, 0)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	input := cms.PageInput{}
	if err := remarshalRaw(raw, &input); err != nil {
		writeError(w, r, err)
		return
	}
	list := make([]cms.PageTranslation, 0, len(translations))
	for _, entry := range translations {
		translation := cms.PageTranslation{}
		if entry.LanguageID != nil {
			translation.LanguageID = *entry.LanguageID
		}
		if entry.Title != nil {
			translation.Title = *entry.Title
		}
		translation.Body = entry.Body
		translation.SEOTitle = entry.SEOTitle
		translation.SEODescription = entry.SEODescription
		list = append(list, translation)
	}
	input.Translations = &list
	result, err := cms.CreatePage(r.Context(), s.database, input)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func (s *Server) handleCMSPageUpdate(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "page")
	if !ok {
		return
	}
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	fields, translations := validateCMSPage(r.Context(), s, raw, false, id)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	input := cms.PageInput{}
	if err := remarshalRaw(raw, &input); err != nil {
		writeError(w, r, err)
		return
	}
	if _, present := raw["translations"]; present {
		list := make([]cms.PageTranslation, 0, len(translations))
		for _, entry := range translations {
			translation := cms.PageTranslation{}
			if entry.LanguageID != nil {
				translation.LanguageID = *entry.LanguageID
			}
			if entry.Title != nil {
				translation.Title = *entry.Title
			}
			translation.Body = entry.Body
			translation.SEOTitle = entry.SEOTitle
			translation.SEODescription = entry.SEODescription
			list = append(list, translation)
		}
		input.Translations = &list
	}
	result, err := cms.UpdatePage(r.Context(), s.database, id, input)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSPageDestroy(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "page")
	if !ok {
		return
	}
	err := cms.DeletePage(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "cms.page_deleted"), nil)
}

func (s *Server) handleCMSBlockIndex(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	result, err := cms.AdminBlocks(r.Context(), s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSBlockStore(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	var input cms.BlockInput
	var uploads cms.BlockUploads
	var err error
	if strings.HasPrefix(strings.ToLower(r.Header.Get("Content-Type")), "multipart/form-data") {
		input, uploads, err = decodeHomepageBlockMultipart(r)
	} else {
		input, err = decodeCMSBlockJSON(r)
	}
	contentErr := validateHomepageBlockContent(r.Context(), s.database, input.Type, input.Content)
	if err = mergeValidationErrors(err, contentErr); err != nil {
		writeError(w, r, err)
		return
	}
	fileErrors := fieldErrors{}
	validateHomepageBlockUpload(r.Context(), fileErrors, "image", uploads.Image)
	if input.Type != nil && *input.Type == "banner_slider" && len(uploads.SlideImages) > 10 {
		fileErrors.add("slide_images", vmsg(r.Context(), "max.array", "slide_images", "max", "10"))
	}
	for key, fields := range uploads.SlideImages {
		for slot, file := range fields {
			validateHomepageBlockUpload(r.Context(), fileErrors, "slide_images."+key+"."+slot, file)
		}
	}
	if err := fileErrors.fail(r.Context()); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.CreateBlockWithUploads(r.Context(), s.database, input, uploads)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func mergeValidationErrors(first, second error) error {
	if first == nil {
		return second
	}
	if second == nil {
		return first
	}
	var left, right *httpx.Error
	if !errors.As(first, &left) || !errors.As(second, &right) || left.Status != http.StatusUnprocessableEntity || right.Status != http.StatusUnprocessableEntity {
		return first
	}
	if left.Fields == nil {
		left.Fields = map[string][]string{}
	}
	for field, messages := range right.Fields {
		left.Fields[field] = append(left.Fields[field], messages...)
	}
	if len(right.FieldStrings) > 0 && left.FieldStrings == nil {
		left.FieldStrings = map[string]string{}
	}
	for field, message := range right.FieldStrings {
		left.FieldStrings[field] = message
	}
	return left
}

func decodeCMSBlockJSON(r *http.Request) (cms.BlockInput, error) {
	return decodeCMSBlockFields(r, true)
}

func decodeCMSBlockUpdateJSON(r *http.Request) (cms.BlockInput, error) {
	return decodeCMSBlockFields(r, false)
}

func decodeCMSBlockFields(r *http.Request, requireType bool) (cms.BlockInput, error) {
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		return cms.BlockInput{}, err
	}
	input := cms.BlockInput{}
	errs := fieldErrors{}
	_, input.ContentSet = raw["content"]
	if requireType {
		typeRaw, typePresent := raw["type"]
		if !typePresent || string(typeRaw) == "null" || string(typeRaw) == `""` {
			errs.add("type", vmsg(r.Context(), "required", "type"))
		} else if json.Unmarshal(typeRaw, new(string)) != nil {
			errs.add("type", vmsg(r.Context(), "string", "type"), vmsg(r.Context(), "in", "type"))
		} else {
			var value string
			_ = json.Unmarshal(typeRaw, &value)
			input.Type = &value
		}
	}
	if contentRaw, present := raw["content"]; present && string(contentRaw) != "null" {
		trimmed := strings.TrimSpace(string(contentRaw))
		if strings.HasPrefix(trimmed, "{") {
			if err := json.Unmarshal(contentRaw, &input.Content); err != nil {
				errs.add("content", vmsg(r.Context(), "array", "content"))
			}
		} else if strings.HasPrefix(trimmed, "[") {
			// Laravel accepts a JSON list as an array. Homepage block content is
			// consumed as keyed data, so preserve its numeric keys in the Go map.
			var values []any
			if err := json.Unmarshal(contentRaw, &values); err != nil {
				errs.add("content", vmsg(r.Context(), "array", "content"))
			} else {
				input.Content = make(map[string]any, len(values))
				for i, value := range values {
					input.Content[strconv.Itoa(i)] = value
				}
			}
		} else {
			input.Content = map[string]any{}
			errs.add("content", vmsg(r.Context(), "array", "content"))
		}
	}
	if value, present := raw["sort_order"]; present && (string(value) == "null" || string(value) == `""`) {
		sortOrder := 0
		input.SortOrder = &sortOrder
	} else if present {
		var number json.Number
		decoder := json.NewDecoder(strings.NewReader(string(value)))
		decoder.UseNumber()
		var decoded any
		if err := decoder.Decode(&decoded); err == nil {
			switch candidate := decoded.(type) {
			case json.Number:
				number = candidate
			case string:
				number = json.Number(candidate)
			}
		}
		integer, err := number.Int64()
		if err != nil || int64(int(integer)) != integer {
			errs.add("sort_order", vmsg(r.Context(), "integer", "sort_order"))
		} else if integer < 0 {
			errs.add("sort_order", vmsg(r.Context(), "min.numeric", "sort_order", "min", "0"))
		} else {
			value := int(integer)
			input.SortOrder = &value
		}
	}
	if value, present := raw["is_active"]; present {
		var decoded any
		if err := json.Unmarshal(value, &decoded); err == nil {
			switch candidate := decoded.(type) {
			case bool:
				input.IsActive = &candidate
			case json.Number:
				if candidate == "0" || candidate == "1" {
					active := candidate == "1"
					input.IsActive = &active
				} else {
					errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
				}
			case string:
				if candidate == "0" || candidate == "1" {
					active := candidate == "1"
					input.IsActive = &active
				} else {
					errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
				}
			default:
				errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
			}
		} else {
			errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
		}
	}
	decodeDate := func(key string, target **string) {
		value, present := raw[key]
		if key == "starts_at" {
			input.StartsAtSet = present
		} else if key == "ends_at" {
			input.EndsAtSet = present
		}
		if !present || string(value) == "null" || string(value) == `""` {
			return
		}
		var text string
		if err := json.Unmarshal(value, &text); err != nil {
			errs.add(key, vmsg(r.Context(), "date", key))
			return
		}
		if _, ok := parseHomepageFormDate(&text); !ok {
			errs.add(key, vmsg(r.Context(), "date", key))
			return
		}
		*target = &text
	}
	decodeDate("starts_at", &input.StartsAt)
	decodeDate("ends_at", &input.EndsAt)
	if input.StartsAt != nil && input.EndsAt != nil {
		starts, _ := parseHomepageFormDate(input.StartsAt)
		ends, _ := parseHomepageFormDate(input.EndsAt)
		if ends.Before(starts) {
			errs.add("ends_at", vmsg(r.Context(), "after_or_equal", "ends_at", "date", i18n.AttributeName(LocaleFrom(r.Context()), "starts_at")))
		}
	}
	if !requireType {
		if value, present := raw["remove_image"]; present {
			var decoded any
			if err := json.Unmarshal(value, &decoded); err == nil {
				switch candidate := decoded.(type) {
				case bool:
					input.RemoveImage = &candidate
				case json.Number:
					if candidate == "0" || candidate == "1" {
						remove := candidate == "1"
						input.RemoveImage = &remove
					} else {
						errs.add("remove_image", vmsg(r.Context(), "boolean", "remove_image"))
					}
				case float64:
					numeric := strings.TrimSpace(string(value))
					if numeric == "0" || numeric == "1" {
						remove := numeric == "1"
						input.RemoveImage = &remove
					} else {
						errs.add("remove_image", vmsg(r.Context(), "boolean", "remove_image"))
					}
				case string:
					if candidate == "0" || candidate == "1" {
						remove := candidate == "1"
						input.RemoveImage = &remove
					} else {
						errs.add("remove_image", vmsg(r.Context(), "boolean", "remove_image"))
					}
				default:
					errs.add("remove_image", vmsg(r.Context(), "boolean", "remove_image"))
				}
			} else {
				errs.add("remove_image", vmsg(r.Context(), "boolean", "remove_image"))
			}
		}
	}
	if err := errs.fail(r.Context()); err != nil {
		return input, err
	}
	return input, nil
}

func (s *Server) handleCMSBlockUpdate(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "block")
	if !ok {
		return
	}
	var input cms.BlockInput
	var uploads cms.BlockUploads
	var decodeErr error
	if strings.HasPrefix(strings.ToLower(r.Header.Get("Content-Type")), "multipart/form-data") {
		input, uploads, decodeErr = decodeHomepageBlockMultipart(r)
	} else {
		input, decodeErr = decodeCMSBlockUpdateJSON(r)
	}
	blockType, _, err := cms.HomepageBlockContent(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	if input.Content != nil {
		normalizeHomepageFormContent(blockType, input.Content)
	}
	typ := blockType
	contentErr := validateHomepageBlockContentUpdate(r.Context(), s.database, &typ, input.Content)
	if err := mergeValidationErrors(decodeErr, contentErr); err != nil {
		writeError(w, r, err)
		return
	}
	fileErrors := fieldErrors{}
	validateHomepageBlockUpload(r.Context(), fileErrors, "image", uploads.Image)
	if blockType == "banner_slider" && len(uploads.SlideImages) > 10 {
		fileErrors.add("slide_images", vmsg(r.Context(), "max.array", "slide_images", "max", "10"))
	}
	for key, fields := range uploads.SlideImages {
		for slot, file := range fields {
			validateHomepageBlockUpload(r.Context(), fileErrors, "slide_images."+key+"."+slot, file)
		}
	}
	if err := fileErrors.fail(r.Context()); err != nil {
		writeError(w, r, err)
		return
	}
	if input.ContentSet && input.Content == nil {
		// Laravel accepts nullable content during FormRequest validation, then its typed service call
		// raises a TypeError when the controller passes null. Match the 500 status without copying the
		// debug-only file/line details exposed by the local reference environment.
		writeError(w, r, httpx.NewError(http.StatusInternalServerError, msg(r.Context(), "system.server_error")))
		return
	}
	result, err := cms.UpdateBlockWithUploads(r.Context(), s.database, id, input, uploads)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSBlockUpdateMethodOverride(w http.ResponseWriter, r *http.Request) {
	if r.FormValue("_method") != http.MethodPatch {
		writeError(w, r, httpx.NewError(http.StatusMethodNotAllowed, "Method Not Allowed"))
		return
	}
	s.handleCMSBlockUpdate(w, r)
}

func (s *Server) handleCMSBlockToggle(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "block")
	if !ok {
		return
	}
	result, err := cms.ToggleBlock(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	message := msg(r.Context(), "cms.block_deactivated")
	if active, _ := result["is_active"].(bool); active {
		message = msg(r.Context(), "cms.block_activated")
	}
	httpx.OK(w, result, message, nil)
}

func (s *Server) handleCMSBlockReorder(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	ids, err := decodeCMSBlockIDs(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	validationErrors := fieldErrors{}
	for i, id := range ids {
		var exists bool
		if err := s.database.QueryRowContext(r.Context(), `SELECT EXISTS(SELECT 1 FROM cms_homepage_blocks WHERE id = ?)`, id).Scan(&exists); err != nil {
			writeError(w, r, err)
			return
		}
		if !exists {
			field := "block_ids." + strconv.Itoa(i)
			validationErrors.add(field, vmsgIndexed(r.Context(), "exists", field))
		}
	}
	if err := validationErrors.fail(r.Context()); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.ReorderBlocks(r.Context(), s.database, ids)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, msg(r.Context(), "cms.reorder_saved"), nil)
}

func decodeCMSBlockIDs(r *http.Request) ([]int64, error) {
	raw := map[string]json.RawMessage{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		return nil, err
	}
	value, exists := raw["block_ids"]
	if !exists || string(value) == "null" || string(value) == `""` {
		return nil, httpx.Validation(map[string][]string{"block_ids": requiredField(r.Context(), "block_ids")})
	}
	trimmed := strings.TrimSpace(string(value))
	var values []json.RawMessage
	switch {
	case strings.HasPrefix(trimmed, "["):
		if err := json.Unmarshal(value, &values); err != nil {
			return nil, httpx.Validation(map[string][]string{"block_ids": {vmsg(r.Context(), "array", "block_ids")}})
		}
	case strings.HasPrefix(trimmed, "{"):
		decoder := json.NewDecoder(strings.NewReader(trimmed))
		if _, err := decoder.Token(); err != nil {
			return nil, httpx.Validation(map[string][]string{"block_ids": {vmsg(r.Context(), "array", "block_ids")}})
		}
		for decoder.More() {
			if _, err := decoder.Token(); err != nil {
				return nil, httpx.Validation(map[string][]string{"block_ids": {vmsg(r.Context(), "array", "block_ids")}})
			}
			var item json.RawMessage
			if err := decoder.Decode(&item); err != nil {
				return nil, httpx.Validation(map[string][]string{"block_ids": {vmsg(r.Context(), "array", "block_ids")}})
			}
			values = append(values, item)
		}
	default:
		return nil, httpx.Validation(map[string][]string{"block_ids": {vmsg(r.Context(), "array", "block_ids")}})
	}
	if len(values) == 0 {
		return nil, httpx.Validation(map[string][]string{"block_ids": requiredField(r.Context(), "block_ids")})
	}
	ids := make([]int64, len(values))
	errs := fieldErrors{}
	for i, item := range values {
		var rawID any
		decoder := json.NewDecoder(strings.NewReader(string(item)))
		decoder.UseNumber()
		if err := decoder.Decode(&rawID); err != nil {
			field := "block_ids." + strconv.Itoa(i)
			errs.add(field, vmsgIndexed(r.Context(), "exists", field))
			continue
		}
		text := ""
		switch candidate := rawID.(type) {
		case json.Number:
			text = candidate.String()
		case string:
			text = candidate
		case bool:
			if candidate {
				text = "1"
			} else {
				text = "0"
			}
		}
		id, err := strconv.ParseInt(text, 10, 64)
		if err != nil {
			value, floatErr := strconv.ParseFloat(text, 64)
			if floatErr != nil || math.Trunc(value) != value || value < math.MinInt64 || value >= math.MaxInt64 {
				field := "block_ids." + strconv.Itoa(i)
				errs.add(field, vmsgIndexed(r.Context(), "exists", field))
				continue
			}
			id = int64(value)
		}
		ids[i] = id
	}
	if err := errs.fail(r.Context()); err != nil {
		return nil, err
	}
	return ids, nil
}

func vmsgIndexed(ctx context.Context, rule, field string) string {
	return strings.ReplaceAll(i18n.Translate(LocaleFrom(ctx), "validation."+rule), ":attribute", field)
}

func (s *Server) handleCMSBlockDestroy(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "block")
	if !ok {
		return
	}
	err := cms.DeleteBlock(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "cms.block_deleted"), nil)
}

// remarshalRaw re-decodes a raw-map body into a typed payload (RawMessage variant of remarshal).
func remarshalRaw(raw map[string]json.RawMessage, dst any) error {
	buf, err := json.Marshal(raw)
	if err != nil {
		return err
	}
	if err := json.Unmarshal(buf, dst); err != nil {
		return httpx.Validation(map[string][]string{"body": {"The body must be valid JSON."}})
	}
	return nil
}
