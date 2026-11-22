package api

import (
	"context"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

var cmsSlideKey = regexp.MustCompile(`^[A-Za-z0-9_-]{6,40}$`)
var cmsPhone = regexp.MustCompile(`^[1-9][0-9]{7,14}$`)
var cmsSafeLink = regexp.MustCompile(`(?i)^(?:https?://[^\s<>"'\\]+|mailto:[^\s<>"'\\]+|tel:\+?[0-9][0-9\-\s().]{3,30}|/[^\s<>"'\\]*|#[^\s<>"'\\]*)$`)

func validateHomepageBlockContent(ctx context.Context, d *db.DB, typ *string, content map[string]any) error {
	return validateHomepageBlockContentMode(ctx, d, typ, content, false)
}

func validateHomepageBlockContentUpdate(ctx context.Context, d *db.DB, typ *string, content map[string]any) error {
	return validateHomepageBlockContentMode(ctx, d, typ, content, true)
}

func validateHomepageBlockContentMode(ctx context.Context, d *db.DB, typ *string, content map[string]any, partial bool) error {
	if typ == nil || !isCMSBlockType(*typ) {
		return nil
	}
	if content == nil {
		content = map[string]any{}
	}
	errs := fieldErrors{}
	req := func(key string, max int) {
		field := "content." + key
		v, ok := content[key]
		if !ok && partial {
			return
		}
		if !ok || v == nil || v == "" {
			if partial {
				errs.add(field, vmsg(ctx, "string", field))
				return
			}
			errs.add(field, vmsg(ctx, "required", field))
			return
		}
		text, ok := v.(string)
		if !ok {
			errs.add(field, vmsg(ctx, "string", field))
			return
		}
		if max > 0 && utf8.RuneCountInString(text) > max {
			errs.add(field, vmsg(ctx, "max.string", field, "max", strconv.Itoa(max)))
		}
	}
	opt := func(key string, max int) {
		field := "content." + key
		v, ok := content[key]
		if !ok || v == nil || v == "" {
			return
		}
		text, ok := v.(string)
		if !ok {
			errs.add(field, vmsg(ctx, "string", field))
			return
		}
		if max > 0 && utf8.RuneCountInString(text) > max {
			errs.add(field, vmsg(ctx, "max.string", field, "max", strconv.Itoa(max)))
		}
	}
	allowed := func(key string, values ...string) {
		v, ok := content[key]
		if !ok || v == nil || v == "" {
			return
		}
		text, ok := v.(string)
		for _, candidate := range values {
			if ok && text == candidate {
				return
			}
		}
		errs.add("content."+key, vmsg(ctx, "in", "content."+key))
	}
	link := func(key string, max int) {
		opt(key, max)
		if value, ok := content[key].(string); ok && value != "" && (!cmsSafeLink.MatchString(value) || strings.HasPrefix(value, "//") || strings.ContainsAny(value, "\x00\r\n\t")) {
			errs.add("content."+key, "Tautan harus berupa alamat http(s), mailto:, tel:, path situs (/…) atau anchor (#…) yang valid.")
		}
	}
	array := func(key string, required bool, max int) []any {
		field := "content." + key
		v, exists := content[key]
		if !exists || v == nil || v == "" {
			if required && !partial {
				errs.add(field, vmsg(ctx, "required", field))
			} else if partial && exists {
				errs.add(field, vmsg(ctx, "array", field))
			}
			return nil
		}
		items, ok := v.([]any)
		if !ok {
			errs.add(field, vmsg(ctx, "array", field))
			return nil
		}
		if required && len(items) == 0 {
			if partial {
				errs.add(field, vmsg(ctx, "min.array", field, "min", "1"))
			} else {
				errs.add(field, vmsg(ctx, "required", field))
			}
		}
		if max > 0 && len(items) > max {
			errs.add(field, vmsg(ctx, "max.array", field, "max", strconv.Itoa(max)))
		}
		return items
	}
	integer := func(key string, required bool, min, max int, enum ...int) {
		field := "content." + key
		v, exists := content[key]
		if !exists || v == nil || v == "" {
			if required && !partial {
				errs.add(field, vmsg(ctx, "required", field))
			} else if partial && exists {
				errs.add(field, cmsFieldVmsg(ctx, "integer", field))
			}
			return
		}
		n, ok := cmsInt(v)
		if !ok {
			errs.add(field, cmsFieldVmsg(ctx, "integer", field))
			return
		}
		if min > 0 && n < min {
			errs.add(field, vmsg(ctx, "min.numeric", field, "min", strconv.Itoa(min)))
		}
		if max > 0 && n > max {
			errs.add(field, vmsg(ctx, "max.numeric", field, "max", strconv.Itoa(max)))
		}
		if len(enum) > 0 {
			for _, x := range enum {
				if n == x {
					return
				}
			}
			errs.add(field, vmsg(ctx, "in", field))
		}
	}
	boolean := func(key string, required bool) {
		field := "content." + key
		v, exists := content[key]
		if !exists || v == nil || v == "" {
			if required && !partial {
				errs.add(field, vmsg(ctx, "required", field))
			} else if partial && exists {
				errs.add(field, vmsg(ctx, "boolean", field))
			}
			return
		}
		if !cmsBool(v) {
			errs.add(field, vmsg(ctx, "boolean", field))
		}
	}
	ids := func(key, table string, required bool) {
		items := array(key, required, 0)
		for i, raw := range items {
			field := "content." + key + "." + strconv.Itoa(i)
			id, ok := cmsInt(raw)
			if !ok {
				errs.add(field, cmsFieldVmsg(ctx, "integer", field))
				continue
			}
			var exists bool
			if e := d.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM "+table+" WHERE id = ?)", id).Scan(&exists); e == nil && !exists {
				errs.add(field, cmsFieldVmsg(ctx, "exists", field))
			}
		}
	}
	switch *typ {
	case "hero":
		req("heading", 150)
		opt("subheading", 255)
		opt("cta_label", 50)
		link("cta_url", 255)
		allowed("image_fit", "cover", "contain")
		allowed("image_position", "center", "top", "bottom", "left", "right")
		allowed("overlay", "none", "light", "dark")
		allowed("align", "left", "center")
	case "banner":
		link("url", 255)
	case "category":
		opt("heading", 150)
		ids("category_ids", "product_categories", true)
	case "product":
		opt("heading", 150)
		integer("category_id", false, 0, 0)
		if value, ok := content["category_id"]; ok && value != nil && value != "" {
			if id, good := cmsInt(value); good {
				var exists bool
				if d.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM product_categories WHERE id = ?)", id).Scan(&exists) == nil && !exists {
					errs.add("content.category_id", vmsg(ctx, "exists", "content.category_id"))
				}
			}
		}
		if _, ok := content["product_ids"]; ok {
			ids("product_ids", "products", false)
		}
		integer("limit", false, 1, 24)
	case "bestseller":
		opt("heading", 150)
		allowed("period", "7", "30", "90", "all")
		if value, ok := content["period"]; !ok || value == nil || value == "" {
			if !partial {
				errs.add("content.period", vmsg(ctx, "required", "content.period"))
			} else if ok {
				errs.add("content.period", vmsg(ctx, "in", "content.period"))
			}
		}
		integer("limit", true, 0, 0, 4, 8, 12)
	case "promotional":
		req("heading", 150)
		opt("description", 500)
		link("url", 255)
	case "text":
		opt("heading", 150)
		req("body", 5000)
	case "image":
		opt("alt", 150)
		link("url", 255)
	case "article":
		opt("heading", 150)
		ids("article_ids", "cms_articles", true)
	case "cta":
		req("heading", 150)
		req("button_label", 50)
		req("button_url", 255)
		link("button_url", 255)
		opt("description", 300)
		allowed("theme", "brand", "dark", "light", "sand")
		allowed("align", "left", "center")
	case "custom":
		req("html", 20000)
	case "social_media", "marketplace_links":
		opt("heading", 150)
		opt("description", 500)
		if *typ == "social_media" {
			allowed("layout", "cards", "compact")
		} else {
			allowed("layout", "cards", "horizontal")
		}
		if value, ok := content["layout"]; !ok || value == nil || value == "" {
			if !partial {
				errs.add("content.layout", vmsg(ctx, "required", "content.layout"))
			} else if ok {
				errs.add("content.layout", vmsg(ctx, "in", "content.layout"))
			}
		}
		platforms, ok := content["platforms"].(map[string]any)
		if !ok {
			if content["platforms"] == nil || content["platforms"] == "" {
				if !partial || content["platforms"] != nil {
					errs.add("content.platforms", vmsg(ctx, "required", "content.platforms"))
				}
			} else {
				errs.add("content.platforms", vmsg(ctx, "array", "content.platforms"))
			}
			platforms = map[string]any{}
		}
		if partial && !ok {
			break
		}
		domains := map[string]string{"instagram": "instagram.com", "tiktok": "tiktok.com", "facebook": "facebook.com", "tokopedia": "tokopedia.com", "shopee": "shopee.co.id"}
		names := []string{"instagram", "tiktok", "facebook"}
		if *typ == "marketplace_links" {
			names = []string{"tokopedia", "shopee"}
		}
		for _, name := range names {
			field := "content.platforms." + name + ".enabled"
			config, ok := platforms[name].(map[string]any)
			if !ok {
				if !partial {
					errs.add(field, vmsg(ctx, "required", field))
				}
				continue
			}
			v, ok := config["enabled"]
			if !ok || v == nil || v == "" {
				if !partial {
					errs.add(field, vmsg(ctx, "required", field))
				} else if ok {
					errs.add(field, vmsg(ctx, "boolean", field))
				}
			} else if !cmsBool(v) {
				errs.add(field, vmsg(ctx, "boolean", field))
			}
			if raw, ok := config["url"]; ok && raw != nil && raw != "" {
				field = "content.platforms." + name + ".url"
				text, ok := raw.(string)
				if !ok {
					errs.add(field, vmsg(ctx, "string", field))
					continue
				}
				if utf8.RuneCountInString(text) > 500 {
					errs.add(field, vmsg(ctx, "max.string", field, "max", "500"))
				}
				parsed, e := url.Parse(text)
				if e != nil || parsed.Scheme != "https" || parsed.Hostname() == "" {
					errs.add(field, vmsg(ctx, "url", field))
				}
				host := ""
				if parsed != nil {
					host = strings.ToLower(parsed.Hostname())
				}
				domain := domains[name]
				if host != domain && !strings.HasSuffix(host, "."+domain) {
					errs.add(field, vmsg(ctx, "regex", field))
				}
			}
		}
	case "whatsapp_chat":
		opt("heading", 150)
		opt("description", 500)
		req("phone", 0)
		if p, ok := content["phone"].(string); ok && p != "" && !cmsPhone.MatchString(p) {
			errs.add("content.phone", vmsg(ctx, "regex", "content.phone"))
		}
		opt("message", 1000)
		req("button_label", 50)
		allowed("mode", "card", "floating")
		if value, ok := content["mode"]; !ok || value == nil || value == "" {
			if !partial {
				errs.add("content.mode", vmsg(ctx, "required", "content.mode"))
			} else if ok {
				errs.add("content.mode", vmsg(ctx, "in", "content.mode"))
			}
		}
	case "banner_slider":
		allowed("layout", "contained", "wide")
		if value, ok := content["layout"]; !ok || value == nil || value == "" {
			if !partial {
				errs.add("content.layout", vmsg(ctx, "required", "content.layout"))
			} else if ok {
				errs.add("content.layout", vmsg(ctx, "in", "content.layout"))
			}
		}
		boolean("autoplay", true)
		integer("interval_seconds", true, 3, 10)
		boolean("show_arrows", true)
		boolean("show_dots", true)
		slides := array("slides", true, 10)
		seen := map[string]int{}
		duplicateReported := map[string]bool{}
		for i, raw := range slides {
			field := "content.slides." + strconv.Itoa(i)
			slide, ok := raw.(map[string]any)
			if !ok {
				errs.add(field+".key", cmsFieldVmsg(ctx, "required", field+".key"))
				continue
			}
			key, ok := slide["key"].(string)
			kf := field + ".key"
			if !ok || key == "" {
				errs.add(kf, cmsFieldVmsg(ctx, "required", kf))
			} else {
				if !cmsSlideKey.MatchString(key) {
					errs.add(kf, cmsFieldVmsg(ctx, "regex", kf))
				}
				if first, ok := seen[key]; ok {
					firstField := "content.slides." + strconv.Itoa(first) + ".key"
					if !duplicateReported[firstField] {
						errs.add(firstField, cmsFieldVmsg(ctx, "distinct", firstField))
						duplicateReported[firstField] = true
					}
					if !duplicateReported[kf] {
						errs.add(kf, cmsFieldVmsg(ctx, "distinct", kf))
						duplicateReported[kf] = true
					}
				} else {
					seen[key] = i
				}
			}
			for name, max := range map[string]int{"title": 150, "description": 300, "alt": 150, "button_label": 50} {
				nestedOptionalString(ctx, errs, field+"."+name, slide[name], max)
			}
			nestedSafeLink(ctx, errs, field+".button_url", slide["button_url"], 255)
			nestedIn(ctx, errs, field+".overlay", slide["overlay"], "none", "light", "dark")
			nestedIn(ctx, errs, field+".content_position", slide["content_position"], "left", "center", "right")
			if v, ok := slide["remove_mobile"]; ok && v != nil && v != "" && !cmsBool(v) {
				errs.add(field+".remove_mobile", cmsFieldVmsg(ctx, "boolean", field+".remove_mobile"))
			}
		}
	}
	return errs.fail(ctx)
}

