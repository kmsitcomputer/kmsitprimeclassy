package api

// Request parsing + Laravel-rule validation shared by the payment endpoints. Every sentence is rendered through
// the i18n catalogue (never inline text) so the 422 bodies stay byte-identical to the reference.

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

// paymentForm is a decoded request: scalar fields (json.Number-preserving) plus uploaded files.
type paymentForm struct {
	fields map[string]any
	files  map[string]*media.Upload
}

func (f *paymentForm) has(name string) bool { _, ok := f.fields[name]; return ok }

// filled is Request::filled — present and not an empty string.
func (f *paymentForm) filled(name string) bool {
	v, ok := f.fields[name]
	if !ok || v == nil {
		return false
	}
	if text, isString := v.(string); isString && strings.TrimSpace(text) == "" {
		return false
	}
	return true
}

func (f *paymentForm) text(name string) (string, bool) {
	if !f.filled(name) {
		return "", false
	}
	return stringValue(f.fields[name])
}

// readPaymentForm accepts JSON or multipart. A multipart value that is the empty string is dropped, mirroring
// Laravel's ConvertEmptyStringsToNull (a null and an absent field are the same to every rule used here).
func readPaymentForm(r *http.Request) (*paymentForm, error) {
	form := &paymentForm{fields: map[string]any{}, files: map[string]*media.Upload{}}
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		if err := r.ParseMultipartForm(8 << 20); err != nil {
			return nil, httpx.NewError(http.StatusBadRequest, i18n.Translate(i18n.Default, "system.generic_error"))
		}
		for name, values := range r.MultipartForm.Value {
			if len(values) > 0 && values[0] != "" {
				form.fields[name] = json.Number(values[0])
				if _, err := strconv.ParseFloat(values[0], 64); err != nil {
					form.fields[name] = values[0]
				}
			}
		}
		for name, headers := range r.MultipartForm.File {
			if len(headers) == 0 {
				continue
			}
			handle, err := headers[0].Open()
			if err != nil {
				return nil, err
			}
			content, err := io.ReadAll(io.LimitReader(handle, 12<<20))
			handle.Close()
			if err != nil {
				return nil, err
			}
			form.files[name] = &media.Upload{Filename: headers[0].Filename, Content: content}
		}
		return form, nil
	}
	body, err := decodeObject(r)
	if err != nil {
		return nil, err
	}
	for name, value := range body {
		if text, ok := value.(string); ok && text == "" {
			continue
		}
		form.fields[name] = value
	}
	return form, nil
}

type fieldErrors map[string][]string

func (e fieldErrors) add(field string, messages ...string) { e[field] = append(e[field], messages...) }

func (e fieldErrors) fail(ctx context.Context) error {
	if len(e) == 0 {
		return nil
	}
	return &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"), Fields: e}
}

func vmsg(ctx context.Context, rule, field string, params ...string) string {
	return i18n.ValidationMessage(LocaleFrom(ctx), rule, field, params...)
}

// validateImageFile is `required|image|mimes:jpg,jpeg,png,webp|max:5120` (or the nullable file form).
func validateImageFile(ctx context.Context, errs fieldErrors, field string, upload *media.Upload, required bool) {
	if upload == nil {
		if required {
			errs.add(field, vmsg(ctx, "required", field))
		}
		return
	}
	kind := strings.ToLower(http.DetectContentType(upload.Content))
	isImage := kind == "image/jpeg" || kind == "image/png" || kind == "image/gif" || kind == "image/bmp" || kind == "image/webp"
	allowed := kind == "image/jpeg" || kind == "image/png" || kind == "image/webp"
	if !isImage {
		errs.add(field, vmsg(ctx, "image", field))
	}
	if !allowed {
		errs.add(field, vmsg(ctx, "mimes", field, "values", "jpg, jpeg, png, webp"))
	}
	if len(upload.Content) > 5120*1024 {
		errs.add(field, vmsg(ctx, "max.file", field, "max", "5120"))
	}
}

// validateMoney is `numeric|gt:0|max:9999999999`; returns the value when it parsed.
func validateMoney(ctx context.Context, errs fieldErrors, field string, raw any) (float64, bool) {
	text, _ := stringValue(raw)
	value, err := strconv.ParseFloat(strings.TrimSpace(text), 64)
	if err != nil || strings.ContainsAny(text, "eExXpP") {
		errs.add(field, vmsg(ctx, "numeric", field))
		return 0, false
	}
	ok := true
	if value <= 0 {
		errs.add(field, vmsg(ctx, "gt.numeric", field, "value", "0"))
		ok = false
	}
	if value > 9999999999 {
		errs.add(field, vmsg(ctx, "max.numeric", field, "max", "9999999999"))
		ok = false
	}
	return value, ok
}

// laravelBool is the `boolean` rule: true, false, 0, 1, "0", "1" only.
func laravelBool(raw any) (value, ok bool) {
	switch v := raw.(type) {
	case bool:
		return v, true
	case json.Number:
		switch string(v) {
		case "1":
			return true, true
		case "0":
			return false, true
		}
	case float64:
		if v == 1 {
			return true, true
		}
		if v == 0 {
			return false, true
		}
	case string:
		switch v {
		case "1":
			return true, true
		case "0":
			return false, true
		}
	}
	return false, false
}

// validateBoolRequired is `required|boolean`.
func validateBoolRequired(ctx context.Context, errs fieldErrors, form *paymentForm, field string) (value, ok bool) {
	if !form.has(field) {
		errs.add(field, vmsg(ctx, "required", field))
		return false, false
	}
	value, ok = laravelBool(form.fields[field])
	if !ok {
		errs.add(field, vmsg(ctx, "boolean", field))
	}
	return value, ok
}

// validateOptionalString is `nullable|string|max:N`.
func validateOptionalString(ctx context.Context, errs fieldErrors, form *paymentForm, field string, max int) (string, bool) {
	if !form.filled(field) {
		return "", false
	}
	text, ok := stringValue(form.fields[field])
	if !ok {
		errs.add(field, vmsg(ctx, "string", field))
		return "", false
	}
	if len([]rune(text)) > max {
		errs.add(field, vmsg(ctx, "max.string", field, "max", strconv.Itoa(max)))
		return "", false
	}
	return text, true
}

// validateRequiredIfFalse is `required_if:<flag>,false|nullable|string|max:255`.
func validateRequiredIfFalse(ctx context.Context, errs fieldErrors, form *paymentForm, field, flag string, flagValue, flagValid bool) (string, bool) {
	if flagValid && !flagValue && !form.filled(field) {
		errs.add(field, vmsg(ctx, "required_if", field, "other", flag, "value", "false"))
		return "", false
	}
	return validateOptionalString(ctx, errs, form, field, 255)
}
