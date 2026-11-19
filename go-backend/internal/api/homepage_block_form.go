package api

import (
	"context"
	"io"
	"mime/multipart"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/cms"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

var (
	phpFormPath = regexp.MustCompile(`^([^\[]+)((?:\[[^\]]*\])*)$`)
	phpFormPart = regexp.MustCompile(`\[([^\]]*)\]`)
	slideUpload = regexp.MustCompile(`^slide_images\[([^\]]+)\]\[(desktop|mobile)\]$`)
)

func decodeHomepageBlockMultipart(r *http.Request) (cms.BlockInput, cms.BlockUploads, error) {
	if err := r.ParseMultipartForm(32 << 20); err != nil {
		return cms.BlockInput{}, cms.BlockUploads{}, httpx.NewError(http.StatusBadRequest, i18n.Translate(i18n.Default, "system.generic_error"))
	}
	root, errs := map[string]any{}, fieldErrors{}
	for key, values := range r.MultipartForm.Value {
		if len(values) == 0 {
			continue
		}
		path, ok := parsePHPFormPath(key)
		if !ok {
			continue
		}
		var value any
		if values[0] != "" {
			value = values[0]
		}
		setPHPFormPath(root, path, value)
	}
	for key := range root {
		root[key] = finalizePHPFormValue(root[key])
	}
	input := cms.BlockInput{}
	uploads := cms.BlockUploads{SlideImages: map[string]map[string]*media.Upload{}}
	_, input.ContentSet = root["content"]
	if value, ok := root["type"].(string); ok {
		input.Type = &value
	}
	if value, ok := root["content"].(map[string]any); ok {
		normalizeHomepageFormContent(root["type"], value)
		input.Content = value
	}
	if _, exists := root["sort_order"]; exists && root["sort_order"] == nil {
		sortOrder := 0
		input.SortOrder = &sortOrder
	} else if value, ok := root["sort_order"].(string); ok {
		if n, err := strconv.Atoi(value); err == nil {
			input.SortOrder = &n
			if n < 0 {
				errs.add("sort_order", vmsg(r.Context(), "min.numeric", "sort_order", "min", "0"))
			}
		} else {
			errs.add("sort_order", vmsg(r.Context(), "integer", "sort_order"))
		}
	}
	if value, ok := root["is_active"].(string); ok {
		switch strings.ToLower(value) {
		case "0", "false":
			// Laravel's boolean rule permits only numeric 0/1 (and actual booleans).
			if value == "false" {
				errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
				break
			}
			active := false
			input.IsActive = &active
		case "1":
			active := true
			input.IsActive = &active
		default:
			errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
		}
	} else if _, ok := root["is_active"]; ok {
		errs.add("is_active", vmsg(r.Context(), "boolean", "is_active"))
	}
	_, input.StartsAtSet = root["starts_at"]
	_, input.EndsAtSet = root["ends_at"]
	if value, ok := root["starts_at"].(string); ok {
		input.StartsAt = &value
	}
	if value, ok := root["ends_at"].(string); ok {
		input.EndsAt = &value
	}
	if value, exists := root["remove_image"]; exists {
		if text, ok := value.(string); ok && (text == "0" || text == "1") {
			remove := text == "1"
			input.RemoveImage = &remove
		} else {
			errs.add("remove_image", vmsg(r.Context(), "boolean", "remove_image"))
		}
	}
	starts, startsOK := parseHomepageFormDate(input.StartsAt)
	ends, endsOK := parseHomepageFormDate(input.EndsAt)
	if input.StartsAt != nil && *input.StartsAt != "" && !startsOK {
		errs.add("starts_at", vmsg(r.Context(), "date", "starts_at"))
	}
	if input.EndsAt != nil && *input.EndsAt != "" && !endsOK {
		errs.add("ends_at", vmsg(r.Context(), "date", "ends_at"))
	}
	if startsOK && endsOK && ends.Before(starts) {
		errs.add("ends_at", vmsg(r.Context(), "after_or_equal", "ends_at", "date", i18n.AttributeName(LocaleFrom(r.Context()), "starts_at")))
	}
	if err := errs.fail(r.Context()); err != nil {
		return input, uploads, err
	}
	for key, headers := range r.MultipartForm.File {
		if len(headers) == 0 || headers[0].Filename == "" {
			continue
		}
		file, err := readHomepageBlockUpload(headers[0])
		if err != nil {
			return input, uploads, err
		}
		if key == "image" {
			uploads.Image = file
			continue
		}
		if match := slideUpload.FindStringSubmatch(key); len(match) == 3 {
			if uploads.SlideImages[match[1]] == nil {
				uploads.SlideImages[match[1]] = map[string]*media.Upload{}
			}
			uploads.SlideImages[match[1]][match[2]] = file
		}
	}
	return input, uploads, nil
}

func readHomepageBlockUpload(header *multipart.FileHeader) (*media.Upload, error) {
	f, err := header.Open()
	if err != nil {
		return nil, err
	}
	defer f.Close()
	content, err := io.ReadAll(io.LimitReader(f, (10<<20)+1))
	if err != nil {
		return nil, err
	}
	return &media.Upload{Filename: header.Filename, Content: content}, nil
}

func parsePHPFormPath(key string) ([]string, bool) {
	match := phpFormPath.FindStringSubmatch(key)
	if len(match) != 3 {
		return nil, false
	}
	parts := []string{match[1]}
	for _, item := range phpFormPart.FindAllStringSubmatch(match[2], -1) {
		parts = append(parts, item[1])
	}
	return parts, true
}

func setPHPFormPath(dst map[string]any, path []string, value any) {
	current := dst
	for _, part := range path[:len(path)-1] {
		next, ok := current[part].(map[string]any)
		if !ok {
			next = map[string]any{}
			current[part] = next
		}
		current = next
	}
	current[path[len(path)-1]] = value
}

func finalizePHPFormValue(value any) any {
	values, ok := value.(map[string]any)
	if !ok {
		return value
	}
	isArray, max := len(values) > 0, -1
	for key := range values {
		n, err := strconv.Atoi(key)
		if err != nil || n < 0 {
			isArray = false
			break
		}
		if n > max {
			max = n
		}
	}
	if isArray && max+1 == len(values) {
		out := make([]any, max+1)
		for key, child := range values {
			n, _ := strconv.Atoi(key)
			out[n] = finalizePHPFormValue(child)
		}
		return out
	}
	for key, child := range values {
		values[key] = finalizePHPFormValue(child)
	}
	return values
}

func normalizeHomepageFormContent(rawType any, content map[string]any) {
	typ, _ := rawType.(string)
	if typ == "category" {
		if ids, ok := content["category_ids"].([]any); ok {
			seen, out := map[int64]bool{}, make([]any, 0, len(ids))
			for _, raw := range ids {
				text, ok := raw.(string)
				if !ok {
					out = append(out, raw)
					continue
				}
				id, err := strconv.ParseInt(text, 10, 64)
				if err != nil {
					out = append(out, raw)
					continue
				}
				if !seen[id] {
					seen[id] = true
					out = append(out, float64(id))
				}
			}
			content["category_ids"] = out
		}
	}
	if typ != "social_media" && typ != "marketplace_links" {
		return
	}
	platforms, ok := content["platforms"].(map[string]any)
	if !ok {
		return
	}
	for _, raw := range platforms {
		config, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		value, ok := config["enabled"].(string)
		if !ok {
			continue
		}
		if value == "1" || strings.EqualFold(value, "true") {
			config["enabled"] = true
		}
		if value == "0" || strings.EqualFold(value, "false") {
			config["enabled"] = false
		}
	}
}

func parseHomepageFormDate(value *string) (time.Time, bool) {
	if value == nil || strings.TrimSpace(*value) == "" {
		return time.Time{}, true
	}
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02 15:04:05", "2006-01-02"} {
		if parsed, err := time.Parse(layout, *value); err == nil {
			return parsed, true
		}
	}
	return time.Time{}, false
}

