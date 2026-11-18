// Package media is the Go port of Services/Media/MediaService + config/media.php.
//
// One upload path for EVERY image slot in the system: a collection profile (MIME allowlist, extension allowlist,
// size and dimension caps) is the only thing a new slot adds. A bespoke upload path per endpoint is exactly what
// the reference forbids, because each one re-implements (and weakens) the same validation.
//
// Security properties preserved from the reference:
//   - the MIME type is sniffed from the file's own CONTENT (http.DetectContentType), never from the client's
//     Content-Type header — a renamed .php pretending to be .jpg fails;
//   - the extension stored is chosen from the collection's allowlist, never echoed from the client;
//   - the file must actually DECODE as an image (image.DecodeConfig), which catches forged header bytes;
//   - raster only: no SVG collection exists anywhere, because an SVG can carry an embedded <script>.
//
// The stored file lands under MEDIA_ROOT (the `public` disk root, e.g. storage/app/public) at
// media/<collection>/<uuid>.<ext>, exactly like Laravel's `storeAs`, so the URL contract /storage/... keeps working.
package media

import (
	"bytes"
	"context"
	"crypto/rand"
	"database/sql"
	"errors"
	"fmt"
	"image"
	_ "image/jpeg"
	_ "image/png"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	_ "golang.org/x/image/webp"
)

// Profile is one collection's upload rules.
type Profile struct {
	Mimes      []string
	Extensions []string
	MaxSize    int64
	MaxWidth   int
	MaxHeight  int
}

// Collections mirrors config/media.php.
var Collections = map[string]Profile{
	"cms_article_cover":          {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 4000, MaxHeight: 4000},
	"cms_page_cover":             {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 4000, MaxHeight: 4000},
	"cms_content":                {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 4000, MaxHeight: 4000},
	"product_image":              {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 4000, MaxHeight: 4000},
	"homepage_block":             {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 10 << 20, MaxWidth: 4000, MaxHeight: 4000},
	"site_logo":                  {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 2 << 20, MaxWidth: 2000, MaxHeight: 2000},
	"site_favicon":               {Mimes: []string{"image/png"}, Extensions: []string{"png"}, MaxSize: 512 << 10, MaxWidth: 512, MaxHeight: 512},
	"shipment_proof":             {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 8000, MaxHeight: 8000},
	"fulfillment_handover_proof": {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 8000, MaxHeight: 8000},
	"cod_payment_proof":          {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 5 << 20, MaxWidth: 8000, MaxHeight: 8000},
	"user_avatar":                {Mimes: []string{"image/jpeg", "image/png", "image/webp"}, Extensions: []string{"jpg", "jpeg", "png", "webp"}, MaxSize: 2 << 20, MaxWidth: 2000, MaxHeight: 2000},
}

// Root is the `public` disk root. Configured once at boot from MEDIA_ROOT.
var Root = "storage/app/public"

// SetRoot configures the storage root (the `public` disk).
func SetRoot(root string) {
	if strings.TrimSpace(root) != "" {
		Root = strings.TrimRight(strings.TrimSpace(root), "/")
	}
}

// Upload is one validated client upload.
type Upload struct {
	Filename string
	Content  []byte
}

// Media is the persisted row.
type Media struct {
	ID               int64
	Disk             string
	Path             string
	Collection       string
	OriginalFilename *string
	MimeType         string
	Extension        string
	Size             *int64
	Width, Height    *int
	MediableType     *string
	MediableID       *int64
	CreatedBy        *int64
	CreatedAt        time.Time
}

// Problem is an upload failure mapped to an API error.
type Problem struct {
	Status  int
	Message string
}

func (p *Problem) Error() string { return p.Message }

func problem(status int, message string) *Problem { return &Problem{Status: status, Message: message} }

// messages are resolved by the caller; these keys mirror lang/*/messages.php.
const (
	MsgUnknownCollection = "media.unknown_collection"
	MsgUploadFailed      = "media.upload_failed"
	MsgInvalidType       = "media.invalid_type"
	MsgTooLarge          = "media.too_large"
	MsgDimensions        = "media.dimensions_too_large"
)

