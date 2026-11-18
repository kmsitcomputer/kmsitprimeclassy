package auth

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// Service bundles the identity use-cases. It depends only on the DB and the message catalogue, so it can
// be exercised by tests without an HTTP server.
type Service struct {
	database *db.DB
	locale   string
}

// NewService builds the identity service.
func NewService(database *db.DB) *Service { return &Service{database: database} }

// WithLocale returns a copy bound to a request locale (used for message rendering).
func (s *Service) WithLocale(locale string) *Service {
	clone := *s
	clone.locale = locale
	return &clone
}

func (s *Service) t(key string) string { return i18n.Translate(s.locale, key) }

// ClientMeta carries the audit metadata captured from the request (never client-supplied values).
type ClientMeta struct {
	IP        string
	UserAgent string
}

// Login verifies credentials and returns the authenticated user.
//
// Parity notes (AuthController::login):
//   - the identifier is matched against `email` when it looks like an email, otherwise against the
//     normalised `phone`;
//   - a failure is a 422 on the `identifier` field with the auth.login_failed message — not a 401 — because
//     the SPA renders the field error under the input.
func (s *Service) Login(ctx context.Context, identifier, password string, meta ClientMeta) (*User, error) {
	identifier = strings.TrimSpace(identifier)
	user, err := FindUserByIdentifier(ctx, s.database, identifier)
	if errors.Is(err, ErrUserNotFound) {
		return nil, httpx.Validation(map[string][]string{"identifier": {s.t("auth.login_failed")}})
	}
	if err != nil {
		return nil, fmt.Errorf("find user: %w", err)
	}
	if err := VerifyPassword(user.PasswordHash, password); err != nil {
		if errors.Is(err, ErrPasswordMismatch) {
			return nil, httpx.Validation(map[string][]string{"identifier": {s.t("auth.login_failed")}})
		}
		return nil, fmt.Errorf("verify password: %w", err)
	}
	// A non-active account must not be able to log in. Laravel does not gate this in the login path, so this
	// is deliberately NOT enforced here either: reproducing Laravel is the requirement for IMP-010, and
	// tightening it would be an unapproved business-rule change. Recorded as a parity finding (docs/21 §5).
	if err := s.audit(ctx, s.database, user.ID, user.ID, user.Role, "auth.login", meta); err != nil {
		return nil, fmt.Errorf("audit login: %w", err)
	}
	return user, nil
}

// RegisterInput is the public konsumen registration payload.
type RegisterInput struct {
	Name         string
	Email        string
	Phone        string
	Password     string
	PasswordConf string
	ReferralCode string
}

