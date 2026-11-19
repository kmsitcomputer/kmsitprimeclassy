// Package users ports user administration and referral-network operations.
package users

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"math/big"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

var ErrNotFound = errors.New("user not found")

// Error is a domain error rendered through the Laravel-compatible API envelope.
type Error struct {
	Status  int
	Message string
	Fields  map[string][]string
}

func (e *Error) Error() string { return e.Message }

func fail(status int, message string) error { return &Error{Status: status, Message: message} }

func failFields(status int, message string, fields map[string][]string) error {
	return &Error{Status: status, Message: message, Fields: fields}
}

// validationFailed is Laravel's ONE ValidationException message (BaseFormRequest::failedValidation).
func validationFailed(fields map[string][]string) error {
	return failFields(422, i18n.Translate(i18n.Default, "system.validation_failed"), fields)
}

// unauthorized is a FormRequest::failedAuthorization envelope ('system.unauthorized_action', 403) —
// used by CreateUserRequest::authorize, which fails BEFORE validation.
func unauthorized() error {
	return fail(403, i18n.Translate(i18n.Default, "system.unauthorized_action"))
}

// policyDenied is a CONTROLLER $this->authorize() denial: Laravel renders the AuthorizationException
// with its own default text ('This action is unauthorized.') — observed on the running reference
// (sales deleting a user answers exactly this sentence), unlike the ApiResponse override which only
// fires for FormRequest authorization failures.
func policyDenied() error { return fail(403, "This action is unauthorized.") }

// v renders one validation sentence through the catalogue (:attribute resolved via validation.attributes).
func v(rule, field string, params ...string) string {
	return i18n.ValidationMessage(i18n.Default, rule, field, params...)
}

func fieldMsg(rule, field string, params ...string) []string {
	return []string{v(rule, field, params...)}
}

// Service owns the user-administration use cases. It never trusts role/agent hierarchy data from a caller.
type Service struct {
	database *db.DB
}

func New(database *db.DB) *Service { return &Service{database: database} }

type Filters struct {
	Actor   *auth.User
	AgentID int64
	Role    string
	Search  string
	Status  string
	Page    int
	PerPage int
}

type Page struct {
	Users   []*auth.User
	Current int
	Last    int
	Total   int
	PerPage int
}

var visibleRoles = []string{
	auth.RoleAgen, auth.RoleKorsal, auth.RoleSales, auth.RoleSalesKurirSub, "sales-kurir",
	auth.RoleKonsumen, auth.RoleAdmin, auth.RoleKeuangan, auth.RoleKurir, auth.RoleGudang,
	auth.RoleKoordinatorKurir,
}

func CanCreate(actor *auth.User, target string) bool {
	if actor == nil {
		return false
	}
	target = auth.CanonicalSlug(target)
	switch {
	case actor.IsRole(auth.RoleSuperAdmin):
		return target == auth.RoleAgen
	case actor.IsRole(auth.RoleAgen):
		switch target {
		case auth.RoleKorsal, auth.RoleSales, auth.RoleAdmin, auth.RoleKeuangan, auth.RoleKurir, auth.RoleGudang, auth.RoleSalesKurirSub, auth.RoleKoordinatorKurir:
			return true
		}
	case actor.IsRole(auth.RoleKorsal):
		return target == auth.RoleSales || target == auth.RoleSalesKurirSub
	case actor.IsRole(auth.RoleKoordinatorKurir):
		return target == auth.RoleKurir
	}
	return false
}

func CanView(actor, target *auth.User) bool {
	if actor == nil || target == nil {
		return false
	}
	if actor.IsRole(auth.RoleSuperAdmin) || actor.ID == target.ID {
		return true
	}
	if actor.IsRole(auth.RoleKoordinatorKurir) {
		return actor.AgentID != nil && target.AgentID != nil && *target.AgentID == *actor.AgentID &&
			target.IsRole(auth.RoleKurir) && target.ParentID != nil && *target.ParentID == actor.ID
	}
	if actor.IsRole(auth.RoleAgen, auth.RoleAdmin, auth.RoleKeuangan) {
		return sameID(actor.AgentID, target.AgentID)
	}
	if actor.IsRole(auth.RoleKorsal) {
		return target.KorsalID != nil && *target.KorsalID == actor.ID
	}
	if actor.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) {
		return target.SalesID != nil && *target.SalesID == actor.ID
	}
	return false
}

