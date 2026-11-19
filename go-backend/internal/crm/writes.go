package crm

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"
)

// Writes port CrmController's store/update/archive/storeVisit/updateVisit. Authority is CrmAccess
// (BUSINESS-RULES §27, OD-01): writes are the OWNING Sales/SKS/Korsal only, inside one Agent. The
// owner and Agent are taken from the authenticated actor — never from the request body.

var crmProspectPercents = map[int]bool{0: true, 25: true, 50: true, 75: true, 90: true, 100: true}

var crmVisitStatuses = map[string]bool{"N": true, "1": true, "2": true, "3": true, "4": true, "5": true, "6": true, "7": true}

// NormalizePhone ports App\Support\PhoneNormalizer::normalize.
func NormalizePhone(v *string) {
	if v == nil {
		return
	}
	phone := strings.TrimSpace(*v)
	compact := regexp.MustCompile(`[\s().-]+`).ReplaceAllString(phone, "")
	if regexp.MustCompile(`^\+628[0-9]+$`).MatchString(compact) {
		phone = "0" + compact[3:]
	} else if regexp.MustCompile(`^628[0-9]+$`).MatchString(compact) {
		phone = "0" + compact[2:]
	} else if regexp.MustCompile(`^08[0-9]+$`).MatchString(compact) {
		phone = compact
	}
	*v = phone
}

// Domain errors surfaced as specific API failures.
var (
	ErrPhoneDuplicate   = errors.New("crm.phone_duplicate")
	ErrCannotCreate     = errors.New("crm.cannot_create")
	ErrCannotWrite      = errors.New("crm.cannot_write")
	ErrCannotRead       = errors.New("crm.cannot_read")
	ErrVisitNotFound    = errors.New("crm.visit_not_found")
	ErrCustomerArchived = errors.New("crm.customer_archived")
)

// canCreate mirrors CrmAccess::canCreate.
func canCreate(actor Actor) bool {
	return (actor.Role == "sales" || actor.Role == "sales-kurir-sub" || actor.Role == "korsal") && actor.AgentID != nil
}

// canWrite mirrors CrmAccess::canWrite: owner in the same branch, seller/korsal roles only.
func canWrite(actor Actor, ownerUserID, agentID int64) bool {
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		return false
	}
	return ownerUserID == actor.ID && actor.AgentID != nil && *actor.AgentID == agentID
}

// loadVisible loads one customer within the actor's read scope (read scope == visible scope here);
// a foreign or missing record is ErrNotFound (route-model binding + global agent scope).
func (s *Service) loadVisible(ctx context.Context, actor Actor, id int64) (map[string]any, error) {
	row, err := s.Show(ctx, actor, id)
	if err != nil {
		return nil, err
	}
	return row, nil
}

