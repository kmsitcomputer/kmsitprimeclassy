// Package consent ports the REM-005 consent evidence domain (ConsentService +
// OtpChallengeService + ConsentAccess) for the /consent and /consent-events routes.
package consent

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"math/big"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

const (
	maxAttempts     = 5
	issueLimit      = 3
	policyVersion   = "consent-v1"
	PurposeAssisted = "assisted_return"
)

// Actor is the authenticated caller.
type Actor struct {
	ID      int64
	Role    string
	AgentID int64
	IsSuper bool
}

// Subject is the konsumen the consent is about.
type Subject struct {
	ID              int64
	Role            string
	AgentID         int64
	SalesID         sql.NullInt64
	KorsalID        sql.NullInt64
	Email           sql.NullString
	EmailVerifiedAt sql.NullTime
}

type DomainError struct {
	Status  int
	Message string
}

func (e *DomainError) Error() string { return e.Message }

func errStr(status int, message string) error { return &DomainError{Status: status, Message: message} }

// Service is the consent domain service.
type Service struct {
	database *db.DB
	appKey   string
}

// New builds a consent service.
func New(database *db.DB, appKey string) *Service {
	return &Service{database: database, appKey: appKey}
}

func (s *Service) loadSubjectTx(ctx context.Context, tx *sql.Tx, id int64) (*Subject, error) {
	var sub Subject
	err := tx.QueryRowContext(ctx, `SELECT u.id, r.slug, u.agent_id, u.sales_id, u.korsal_id,
		u.email, u.email_verified_at FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, id).
		Scan(&sub.ID, &sub.Role, &sub.AgentID, &sub.SalesID, &sub.KorsalID, &sub.Email, &sub.EmailVerifiedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, errStr(404, "")
	}
	if err != nil {
		return nil, err
	}
	return &sub, nil
}

// canCapture ports ConsentAccess::canCapture.
func (s *Service) canCapture(a Actor, sub *Subject) bool {
	if sub.Role != "konsumen" || sub.AgentID == 0 || a.AgentID != sub.AgentID {
		return false
	}
	switch a.Role {
	case "sales", "sales-kurir-sub":
		return sub.SalesID.Valid && sub.SalesID.Int64 == a.ID
	case "korsal":
		if sub.SalesID.Valid && sub.SalesID.Int64 == a.ID {
			return true
		}
		return !sub.SalesID.Valid && sub.KorsalID.Valid && sub.KorsalID.Int64 == a.ID
	case "agen":
		return true
	}
	return false
}

// canReview ports ConsentAccess::canReview.
func canReview(a Actor, sub *Subject) bool {
	if a.IsSuper {
		return true
	}
	return (a.Role == "agen" || a.Role == "admin") && sub.AgentID != 0 && a.AgentID == sub.AgentID
}

// canWithdrawOrDispute ports ConsentAccess::canWithdrawOrDispute.
func (s *Service) canWithdrawOrDispute(a Actor, sub *Subject) bool {
	return a.ID == sub.ID || s.canCapture(a, sub) || canReview(a, sub)
}

func (s *Service) hash(challengeID, subjectID int64, purpose, code string) string {
	mac := hmac.New(sha256.New, []byte(s.appKey))
	fmt.Fprintf(mac, "%d|%d|%s|%s", challengeID, subjectID, purpose, code)
	return hex.EncodeToString(mac.Sum(nil))
}

// IssueOtp ports OtpChallengeService::issue for the email channel. The code is
// delivered by email only — never in the API response (the mail side effect is
// recorded by the notification pipeline; no code is returned here, exactly like Laravel).
func (s *Service) IssueOtp(ctx context.Context, a Actor, subjectID int64, purpose string) (map[string]any, error) {
	var challengeID int64
	var expiresAt sql.NullTime
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		sub, err := s.loadSubjectTx(ctx, tx, subjectID)
		if err != nil {
			return err
		}
		if !s.canCapture(a, sub) {
			return errStr(403, "Anda tidak memiliki akses untuk melakukan aksi ini.")
		}
		if !sub.Email.Valid || sub.Email.String == "" || !sub.EmailVerifiedAt.Valid {
			return errStr(422, "Pelanggan ini wajib memiliki email terverifikasi.")
		}
		// Serialize issuance per subject so the rate limit cannot be raced.
		if _, err := tx.ExecContext(ctx, `SELECT id FROM users WHERE id = ? FOR UPDATE`, subjectID); err != nil {
			return err
		}
		var recent int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
			WHERE subject_user_id = ? AND purpose = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE`,
			subjectID, purpose).Scan(&recent); err != nil {
			return err
		}
		if recent >= issueLimit {
			return errStr(429, "Terlalu banyak permintaan kode. Coba lagi nanti.")
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET invalidated_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE subject_user_id = ? AND purpose = ?
			AND consumed_at IS NULL AND invalidated_at IS NULL`, subjectID, purpose); err != nil {
			return err
		}
		code := randomCode()
		res, ierr := tx.ExecContext(ctx, `INSERT INTO identity_otp_challenges (agent_id, subject_user_id,
			created_by, purpose, code_hash, sent_to_email, attempts, max_attempts, expires_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			nullI(sub.AgentID), subjectID, a.ID, purpose, strings.Repeat("0", 64), sub.Email.String, maxAttempts)
		if ierr != nil {
			return ierr
		}
		newID, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET code_hash = ? WHERE id = ?`,
			s.hash(newID, subjectID, purpose, code), newID); err != nil {
			return err
		}
		challengeID = newID
		return tx.QueryRowContext(ctx, `SELECT expires_at FROM identity_otp_challenges WHERE id = ?`, newID).
			Scan(&expiresAt)
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"challenge_id": challengeID,
		"expires_at": formatTime(expiresAt), "max_attempts": maxAttempts}, nil
}

func randomCode() string {
	n, _ := rand.Int(rand.Reader, big.NewInt(1000000))
	return fmt.Sprintf("%06d", n.Int64())
}

func nullI(v int64) any {
	if v == 0 {
		return nil
	}
	return v
}

func formatTime(t sql.NullTime) any {
	if !t.Valid {
		return nil
	}
	return t.Time.UTC().Format("2006-01-02T15:04:05.000000Z")
}

// VerifyOtp ports OtpChallengeService::verify (attempt counter persists even on mismatch).
func (s *Service) VerifyOtp(ctx context.Context, a Actor, challengeID int64, code string) (map[string]any, error) {
	var verifiedAt sql.NullTime
	mismatch := false
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var createdBy, subjectID int64
		var purpose, codeHash, sentTo string
		var attempts, maxAtt int64
		var consumedAt, invalidatedAt, verified sql.NullTime
		var expiresAt sql.NullTime
		err := tx.QueryRowContext(ctx, `SELECT created_by, subject_user_id, purpose, sent_to_email,
			attempts, max_attempts, consumed_at, invalidated_at, verified_at, expires_at, code_hash
			FROM identity_otp_challenges WHERE id = ? FOR UPDATE`, challengeID).
			Scan(&createdBy, &subjectID, &purpose, &sentTo, &attempts, &maxAtt, &consumedAt, &invalidatedAt,
				&verified, &expiresAt, &codeHash)
		if errors.Is(err, sql.ErrNoRows) {
			return errStr(404, "")
		}
		if err != nil {
			return err
		}
		if createdBy != a.ID {
			return errStr(403, "Anda tidak memiliki akses untuk melakukan aksi ini.")
		}
		if consumedAt.Valid || invalidatedAt.Valid || verified.Valid {
			return errStr(422, "Kode tidak valid.")
		}
		if expiresAt.Valid && expiresAt.Time.Before(time.Now().UTC()) {
			return errStr(422, "Kode kedaluwarsa. Minta kode baru.")
		}
		if attempts >= maxAtt {
			return errStr(429, "Terlalu banyak percobaan salah. Minta kode baru.")
		}
		// Attempts must persist even when the code is wrong (Laravel throws after commit).
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET attempts = attempts + 1,
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, challengeID); err != nil {
			return err
		}
		if !hmac.Equal([]byte(s.hash(challengeID, subjectID, purpose, code)), []byte(codeHash)) {
			mismatch = true
			return nil // commit the attempt counter
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET verified_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, challengeID); err != nil {
			return err
		}
		return tx.QueryRowContext(ctx, `SELECT verified_at FROM identity_otp_challenges WHERE id = ?`,
			challengeID).Scan(&verifiedAt)
	})
	if err != nil {
		return nil, err
	}
	if mismatch {
		return nil, errStr(422, "Kode tidak valid.")
	}
	return map[string]any{"challenge_id": challengeID, "verified_at": formatTime(verifiedAt)}, nil
}