func CanUpdate(actor, target *auth.User) bool {
	if actor == nil || target == nil {
		return false
	}
	if actor.IsRole(auth.RoleKoordinatorKurir) {
		return actor.AgentID != nil && sameID(actor.AgentID, target.AgentID) &&
			target.IsRole(auth.RoleKurir) && target.ParentID != nil && *target.ParentID == actor.ID
	}
	if actor.IsRole(auth.RoleKeuangan) {
		return actor.ID == target.ID
	}
	return CanView(actor, target)
}

func CanDelete(actor, target *auth.User) bool {
	if actor == nil || target == nil || actor.ID == target.ID {
		return false
	}
	if actor.IsRole(auth.RoleSuperAdmin) {
		return !target.IsRole(auth.RoleSuperAdmin, auth.RoleAgen)
	}
	return actor.IsRole(auth.RoleAgen) && actor.AgentID != nil &&
		sameID(actor.AgentID, target.AgentID) &&
		!target.IsRole(auth.RoleSuperAdmin, auth.RoleAgen)
}

func sameID(a, b *int64) bool { return a != nil && b != nil && *a == *b }

func (s *Service) Find(ctx context.Context, id int64) (*auth.User, error) {
	return auth.FindUserByID(ctx, s.database, id)
}

func (s *Service) List(ctx context.Context, f Filters) (*Page, error) {
	if f.Actor == nil {
		return nil, fail(401, "Silakan login terlebih dahulu.")
	}
	if f.Status != "" && f.Status != "active" && f.Status != "inactive" && f.Status != "suspended" {
		return nil, &Error{Status: 422, Message: "Status tidak valid.", Fields: map[string][]string{"status": {"Status tidak valid."}}}
	}
	if f.PerPage == 0 {
		f.PerPage = 15
	}
	f.Page = max(1, f.Page)
	f.PerPage = min(max(f.PerPage, 1), 100)

	where := []string{"u.deleted_at IS NULL", "r.slug IN (" + placeholders(len(visibleRoles)) + ")"}
	args := make([]any, 0, len(visibleRoles)+8)
	for _, role := range visibleRoles {
		args = append(args, role)
	}
	if f.Actor.IsRole(auth.RoleSuperAdmin) {
		if f.AgentID > 0 {
			where = append(where, "u.agent_id = ?")
			args = append(args, f.AgentID)
		}
	} else {
		where = append(where, "u.agent_id = ?")
		args = append(args, nullableInt(f.Actor.AgentID))
	}
	if f.Actor.IsRole(auth.RoleKorsal) {
		where = append(where, "(u.korsal_id = ? OR u.id = ?)")
		args = append(args, f.Actor.ID, f.Actor.ID)
	} else if f.Actor.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) {
		where = append(where, "(u.sales_id = ? OR u.id = ?)")
		args = append(args, f.Actor.ID, f.Actor.ID)
	} else if f.Actor.IsRole(auth.RoleKoordinatorKurir) {
		where = append(where, "u.parent_id = ? AND r.slug = 'kurir'")
		args = append(args, f.Actor.ID)
	}
	if f.Role != "" {
		role := auth.CanonicalSlug(strings.TrimSpace(f.Role))
		if role == auth.RoleSalesKurirSub {
			where = append(where, "r.slug IN (?, ?)")
			args = append(args, auth.RoleSalesKurirSub, "sales-kurir")
		} else {
			where = append(where, "r.slug = ?")
			args = append(args, role)
		}
	}
	if f.Status != "" {
		where = append(where, "u.status = ?")
		args = append(args, f.Status)
	}
	if f.Search != "" {
		where = append(where, "(u.name LIKE ? ESCAPE '\\\\' OR u.phone LIKE ? ESCAPE '\\\\' OR u.email LIKE ? ESCAPE '\\\\')")
		search := "%" + escapeLike(f.Search) + "%"
		args = append(args, search, search, search)
	}
	clause := strings.Join(where, " AND ")
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE `+clause, args...).Scan(&total); err != nil {
		return nil, err
	}
	page := &Page{Current: f.Page, Last: max(1, (total+f.PerPage-1)/f.PerPage), Total: total, PerPage: f.PerPage, Users: []*auth.User{}}
	queryArgs := append(append([]any{}, args...), f.PerPage, (f.Page-1)*f.PerPage)
	rows, err := s.database.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE `+clause+` ORDER BY u.id LIMIT ? OFFSET ?`, queryArgs...)
	if err != nil {
		return nil, err
	}
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return nil, err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	for _, id := range ids {
		u, err := s.Find(ctx, id)
		if err != nil {
			return nil, err
		}
		page.Users = append(page.Users, u)
	}
	return page, nil
}