func validateHomepageBlockUpload(ctx context.Context, errs fieldErrors, field string, upload *media.Upload) {
	if upload == nil {
		return
	}
	mime := strings.ToLower(http.DetectContentType(upload.Content))
	isImage := mime == "image/jpeg" || mime == "image/png" || mime == "image/gif" || mime == "image/bmp" || mime == "image/webp" || mime == "image/avif"
	if !isImage {
		errs.add(field, homepageFileVmsg(ctx, "image", field))
	}
	allowed := mime == "image/jpeg" || mime == "image/png" || mime == "image/webp"
	if !allowed {
		errs.add(field, homepageFileVmsg(ctx, "mimes", field, "values", "jpg, jpeg, png, webp"))
	}
	if len(upload.Content) > 10<<20 {
		errs.add(field, homepageFileVmsg(ctx, "max.file", field, "max", "10240"))
	}
}

func homepageFileVmsg(ctx context.Context, rule, field string, params ...string) string {
	if field == "image" {
		return vmsg(ctx, rule, field, params...)
	}
	message := i18n.Translate(LocaleFrom(ctx), "validation."+rule)
	message = strings.ReplaceAll(message, ":attribute", field)
	for i := 0; i+1 < len(params); i += 2 {
		message = strings.ReplaceAll(message, ":"+params[i], params[i+1])
	}
	return message
}
