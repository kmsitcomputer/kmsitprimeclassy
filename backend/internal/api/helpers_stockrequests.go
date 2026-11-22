package api

// Small rendering/validation helpers shared by the warehouse stock-request endpoints.

import (
	"context"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// nullableInt renders a *int64 as JSON null or the number.
func nullableInt(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

// nullableIntPtr renders a *int as JSON null or the number.
func nullableIntPtr(v *int) any {
	if v == nil {
		return nil
	}
	return *v
}

// nullableString renders a *string as JSON null or the string.
func nullableString(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

// renderTimestamp renders an optional datetime the way Eloquent's datetime cast does.
func renderTimestamp(v *time.Time) any {
	if v == nil {
		return nil
	}
	return v.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// defaultMessage is the reference's default success sentence for a `created()` response.
func defaultMessage(ctx context.Context, key string) string {
	if key == "Created" {
		return "Created"
	}
	return msg(ctx, key)
}

// notInMessage renders Laravel's `not_in` sentence.
func notInMessage(ctx context.Context, field, values string) string {
	return i18n.ValidationMessage(LocaleFrom(ctx), "not_in", field, "values", values)
}

var _ = strconv.Itoa
