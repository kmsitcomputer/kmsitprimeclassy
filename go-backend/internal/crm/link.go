package crm

import (
	"context"
	"database/sql"
	"errors"
	"strings"
	"time"
)

// Unit 2 — CRM linking (OD-03/OD-04/CI-05): link a CRM customer to a konsumen ACCOUNT only after an
// OTP challenge bound to that account was verified, inside one Agent, never by contact matching.
// Link/unlink change FUTURE authorization only; the append-only crm_customer_link_events history is
// written for both. The DB's unique linked_konsumen_id / otp_challenge_id indexes are the final
// arbiters against racing links.

var (
	ErrLinkNotAllowed       = errors.New("crm.link_not_allowed")
	ErrAlreadyLinked        = errors.New("crm.already_linked")
	ErrAccountAlreadyLinked = errors.New("crm.account_already_linked")
	ErrNotLinked            = errors.New("crm.not_linked")
	ErrChallengeNotUsable   = errors.New("consent.challenge_not_usable")
	ErrOtpUnavailable       = errors.New("whatsapp.otp_unavailable")
	ErrNumberNotVerified    = errors.New("whatsapp.number_not_verified")
	ErrEmailNotVerified     = errors.New("consent.email_not_verified")
	ErrOtpRateLimited       = errors.New("consent.otp_rate_limited")
)

const (
	otpTTLMinutes   = 10
	otpMaxAttempts  = 5
	otpIssueLimit   = 3
	otpIssueWindowM = 10
	linkWindowMin   = 15
)

// CanCapture mirrors ConsentAccess::canCapture for the referral reach (sales/sks own referrals,
// korsal own referral or its own unowned-by-sales korsal referrals, agen whole branch).
func (s *Service) CanCapture(ctx context.Context, actor Actor, subjectUserID int64) bool {
	var roleSlug string
	var subjectAgent, salesID, korsalID sql.NullInt64
	err := s.database.QueryRowContext(ctx, `SELECT r.slug, u.agent_id, u.sales_id, u.korsal_id
		FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=? AND u.deleted_at IS NULL`, subjectUserID).
		Scan(&roleSlug, &subjectAgent, &salesID, &korsalID)
	if err != nil || roleSlug != "konsumen" {
		return false
	}
	if !subjectAgent.Valid || actor.AgentID == nil || *actor.AgentID != subjectAgent.Int64 {
		return false
	}
	if actor.Role == "sales" || actor.Role == "sales-kurir-sub" {
		return salesID.Valid && salesID.Int64 == actor.ID
	}
	if actor.Role == "korsal" {
		return (salesID.Valid && salesID.Int64 == actor.ID) || (!salesID.Valid && korsalID.Valid && korsalID.Int64 == actor.ID)
	}
	return actor.Role == "agen"
}

// LinkCandidates ports CrmAssistedController::linkCandidates: ONLY konsumen this seller referred,
// same Agent, active, not already linked; contact data masked; ordered by name, limit 200.
func (s *Service) LinkCandidates(ctx context.Context, actor Actor) ([]map[string]any, error) {
	if actor.AgentID == nil {
		return []map[string]any{}, nil
	}
	reach := "u.sales_id = ?"
	args := []any{actor.ID}
	if actor.Role == "korsal" {
		reach = "(u.sales_id = ? OR (u.sales_id IS NULL AND u.korsal_id = ?))"
		args = []any{actor.ID, actor.ID}
	} else if actor.Role != "sales" && actor.Role != "sales-kurir-sub" {
		return []map[string]any{}, nil
	}
	rows, err := s.database.QueryContext(ctx, `SELECT u.id, u.name, u.phone, u.email, u.email_verified_at
		FROM users u JOIN roles r ON u.role_id=r.id
		WHERE r.slug='konsumen' AND u.agent_id=? AND u.status='active' AND u.deleted_at IS NULL AND `+reach+`
		AND u.id NOT IN (SELECT linked_konsumen_id FROM crm_customers WHERE linked_konsumen_id IS NOT NULL)
		ORDER BY u.name LIMIT 200`, append([]any{*actor.AgentID}, args...)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id int64
		var name string
		var phone, email sql.NullString
		var verified sql.NullTime
		if err := rows.Scan(&id, &name, &phone, &email, &verified); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id": id, "name": name,
			"phone_tail":     phoneTail(phone),
			"email_masked":   maskEmail(email),
			"email_verified": email.Valid && verified.Valid,
		})
	}
	return out, rows.Err()
}

