package catalog

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"strings"
	"unicode"

	"github.com/go-sql-driver/mysql"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

var (
	ErrCategoryNotFound  = errors.New("category not found")
	ErrCategoryCycle     = errors.New("category cannot create a hierarchy cycle")
	ErrCategorySelf      = errors.New("category cannot be its own parent")
	ErrCategoryDuplicate = errors.New("category duplicate")
	ErrCategoryNameEmpty = errors.New("category name empty")
	ErrCategoryParent    = errors.New("category parent not found")
	ErrCategorySlug      = errors.New("category slug duplicate")
	ErrCategoryDelete    = errors.New("category delete conflict")
)

type CategoryInput struct {
	ParentID  *int64
	SetParent bool
	Name      *string
	// Slug/IsActive/SortOrder are the presentation controls of the GLOBAL taxonomy: the controller passes
	// them ONLY for a super_admin actor — a create-only role (admin/agen) cannot influence ordering,
	// visibility or the slug, whatever the payload says (ProductCategoryController::store).
	Slug      *string
	IsActive  *bool
	SortOrder *int64
	// ActorRole/ActorAgentID go onto the audit row (ActivityLogger records role + branch identity).
	ActorRole    *string
	ActorAgentID *int64
}

func NormalizeCategoryName(name string) string {
	name = strings.Map(func(r rune) rune {
		if unicode.Is(unicode.Cc, r) || unicode.Is(unicode.Cf, r) || unicode.Is(unicode.Zs, r) {
			if r == ' ' || r == '\t' {
				return ' '
			}
			if unicode.Is(unicode.Zs, r) {
				return ' '
			}
			return ' '
		}
		return r
	}, name)
	fields := strings.Fields(name)
	return strings.TrimSpace(strings.Join(fields, " "))
}

func categoryNameKey(name string) string {
	return strings.ToLower(NormalizeCategoryName(name))
}

func categorySlug(name string) (string, error) {
	slug := strings.ToLower(NormalizeCategoryName(name))
	var b strings.Builder
	lastDash := false
	for _, r := range slug {
		switch {
		case r >= 'a' && r <= 'z', r >= '0' && r <= '9':
			b.WriteRune(r)
			lastDash = false
		case unicode.IsLetter(r) || unicode.IsDigit(r):
			b.WriteRune(unicode.ToLower(r))
			lastDash = false
		default:
			if b.Len() > 0 && !lastDash {
				b.WriteByte('-')
				lastDash = true
			}
		}
	}
	base := strings.Trim(b.String(), "-")
	random := make([]byte, 7) // 13 hex chars: trimmed to match PHP uniqid()
	if _, err := rand.Read(random); err != nil {
		return "", err
	}
	if base == "" {
		base = "kategori"
	}
	token := hex.EncodeToString(random)[:13]
	return base + "-" + token, nil
}

func acquireCategoryLock(ctx context.Context, d *db.DB) (bool, error) {
	var got int
	if err := d.QueryRowContext(ctx, "SELECT GET_LOCK(?, 10)", "primeclassy:product_categories:create").Scan(&got); err != nil {
		return false, err
	}
	return got == 1, nil
}

func releaseCategoryLock(ctx context.Context, d *db.DB) {
	_, _ = d.ExecContext(ctx, "SELECT RELEASE_LOCK(?)", "primeclassy:product_categories:create")
}

type categoryQuerier interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
	QueryRowContext(context.Context, string, ...any) *sql.Row
	ExecContext(context.Context, string, ...any) (sql.Result, error)
}

func lockCategoryTree(ctx context.Context, q categoryQuerier) error {
	rows, err := q.QueryContext(ctx, "SELECT id FROM product_categories ORDER BY id FOR UPDATE")
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return err
		}
	}
	return rows.Err()
}

func loadCategoryRow(ctx context.Context, q categoryQuerier, id int64) (map[string]any, error) {
	var parentID sql.NullInt64
	var sortOrder int64
	var isActive int
	var name, slug string
	err := q.QueryRowContext(ctx, `SELECT id, parent_id, name, slug, is_active, sort_order
		FROM product_categories WHERE id = ?`, id).Scan(new(int64), &parentID, &name, &slug, &isActive, &sortOrder)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrCategoryNotFound
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "parent_id": db.NullInt64(parentID), "name": name, "slug": slug,
		"is_active": isActive == 1, "sort_order": sortOrder,
	}, nil
}

