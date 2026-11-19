package cms

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
)

var ErrAdminContentNotFound = errors.New("cms content not found")

// ArticleTranslation is one localized article body.
type ArticleTranslation struct {
	LanguageID int64   `json:"language_id"`
	Title      string  `json:"title"`
	Excerpt    *string `json:"excerpt"`
	Body       string  `json:"body"`
}

// ArticleInput matches the CMS article request fields; nil values preserve omitted PATCH fields.
type ArticleInput struct {
	Type         *string               `json:"type"`
	Slug         *string               `json:"slug"`
	Status       *string               `json:"status"`
	PublishedAt  *string               `json:"published_at"`
	CoverMediaID *int64                `json:"cover_media_id"`
	RemoveCover  *bool                 `json:"remove_cover"`
	Translations *[]ArticleTranslation `json:"translations"`
}

// PageTranslation is one localized static page body.
type PageTranslation struct {
	LanguageID     int64   `json:"language_id"`
	Title          string  `json:"title"`
	Body           *string `json:"body"`
	SEOTitle       *string `json:"seo_title"`
	SEODescription *string `json:"seo_description"`
}

// PageInput matches the CMS page request fields; nil values preserve omitted PATCH fields.
type PageInput struct {
	Slug         *string            `json:"slug"`
	Status       *string            `json:"status"`
	PublishedAt  *string            `json:"published_at"`
	CoverMediaID *int64             `json:"cover_media_id"`
	RemoveCover  *bool              `json:"remove_cover"`
	Translations *[]PageTranslation `json:"translations"`
}

// PageResult contains the resource rows and Laravel's explicit pagination metadata.
type PageResult struct {
	Items    []map[string]any
	Page     int
	LastPage int
	Total    int
}

func validation(field, message string) error {
	return &httpx.Error{Status: 422, Fields: map[string][]string{field: {message}}}
}