func phoneTail(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	return "…" + v.String[max(0, len(v.String)-3):]
}

func maskEmail(v sql.NullString) any {
	if !v.Valid || v.String == "" {
		return nil
	}
	at := strings.LastIndexByte(v.String, '@')
	if at <= 0 {
		return v.String
	}
	return v.String[:1] + "***@" + v.String[at+1:]
}

// IssueLinkOtp ports OtpChallengeService::issue for PURPOSE_CRM_LINK. Returns the serialized challenge.
func (s *Service) IssueLinkOtp(ctx context.Context, actor Actor, customerID, konsumenID int64, channel string) (map[string]any, error) {
	var ownerID, agentID int64
	// canLink == canWrite
	var err error
	ownerID, agentID, err = s.BindingCustomer(ctx, actor, customerID)
	if err != nil {
		return nil, err
	}
	if !canWrite(actor, ownerID, agentID) {
		return nil, ErrLinkNotAllowed
	}
	// subject checks: role konsumen, same agent as the customer, and the referral reach
	var subjectAgent sql.NullInt64
	var roleSlug string
	var subjectEmail sql.NullString
	var subjectEmailVerified sql.NullTime
	if err := s.database.QueryRowContext(ctx, `SELECT r.slug, u.agent_id, u.email, u.email_verified_at
		FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=? AND u.deleted_at IS NULL`, konsumenID).
		Scan(&roleSlug, &subjectAgent, &subjectEmail, &subjectEmailVerified); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrLinkNotAllowed
		}
		return nil, err
	}
	if roleSlug != "konsumen" || !subjectAgent.Valid || subjectAgent.Int64 != agentID {
		return nil, ErrLinkNotAllowed
	}
	if !s.CanCapture(ctx, actor, konsumenID) {
		return nil, ErrLinkNotAllowed
	}
	// channel: email (default) or whatsapp — WhatsApp is gated by the gateway settings and a proven number
	var msisdn string
	policy := map[string]int{"ttl": otpTTLMinutes, "max": otpMaxAttempts, "limit": otpIssueLimit, "window": otpIssueWindowM, "cooldown": 0}
	if channel == "whatsapp" {
		usable, err := s.whatsappUsable(ctx, subjectAgent.Int64)
		if err != nil {
			return nil, err
		}
		if !usable {
			return nil, ErrOtpUnavailable
		}
		msisdn, err = s.provenMsisdn(ctx, konsumenID)
		if err != nil {
			return nil, err
		}
		if msisdn == "" {
			return nil, ErrNumberNotVerified
		}
	} else if !subjectEmail.Valid || subjectEmail.String == "" || !subjectEmailVerified.Valid {
		return nil, ErrEmailNotVerified
	}
	// rate limit + invalidate previous unconsumed challenges, then create (serialized per subject)
	var challengeID int64
	var expiresAt time.Time
	txErr := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var since time.Time
		since = time.Now().UTC().Add(-time.Duration(policy["window"]) * time.Minute)
		var n int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
			WHERE subject_user_id=? AND purpose='crm_link' AND created_at > ?`, konsumenID, since).Scan(&n); err != nil {
			return err
		}
		if n >= policy["limit"] {
			return ErrOtpRateLimited
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET invalidated_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
			WHERE subject_user_id=? AND purpose='crm_link' AND consumed_at IS NULL AND invalidated_at IS NULL`, konsumenID); err != nil {
			return err
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO identity_otp_challenges
			(agent_id, subject_user_id, crm_customer_id, created_by, purpose, code_hash, sent_to_email, attempts, max_attempts, expires_at, created_at, updated_at)
			VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
			subjectAgent.Int64, konsumenID, customerID, actor.ID, "crm_link", strings.Repeat("0", 64),
			sentTo(channel, subjectEmail, msisdn), 0, policy["max"],
			time.Now().UTC().Add(time.Duration(policy["ttl"])*time.Minute).Format("2006-01-02 15:04:05"))
		if err != nil {
			return err
		}
		challengeID, err = res.LastInsertId()
		if err != nil {
			return err
		}
		if err := tx.QueryRowContext(ctx, `SELECT expires_at FROM identity_otp_challenges WHERE id=?`, challengeID).Scan(&expiresAt); err != nil {
			return err
		}
		return nil
	})
	if txErr != nil {
		return nil, txErr
	}
	return map[string]any{
		"challenge_id": challengeID,
		"expires_at":   isoMicro(expiresAt),
		"max_attempts": policy["max"],
	}, nil
}