func assertParentExists(ctx context.Context, tx *sql.Tx, parentID *int64) error {
	if parentID == nil {
		return nil
	}
	var id int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM product_categories WHERE id = ? LIMIT 1", *parentID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrCategoryParent
	}
	return err
}

func assertNoSibling(ctx context.Context, tx *sql.Tx, parentID *int64, name string, exceptID *int64) error {
	query := "SELECT id, name FROM product_categories WHERE parent_id IS NULL"
	args := []any{}
	if parentID != nil {
		query = "SELECT id, name FROM product_categories WHERE parent_id = ?"
		args = append(args, *parentID)
	}
	query += " FOR UPDATE"
	rows, err := tx.QueryContext(ctx, query, args...)
	if err != nil {
		return err
	}
	defer rows.Close()
	key := categoryNameKey(name)
	for rows.Next() {
		var id int64
		var siblingName string
		if err := rows.Scan(&id, &siblingName); err != nil {
			return err
		}
		if exceptID != nil && id == *exceptID {
			continue
		}
		if categoryNameKey(siblingName) == key {
			return ErrCategoryDuplicate
		}
	}
	return rows.Err()
}

func assertSlugUnique(ctx context.Context, tx *sql.Tx, slug string, exceptID *int64) error {
	query := "SELECT COUNT(*) FROM product_categories WHERE slug = ?"
	args := []any{slug}
	if exceptID != nil {
		query += " AND id <> ?"
		args = append(args, *exceptID)
	}
	var count int
	if err := tx.QueryRowContext(ctx, query, args...).Scan(&count); err != nil {
		return err
	}
	if count > 0 {
		return ErrCategorySlug
	}
	return nil
}

