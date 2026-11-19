// Package crm implements the read-only CRM surfaces. Read authority mirrors
// CrmAccess::scopeVisible; record lookups use that same scope so foreign IDs
// are indistinguishable from missing records.
package crm

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

var ErrNotFound = errors.New("CRM record not found")
var ErrForbidden = errors.New("role has no CRM read scope")

type Actor struct {
	ID      int64
	Role    string
	AgentID *int64
}

type Page struct {
	Rows        []map[string]any
	CurrentPage int
	LastPage    int
	Total       int
}

type Service struct{ database *db.DB }

func New(database *db.DB) *Service { return &Service{database: database} }

// visibleSQL returns a predicate, never interpolating actor-controlled values.
func visibleSQL(actor Actor, alias string) (string, []any, error) {
	switch actor.Role {
	case "super_admin":
		return "1=1", nil, nil
	case "agen", "admin":
		if actor.AgentID == nil {
			return "1=0", nil, nil
		}
		return alias + ".agent_id = ?", []any{*actor.AgentID}, nil
	case "sales", "sales-kurir-sub":
		return alias + ".owner_user_id = ?", []any{actor.ID}, nil
	case "korsal":
		if actor.AgentID == nil {
			return "1=0", nil, nil
		}
		return alias + ".agent_id = ? AND (" + alias + ".owner_user_id = ? OR " + alias +
				".owner_user_id IN (SELECT id FROM users WHERE korsal_id = ? AND deleted_at IS NULL))",
			[]any{*actor.AgentID, actor.ID, actor.ID}, nil
	default:
		return "", nil, ErrForbidden
	}
}

