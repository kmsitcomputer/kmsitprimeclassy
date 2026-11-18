// Package cms is the public content read model: homepage blocks, articles and pages.
//
// The homepage feed is the one endpoint whose shape the storefront renders block-by-block, so it is ported
// including the per-block reference resolution (ProductController::resolveContent). The 'bestseller' block
// needs the sales-ranking service, which belongs to the reporting domain: for an ANONYMOUS visitor Laravel
// itself returns an empty product list for that block, which is exactly what this port does — an
// authenticated ranking is not claimed.
package cms

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrNotFound is returned for a missing article/page slug.
var ErrNotFound = errors.New("content not found")

// ---- homepage --------------------------------------------------------------------------------------

// Homepage returns the ordered, active homepage blocks with their references resolved.
func Homepage(ctx context.Context, d *db.DB) ([]map[string]any, error) {
	now := time.Now().UTC()
	rows, err := d.QueryContext(ctx, `SELECT id, type, content, sort_order FROM cms_homepage_blocks
		WHERE is_active = 1
		  AND (starts_at IS NULL OR starts_at <= ?)
		  AND (ends_at IS NULL OR ends_at >= ?)
		ORDER BY sort_order ASC`, now, now)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type block struct {
		id      int64
		typ     string
		content sql.NullString
	}
	var blocks []block
	for rows.Next() {
		var b block
		var sortOrder int64
		if err := rows.Scan(&b.id, &b.typ, &b.content, &sortOrder); err != nil {
			return nil, err
		}
		blocks = append(blocks, b)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	out := make([]map[string]any, 0, len(blocks))
	for _, b := range blocks {
		content := decodeObject(b.content)
		resolved, err := resolveContent(ctx, d, b.typ, content)
		if err != nil {
			return nil, err
		}
		out = append(out, map[string]any{"id": b.id, "type": b.typ, "content": resolved})
	}
	return out, nil
}

func decodeObject(v sql.NullString) map[string]any {
	out := map[string]any{}
	if !v.Valid || strings.TrimSpace(v.String) == "" {
		return out
	}
	// A malformed block payload must not take the whole homepage down: an empty content object renders an
	// empty block, which is what the frontend already handles.
	_ = json.Unmarshal([]byte(v.String), &out)
	return out
}

// Block type constants (Support\HomepageBlockTypes).
const (
	TypeBannerSlider = "banner_slider"
	TypeCategory     = "category"
	TypeBestseller   = "bestseller"
	TypeProduct      = "product"
	TypeArticle      = "article"
)

func resolveContent(ctx context.Context, d *db.DB, blockType string, content map[string]any) (map[string]any, error) {
	if path, ok := content["image_path"].(string); ok && path != "" {
		content["image_url"] = asset.URL(ctx, path)
		delete(content, "image_path")
	}
	switch blockType {
	case TypeBannerSlider:
		content["slides"] = resolveSlides(ctx, content["slides"])
	case TypeCategory:
		cats, err := resolveCategories(ctx, d, content["category_ids"])
		if err != nil {
			return nil, err
		}
		content["categories"] = cats
	case TypeBestseller:
		// Anonymous visitors get an empty ranking from Laravel too (BestsellerAgentResolver → null agent).
		content["products"] = []map[string]any{}
		if _, ok := content["period"]; !ok {
			content["period"] = "30"
		}
		if _, ok := content["limit"]; !ok {
			content["limit"] = 8
		}
	case TypeProduct:
		products, err := resolveProducts(ctx, d, content)
		if err != nil {
			return nil, err
		}
		content["products"] = products
	case TypeArticle:
		articles, err := resolveArticles(ctx, d, content["article_ids"])
		if err != nil {
			return nil, err
		}
		content["articles"] = articles
	}
	return content, nil
}

// resolveSlides is HomepageBlockTypes::resolveSlides($slides, keepPaths=false).
func resolveSlides(ctx context.Context, raw any) []map[string]any {
	list, ok := raw.([]any)
	if !ok {
		return []map[string]any{}
	}
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		slide, ok := item.(map[string]any)
		if !ok {
			slide = map[string]any{}
		} else {
			// copy so the caller's decoded map is never mutated twice
			clone := make(map[string]any, len(slide))
			for k, v := range slide {
				clone[k] = v
			}
			slide = clone
		}
		if p, ok := slide["image_path"].(string); ok && p != "" {
			slide["image_url"] = asset.URL(ctx, p)
		} else {
			slide["image_url"] = nil
		}
		if p, ok := slide["mobile_image_path"].(string); ok && p != "" {
			slide["mobile_image_url"] = asset.URL(ctx, p)
		} else {
			slide["mobile_image_url"] = nil
		}
		delete(slide, "image_path")
		delete(slide, "mobile_image_path")
		delete(slide, "remove_mobile")
		out = append(out, slide)
	}
	return out
}

