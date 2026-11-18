package admin

import (
	"context"
	"database/sql"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
)

// assetURL resolves a stored path to its public URL (same rule as every other resource).
func assetURL(ctx context.Context, path string) any {
	if path == "" {
		return nil
	}
	return asset.URL(ctx, path)
}

// formatMySQLTime renders a DATETIME/TIMESTAMP read as text in Eloquent's JSON form.
func formatMySQLTime(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	for _, layout := range []string{"2006-01-02 15:04:05.000000", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, v.String); err == nil {
			return t.UTC().Format("2006-01-02T15:04:05.000000Z")
		}
	}
	return v.String
}
