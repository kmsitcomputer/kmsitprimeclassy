// Package inventory — Sub Location ownership (create, assign owner, deactivate).
//
// LOCK ORDER (SubLocationOwnershipService, preserved):
//
//	create      : owner user row FOR UPDATE  → insert the location
//	assignOwner : location row FOR UPDATE    → owner user row FOR UPDATE
//	deactivate  : location row FOR UPDATE    → (stock existence check) → update
//
// The owner row is locked FIRST on create so two concurrent creations cannot both pass "does this
// Sales-Kurir-Sub already own a location?"; the UNIQUE index `warehouse_sub_locations_owner_unique` is the
// final backstop and its duplicate-key error is mapped to the same 422 the reference returns.
package inventory

import (
	"context"
	"database/sql"
	"errors"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

const errOwnerTaken = "Sales-Kurir-Sub ini sudah memiliki Sub Location."

// SubLocationInput is the validated create/update payload.
type SubLocationInput struct {
	Code          *string
	Name          *string
	Address       *string
	ContactNumber *string
	Description   *string
}

// CreateSubLocation is SubLocationOwnershipService::create.
func CreateSubLocation(ctx context.Context, d *db.DB, actor Actor, in SubLocationInput, ownerUserID int64) (map[string]any, error) {
	if !actor.IsRole("agen", "admin") || actor.AgentID == 0 {
		return nil, &DomainError{Status: 403, Message: "Hanya Agen/Admin yang dapat mengelola kepemilikan Sub Location."}
	}
	// Length/required checks live in the HANDLER (which knows the field's limits and can render the exact
	// `required`/`max` sentences). Here only the payload is trusted to be pre-validated.
	if in.Code == nil || in.Name == nil {
		return nil, &DomainError{Status: 422, Message: "validation"}
	}

	var newID int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var dup bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_sub_locations
			WHERE agent_id = ? AND code = ?)`, actor.AgentID, *in.Code).Scan(&dup); err != nil {
			return err
		}
		if dup {
			return &DomainError{Status: 422, Message: "validation", Fields: map[string]string{"code": "unique"}}
		}
		if err := lockEligibleOwner(ctx, tx, actor.AgentID, ownerUserID); err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO warehouse_sub_locations
			(agent_id, owner_user_id, previous_owner_user_id, code, name, address, contact_number, description,
			 is_active, created_by, created_at, updated_at)
			VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			actor.AgentID, ownerUserID, *in.Code, *in.Name, nullableStrPtr(in.Address),
			nullableStrPtr(in.ContactNumber), nullableStrPtr(in.Description), actor.ID)
		if err != nil {
			if db.IsDuplicateKey(err) {
				return &DomainError{Status: 422, Message: errOwnerTaken, Fields: map[string]string{"owner_user_id": errOwnerTaken}}
			}
			return err
		}
		newID, _ = res.LastInsertId()
		return activityLog(ctx, tx, actor.ID, "App\\Models\\WarehouseSubLocation", newID,
			"sub_location.created", map[string]any{"agent_id": actor.AgentID, "owner_user_id": ownerUserID})
	})
	if err != nil {
		return nil, err
	}
	return SubLocationByID(ctx, d, actor.AgentID, newID)
}

// lockEligibleOwner is SubLocationOwnershipService::lockEligibleOwner: the owner must be an ACTIVE
// Sales-Kurir-Sub of the same network that does not already own a location.
func lockEligibleOwner(ctx context.Context, tx *sql.Tx, agentID, ownerUserID int64) error {
	var (
		status   string
		agent    sql.NullInt64
		roleSlug string
		deleted  sql.NullString
		owns     bool
	)
	err := tx.QueryRowContext(ctx, `SELECT u.status, u.agent_id, COALESCE(r.slug,''), CAST(u.deleted_at AS CHAR)
		FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE`, ownerUserID).
		Scan(&status, &agent, &roleSlug, &deleted)
	if errors.Is(err, sql.ErrNoRows) {
		return &DomainError{Status: 422, Message: "Pemilik harus Sales-Kurir-Sub aktif pada network Agen yang sama.",
			Fields: map[string]string{"owner_user_id": "Pemilik tidak valid."}}
	}
	if err != nil {
		return err
	}
	eligible := status == "active" && agent.Valid && agent.Int64 == agentID &&
		(roleSlug == "sales-kurir-sub" || roleSlug == "sales-kurir") && !deleted.Valid
	if !eligible {
		return &DomainError{Status: 422, Message: "Pemilik harus Sales-Kurir-Sub aktif pada network Agen yang sama.",
			Fields: map[string]string{"owner_user_id": "Pemilik tidak valid."}}
	}
	if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_sub_locations WHERE owner_user_id = ?)`,
		ownerUserID).Scan(&owns); err != nil {
		return err
	}
	if owns {
		return &DomainError{Status: 422, Message: errOwnerTaken,
			Fields: map[string]string{"owner_user_id": "Satu Sales-Kurir-Sub hanya boleh memiliki satu Sub Location."}}
	}
	return nil
}