func (s *Service) Create(ctx context.Context, actor *auth.User, input CreateInput) (*auth.User, error) {
	role := auth.CanonicalSlug(strings.TrimSpace(input.Role))
	// CreateUserRequest::authorize runs BEFORE validation: a target the actor may never create answers
	// AuthorizationException (403 'Anda tidak memiliki akses...') even when the payload is also invalid.
	if !CanCreate(actor, role) {
		return nil, unauthorized()
	}
	if err := s.validateCreate(ctx, actor, input); err != nil {
		return nil, err
	}
	parentID, agentID, korsalID, err := s.creationScope(ctx, actor, role, input)
	if err != nil {
		return nil, err
	}
	roleID, err := s.roleID(ctx, role)
	if err != nil {
		return nil, err
	}
	password, err := auth.HashPassword(input.Password)
	if err != nil {
		return nil, err
	}
	referral := ""
	switch role {
	case auth.RoleAgen:
		referral = "AG-"
	case auth.RoleKorsal:
		referral = "KO-"
	case auth.RoleSales:
		referral = "SA-"
	case auth.RoleSalesKurirSub:
		referral = "SS-"
	}
	var createdID int64
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if exists, err := uniqueConflict(ctx, tx, input.Email, input.Phone, 0); err != nil {
			return err
		} else if exists {
			return validationFailed(uniqueFields(ctx, tx, input.Email, input.Phone, 0))
		}
		code := any(nil)
		if referral != "" {
			candidate, err := s.referralCode(ctx, tx, referral)
			if err != nil {
				return err
			}
			code = candidate
		}
		result, err := tx.ExecContext(ctx, `INSERT INTO users
			(role_id,parent_id,agent_id,korsal_id,sales_id,name,email,phone,password,referral_code,status,created_at,updated_at)
			VALUES (?,?,?,?,NULL,?,?,?,?,?,'active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			roleID, nullableInt(parentID), nullableInt(agentID), nullableInt(korsalID),
			strings.TrimSpace(input.Name), strings.TrimSpace(input.Email), strings.TrimSpace(input.Phone), password, code)
		if err != nil {
			return err
		}
		createdID, err = result.LastInsertId()
		if err != nil {
			return err
		}
		if role == auth.RoleAgen {
			if _, err := tx.ExecContext(ctx, `UPDATE users SET agent_id=? WHERE id=?`, createdID, createdID); err != nil {
				return err
			}
			agentID = &createdID
		}
		if err := attachClosure(ctx, tx, createdID, parentID); err != nil {
			return err
		}
		if role == auth.RoleKurir || role == auth.RoleSalesKurirSub {
			_, err = tx.ExecContext(ctx, `INSERT INTO couriers (type,user_id,agent_id,name,is_active,created_at,updated_at)
				VALUES ('internal',?,?,?,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
				ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)`,
				createdID, nullableInt(agentID), strings.TrimSpace(input.Name))
			if err != nil {
				return err
			}
		}
		return logAudit(ctx, tx, actor.ID, createdID, "user.created", map[string]any{
			"actor_role": actor.Role, "target_role": role,
		})
	})
	if err != nil {
		return nil, err
	}
	return s.Find(ctx, createdID)
}

type CreateInput struct {
	Role         string
	Name         string
	Email        string
	Phone        string
	Password     string
	Confirmation string
	AgentID      *int64
	KorsalID     *int64
}

// validateCreate is CreateUserRequest::rules with Laravel's id-locale sentences. Phone is already
// normalised by prepareForValidation (the handler normalises before calling). Errors ACCUMULATE —
// Laravel reports every failing rule, not just the first.
func (s *Service) validateCreate(ctx context.Context, actor *auth.User, in CreateInput) error {
	fields := map[string][]string{}
	// agent_id: ['nullable','integer', Rule::in([actor.agent_id])] — a mismatched branch id is a
	// validation failure ("agen yang dipilih tidak valid."), never the service error.
	if in.AgentID != nil && !actor.IsRole(auth.RoleSuperAdmin) {
		if actor.AgentID == nil || *in.AgentID != *actor.AgentID {
			fields["agent_id"] = fieldMsg("in", "agent_id")
		}
	}
	if strings.TrimSpace(in.Name) == "" {
		fields["name"] = fieldMsg("required", "name")
	} else if len([]rune(in.Name)) > 150 {
		fields["name"] = fieldMsg("max.string", "name", "max", "150")
	}
	if strings.TrimSpace(in.Email) == "" {
		fields["email"] = fieldMsg("required", "email")
	} else {
		if !auth.LooksLikeEmail(in.Email) {
			fields["email"] = fieldMsg("email", "email")
		}
		if len([]rune(in.Email)) > 255 {
			fields["email"] = append(fields["email"], fieldMsg("max.string", "email", "max", "255")...)
		}
	}
	if strings.TrimSpace(in.Phone) == "" {
		fields["phone"] = fieldMsg("required", "phone")
	} else if len([]rune(in.Phone)) > 20 {
		fields["phone"] = fieldMsg("max.string", "phone", "max", "20")
	}
	if len(in.Password) < 8 {
		fields["password"] = fieldMsg("min.string", "password", "min", "8")
	}
	if in.Password != in.Confirmation {
		// The confirmed rule reports against the 'password' attribute itself.
		fields["password"] = append(fields["password"], fieldMsg("confirmed", "password")...)
	}

	// korsal_id: required when an AGEN creates a sales/sales-kurir-sub; must be a korsal user under the
	// actor's branch (the exists query); a korsal actor may only ever submit its own id.
	needsKorsal := (in.Role == auth.RoleSales || in.Role == auth.RoleSalesKurirSub) && actor.IsRole(auth.RoleAgen)
	if needsKorsal && in.KorsalID == nil {
		fields["korsal_id"] = fieldMsg("required", "korsal_id")
	} else if in.KorsalID != nil {
		var valid bool
		if s.database == nil {
			// Unit-test seam: a service without a database cannot resolve the exists rule.
			valid = true
		} else {
			err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id
				WHERE u.id=? AND u.agent_id=? AND u.deleted_at IS NULL AND r.slug='korsal')`, *in.KorsalID, derefAgent(actor)).Scan(&valid)
			if err != nil {
				return err
			}
		}
		if !valid {
			fields["korsal_id"] = fieldMsg("exists", "korsal_id")
		} else if actor.IsRole(auth.RoleKorsal) && *in.KorsalID != actor.ID {
			fields["korsal_id"] = fieldMsg("in", "korsal_id")
		}
	}

	// unique email/phone — Laravel runs these as ordinary validation rules, so they ACCUMULATE into the
	// same envelope as every other failure (the table query is not soft-delete aware).
	if s.database != nil {
		// unique email/phone — Laravel runs these as ordinary validation rules, so they ACCUMULATE into
		// the same envelope as every other failure (the table query is not soft-delete aware).
		if strings.TrimSpace(in.Email) != "" {
			var exists bool
			if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE email=? AND id<>?)`, in.Email, 0).Scan(&exists); err != nil {
				return err
			}
			if exists {
				fields["email"] = fieldMsg("unique", "email")
			}
		}
		if strings.TrimSpace(in.Phone) != "" {
			var exists bool
			if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE phone=? AND id<>?)`, in.Phone, 0).Scan(&exists); err != nil {
				return err
			}
			if exists {
				fields["phone"] = fieldMsg("unique", "phone")
			}
		}
	}
	if len(fields) > 0 {
		return validationFailed(fields)
	}
	return nil
}

