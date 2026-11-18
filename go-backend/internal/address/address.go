// Package address is the konsumen address book.
//
// Scope: every query is restricted to the AUTHENTICATED user's own rows. There is no agent scope here because
// an address belongs to a person, not a branch — the reference filters on user_id directly.
package address

import (
	"context"
	"database/sql"
	"errors"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

// ErrNotFound is a missing (or foreign — the two are indistinguishable on purpose) address.
var ErrNotFound = errors.New("not found")

// DomainError carries an HTTP status, the reference's exact message and optional field errors.
type DomainError struct {
	Status  int
	Message string
	Fields  map[string]string
}

func (e *DomainError) Error() string { return e.Message }

// Input is the validated create/update payload.
type Input struct {
	Label         *string
	RecipientName *string
	Phone         *string
	AddressLine   *string
	VillageID     string
	Latitude      *float64
	Longitude     *float64
	IsDefault     bool
}

// villageRef is the resolved administrative chain for a village id.
type villageRef struct {
	Name       string
	DistrictID string
	RegencyID  string
	ProvinceID string
}

// resolveVillage loads a village plus its parent chain (one query with joins).
func resolveVillage(ctx context.Context, q interface {
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
}, villageID string) (*villageRef, error) {
	var v villageRef
	err := q.QueryRowContext(ctx, `SELECT v.name, v.district_id, d.regency_id, r.province_id
		FROM villages v
		JOIN districts d ON d.id = v.district_id
		JOIN regencies r ON r.id = d.regency_id
		WHERE v.id = ?`, villageID).Scan(&v.Name, &v.DistrictID, &v.RegencyID, &v.ProvinceID)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, &DomainError{Status: 422, Message: "validation", Fields: map[string]string{"village_id": "exists"}}
	}
	if err != nil {
		return nil, err
	}
	return &v, nil
}

// List returns the caller's addresses, newest first, with the village chain resolved.
func List(ctx context.Context, d *db.DB, userID int64) ([]map[string]any, error) {
	rows, err := d.QueryContext(ctx, addressColumns+` WHERE a.user_id = ? ORDER BY a.created_at DESC, a.id DESC`, userID)
	if err != nil {
		if db.IsMissingSchema(err) {
			return []map[string]any{}, nil
		}
		return nil, err
	}
	defer rows.Close()
	return scanAddresses(rows)
}

const addressColumns = `SELECT a.id, a.label, a.recipient_name, a.phone, a.address_line,
	a.latitude, a.longitude, a.is_default,
	v.id, v.name, d.name, r.name, p.name
	FROM konsumen_addresses a
	LEFT JOIN villages v ON v.id = a.village_id
	LEFT JOIN districts d ON d.id = v.district_id
	LEFT JOIN regencies r ON r.id = d.regency_id
	LEFT JOIN provinces p ON p.id = r.province_id`

func scanAddresses(rows *sql.Rows) ([]map[string]any, error) {
	out := []map[string]any{}
	for rows.Next() {
		var (
			id                      int64
			label, recipient, phone sql.NullString
			line                    sql.NullString
			lat, lng                sql.NullString
			isDefault               int
			villageID               sql.NullString
			village, district       sql.NullString
			regency, province       sql.NullString
		)
		if err := rows.Scan(&id, &label, &recipient, &phone, &line, &lat, &lng, &isDefault,
			&villageID, &village, &district, &regency, &province); err != nil {
			return nil, err
		}
		var villageObj any
		if villageID.Valid {
			// The resource nests name + parents and never exposes the ids.
			villageObj = map[string]any{
				"id":       villageID.String,
				"name":     nullStr(village),
				"district": nullStr(district),
				"regency":  nullStr(regency),
				"province": nullStr(province),
			}
		}
		out = append(out, map[string]any{
			"id":             id,
			"label":          nullStr(label),
			"recipient_name": nullStr(recipient),
			"phone":          nullStr(phone),
			"address_line":   nullStr(line),
			"village":        villageObj,
			"latitude":       nullStr(lat),
			"longitude":      nullStr(lng),
			"is_default":     isDefault == 1,
		})
	}
	return out, rows.Err()
}