func sentTo(channel string, email sql.NullString, msisdn string) string {
	if channel == "whatsapp" {
		return "whatsapp:" + maskMsisdn(msisdn)
	}
	if email.Valid {
		return email.String
	}
	return ""
}

func maskMsisdn(msisdn string) string {
	if len(msisdn) <= 4 {
		return strings.Repeat("*", len(msisdn))
	}
	return strings.Repeat("*", len(msisdn)-4) + msisdn[len(msisdn)-4:]
}

// whatsappUsable mirrors WhatsAppOtpChannel::prepare's gates (settings + OTP switch). The device
// layer is env-specific; the reference environment runs without a configured gateway, so the 409
// branch is the exercised path.
func (s *Service) whatsappUsable(ctx context.Context, agentID int64) (bool, error) {
	var enabled int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM settings
		WHERE `+"`group`"+`='integration.whatsapp' AND key='wa.enabled' AND value IN ('1','true')`).Scan(&enabled); err != nil {
		return false, err
	}
	if enabled == 0 {
		return false, nil
	}
	var configured int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM settings
		WHERE `+"`group`"+`='integration.whatsapp' AND key IN ('wa.base_url','wa.username','wa.password') AND value <> ''`).Scan(&configured); err != nil {
		return false, err
	}
	if configured < 3 {
		return false, nil
	}
	var otpOn int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM settings
		WHERE `+"`group`"+`='integration.whatsapp' AND key='wa.otp.enabled' AND value IN ('1','true')`).Scan(&otpOn); err != nil {
		return false, err
	}
	return otpOn > 0, nil
}

// provenMsisdn mirrors VerifiedWhatsAppNumberResolver::msisdnFor: users.phone as msisdn with a
// CONSUMED whatsapp_number proof challenge for exactly that number.
func (s *Service) provenMsisdn(ctx context.Context, userID int64) (string, error) {
	var phone sql.NullString
	if err := s.database.QueryRowContext(ctx, `SELECT phone FROM users WHERE id=?`, userID).Scan(&phone); err != nil {
		return "", err
	}
	if !phone.Valid || phone.String == "" {
		return "", nil
	}
	msisdn := phone.String
	var n int
	if err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
		WHERE subject_user_id=? AND purpose='whatsapp_number' AND sent_to_email=? AND consumed_at IS NOT NULL AND invalidated_at IS NULL`,
		userID, "wa:"+msisdn).Scan(&n); err != nil {
		return "", err
	}
	if n == 0 {
		return "", nil
	}
	return msisdn, nil
}