// Validate is MediaService::validate — returns the extension to store.
func Validate(collection string, upload Upload) (string, error) {
	profile, ok := Collections[collection]
	if !ok {
		return "", problem(422, MsgUnknownCollection)
	}
	if len(upload.Content) == 0 {
		return "", problem(422, MsgUploadFailed)
	}

	// Sniff the MIME from the content itself, never the client header.
	mime := httpDetectContentType(upload.Content)
	if !contains(profile.Mimes, mime) {
		return "", problem(422, MsgInvalidType)
	}

	clientExtension := strings.ToLower(strings.TrimPrefix(filepath.Ext(upload.Filename), "."))
	if !contains(profile.Extensions, clientExtension) {
		return "", problem(422, MsgInvalidType)
	}

	if int64(len(upload.Content)) > profile.MaxSize {
		return "", problem(422, MsgTooLarge)
	}

	// A real image must actually decode — this catches forged header bytes.
	config, _, err := image.DecodeConfig(bytes.NewReader(upload.Content))
	if err != nil {
		return "", problem(422, MsgInvalidType)
	}
	if profile.MaxWidth > 0 && config.Width > profile.MaxWidth {
		return "", problem(422, MsgDimensions)
	}
	if profile.MaxHeight > 0 && config.Height > profile.MaxHeight {
		return "", problem(422, MsgDimensions)
	}
	return clientExtension, nil
}

// Store validates and persists one upload, creating the media row inside the caller's transaction (the reference
// does exactly this: the file write is not transactional, the row is).
//
// mediableType/mediableID may be nil (an unattached upload, e.g. CKEditor's inline images).
func Store(ctx context.Context, tx *sql.Tx, collection string, upload Upload, mediableType *string, mediableID *int64, createdBy *int64) (*Media, error) {
	extension, err := Validate(collection, upload)
	if err != nil {
		return nil, err
	}
	mime := httpDetectContentType(upload.Content)

	width, height := imageDimensions(upload.Content)
	uuid, err := randomUUID()
	if err != nil {
		return nil, err
	}
	path := "media/" + collection + "/" + uuid + "." + extension

	absolute := filepath.Join(Root, filepath.FromSlash(path))
	if err := os.MkdirAll(filepath.Dir(absolute), 0o755); err != nil {
		return nil, fmt.Errorf("media: create directory: %w", err)
	}
	if err := os.WriteFile(absolute, upload.Content, 0o644); err != nil {
		return nil, fmt.Errorf("media: write file: %w", err)
	}

	original := upload.Filename
	size := int64(len(upload.Content))
	res, err := tx.ExecContext(ctx, `INSERT INTO media
		(disk, path, collection, original_filename, mime_type, extension, size, width, height,
		 mediable_type, mediable_id, created_by, created_at, updated_at)
		VALUES ('public', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
		path, collection, original, mime, extension, size, nullableIntPtr(width), nullableIntPtr(height),
		nullableStrPtr(mediableType), nullableInt64(mediableID), nullableInt64(createdBy))
	if err != nil {
		_ = os.Remove(absolute)
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return &Media{
		ID: id, Disk: "public", Path: path, Collection: collection, OriginalFilename: &original,
		MimeType: mime, Extension: extension, Size: &size, Width: width, Height: height,
		MediableType: mediableType, MediableID: mediableID, CreatedBy: createdBy, CreatedAt: time.Now().UTC(),
	}, nil
}

// URL renders the public-disk URL for a stored path.
func URL(path string) string {
	if path == "" {
		return ""
	}
	return "/storage/" + strings.TrimLeft(path, "/")
}

// ---- helpers ---------------------------------------------------------------------------------------

// httpDetectContentType wraps net/http's sniffer so tests can substitute it.
var httpDetectContentType = detectContentType

func detectContentType(content []byte) string {
	// http.DetectContentType needs at least 512 bytes to be reliable; pad safely.
	buf := content
	if len(buf) < 512 {
		padded := make([]byte, 512)
		copy(padded, buf)
		buf = padded
	}
	return httpSniff(buf)
}

func imageDimensions(content []byte) (*int, *int) {
	config, _, err := image.DecodeConfig(bytes.NewReader(content))
	if err != nil {
		return nil, nil
	}
	w, h := config.Width, config.Height
	return &w, &h
}

func contains(list []string, value string) bool {
	for _, item := range list {
		if item == value {
			return true
		}
	}
	return false
}

func randomUUID() (string, error) {
	buf := make([]byte, 16)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	buf[6] = (buf[6] & 0x0f) | 0x40 // version 4
	buf[8] = (buf[8] & 0x3f) | 0x80 // variant
	return fmt.Sprintf("%x-%x-%x-%x-%x", buf[0:4], buf[4:6], buf[6:8], buf[8:10], buf[10:16]), nil
}

func nullableStrPtr(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullableInt64(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullableIntPtr(v *int) any {
	if v == nil {
		return nil
	}
	return *v
}

var _ = io.Discard
var _ = errors.New
