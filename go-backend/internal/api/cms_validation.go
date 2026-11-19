package api

// CMS admin article/page validation — StoreArticleRequest / UpdateArticleRequest with Laravel's
// nested-array sentence paths (translations.0.language_id) and raw-path attribute names (Laravel does
// NOT humanise nested paths, unlike top-level fields).

import (
	"context"
	"encoding/json"
	"sort"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// nestedMsg renders a Laravel sentence for a TOP-LEVEL field (:attribute resolved through
// validation.attributes — observed "tipe wajib diisi." for the type field).
func nestedMsg(ctx context.Context, rule, path string, params ...string) string {
	template := i18n.Translate(LocaleFrom(ctx), "validation."+rule)
	out := strings.ReplaceAll(template, ":attribute", i18n.AttributeName(LocaleFrom(ctx), path))
	for i := 0; i+1 < len(params); i += 2 {
		out = strings.ReplaceAll(out, ":"+params[i], params[i+1])
	}
	return out
}

// nestedRawMsg renders a sentence for a NESTED path (Laravel does NOT humanise those — observed
// "translations.1.language_id wajib diisi.").
func nestedRawMsg(ctx context.Context, rule, path string, params ...string) string {
	template := i18n.Translate(LocaleFrom(ctx), "validation."+rule)
	out := strings.ReplaceAll(template, ":attribute", path)
	for i := 0; i+1 < len(params); i += 2 {
		out = strings.ReplaceAll(out, ":"+params[i], params[i+1])
	}
	return out
}

// cmsTranslationPath is one decoded translations entry with the JSON path it came from (object keys are
// kept verbatim: `{"1": {...}}` validates under translations.1.*).
type cmsTranslationPath struct {
	Path       string
	LanguageID *int64
	HasLangID  bool
	Title      *string
	Excerpt    *string
	Body       *string
}

// decodeCMSTranslations walks the raw translations value: a LIST (paths translations.<index>.*) or an
// OBJECT (paths translations.<key>.*). Laravel's `translations.*` rule iterates values but keeps the
// ORIGINAL key in the error path — observed "translations.1.language_id wajib diisi." for object input.
func decodeCMSTranslations(raw json.RawMessage) []cmsTranslationPath {
	var asList []map[string]json.RawMessage
	if err := json.Unmarshal(raw, &asList); err == nil {
		paths := make([]cmsTranslationPath, 0, len(asList))
		for i, item := range asList {
			paths = append(paths, cmsTranslationEntry("translations."+strconv.Itoa(i), item))
		}
		return paths
	}
	var asObject map[string]map[string]json.RawMessage
	if err := json.Unmarshal(raw, &asObject); err == nil {
		paths := make([]cmsTranslationPath, 0, len(asObject))
		for key, item := range asObject {
			paths = append(paths, cmsTranslationEntry("translations."+key, item))
		}
		return paths
	}
	return nil
}

func cmsTranslationEntry(path string, item map[string]json.RawMessage) cmsTranslationPath {
	entry := cmsTranslationPath{Path: path}
	if value, ok := item["language_id"]; ok && string(value) != "null" {
		var id int64
		if json.Unmarshal(value, &id) == nil {
			entry.LanguageID = &id
			entry.HasLangID = true
		}
	}
	if value, ok := item["title"]; ok && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) == nil {
			entry.Title = &text
		}
	}
	if value, ok := item["excerpt"]; ok && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) == nil {
			entry.Excerpt = &text
		}
	}
	if value, ok := item["body"]; ok && string(value) != "null" {
		var text string
		if json.Unmarshal(value, &text) == nil {
			entry.Body = &text
		}
	}
	return entry
}

func cmsStringRule(ctx context.Context, fields map[string][]string, path string, value *string, max int) {
	if value == nil {
		return
	}
	if utf8Len(*value) > max {
		fields[path] = append(fields[path], nestedRawMsg(ctx, "max.string", path, "max", strconv.Itoa(max)))
	}
}