// ValidateSafeLink mirrors the CMS SafeLinkUrl rule; arbitrary schemes and protocol-relative URLs are rejected.
func ValidateSafeLink(raw string) bool {
	if strings.ContainsAny(raw, "\x00\r\n\t") {
		return false
	}
	lower := strings.ToLower(raw)
	if strings.HasPrefix(lower, "http://") || strings.HasPrefix(lower, "https://") || strings.HasPrefix(lower, "mailto:") ||
		strings.HasPrefix(lower, "tel:") || strings.HasPrefix(raw, "/") && !strings.HasPrefix(raw, "//") || strings.HasPrefix(raw, "#") {
		return !strings.ContainsAny(raw, ` <>"'\`)
	}
	return false
}

func AdminArticles(ctx context.Context, d *db.DB, typ, status string, page, perPage int) (*PageResult, error) {
	if page < 1 {
		page = 1
	}
	if perPage < 1 || perPage > 100 {
		perPage = 15
	}
	where := []string{"1=1"}
	args := []any{}
	if typ != "" {
		where = append(where, "a.type = ?")
		args = append(args, typ)
	}
	if status != "" {
		where = append(where, "a.status = ?")
		args = append(args, status)
	}
	condition := strings.Join(where, " AND ")
	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM cms_articles a WHERE `+condition, args...).Scan(&total); err != nil {
		return nil, err
	}
	rows, err := d.QueryContext(ctx, `SELECT a.id, a.type, a.slug, a.status, a.published_at, a.cover_image_path,
			a.author_id, u.name, a.created_at, a.updated_at
		FROM cms_articles a LEFT JOIN users u ON u.id = a.author_id
		WHERE `+condition+` ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?`,
		append(append([]any{}, args...), perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	type articleRow struct {
		id                   int64
		typ, slug, status    string
		published, createdAt sql.NullTime
		coverPath, author    sql.NullString
		authorID             sql.NullInt64
		updatedAt            sql.NullTime
	}
	items := []articleRow{}
	ids := []int64{}
	for rows.Next() {
		var row articleRow
		if err := rows.Scan(&row.id, &row.typ, &row.slug, &row.status, &row.published, &row.coverPath,
			&row.authorID, &row.author, &row.createdAt, &row.updatedAt); err != nil {
			return nil, err
		}
		items = append(items, row)
		ids = append(ids, row.id)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	translations, err := articleTranslations(ctx, d, ids)
	if err != nil {
		return nil, err
	}
	result := make([]map[string]any, 0, len(items))
	for _, row := range items {
		var coverURL any
		if row.coverPath.Valid && row.coverPath.String != "" {
			coverURL = asset.URL(ctx, row.coverPath.String)
		}
		result = append(result, map[string]any{
			"id": row.id, "type": row.typ, "slug": row.slug, "status": row.status,
			"published_at": formatTimestamp(row.published), "cover_image_url": coverURL,
			"author_name": nullStr(row.author), "translations": translations[row.id],
			"created_at": formatTimestamp(row.createdAt), "updated_at": formatTimestamp(row.updatedAt),
		})
	}
	return &PageResult{Items: result, Page: page, LastPage: lastPage(total, perPage), Total: total}, nil
}

func AdminPages(ctx context.Context, d *db.DB, status string, page, perPage int) (*PageResult, error) {
	if page < 1 {
		page = 1
	}
	if perPage < 1 || perPage > 100 {
		perPage = 15
	}
	where := "1=1"
	args := []any{}
	if status != "" {
		where = "status = ?"
		args = append(args, status)
	}
	var total int
	if err := d.QueryRowContext(ctx, `SELECT COUNT(*) FROM cms_pages WHERE `+where, args...).Scan(&total); err != nil {
		return nil, err
	}
	rows, err := d.QueryContext(ctx, `SELECT id, slug, status, published_at, cover_image_path, created_at, updated_at
		FROM cms_pages WHERE `+where+` ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?`,
		append(append([]any{}, args...), perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	type pageRow struct {
		id                   int64
		slug, status         string
		published, createdAt sql.NullTime
		coverPath            sql.NullString
		updatedAt            sql.NullTime
	}
	items := []pageRow{}
	for rows.Next() {
		var row pageRow
		if err := rows.Scan(&row.id, &row.slug, &row.status, &row.published, &row.coverPath, &row.createdAt, &row.updatedAt); err != nil {
			return nil, err
		}
		items = append(items, row)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	result := make([]map[string]any, 0, len(items))
	for _, row := range items {
		var coverURL any
		if row.coverPath.Valid && row.coverPath.String != "" {
			coverURL = asset.URL(ctx, row.coverPath.String)
		}
		translations, err := pageTranslations(ctx, d, row.id)
		if err != nil {
			return nil, err
		}
		result = append(result, map[string]any{
			"id": row.id, "slug": row.slug, "status": row.status,
			"published_at": formatTimestamp(row.published), "cover_image_url": coverURL,
			"translations": translations, "created_at": formatTimestamp(row.createdAt),
			"updated_at": formatTimestamp(row.updatedAt),
		})
	}
	return &PageResult{Items: result, Page: page, LastPage: lastPage(total, perPage), Total: total}, nil
}

func CreateArticle(ctx context.Context, d *db.DB, input ArticleInput, authorID int64) (map[string]any, error) {
	if input.Type == nil || (*input.Type != "article" && *input.Type != "news") {
		return nil, validation("type", "The selected type is invalid.")
	}
	if input.Slug == nil || !validSlug(*input.Slug, 180) {
		return nil, validation("slug", "The slug field is required and must be valid.")
	}
	if input.Translations == nil || len(*input.Translations) == 0 {
		return nil, validation("translations", "The translations field is required.")
	}
	publishedAt, err := parsePublishedAt(input.PublishedAt)
	if err != nil {
		return nil, validation("published_at", "The published at field is not a valid date.")
	}
	var id int64
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		status := valueOr(input.Status, "draft")
		if !validStatus(status) {
			return validation("status", "The selected status is invalid.")
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO cms_articles
			(type, slug, author_id, status, published_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			*input.Type, *input.Slug, authorID, status, publishedAt)
		if err != nil {
			return err
		}
		id, err = res.LastInsertId()
		if err != nil {
			return err
		}
		if err := syncArticleTranslations(ctx, tx, id, *input.Translations); err != nil {
			return err
		}
		if input.CoverMediaID != nil {
			if err := syncCover(ctx, tx, "App\\Models\\CmsArticle", id, *input.CoverMediaID, "cms_article_cover"); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return articleByID(ctx, d, id)
}

func UpdateArticle(ctx context.Context, d *db.DB, id int64, input ArticleInput) (map[string]any, error) {
	publishedAt, err := parsePublishedAt(input.PublishedAt)
	if err != nil {
		return nil, validation("published_at", "The published at field is not a valid date.")
	}
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var oldType, oldSlug, oldStatus string
		var oldPublished sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT type, slug, status, published_at FROM cms_articles WHERE id = ? FOR UPDATE`,
			id).Scan(&oldType, &oldSlug, &oldStatus, &oldPublished); errors.Is(err, sql.ErrNoRows) {
			return ErrAdminContentNotFound
		} else if err != nil {
			return err
		}
		typ, slug, status := valueOr(input.Type, oldType), valueOr(input.Slug, oldSlug), valueOr(input.Status, oldStatus)
		if typ != "article" && typ != "news" {
			return validation("type", "The selected type is invalid.")
		}
		if !validSlug(slug, 180) {
			return validation("slug", "The slug field must be valid.")
		}
		if !validStatus(status) {
			return validation("status", "The selected status is invalid.")
		}
		published := any(oldPublished)
		if input.PublishedAt != nil {
			published = publishedAt
		}
		if _, err := tx.ExecContext(ctx, `UPDATE cms_articles SET type = ?, slug = ?, status = ?, published_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
			typ, slug, status, published, id); err != nil {
			return err
		}
		if input.Translations != nil {
			if len(*input.Translations) == 0 {
				return validation("translations", "The translations field must contain at least one item.")
			}
			if err := syncArticleTranslations(ctx, tx, id, *input.Translations); err != nil {
				return err
			}
		}
		if input.RemoveCover != nil && *input.RemoveCover {
			if err := removeCover(ctx, tx, "App\\Models\\CmsArticle", id, "cms_article_cover"); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE cms_articles SET cover_image_path = NULL WHERE id = ?`, id); err != nil {
				return err
			}
		} else if input.CoverMediaID != nil {
			if err := syncCover(ctx, tx, "App\\Models\\CmsArticle", id, *input.CoverMediaID, "cms_article_cover"); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return articleByID(ctx, d, id)
}

func DeleteArticle(ctx context.Context, d *db.DB, id int64) error {
	return d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM cms_articles WHERE id = ?)`, id).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return ErrAdminContentNotFound
		}
		if err := removeCover(ctx, tx, "App\\Models\\CmsArticle", id, "cms_article_cover"); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `DELETE FROM cms_articles WHERE id = ?`, id)
		return err
	})
}

func CreatePage(ctx context.Context, d *db.DB, input PageInput) (map[string]any, error) {
	if input.Slug == nil || !validSlug(*input.Slug, 150) {
		return nil, validation("slug", "The slug field is required and must be valid.")
	}
	if input.Translations == nil || len(*input.Translations) == 0 {
		return nil, validation("translations", "The translations field is required.")
	}
	publishedAt, err := parsePublishedAt(input.PublishedAt)
	if err != nil {
		return nil, validation("published_at", "The published at field is not a valid date.")
	}
	var id int64
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		status := valueOr(input.Status, "draft")
		if !validStatus(status) {
			return validation("status", "The selected status is invalid.")
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO cms_pages
			(slug, status, published_at, created_at, updated_at)
			VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`, *input.Slug, status, publishedAt)
		if err != nil {
			return err
		}
		id, err = res.LastInsertId()
		if err != nil {
			return err
		}
		if err := syncPageTranslations(ctx, tx, id, *input.Translations); err != nil {
			return err
		}
		if input.CoverMediaID != nil {
			return syncCover(ctx, tx, "App\\Models\\CmsPage", id, *input.CoverMediaID, "cms_page_cover")
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return pageByID(ctx, d, id)
}

func UpdatePage(ctx context.Context, d *db.DB, id int64, input PageInput) (map[string]any, error) {
	publishedAt, err := parsePublishedAt(input.PublishedAt)
	if err != nil {
		return nil, validation("published_at", "The published at field is not a valid date.")
	}
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var oldSlug, oldStatus string
		var oldPublished sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT slug, status, published_at FROM cms_pages WHERE id = ? FOR UPDATE`,
			id).Scan(&oldSlug, &oldStatus, &oldPublished); errors.Is(err, sql.ErrNoRows) {
			return ErrAdminContentNotFound
		} else if err != nil {
			return err
		}
		slug, status := valueOr(input.Slug, oldSlug), valueOr(input.Status, oldStatus)
		if !validSlug(slug, 150) {
			return validation("slug", "The slug field must be valid.")
		}
		if !validStatus(status) {
			return validation("status", "The selected status is invalid.")
		}
		published := any(oldPublished)
		if input.PublishedAt != nil {
			published = publishedAt
		}
		if _, err := tx.ExecContext(ctx, `UPDATE cms_pages SET slug = ?, status = ?, published_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
			slug, status, published, id); err != nil {
			return err
		}
		if input.Translations != nil {
			if len(*input.Translations) == 0 {
				return validation("translations", "The translations field must contain at least one item.")
			}
			if err := syncPageTranslations(ctx, tx, id, *input.Translations); err != nil {
				return err
			}
		}
		if input.RemoveCover != nil && *input.RemoveCover {
			if err := removeCover(ctx, tx, "App\\Models\\CmsPage", id, "cms_page_cover"); err != nil {
				return err
			}
			if _, err := tx.ExecContext(ctx, `UPDATE cms_pages SET cover_image_path = NULL WHERE id = ?`, id); err != nil {
				return err
			}
		} else if input.CoverMediaID != nil {
			if err := syncCover(ctx, tx, "App\\Models\\CmsPage", id, *input.CoverMediaID, "cms_page_cover"); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return pageByID(ctx, d, id)
}

func DeletePage(ctx context.Context, d *db.DB, id int64) error {
	return d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM cms_pages WHERE id = ?)`, id).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return ErrAdminContentNotFound
		}
		if err := removeCover(ctx, tx, "App\\Models\\CmsPage", id, "cms_page_cover"); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `DELETE FROM cms_pages WHERE id = ?`, id)
		return err
	})
}

