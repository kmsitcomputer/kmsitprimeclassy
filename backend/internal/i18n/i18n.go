// Package i18n resolves the message catalogue the API returns.
//
// The checked-in catalog.json preserves the verified API messages for each supported locale. Tests pin all
// required keys and reject empty values without depending on a PHP/Laravel source tree.
//
// Locale resolution mirrors App\Http\Middleware\SetLocale: the `X-Locale` request header first, then
// `Accept-Language`, then the default (`id`).
package i18n

import (
	_ "embed"
	"encoding/json"
	"strings"
	"sync"
)

//go:embed catalog.json
var catalogJSON []byte

// Default is the fallback locale used by the established API contract.
const Default = "id"

var (
	once    sync.Once
	catalog map[string]map[string]string
	loadErr error
)

func load() {
	catalog = make(map[string]map[string]string)
	loadErr = json.Unmarshal(catalogJSON, &catalog)
}

// Translate returns the message for key in the given locale, falling back to the default locale and then
// to the key itself (so a missing translation is visible, never silently empty).
func Translate(locale, key string) string {
	once.Do(load)
	if m, ok := catalog[locale]; ok {
		if v, ok := m[key]; ok && v != "" {
			return v
		}
	}
	if m, ok := catalog[Default]; ok {
		if v, ok := m[key]; ok && v != "" {
			return v
		}
	}
	return key
}

// ValidationMessage renders an API validation sentence for a rule, substituting :attribute with the field
// name humanised the same way Laravel does (underscores become spaces: province_id → "province id").
//
// Extra parameters (:max, :min, :values) are substituted from params so the sentences stay byte-identical to
// what the reference validation layer emits.
func ValidationMessage(locale, rule, field string, params ...string) string {
	template := Translate(locale, "validation."+rule)
	out := strings.ReplaceAll(template, ":attribute", AttributeName(locale, field))
	for i := 0; i+1 < len(params); i += 2 {
		out = strings.ReplaceAll(out, ":"+params[i], params[i+1])
	}
	return out
}

// AttributeName renders the established display name for a field: validation.attributes[field] when the
// catalogue defines one (e.g. recipient_name → "nama penerima"), otherwise the field with underscores turned
// into spaces (Laravel's Str::snake → headline fallback).
func AttributeName(locale, field string) string {
	key := "attributes." + field
	once.Do(load)
	for _, loc := range []string{locale, Default} {
		if m, ok := catalog[loc]; ok {
			if v, ok := m[key]; ok && v != "" {
				return v
			}
		}
	}
	return strings.ReplaceAll(field, "_", " ")
}

// ResolveLocale mirrors the backend's SetLocale precedence.
func ResolveLocale(header string, acceptLanguage string) string {
	once.Do(load)
	candidate := strings.ToLower(strings.TrimSpace(header))
	if candidate == "" {
		// Accept-Language: "en-US,en;q=0.9,id;q=0.8" → first tag, primary subtag.
		first := strings.Split(acceptLanguage, ",")[0]
		first = strings.Split(first, ";")[0]
		candidate = strings.ToLower(strings.TrimSpace(first))
	}
	if i := strings.IndexAny(candidate, "-_"); i > 0 {
		candidate = candidate[:i]
	}
	if _, ok := catalog[candidate]; ok {
		return candidate
	}
	return Default
}

// Supported reports whether a locale has a catalogue.
func Supported(locale string) bool {
	once.Do(load)
	_, ok := catalog[locale]
	return ok
}

// Locales lists the catalogues (used by tests and the /api/v1/_parity report).
func Locales() []string {
	once.Do(load)
	out := make([]string, 0, len(catalog))
	for k := range catalog {
		out = append(out, k)
	}
	return out
}

// LoadError reports a corrupt embedded catalogue (only ever non-nil if the embedded JSON is broken, which
// the catalogue test forbids).
func LoadError() error {
	once.Do(load)
	return loadErr
}