func derefAgent(u *auth.User) int64 {
	if u.AgentID == nil {
		return -1
	}
	return *u.AgentID
}

func (s *Service) creationScope(ctx context.Context, actor *auth.User, role string, in CreateInput) (*int64, *int64, *int64, error) {
	if actor.IsRole(auth.RoleSuperAdmin) {
		// Laravel's create match arm for 'agen' ignores any client agent_id entirely
		// ([null, null, null] then self-reference) — no error, no honored value.
		return nil, nil, nil, nil
	}
	if actor.AgentID == nil || (actor.IsRole(auth.RoleAgen) && *actor.AgentID != actor.ID) {
		return nil, nil, nil, fail(422, "Network Agen tidak valid.")
	}
	parentID, agentID := actor.ID, *actor.AgentID
	var korsalID *int64
	switch role {
	case auth.RoleKorsal, auth.RoleAdmin, auth.RoleKeuangan, auth.RoleGudang, auth.RoleKoordinatorKurir:
		if !actor.IsRole(auth.RoleAgen) && !(role == auth.RoleKurir && actor.IsRole(auth.RoleKoordinatorKurir)) {
			return nil, nil, nil, unauthorized()
		}
	case auth.RoleSales, auth.RoleSalesKurirSub:
		if actor.IsRole(auth.RoleKorsal) {
			korsalID = int64Ptr(actor.ID)
			if in.KorsalID != nil && *in.KorsalID != actor.ID {
				return nil, nil, nil, fail(422, "Korsal hanya dapat membuat Sales di bawah dirinya.")
			}
		} else if actor.IsRole(auth.RoleAgen) {
			// Validated already; the service arm mirrors Laravel's [$korsalId, creator.id, korsalId].
			korsalID = int64Ptr(*in.KorsalID)
			parentID = *in.KorsalID
		} else {
			return nil, nil, nil, unauthorized()
		}
	case auth.RoleKurir:
		if actor.IsRole(auth.RoleKoordinatorKurir) {
			if in.AgentID != nil && *in.AgentID != agentID {
				return nil, nil, nil, fail(422, "Tidak dapat membuat user pada network Agen lain.")
			}
		} else if !actor.IsRole(auth.RoleAgen) {
			return nil, nil, nil, fail(403, "Role target tidak diizinkan.")
		}
	default:
		return nil, nil, nil, fail(422, "Kombinasi role tidak didukung.")
	}
	if role == auth.RoleKurir && actor.IsRole(auth.RoleKoordinatorKurir) {
		parentID = actor.ID
	}
	return int64Ptr(parentID), int64Ptr(agentID), korsalID, nil
}