// BlockInput is the JSON-compatible subset of the homepage-block request. Uploaded
// image files remain an HTTP adapter concern; raw client storage paths are never accepted.
type BlockInput struct {
	Type        *string        `json:"type"`
	Content     map[string]any `json:"content"`
	SortOrder   *int           `json:"sort_order"`
	IsActive    *bool          `json:"is_active"`
	StartsAt    *string        `json:"starts_at"`
	EndsAt      *string        `json:"ends_at"`
	RemoveImage *bool          `json:"remove_image"`
}

func AdminBlocks(ctx context.Context, d *db.DB) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, `SELECT id, type, content, sort_order, is_active, starts_at, ends_at, updated_at
		FROM cms_homepage_blocks ORDER BY sort_order ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var (
			id                    int64
			typ                   string
			raw                   sql.NullString
			sortOrder             int
			active                bool
			starts, ends, updated sql.NullTime
		)
		if err := rows.Scan(&id, &typ, &raw, &sortOrder, &active, &starts, &ends, &updated); err != nil {
			return nil, err
		}
		content := decodeObject(raw)
		resolveAdminSlideURLs(ctx, content)
		var imageURL any
		if path, ok := content["image_path"].(string); ok && path != "" {
			imageURL = asset.URL(ctx, path)
		}
		out = append(out, map[string]any{
			"id": id, "type": typ, "content": content, "image_url": imageURL,
			"sort_order": sortOrder, "is_active": active,
			"starts_at": formatTimestamp(starts), "ends_at": formatTimestamp(ends),
			"updated_at": formatTimestamp(updated),
		})
	}
	return out, rows.Err()
}

func CreateBlock(ctx context.Context, d *db.DB, input BlockInput) (map[string]any, error) {
	if input.Type != nil && !validBlockType(*input.Type) {
		message := strings.ReplaceAll(i18n.Translate(i18n.Default, "cms.unknown_type"), ":type", *input.Type)
		return nil, &httpx.Error{Status: 422, Message: message, FieldStrings: map[string]string{"type": i18n.Translate(i18n.Default, "system.field_invalid")}}
	}
	if input.Type == nil {
		return nil, validation("type", "The selected type is invalid.")
	}
	content := cloneContent(input.Content)
	delete(content, "image_path")
	stripSlideImagePaths(content)
	if err := sanitizeAndValidateBlock(*input.Type, content); err != nil {
		return nil, err
	}
	starts, ends, err := parseBlockDates(input.StartsAt, input.EndsAt)
	if err != nil {
		return nil, err
	}
	var id int64
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var next int
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(MAX(sort_order), -1) + 1 FROM cms_homepage_blocks`).Scan(&next); err != nil {
			return err
		}
		sortOrder := next
		if input.SortOrder != nil {
			if *input.SortOrder < 0 {
				return validation("sort_order", "The sort order must be at least 0.")
			}
			sortOrder = *input.SortOrder
		}
		active := true
		if input.IsActive != nil {
			active = *input.IsActive
		}
		buf, err := json.Marshal(content)
		if err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO cms_homepage_blocks
			(type, content, sort_order, is_active, starts_at, ends_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			*input.Type, string(buf), sortOrder, active, starts, ends)
		if err != nil {
			return err
		}
		id, err = res.LastInsertId()
		return err
	})
	if err != nil {
		return nil, err
	}
	return blockByID(ctx, d, id)
}

