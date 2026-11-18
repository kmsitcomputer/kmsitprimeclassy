// Package asset builds the public URLs for files stored on the `public` disk.
//
// Laravel resolves them through Storage::disk('public')->url($path), which is configured as
// `env('APP_URL').'/storage'` — an ABSOLUTE url. The Go service reproduces that exactly, because the SPA
// and the Nuxt renderer both use the value as-is in <img src> and JSON-LD.
package asset

import (
	"context"
	"strings"
)

type baseKey struct{}

var defaultBase string

// SetBase configures the process-wide public origin (APP_URL).
func SetBase(base string) { defaultBase = strings.TrimRight(strings.TrimSpace(base), "/") }

// WithBase overrides the origin for one context (tests, multi-tenant previews).
func WithBase(ctx context.Context, base string) context.Context {
	return context.WithValue(ctx, baseKey{}, strings.TrimRight(strings.TrimSpace(base), "/"))
}

// Base returns the effective public origin for the context.
func Base(ctx context.Context) string {
	if v, ok := ctx.Value(baseKey{}).(string); ok && v != "" {
		return v
	}
	return defaultBase
}

// URL renders the public URL of a stored path. An empty path renders "" (never a bare prefix), so optional
// images stay null instead of pointing at the storage root.
func URL(ctx context.Context, path string) string {
	if strings.TrimSpace(path) == "" {
		return ""
	}
	clean := strings.TrimLeft(path, "/")
	base := Base(ctx)
	if base == "" {
		return "/storage/" + clean
	}
	return base + "/storage/" + clean
}

// URLOrNil renders a nullable path.
func URLOrNil(ctx context.Context, path *string) any {
	if path == nil || *path == "" {
		return nil
	}
	return URL(ctx, *path)
}
