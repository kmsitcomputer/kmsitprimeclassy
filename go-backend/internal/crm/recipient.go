// Package crm ports the CRM assisted surfaces: RefundRecipientService
// (G17-D4 append-only refund-recipient evidence).
package crm

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
)

// RecipientActor is the actor view for the refund-recipient surface.
type RecipientActor struct {
	ID      int64
	Role    string
	AgentID int64
	IsSuper bool
}

// DomainError mirrors the other domain error shapes.
type DomainError struct {
	Status  int
	Message string
	Fields  map[string][]string
}

func (e *DomainError) Error() string { return e.Message }

func errStr(status int, message string) error { return &DomainError{Status: status, Message: message} }

// Destination is the bank/e-wallet destination payload.
type Destination struct {
	BankOrProvider *string `json:"bank_or_provider"`
	AccountNumber  *string `json:"account_number"`
	AccountHolder  *string `json:"account_holder"`
}

// RecipientInput is the storeRecipient request body.
type RecipientInput struct {
	RecipientName     *string      `json:"recipient_name"`
	RecipientPhone    *string      `json:"recipient_phone"`
	RecipientRelation *string      `json:"recipient_relation"`
	Method            *string      `json:"method"`
	Destination       *Destination `json:"destination"`
	AuthorizationNote *string      `json:"authorization_note"`
	ConsentEventID    *int64       `json:"consent_event_id"`
	ReturnID          *int64       `json:"return_id"`
	Evidence          []byte
	EvidenceName      string
}

// canRecord ports RefundRecipientService::canRecord.
func (s *Service) canRecord(ctx context.Context, q ctxQueryer, a RecipientActor, orderID int64) bool {
	var agentID int64
	var salesID, korsalID, konsumenID sql.NullInt64
	var crmCustomer sql.NullInt64
	if err := q.QueryRowContext(ctx, `SELECT agent_id, sales_id, korsal_id, konsumen_id, crm_customer_id
		FROM orders WHERE id = ?`, orderID).Scan(&agentID, &salesID, &korsalID, &konsumenID, &crmCustomer); err != nil {
		return false
	}
	if a.IsSuper {
		return true
	}
	if a.AgentID == 0 || a.AgentID != agentID {
		return false
	}
	switch a.Role {
	case "sales", "sales-kurir-sub":
		return salesID.Valid && salesID.Int64 == a.ID
	case "korsal":
		var buyerRole string
		_ = q.QueryRowContext(ctx, `SELECT r.slug FROM users u JOIN roles r ON r.id = u.role_id
			WHERE u.id = ?`, konsumenID).Scan(&buyerRole)
		if buyerRole == "agen" || buyerRole == "korsal" || buyerRole == "sales" || buyerRole == "sales-kurir-sub" {
			return konsumenID.Valid && konsumenID.Int64 == a.ID
		}
		return (salesID.Valid && salesID.Int64 == a.ID) || (!salesID.Valid && korsalID.Valid && korsalID.Int64 == a.ID)
	case "agen", "admin", "keuangan":
		return true
	}
	return false
}

type ctxQueryer interface {
	QueryRowContext(ctx context.Context, query string, args ...any) *sql.Row
}

// CanSeeDestination ports RefundRecipientService::canSeeDestination (full bank details only
// for those who actually pay out).
func CanSeeDestination(a RecipientActor) bool { return a.IsSuper || a.Role == "keuangan" }