func UpdateBlock(ctx context.Context, d *db.DB, id int64, input BlockInput) (map[string]any, error) {
	var blockType string
	var oldContent sql.NullString
	var oldOrder int
	var oldActive bool
	var oldStarts, oldEnds sql.NullTime
	err := d.QueryRowContext(ctx, `SELECT type, content, sort_order, is_active, starts_at, ends_at
		FROM cms_homepage_blocks WHERE id = ?`, id).Scan(&blockType, &oldContent, &oldOrder, &oldActive, &oldStarts, &oldEnds)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrAdminContentNotFound
	}
	if err != nil {
		return nil, err
	}
	content := decodeObject(oldContent)
	if input.Content != nil {
		content = mergeContent(content, input.Content)
	}
	if err := sanitizeAndValidateBlock(blockType, content); err != nil {
		return nil, err
	}
	starts, ends, err := parseBlockDates(input.StartsAt, input.EndsAt)
	if err != nil {
		return nil, err
	}
	if input.StartsAt == nil {
		starts = oldStarts
	}
	if input.EndsAt == nil {
		ends = oldEnds
	}
	sortOrder, active := oldOrder, oldActive
	if input.SortOrder != nil {
		if *input.SortOrder < 0 {
			return nil, validation("sort_order", "The sort order must be at least 0.")
		}
		sortOrder = *input.SortOrder
	}
	if input.IsActive != nil {
		active = *input.IsActive
	}
	if input.RemoveImage != nil && *input.RemoveImage {
		deleteStoredPath(content["image_path"])
		delete(content, "image_path")
	}
	buf, err := json.Marshal(content)
	if err != nil {
		return nil, err
	}
	_, err = d.ExecContext(ctx, `UPDATE cms_homepage_blocks SET content = ?, sort_order = ?, is_active = ?,
		starts_at = ?, ends_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		string(buf), sortOrder, active, starts, ends, id)
	if err != nil {
		return nil, err
	}
	return blockByID(ctx, d, id)
}

func ToggleBlock(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	res, err := d.ExecContext(ctx, `UPDATE cms_homepage_blocks SET is_active = NOT is_active, updated_at = UTC_TIMESTAMP() WHERE id = ?`, id)
	if err != nil {
		return nil, err
	}
	n, err := res.RowsAffected()
	if err != nil {
		return nil, err
	}
	if n == 0 {
		return nil, ErrAdminContentNotFound
	}
	return blockByID(ctx, d, id)
}

func ReorderBlocks(ctx context.Context, d *db.DB, ids []int64) ([]map[string]any, error) {
	if len(ids) == 0 {
		return nil, validation("block_ids", "The block ids field is required.")
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		for i, id := range ids {
			var exists bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM cms_homepage_blocks WHERE id = ?)`, id).Scan(&exists); err != nil {
				return err
			}
			if !exists {
				return ErrAdminContentNotFound
			}
			if _, err := tx.ExecContext(ctx, `UPDATE cms_homepage_blocks SET sort_order = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`, i, id); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return AdminBlocks(ctx, d)
}

