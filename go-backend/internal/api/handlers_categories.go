package api

import (
	"context"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/catalog"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

type categoryPayload struct {
	ParentID  *int64  `json:"parent_id"`
	Name      *string `json:"name"`
	Slug      *string `json:"slug"`
	IsActive  *bool   `json:"is_active"`
	SortOrder *int64  `json:"sort_order"`
}

func writeCategoryError(w http.ResponseWriter, r *http.Request, err error) {
	ctx := r.Context()
	switch err {
	case catalog.ErrCategoryNotFound:
		writeError(w, r, notFoundError(ctx))
	case catalog.ErrCategoryDuplicate:
		writeError(w, r, httpx.Validation(map[string][]string{
			"name": {"Kategori dengan nama yang sama sudah ada pada induk ini."},
		}))
	case catalog.ErrCategoryParent:
		writeError(w, r, httpx.Validation(map[string][]string{
			"parent_id": {"Kategori induk tidak ditemukan."},
		}))
	case catalog.ErrCategoryCycle:
		writeError(w, r, httpx.Validation(map[string][]string{
			"parent_id": {"Induk tidak boleh berupa turunan kategori ini (siklus hierarki)."},
		}))
	case catalog.ErrCategorySlug:
		writeError(w, r, httpx.Validation(map[string][]string{
			"slug": {"Slug kategori sudah digunakan."},
		}))
	case catalog.ErrCategoryDelete:
		writeError(w, r, &httpx.Error{
			Status:  409,
			Message: "Kategori tidak dapat dihapus: sub-kategorinya akan bentrok dengan kategori utama bernama sama. Pindahkan atau ganti nama sub-kategori terlebih dahulu.",
		})
	default:
		writeError(w, r, err)
	}
}

func validateCategoryCreate(ctx context.Context, raw map[string]any, p categoryPayload) map[string][]string {
	fields := map[string][]string{}
	if p.Name == nil {
		fields["name"] = requiredField(ctx, "name")
	} else if len(*p.Name) == 0 {
		fields["name"] = requiredField(ctx, "name")
	} else if len(*p.Name) > 100 {
		fields["name"] = maxString(ctx, "name", 100)
	}
	if p.Slug != nil && len(*p.Slug) > 120 {
		fields["slug"] = maxString(ctx, "slug", 120)
	}
	if p.SortOrder != nil && *p.SortOrder < 0 {
		fields["sort_order"] = minNumeric(ctx, "sort_order", 0)
	}
	_ = raw
	return fields
}

func (s *Server) handleCategoryStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u, ok := requireUser(w, r)
	if !ok {
		return
	}
	raw := map[string]any{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var p categoryPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
		return
	}
	if fields := validateCategoryCreate(ctx, raw, p); len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := catalog.CreateCategory(ctx, s.database, u.ID, catalog.CategoryInput{
		ParentID: p.ParentID, Name: p.Name, Slug: p.Slug, IsActive: p.IsActive, SortOrder: p.SortOrder,
	})
	if err != nil {
		writeCategoryError(w, r, err)
		return
	}
	httpx.Created(w, row, "Created")
}

func (s *Server) handleCategoryUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "category")
	if !ok {
		return
	}
	raw := map[string]any{}
	if err := httpx.DecodeJSON(r, &raw); err != nil {
		writeError(w, r, err)
		return
	}
	var p categoryPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if p.Name != nil {
		if len(*p.Name) == 0 {
			fields["name"] = requiredField(ctx, "name")
		} else if len(*p.Name) > 100 {
			fields["name"] = maxString(ctx, "name", 100)
		}
	}
	if p.Slug != nil && len(*p.Slug) > 120 {
		fields["slug"] = maxString(ctx, "slug", 120)
	}
	if p.SortOrder != nil && *p.SortOrder < 0 {
		fields["sort_order"] = minNumeric(ctx, "sort_order", 0)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := catalog.UpdateCategory(ctx, s.database, u.ID, id, catalog.CategoryInput{
		ParentID: p.ParentID, SetParent: hasKey(raw, "parent_id"),
		Name: p.Name, Slug: p.Slug, IsActive: p.IsActive, SortOrder: p.SortOrder,
	})
	if err != nil {
		writeCategoryError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

func (s *Server) handleCategoryDestroy(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "category")
	if !ok {
		return
	}
	if err := catalog.DeleteCategory(r.Context(), s.database, id); err != nil {
		writeCategoryError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "messages.product.category_deleted"), nil)
}
