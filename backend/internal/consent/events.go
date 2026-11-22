package consent

// ConsentEvent append-only chain operations (ConsentService::capture, withdraw, dispute,
// reinstate) plus the order-scoped read surfaces (indexForOrder, context).

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"time"
)

type ScopeLine struct {
	OrderItemID int64 `json:"order_item_id"`
	Quantity    int64 `json:"quantity"`
}

func timeNowUTC() time.Time { return time.Now().UTC() }

// Capture ports ConsentService::capture (evidence bytes hashed; file stored by the media path
// convention consent/evidence).
func (s *Service) Capture(ctx context.Context, a Actor, subjectID, orderID int64, purpose string,
	scope []ScopeLine, evidence []byte, evidenceName, note string, challengeID int64, consentKey string) (map[string]any, error) {
	var event map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		sub, err := s.loadSubjectTx(ctx, tx, subjectID)
		if err != nil {
			return err
		}
		if !s.canCapture(a, sub) {
			return errStr(403, "Aksi tidak diizinkan untuk peran Anda.")
		}
		var orderKonsumen, orderAgent int64
		if err := tx.QueryRowContext(ctx, `SELECT konsumen_id, agent_id FROM orders WHERE id = ? FOR UPDATE`,
			orderID).Scan(&orderKonsumen, &orderAgent); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return errStr(404, "")
			}
			return err
		}
		if orderKonsumen != subjectID || orderAgent != sub.AgentID {
			return errStr(422, "Pesanan bukan milik pelanggan ini.")
		}
		if consentKey != "" {
			var existingID int64
			var exActor, exSubject int64
			eerr := tx.QueryRowContext(ctx, `SELECT id, actor_id, subject_user_id FROM consent_events
				WHERE consent_key = ?`, consentKey).Scan(&existingID, &exActor, &exSubject)
			if eerr == nil {
				if exActor != a.ID || exSubject != subjectID {
					return errStr(409, "Kunci persetujuan ini sudah dipakai permintaan lain.")
				}
				event, err = s.renderEvent(ctx, tx, existingID)
				return err
			}
			if eerr != sql.ErrNoRows {
				return eerr
			}
		}
		normalized, serr := s.validateScope(ctx, tx, orderID, scope)
		if serr != nil {
			return serr
		}
		sum := sha256.Sum256(evidence)
		hash := hex.EncodeToString(sum[:])
		// OTP challenge usability under lock.
		var chSubject, chCreatedBy int64
		var chPurpose string
		var chConsumed, chInvalidated, chVerified sql.NullTime
		cerr := tx.QueryRowContext(ctx, `SELECT subject_user_id, created_by, purpose, consumed_at,
			invalidated_at, verified_at FROM identity_otp_challenges WHERE id = ? FOR UPDATE`, challengeID).
			Scan(&chSubject, &chCreatedBy, &chPurpose, &chConsumed, &chInvalidated, &chVerified)
		if errors.Is(cerr, sql.ErrNoRows) {
			return errStr(404, "")
		}
		if cerr != nil {
			return cerr
		}
		usable := chSubject == subjectID && chCreatedBy == a.ID && chPurpose == purpose &&
			!chConsumed.Valid && !chInvalidated.Valid && chVerified.Valid &&
			chVerified.Time.After(timeNowUTC().Add(-15*time.Minute))
		if !usable {
			return errStr(422, "Verifikasi tidak ada, kedaluwarsa, atau sudah dipakai.")
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET consumed_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, challengeID); err != nil {
			return err
		}
		scopeJSON, _ := json.Marshal(normalized)
		path := "consent/evidence/" + evidenceName
		res, ierr := tx.ExecContext(ctx, `INSERT INTO consent_events (agent_id, subject_user_id, order_id,
			event_type, purpose, scope, evidence_path, evidence_sha256, note, otp_challenge_id, actor_id,
			consent_key, policy_version, occurred_at, created_at, updated_at)
			VALUES (?, ?, ?, 'consent_captured', ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			nullI(sub.AgentID), subjectID, orderID, purpose, string(scopeJSON), path, hash, note,
			challengeID, a.ID, nullStr(consentKey), policyVersion)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		event, err = s.renderEvent(ctx, tx, newID)
		return err
	})
	if err != nil {
		return nil, err
	}
	return event, nil
}

func nullStr(v string) any {
	if v == "" {
		return nil
	}
	return v
}

// validateScope ports ConsentService::validateScope (order-bound items, quantity bounds).
func (s *Service) validateScope(ctx context.Context, tx *sql.Tx, orderID int64, scope []ScopeLine) ([]map[string]any, error) {
	if len(scope) == 0 {
		return nil, errStr(422, "Cakupan persetujuan (item dan jumlah) wajib diisi.")
	}
	normalized := make([]map[string]any, 0)
	seen := map[int64]bool{}
	for _, line := range scope {
		var itemOrder int64
		var fulfilled, returned int64
		err := tx.QueryRowContext(ctx, `SELECT order_id, fulfilled_quantity, returned_quantity
			FROM order_items WHERE id = ?`, line.OrderItemID).Scan(&itemOrder, &fulfilled, &returned)
		if errors.Is(err, sql.ErrNoRows) || (err == nil && itemOrder != orderID) {
			return nil, errStr(422, "Cakupan persetujuan berisi item atau jumlah yang tidak valid.")
		}
		if err != nil {
			return nil, err
		}
		maxScope := fulfilled - returned
		if line.Quantity < 1 || line.Quantity > maxScope || seen[line.OrderItemID] {
			return nil, errStr(422, "Cakupan persetujuan berisi item atau jumlah yang tidak valid.")
		}
		seen[line.OrderItemID] = true
		normalized = append(normalized, map[string]any{"order_item_id": line.OrderItemID, "quantity": line.Quantity})
	}
	return normalized, nil
}

// renderEvent renders a ConsentEventResource row.
func (s *Service) renderEvent(ctx context.Context, tx *sql.Tx, id int64) (map[string]any, error) {
	var evID int64
	var rootEvent sql.NullInt64
	var eventType, purpose string
	var subjectID int64
	var crmCustomer sql.NullInt64
	var orderID int64
	var returnID sql.NullInt64
	var scope sql.NullString
	var evidencePath sql.NullString
	var evidenceSHA sql.NullString
	var note sql.NullString
	var actorID int64
	var policyVer string
	var occurredAt sql.NullTime
	err := tx.QueryRowContext(ctx, `SELECT id, root_event_id, event_type, purpose, subject_user_id,
		crm_customer_id, order_id, return_id, scope, evidence_path, evidence_sha256, note, actor_id,
		policy_version, occurred_at FROM consent_events WHERE id = ?`, id).
		Scan(&evID, &rootEvent, &eventType, &purpose, &subjectID, &crmCustomer, &orderID, &returnID,
			&scope, &evidencePath, &evidenceSHA, &note, &actorID, &policyVer, &occurredAt)
	if err != nil {
		return nil, err
	}
	var scopeVal any
	if scope.Valid && scope.String != "" {
		var parsed any
		if json.Unmarshal([]byte(scope.String), &parsed) == nil {
			scopeVal = parsed
		} else {
			scopeVal = []any{}
		}
	}
	return map[string]any{
		"id": evID, "root_event_id": nullI64(rootEvent), "event_type": eventType, "purpose": purpose,
		"subject_user_id": subjectID, "crm_customer_id": nullI64(crmCustomer), "order_id": orderID,
		"return_id": nullI64(returnID), "scope": scopeVal,
		"has_evidence":    evidencePath.Valid && evidencePath.String != "",
		"evidence_sha256": nullStrPtr(evidenceSHA), "note": nullStrPtr(note), "actor_id": actorID,
		"policy_version": policyVer, "occurred_at": fmtTime(occurredAt),
	}, nil
}

func nullI64(v sql.NullInt64) any {
	if !v.Valid {
		return nil
	}
	return v.Int64
}

func nullStrPtr(v sql.NullString) any {
	if !v.Valid {
		return nil
	}
	return v.String
}

func fmtTime(t sql.NullTime) any {
	if !t.Valid {
		return nil
	}
	return t.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// Append ports ConsentService::append (withdraw/dispute/reinstate chain transitions).
func (s *Service) Append(ctx context.Context, a Actor, rootID int64, newType, reason string) (map[string]any, error) {
	var event map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var rootRoot sql.NullInt64
		var rootType string
		var rootAgent, rootSubject int64
		var crmCustomer sql.NullInt64
		var rootOrder int64
		var rootReturn sql.NullInt64
		var rootPurpose, rootScope sql.NullString
		rerr := tx.QueryRowContext(ctx, `SELECT root_event_id, event_type, agent_id, subject_user_id,
			crm_customer_id, order_id, return_id, purpose, scope FROM consent_events WHERE id = ?`,
			rootID).Scan(&rootRoot, &rootType, &rootAgent, &rootSubject, &crmCustomer, &rootOrder,
			&rootReturn, &rootPurpose, &rootScope)
		if errors.Is(rerr, sql.ErrNoRows) {
			return errStr(404, "")
		}
		if rerr != nil {
			return rerr
		}
		if rootRoot.Valid || rootType != "consent_captured" {
			return errStr(422, "Hanya catatan persetujuan asli yang dapat diubah lewat event baru.")
		}
		allowed := false
		if crmCustomer.Valid {
			var owner, crmAgent int64
			if err := tx.QueryRowContext(ctx, `SELECT owner_user_id, agent_id FROM crm_customers WHERE id = ?`,
				crmCustomer.Int64).Scan(&owner, &crmAgent); err == nil {
				allowed = (a.Role == "sales" || a.Role == "sales-kurir-sub" || a.Role == "korsal") &&
					owner == a.ID && a.AgentID == crmAgent
				if !allowed {
					allowed = a.IsSuper || ((a.Role == "agen" || a.Role == "admin") && a.AgentID == crmAgent)
				}
			}
		} else {
			sub, lerr := s.loadSubjectTx(ctx, tx, rootSubject)
			if lerr != nil {
				return lerr
			}
			if newType == "reinstated" {
				allowed = canReview(a, sub)
			} else {
				allowed = s.canWithdrawOrDispute(a, sub)
			}
		}
		if !allowed {
			return errStr(403, "Aksi tidak diizinkan untuk peran Anda.")
		}
		if _, err := tx.ExecContext(ctx, `SELECT id FROM consent_events WHERE id = ? FOR UPDATE`, rootID); err != nil {
			return err
		}
		var latestType string
		if err := tx.QueryRowContext(ctx, `SELECT event_type FROM consent_events
			WHERE (id = ? OR root_event_id = ?) ORDER BY id DESC LIMIT 1`, rootID, rootID).Scan(&latestType); err != nil {
			return err
		}
		var allowedFrom map[string]bool
		switch newType {
		case "consent_withdrawn":
			allowedFrom = map[string]bool{"consent_captured": true, "consent_reinstated": true}
		case "consent_disputed":
			allowedFrom = map[string]bool{"consent_captured": true, "consent_reinstated": true, "consent_withdrawn": true}
		case "consent_reinstated":
			allowedFrom = map[string]bool{"consent_disputed": true}
		}
		if !allowedFrom[latestType] {
			return errStr(422, "Aksi ini tidak diizinkan dari status persetujuan saat ini.")
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO consent_events (agent_id, subject_user_id,
			crm_customer_id, order_id, return_id, root_event_id, event_type, purpose, scope, note,
			actor_id, policy_version, occurred_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			nullI(rootAgent), rootSubject, nullI64(crmCustomer), rootOrder, nullI64(rootReturn), rootID,
			newType, nullStrPtr(rootPurpose), nullStrPtr(rootScope), reason, a.ID, policyVersion)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		var rerr2 error
		event, rerr2 = s.renderEvent(ctx, tx, newID)
		return rerr2
	})
	if err != nil {
		return nil, err
	}
	return event, nil
}

// IndexForOrder ports ConsentController::indexForOrder (Order view policy enforced by the handler).
func (s *Service) IndexForOrder(ctx context.Context, orderID int64) ([]map[string]any, error) {
	rows, err := s.database.DB.QueryContext(ctx, `SELECT id, root_event_id, event_type, purpose,
		subject_user_id, crm_customer_id, order_id, return_id, scope, evidence_path, evidence_sha256,
		note, actor_id, policy_version, occurred_at FROM consent_events WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	data := make([]map[string]any, 0)
	for rows.Next() {
		var evID int64
		var rootEvent sql.NullInt64
		var eventType, purpose string
		var subjectID int64
		var crmCustomer sql.NullInt64
		var oid int64
		var returnID sql.NullInt64
		var scope, evidencePath, evidenceSHA, note sql.NullString
		var actorID int64
		var policyVer string
		var occurredAt sql.NullTime
		if err := rows.Scan(&evID, &rootEvent, &eventType, &purpose, &subjectID, &crmCustomer, &oid,
			&returnID, &scope, &evidencePath, &evidenceSHA, &note, &actorID, &policyVer, &occurredAt); err != nil {
			return nil, err
		}
		var scopeVal any
		if scope.Valid && scope.String != "" {
			var parsed any
			if json.Unmarshal([]byte(scope.String), &parsed) == nil {
				scopeVal = parsed
			} else {
				scopeVal = []any{}
			}
		}
		data = append(data, map[string]any{
			"id": evID, "root_event_id": nullI64(rootEvent), "event_type": eventType, "purpose": purpose,
			"subject_user_id": subjectID, "crm_customer_id": nullI64(crmCustomer), "order_id": oid,
			"return_id": nullI64(returnID), "scope": scopeVal,
			"has_evidence":    evidencePath.Valid && evidencePath.String != "",
			"evidence_sha256": nullStrPtr(evidenceSHA), "note": nullStrPtr(note), "actor_id": actorID,
			"policy_version": policyVer, "occurred_at": fmtTime(occurredAt),
		})
	}
	return data, rows.Err()
}