func (s *Service) List(ctx context.Context, actor Actor, archived bool, stage, search string, page, perPage int) (Page, error) {
	predicate, args, err := visibleSQL(actor, "c")
	if err != nil {
		return Page{}, err
	}
	if archived {
		predicate += " AND c.archived_at IS NOT NULL"
	} else {
		predicate += " AND c.archived_at IS NULL"
	}
	switch strings.ToUpper(stage) {
	case "FU":
		predicate += " AND c.id IN (SELECT crm_customer_id FROM crm_visits v WHERE v.id = (SELECT MAX(v2.id) FROM crm_visits v2 WHERE v2.crm_customer_id = c.id) AND v.plan_next_date IS NOT NULL)"
	case "D":
		predicate += " AND c.id IN (SELECT crm_customer_id FROM crm_visits v WHERE v.id = (SELECT MAX(v2.id) FROM crm_visits v2 WHERE v2.crm_customer_id = c.id) AND v.status = '7')"
	}
	if search != "" {
		predicate += " AND (c.name LIKE ? OR c.phone LIKE ?)"
		args = append(args, "%"+search+"%", "%"+search+"%")
	}
	var total int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM crm_customers c WHERE `+predicate, args...).Scan(&total); err != nil {
		return Page{}, err
	}
	page, perPage = pagination(page, perPage, 25, 100)
	rows, err := s.database.QueryContext(ctx, `SELECT c.id,c.agent_id,c.owner_user_id,c.name,c.phone,c.email,
		c.customer_type,c.relation,c.address,c.prospect_percent,c.note,c.archived_at,c.created_at,c.updated_at,
		c.linked_konsumen_id,u.name
		FROM crm_customers c JOIN users u ON u.id=c.owner_user_id
		WHERE `+predicate+` ORDER BY c.id DESC LIMIT ? OFFSET ?`, append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		return Page{}, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		row, err := scanCustomer(rows.Scan)
		if err != nil {
			return Page{}, err
		}
		data = append(data, row)
	}
	if err := rows.Err(); err != nil {
		return Page{}, err
	}
	return Page{Rows: data, CurrentPage: page, LastPage: lastPage(total, perPage), Total: total}, nil
}

func (s *Service) Show(ctx context.Context, actor Actor, id int64) (map[string]any, error) {
	predicate, args, err := visibleSQL(actor, "c")
	if err != nil {
		return nil, err
	}
	args = append(args, id)
	row, err := scanCustomer(s.database.QueryRowContext(ctx, `SELECT c.id,c.agent_id,c.owner_user_id,c.name,c.phone,c.email,
		c.customer_type,c.relation,c.address,c.prospect_percent,c.note,c.archived_at,c.created_at,c.updated_at,
		c.linked_konsumen_id,u.name FROM crm_customers c JOIN users u ON u.id=c.owner_user_id
		WHERE `+predicate+` AND c.id=?`, args...).Scan)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	return row, err
}

func (s *Service) Visits(ctx context.Context, actor Actor, customerID int64) ([]map[string]any, error) {
	if _, err := s.Show(ctx, actor, customerID); err != nil {
		return nil, err
	}
	rows, err := s.database.QueryContext(ctx, `SELECT id,agent_id,crm_customer_id,user_id,visit_date,status,
		prospect_percent,note,plan_next_date,created_at,updated_at FROM crm_visits
		WHERE crm_customer_id=? ORDER BY visit_date DESC,id DESC`, customerID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, agentID, customer, userID int64
		var visitDate time.Time
		var status string
		var prospect sql.NullInt64
		var note sql.NullString
		var nextDate sql.NullTime
		var created, updated time.Time
		if err := rows.Scan(&id, &agentID, &customer, &userID, &visitDate, &status, &prospect, &note, &nextDate, &created, &updated); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{"id": id, "agent_id": agentID, "crm_customer_id": customer,
			"user_id": userID, "visit_date": isoMicro(visitDate), "status": status, "prospect_percent": nullableInt(prospect),
			"note": nullableString(note), "plan_next_date": nullTimeMicro(nextDate),
			"created_at": isoMicro(created), "updated_at": isoMicro(updated)})
	}
	return data, rows.Err()
}

func (s *Service) FollowUps(ctx context.Context, actor Actor, until string) ([]map[string]any, error) {
	predicate, args, err := visibleSQL(actor, "c")
	if err != nil {
		return nil, err
	}
	if until == "" {
		until = time.Now().Format("2006-01-02")
	}
	args = append(args, until)
	rows, err := s.database.QueryContext(ctx, `SELECT v.id,v.agent_id,v.crm_customer_id,v.user_id,v.visit_date,v.status,
		v.prospect_percent,v.note,v.plan_next_date,v.created_at,v.updated_at,c.name,c.phone,c.owner_user_id
		FROM crm_visits v JOIN crm_customers c ON c.id=v.crm_customer_id
		WHERE `+predicate+` AND c.archived_at IS NULL
		AND v.id=(SELECT MAX(v2.id) FROM crm_visits v2 WHERE v2.crm_customer_id=c.id)
		AND v.plan_next_date IS NOT NULL AND v.plan_next_date<=?
		ORDER BY v.plan_next_date`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, agentID, customerID, userID, ownerID int64
		var visitDate time.Time
		var status string
		var prospect sql.NullInt64
		var note, name, phone sql.NullString
		var nextDate sql.NullTime
		var created, updated time.Time
		if err := rows.Scan(&id, &agentID, &customerID, &userID, &visitDate, &status, &prospect, &note, &nextDate, &created, &updated, &name, &phone, &ownerID); err != nil {
			return nil, err
		}
		data = append(data, map[string]any{"id": id, "agent_id": agentID, "crm_customer_id": customerID,
			"user_id": userID, "visit_date": isoMicro(visitDate), "status": status, "prospect_percent": nullableInt(prospect),
			"note": nullableString(note), "plan_next_date": nullTimeMicro(nextDate),
			"created_at": isoMicro(created), "updated_at": isoMicro(updated),
			"customer": map[string]any{"id": customerID, "name": nullableString(name), "phone": nullableString(phone), "owner_user_id": ownerID}})
	}
	return data, rows.Err()
}

func scanCustomer(scan func(...any) error) (map[string]any, error) {
	var id, agentID, ownerID int64
	var name string
	var phone, email, kind, relation, address, note sql.NullString
	var archived sql.NullTime
	var prospect, linked sql.NullInt64
	var created, updated time.Time
	var ownerName string
	if err := scan(&id, &agentID, &ownerID, &name, &phone, &email, &kind, &relation, &address, &prospect, &note,
		&archived, &created, &updated, &linked, &ownerName); err != nil {
		return nil, err
	}
	return map[string]any{"id": id, "agent_id": agentID, "owner_user_id": ownerID, "name": name,
		"phone": nullableString(phone), "email": nullableString(email), "customer_type": nullableString(kind),
		"relation": nullableString(relation), "address": nullableString(address), "prospect_percent": nullableInt(prospect),
		"note": nullableString(note), "archived_at": nullTimeMicro(archived), "created_at": isoMicro(created), "updated_at": isoMicro(updated),
		"linked_konsumen_id": nullableInt(linked), "owner": map[string]any{"id": ownerID, "name": ownerName}}, nil
}

func nullableString(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}
func nullableInt(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}
func pagination(page, perPage, defaultPerPage, maxPerPage int) (int, int) {
	if page < 1 {
		page = 1
	}
	if perPage < 1 {
		perPage = defaultPerPage
	}
	if perPage > maxPerPage {
		perPage = maxPerPage
	}
	return page, perPage
}
func lastPage(total, perPage int) int {
	if total == 0 {
		return 1
	}
	return (total + perPage - 1) / perPage
}

// ScopePredicate is exported for the report package and regression tests.
func ScopePredicate(actor Actor, alias string) (string, []any, error) {
	if alias == "" || strings.ContainsAny(alias, " ;\t\n") {
		return "", nil, fmt.Errorf("invalid SQL alias")
	}
	return visibleSQL(actor, alias)
}