// Create adds an address. When is_default is set, every other address of the caller stops being the default
// (inside the same transaction, exactly like the reference).
func Create(ctx context.Context, d *db.DB, userID int64, in Input) (map[string]any, error) {
	var newID int64
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		v, err := resolveVillage(ctx, tx, in.VillageID)
		if err != nil {
			return err
		}
		if in.IsDefault {
			if _, err := tx.ExecContext(ctx, `UPDATE konsumen_addresses SET is_default = 0, updated_at = UTC_TIMESTAMP()
				WHERE user_id = ?`, userID); err != nil {
				return err
			}
		}
		label := "Rumah"
		if in.Label != nil && *in.Label != "" {
			label = *in.Label
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO konsumen_addresses
			(user_id, label, recipient_name, phone, address_line, province_id, regency_id, district_id,
			 village_id, latitude, longitude, is_default, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())`,
			userID, label, strVal(in.RecipientName), strVal(in.Phone), strVal(in.AddressLine),
			v.ProvinceID, v.RegencyID, v.DistrictID, in.VillageID, floatVal(in.Latitude), floatVal(in.Longitude),
			in.IsDefault)
		if err != nil {
			return err
		}
		newID, _ = res.LastInsertId()
		return nil
	})
	if err != nil {
		return nil, err
	}
	return byID(ctx, d, userID, newID)
}

// Update replaces an address (the request is the full payload, as in the reference).
func Update(ctx context.Context, d *db.DB, userID, addressID int64, in Input) (map[string]any, error) {
	err := d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := assertOwned(ctx, tx, userID, addressID); err != nil {
			return err
		}
		v, err := resolveVillage(ctx, tx, in.VillageID)
		if err != nil {
			return err
		}
		if in.IsDefault {
			if _, err := tx.ExecContext(ctx, `UPDATE konsumen_addresses SET is_default = 0, updated_at = UTC_TIMESTAMP()
				WHERE user_id = ?`, userID); err != nil {
				return err
			}
		}
		_, err = tx.ExecContext(ctx, `UPDATE konsumen_addresses SET
				label = COALESCE(?, label), recipient_name = ?, phone = ?, address_line = ?,
				province_id = ?, regency_id = ?, district_id = ?, village_id = ?,
				latitude = ?, longitude = ?, is_default = ?, updated_at = UTC_TIMESTAMP()
			WHERE id = ? AND user_id = ?`,
			nullableStrPtr(in.Label), strVal(in.RecipientName), strVal(in.Phone), strVal(in.AddressLine),
			v.ProvinceID, v.RegencyID, v.DistrictID, in.VillageID, floatVal(in.Latitude), floatVal(in.Longitude),
			in.IsDefault, addressID, userID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return byID(ctx, d, userID, addressID)
}

// Delete removes an address owned by the caller.
func Delete(ctx context.Context, d *db.DB, userID, addressID int64) error {
	res, err := d.ExecContext(ctx, `DELETE FROM konsumen_addresses WHERE id = ? AND user_id = ?`, addressID, userID)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

func byID(ctx context.Context, d *db.DB, userID, addressID int64) (map[string]any, error) {
	rows, err := d.QueryContext(ctx, addressColumns+` WHERE a.user_id = ? AND a.id = ?`, userID, addressID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	list, err := scanAddresses(rows)
	if err != nil {
		return nil, err
	}
	if len(list) == 0 {
		return nil, ErrNotFound
	}
	return list[0], nil
}

// assertOwned proves the address belongs to the user (a foreign id is a 404, never a 403).
func assertOwned(ctx context.Context, tx *sql.Tx, userID, addressID int64) error {
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM konsumen_addresses WHERE id = ? AND user_id = ? FOR UPDATE`,
		addressID, userID).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrNotFound
	}
	return err
}

func nullStr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func strVal(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullableStrPtr(v *string) any {
	if v == nil || *v == "" {
		return nil
	}
	return *v
}

// floatVal renders a coordinate; it is stored as DECIMAL(10,7) and passed as text so no float rounding is
// introduced by the driver.
func floatVal(v *float64) any {
	if v == nil {
		return nil
	}
	return formatDecimal(*v)
}