func utf8Len(s string) int { return len([]rune(s)) }

// validateCMSArticle is Store/UpdateArticleRequest::rules. `create` selects the required vs
// required_with variants and whether slug/type are mandatory.
func validateCMSArticle(ctx context.Context, s *Server, raw map[string]json.RawMessage, create bool,
	currentID int64) (map[string][]string, []cmsTranslationPath) {

	fields := map[string][]string{}
	typeField := "type"
	if _, present := raw["type"]; !present || string(raw["type"]) == "null" {
		if create {
			fields[typeField] = append(fields[typeField], nestedMsg(ctx, "required", typeField))
		}
	} else if text, ok := rawString(jsonValue(raw, "type")); !ok || (text != "article" && text != "news") {
		fields[typeField] = append(fields[typeField], nestedMsg(ctx, "in", typeField))
	}

	if _, present := raw["slug"]; !present || string(raw["slug"]) == "null" {
		if create {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "required", "slug"))
		}
	} else if text, ok := rawString(jsonValue(raw, "slug")); ok {
		if text == "" {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "required", "slug"))
		} else if utf8Len(text) > 180 {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "max.string", "slug", "max", "180"))
		} else if !cmsSlugFree(ctx, s, text, currentID) {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "unique", "slug"))
		}
	}

	if value, present := raw["status"]; present && string(value) != "null" {
		if text, ok := rawString(jsonValue(raw, "status")); !ok || (text != "draft" && text != "published") {
			fields["status"] = append(fields["status"], nestedMsg(ctx, "in", "status"))
		}
	}
	if value, present := raw["published_at"]; present && string(value) != "null" {
		if _, ok := rawString(jsonValue(raw, "published_at")); !ok {
			fields["published_at"] = append(fields["published_at"], nestedMsg(ctx, "date", "published_at"))
		}
	}
	if value, present := raw["cover_media_id"]; present && string(value) != "null" {
		if id, ok := intValue(jsonValue(raw, "cover_media_id")); !ok {
			fields["cover_media_id"] = append(fields["cover_media_id"], nestedMsg(ctx, "integer", "cover media"))
		} else if !existsIn(ctx, s.database, "media", "id", formatID(int64(id))) {
			fields["cover_media_id"] = append(fields["cover_media_id"], nestedMsg(ctx, "exists", "cover media"))
		}
	}

	// translations: required (+ array + min:1) on store, required_with on update.
	var translations []cmsTranslationPath
	if value, present := raw["translations"]; present && string(value) != "null" {
		translations = decodeCMSTranslations(value)
		if len(translations) == 0 {
			fields["translations"] = append(fields["translations"], nestedMsg(ctx, "array", "translations"))
		}
	} else if create {
		fields["translations"] = append(fields["translations"], nestedMsg(ctx, "required", "translations"))
	}

	// The distinct rule flags EVERY duplicate occurrence (observed: both translations.0.language_id and
	// translations.1.language_id carry the sentence on a two-identical-rows payload).
	counts := map[int64]int{}
	for _, entry := range translations {
		if entry.HasLangID {
			counts[*entry.LanguageID]++
		}
	}
	for _, entry := range translations {
		if entry.HasLangID {
			if !existsIn(ctx, s.database, "languages", "id", formatID(*entry.LanguageID)) {
				fields[entry.Path+".language_id"] = append(fields[entry.Path+".language_id"],
					nestedRawMsg(ctx, "exists", entry.Path+".language_id"))
			}
			if counts[*entry.LanguageID] > 1 {
				fields[entry.Path+".language_id"] = append(fields[entry.Path+".language_id"],
					nestedRawMsg(ctx, "distinct", entry.Path+".language_id"))
			}
		} else {
			// required on store, required_with:translations on update — but when translations IS present
			// both forms demand the field.
			fields[entry.Path+".language_id"] = append(fields[entry.Path+".language_id"],
				nestedRawMsg(ctx, "required", entry.Path+".language_id"))
		}
		if entry.Title == nil {
			fields[entry.Path+".title"] = append(fields[entry.Path+".title"],
				nestedRawMsg(ctx, "required", entry.Path+".title"))
		} else {
			cmsStringRule(ctx, fields, entry.Path+".title", entry.Title, 200)
		}
		cmsStringRule(ctx, fields, entry.Path+".excerpt", entry.Excerpt, 300)
		if entry.Body == nil {
			fields[entry.Path+".body"] = append(fields[entry.Path+".body"],
				nestedMsg(ctx, "required", entry.Path+".body"))
		}
	}
	return fields, translations
}