func DeleteBlock(ctx context.Context, d *db.DB, id int64) error {
	return d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var raw sql.NullString
		if err := tx.QueryRowContext(ctx, `SELECT content FROM cms_homepage_blocks WHERE id = ? FOR UPDATE`, id).Scan(&raw); errors.Is(err, sql.ErrNoRows) {
			return ErrAdminContentNotFound
		} else if err != nil {
			return err
		}
		content := decodeObject(raw)
		deleteStoredPath(content["image_path"])
		if slides, ok := content["slides"].([]any); ok {
			for _, value := range slides {
				if slide, ok := value.(map[string]any); ok {
					deleteStoredPath(slide["image_path"])
					deleteStoredPath(slide["mobile_image_path"])
				}
			}
		}
		_, err := tx.ExecContext(ctx, `DELETE FROM cms_homepage_blocks WHERE id = ?`, id)
		return err
	})
}

func articleByID(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	var typ, slug, status string
	var published, created, updated sql.NullTime
	var cover, author sql.NullString
	err := d.QueryRowContext(ctx, `SELECT a.type, a.slug, a.status, a.published_at, a.cover_image_path,
		u.name, a.created_at, a.updated_at FROM cms_articles a LEFT JOIN users u ON u.id = a.author_id
		WHERE a.id = ?`, id).Scan(&typ, &slug, &status, &published, &cover, &author, &created, &updated)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrAdminContentNotFound
	}
	if err != nil {
		return nil, err
	}
	var coverURL any
	if cover.Valid && cover.String != "" {
		coverURL = asset.URL(ctx, cover.String)
	}
	translations, err := articleTranslations(ctx, d, []int64{id})
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "type": typ, "slug": slug, "status": status, "published_at": formatTimestamp(published),
		"cover_image_url": coverURL, "author_name": nullStr(author), "translations": translations[id],
		"created_at": formatTimestamp(created), "updated_at": formatTimestamp(updated),
	}, nil
}

func pageByID(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	var slug, status string
	var published, created, updated sql.NullTime
	var cover sql.NullString
	err := d.QueryRowContext(ctx, `SELECT slug, status, published_at, cover_image_path, created_at, updated_at
		FROM cms_pages WHERE id = ?`, id).Scan(&slug, &status, &published, &cover, &created, &updated)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrAdminContentNotFound
	}
	if err != nil {
		return nil, err
	}
	var coverURL any
	if cover.Valid && cover.String != "" {
		coverURL = asset.URL(ctx, cover.String)
	}
	translations, err := pageTranslations(ctx, d, id)
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "slug": slug, "status": status, "published_at": formatTimestamp(published),
		"cover_image_url": coverURL, "translations": translations,
		"created_at": formatTimestamp(created), "updated_at": formatTimestamp(updated),
	}, nil
}

func blockByID(ctx context.Context, d *db.DB, id int64) (map[string]any, error) {
	rows, err := AdminBlocks(ctx, d)
	if err != nil {
		return nil, err
	}
	for _, row := range rows {
		if row["id"] == id {
			return row, nil
		}
	}
	return nil, ErrAdminContentNotFound
}

func articleTranslations(ctx context.Context, q interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
}, ids []int64) (map[int64][]map[string]any, error) {
	result := map[int64][]map[string]any{}
	if len(ids) == 0 {
		return result, nil
	}
	args := make([]any, len(ids))
	for i, id := range ids {
		args[i] = id
	}
	rows, err := q.QueryContext(ctx, `SELECT cms_article_id, language_id, title, excerpt, body
		FROM cms_articles_translations WHERE cms_article_id IN (`+placeholders(len(ids))+`) ORDER BY cms_article_id, id`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var articleID, languageID int64
		var title, excerpt, body sql.NullString
		if err := rows.Scan(&articleID, &languageID, &title, &excerpt, &body); err != nil {
			return nil, err
		}
		result[articleID] = append(result[articleID], map[string]any{
			"language_id": languageID, "title": nullStr(title), "excerpt": nullStr(excerpt), "body": nullStr(body),
		})
	}
	return result, rows.Err()
}