// AssignSubLocationOwner is SubLocationOwnershipService::assignOwner — an UNOWNED, active location only.
func AssignSubLocationOwner(ctx context.Context, d *db.DB, actor Actor, locationID, ownerUserID int64) (map[string]any, error) {
	if !actor.IsRole("agen", "admin") || actor.AgentID == 0 {
		return nil, &DomainError{Status: 403, Message: "Hanya Agen/Admin yang dapat mengelola kepemilikan Sub Location."}
	}
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var (
			active bool
			owner  sql.NullInt64
		)
		if err := tx.QueryRowContext(ctx, `SELECT is_active, owner_user_id FROM warehouse_sub_locations
			WHERE id = ? AND agent_id = ? FOR UPDATE`, locationID, actor.AgentID).Scan(&active, &owner); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrNotFound
			}
			return err
		}
		if !active {
			return transferInvalid("Sub location tidak aktif.")
		}
		if owner.Valid {
			return transferInvalid("Sub location sudah memiliki pemilik dan tidak dapat dipindahkan.")
		}
		if err := lockEligibleOwner(ctx, tx, actor.AgentID, ownerUserID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE warehouse_sub_locations SET owner_user_id = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, ownerUserID, locationID); err != nil {
			if db.IsDuplicateKey(err) {
				return &DomainError{Status: 422, Message: errOwnerTaken, Fields: map[string]string{"owner_user_id": errOwnerTaken}}
			}
			return err
		}
		return activityLog(ctx, tx, actor.ID, "App\\Models\\WarehouseSubLocation", locationID,
			"sub_location.owner_assigned", map[string]any{"agent_id": actor.AgentID, "owner_user_id": ownerUserID})
	})
	if err != nil {
		return nil, err
	}
	return SubLocationByID(ctx, d, actor.AgentID, locationID)
}