func jsonValue(raw map[string]json.RawMessage, field string) json.RawMessage { return raw[field] }

// rawString decodes a JSON string field from a raw map (json.RawMessage is a []byte, so stringValue's
// string case never matches — decode explicitly).
func rawString(raw json.RawMessage) (string, bool) {
	var text string
	if err := json.Unmarshal(raw, &text); err != nil {
		return "", false
	}
	return text, true
}

// cmsSlugFree is `unique:cms_articles,slug` (soft-deleted rows still count, like the bare unique rule).
func cmsSlugFree(ctx context.Context, s *Server, slug string, exceptID int64) bool {
	return !existsWhere(ctx, s.database, "cms_articles", "slug", slug, exceptID)
}

type cmsPageTranslationPath struct {
	cmsTranslationPath
	SEOTitle       *string
	SEODescription *string
}

// decodeCMSPageTranslations decodes the page translation shape (body nullable + seo_* fields).
func decodeCMSPageTranslations(raw json.RawMessage) []cmsPageTranslationPath {
	base := decodeCMSTranslations(raw)
	out := make([]cmsPageTranslationPath, 0, len(base))
	var asList []map[string]json.RawMessage
	var asObject map[string]map[string]json.RawMessage
	_ = json.Unmarshal(raw, &asList)
	_ = json.Unmarshal(raw, &asObject)
	var keys []string
	if len(asList) > 0 {
		for i := range asList {
			keys = append(keys, strconv.Itoa(i))
		}
	} else {
		for key := range asObject {
			keys = append(keys, key)
		}
		sort.Strings(keys)
	}
	for i, entry := range base {
		page := cmsPageTranslationPath{cmsTranslationPath: entry}
		var item map[string]json.RawMessage
		if len(asList) > 0 && i < len(asList) {
			item = asList[i]
		} else if i < len(keys) {
			item = asObject[keys[i]]
		}
		if value, ok := item["seo_title"]; ok && string(value) != "null" {
			var text string
			if json.Unmarshal(value, &text) == nil {
				page.SEOTitle = &text
			}
		}
		if value, ok := item["seo_description"]; ok && string(value) != "null" {
			var text string
			if json.Unmarshal(value, &text) == nil {
				page.SEODescription = &text
			}
		}
		out = append(out, page)
	}
	return out
}