// Context ports ConsentController::context (subject id revealed only to a capturing actor).
func (s *Service) Context(ctx context.Context, a Actor, orderID int64) (map[string]any, error) {
	var konsumenID sql.NullInt64
	var crmCustomerID sql.NullInt64
	if err := s.database.DB.QueryRowContext(ctx, `SELECT konsumen_id, crm_customer_id
		FROM orders WHERE id = ?`, orderID).Scan(&konsumenID, &crmCustomerID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, errStr(404, "")
		}
		return nil, err
	}
	if crmCustomerID.Valid {
		payload := map[string]any{"mode": "crm", "purposes": []string{"assisted_return"}}
		var owner, crmAgent int64
		var linked sql.NullInt64
		if err := s.database.DB.QueryRowContext(ctx, `SELECT owner_user_id, agent_id, linked_konsumen_id
			FROM crm_customers WHERE id = ?`, crmCustomerID.Int64).Scan(&owner, &crmAgent, &linked); err == nil {
			canCaptureCrm := (a.Role == "sales" || a.Role == "sales-kurir-sub" || a.Role == "korsal") &&
				owner == a.ID && a.AgentID == crmAgent
			payload["can_capture"] = canCaptureCrm
			if canCaptureCrm {
				var email sql.NullString
				var emailVerified sql.NullTime
				channelAvailable := false
				if linked.Valid {
					if err := s.database.DB.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users
					WHERE id = ?`, linked.Int64).Scan(&email, &emailVerified); err == nil {
						channelAvailable = email.Valid && email.String != "" && emailVerified.Valid
					}
				}
				payload["channel_available"] = channelAvailable
				payload["crm_customer_id"] = crmCustomerID.Int64
				items, ierr := s.contextItems(ctx, orderID)
				if ierr != nil {
					return nil, ierr
				}
				payload["items"] = items
			}
		} else {
			payload["can_capture"] = false
		}
		return payload, nil
	}
	payload := map[string]any{"purposes": []string{"assisted_return"}}
	canCapture := false
	if konsumenID.Valid {
		sub, err := s.loadSubjectByID(ctx, konsumenID.Int64)
		if err == nil {
			canCapture = s.canCapture(a, sub)
			if canCapture {
				payload["subject_user_id"] = sub.ID
				payload["subject_email_verified"] = sub.Email.Valid && sub.Email.String != "" && sub.EmailVerifiedAt.Valid
				items, ierr := s.contextItems(ctx, orderID)
				if ierr != nil {
					return nil, ierr
				}
				payload["items"] = items
			}
		}
	}
	payload["can_capture"] = canCapture
	return payload, nil
}

func (s *Service) loadSubjectByID(ctx context.Context, id int64) (*Subject, error) {
	var sub Subject
	err := s.database.DB.QueryRowContext(ctx, `SELECT u.id, r.slug, u.agent_id, u.sales_id, u.korsal_id,
		u.email, u.email_verified_at FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, id).
		Scan(&sub.ID, &sub.Role, &sub.AgentID, &sub.SalesID, &sub.KorsalID, &sub.Email, &sub.EmailVerifiedAt)
	if err != nil {
		return nil, err
	}
	return &sub, nil
}

func (s *Service) contextItems(ctx context.Context, orderID int64) ([]map[string]any, error) {
	rows, err := s.database.DB.QueryContext(ctx, `SELECT id, product_name_snapshot,
		variation_label_snapshot, fulfilled_quantity, returned_quantity FROM order_items
		WHERE order_id = ? ORDER BY id`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]map[string]any, 0)
	for rows.Next() {
		var id, fulfilled, returned int64
		var pname, vlabel sql.NullString
		if err := rows.Scan(&id, &pname, &vlabel, &fulfilled, &returned); err != nil {
			return nil, err
		}
		max := fulfilled - returned
		if max < 0 {
			max = 0
		}
		items = append(items, map[string]any{"id": id, "name": nullStrPtr(pname),
			"variation": nullStrPtr(vlabel), "max_quantity": max})
	}
	return items, rows.Err()
}