func (s *Service) Update(ctx context.Context, actor, target *auth.User, patch UpdateInput) (*auth.User, error) {
	// UserPolicy::update — KEUANGAN may only ever edit its own profile through ProfileController.
	if !CanUpdate(actor, target) {
		return nil, policyDenied()
	}
	fields := map[string][]string{}
	if patch.Name.Present {
		if len([]rune(patch.Name.Value)) > 150 {
			fields["name"] = fieldMsg("max.string", "name", "max", "150")
		}
	}
	if patch.Phone.Present && len([]rune(patch.Phone.Value)) > 20 {
		fields["phone"] = fieldMsg("max.string", "phone", "max", "20")
	}
	if patch.Status.Present && patch.Status.Value != "active" && patch.Status.Value != "inactive" && patch.Status.Value != "suspended" {
		fields["status"] = fieldMsg("in", "status")
	}
	if len(fields) > 0 {
		return nil, validationFailed(fields)
	}
	old := map[string]any{"name": target.Name, "phone": target.Phone, "status": target.Status}
	sets, args := []string{}, []any{}
	if patch.Name.Present {
		sets = append(sets, "name=?")
		args = append(args, strings.TrimSpace(patch.Name.Value))
	}
	if patch.Phone.Present {
		sets = append(sets, "phone=?")
		args = append(args, strings.TrimSpace(patch.Phone.Value))
	}
	if patch.Status.Present {
		sets = append(sets, "status=?")
		args = append(args, patch.Status.Value)
	}
	if err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if patch.Phone.Present && strings.TrimSpace(patch.Phone.Value) != "" {
			var exists bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE phone=? AND id<>? AND deleted_at IS NULL)`, strings.TrimSpace(patch.Phone.Value), target.ID).Scan(&exists); err != nil {
				return err
			}
			if exists {
				return validationFailed(map[string][]string{"phone": fieldMsg("unique", "phone")})
			}
		}
		if len(sets) > 0 {
			sets = append(sets, "updated_at=UTC_TIMESTAMP(6)")
			args = append(args, target.ID)
			if _, err := tx.ExecContext(ctx, `UPDATE users SET `+strings.Join(sets, ",")+` WHERE id=? AND deleted_at IS NULL`, args...); err != nil {
				return err
			}
		}
		newData := map[string]any{"name": target.Name, "phone": target.Phone, "status": target.Status}
		if patch.Name.Present {
			newData["name"] = strings.TrimSpace(patch.Name.Value)
		}
		if patch.Phone.Present {
			newData["phone"] = strings.TrimSpace(patch.Phone.Value)
		}
		if patch.Status.Present {
			newData["status"] = patch.Status.Value
		}
		return logAudit(ctx, tx, actor.ID, target.ID, "user.updated", map[string]any{
			"actor_role": actor.Role, "old": old,
			"new": newData,
		})
	}); err != nil {
		return nil, err
	}
	return s.Find(ctx, target.ID)
}

type Field[T any] struct {
	Present bool
	Value   T
}
type UpdateInput struct {
	Name   Field[string]
	Phone  Field[string]
	Status Field[string]
}

func (s *Service) Delete(ctx context.Context, actor, target *auth.User) error {
	if target.IsRole(auth.RoleSuperAdmin) {
		return fail(403, "Akun Super Admin tidak dapat dihapus.")
	}
	if !CanDelete(actor, target) {
		return policyDenied()
	}
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if target.IsRole(auth.RoleAgen) {
			if _, err := tx.ExecContext(ctx, `DELETE FROM agent_profiles WHERE user_id=?`, target.ID); err != nil {
				return err
			}
		}
		_, err := tx.ExecContext(ctx, `UPDATE users SET deleted_at=UTC_TIMESTAMP(6), updated_at=UTC_TIMESTAMP(6) WHERE id=? AND deleted_at IS NULL`, target.ID)
		if err != nil {
			return err
		}
		return logAudit(ctx, tx, actor.ID, target.ID, "user.deleted", map[string]any{
			"actor_role": actor.Role, "target_role": target.Role,
		})
	})
}

func (s *Service) ConvertToSalesKurirSub(ctx context.Context, actor, target *auth.User) (*auth.User, error) {
	// UserPolicy::convertToSalesKurirSub runs FIRST (controller authorize): agen + same branch only.
	if !CanConvert(actor, target) {
		return nil, policyDenied()
	}
	if !target.IsRole(auth.RoleSales) {
		return nil, fail(422, "User target harus memiliki role Sales.")
	}
	// Both a missing and an invalid korsal surface as the SAME ApiException with a korsal_id field error.
	var valid bool
	if target.KorsalID != nil {
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id
			WHERE u.id=? AND u.agent_id=? AND u.deleted_at IS NULL AND r.slug='korsal')`, *target.KorsalID, *actor.AgentID).Scan(&valid); err != nil {
			return nil, err
		}
	}
	if !valid {
		return nil, &Error{Status: 422, Message: "Sales harus memiliki Korsal valid dalam network yang sama.",
			Fields: map[string][]string{"korsal_id": {"Korsal wajib valid dalam network Agen."}}}
	}
	roleID, err := s.roleID(ctx, auth.RoleSalesKurirSub)
	if err != nil {
		return nil, err
	}
	return s.convertRole(ctx, actor.ID, target, roleID, actor.AgentID)
}