// validateCMSPage is Store/UpdatePageRequest::rules (slug max 150, title required, body NULLABLE,
// seo_title 200, seo_description 300).
func validateCMSPage(ctx context.Context, s *Server, raw map[string]json.RawMessage, create bool,
	currentID int64) (map[string][]string, []cmsPageTranslationPath) {

	fields := map[string][]string{}
	if _, present := raw["slug"]; !present || string(raw["slug"]) == "null" {
		if create {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "required", "slug"))
		}
	} else if text, ok := rawString(jsonValue(raw, "slug")); ok {
		if text == "" {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "required", "slug"))
		} else if utf8Len(text) > 150 {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "max.string", "slug", "max", "150"))
		} else if existsWhere(ctx, s.database, "cms_pages", "slug", text, currentID) {
			fields["slug"] = append(fields["slug"], nestedMsg(ctx, "unique", "slug"))
		}
	}
	if value, present := raw["status"]; present && string(value) != "null" {
		if text, ok := rawString(jsonValue(raw, "status")); !ok || (text != "draft" && text != "published") {
			fields["status"] = append(fields["status"], nestedMsg(ctx, "in", "status"))
		}
	}
	if value, present := raw["published_at"]; present && string(value) != "null" {
		if _, ok := rawString(jsonValue(raw, "published_at")); !ok {
			fields["published_at"] = append(fields["published_at"], nestedMsg(ctx, "date", "published_at"))
		}
	}
	if value, present := raw["cover_media_id"]; present && string(value) != "null" {
		if id, ok := intValue(jsonValue(raw, "cover_media_id")); !ok {
			fields["cover_media_id"] = append(fields["cover_media_id"], nestedMsg(ctx, "integer", "cover media"))
		} else if !existsIn(ctx, s.database, "media", "id", formatID(int64(id))) {
			fields["cover_media_id"] = append(fields["cover_media_id"], nestedMsg(ctx, "exists", "cover media"))
		}
	}
	var translations []cmsPageTranslationPath
	if value, present := raw["translations"]; present && string(value) != "null" {
		translations = decodeCMSPageTranslations(value)
		if len(translations) == 0 {
			fields["translations"] = append(fields["translations"], nestedMsg(ctx, "array", "translations"))
		}
	} else if create {
		fields["translations"] = append(fields["translations"], nestedMsg(ctx, "required", "translations"))
	}
	counts := map[int64]int{}
	for _, entry := range translations {
		if entry.HasLangID {
			counts[*entry.LanguageID]++
		}
	}
	for _, entry := range translations {
		if entry.HasLangID {
			if !existsIn(ctx, s.database, "languages", "id", formatID(*entry.LanguageID)) {
				fields[entry.Path+".language_id"] = append(fields[entry.Path+".language_id"],
					nestedRawMsg(ctx, "exists", entry.Path+".language_id"))
			}
			if counts[*entry.LanguageID] > 1 {
				fields[entry.Path+".language_id"] = append(fields[entry.Path+".language_id"],
					nestedRawMsg(ctx, "distinct", entry.Path+".language_id"))
			}
		} else {
			fields[entry.Path+".language_id"] = append(fields[entry.Path+".language_id"],
				nestedRawMsg(ctx, "required", entry.Path+".language_id"))
		}
		if entry.Title == nil {
			fields[entry.Path+".title"] = append(fields[entry.Path+".title"],
				nestedRawMsg(ctx, "required", entry.Path+".title"))
		} else if utf8Len(*entry.Title) > 200 {
			fields[entry.Path+".title"] = append(fields[entry.Path+".title"],
				nestedRawMsg(ctx, "max.string", entry.Path+".title", "max", "200"))
		}
		// body is NULLABLE for pages (unlike articles).
		if entry.Body != nil {
			// no max rule
		}
		if entry.SEOTitle != nil && utf8Len(*entry.SEOTitle) > 200 {
			fields[entry.Path+".seo_title"] = append(fields[entry.Path+".seo_title"],
				nestedRawMsg(ctx, "max.string", entry.Path+".seo_title", "max", "200"))
		}
		if entry.SEODescription != nil && utf8Len(*entry.SEODescription) > 300 {
			fields[entry.Path+".seo_description"] = append(fields[entry.Path+".seo_description"],
				nestedRawMsg(ctx, "max.string", entry.Path+".seo_description", "max", "300"))
		}
	}
	return fields, translations
}

// existsWhere is existsIn with an id exclusion (Rule::unique(...)->ignore($id)).
func existsWhere(ctx context.Context, d *db.DB, table, column, value string, exceptID int64) bool {
	var exists bool
	query := "SELECT EXISTS(SELECT 1 FROM `" + table + "` WHERE `" + column + "` = ?"
	args := []any{value}
	if exceptID > 0 {
		query += " AND id <> ?"
		args = append(args, exceptID)
	}
	query += ")"
	if err := d.QueryRowContext(ctx, query, args...).Scan(&exists); err != nil {
		return false
	}
	return exists
}