// IndexRecipient ports CrmAssistedController::indexRecipient (Order view policy enforced by handler).
func (s *Service) IndexRecipient(ctx context.Context, orderID int64, a RecipientActor) ([]map[string]any, error) {
	rows, err := s.database.DB.QueryContext(ctx, `SELECT id, order_id, return_id, recipient_name,
		recipient_phone, recipient_relation, method, destination_details, consent_event_id,
		evidence_path, evidence_sha256, authorization_note, supersedes_id, captured_by, occurred_at
		FROM refund_recipient_evidences WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var id, oid int64
		var returnID sql.NullInt64
		var name string
		var phone, relation sql.NullString
		var method string
		var details sql.NullString
		var consentID sql.NullInt64
		var evidencePath, evidenceSHA, authNote sql.NullString
		var supersedes sql.NullInt64
		var capturedBy int64
		var occurredAt sql.NullTime
		if err := rows.Scan(&id, &oid, &returnID, &name, &phone, &relation, &method, &details,
			&consentID, &evidencePath, &evidenceSHA, &authNote, &supersedes, &capturedBy, &occurredAt); err != nil {
			return nil, err
		}
		data = append(data, recipientPayload(id, oid, returnID, name, phone, relation, method, details,
			consentID, evidencePath, evidenceSHA, authNote, supersedes, capturedBy, occurredAt, a))
	}
	return data, rows.Err()
}

func recipientPayload(id, oid int64, returnID sql.NullInt64, name string, phone, relation sql.NullString,
	method string, details sql.NullString, consentID sql.NullInt64, evidencePath, evidenceSHA,
	authNote sql.NullString, supersedes sql.NullInt64, capturedBy int64, occurredAt sql.NullTime, a RecipientActor) map[string]any {
	var detailsVal map[string]any
	if details.Valid && details.String != "" {
		_ = json.Unmarshal([]byte(details.String), &detailsVal)
	}
	var destination any
	if CanSeeDestination(a) {
		destination = detailsVal
	} else if v, ok := detailsVal["account_number"].(string); ok && v != "" {
		tail := v
		if len(tail) > 4 {
			tail = tail[len(tail)-4:]
		}
		destination = map[string]any{"account_number_tail": tail}
	}
	return map[string]any{
		"id": id, "order_id": oid, "return_id": nullI(returnID), "recipient_name": name,
		"recipient_phone": nullS(phone), "recipient_relation": nullS(relation), "method": method,
		"destination": destination, "consent_event_id": nullI(consentID),
		"has_evidence_file": evidencePath.Valid && evidencePath.String != "",
		"evidence_sha256":   nullS(evidenceSHA), "authorization_note": nullS(authNote),
		"supersedes_id": nullI(supersedes), "captured_by": capturedBy, "occurred_at": fmtT(occurredAt),
	}
}

func nullI(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}

func nullS(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func fmtT(t sql.NullTime) any {
	if !t.Valid {
		return nil
	}
	return t.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// RecordRecipient ports RefundRecipientService::record (append-only; supersedes the previous row).
func (s *Service) RecordRecipient(ctx context.Context, a RecipientActor, orderID int64, in RecipientInput) (map[string]any, error) {
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var agentID int64
		var crmCustomer sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT agent_id, crm_customer_id FROM orders
			WHERE id = ? FOR UPDATE`, orderID).Scan(&agentID, &crmCustomer); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return errStr(404, "")
			}
			return err
		}
		if !crmCustomer.Valid {
			return errStr(422, "Order ini tidak dibuat untuk pelanggan CRM.")
		}
		if !s.canRecord(ctx, tx, a, orderID) {
			return errStr(403, "Anda tidak memiliki akses untuk melakukan aksi ini.")
		}
		method := ""
		if in.Method != nil {
			method = *in.Method
		}
		if (method == "bank_transfer" || method == "ewallet") && in.Destination != nil {
			acctEmpty := in.Destination.AccountNumber == nil || *in.Destination.AccountNumber == ""
			holderEmpty := in.Destination.AccountHolder == nil || *in.Destination.AccountHolder == ""
			if acctEmpty || holderEmpty {
				return &DomainError{Status: 422, Message: "Nomor rekening dan nama pemilik wajib untuk metode ini.",
					Fields: map[string][]string{"destination": {"Nomor rekening dan nama pemilik wajib untuk metode ini."}}}
			}
		}
		if in.ConsentEventID != nil {
			var cOrder int64
			var cCrm sql.NullInt64
			cerr := tx.QueryRowContext(ctx, `SELECT order_id, crm_customer_id FROM consent_events
				WHERE id = ?`, *in.ConsentEventID).Scan(&cOrder, &cCrm)
			if cerr != nil || cOrder != orderID || !cCrm.Valid || cCrm.Int64 != crmCustomer.Int64 {
				return errStr(422, "Persetujuan tidak sesuai dengan order atau pelanggan ini.")
			}
		}
		if in.ConsentEventID == nil && in.Evidence == nil {
			return &DomainError{Status: 422, Message: "Sertakan catatan persetujuan atau dokumen/foto otorisasi.",
				Fields: map[string][]string{"authorization": {"Sertakan catatan persetujuan atau dokumen/foto otorisasi."}}}
		}
		if in.ReturnID != nil {
			var exists bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM returns
				WHERE order_id = ? AND id = ?)`, orderID, *in.ReturnID).Scan(&exists); err != nil {
				return err
			}
			if !exists {
				return errStr(422, "Persetujuan tidak sesuai dengan order atau pelanggan ini.")
			}
		}
		var previous sql.NullInt64
		_ = tx.QueryRowContext(ctx, `SELECT id FROM refund_recipient_evidences
			WHERE order_id = ? ORDER BY id DESC LIMIT 1`, orderID).Scan(&previous)
		var evidencePath any
		var evidenceSHA any
		if in.Evidence != nil {
			sum := sha256.Sum256(in.Evidence)
			evidencePath = "refund/recipient-evidence/" + in.EvidenceName
			evidenceSHA = hex.EncodeToString(sum[:])
		}
		var detailsJSON any
		if in.Destination != nil {
			b, _ := json.Marshal(destinationMap(in.Destination))
			detailsJSON = string(b)
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO refund_recipient_evidences (agent_id, order_id,
			crm_customer_id, return_id, recipient_name, recipient_phone, recipient_relation, method,
			destination_details, consent_event_id, authorization_note, evidence_path, evidence_sha256,
			supersedes_id, captured_by, occurred_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			agentID, orderID, crmCustomer, nullIP(in.ReturnID), derefS(in.RecipientName), nullSP(in.RecipientPhone),
			nullSP(in.RecipientRelation), method, detailsJSON, nullIP(in.ConsentEventID), derefS(in.AuthorizationNote),
			evidencePath, evidenceSHA, nullI(previous), a.ID)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, 'App\Models\Order', ?, 'refund.recipient_evidence.recorded', NULL, ?, UTC_TIMESTAMP(6))`,
			a.ID, orderID, mustJSON(map[string]any{"evidence_id": newID, "supersedes_id": nullI(previous),
				"method": method})); err != nil {
			return err
		}
		// Render the created row.
		var rid sql.NullInt64
		var name string
		var phone, relation, details, evidenceP, evidenceS, authN sql.NullString
		var consentID sql.NullInt64
		var sup sql.NullInt64
		var capturedBy int64
		var occurredAt sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT return_id, recipient_name, recipient_phone,
			recipient_relation, method, destination_details, consent_event_id, evidence_path,
			evidence_sha256, authorization_note, supersedes_id, captured_by, occurred_at
			FROM refund_recipient_evidences WHERE id = ?`, newID).
			Scan(&rid, &name, &phone, &relation, &method, &details, &consentID, &evidenceP, &evidenceS,
				&authN, &sup, &capturedBy, &occurredAt); err != nil {
			return err
		}
		var detailsVal map[string]any
		if details.Valid && details.String != "" {
			_ = json.Unmarshal([]byte(details.String), &detailsVal)
		}
		var destination any
		if CanSeeDestination(a) {
			destination = detailsVal
		} else if v, ok := detailsVal["account_number"].(string); ok && v != "" {
			tail := v
			if len(tail) > 4 {
				tail = tail[len(tail)-4:]
			}
			destination = map[string]any{"account_number_tail": tail}
		}
		out = map[string]any{
			"id": newID, "order_id": orderID, "return_id": nullI(rid), "recipient_name": name,
			"recipient_phone": nullS(phone), "recipient_relation": nullS(relation), "method": method,
			"destination": destination, "consent_event_id": nullI(consentID),
			"has_evidence_file": evidenceP.Valid && evidenceP.String != "",
			"evidence_sha256":   nullS(evidenceS), "authorization_note": nullS(authN),
			"supersedes_id": nullI(sup), "captured_by": capturedBy, "occurred_at": fmtT(occurredAt),
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

func destinationMap(d *Destination) map[string]any {
	m := map[string]any{}
	if d.BankOrProvider != nil && *d.BankOrProvider != "" {
		m["bank_or_provider"] = *d.BankOrProvider
	}
	if d.AccountNumber != nil && *d.AccountNumber != "" {
		m["account_number"] = *d.AccountNumber
	}
	if d.AccountHolder != nil && *d.AccountHolder != "" {
		m["account_holder"] = *d.AccountHolder
	}
	return m
}

func derefS(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullSP(v *string) any {
	if v == nil || *v == "" {
		return nil
	}
	return *v
}

func nullIP(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

func mustJSON(v map[string]any) []byte {
	b, _ := json.Marshal(v)
	return b
}