// DeactivateSubLocation is SubLocationOwnershipService::deactivate — refused while physical stock remains.
func DeactivateSubLocation(ctx context.Context, d *db.DB, actor Actor, locationID int64) (map[string]any, error) {
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var (
			prevOwner, owner sql.NullInt64
		)
		if err := tx.QueryRowContext(ctx, `SELECT owner_user_id, previous_owner_user_id FROM warehouse_sub_locations
			WHERE id = ? AND agent_id = ? FOR UPDATE`, locationID, actor.AgentID).Scan(&owner, &prevOwner); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrNotFound
			}
			return err
		}
		var hasStock bool
		if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_stocks
			WHERE sub_location_id = ? AND quantity > 0)`, locationID).Scan(&hasStock); err != nil {
			return err
		}
		if hasStock {
			return transferInvalid("Sub location masih memiliki stok fisik.")
		}
		// The released owner is remembered so the history is not lost.
		newPrevious := prevOwner
		if owner.Valid {
			newPrevious = owner
		}
		if _, err := tx.ExecContext(ctx, `UPDATE warehouse_sub_locations
			SET is_active = 0, owner_user_id = NULL, previous_owner_user_id = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ?`, nullableInt(nullInt64Ptr(newPrevious)), locationID); err != nil {
			return err
		}
		props := map[string]any{"agent_id": actor.AgentID}
		if owner.Valid {
			props["released_owner_user_id"] = owner.Int64
		} else {
			props["released_owner_user_id"] = nil
		}
		return activityLog(ctx, tx, actor.ID, "App\\Models\\WarehouseSubLocation", locationID,
			"sub_location.deactivated", props)
	})
	if err != nil {
		return nil, err
	}
	return SubLocationByID(ctx, d, actor.AgentID, locationID)
}

// UpdateSubLocation is WarehouseSubLocationController::update (partial; code unique per agent).
func UpdateSubLocation(ctx context.Context, d *db.DB, actor Actor, locationID int64, in SubLocationInput) (map[string]any, error) {
	if !actor.IsRole("agen", "admin", "gudang") {
		return nil, forbiddenDomain("Anda tidak memiliki akses untuk melakukan aksi ini.")
	}
	sets := []string{}
	args := []any{}
	if in.Code != nil {
		if len(*in.Code) == 0 || len(*in.Code) > 40 {
			return nil, &DomainError{Status: 422, Message: "validation", Fields: map[string]string{"code": "invalid length"}}
		}
		var dup bool
		if err := d.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM warehouse_sub_locations
			WHERE agent_id = ? AND code = ? AND id <> ?)`, actor.AgentID, *in.Code, locationID).Scan(&dup); err != nil {
			return nil, err
		}
		if dup {
			return nil, &DomainError{Status: 422, Message: "validation", Fields: map[string]string{"code": "unique"}}
		}
		sets = append(sets, "code = ?")
		args = append(args, *in.Code)
	}
	if in.Name != nil {
		if len(*in.Name) == 0 || len(*in.Name) > 150 {
			return nil, &DomainError{Status: 422, Message: "validation", Fields: map[string]string{"name": "invalid length"}}
		}
		sets = append(sets, "name = ?")
		args = append(args, *in.Name)
	}
	if in.Address != nil {
		sets = append(sets, "address = ?")
		args = append(args, *in.Address)
	}
	if in.ContactNumber != nil {
		sets = append(sets, "contact_number = ?")
		args = append(args, *in.ContactNumber)
	}
	if in.Description != nil {
		sets = append(sets, "description = ?")
		args = append(args, *in.Description)
	}
	if len(sets) > 0 {
		args = append(args, locationID, actor.AgentID)
		res, err := d.ExecContext(context.Background(), `UPDATE warehouse_sub_locations SET `+
			joinStrings(sets, ", ")+`, updated_at = UTC_TIMESTAMP() WHERE id = ? AND agent_id = ?`, args...)
		if err != nil {
			return nil, err
		}
		if n, _ := res.RowsAffected(); n == 0 {
			// Either not found or nothing changed; a scoped read decides which.
			if _, err := SubLocationByID(ctx, d, actor.AgentID, locationID); err != nil {
				return nil, err
			}
		}
	}
	return SubLocationByID(ctx, d, actor.AgentID, locationID)
}

// ---- helpers ---------------------------------------------------------------------------------------

func forbiddenDomain(message string) error { return &DomainError{Status: 403, Message: message} }

// activityLog writes an activity_logs row with a valid JSON properties payload.
func activityLog(ctx context.Context, tx *sql.Tx, causerID int64, subjectType string, subjectID int64, event string, props map[string]any) error {
	payload, err := jsonMarshal(props)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs
		(causer_id, subject_type, subject_id, event, description, properties, created_at)
		VALUES (?, ?, ?, ?, NULL, ?, UTC_TIMESTAMP())`, causerID, subjectType, subjectID, event, payload)
	return err
}

func jsonMarshal(v any) (string, error) {
	buf, err := jsonMarshalBytes(v)
	if err != nil {
		return "", err
	}
	return string(buf), nil
}

func joinStrings(v []string, sep string) string {
	out := ""
	for i, s := range v {
		if i > 0 {
			out += sep
		}
		out += s
	}
	return out
}

var _ = strconv.Itoa