// Link consumes a verified crm_link challenge and binds the account (OD-03). Returns the fresh customer.
func (s *Service) Link(ctx context.Context, actor Actor, customerID, konsumenID, challengeID int64) (map[string]any, error) {
	var ownerID, agentID int64
	ownerID, agentID, err := s.BindingCustomer(ctx, actor, customerID)
	if err != nil {
		return nil, err
	}
	if !canWrite(actor, ownerID, agentID) {
		return nil, ErrCannotWrite
	}
	// CI-05: konsumen only, same Agent, and within the actor's referral reach
	var roleSlug string
	var subjectAgent sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT r.slug, u.agent_id FROM users u JOIN roles r ON u.role_id=r.id
		WHERE u.id=? AND u.deleted_at IS NULL`, konsumenID).Scan(&roleSlug, &subjectAgent); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, ErrLinkNotAllowed
		}
		return nil, err
	}
	if roleSlug != "konsumen" || !subjectAgent.Valid || subjectAgent.Int64 != agentID || !s.CanCapture(ctx, actor, konsumenID) {
		return nil, ErrLinkNotAllowed
	}
	var archived sql.NullTime
	if err := s.database.QueryRowContext(ctx, `SELECT archived_at FROM crm_customers WHERE id=?`, customerID).Scan(&archived); err != nil {
		return nil, err
	}
	if archived.Valid {
		return nil, ErrCustomerArchived
	}
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var linked sql.NullInt64
		if err := tx.QueryRowContext(ctx, `SELECT linked_konsumen_id FROM crm_customers WHERE id=? FOR UPDATE`, customerID).Scan(&linked); err != nil {
			return err
		}
		if linked.Valid {
			return ErrAlreadyLinked
		}
		var others int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM crm_customers WHERE linked_konsumen_id=?`, konsumenID).Scan(&others); err != nil {
			return err
		}
		if others > 0 {
			return ErrAccountAlreadyLinked
		}
		var purpose string
		var chCust, chSubject, chCreator sql.NullInt64
		var verified, consumed, invalidated sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT purpose, crm_customer_id, subject_user_id, created_by, verified_at, consumed_at, invalidated_at
			FROM identity_otp_challenges WHERE id=? FOR UPDATE`, challengeID).
			Scan(&purpose, &chCust, &chSubject, &chCreator, &verified, &consumed, &invalidated); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return ErrChallengeNotUsable
			}
			return err
		}
		usable := purpose == "crm_link" && chCust.Valid && chCust.Int64 == customerID &&
			chSubject.Valid && chSubject.Int64 == konsumenID && chCreator.Valid && chCreator.Int64 == actor.ID &&
			verified.Valid && !consumed.Valid && !invalidated.Valid
		if usable {
			// verified within the 15-minute window (verified_at >= now - 15min)
			usable = time.Now().UTC().Sub(verified.Time) <= linkWindowMin*time.Minute && time.Now().UTC().After(verified.Time)
		}
		if !usable {
			return ErrChallengeNotUsable
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET consumed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id=?`, challengeID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE crm_customers SET linked_konsumen_id = ?, updated_at = UTC_TIMESTAMP(6) WHERE id=?`, konsumenID, customerID); err != nil {
			return err
		}
		_, err := tx.ExecContext(ctx, `INSERT INTO crm_customer_link_events
			(agent_id, crm_customer_id, konsumen_user_id, event_type, actor_id, otp_challenge_id, occurred_at, created_at, updated_at)
			VALUES (?,?,?,?,?,?,?,?,?)`,
			agentID, customerID, konsumenID, "linked", actor.ID, challengeID, time.Now().UTC().Format("2006-01-02 15:04:05"),
			time.Now().UTC().Format("2006-01-02 15:04:05"), time.Now().UTC().Format("2006-01-02 15:04:05"))
		return err
	})
	if err != nil {
		return nil, err
	}
	row, err := s.loadRaw(ctx, customerID)
	return row, err
}

// Unlink clears the binding (OD-04): the owning seller or the branch Agen/Admin. Returns the fresh customer.
func (s *Service) Unlink(ctx context.Context, actor Actor, customerID int64, reason string) (map[string]any, int64, int64, error) {
	var ownerID, agentID int64
	ownerID, agentID, err := s.BindingCustomer(ctx, actor, customerID)
	if err != nil {
		return nil, 0, 0, err
	}
	allowed := canWrite(actor, ownerID, agentID)
	if !allowed && (actor.Role == "agen" || actor.Role == "admin") && actor.AgentID != nil && *actor.AgentID == agentID {
		allowed = true
	}
	if !allowed {
		return nil, 0, 0, ErrCannotWrite
	}
	var konsumenID sql.NullInt64
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if err := tx.QueryRowContext(ctx, `SELECT linked_konsumen_id FROM crm_customers WHERE id=? FOR UPDATE`, customerID).Scan(&konsumenID); err != nil {
			return err
		}
		if !konsumenID.Valid {
			return ErrNotLinked
		}
		if _, err := tx.ExecContext(ctx, `UPDATE crm_customers SET linked_konsumen_id = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id=?`, customerID); err != nil {
			return err
		}
		now := time.Now().UTC().Format("2006-01-02 15:04:05")
		_, err := tx.ExecContext(ctx, `INSERT INTO crm_customer_link_events
			(agent_id, crm_customer_id, konsumen_user_id, event_type, actor_id, reason, occurred_at, created_at, updated_at)
			VALUES (?,?,?,?,?,?,?,?,?)`,
			agentID, customerID, konsumenID, "unlinked", actor.ID, reason, now, now, now)
		return err
	})
	if err != nil {
		return nil, 0, 0, err
	}
	row, err := s.loadRaw(ctx, customerID)
	return row, konsumenID.Int64, s.lastEventID(ctx, customerID), err
}

func (s *Service) lastEventID(ctx context.Context, customerID int64) int64 {
	var id int64
	_ = s.database.QueryRowContext(ctx, `SELECT id FROM crm_customer_link_events WHERE crm_customer_id=? ORDER BY id DESC LIMIT 1`, customerID).Scan(&id)
	return id
}