// Create ports CrmController::store.
func (s *Service) Create(ctx context.Context, actor Actor, input CustomerInput) (map[string]any, error) {
	if !canCreate(actor) {
		return nil, ErrCannotCreate
	}
	if err := s.assertPhoneFree(ctx, actor.ID, input.Phone, nil); err != nil {
		return nil, err
	}
	res, err := s.database.ExecContext(ctx, `INSERT INTO crm_customers
		(agent_id, owner_user_id, name, phone, email, customer_type, relation, address, prospect_percent, note, created_at, updated_at)
		VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		*actor.AgentID, actor.ID, input.Name, nullArg(input.Phone), nullArg(input.Email), nullArg(input.CustomerType),
		nullArg(input.Relation), nullArg(input.Address), nullArg(input.ProspectPercent), nullArg(input.Note))
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.loadRaw(ctx, id)
}

// Update ports CrmController::update. Returns (row, oldChangedValues, err) so the handler can audit
// the dirty columns exactly like $customer->getDirty() / getOriginal().
func (s *Service) Update(ctx context.Context, actor Actor, id int64, input CustomerInput, present map[string]bool) (map[string]any, map[string]any, error) {
	ownerID, agentID, err := s.BindingCustomer(ctx, actor, id)
	if err != nil {
		return nil, nil, err
	}
	// Controller-body order: authorizeRead (403) then canWrite (403) — validation already ran in the handler.
	if !s.CanRead(ctx, actor, ownerID, agentID) {
		return nil, nil, ErrCannotRead
	}
	if !canWrite(actor, ownerID, agentID) {
		return nil, nil, ErrCannotWrite
	}
	if present["phone"] {
		if err := s.assertPhoneFree(ctx, ownerID, input.Phone, &id); err != nil {
			return nil, nil, err
		}
	}
	sets := []string{}
	args := []any{}
	oldChanged := map[string]any{}
	// current values (for dirty detection + the audit's old side)
	var curName string
	var curPhone, curEmail, curKind, curRelation, curAddress, curNote sql.NullString
	var curProspect sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT name,phone,email,customer_type,relation,address,prospect_percent,note
		FROM crm_customers WHERE id=?`, id).
		Scan(&curName, &curPhone, &curEmail, &curKind, &curRelation, &curAddress, &curProspect, &curNote); err != nil {
		return nil, nil, err
	}
	add := func(col string, v any, oldValue any) {
		oldChanged[col] = oldValue
		sets = append(sets, col+" = ?")
		args = append(args, v)
	}
	change := func(col string, newVal any, curVal any, curValid bool) {
		if !curValid && newVal == nil {
			return
		}
		if curValid && newVal != nil {
			if curStr, ok := curVal.(string); ok {
				if nv, ok2 := newVal.(string); ok2 && nv == curStr {
					return
				}
			}
			if curInt, ok := curVal.(int64); ok {
				if nv, ok2 := newVal.(int); ok2 && int64(nv) == curInt {
					return
				}
			}
		}
		var old any
		if curValid {
			old = curVal
		}
		add(col, newVal, old)
	}
	if present["name"] {
		change("name", input.Name, curName, true)
	}
	if present["phone"] {
		change("phone", nullArg(input.Phone), curPhone.String, curPhone.Valid)
	}
	if present["email"] {
		change("email", nullArg(input.Email), curEmail.String, curEmail.Valid)
	}
	if present["customer_type"] {
		change("customer_type", nullArg(input.CustomerType), curKind.String, curKind.Valid)
	}
	if present["relation"] {
		change("relation", nullArg(input.Relation), curRelation.String, curRelation.Valid)
	}
	if present["address"] {
		change("address", nullArg(input.Address), curAddress.String, curAddress.Valid)
	}
	if present["prospect_percent"] {
		change("prospect_percent", nullArg(input.ProspectPercent), curProspect.Int64, curProspect.Valid)
	}
	if present["note"] {
		change("note", nullArg(input.Note), curNote.String, curNote.Valid)
	}
	if len(sets) > 0 {
		args = append(args, id)
		if _, err := s.database.ExecContext(ctx,
			`UPDATE crm_customers SET `+strings.Join(sets, ", ")+`, updated_at = UTC_TIMESTAMP(6) WHERE id=?`, args...); err != nil {
			return nil, nil, err
		}
	}
	row, err := s.loadRaw(ctx, id)
	if err != nil {
		return nil, nil, err
	}
	if len(sets) == 0 {
		return row, nil, nil
	}
	return row, oldChanged, nil
}

// Archive ports CrmController::archive (reversible soft-archive; CRM history is never deleted).
// Returns the archived flag AFTER the update (the audit event name depends on it).
func (s *Service) Archive(ctx context.Context, actor Actor, id int64, restore bool) (map[string]any, bool, error) {
	ownerID, agentID, err := s.BindingCustomer(ctx, actor, id)
	if err != nil {
		return nil, false, err
	}
	if !s.CanRead(ctx, actor, ownerID, agentID) {
		return nil, false, ErrCannotRead
	}
	if !canWrite(actor, ownerID, agentID) {
		return nil, false, ErrCannotWrite
	}
	if restore {
		if _, err := s.database.ExecContext(ctx,
			`UPDATE crm_customers SET archived_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id=?`, id); err != nil {
			return nil, false, err
		}
	} else {
		if _, err := s.database.ExecContext(ctx,
			`UPDATE crm_customers SET archived_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id=?`, id); err != nil {
			return nil, false, err
		}
	}
	row, err := s.loadRaw(ctx, id)
	return row, row["archived_at"] != nil, err
}