// Register creates a konsumen account with the referral chain and closure rows.
//
// Faithful to AuthController::register + HierarchyService::attachClosures. The only deliberate omission is
// the RegistrationNotifier (welcome e-mail + restricted WhatsApp notice), which is a queued side effect
// owned by the Laravel queue; it is documented as an open gap rather than half-implemented here.
func (s *Service) Register(ctx context.Context, in RegisterInput, meta ClientMeta) (*User, error) {
	fields := map[string][]string{}
	if strings.TrimSpace(in.Name) == "" {
		fields["name"] = []string{s.t("system.field_required")}
	} else if len(in.Name) > 150 {
		fields["name"] = []string{s.t("system.field_invalid")}
	}
	if in.Email != "" && !LooksLikeEmail(in.Email) {
		fields["email"] = []string{s.t("system.field_invalid")}
	}
	normalizedPhone := NormalizePhone(in.Phone)
	if normalizedPhone == "" {
		fields["phone"] = []string{s.t("system.field_required")}
	} else if len(normalizedPhone) > 20 {
		fields["phone"] = []string{s.t("system.field_invalid")}
	}
	if len(in.Password) < 8 {
		fields["password"] = []string{s.t("system.field_invalid")}
	}
	if in.Password != in.PasswordConf {
		fields["password"] = []string{s.t("system.field_invalid")}
	}
	if len(fields) > 0 {
		return nil, httpx.Validation(fields)
	}

	var created *User
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		// Uniqueness is authoritative in the database (unique indexes), but pre-checking gives the same
		// field-level 422 the SPA expects instead of a generic conflict.
		var exists bool
		if in.Email != "" {
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE email = ? AND deleted_at IS NULL)`, strings.TrimSpace(in.Email)).Scan(&exists); err != nil {
				return err
			}
			if exists {
				return httpx.Validation(map[string][]string{"email": {s.t("system.field_invalid")}})
			}
		}
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE phone = ? AND deleted_at IS NULL)`, normalizedPhone).Scan(&exists); err != nil {
			return err
		}
		if exists {
			return httpx.Validation(map[string][]string{"phone": {s.t("system.field_invalid")}})
		}

		chain, err := s.resolveReferralChain(ctx, tx, in.ReferralCode)
		if err != nil {
			return err
		}

		var konsumenRoleID int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM roles WHERE slug = 'konsumen' LIMIT 1`).Scan(&konsumenRoleID); err != nil {
			return fmt.Errorf("konsumen role missing: %w", err)
		}

		hash, err := HashPassword(in.Password)
		if err != nil {
			return err
		}
		var email any
		if strings.TrimSpace(in.Email) != "" {
			email = strings.TrimSpace(in.Email)
		}
		now := time.Now().UTC()
		res, err := tx.ExecContext(ctx, `INSERT INTO users
			(role_id, parent_id, agent_id, korsal_id, sales_id, name, email, phone, password, status, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)`,
			konsumenRoleID, chain.ParentID, chain.AgentID, chain.KorsalID, chain.SalesID,
			strings.TrimSpace(in.Name), email, normalizedPhone, hash, now, now)
		if err != nil {
			if db.IsDuplicateKey(err) {
				return httpx.Validation(map[string][]string{"phone": {s.t("system.field_invalid")}})
			}
			return err
		}
		newID, err := res.LastInsertId()
		if err != nil {
			return err
		}
		parentID := chain.ParentID
		if err := attachClosures(ctx, tx, newID, &parentID); err != nil {
			return err
		}
		created, err = FindUserByID(ctx, tx, newID)
		if err != nil {
			return err
		}
		return s.auditTx(ctx, tx, created.ID, created.ID, created.Role, "auth.registered", meta, map[string]any{
			"actor_role":  created.Role,
			"agent_id":    chain.AgentID,
			"referrer_id": chain.ParentID,
		})
	})
	if err != nil {
		return nil, err
	}
	return created, nil
}

// referralChain is the resolved upline snapshot stored on the new user row.
type referralChain struct {
	ParentID int64
	AgentID  *int64
	KorsalID *int64
	SalesID  *int64
}

// resolveReferralChain is ReferralService::resolveChainByCode, including the "no agent configured" 503.
func (s *Service) resolveReferralChain(ctx context.Context, tx *sql.Tx, code string) (*referralChain, error) {
	code = strings.ToUpper(strings.TrimSpace(code))
	if code == "" {
		// No referral: fall back to the lowest-id active agen, exactly like AuthController::register.
		var agentID int64
		err := tx.QueryRowContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
			WHERE r.slug = 'agen' AND u.status = 'active' AND u.deleted_at IS NULL
			ORDER BY u.id LIMIT 1`).Scan(&agentID)
		if errors.Is(err, sql.ErrNoRows) {
			return nil, &httpx.Error{
				Status:  503,
				Message: s.t("auth.registration_unavailable"),
				Code:    "registration_unavailable",
			}
		}
		if err != nil {
			return nil, err
		}
		return &referralChain{ParentID: agentID, AgentID: &agentID}, nil
	}

	var (
		referrerID int64
		roleSlug   string
		agentID    sql.NullInt64
		korsalID   sql.NullInt64
	)
	err := tx.QueryRowContext(ctx, `SELECT u.id, r.slug, u.agent_id, u.korsal_id
		FROM users u JOIN roles r ON r.id = u.role_id
		WHERE u.referral_code = ? AND u.status = 'active' AND u.deleted_at IS NULL
			AND r.slug IN ('agen','korsal','sales','sales-kurir-sub','sales-kurir')
		LIMIT 1`, code).Scan(&referrerID, &roleSlug, &agentID, &korsalID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &httpx.Error{
			Status:  422,
			Message: s.t("referral.invalid_code"),
			Fields:  map[string][]string{"referral_code": {s.t("referral.code_not_found")}},
		}
	}
	if err != nil {
		return nil, err
	}
	if !agentID.Valid {
		return nil, &httpx.Error{Status: 422, Message: s.t("referral.not_linked_to_agent")}
	}

	chain := &referralChain{ParentID: referrerID}
	agent := agentID.Int64
	chain.AgentID = &agent
	switch CanonicalSlug(roleSlug) {
	case RoleSales, RoleSalesKurirSub:
		sales := referrerID
		chain.SalesID = &sales
		if korsalID.Valid {
			k := korsalID.Int64
			chain.KorsalID = &k
		}
	case RoleKorsal:
		k := referrerID
		chain.KorsalID = &k
	}
	return chain, nil
}

