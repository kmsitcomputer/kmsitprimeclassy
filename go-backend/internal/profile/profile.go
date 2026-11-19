// Package profile contains self-service profile and referral use cases.
package profile

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/json"
	"errors"
	"math/big"
	"regexp"
	"strings"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

var (
	ErrEmailTaken           = errors.New("email already used")
	ErrPhoneTaken           = errors.New("phone already used")
	ErrReferralCodeNotFound = errors.New("referral code not found")
)

// UpdateInput carries only self-editable profile fields. AvatarSet distinguishes
// an omitted avatar_media_id from explicit null, which clears the avatar.
type UpdateInput struct {
	Name      *string
	Email     *string
	Phone     *string
	AvatarID  *int64
	AvatarSet bool
}

var (
	referralCodePattern = regexp.MustCompile(`^[A-Z0-9-]+$`)
)

func Update(ctx context.Context, d *db.DB, user *auth.User, input UpdateInput, meta auth.ClientMeta) (*auth.User, error) {
	fields := map[string][]string{}
	if input.Name != nil {
		name := strings.TrimSpace(*input.Name)
		input.Name = &name
		if name == "" || utf8.RuneCountInString(name) > 150 {
			fields["name"] = []string{"The name field is invalid."}
		}
	}
	if input.Email != nil {
		email := strings.TrimSpace(*input.Email)
		if !auth.LooksLikeEmail(email) || utf8.RuneCountInString(email) > 255 {
			fields["email"] = []string{"The email must be a valid email address."}
		} else {
			input.Email = &email
		}
	}
	if input.Phone != nil {
		phone := auth.NormalizePhone(*input.Phone)
		if phone == "" || utf8.RuneCountInString(phone) > 20 {
			fields["phone"] = []string{"The phone field is invalid."}
		}
		input.Phone = &phone
	}
	if len(fields) > 0 {
		return nil, httpx.Validation(fields)
	}

	var oldName, oldPhone, oldEmail sql.NullString
	err := d.QueryRowContext(ctx, `SELECT name, phone, email FROM users WHERE id = ? AND deleted_at IS NULL`, user.ID).
		Scan(&oldName, &oldPhone, &oldEmail)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, auth.ErrUserNotFound
	}
	if err != nil {
		return nil, err
	}

	if input.Email != nil {
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE email = ? AND id <> ?)`,
			*input.Email, user.ID).Scan(&exists); err != nil {
			return nil, err
		}
		if exists {
			return nil, ErrEmailTaken
		}
	}
	if input.Phone != nil {
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE phone = ? AND id <> ?)`,
			*input.Phone, user.ID).Scan(&exists); err != nil {
			return nil, err
		}
		if exists {
			return nil, ErrPhoneTaken
		}
	}
	if input.AvatarSet && input.AvatarID != nil {
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM media WHERE id = ?)`, *input.AvatarID).Scan(&exists); err != nil {
			return nil, err
		}
		if !exists {
			return nil, httpx.Validation(map[string][]string{"avatar_media_id": {"The selected avatar media is invalid."}})
		}
	}

	emailChanged := input.Email != nil && !strings.EqualFold(*input.Email, oldEmail.String)
	assignments := []string{"updated_at = UTC_TIMESTAMP()"}
	args := []any{}
	if input.Name != nil {
		assignments = append(assignments, "name = ?")
		args = append(args, *input.Name)
	}
	if input.Email != nil {
		assignments = append(assignments, "email = ?")
		args = append(args, *input.Email)
		if emailChanged {
			assignments = append(assignments, "email_verified_at = NULL")
		}
	}
	if input.Phone != nil {
		assignments = append(assignments, "phone = ?")
		args = append(args, *input.Phone)
	}
	if input.AvatarSet {
		assignments = append(assignments, "avatar_media_id = ?")
		args = append(args, nullableInt64(input.AvatarID))
	}
	args = append(args, user.ID)
	if _, err := d.ExecContext(ctx, `UPDATE users SET `+strings.Join(assignments, ", ")+` WHERE id = ?`, args...); err != nil {
		if db.IsDuplicateKey(err) {
			if input.Email != nil {
				return nil, ErrEmailTaken
			}
			if input.Phone != nil {
				return nil, ErrPhoneTaken
			}
		}
		return nil, err
	}

	newName, newPhone, newEmail := oldName, oldPhone, oldEmail
	if input.Name != nil {
		newName = sql.NullString{String: *input.Name, Valid: true}
	}
	if input.Phone != nil {
		newPhone = sql.NullString{String: *input.Phone, Valid: true}
	}
	if input.Email != nil {
		newEmail = sql.NullString{String: *input.Email, Valid: true}
	}
	properties, err := json.Marshal(map[string]any{
		"old":           map[string]any{"name": nullString(oldName), "phone": nullString(oldPhone), "email": nullString(oldEmail)},
		"new":           map[string]any{"name": nullString(newName), "phone": nullString(newPhone), "email": nullString(newEmail)},
		"email_changed": emailChanged,
	})
	if err != nil {
		return nil, err
	}
	_, err = d.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, 'App\\Models\\User', ?, 'profile.updated', NULL, ?, ?, ?, UTC_TIMESTAMP())`,
		user.ID, user.ID, string(properties), nullableMeta(meta.IP), truncate(meta.UserAgent, 500))
	if err != nil {
		return nil, err
	}
	return auth.FindUserByID(ctx, d, user.ID)
}