func (s *Service) convertRole(ctx context.Context, actorID int64, target *auth.User, roleID int64, agentID *int64) (*auth.User, error) {
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		oldRole := target.Role
		if target.ReferralCode == nil || *target.ReferralCode == "" {
			code, err := s.referralCode(ctx, tx, "SS-")
			if err != nil {
				return err
			}
			_, err = tx.ExecContext(ctx, `UPDATE users SET role_id=?, referral_code=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?`, roleID, code, target.ID)
			if err != nil {
				return err
			}
		} else if _, err := tx.ExecContext(ctx, `UPDATE users SET role_id=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?`, roleID, target.ID); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO couriers (type,user_id,agent_id,name,is_active,created_at,updated_at)
			VALUES ('internal',?,?,?,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)`,
			target.ID, nullableInt(agentID), target.Name)
		if err != nil {
			return err
		}
		return logAudit(ctx, tx, actorID, target.ID, "user.role_converted", map[string]any{
			"previous_role": oldRole, "target_role": auth.RoleSalesKurirSub,
		})
	})
	if err != nil {
		return nil, err
	}
	return s.Find(ctx, target.ID)
}

func (s *Service) Reassign(ctx context.Context, actor, target *auth.User, korsalID, salesID *int64) (*auth.User, error) {
	if !CanUpdate(actor, target) {
		return nil, policyDenied()
	}
	if korsalID != nil {
		// ReferralReassignmentService::reassignSalesToKorsal.
		if !target.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) {
			return nil, fail(422, i18n.Translate(i18n.Default, "referral.unsupported_role"))
		}
		next, err := s.Find(ctx, *korsalID)
		if err != nil || !next.IsRole(auth.RoleKorsal) || !sameID(next.AgentID, target.AgentID) {
			return nil, fail(422, i18n.Translate(i18n.Default, "referral.invalid_target"))
		}
		return s.reassign(ctx, actor, target, next.ID, &next.ID, nil)
	}
	if salesID != nil {
		// ReferralReassignmentService::reassignKonsumenToSales.
		if !target.IsRole(auth.RoleKonsumen) {
			return nil, fail(422, i18n.Translate(i18n.Default, "referral.unsupported_role"))
		}
		next, err := s.Find(ctx, *salesID)
		if err != nil || !next.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) || !sameID(next.AgentID, target.AgentID) {
			return nil, fail(422, i18n.Translate(i18n.Default, "referral.invalid_target"))
		}
		return s.reassign(ctx, actor, target, next.ID, next.KorsalID, &next.ID)
	}
	return nil, validationFailed(map[string][]string{
		"korsal_id": fieldMsg("required_without", "korsal_id", "values", "sales"),
		"sales_id":  fieldMsg("required_without", "sales_id", "values", "korsal"),
	})
}

func (s *Service) reassign(ctx context.Context, actor, target *auth.User, parentID int64, korsalID, salesID *int64) (*auth.User, error) {
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		// Lock the target and its closure rows; the adjacency list remains authoritative.
		var locked int64
		if err := tx.QueryRowContext(ctx, `SELECT id FROM users WHERE id=? AND deleted_at IS NULL FOR UPDATE`, target.ID).Scan(&locked); err != nil {
			return err
		}
		var parentExists bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM user_closures WHERE ancestor_id=? AND descendant_id=?)`, target.ID, parentID).Scan(&parentExists); err != nil {
			return err
		}
		if parentID == target.ID || parentExists {
			return fail(422, "Perubahan referral membentuk hierarki tidak valid.")
		}
		old := map[string]any{"parent_id": target.ParentID, "korsal_id": target.KorsalID, "sales_id": target.SalesID}
		sets, args := []string{"parent_id=?", "korsal_id=?"}, []any{parentID, nullableInt(korsalID)}
		if salesID != nil {
			sets = append(sets, "sales_id=?")
			args = append(args, *salesID)
		}
		args = append(args, target.ID)
		if _, err := tx.ExecContext(ctx, `UPDATE users SET `+strings.Join(sets, ",")+`, updated_at=UTC_TIMESTAMP(6) WHERE id=?`, args...); err != nil {
			return err
		}
		if err := reattachSubtree(ctx, tx, target.ID, parentID); err != nil {
			return err
		}
		if target.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) {
			_, err := tx.ExecContext(ctx, `UPDATE users SET korsal_id=? WHERE sales_id=? AND deleted_at IS NULL`, nullableInt(korsalID), target.ID)
			if err != nil {
				return err
			}
		}
		return logAudit(ctx, tx, actor.ID, target.ID, "referral.changed", map[string]any{
			"actor_role": actor.Role, "old": old,
			"new": map[string]any{"parent_id": parentID, "korsal_id": nullableInt(korsalID), "sales_id": nullableInt(salesID)},
		})
	})
	if err != nil {
		return nil, err
	}
	return s.Find(ctx, target.ID)
}