func isCMSBlockType(t string) bool {
	switch t {
	case "hero", "banner", "category", "product", "bestseller", "promotional", "text", "image", "article", "cta", "custom", "social_media", "whatsapp_chat", "marketplace_links", "banner_slider":
		return true
	}
	return false
}
func cmsInt(v any) (int, bool) {
	switch n := v.(type) {
	case int:
		return n, true
	case int64:
		return int(n), true
	case float64:
		if n == float64(int(n)) {
			return int(n), true
		}
	case string:
		n2, e := strconv.Atoi(n)
		return n2, e == nil
	}
	return 0, false
}
func cmsBool(v any) bool {
	switch x := v.(type) {
	case bool:
		return true
	case float64:
		return x == 0 || x == 1
	case int:
		return x == 0 || x == 1
	case string:
		return x == "0" || x == "1" || x == "true" || x == "false"
	}
	return false
}
func nestedOptionalString(ctx context.Context, errs fieldErrors, field string, value any, max int) {
	if value == nil || value == "" {
		return
	}
	text, ok := value.(string)
	if !ok {
		errs.add(field, cmsFieldVmsg(ctx, "string", field))
		return
	}
	if utf8.RuneCountInString(text) > max {
		errs.add(field, cmsFieldVmsg(ctx, "max.string", field, "max", strconv.Itoa(max)))
	}
}
func nestedSafeLink(ctx context.Context, errs fieldErrors, field string, value any, max int) {
	if value == nil || value == "" {
		return
	}
	text, ok := value.(string)
	if !ok {
		errs.add(field, cmsFieldVmsg(ctx, "string", field))
		return
	}
	if utf8.RuneCountInString(text) > max {
		errs.add(field, cmsFieldVmsg(ctx, "max.string", field, "max", strconv.Itoa(max)))
	}
	if !cmsSafeLink.MatchString(text) || strings.HasPrefix(text, "//") || strings.ContainsAny(text, "\x00\r\n\t") {
		errs.add(field, "Tautan harus berupa alamat http(s), mailto:, tel:, path situs (/…) atau anchor (#…) yang valid.")
	}
}
func nestedIn(ctx context.Context, errs fieldErrors, field string, value any, choices ...string) {
	if value == nil || value == "" {
		return
	}
	text, ok := value.(string)
	if ok {
		for _, x := range choices {
			if text == x {
				return
			}
		}
	}
	errs.add(field, cmsFieldVmsg(ctx, "in", field))
}

var cmsNestedIndex = regexp.MustCompile(`\.\d+(?:\.|$)`)

func cmsFieldVmsg(ctx context.Context, rule, field string, params ...string) string {
	if !cmsNestedIndex.MatchString(field) {
		return vmsg(ctx, rule, field, params...)
	}
	message := i18n.Translate(LocaleFrom(ctx), "validation."+rule)
	message = strings.ReplaceAll(message, ":attribute", field)
	for i := 0; i+1 < len(params); i += 2 {
		message = strings.ReplaceAll(message, ":"+params[i], params[i+1])
	}
	return message
}