func pageTranslations(ctx context.Context, q interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
}, pageID int64) ([]map[string]any, error) {
	rows, err := q.QueryContext(ctx, `SELECT language_id, title, body, seo_title, seo_description
		FROM cms_pages_translations WHERE cms_page_id = ? ORDER BY id`, pageID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	result := []map[string]any{}
	for rows.Next() {
		var languageID int64
		var title, body, seoTitle, seoDescription sql.NullString
		if err := rows.Scan(&languageID, &title, &body, &seoTitle, &seoDescription); err != nil {
			return nil, err
		}
		result = append(result, map[string]any{
			"language_id": languageID, "title": nullStr(title), "body": nullStr(body),
			"seo_title": nullStr(seoTitle), "seo_description": nullStr(seoDescription),
		})
	}
	return result, rows.Err()
}

func syncArticleTranslations(ctx context.Context, tx *sql.Tx, id int64, translations []ArticleTranslation) error {
	seen := map[int64]bool{}
	for i, tr := range translations {
		if tr.LanguageID < 1 || seen[tr.LanguageID] {
			return validation(fmt.Sprintf("translations.%d.language_id", i), "The language id must be unique and valid.")
		}
		seen[tr.LanguageID] = true
		if strings.TrimSpace(tr.Title) == "" || len(tr.Title) > 200 {
			return validation(fmt.Sprintf("translations.%d.title", i), "The title field is required and may not exceed 200 characters.")
		}
		if tr.Excerpt != nil && len(*tr.Excerpt) > 300 {
			return validation(fmt.Sprintf("translations.%d.excerpt", i), "The excerpt may not exceed 300 characters.")
		}
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM languages WHERE id = ?)`, tr.LanguageID).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return validation(fmt.Sprintf("translations.%d.language_id", i), "The selected language is invalid.")
		}
		if strings.TrimSpace(tr.Body) == "" {
			return validation(fmt.Sprintf("translations.%d.body", i), "The body field is required.")
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO cms_articles_translations
			(cms_article_id, language_id, title, excerpt, body, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE title = VALUES(title), excerpt = VALUES(excerpt), body = VALUES(body), updated_at = UTC_TIMESTAMP()`,
			id, tr.LanguageID, tr.Title, nullableString(tr.Excerpt), SanitizeHTML(tr.Body))
		if err != nil {
			return err
		}
	}
	return nil
}

func syncPageTranslations(ctx context.Context, tx *sql.Tx, id int64, translations []PageTranslation) error {
	seen := map[int64]bool{}
	for i, tr := range translations {
		if tr.LanguageID < 1 || seen[tr.LanguageID] {
			return validation(fmt.Sprintf("translations.%d.language_id", i), "The language id must be unique and valid.")
		}
		seen[tr.LanguageID] = true
		if strings.TrimSpace(tr.Title) == "" || len(tr.Title) > 200 {
			return validation(fmt.Sprintf("translations.%d.title", i), "The title field is required and may not exceed 200 characters.")
		}
		if tr.SEOTitle != nil && len(*tr.SEOTitle) > 200 {
			return validation(fmt.Sprintf("translations.%d.seo_title", i), "The seo title may not exceed 200 characters.")
		}
		if tr.SEODescription != nil && len(*tr.SEODescription) > 300 {
			return validation(fmt.Sprintf("translations.%d.seo_description", i), "The seo description may not exceed 300 characters.")
		}
		var exists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM languages WHERE id = ?)`, tr.LanguageID).Scan(&exists); err != nil {
			return err
		}
		if !exists {
			return validation(fmt.Sprintf("translations.%d.language_id", i), "The selected language is invalid.")
		}
		body := ""
		if tr.Body != nil {
			body = *tr.Body
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO cms_pages_translations
			(cms_page_id, language_id, title, body, seo_title, seo_description, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), seo_title = VALUES(seo_title),
			seo_description = VALUES(seo_description), updated_at = UTC_TIMESTAMP()`,
			id, tr.LanguageID, tr.Title, SanitizeHTML(body), nullableString(tr.SEOTitle), nullableString(tr.SEODescription))
		if err != nil {
			return err
		}
	}
	return nil
}