func reattachSubtree(ctx context.Context, tx *sql.Tx, root, parent int64) error {
	rows, err := tx.QueryContext(ctx, `SELECT descendant_id, depth FROM user_closures WHERE ancestor_id=? ORDER BY depth`, root)
	if err != nil {
		return err
	}
	depth := map[int64]int{}
	for rows.Next() {
		var id int64
		var d int
		if err := rows.Scan(&id, &d); err != nil {
			rows.Close()
			return err
		}
		depth[id] = d
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	if len(depth) == 0 {
		depth[root] = 0
	}
	ids := make([]int64, 0, len(depth))
	for id := range depth {
		ids = append(ids, id)
	}
	marks := placeholders(len(ids))
	args := make([]any, 0, len(ids)+len(ids))
	for _, id := range ids {
		args = append(args, id)
	}
	for _, id := range ids {
		args = append(args, id)
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM user_closures WHERE descendant_id IN (`+marks+`) AND ancestor_id NOT IN (`+marks+`)`, args...); err != nil {
		return err
	}
	rows, err = tx.QueryContext(ctx, `SELECT ancestor_id,depth FROM user_closures WHERE descendant_id=?`, parent)
	if err != nil {
		return err
	}
	type ancestor struct {
		id    int64
		depth int
	}
	var ancestors []ancestor
	for rows.Next() {
		var a ancestor
		if err := rows.Scan(&a.id, &a.depth); err != nil {
			rows.Close()
			return err
		}
		ancestors = append(ancestors, a)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	for id, base := range depth {
		for _, a := range ancestors {
			if _, err := tx.ExecContext(ctx, `INSERT INTO user_closures (ancestor_id,descendant_id,depth) VALUES (?,?,?)
				ON DUPLICATE KEY UPDATE depth=VALUES(depth)`, a.id, id, a.depth+1+base); err != nil {
				return err
			}
		}
	}
	return nil
}

func attachClosure(ctx context.Context, tx *sql.Tx, id int64, parent *int64) error {
	if _, err := tx.ExecContext(ctx, `INSERT INTO user_closures (ancestor_id,descendant_id,depth) VALUES (?,?,0)`, id, id); err != nil {
		return err
	}
	if parent == nil {
		return nil
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO user_closures (ancestor_id,descendant_id,depth)
		SELECT ancestor_id,?,depth+1 FROM user_closures WHERE descendant_id=?`, id, *parent)
	return err
}

func (s *Service) roleID(ctx context.Context, role string) (int64, error) {
	slugs := auth.SlugsFor(role)
	marks := placeholders(len(slugs))
	args := make([]any, len(slugs))
	for i, v := range slugs {
		args[i] = v
	}
	var id sql.NullInt64
	err := s.database.QueryRowContext(ctx, `SELECT id FROM roles WHERE slug IN (`+marks+`) ORDER BY slug=? DESC LIMIT 1`, append(args, role)...).Scan(&id)
	if err != nil {
		return 0, err
	}
	if !id.Valid {
		return 0, fail(422, "Role tidak ditemukan.")
	}
	return id.Int64, nil
}

func (s *Service) referralCode(ctx context.Context, q interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}, prefix string) (string, error) {
	const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	for tries := 0; tries < 8; tries++ {
		var suffix strings.Builder
		for i := 0; i < 6; i++ {
			n, err := rand.Int(rand.Reader, big.NewInt(int64(len(chars))))
			if err != nil {
				return "", err
			}
			suffix.WriteByte(chars[n.Int64()])
		}
		candidate := prefix + suffix.String()
		var exists bool
		if err := q.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE referral_code=?)`, candidate).Scan(&exists); err != nil {
			return "", err
		}
		if !exists {
			return candidate, nil
		}
	}
	return "", fmt.Errorf("could not allocate unique referral code")
}

func uniqueConflict(ctx context.Context, tx *sql.Tx, email, phone string, except int64) (bool, error) {
	var exists bool
	err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE id<>? AND (email=? OR phone=?))`, except, email, phone).Scan(&exists)
	return exists, err
}

