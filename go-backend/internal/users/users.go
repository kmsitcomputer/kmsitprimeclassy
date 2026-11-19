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
	if !CanCreate(actor, role) {
		return nil, fail(403, "Role target tidak diizinkan.")
	}
	if err := validateCreate(input); err != nil {
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
			return &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: uniqueFields(ctx, tx, input.Email, input.Phone, 0)}
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

func validateCreate(in CreateInput) error {
	fields := map[string][]string{}
	if strings.TrimSpace(in.Name) == "" {
		fields["name"] = []string{"The name field is required."}
	}
	if len(in.Name) > 150 {
		fields["name"] = []string{"The name may not be greater than 150 characters."}
	}
	if strings.TrimSpace(in.Email) == "" {
		fields["email"] = []string{"The email field is required."}
	}
	if !auth.LooksLikeEmail(in.Email) {
		fields["email"] = []string{"The email must be a valid email address."}
	}
	if strings.TrimSpace(in.Phone) == "" {
		fields["phone"] = []string{"The phone field is required."}
	}
	if len(in.Phone) > 20 {
		fields["phone"] = []string{"The phone may not be greater than 20 characters."}
	}
	if len(in.Password) < 8 {
		fields["password"] = []string{"The password must be at least 8 characters."}
	}
	if in.Password != in.Confirmation {
		fields["password"] = []string{"The password field confirmation does not match."}
	}
	if len(fields) > 0 {
		return &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: fields}
	}
	return nil
}

