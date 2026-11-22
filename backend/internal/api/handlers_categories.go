package api

// Category admin mutations (ProductCategoryController + CategoryHierarchyService).
//
//	POST   /categories             store   — super_admin/admin/agen (REM-06 create-only)
//	PATCH  /categories/{category}  update  — super_admin only
//	DELETE /categories/{category}  destroy — super_admin only
//
// The effect order is the reference's: middleware auth/role → route-model binding 404 → FormRequest
// validation 422 → controller policy → service. Presentation controls (slug, is_active, sort_order) are
// read ONLY for a super_admin actor: create-only roles cannot influence ordering, visibility or the slug.

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/catalog"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

type categoryPayload struct {
	ParentID  *int64  `json:"parent_id"`
	Name      *string `json:"name"`
	Slug      *string `json:"slug"`
	IsActive  *bool   `json:"is_active"`
	SortOrder *int64  `json:"sort_order"`
}

const categoryEmptyNameMessage = "Nama kategori tidak boleh kosong."

func writeCategoryError(w http.ResponseWriter, r *http.Request, err error) {
	ctx := r.Context()
	switch err {
	case catalog.ErrCategoryNotFound:
		writeError(w, r, notFoundError(ctx))
	case catalog.ErrCategoryDuplicate:
		writeError(w, r, httpx.Validation(map[string][]string{
			"name": {"Kategori dengan nama yang sama sudah ada pada induk ini."},
		}))
	case catalog.ErrCategoryNameEmpty:
		writeError(w, r, httpx.Validation(map[string][]string{
			"name": {categoryEmptyNameMessage},
		}))
	case catalog.ErrCategoryParent:
		writeError(w, r, httpx.Validation(map[string][]string{
			"parent_id": {"Kategori induk tidak ditemukan."},
		}))
	case catalog.ErrCategorySelf:
		writeError(w, r, httpx.Validation(map[string][]string{
			"parent_id": {"Kategori tidak boleh menjadi induk dirinya sendiri."},
		}))
	case catalog.ErrCategoryCycle:
		writeError(w, r, httpx.Validation(map[string][]string{
			"parent_id": {"Induk tidak boleh berupa turunan kategori ini (siklus hierarki)."},
		}))
	case catalog.ErrCategorySlug:
		writeError(w, r, httpx.Validation(map[string][]string{
			"slug": {i18n.ValidationMessage(LocaleFrom(ctx), "unique", "slug")},
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

// categoryFieldErrors is the shared shape validator: Laravel's ConvertEmptyStringsToNull makes a
// whitespace-only string null before `required` runs, so a blank name fails required — never as a
// "duplicate" error. The parent_id `exists` rule also fires here (its failure sentence is the
// validator's, not the service's).
func categoryFieldErrors(ctx context.Context, s *Server, raw map[string]any, isStore bool) map[string][]string {
	fields := map[string][]string{}

	if value, present := raw["name"]; present {
		text, ok := value.(string)
		if !present || value == nil {
			text = ""
		} else if !ok {
			fields["name"] = maxString(ctx, "name", 100)
			text = ""
		}
		if strings.TrimSpace(text) == "" {
			// null name: required on store, "Nama kategori tidak boleh kosong" on update (sometimes+normalize).
			if isStore {
				fields["name"] = requiredField(ctx, "name")
			} else {
				fields["name"] = []string{categoryEmptyNameMessage}
			}
		} else if len([]rune(text)) > 100 {
			fields["name"] = maxString(ctx, "name", 100)
		}
	} else if isStore {
		fields["name"] = requiredField(ctx, "name")
	}

	if value, present := raw["parent_id"]; present && value != nil {
		if _, ok := intValue(value); !ok {
			fields["parent_id"] = notInteger(ctx, "parent_id")
		} else if id, valid := intValue(value); valid {
			if !existsIn(ctx, s.database, "product_categories", "id", formatID(int64(id))) {
				fields["parent_id"] = unknownChoice(ctx, "parent_id")
			}
		}
	}

	if value, present := raw["slug"]; present && value != nil {
		text, _ := stringValue(value)
		if len([]rune(text)) > 120 {
			fields["slug"] = maxString(ctx, "slug", 120)
		}
	}

	if value, present := raw["is_active"]; present && value != nil {
		if _, ok := laravelBool(value); !ok {
			fields["is_active"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "boolean", "is active")}
		}
	}

	if value, present := raw["sort_order"]; present && value != nil {
		if order, ok := intValue(value); !ok {
			fields["sort_order"] = notInteger(ctx, "sort_order")
		} else if order < 0 {
			fields["sort_order"] = []string{i18nMinNumeric(ctx, "sort_order", 0)}
		}
	}

	return fields
}

// normalizeCategoryRaw coerces the scalar forms Laravel's validator accepts BEFORE remarshal: the `integer`
// rule passes "24"/24.0 (FILTER_VALIDATE_INT) and the `boolean` rule passes 0/1/"0"/"1". A strict JSON
// re-decode would otherwise 422 payloads Laravel accepts.
func normalizeCategoryRaw(raw map[string]any) map[string]any {
	out := make(map[string]any, len(raw))
	for key, value := range raw {
		switch key {
		case "parent_id", "sort_order":
			if id, ok := intValue(value); ok {
				out[key] = json.Number(strconv.Itoa(id))
				continue
			}
		case "is_active":
			if flag, ok := laravelBool(value); ok {
				out[key] = flag
				continue
			}
		}
		out[key] = value
	}
	return out
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
	raw = normalizeCategoryRaw(raw)
	if fields := categoryFieldErrors(ctx, s, raw, true); len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	var p categoryPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
		return
	}
	superAdmin := u.IsRole("super_admin")
	if !superAdmin {
		// REM-06: create-only roles cannot set the presentation controls.
		p.Slug, p.IsActive, p.SortOrder = nil, nil, nil
	}
	row, err := catalog.CreateCategory(ctx, s.database, u.ID, catalog.CategoryInput{
		ParentID: p.ParentID, Name: p.Name, Slug: p.Slug, IsActive: p.IsActive, SortOrder: p.SortOrder,
		ActorRole: &u.Role, ActorAgentID: u.AgentID,
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
	raw = normalizeCategoryRaw(raw)
	if fields := categoryFieldErrors(ctx, s, raw, false); len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	var p categoryPayload
	if err := remarshal(raw, &p); err != nil {
		writeError(w, r, err)
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
	httpx.OK(w, nil, msg(r.Context(), "product.category_deleted"), nil)
}

var _ = json.Marshal
