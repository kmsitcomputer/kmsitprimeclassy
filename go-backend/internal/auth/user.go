package auth

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrUserNotFound is returned when no user row matches.
var ErrUserNotFound = errors.New("user not found")

// User is the identity aggregate the API exposes. Field names and nullability mirror the columns of
// `users` plus the resolved role slug.
type User struct {
	ID             int64
	RoleID         int64
	Role           string // canonical role slug
	Name           string
	Email          *string
	Phone          *string
	AvatarMediaID  *int64
	ReferralCode   *string
	AgentID        *int64
	ParentID       *int64
	KorsalID       *int64
	SalesID        *int64
	Status         string
	PasswordHash   string
	CreatedAt      time.Time
	GoogleLinked   bool
	KorsalName     *string
}

// publicBaseURL is injected once at boot so asset URLs match Laravel's `APP_URL` + `/storage`.
var publicBaseURL string

// SetPublicBaseURL configures the absolute asset base used by resource rendering.
func SetPublicBaseURL(base string) { publicBaseURL = strings.TrimRight(base, "/") }

func storageURL(path string) string {
	if path == "" {
		return ""
	}
	if publicBaseURL == "" {
		return "/storage/" + strings.TrimLeft(path, "/")
	}
	return publicBaseURL + "/storage/" + strings.TrimLeft(path, "/")
}

// AvatarURL resolves avatar_media_id through `media` (User::avatarUrl()).
func (u *User) AvatarURL(ctx context.Context, d *db.DB) *string {
	if u.AvatarMediaID == nil {
		return nil
	}
	var path string
	err := d.QueryRowContext(ctx, `SELECT path FROM media WHERE id = ?`, *u.AvatarMediaID).Scan(&path)
	if err != nil {
		// A dangling avatar reference is not an error the caller must handle: render null, exactly as the
		// Laravel accessor would when the relation is missing.
		return nil
	}
	url := storageURL(path)
	return &url
}

const userColumns = `u.id, u.role_id, COALESCE(r.slug, ''), u.name, u.email, u.phone, u.avatar_media_id,
	u.referral_code, u.agent_id, u.parent_id, u.korsal_id, u.sales_id, u.status, u.password, u.created_at`

func scanUser(scan func(dest ...any) error) (*User, error) {
	var u User
	var email, phone, referral sql.NullString
	var avatar, agent, parent, korsal, sales sql.NullInt64
	err := scan(&u.ID, &u.RoleID, &u.Role, &u.Name, &email, &phone, &avatar,
		&referral, &agent, &parent, &korsal, &sales, &u.Status, &u.PasswordHash, &u.CreatedAt)
	if err != nil {
		return nil, err
	}
	u.Email = strPtr(email)
	u.Phone = strPtr(phone)
	u.AvatarMediaID = db.NullInt64(avatar)
	u.ReferralCode = strPtr(referral)
	u.AgentID = db.NullInt64(agent)
	u.ParentID = db.NullInt64(parent)
	u.KorsalID = db.NullInt64(korsal)
	u.SalesID = db.NullInt64(sales)
	u.Role = CanonicalSlug(u.Role)
	u.CreatedAt = u.CreatedAt.UTC()
	return &u, nil
}

// FindUserByID loads a user with its role slug.
func FindUserByID(ctx context.Context, q queryer, id int64) (*User, error) {
	row := q.QueryRowContext(ctx, `SELECT `+userColumns+`
		FROM users u LEFT JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL`, id)
	u, err := scanUser(row.Scan)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrUserNotFound
	}
	return u, err
}

// FindUserByIdentifier loads a user by email (when the identifier is an email) or by normalised phone.
// This mirrors AuthController::login, which picks the column from the identifier's shape.
func FindUserByIdentifier(ctx context.Context, q queryer, identifier string) (*User, error) {
	column := "phone"
	value := NormalizePhone(identifier)
	if LooksLikeEmail(identifier) {
		column = "email"
		value = strings.TrimSpace(identifier)
	}
	row := q.QueryRowContext(ctx, `SELECT `+userColumns+`
		FROM users u LEFT JOIN roles r ON r.id = u.role_id
		WHERE u.`+column+` = ? AND u.deleted_at IS NULL
		LIMIT 1`, value)
	u, err := scanUser(row.Scan)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrUserNotFound
	}
	return u, err
}