// SetReferralCode sets the caller's own code after format and uniqueness checks.
func SetReferralCode(ctx context.Context, d *db.DB, user *auth.User, code string, meta auth.ClientMeta) (*auth.User, error) {
	if !ownsReferralCode(user.Role) {
		return nil, httpx.NewError(403, "This action is unauthorized.")
	}
	code = strings.TrimSpace(code)
	var failures []string
	if code == "" {
		failures = append(failures, "required")
	} else {
		if utf8.RuneCountInString(code) > 30 {
			failures = append(failures, "max:30")
		}
		if !referralCodePattern.MatchString(code) {
			failures = append(failures, "regex")
		}
	}
	if len(failures) != 0 {
		return nil, httpx.Validation(map[string][]string{"referral_code": failures})
	}
	return saveReferralCode(ctx, d, user, &code, "profile.referral_code_updated", meta)
}

// RegenerateReferralCode replaces the caller's code using the canonical role prefix.
func RegenerateReferralCode(ctx context.Context, d *db.DB, user *auth.User, meta auth.ClientMeta) (*auth.User, error) {
	if !ownsReferralCode(user.Role) {
		return nil, httpx.NewError(403, "This action is unauthorized.")
	}
	prefix, ok := map[string]string{
		auth.RoleAgen: "AG-", auth.RoleKorsal: "KO-", auth.RoleSales: "SA-", auth.RoleSalesKurirSub: "SS-",
	}[auth.CanonicalSlug(user.Role)]
	if !ok {
		return nil, httpx.NewError(403, "This action is unauthorized.")
	}
	for range 5 {
		suffix, err := randomCode(6)
		if err != nil {
			return nil, err
		}
		code := prefix + suffix
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE referral_code = ?)`, code).Scan(&exists); err != nil {
			return nil, err
		}
		if !exists {
			return saveReferralCode(ctx, d, user, &code, "profile.referral_code_regenerated", meta)
		}
	}
	return nil, errors.New("could not generate a unique referral code")
}

// DeleteReferralCode removes the caller's own code without changing any referral ancestry.
func DeleteReferralCode(ctx context.Context, d *db.DB, user *auth.User, meta auth.ClientMeta) (*auth.User, error) {
	if !ownsReferralCode(user.Role) {
		return nil, httpx.NewError(403, "This action is unauthorized.")
	}
	return saveReferralCode(ctx, d, user, nil, "profile.referral_code_deleted", meta)
}

func saveReferralCode(ctx context.Context, d *db.DB, user *auth.User, code *string, event string, meta auth.ClientMeta) (*auth.User, error) {
	var old sql.NullString
	if err := d.QueryRowContext(ctx, `SELECT referral_code FROM users WHERE id = ? AND deleted_at IS NULL`, user.ID).Scan(&old); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, auth.ErrUserNotFound
		}
		return nil, err
	}
	if code != nil {
		var exists bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE referral_code = ? AND id <> ?)`,
			*code, user.ID).Scan(&exists); err != nil {
			return nil, err
		}
		if exists {
			return nil, httpx.Validation(map[string][]string{"referral_code": {"unique"}})
		}
	}
	if _, err := d.ExecContext(ctx, `UPDATE users SET referral_code = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?`,
		nullableStringPtr(code), user.ID); err != nil {
		if db.IsDuplicateKey(err) {
			return nil, httpx.Validation(map[string][]string{"referral_code": {"unique"}})
		}
		return nil, err
	}
	propertiesMap := map[string]any{"old": nullString(old)}
	if event != "profile.referral_code_deleted" {
		propertiesMap["new"] = nullableStringPtr(code)
	}
	properties, err := json.Marshal(propertiesMap)
	if err != nil {
		return nil, err
	}
	_, err = d.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, 'App\\Models\\User', ?, ?, NULL, ?, ?, ?, UTC_TIMESTAMP())`,
		user.ID, user.ID, event, string(properties), nullableMeta(meta.IP), truncate(meta.UserAgent, 500))
	if err != nil {
		return nil, err
	}
	return auth.FindUserByID(ctx, d, user.ID)
}

// ReferralPreview exposes only the referrer's name and the associated store name.
func ReferralPreview(ctx context.Context, d *db.DB, rawCode string) (map[string]any, error) {
	code := strings.ToUpper(strings.TrimSpace(rawCode))
	var name string
	var storeName sql.NullString
	err := d.QueryRowContext(ctx, `SELECT u.name, ap.store_name
		FROM users u JOIN roles r ON r.id = u.role_id
		LEFT JOIN agent_profiles ap ON ap.user_id = u.agent_id
		WHERE u.referral_code = ? AND u.status = 'active' AND u.deleted_at IS NULL
			AND r.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir') LIMIT 1`, code).
		Scan(&name, &storeName)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrReferralCodeNotFound
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"referrer_name": name, "agent_store_name": nullString(storeName)}, nil
}

