// Package admin holds the system-configuration domains: language registry, website settings and (later)
// the remaining admin surfaces.
//
// Every write here is gated by the `manage-system-config` Gate in Laravel, which the Go router enforces as a
// super_admin role check. Nothing in this package touches money or stock, so the transactional surface is a
// single row update per request.
package admin

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrNotFound is a missing row.
var ErrNotFound = errors.New("not found")

// DomainError carries an HTTP status and the reference's exact message.
type DomainError struct {
	Status  int
	Message string
	Fields  map[string][]string
}

func (e *DomainError) Error() string { return e.Message }

// Language is one row of the language registry (full model serialisation, admin listing).
type Language struct {
	ID        int64
	Code      string
	Name      string
	IsDefault bool
	IsActive  bool
	SortOrder int64
	CreatedAt any
	UpdatedAt any
}

// JSON renders the model exactly as Eloquent serialises it.
func (l Language) JSON() map[string]any {
	return map[string]any{
		"id":         l.ID,
		"code":       l.Code,
		"name":       l.Name,
		"is_default": l.IsDefault,
		"is_active":  l.IsActive,
		"sort_order": l.SortOrder,
		"created_at": l.CreatedAt,
		"updated_at": l.UpdatedAt,
	}
}

const languageColumns = `SELECT id, code, name, is_default, is_active, sort_order,
	CAST(created_at AS CHAR), CAST(updated_at AS CHAR) FROM languages`

func scanLanguages(rows *sql.Rows) ([]Language, error) {
	var out []Language
	for rows.Next() {
		var (
			l                   Language
			isDefault, isActive int
			created, updated    sql.NullString
		)
		if err := rows.Scan(&l.ID, &l.Code, &l.Name, &isDefault, &isActive, &l.SortOrder, &created, &updated); err != nil {
			return nil, err
		}
		l.IsDefault = isDefault == 1
		l.IsActive = isActive == 1
		l.CreatedAt = formatMySQLTime(created)
		l.UpdatedAt = formatMySQLTime(updated)
		out = append(out, l)
	}
	return out, rows.Err()
}

// ListLanguages is LanguageController::adminIndex — EVERY language, active or not.
func ListLanguages(ctx context.Context, d *db.DB) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, languageColumns+` ORDER BY sort_order ASC`)
	if err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	list, err := scanLanguages(rows)
	if err != nil {
		return nil, err
	}
	out := make([]map[string]any, 0, len(list))
	for _, l := range list {
		out = append(out, l.JSON())
	}
	return out, nil
}

// FindLanguage loads one language by id.
func FindLanguage(ctx context.Context, d *db.DB, id int64) (*Language, error) {
	rows, err := d.QueryContext(ctx, languageColumns+` WHERE id = ?`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	list, err := scanLanguages(rows)
	if err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return nil, ErrNotFound
	}
	return &list[0], nil
}

// CreateLanguage is LanguageController::store.
//
// A new language is never the default (is_default = false) and starts active — that is the reference
// behaviour and it keeps exactly one default at all times.
func CreateLanguage(ctx context.Context, d *db.DB, code, name string, sortOrder *int64) (map[string]any, error) {
	var exists bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM languages WHERE code = ?)`, code).Scan(&exists); err != nil {
		return nil, err
	}
	if exists {
		return nil, &DomainError{Status: 422, Message: "unique", Fields: map[string][]string{"code": {"unique"}}}
	}
	order := int64(0)
	if sortOrder != nil {
		order = *sortOrder
	}
	res, err := d.ExecContext(ctx, `INSERT INTO languages (code, name, is_default, is_active, sort_order, created_at, updated_at)
		VALUES (?, ?, 0, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, code, name, order)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	l, err := FindLanguage(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return l.JSON(), nil
}

// UpdateLanguage is LanguageController::update (partial update, code uniqueness excluding itself).
func UpdateLanguage(ctx context.Context, d *db.DB, id int64, code, name *string, sortOrder *int64) (map[string]any, error) {
	if _, err := FindLanguage(ctx, d, id); err != nil {
		return nil, err
	}
	if code != nil {
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM languages WHERE code = ? AND id <> ?)`, *code, id).Scan(&exists); err != nil {
			return nil, err
		}
		if exists {
			return nil, &DomainError{Status: 422, Message: "unique", Fields: map[string][]string{"code": {"unique"}}}
		}
	}
	sets := []string{}
	args := []any{}
	if code != nil {
		sets = append(sets, "code = ?")
		args = append(args, *code)
	}
	if name != nil {
		sets = append(sets, "name = ?")
		args = append(args, *name)
	}
	if sortOrder != nil {
		sets = append(sets, "sort_order = ?")
		args = append(args, *sortOrder)
	}
	if len(sets) > 0 {
		args = append(args, id)
		if _, err := d.ExecContext(ctx, `UPDATE languages SET `+strings.Join(sets, ", ")+`, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, args...); err != nil {
			return nil, err
		}
	}
	l, err := FindLanguage(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return l.JSON(), nil
}

// SetDefaultLanguage is LanguageController::setDefault: exactly one default, and it must be active.
func SetDefaultLanguage(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	l, err := FindLanguage(ctx, d, id)
	if err != nil {
		return nil, err
	}
	if !l.IsActive {
		return nil, &DomainError{Status: 422, Message: "language.default_must_be_active"}
	}
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, `UPDATE languages SET is_default = 0, updated_at = UTC_TIMESTAMP() WHERE id <> ?`, id); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `UPDATE languages SET is_default = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?`, id)
		return err
	})
	if err != nil {
		return nil, err
	}
	fresh, err := FindLanguage(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return fresh.JSON(), nil
}