// queryer is the subset of *sql.DB / *sql.Tx the read helpers need.
type queryer interface {
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
	QueryContext(ctx context.Context, query string, args ...any) (*sql.Rows, error)
}

// LooksLikeEmail mirrors filter_var($identifier, FILTER_VALIDATE_EMAIL) for the shapes the API accepts.
func LooksLikeEmail(s string) bool {
	s = strings.TrimSpace(s)
	at := strings.IndexByte(s, '@')
	if at <= 0 || at == len(s)-1 {
		return false
	}
	if strings.ContainsAny(s, " \t\n\r") {
		return false
	}
	domain := s[at+1:]
	dot := strings.IndexByte(domain, '.')
	return dot > 0 && dot < len(domain)-1
}

// NormalizePhone is a faithful port of App\Support\PhoneNormalizer::normalize.
func NormalizePhone(phone string) string {
	p := strings.TrimSpace(phone)
	if p == "" {
		return ""
	}
	var b strings.Builder
	for _, r := range p {
		switch r {
		case ' ', '(', ')', '.', '-':
			continue
		}
		b.WriteRune(r)
	}
	compact := b.String()
	if strings.HasPrefix(compact, "+628") && len(compact) > 4 {
		return "0" + compact[3:]
	}
	if strings.HasPrefix(compact, "628") && len(compact) > 3 {
		return "0" + compact[2:]
	}
	if strings.HasPrefix(compact, "08") {
		return compact
	}
	return p
}

// strPtr turns a nullable column into a JSON-friendly pointer.
func strPtr(v sql.NullString) *string {
	if !v.Valid {
		return nil
	}
	s := v.String
	return &s
}

// KorsalNameFor resolves the assigned Korsal's name for a Sales / Sales-Kurir-Sub user, restricted to the
// same branch (UserResource::korsal_name).
func KorsalNameFor(ctx context.Context, q queryer, u *User) *string {
	if u.KorsalID == nil {
		return nil
	}
	if !u.IsRole(RoleSales, RoleSalesKurirSub) {
		return nil
	}
	var name string
	err := q.QueryRowContext(ctx, `SELECT u.name FROM users u
		JOIN roles r ON r.id = u.role_id
		WHERE u.id = ? AND u.deleted_at IS NULL AND u.agent_id <=> ? AND r.slug IN ('korsal')`,
		*u.KorsalID, nullable(u.AgentID)).Scan(&name)
	if err != nil {
		return nil
	}
	return &name
}

func nullable(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

// UserJSON renders the public user shape (Http\Resources\UserResource) including the own-record-only
// fields. `viewerID` is the authenticated user; only then are google_linked / korsal_name resolved.
func (u *User) UserJSON(ctx context.Context, d *db.DB, viewerID int64) (map[string]any, error) {
	avatar := u.AvatarURL(ctx, d)
	out := map[string]any{
		"id":            u.ID,
		"name":          u.Name,
		"email":         u.Email,
		"phone":         u.Phone,
		"avatar_url":    avatar,
		"role":          u.Role,
		"referral_code": u.ReferralCode,
		"agent_id":      u.AgentID,
		"parent_id":     u.ParentID,
		"korsal_id":     u.KorsalID,
		"sales_id":      u.SalesID,
		"status":        u.Status,
		"created_at":    u.CreatedAt.Format("2006-01-02T15:04:05.000000Z"),
	}
	if viewerID == u.ID {
		linked := false
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM user_social_identities
			WHERE user_id = ? AND provider = 'google')`, u.ID).Scan(&linked); err == nil {
			u.GoogleLinked = linked
		}
		out["google_linked"] = u.GoogleLinked
		// korsal_name is a `when()` field: only Sales / Sales-Kurir-Sub get the key at all. Emitting it for
		// every role (as null) would be a contract difference the SPA can observe.
		if u.IsRole(RoleSales, RoleSalesKurirSub) {
			out["korsal_name"] = KorsalNameFor(ctx, d, u)
		}
	}
	return out, nil
}

// AuthPayload is the exact body GET /auth/me and login/register return:
// { user, permissions } (AuthController::authPayload).
func (u *User) AuthPayload(ctx context.Context, d *db.DB) (map[string]any, error) {
	userJSON, err := u.UserJSON(ctx, d, u.ID)
	if err != nil {
		return nil, fmt.Errorf("user json: %w", err)
	}
	return map[string]any{
		"user":        userJSON,
		"permissions": Permissions(u.Role),
	}, nil
}