// CreateVisit ports CrmController::storeVisit: the latest prospect percentage on a visit becomes the
// customer's current stage (inside one transaction).
func (s *Service) CreateVisit(ctx context.Context, actor Actor, customerID int64, input VisitInput) (map[string]any, error) {
	ownerID, agentID, err := s.BindingCustomer(ctx, actor, customerID)
	if err != nil {
		return nil, err
	}
	if !s.CanRead(ctx, actor, ownerID, agentID) {
		return nil, ErrCannotRead
	}
	if !canWrite(actor, ownerID, agentID) {
		return nil, ErrCannotWrite
	}
	var visitID int64
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		res, err := tx.ExecContext(ctx, `INSERT INTO crm_visits
			(agent_id, crm_customer_id, user_id, visit_date, status, prospect_percent, note, plan_next_date, created_at, updated_at)
			VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			agentID, customerID, actor.ID, input.VisitDate, input.Status, nullArg(input.ProspectPercent),
			nullArg(input.Note), nullArg(input.PlanNextDate))
		if err != nil {
			return err
		}
		visitID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		if input.ProspectPercent != nil {
			if _, err := tx.ExecContext(ctx,
				`UPDATE crm_customers SET prospect_percent = ?, updated_at = UTC_TIMESTAMP(6) WHERE id=?`, *input.ProspectPercent, customerID); err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return s.loadVisit(ctx, visitID)
}

// UpdateVisit ports CrmController::updateVisit. Returns (visit, changed, err).
func (s *Service) UpdateVisit(ctx context.Context, actor Actor, visitID int64, input VisitInput, present map[string]bool) (map[string]any, bool, error) {
	customerID, _, err := s.BindingVisit(ctx, actor, visitID)
	if err != nil {
		return nil, false, err
	}
	var ownerID, agentID int64
	if err := s.database.QueryRowContext(ctx, `SELECT owner_user_id, agent_id FROM crm_customers WHERE id=?`, customerID).
		Scan(&ownerID, &agentID); err != nil {
		return nil, false, err
	}
	if !s.CanRead(ctx, actor, ownerID, agentID) {
		return nil, false, ErrCannotRead
	}
	if !canWrite(actor, ownerID, agentID) {
		return nil, false, ErrCannotWrite
	}
	// Dirty tracking mirrors $visit->getDirty(): only actually-changed values are persisted/audited.
	old := map[string]any{}
	sets := []string{}
	args := []any{}
	scanCur := func(col string, dst any) {}
	_ = scanCur
	var curStatus string
	var curNote, curPlan sql.NullString
	var curProspect sql.NullInt64
	if err := s.database.QueryRowContext(ctx,
		`SELECT status, prospect_percent, note, plan_next_date FROM crm_visits WHERE id=?`, visitID).
		Scan(&curStatus, &curProspect, &curNote, &curPlan); err != nil {
		return nil, false, err
	}
	if present["status"] && input.Status != curStatus {
		old["status"] = curStatus
		sets = append(sets, "status = ?")
		args = append(args, input.Status)
	}
	if present["prospect_percent"] {
		if input.ProspectPercent == nil && curProspect.Valid {
			old["prospect_percent"] = curProspect.Int64
			sets = append(sets, "prospect_percent = NULL")
		} else if input.ProspectPercent != nil && (!curProspect.Valid || curProspect.Int64 != int64(*input.ProspectPercent)) {
			old["prospect_percent"] = nullableInt(curProspect)
			sets = append(sets, "prospect_percent = ?")
			args = append(args, *input.ProspectPercent)
		}
	}
	if present["note"] {
		if input.Note == nil && curNote.Valid {
			old["note"] = curNote.String
			sets = append(sets, "note = NULL")
		} else if input.Note != nil && (!curNote.Valid || curNote.String != *input.Note) {
			old["note"] = nullableString(curNote)
			sets = append(sets, "note = ?")
			args = append(args, *input.Note)
		}
	}
	if present["visit_date"] && input.VisitDate != "" {
		var curDate string
		if err := s.database.QueryRowContext(ctx, `SELECT visit_date FROM crm_visits WHERE id=?`, visitID).Scan(&curDate); err != nil {
			return nil, false, err
		}
		if curDate != input.VisitDate {
			old["visit_date"] = curDate
			sets = append(sets, "visit_date = ?")
			args = append(args, input.VisitDate)
		}
	}
	if present["plan_next_date"] {
		if input.PlanNextDate == nil && curPlan.Valid {
			old["plan_next_date"] = curPlan.String
			sets = append(sets, "plan_next_date = NULL")
		} else if input.PlanNextDate != nil && (!curPlan.Valid || curPlan.String != *input.PlanNextDate) {
			old["plan_next_date"] = nullableString(curPlan)
			sets = append(sets, "plan_next_date = ?")
			args = append(args, *input.PlanNextDate)
		}
	}
	changed := len(sets) > 0
	if changed {
		args = append(args, visitID)
		if _, err := s.database.ExecContext(ctx,
			`UPDATE crm_visits SET `+strings.Join(sets, ", ")+`, updated_at = UTC_TIMESTAMP(6) WHERE id=?`, args...); err != nil {
			return nil, false, err
		}
	}
	row, err := s.loadVisit(ctx, visitID)
	if err != nil {
		return nil, changed, err
	}
	// The controller touched $visit->customer, lazy-loading the relation — Laravel serializes it.
	customer, err := s.loadRaw(ctx, customerID)
	if err != nil {
		return nil, changed, err
	}
	row["customer"] = customer
	if changed {
		// Laravel audits the ORIGINAL values of the changed columns.
		row["_old"] = old
	}
	return row, changed, nil
}

// assertPhoneFree ports CrmController::assertPhoneFree (per-owner uniqueness, ignores archived state).
func (s *Service) assertPhoneFree(ctx context.Context, ownerID int64, phone *string, ignoreID *int64) error {
	if phone == nil {
		return nil
	}
	NormalizePhone(phone)
	if *phone == "" {
		return nil
	}
	query := `SELECT COUNT(*) FROM crm_customers WHERE owner_user_id=? AND phone=?`
	args := []any{ownerID, *phone}
	if ignoreID != nil {
		query += ` AND id != ?`
		args = append(args, *ignoreID)
	}
	var n int
	if err := s.database.QueryRowContext(ctx, query, args...).Scan(&n); err != nil {
		return err
	}
	if n > 0 {
		return ErrPhoneDuplicate
	}
	return nil
}

// loadRaw loads a customer WITHOUT the owner relation (store/update/archive return bare models).
func (s *Service) loadRaw(ctx context.Context, id int64) (map[string]any, error) {
	var idOut, agentID, ownerID int64
	var name string
	var phone, email, kind, relation, address, note sql.NullString
	var archived sql.NullTime
	var prospect, linked sql.NullInt64
	var created, updated time.Time
	err := s.database.QueryRowContext(ctx, `SELECT id,agent_id,owner_user_id,name,phone,email,customer_type,relation,
		address,prospect_percent,note,archived_at,created_at,updated_at,linked_konsumen_id
		FROM crm_customers WHERE id=?`, id).
		Scan(&idOut, &agentID, &ownerID, &name, &phone, &email, &kind, &relation, &address, &prospect, &note,
			&archived, &created, &updated, &linked)
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": idOut, "agent_id": agentID, "owner_user_id": ownerID, "name": name,
		"phone": nullableString(phone), "email": nullableString(email), "customer_type": nullableString(kind),
		"relation": nullableString(relation), "address": nullableString(address),
		"prospect_percent": nullableInt(prospect), "note": nullableString(note),
		"archived_at": nullTimeMicro(archived), "created_at": isoMicro(created), "updated_at": isoMicro(updated),
		"linked_konsumen_id": nullableInt(linked),
	}, nil
}

// nullTimeMicro formats a nullable TIMESTAMP the way Laravel's datetime cast does. Scanning a
// time.Time into sql.NullString silently yields RFC3339Nano — the wrong shape.
func nullTimeMicro(v sql.NullTime) any {
	if !v.Valid {
		return nil
	}
	return isoMicro(v.Time)
}

// loadVisit loads a visit with Laravel model-cast serialization (date casts → ISO micro midnight).
func (s *Service) loadVisit(ctx context.Context, id int64) (map[string]any, error) {
	var idOut, agentID, customerID, userID int64
	var visitDate time.Time
	var status string
	var prospect sql.NullInt64
	var note sql.NullString
	var plan sql.NullTime
	var created, updated time.Time
	err := s.database.QueryRowContext(ctx, `SELECT id,agent_id,crm_customer_id,user_id,visit_date,status,
		prospect_percent,note,plan_next_date,created_at,updated_at FROM crm_visits WHERE id=?`, id).
		Scan(&idOut, &agentID, &customerID, &userID, &visitDate, &status, &prospect, &note, &plan, &created, &updated)
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": idOut, "agent_id": agentID, "crm_customer_id": customerID, "user_id": userID,
		"visit_date": isoMicro(visitDate), "status": status, "prospect_percent": nullableInt(prospect),
		"note": nullableString(note), "plan_next_date": nullTimeMicro(plan),
		"created_at": isoMicro(created), "updated_at": isoMicro(updated),
	}, nil
}

// Input structs carry PRE-normalized values (empty string → nil, phone normalized upstream).
type CustomerInput struct {
	Name            string
	Phone           *string
	Email           *string
	CustomerType    *string
	Relation        *string
	Address         *string
	ProspectPercent *int
	Note            *string
}

type VisitInput struct {
	VisitDate       string
	Status          string
	ProspectPercent *int
	Note            *string
	PlanNextDate    *string
}

func nullArg(v any) any {
	switch t := v.(type) {
	case nil:
		return nil
	case *string:
		if t == nil || *t == "" {
			return nil
		}
		return *t
	case *int:
		if t == nil {
			return nil
		}
		return *t
	case string:
		if t == "" {
			return nil
		}
		return t
	default:
		return fmt.Sprintf("%v", t)
	}
}

func isoMicro(t time.Time) string { return t.UTC().Format("2006-01-02T15:04:05.000000Z") }

func isoMicroNullable(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	t, err := time.Parse("2006-01-02 15:04:05", v.String)
	if err != nil {
		t2, err2 := time.Parse("2006-01-02T15:04:05", v.String)
		if err2 != nil {
			return v.String
		}
		return isoMicro(t2)
	}
	return isoMicro(t)
}

// isoMicroDate renders a DATE column the way Laravel's date cast does (ISO micro at midnight).
func isoMicroDate(v string) string {
	t, err := time.Parse("2006-01-02", v)
	if err != nil {
		return v
	}
	return t.UTC().Format("2006-01-02T15:04:05.000000Z")
}

func isoMicroDateNullable(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	return isoMicroDate(v.String)
}

// ProspectPercentAllowed reports membership of CrmCustomer::PROSPECT_PERCENTS.
func ProspectPercentAllowed(v int) bool { return crmProspectPercents[v] }

// VisitStatusAllowed reports membership of CrmVisit::STATUSES.
func VisitStatusAllowed(v string) bool { return crmVisitStatuses[v] }

// BindingCustomer mirrors route-model binding + BelongsToAgentScope: a missing or foreign-agent
// customer is a binding miss (404 endpoint_not_found), NOT a 403. Note the global scope is
// agent-only — an other-owner same-agent customer BINDS fine (the 403 comes from canRead later).
func (s *Service) BindingCustomer(ctx context.Context, actor Actor, id int64) (ownerUserID, agentID int64, err error) {
	q := `SELECT owner_user_id, agent_id FROM crm_customers WHERE id=?`
	args := []any{id}
	if actor.Role != "super_admin" {
		if actor.AgentID == nil {
			return 0, 0, ErrNotFound
		}
		q += ` AND agent_id=?`
		args = append(args, *actor.AgentID)
	}
	if err := s.database.QueryRowContext(ctx, q, args...).Scan(&ownerUserID, &agentID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return 0, 0, ErrNotFound
		}
		return 0, 0, err
	}
	return ownerUserID, agentID, nil
}

// BindingVisit mirrors binding on CrmVisit (global agent scope).
func (s *Service) BindingVisit(ctx context.Context, actor Actor, visitID int64) (customerID, agentID int64, err error) {
	q := `SELECT crm_customer_id, agent_id FROM crm_visits WHERE id=?`
	args := []any{visitID}
	if actor.Role != "super_admin" {
		if actor.AgentID == nil {
			return 0, 0, ErrNotFound
		}
		q += ` AND agent_id=?`
		args = append(args, *actor.AgentID)
	}
	if err := s.database.QueryRowContext(ctx, q, args...).Scan(&customerID, &agentID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return 0, 0, ErrNotFound
		}
		return 0, 0, err
	}
	return customerID, agentID, nil
}

// CanRead mirrors CrmAccess::canRead for a loaded customer row.
func (s *Service) CanRead(ctx context.Context, actor Actor, ownerUserID, agentID int64) bool {
	if actor.Role == "super_admin" {
		return true
	}
	if actor.AgentID == nil || *actor.AgentID != agentID {
		return false
	}
	if actor.Role == "agen" || actor.Role == "admin" {
		return true
	}
	if actor.Role == "sales" || actor.Role == "sales-kurir-sub" {
		return ownerUserID == actor.ID
	}
	if actor.Role == "korsal" {
		// owner is the actor, or the owner is the actor's downline (owner.korsal_id = actor)
		return ownerUserID == actor.ID
	}
	return false
}

// CanReadKorsalDownline reports whether the owner is the korsal actor's downline (owner.korsal_id).
func (s *Service) CanReadKorsalDownline(ctx context.Context, actor Actor, ownerUserID int64) bool {
	var n int
	if err := s.database.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM users WHERE id=? AND korsal_id=? AND deleted_at IS NULL`, ownerUserID, actor.ID).Scan(&n); err != nil {
		return false
	}
	return n > 0
}

func sqlNullString(v string) sql.NullString { return sql.NullString{String: v, Valid: v != ""} }