// EmailVerificationState is the safe read-only state: the raw address and OTP code never leave the service.
func EmailVerificationState(ctx context.Context, d *db.DB, userID int64) (map[string]any, error) {
	var email sql.NullString
	var verifiedAt sql.NullTime
	if err := d.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users WHERE id = ? AND deleted_at IS NULL`,
		userID).Scan(&email, &verifiedAt); err != nil {
		return nil, err
	}
	var pending bool
	if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges
		WHERE subject_user_id = ? AND purpose = 'email_verification'
			AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at > UTC_TIMESTAMP())`, userID).Scan(&pending); err != nil {
		return nil, err
	}
	var masked any
	if email.Valid {
		masked = maskEmail(email.String)
	}
	return map[string]any{
		"email_masked": masked, "verified": email.Valid && verifiedAt.Valid, "pending": pending,
	}, nil
}

func randomCode(length int) (string, error) {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	bytes := make([]byte, length)
	for i := range bytes {
		index, err := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		if err != nil {
			return "", err
		}
		bytes[i] = alphabet[index.Int64()]
	}
	return string(bytes), nil
}

func maskEmail(email string) string {
	local, domain, _ := strings.Cut(email, "@")
	runes := []rune(local)
	if len(runes) > 2 {
		runes = runes[:2]
	}
	return string(runes) + "••••@" + domain
}

func nullableString(value sql.NullString) any {
	if !value.Valid {
		return nil
	}
	return value.String
}

func nullString(value sql.NullString) any { return nullableString(value) }

func nullableStringPtr(value *string) any {
	if value == nil {
		return nil
	}
	return *value
}

func nullableInt64(value *int64) any {
	if value == nil {
		return nil
	}
	return *value
}

func nullableMeta(value string) any {
	if value == "" {
		return nil
	}
	return value
}

func truncate(value string, max int) string {
	if len(value) <= max {
		return value
	}
	return value[:max]
}

func ownsReferralCode(role string) bool {
	switch auth.CanonicalSlug(role) {
	case auth.RoleAgen, auth.RoleKorsal, auth.RoleSales, auth.RoleSalesKurirSub:
		return true
	default:
		return false
	}
}