func syncCover(ctx context.Context, tx *sql.Tx, model string, ownerID, mediaID int64, collection string) error {
	var path string
	err := tx.QueryRowContext(ctx, `SELECT path FROM media WHERE id = ? AND collection = ?`, mediaID, collection).Scan(&path)
	if errors.Is(err, sql.ErrNoRows) {
		return validation("cover_media_id", "The selected cover media is invalid.")
	}
	if err != nil {
		return err
	}
	var attachedID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT id FROM media
		WHERE mediable_type = ? AND mediable_id = ? AND collection = ? LIMIT 1`, model, ownerID, collection).Scan(&attachedID); err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	if attachedID.Valid && attachedID.Int64 == mediaID {
		table := "cms_articles"
		if strings.Contains(model, "CmsPage") {
			table = "cms_pages"
		}
		_, err := tx.ExecContext(ctx, `UPDATE `+table+` SET cover_image_path = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`, path, ownerID)
		return err
	}
	if err := removeCover(ctx, tx, model, ownerID, collection); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE media SET mediable_type = ?, mediable_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		model, ownerID, mediaID); err != nil {
		return err
	}
	table := "cms_articles"
	if strings.Contains(model, "CmsPage") {
		table = "cms_pages"
	}
	_, err = tx.ExecContext(ctx, `UPDATE `+table+` SET cover_image_path = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`, path, ownerID)
	return err
}

func removeCover(ctx context.Context, tx *sql.Tx, model string, ownerID int64, collection string) error {
	rows, err := tx.QueryContext(ctx, `SELECT id, disk, path FROM media
		WHERE mediable_type = ? AND mediable_id = ? AND collection = ?`, model, ownerID, collection)
	if err != nil {
		return err
	}
	type oldMedia struct {
		id   int64
		disk string
		path string
	}
	var found []oldMedia
	for rows.Next() {
		var row oldMedia
		if err := rows.Scan(&row.id, &row.disk, &row.path); err != nil {
			rows.Close()
			return err
		}
		found = append(found, row)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	for _, row := range found {
		if row.disk == "public" {
			deleteStoredPath(row.path)
		}
		if _, err := tx.ExecContext(ctx, `DELETE FROM media WHERE id = ?`, row.id); err != nil {
			return err
		}
	}
	return nil
}

func sanitizeAndValidateBlock(typ string, content map[string]any) error {
	if content == nil {
		content = map[string]any{}
	}
	switch typ {
	case "hero":
		if text(content, "heading") == "" || len(text(content, "heading")) > 150 {
			return validation("content.heading", "The heading field is required and may not exceed 150 characters.")
		}
		if err := validateLinks(content, "cta_url"); err != nil {
			return err
		}
	case "banner":
		if err := validateLinks(content, "url"); err != nil {
			return err
		}
	case "category", "article":
		key := "category_ids"
		if typ == "article" {
			key = "article_ids"
		}
		if _, ok := content[key].([]any); !ok {
			return validation("content."+key, "The field must be an array.")
		}
	case "product":
		if limit, ok := content["limit"].(float64); ok && (limit < 1 || limit > 24) {
			return validation("content.limit", "The limit must be between 1 and 24.")
		}
	case "bestseller":
		period := fmt.Sprint(content["period"])
		if period != "7" && period != "30" && period != "90" && period != "all" {
			return validation("content.period", "The selected period is invalid.")
		}
	case "promotional":
		if text(content, "heading") == "" {
			return validation("content.heading", "The heading field is required.")
		}
		if err := validateLinks(content, "url"); err != nil {
			return err
		}
	case "text":
		if _, ok := content["body"].(string); !ok {
			return validation("content.body", "The body field is required.")
		}
	case "image":
		if err := validateLinks(content, "url"); err != nil {
			return err
		}
	case "cta":
		for _, field := range []string{"heading", "button_label", "button_url"} {
			if text(content, field) == "" {
				return validation("content."+field, "The field is required.")
			}
		}
		if err := validateLinks(content, "button_url"); err != nil {
			return err
		}
	case "custom":
		html, ok := content["html"].(string)
		if !ok || len(html) > 20000 {
			return validation("content.html", "The html field is required and may not exceed 20000 characters.")
		}
		content["html"] = SanitizeHTML(html)
	case "social_media", "marketplace_links":
		platforms, ok := content["platforms"].(map[string]any)
		if !ok {
			return validation("content.platforms", "The platforms field is required.")
		}
		for _, value := range platforms {
			if platform, ok := value.(map[string]any); ok {
				if link, ok := platform["url"].(string); ok && link != "" && !ValidateSafeLink(link) {
					return validation("content.platforms", "The URL is invalid.")
				}
			}
		}
	case "whatsapp_chat":
		if text(content, "phone") == "" || text(content, "button_label") == "" {
			return validation("content.phone", "The phone and button label fields are required.")
		}
	case "banner_slider":
		slides, ok := content["slides"].([]any)
		if !ok || len(slides) == 0 || len(slides) > 10 {
			return validation("content.slides", "The slides field must contain between 1 and 10 items.")
		}
		for i, v := range slides {
			slide, ok := v.(map[string]any)
			if !ok {
				return validation(fmt.Sprintf("content.slides.%d", i), "The slide is invalid.")
			}
			if strings.TrimSpace(fmt.Sprint(slide["key"])) == "" {
				return validation(fmt.Sprintf("content.slides.%d.key", i), "The slide key is required.")
			}
			if strings.TrimSpace(fmt.Sprint(slide["image_path"])) == "" {
				return validation(fmt.Sprintf("content.slides.%d.image", i), "Slide image uploads are not supported by this Go handler yet.")
			}
		}
	}
	return nil
}

func resolveAdminSlideURLs(ctx context.Context, content map[string]any) {
	slides, ok := content["slides"].([]any)
	if !ok {
		return
	}
	for _, raw := range slides {
		slide, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		for pathKey, urlKey := range map[string]string{
			"image_path": "image_url", "mobile_image_path": "mobile_image_url",
		} {
			if path, ok := slide[pathKey].(string); ok && path != "" {
				slide[urlKey] = asset.URL(ctx, path)
			} else {
				slide[urlKey] = nil
			}
		}
		delete(slide, "remove_mobile")
	}
}

func validBlockType(typ string) bool {
	switch typ {
	case "hero", "banner", "category", "product", "bestseller", "promotional", "text", "image",
		"article", "cta", "custom", "social_media", "whatsapp_chat", "marketplace_links", "banner_slider":
		return true
	default:
		return false
	}
}

func validSlug(value string, max int) bool {
	return value != "" && utf8.RuneCountInString(value) <= max
}

func validStatus(status string) bool { return status == "draft" || status == "published" }

func parsePublishedAt(raw *string) (any, error) {
	if raw == nil {
		return nil, nil
	}
	if strings.TrimSpace(*raw) == "" {
		return nil, nil
	}
	t, err := parseTime(*raw)
	if err != nil {
		return nil, err
	}
	return t, nil
}

func parseBlockDates(startsRaw, endsRaw *string) (any, any, error) {
	starts, err := parsePublishedAt(startsRaw)
	if err != nil {
		return nil, nil, validation("starts_at", "The starts at field is not a valid date.")
	}
	ends, err := parsePublishedAt(endsRaw)
	if err != nil {
		return nil, nil, validation("ends_at", "The ends at field is not a valid date.")
	}
	if starts != nil && ends != nil && ends.(time.Time).Before(starts.(time.Time)) {
		return nil, nil, validation("ends_at", "The ends at field must be a date after or equal to starts at.")
	}
	return starts, ends, nil
}

func parseTime(raw string) (time.Time, error) {
	layouts := []string{time.RFC3339Nano, "2006-01-02 15:04:05", "2006-01-02"}
	for _, layout := range layouts {
		if t, err := time.Parse(layout, raw); err == nil {
			return t.UTC(), nil
		}
	}
	return time.Time{}, errors.New("invalid datetime")
}

func cloneContent(input map[string]any) map[string]any {
	result := map[string]any{}
	for k, v := range input {
		result[k] = v
	}
	return result
}

func mergeContent(old, incoming map[string]any) map[string]any {
	result := cloneContent(old)
	for key, value := range incoming {
		switch key {
		case "image_path":
			continue
		case "slides":
			result[key] = mergeSlides(old[key], value)
		default:
			result[key] = value
		}
	}
	if path, ok := old["image_path"]; ok {
		result["image_path"] = path
	}
	return result
}

func mergeSlides(oldValue, incomingValue any) any {
	oldSlides, _ := oldValue.([]any)
	newSlides, ok := incomingValue.([]any)
	if !ok {
		return incomingValue
	}
	oldByKey := map[string]map[string]any{}
	for _, raw := range oldSlides {
		if slide, ok := raw.(map[string]any); ok {
			oldByKey[fmt.Sprint(slide["key"])] = slide
		}
	}
	merged := make([]any, 0, len(newSlides))
	for _, raw := range newSlides {
		slide, ok := raw.(map[string]any)
		if !ok {
			merged = append(merged, raw)
			continue
		}
		copy := cloneContent(slide)
		delete(copy, "image_path")
		delete(copy, "mobile_image_path")
		if prior := oldByKey[fmt.Sprint(copy["key"])]; prior != nil {
			if path, ok := prior["image_path"]; ok {
				copy["image_path"] = path
			}
			if path, ok := prior["mobile_image_path"]; ok {
				copy["mobile_image_path"] = path
			}
		}
		merged = append(merged, copy)
	}
	return merged
}

func stripSlideImagePaths(content map[string]any) {
	slides, ok := content["slides"].([]any)
	if !ok {
		return
	}
	for _, raw := range slides {
		if slide, ok := raw.(map[string]any); ok {
			delete(slide, "image_path")
			delete(slide, "mobile_image_path")
		}
	}
}

func deleteStoredPath(value any) {
	path, ok := value.(string)
	if !ok || path == "" {
		return
	}
	clean := filepath.Clean(filepath.FromSlash(path))
	if filepath.IsAbs(clean) || clean == "." || strings.HasPrefix(clean, ".."+string(filepath.Separator)) || clean == ".." {
		return
	}
	root, err := filepath.Abs(media.Root)
	if err != nil {
		return
	}
	target, err := filepath.Abs(filepath.Join(root, clean))
	if err != nil {
		return
	}
	rel, err := filepath.Rel(root, target)
	if err == nil && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		_ = os.Remove(target)
	}
}

func text(content map[string]any, key string) string {
	value, _ := content[key].(string)
	return strings.TrimSpace(value)
}

func validateLinks(content map[string]any, key string) error {
	value, ok := content[key].(string)
	if ok && value != "" && !ValidateSafeLink(value) {
		return validation("content."+key, "The URL is invalid.")
	}
	return nil
}

func lastPage(total, perPage int) int {
	last := (total + perPage - 1) / perPage
	if last < 1 {
		return 1
	}
	return last
}

func valueOr(value *string, fallback string) string {
	if value == nil {
		return fallback
	}
	return *value
}

func nullableString(value *string) any {
	if value == nil {
		return nil
	}
	return *value
}