func resolveCategories(ctx context.Context, d *db.DB, raw any) ([]map[string]any, error) {
	ids := toInt64Slice(raw)
	if len(ids) == 0 {
		return []map[string]any{}, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT id, name, slug FROM product_categories
		WHERE is_active = 1 AND id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	found := map[int64]map[string]any{}
	for rows.Next() {
		var id int64
		var name, slug string
		if err := rows.Scan(&id, &name, &slug); err != nil {
			return nil, err
		}
		found[id] = map[string]any{"id": id, "name": name, "slug": slug}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	// Preserve the editor's order (sortBy array_search), skipping inactive/removed ids.
	out := make([]map[string]any, 0, len(found))
	for _, id := range ids {
		if c, ok := found[id]; ok {
			out = append(out, c)
		}
	}
	return out, nil
}

func resolveProducts(ctx context.Context, d *db.DB, content map[string]any) ([]map[string]any, error) {
	limit := int64(8)
	if v, ok := toInt(content["limit"]); ok && v > 0 {
		limit = v
	}
	where := []string{"p.status = 'active'", "p.deleted_at IS NULL"}
	args := []any{}
	if ids := toInt64Slice(content["product_ids"]); len(ids) > 0 {
		where = append(where, "p.id IN ("+placeholders(len(ids))+")")
		for _, id := range ids {
			args = append(args, id)
		}
	} else if cid, ok := toInt(content["category_id"]); ok && cid > 0 {
		where = append(where, "p.category_id = ?")
		args = append(args, cid)
	}
	args = append(args, limit)
	rows, err := d.QueryContext(ctx, `SELECT p.id, p.name, p.slug, p.has_variations, p.base_price,
			(SELECT MIN(price) FROM product_variations v WHERE v.product_id = p.id AND v.is_active = 1 AND v.deleted_at IS NULL) AS min_price,
			(SELECT path FROM product_images i WHERE i.product_id = p.id AND i.is_primary = 1 ORDER BY i.sort_order, i.id LIMIT 1) AS image_path
		FROM products p
		WHERE `+strings.Join(where, " AND ")+`
		ORDER BY p.id ASC LIMIT ?`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			id         int64
			name, slug string
			hasVar     int
			basePrice  sql.NullString
			minPrice   sql.NullString
			imagePath  sql.NullString
		)
		if err := rows.Scan(&id, &name, &slug, &hasVar, &basePrice, &minPrice, &imagePath); err != nil {
			return nil, err
		}
		starting := basePrice
		if hasVar == 1 {
			starting = minPrice
		}
		var imageURL any
		if imagePath.Valid && imagePath.String != "" {
			imageURL = asset.URL(ctx, imagePath.String)
		}
		out = append(out, map[string]any{
			"id":             id,
			"name":           name,
			"slug":           slug,
			"has_variations": hasVar == 1,
			"base_price":     nullStr(basePrice),
			"starting_price": nullStr(starting),
			"image_url":      imageURL,
		})
	}
	return out, rows.Err()
}

func resolveArticles(ctx context.Context, d *db.DB, raw any) ([]map[string]any, error) {
	ids := toInt64Slice(raw)
	if len(ids) == 0 {
		return []map[string]any{}, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT a.id, a.slug, a.type, a.cover_image_path, a.published_at,
			(SELECT t.title FROM cms_articles_translations t WHERE t.cms_article_id = a.id ORDER BY t.id LIMIT 1) AS title
		FROM cms_articles a
		WHERE a.status = 'published' AND a.id IN (`+placeholders(len(ids))+`)`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			id        int64
			slug, typ string
			cover     sql.NullString
			published sql.NullTime
			title     sql.NullString
		)
		if err := rows.Scan(&id, &slug, &typ, &cover, &published, &title); err != nil {
			return nil, err
		}
		var coverURL any
		if cover.Valid && cover.String != "" {
			coverURL = asset.URL(ctx, cover.String)
		}
		name := slug
		if title.Valid && title.String != "" {
			name = title.String
		}
		out = append(out, map[string]any{
			"id":              id,
			"slug":            slug,
			"type":            typ,
			"title":           name,
			"cover_image_url": coverURL,
			"published_at":    formatTimestamp(published),
		})
	}
	return out, rows.Err()
}

// ---- articles --------------------------------------------------------------------------------------

// ArticleList is a page of published articles.
type ArticleList struct {
	Items    []map[string]any
	Page     int
	LastPage int
	Total    int
}

// ListArticles mirrors ArticleController::index: published only, newest published_at first, translations loaded.
func ListArticles(ctx context.Context, d *db.DB, typ string, page, perPage int) (*ArticleList, error) {
	if page < 1 {
		page = 1
	}
	if perPage < 1 || perPage > 100 {
		perPage = 15
	}
	where := "a.status = 'published'"
	args := []any{}
	if typ != "" {
		where += " AND a.type = ?"
		args = append(args, typ)
	}
	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM cms_articles a WHERE `+where, args...).Scan(&total); err != nil {
		return nil, err
	}
	offset := (page - 1) * perPage
	rows, err := d.QueryContext(ctx, `SELECT a.id, a.type, a.slug, a.status, a.published_at, a.cover_image_path,
			a.created_at, a.updated_at
		FROM cms_articles a WHERE `+where+`
		ORDER BY a.published_at DESC, a.id DESC LIMIT ? OFFSET ?`, append(append([]any{}, args...), perPage, offset)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type arow struct {
		id        int64
		typ, slug string
		status    string
		published sql.NullTime
		cover     sql.NullString
		created   sql.NullTime
		updated   sql.NullTime
	}
	var list []arow
	for rows.Next() {
		var r arow
		if err := rows.Scan(&r.id, &r.typ, &r.slug, &r.status, &r.published, &r.cover, &r.created, &r.updated); err != nil {
			return nil, err
		}
		list = append(list, r)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	ids := make([]int64, 0, len(list))
	for _, r := range list {
		ids = append(ids, r.id)
	}
	translations, err := loadArticleTranslations(ctx, d, ids)
	if err != nil {
		return nil, err
	}
	out := make([]map[string]any, 0, len(list))
	for _, r := range list {
		var coverURL any
		if r.cover.Valid && r.cover.String != "" {
			coverURL = asset.URL(ctx, r.cover.String)
		}
		out = append(out, map[string]any{
			"id":              r.id,
			"type":            r.typ,
			"slug":            r.slug,
			"status":          r.status,
			"published_at":    formatTimestamp(r.published),
			"cover_image_url": coverURL,
			"translations":    translations[r.id],
			"created_at":      formatTimestamp(r.created),
			"updated_at":      formatTimestamp(r.updated),
		})
	}
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	return &ArticleList{Items: out, Page: page, LastPage: lastPage, Total: total}, nil
}

// ArticleBySlug mirrors ArticleController::show, including author_name.
func ArticleBySlug(ctx context.Context, d *db.DB, slug string) (map[string]any, error) {
	var (
		id        int64
		typ, sl   string
		status    string
		published sql.NullTime
		cover     sql.NullString
		created   sql.NullTime
		updated   sql.NullTime
		author    sql.NullString
	)
	err := d.QueryRowContext(ctx, `SELECT a.id, a.type, a.slug, a.status, a.published_at, a.cover_image_path,
			a.created_at, a.updated_at, u.name
		FROM cms_articles a LEFT JOIN users u ON u.id = a.author_id
		WHERE a.slug = ? AND a.status = 'published' LIMIT 1`, slug).
		Scan(&id, &typ, &sl, &status, &published, &cover, &created, &updated, &author)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	translations, err := loadArticleTranslations(ctx, d, []int64{id})
	if err != nil {
		return nil, err
	}
	var coverURL any
	if cover.Valid && cover.String != "" {
		coverURL = asset.URL(ctx, cover.String)
	}
	out := map[string]any{
		"id":              id,
		"type":            typ,
		"slug":            sl,
		"status":          status,
		"published_at":    formatTimestamp(published),
		"cover_image_url": coverURL,
		"translations":    translations[id],
		"created_at":      formatTimestamp(created),
		"updated_at":      formatTimestamp(updated),
	}
	if author.Valid {
		out["author_name"] = author.String
	}
	return out, nil
}

func loadArticleTranslations(ctx context.Context, d *db.DB, ids []int64) (map[int64][]map[string]any, error) {
	out := map[int64][]map[string]any{}
	if len(ids) == 0 {
		return out, nil
	}
	args := make([]any, 0, len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	rows, err := d.QueryContext(ctx, `SELECT cms_article_id, language_id, title, excerpt, body
		FROM cms_articles_translations WHERE cms_article_id IN (`+placeholders(len(ids))+`)
		ORDER BY cms_article_id ASC, id ASC`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var (
			articleID, languageID int64
			title, excerpt, body  sql.NullString
		)
		if err := rows.Scan(&articleID, &languageID, &title, &excerpt, &body); err != nil {
			return nil, err
		}
		out[articleID] = append(out[articleID], map[string]any{
			"language_id": languageID,
			"title":       nullStr(title),
			"excerpt":     nullStr(excerpt),
			"body":        nullStr(body),
		})
	}
	return out, rows.Err()
}

// ---- pages -----------------------------------------------------------------------------------------

// PageBySlug mirrors PageController::show.
func PageBySlug(ctx context.Context, d *db.DB, slug string) (map[string]any, error) {
	var (
		id        int64
		sl, stat  string
		published sql.NullTime
		cover     sql.NullString
		created   sql.NullTime
		updated   sql.NullTime
	)
	err := d.QueryRowContext(ctx, `SELECT id, slug, status, published_at, cover_image_path, created_at, updated_at
		FROM cms_pages WHERE slug = ? AND status = 'published' LIMIT 1`, slug).
		Scan(&id, &sl, &stat, &published, &cover, &created, &updated)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	translations, err := loadPageTranslations(ctx, d, id)
	if err != nil {
		return nil, err
	}
	var coverURL any
	if cover.Valid && cover.String != "" {
		coverURL = asset.URL(ctx, cover.String)
	}
	return map[string]any{
		"id":              id,
		"slug":            sl,
		"status":          stat,
		"published_at":    formatTimestamp(published),
		"cover_image_url": coverURL,
		"translations":    translations,
		"created_at":      formatTimestamp(created),
		"updated_at":      formatTimestamp(updated),
	}, nil
}

func loadPageTranslations(ctx context.Context, d *db.DB, pageID int64) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT language_id, title, body, seo_title, seo_description
		FROM cms_pages_translations WHERE cms_page_id = ? ORDER BY id ASC`, pageID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var languageID int64
		var title, body, seoTitle, seoDescription sql.NullString
		if err := rows.Scan(&languageID, &title, &body, &seoTitle, &seoDescription); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"language_id":     languageID,
			"title":           nullStr(title),
			"body":            nullStr(body),
			"seo_title":       nullStr(seoTitle),
			"seo_description": nullStr(seoDescription),
		})
	}
	return out, rows.Err()
}

// ---- helpers ---------------------------------------------------------------------------------------

func placeholders(n int) string {
	if n <= 0 {
		return ""
	}
	return strings.TrimSuffix(strings.Repeat("?,", n), ",")
}

func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

// formatTimestamp renders a nullable timestamp the way Eloquent serialises Carbon to JSON (UTC, ISO-8601 with
// microseconds), so a diff against Laravel compares equal on the same row.
func formatTimestamp(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return v.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

func toInt64Slice(v any) []int64 {
	list, ok := v.([]any)
	if !ok {
		return nil
	}
	out := make([]int64, 0, len(list))
	for _, item := range list {
		if n, ok := toInt(item); ok {
			out = append(out, n)
		}
	}
	return out
}

func toInt(v any) (int64, bool) {
	switch n := v.(type) {
	case float64:
		return int64(n), true
	case int:
		return int64(n), true
	case int64:
		return n, true
	case json.Number:
		i, err := n.Int64()
		if err != nil {
			return 0, false
		}
		return i, true
	case string:
		i, err := strconv.ParseInt(strings.TrimSpace(n), 10, 64)
		if err != nil {
			return 0, false
		}
		return i, true
	}
	return 0, false
}