func (s *Service) creationScope(ctx context.Context, actor *auth.User, role string, in CreateInput) (*int64, *int64, *int64, error) {
	if actor.IsRole(auth.RoleSuperAdmin) {
		if role != auth.RoleAgen {
			return nil, nil, nil, fail(403, "Role target tidak diizinkan.")
		}
		if in.AgentID != nil {
			return nil, nil, nil, &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"agent_id": {"Nilai Agen tidak valid."}}}
		}
		return nil, nil, nil, nil
	}
	if actor.AgentID == nil || (actor.IsRole(auth.RoleAgen) && *actor.AgentID != actor.ID) {
		return nil, nil, nil, fail(422, "Network Agen tidak valid.")
	}
	if in.AgentID != nil && *in.AgentID != *actor.AgentID {
		return nil, nil, nil, fail(422, "Tidak dapat membuat user pada network Agen lain.")
	}
	parentID, agentID := actor.ID, *actor.AgentID
	var korsalID *int64
	switch role {
	case auth.RoleKorsal, auth.RoleAdmin, auth.RoleKeuangan, auth.RoleGudang, auth.RoleKoordinatorKurir:
		if !actor.IsRole(auth.RoleAgen) && !(role == auth.RoleKurir && actor.IsRole(auth.RoleKoordinatorKurir)) {
			return nil, nil, nil, fail(403, "Role target tidak diizinkan.")
		}
	case auth.RoleSales, auth.RoleSalesKurirSub:
		if actor.IsRole(auth.RoleKorsal) {
			korsalID = int64Ptr(actor.ID)
			if in.KorsalID != nil && *in.KorsalID != actor.ID {
				return nil, nil, nil, fail(422, "Korsal hanya dapat membuat Sales di bawah dirinya.")
			}
		} else if actor.IsRole(auth.RoleAgen) {
			if in.KorsalID == nil {
				return nil, nil, nil, &Error{Status: 422, Message: "Korsal wajib dipilih.", Fields: map[string][]string{"korsal_id": {"Korsal wajib dipilih."}}}
			}
			var valid bool
			err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id
				WHERE u.id=? AND u.agent_id=? AND u.deleted_at IS NULL AND r.slug='korsal')`, *in.KorsalID, actor.ID).Scan(&valid)
			if err != nil {
				return nil, nil, nil, err
			}
			if !valid {
				return nil, nil, nil, fail(422, "Korsal wajib valid dalam network Agen.")
			}
			korsalID = int64Ptr(*in.KorsalID)
			parentID = *in.KorsalID
		} else {
			return nil, nil, nil, fail(403, "Role target tidak diizinkan.")
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
	if !CanUpdate(actor, target) {
		return nil, fail(403, "Aksi tidak diizinkan.")
	}
	if !patch.Name.Present && !patch.Phone.Present && !patch.Status.Present {
		return target, nil
	}
	if patch.Name.Present && (strings.TrimSpace(patch.Name.Value) == "" || len(patch.Name.Value) > 150) {
		return nil, &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"name": {"Nama tidak valid."}}}
	}
	if patch.Phone.Present && (patch.Phone.Value == "" || len(patch.Phone.Value) > 20) {
		return nil, &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"phone": {"Nomor telepon tidak valid."}}}
	}
	if patch.Status.Present && patch.Status.Value != "active" && patch.Status.Value != "inactive" && patch.Status.Value != "suspended" {
		return nil, &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"status": {"Status tidak valid."}}}
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
	sets = append(sets, "updated_at=UTC_TIMESTAMP(6)")
	args = append(args, target.ID)
	if err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if patch.Phone.Present {
			var exists bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE phone=? AND id<>?)`, patch.Phone.Value, target.ID).Scan(&exists); err != nil {
				return err
			}
			if exists {
				return &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"phone": {"Nomor telepon sudah digunakan."}}}
			}
		}
		if _, err := tx.ExecContext(ctx, `UPDATE users SET `+strings.Join(sets, ",")+` WHERE id=? AND deleted_at IS NULL`, args...); err != nil {
			return err
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
		return fail(403, "Aksi tidak diizinkan.")
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
	if actor == nil || !actor.IsRole(auth.RoleAgen) {
		return nil, fail(403, "Hanya Agen pemilik network yang dapat melakukan konversi.")
	}
	if !target.IsRole(auth.RoleSales) {
		return nil, fail(422, "User target harus memiliki role Sales.")
	}
	if actor.AgentID == nil || !sameID(actor.AgentID, target.AgentID) {
		return nil, fail(403, "Sales harus berada dalam network Agen yang sama.")
	}
	var valid bool
	if target.KorsalID == nil {
		return nil, fail(422, "Sales harus memiliki Korsal valid dalam network yang sama.")
	}
	if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id
		WHERE u.id=? AND u.agent_id=? AND u.deleted_at IS NULL AND r.slug='korsal')`, *target.KorsalID, *actor.AgentID).Scan(&valid); err != nil {
		return nil, err
	}
	if !valid {
		return nil, fail(422, "Sales harus memiliki Korsal valid dalam network yang sama.")
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
		return nil, fail(403, "Aksi tidak diizinkan.")
	}
	if korsalID != nil {
		if !target.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) {
			return nil, fail(422, "Role target tidak mendukung perubahan referral.")
		}
		next, err := s.Find(ctx, *korsalID)
		if err != nil || !next.IsRole(auth.RoleKorsal) || !sameID(next.AgentID, target.AgentID) {
			return nil, fail(422, "Korsal tujuan tidak valid.")
		}
		return s.reassign(ctx, actor, target, next.ID, &next.ID, nil)
	}
	if salesID != nil {
		if !target.IsRole(auth.RoleKonsumen) {
			return nil, fail(422, "Role target tidak mendukung perubahan referral.")
		}
		next, err := s.Find(ctx, *salesID)
		if err != nil || !next.IsRole(auth.RoleSales, auth.RoleSalesKurirSub) || !sameID(next.AgentID, target.AgentID) {
			return nil, fail(422, "Sales tujuan tidak valid.")
		}
		return s.reassign(ctx, actor, target, next.ID, next.KorsalID, &next.ID)
	}
	return nil, &Error{Status: 422, Message: "Data yang diberikan tidak valid.", Fields: map[string][]string{"korsal_id": {"Pilih Korsal atau Sales tujuan."}}}
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
	out := map[string][]string{}
	var exists bool
	if tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE id<>? AND email=?)`, except, email).Scan(&exists) == nil && exists {
		out["email"] = []string{"Email sudah digunakan."}
	}
	if tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM users WHERE id<>? AND phone=?)`, except, phone).Scan(&exists) == nil && exists {
		out["phone"] = []string{"Nomor telepon sudah digunakan."}
	}
	return out
}

func escapeLike(v string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(v)
}
func placeholders(n int) string {
	if n < 1 {
		return ""
	}
	return strings.TrimRight(strings.Repeat("?,", n), ",")
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
