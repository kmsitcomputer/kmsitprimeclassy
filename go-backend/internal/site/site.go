// Package site is the public site-configuration read model: website settings, languages, the agent
// directory, region cascades and install status.
//
// Every function here is reachable on a fresh, unmigrated deployment (the installer wizard and monitoring
// tools call them before the schema exists). A missing table therefore degrades to the same empty/default
// payload Laravel's SafeSchema produces — never a 500.
package site

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ---- website settings ------------------------------------------------------------------------------

// settingDef is one entry of App\Support\WebsiteSettingKeys::DEFINITIONS, in declaration order.
type settingDef struct {
	Key     string
	IsJSON  bool
	Default any
}

// defs mirrors WebsiteSettingKeys::DEFINITIONS exactly (order included, because the JSON object order is
// what the admin form and the SPA both rely on for "which key is which").
var defs = []settingDef{
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

// group is WebsiteSettingKeys::GROUP — the `settings.group` value these rows live under.
const group = "site"

// PublicSettings returns every defined key with its default, overlaid with the stored value, plus the
// resolved logo/favicon URLs (WebsiteSettingsService::publicSettings → all → attachMediaUrls).
func PublicSettings(ctx context.Context, d *db.DB) (map[string]any, error) {
	values := map[string]string{}
	rows, err := d.QueryContext(ctx, `SELECT `+"`key`"+`, value FROM settings WHERE `+"`group`"+` = ?`, group)
	if err != nil {
		if db.IsMissingSchema(err) {
			return assembler(ctx, d, values)
		}
		return nil, err
	}
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
	return assembler(ctx, d, values)
}

func assembler(ctx context.Context, d *db.DB, values map[string]string) (map[string]any, error) {
	out := make(map[string]any, len(defs)+2)
	for _, def := range defs {
		raw, ok := values[def.Key]
		if !ok {
			out[def.Key] = def.Default
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
		// A stored NULL/empty string keeps the shape Laravel produces: the raw string value.
		out[def.Key] = raw
	}

	// attachMediaUrls: logo/favicon ids become absolute urls (null when unset or dangling).
	for _, pair := range [][2]string{{"site_logo_media_id", "logo_url"}, {"site_favicon_media_id", "favicon_url"}} {
		out[pair[1]] = mediaURL(ctx, d, out[pair[0]])
	}
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
	return asset.URL(ctx, path)
}

// ---- languages -------------------------------------------------------------------------------------

// Languages returns the active languages (LanguageController::index).
func Languages(ctx context.Context, d *db.DB) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, code, name, is_default FROM languages
		WHERE is_active = 1 ORDER BY sort_order ASC`)
	if err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id int64
		var code, name string
		var isDefault int
		if err := rows.Scan(&id, &code, &name, &isDefault); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id":         id,
			"code":       code,
			"name":       name,
			"is_default": isDefault == 1,
		})
	}
	return out, rows.Err()
}

// ---- region cascades -------------------------------------------------------------------------------

// Regions is the public Provinsi → Kota/Kabupaten → Kecamatan → Kelurahan cascade.
//
// Each level validates the parent id exactly like the Laravel `exists:` rule, so an unknown parent produces
// the same 422 field error rather than an empty list.
func Regions(ctx context.Context, d *db.DB, level, parentID string) ([]map[string]any, error) {
	switch level {
	case "provinces":
		return regionList(ctx, d, `SELECT id, name FROM provinces ORDER BY name ASC`)
	case "regencies":
		if err := requireParent(ctx, d, "provinces", "province_id", parentID); err != nil {
			return nil, err
		}
		return regionList(ctx, d, `SELECT id, name FROM regencies WHERE province_id = ? ORDER BY name ASC`, parentID)
	case "districts":
		if err := requireParent(ctx, d, "regencies", "regency_id", parentID); err != nil {
			return nil, err
		}
		return regionList(ctx, d, `SELECT id, name FROM districts WHERE regency_id = ? ORDER BY name ASC`, parentID)
	case "villages":
		if err := requireParent(ctx, d, "districts", "district_id", parentID); err != nil {
			return nil, err
		}
		return regionList(ctx, d, `SELECT id, name FROM villages WHERE district_id = ? ORDER BY name ASC`, parentID)
	}
	return nil, errors.New("unknown region level")
}

// ParentError is a 422 for a missing/unknown parent id. Missing distinguishes the `required` rule from the
// `exists` rule, because Laravel's two messages differ.
type ParentError struct {
	Field   string
	Missing bool
}

func (e *ParentError) Error() string { return "invalid parent for " + e.Field }

func requireParent(ctx context.Context, d *db.DB, table, field, id string) error {
	if strings.TrimSpace(id) == "" {
		return &ParentError{Field: field, Missing: true}
	}
	var exists bool
	err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM `+table+` WHERE id = ?)`, id).Scan(&exists)
	if err != nil {
		if db.IsMissingSchema(err) {
			return &ParentError{Field: field}
		}
		return err
	}
	if !exists {
		return &ParentError{Field: field}
	}
	return nil
}

func regionList(ctx context.Context, d *db.DB, query string, args ...any) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, query, args...)
	if err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, name string
		if err := rows.Scan(&id, &name); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": id, "name": name})
	}
	return out, rows.Err()
}

// ---- agent directory -------------------------------------------------------------------------------

// Agents returns the public agent directory (AgentContactController::index → AgentContactResource):
// active agents only, and ONLY name/address/phone/coordinates/referral_code — never e-mail or status.
func Agents(ctx context.Context, d *db.DB) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT p.store_name, p.address, p.phone, p.latitude, p.longitude, u.referral_code
		FROM agent_profiles p
		JOIN users u ON u.id = p.user_id
		WHERE u.status = 'active' AND u.deleted_at IS NULL
		ORDER BY p.id ASC`)
	if err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			storeName, address, phone sql.NullString
			lat, lng                  sql.NullString
			referral                  sql.NullString
		)
		if err := rows.Scan(&storeName, &address, &phone, &lat, &lng, &referral); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"name":          nullStr(storeName),
			"address":       nullStr(address),
			"phone":         nullStr(phone),
			"latitude":      nullStr(lat),
			"longitude":     nullStr(lng),
			"referral_code": nullStr(referral),
		})
	}
	return out, rows.Err()
}

// ---- install status --------------------------------------------------------------------------------

// InstallStatus mirrors InstallController::status.
type InstallStatus struct {
	Installed     bool `json:"installed"`
	HasTables     bool `json:"has_tables"`
	HasSuperAdmin bool `json:"has_super_admin"`
}

// Status reports installer state. `installed` is the InstallLock file, which is the SAME marker Laravel
// writes — the two runtimes therefore agree on whether the deployment is installed.
func Status(ctx context.Context, d *db.DB, lockPath string) (InstallStatus, error) {
	out := InstallStatus{}
	if lockPath != "" {
		if _, err := os.Stat(lockPath); err == nil {
			out.Installed = true
		}
	}
	var hasTables bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM information_schema.tables
		WHERE table_schema = DATABASE() AND table_name = 'roles')`).Scan(&hasTables); err == nil {
		out.HasTables = hasTables
	}
	if out.HasTables {
		var hasSuper bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
			WHERE r.slug = 'super_admin')`).Scan(&hasSuper); err == nil {
			out.HasSuperAdmin = hasSuper
		}
	}
	return out, nil
}

// ---- helpers ---------------------------------------------------------------------------------------

func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

// NowUTC is exposed so handlers and tests agree on the clock used for date-bounded reads.
func NowUTC() time.Time { return time.Now().UTC() }