// ToggleLanguage is LanguageController::toggleActive — the default language can never be deactivated.
func ToggleLanguage(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	l, err := FindLanguage(ctx, d, id)
	if err != nil {
		return nil, err
	}
	if l.IsDefault && l.IsActive {
		return nil, &DomainError{Status: 422, Message: "language.cannot_deactivate_default"}
	}
	if _, err := d.ExecContext(ctx, `UPDATE languages SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		!l.IsActive, id); err != nil {
		return nil, err
	}
	fresh, err := FindLanguage(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return fresh.JSON(), nil
}

// ---- website settings ------------------------------------------------------------------------------

// settingDefs mirrors App\Support\WebsiteSettingKeys::DEFINITIONS (order included).
type settingDef struct {
	Key    string
	IsJSON bool
	Def    any
}

var settingDefs = []settingDef{
	{"site_title", false, "Prime Classy Cake & Cookies"},
	{"site_slogan", false, ""},
	{"site_logo_media_id", false, nil},
	{"site_favicon_media_id", false, nil},
	{"site_contact_email", false, ""},
	{"site_contact_phone", false, ""},
	{"site_address", false, ""},
	{"site_header", true, []any{}},
	{"site_footer", true, []any{}},
	{"site_theme", true, []any{}},
	{"site_social", true, []any{}},
	{"site_seo_title", false, ""},
	{"site_seo_description", false, ""},
	{"site_seo_keywords", false, ""},
	{"site_about", false, ""},
}

// SettingKeys lists the editable keys (used by the update validation).
func SettingKeys() []string {
	out := make([]string, 0, len(settingDefs))
	for _, d := range settingDefs {
		out = append(out, d.Key)
	}
	return out
}

// SettingIsJSON reports whether a key stores JSON.
func SettingIsJSON(key string) bool {
	for _, d := range settingDefs {
		if d.Key == key {
			return d.IsJSON
		}
	}
	return false
}

// IsKnownSetting reports whether a key is defined.
func IsKnownSetting(key string) bool {
	for _, d := range settingDefs {
		if d.Key == key {
			return true
		}
	}
	return false
}

// Settings returns every defined key resolved with its stored value or default, plus logo/favicon URLs
// (WebsiteSettingsService::all). This is the ADMIN read: it is identical to the public one because the
// service has a single implementation, which is why the same payload is returned.
func Settings(ctx context.Context, d *db.DB) (map[string]any, error) {
	values := map[string]string{}
	rows, err := d.QueryContext(ctx, "SELECT `key`, value FROM settings WHERE `group` = 'site'")
	if err != nil {
		if !db.IsMissingSchema(err) {
			return nil, err
		}
	} else {
		defer rows.Close()
		for rows.Next() {
			var key string
			var val sql.NullString
			if err := rows.Scan(&key, &val); err != nil {
				return nil, err
			}
			values[key] = val.String
		}
		if err := rows.Err(); err != nil {
			return nil, err
		}
	}

	out := make(map[string]any, len(settingDefs)+2)
	for _, def := range settingDefs {
		raw, ok := values[def.Key]
		if !ok {
			out[def.Key] = def.Def
			continue
		}
		if def.IsJSON {
			var decoded any = []any{}
			if strings.TrimSpace(raw) != "" {
				if err := json.Unmarshal([]byte(raw), &decoded); err != nil {
					decoded = []any{}
				}
			}
			out[def.Key] = decoded
			continue
		}
		out[def.Key] = raw
	}
	out["logo_url"] = mediaURL(ctx, d, out["site_logo_media_id"])
	out["favicon_url"] = mediaURL(ctx, d, out["site_favicon_media_id"])
	return out, nil
}

func mediaURL(ctx context.Context, d *db.DB, id any) any {
	s, ok := id.(string)
	if !ok || strings.TrimSpace(s) == "" {
		return nil
	}
	var path string
	if err := d.QueryRowContext(ctx, `SELECT path FROM media WHERE id = ?`, s).Scan(&path); err != nil {
		return nil
	}
	return assetURL(ctx, path)
}

// UpdateSettings applies the submitted keys.
//
// Behaviour matches WebsiteSettingsService::update: unknown keys are ignored (the request validator already
// restricted them), JSON keys are stored encoded, everything else as a string, and the full resolved settings
// are returned.
func UpdateSettings(ctx context.Context, d *db.DB, payload map[string]any) (map[string]any, error) {
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		for key, value := range payload {
			if !IsKnownSetting(key) {
				continue
			}
			var stored string
			if SettingIsJSON(key) {
				buf, err := json.Marshal(value)
				if err != nil {
					return err
				}
				stored = string(buf)
			} else if value == nil {
				stored = ""
			} else if s, ok := value.(string); ok {
				stored = s
			} else {
				buf, err := json.Marshal(value)
				if err != nil {
					return err
				}
				stored = strings.Trim(string(buf), `"`)
			}
			if _, err := tx.ExecContext(ctx, "INSERT INTO settings (`key`, value, `group`, is_public, created_at, updated_at)\n\t\t\t\tVALUES (?, ?, 'site', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())\n\t\t\t\tON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = UTC_TIMESTAMP()", key, stored); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return Settings(ctx, d)
}