func descendants(ctx context.Context, tx *sql.Tx, categoryID int64) (map[int64]bool, error) {
	rows, err := tx.QueryContext(ctx, "SELECT id, parent_id FROM product_categories WHERE parent_id IS NOT NULL")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	children := map[int64][]int64{}
	for rows.Next() {
		var id int64
		var parentID int64
		if err := rows.Scan(&id, &parentID); err != nil {
			return nil, err
		}
		children[parentID] = append(children[parentID], id)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	out := map[int64]bool{categoryID: true}
	queue := []int64{categoryID}
	for len(queue) > 0 {
		current := queue[0]
		queue = queue[1:]
		for _, child := range children[current] {
			if !out[child] {
				out[child] = true
				queue = append(queue, child)
			}
		}
	}
	return out, nil
}

func writeCategoryActivity(ctx context.Context, tx *sql.Tx, actorID int64, categoryID int64, event, description string, properties map[string]any) error {
	payload := any(nil)
	if len(properties) > 0 {
		raw, err := json.Marshal(properties)
		if err != nil {
			return err
		}
		payload = string(raw)
	}
	var causer any
	if actorID > 0 {
		causer = actorID
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, UTC_TIMESTAMP())`,
		causer, "App\\Models\\ProductCategory", categoryID, event, description, payload)
	return err
}

func CreateCategory(ctx context.Context, d *db.DB, actorID int64, input CategoryInput) (map[string]any, error) {
	name := ""
	if input.Name != nil {
		name = NormalizeCategoryName(*input.Name)
	}
	if name == "" {
		return nil, ErrCategoryDuplicate
	}
	acquired, err := acquireCategoryLock(ctx, d)
	if err != nil {
		return nil, err
	}
	if !acquired {
		return nil, errors.New("category lock timeout")
	}
	defer releaseCategoryLock(ctx, d)

	var result map[string]any
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockCategoryTree(ctx, tx); err != nil {
			return err
		}
		if err := assertParentExists(ctx, tx, input.ParentID); err != nil {
			return err
		}
		if err := assertNoSibling(ctx, tx, input.ParentID, name, nil); err != nil {
			return err
		}
		slug := ""
		if input.Slug != nil {
			slug = *input.Slug
		} else {
			slug, err = categorySlug(name)
			if err != nil {
				return err
			}
		}
		if err := assertSlugUnique(ctx, tx, slug, nil); err != nil {
			return err
		}
		isActive := int64(1)
		if input.IsActive != nil && !*input.IsActive {
			isActive = 0
		}
		sortOrder := int64(0)
		if input.SortOrder != nil {
			sortOrder = *input.SortOrder
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO product_categories
			(parent_id, name, name_key, slug, is_active, sort_order, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			input.ParentID, name, categoryNameKey(name), slug, isActive, sortOrder)
		if err != nil {
			if db.IsDuplicateKey(err) {
				return ErrCategoryDuplicate
			}
			return err
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		row, err := loadCategoryRow(ctx, tx, id)
		if err != nil {
			return err
		}
		result = row
		return writeCategoryActivity(ctx, tx, actorID, id, "category.create", "Kategori dibuat (taksonomi global)", map[string]any{
			"category_id": id,
			"parent_id":   input.ParentID,
			"actor_role":  input.ActorRole,
			"agent_id":    input.ActorAgentID,
			"result":      "success",
		})
	})
	return result, err
}

func UpdateCategory(ctx context.Context, d *db.DB, actorID int64, id int64, input CategoryInput) (map[string]any, error) {
	acquired, err := acquireCategoryLock(ctx, d)
	if err != nil {
		return nil, err
	}
	if !acquired {
		return nil, errors.New("category lock timeout")
	}
	defer releaseCategoryLock(ctx, d)

	var result map[string]any
	err = d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockCategoryTree(ctx, tx); err != nil {
			return err
		}
		var currentParent sql.NullInt64
		var currentName, currentSlug string
		err := tx.QueryRowContext(ctx, "SELECT parent_id, name, slug FROM product_categories WHERE id = ?", id).
			Scan(&currentParent, &currentName, &currentSlug)
		if errors.Is(err, sql.ErrNoRows) {
			return ErrCategoryNotFound
		}
		if err != nil {
			return err
		}

		newParent := db.NullInt64(currentParent)
		if input.SetParent {
			newParent = input.ParentID
		}
		newName := currentName
		if input.Name != nil {
			newName = NormalizeCategoryName(*input.Name)
			if newName == "" {
				return ErrCategoryNameEmpty
			}
		}
		if newParent != nil {
			if *newParent == id {
				return ErrCategorySelf
			}
			if err := assertParentExists(ctx, tx, newParent); err != nil {
				return err
			}
			desc, err := descendants(ctx, tx, id)
			if err != nil {
				return err
			}
			if desc[*newParent] {
				return ErrCategoryCycle
			}
		}
		if err := assertNoSibling(ctx, tx, newParent, newName, &id); err != nil {
			return err
		}
		newSlug := currentSlug
		if input.Slug != nil {
			newSlug = *input.Slug
		}
		if err := assertSlugUnique(ctx, tx, newSlug, &id); err != nil {
			return err
		}
		sets := []string{"name = ?", "name_key = ?", "slug = ?", "parent_id = ?", "updated_at = UTC_TIMESTAMP()"}
		args := []any{newName, categoryNameKey(newName), newSlug, newParent}
		if input.IsActive != nil {
			sets = append(sets, "is_active = ?")
			if *input.IsActive {
				args = append(args, 1)
			} else {
				args = append(args, 0)
			}
		}
		if input.SortOrder != nil {
			sets = append(sets, "sort_order = ?")
			args = append(args, *input.SortOrder)
		}
		args = append(args, id)
		_, err = tx.ExecContext(ctx, "UPDATE product_categories SET "+strings.Join(sets, ", ")+" WHERE id = ?", args...)
		if err != nil {
			if db.IsDuplicateKey(err) {
				return ErrCategoryDuplicate
			}
			return err
		}
		result, err = loadCategoryRow(ctx, tx, id)
		if err != nil {
			return err
		}
		_ = actorID
		return nil
	})
	return result, err
}

func DeleteCategory(ctx context.Context, d *db.DB, id int64) error {
	acquired, err := acquireCategoryLock(ctx, d)
	if err != nil {
		return err
	}
	if !acquired {
		return errors.New("category lock timeout")
	}
	defer releaseCategoryLock(ctx, d)
	return d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := lockCategoryTree(ctx, tx); err != nil {
			return err
		}
		var exists int
		if err := tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM product_categories WHERE id = ?", id).Scan(&exists); err != nil {
			return err
		}
		if exists == 0 {
			return ErrCategoryNotFound
		}
		_, err := tx.ExecContext(ctx, "DELETE FROM product_categories WHERE id = ?", id)
		if err != nil {
			if db.IsDuplicateKey(err) {
				return ErrCategoryDuplicate
			}
			var me *mysql.MySQLError
			if errors.As(err, &me) && (me.Number == 1451 || me.Number == 23000) {
				return ErrCategoryDelete
			}
			return err
		}
		return nil
	})
}