// attachClosures writes the closure-table rows for a new user (HierarchyService::attachClosures).
func attachClosures(ctx context.Context, tx *sql.Tx, userID int64, parentID *int64) error {
	if _, err := tx.ExecContext(ctx, `INSERT INTO user_closures (ancestor_id, descendant_id, depth)
		VALUES (?, ?, 0)
		ON DUPLICATE KEY UPDATE depth = VALUES(depth)`, userID, userID); err != nil {
		return err
	}
	if parentID == nil {
		return nil
	}
	rows, err := tx.QueryContext(ctx, `SELECT ancestor_id, depth FROM user_closures WHERE descendant_id = ?`, *parentID)
	if err != nil {
		return err
	}
	defer rows.Close()
	type ancestor struct {
		id    int64
		depth int
	}
	var ancestors []ancestor
	for rows.Next() {
		var a ancestor
		if err := rows.Scan(&a.id, &a.depth); err != nil {
			return err
		}
		ancestors = append(ancestors, a)
	}
	if err := rows.Err(); err != nil {
		return err
	}
	for _, a := range ancestors {
		if _, err := tx.ExecContext(ctx, `INSERT INTO user_closures (ancestor_id, descendant_id, depth)
			VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE depth = VALUES(depth)`, a.id, userID, a.depth+1); err != nil {
			return err
		}
	}
	return nil
}

// Logout records the audit event. Session destruction is the caller's job (it owns the cookie jar).
func (s *Service) Logout(ctx context.Context, user *User, meta ClientMeta) error {
	if user == nil {
		return nil
	}
	return s.audit(ctx, s.database, user.ID, user.ID, user.Role, "auth.logout", meta)
}

// ChangePassword verifies the current password and stores a new hash.
func (s *Service) ChangePassword(ctx context.Context, user *User, current, next, confirm string) error {
	fields := map[string][]string{}
	if err := VerifyPassword(user.PasswordHash, current); err != nil {
		fields["current_password"] = []string{s.t("system.field_invalid")}
	}
	if len(next) < 8 {
		fields["password"] = []string{s.t("system.field_invalid")}
	}
	if next != confirm {
		fields["password"] = []string{s.t("system.field_invalid")}
	}
	if len(fields) > 0 {
		return httpx.Validation(fields)
	}
	hash, err := HashPassword(next)
	if err != nil {
		return err
	}
	if _, err := s.database.ExecContext(ctx, `UPDATE users SET password = ?, updated_at = ? WHERE id = ?`,
		hash, time.Now().UTC(), user.ID); err != nil {
		return err
	}
	return s.audit(ctx, s.database, user.ID, user.ID, user.Role, "profile.password_changed", ClientMeta{})
}

// execer is the subset used by audit writes (works for both *db.DB and *sql.Tx).
type execer interface {
	ExecContext(ctx context.Context, query string, args ...any) (sql.Result, error)
}

// audit writes an activity_logs row (ActivityLogger::log) with the canonical subject type and redaction.
func (s *Service) audit(ctx context.Context, q execer, causerID, subjectID int64, role, event string, meta ClientMeta) error {
	return s.auditTx(ctx, q, causerID, subjectID, role, event, meta, map[string]any{"actor_role": role})
}

func (s *Service) auditTx(ctx context.Context, q execer, causerID, subjectID int64, role, event string, meta ClientMeta, props map[string]any) error {
	// ActivityLogger redacts credential-looking property keys before persisting.
	for k, v := range props {
		switch strings.ToLower(k) {
		case "password", "password_confirmation", "token", "secret", "api_key", "api_secret", "access_token", "refresh_token":
			props[k] = "[redacted]"
		default:
			_ = v
		}
	}
	var payload any
	if len(props) > 0 {
		buf, err := json.Marshal(props)
		if err != nil {
			return err
		}
		payload = string(buf)
	}
	_, err := q.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, ip_address, user_agent, created_at)
		VALUES (?, 'App\\Models\\User', ?, ?, NULL, ?, ?, ?, ?)`,
		causerID, subjectID, event, payload, nullableStr(meta.IP), truncate(meta.UserAgent, 500), time.Now().UTC())
	return err
}