func uniqueFields(ctx context.Context, tx *sql.Tx, email, phone string, except int64) map[string][]string {
	fields := map[string][]string{}
	var exists bool
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE email=? AND id<>? AND deleted_at IS NULL)`, email, except).Scan(&exists); err == nil && exists {
		fields["email"] = fieldMsg("unique", "email")
	}
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE phone=? AND id<>? AND deleted_at IS NULL)`, phone, except).Scan(&exists); err == nil && exists {
		fields["phone"] = fieldMsg("unique", "phone")
	}
	return fields
}

func nullableInt(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}
func int64Ptr(v int64) *int64 { return &v }

func logAudit(ctx context.Context, tx *sql.Tx, actorID, subjectID int64, event string, properties map[string]any) error {
	encoded, err := json.Marshal(properties)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id,subject_type,subject_id,event,properties,created_at)
		VALUES (?,'App\\Models\\User',?,?,?,UTC_TIMESTAMP(6))`, actorID, subjectID, event, encoded)
	return err
}

// NormalizePhone mirrors the Laravel phone normalizer for user creation/update.
func NormalizePhone(phone string) string { return auth.NormalizePhone(phone) }

func ParseID(raw string) (int64, error) {
	id, err := strconv.ParseInt(raw, 10, 64)
	if err != nil || id < 1 {
		return 0, ErrNotFound
	}
	return id, nil
}

func NormalizeStatus(s string) string { return strings.ToLower(strings.TrimSpace(s)) }

// escapeLike escapes LIKE wildcards so a literal % or _ searches as text (UserController::index).
func escapeLike(v string) string {
	v = strings.ReplaceAll(v, `\`, `\\`)
	v = strings.ReplaceAll(v, `%`, `\%`)
	v = strings.ReplaceAll(v, `_`, `\_`)
	return v
}

// placeholders renders N question-mark placeholders for an IN (...) list.
func placeholders(n int) string {
	return strings.TrimRight(strings.Repeat("?,", n), ",")
}

// CanConvert is UserPolicy::convertToSalesKurirSub (controller authorize before the service).
func CanConvert(actor, target *auth.User) bool {
	return actor.IsRole(auth.RoleAgen) && actor.AgentID != nil && sameID(actor.AgentID, target.AgentID)
}
