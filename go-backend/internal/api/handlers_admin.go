package api

import (
	"errors"
	"net/http"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/admin"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// writeAdminError maps an admin domain error onto the envelope, translating message KEYS.
func writeAdminError(w http.ResponseWriter, r *http.Request, err error) {
	var de *admin.DomainError
	if errors.As(err, &de) {
		ctx := r.Context()
		if de.Status == 422 && len(de.Fields) > 0 {
			// Field values that name a RULE are rendered through the validation catalogue, so the sentence
			// matches the reference exactly.
			out := make(map[string][]string, len(de.Fields))
			for field, msgs := range de.Fields {
				rendered := make([]string, 0, len(msgs))
				for _, m := range msgs {
					switch m {
					case "unique":
						rendered = append(rendered, uniqueField(ctx, field)...)
					default:
						rendered = append(rendered, m)
					}
				}
				out[field] = rendered
			}
			httpx.Failure(w, de.Status, msg(ctx, "system.validation_failed"), out)
			return
		}
		// A message that names a catalogue key (e.g. "language.default_must_be_active") is translated; anything
		// else is already the final sentence the reference returns.
		if strings.HasPrefix(de.Message, "language.") || strings.HasPrefix(de.Message, "settings.") {
			httpx.Failure(w, de.Status, msg(ctx, de.Message), nil)
			return
		}
		httpx.Failure(w, de.Status, de.Message, nil)
		return
	}
	if errors.Is(err, admin.ErrNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	writeError(w, r, err)
}

// handleAdminLanguages is GET /admin/languages.
func (s *Server) handleAdminLanguages(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	rows, err := admin.ListLanguages(ctx, s.database)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

// handleAdminLanguageStore is POST /admin/languages.
func (s *Server) handleAdminLanguageStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body struct {
		Code      string `json:"code"`
		Name      string `json:"name"`
		SortOrder *int64 `json:"sort_order"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.Code == "" {
		fields["code"] = requiredField(ctx, "code")
	} else if len(body.Code) > 5 {
		fields["code"] = maxString(ctx, "code", 5)
	}
	if body.Name == "" {
		fields["name"] = requiredField(ctx, "name")
	} else if len(body.Name) > 50 {
		fields["name"] = maxString(ctx, "name", 50)
	}
	if body.SortOrder != nil && *body.SortOrder < 0 {
		fields["sort_order"] = minNumeric(ctx, "sort_order", 0)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	row, err := admin.CreateLanguage(ctx, s.database, body.Code, body.Name, body.SortOrder)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.Created(w, row, "Created")
}

// handleAdminLanguageUpdate is PATCH /admin/languages/{language}.
func (s *Server) handleAdminLanguageUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "language")
	if !ok {
		return
	}
	var body struct {
		Code      *string `json:"code"`
		Name      *string `json:"name"`
		SortOrder *int64  `json:"sort_order"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	if body.Code != nil && (len(*body.Code) == 0 || len(*body.Code) > 5) {
		fields["code"] = maxString(ctx, "code", 5)
	}
	if body.Name != nil && (len(*body.Name) == 0 || len(*body.Name) > 50) {
		fields["name"] = maxString(ctx, "name", 50)
	}
	if body.SortOrder != nil && *body.SortOrder < 0 {
		fields["sort_order"] = minNumeric(ctx, "sort_order", 0)
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := admin.UpdateLanguage(ctx, s.database, id, body.Code, body.Name, body.SortOrder)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleAdminLanguageSetDefault is PATCH /admin/languages/{language}/set-default.
func (s *Server) handleAdminLanguageSetDefault(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "language")
	if !ok {
		return
	}
	row, err := admin.SetDefaultLanguage(r.Context(), s.database, id)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleAdminLanguageToggle is PATCH /admin/languages/{language}/toggle-active.
func (s *Server) handleAdminLanguageToggle(w http.ResponseWriter, r *http.Request) {
	id, ok := pathID(w, r, "language")
	if !ok {
		return
	}
	row, err := admin.ToggleLanguage(r.Context(), s.database, id)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.OK(w, row, "OK", nil)
}

// handleAdminSettingsShow is GET /admin/settings (manage-system-config).
func (s *Server) handleAdminSettingsShow(w http.ResponseWriter, r *http.Request) {
	settings, err := admin.Settings(r.Context(), s.database)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.OK(w, settings, "OK", nil)
}

// handleAdminSettingsUpdate is PUT /admin/settings.
//
// The request validator only accepts DEFINED keys with the documented types; unknown keys are ignored exactly
// like the reference (WebsiteSettingKeys::isValid guards the write loop).
func (s *Server) handleAdminSettingsUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body map[string]any
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	for key, value := range body {
		if !admin.IsKnownSetting(key) {
			continue // ignored, not an error (matches the reference)
		}
		if admin.SettingIsJSON(key) {
			switch value.(type) {
			case map[string]any, []any, nil:
			default:
				fields[key] = invalidChoice(ctx, key)
			}
			continue
		}
		if value == nil {
			continue
		}
		if _, ok := value.(string); !ok {
			// JSON media ids arrive as numbers; the reference accepts them as strings/ints, so accept any
			// scalar and let the service stringify it.
			switch value.(type) {
			case float64, bool:
			default:
				fields[key] = invalidChoice(ctx, key)
			}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	settings, err := admin.UpdateSettings(ctx, s.database, body)
	if err != nil {
		writeAdminError(w, r, err)
		return
	}
	httpx.OK(w, settings, msg(ctx, "settings.updated"), nil)
}
