package auth

// EmailVerificationService port (WS-04): self-service email verification for the
// signed-in holder only, reusing the identity_otp_challenges infra with the
// `email_verification` purpose.

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

const emailVerifyPurpose = "email_verification"

// EmailVerifyService issues and consumes email_verification challenges.
type EmailVerifyService struct {
	database *db.DB
	appKey   string
}

// NewEmailVerifyService builds the service.
func NewEmailVerifyService(database *db.DB, appKey string) *EmailVerifyService {
	return &EmailVerifyService{database: database, appKey: appKey}
}

// State ports EmailVerificationService::state.
func (s *EmailVerifyService) State(ctx context.Context, userID int64) (map[string]any, error) {
	var email sql.NullString
	var verifiedAt sql.NullTime
	if err := s.database.DB.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users
		WHERE id = ?`, userID).Scan(&email, &verifiedAt); err != nil {
		return nil, err
	}
	var pending bool
	if err := s.database.DB.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM identity_otp_challenges
		WHERE subject_user_id = ? AND purpose = ? AND consumed_at IS NULL AND invalidated_at IS NULL
		AND expires_at > UTC_TIMESTAMP(6))`, userID, emailVerifyPurpose).Scan(&pending); err != nil {
		return nil, err
	}
	masked := any(nil)
	verified := false
	if email.Valid && email.String != "" {
		masked = maskEmail(email.String)
		verified = verifiedAt.Valid
	}
	return map[string]any{"email_masked": masked, "verified": verified, "pending": pending}, nil
}

func maskEmail(email string) string {
	parts := strings.SplitN(email, "@", 2)
	local := parts[0]
	domain := ""
	if len(parts) > 1 {
		domain = parts[1]
	}
	head := local
	if len(head) > 2 {
		head = head[:2]
	}
	return head + "••••@" + domain
}

// otpHash is the shared HMAC hash over challenge|subject|purpose|code.
func otpHash(appKey string, challengeID, subjectID int64, purpose, code string) string {
	mac := hmac.New(sha256.New, []byte(appKey))
	fmt.Fprintf(mac, "%d|%d|%s|%s", challengeID, subjectID, purpose, code)
	return hex.EncodeToString(mac.Sum(nil))
}

// ErrEmailStale: the account address changed between issue and confirm.
var ErrEmailStale = errors.New("email stale")

// ErrChallengeNotUsable: consumed/invalidated/unverified challenge.
var ErrChallengeNotUsable = errors.New("challenge not usable")

// Request ports EmailVerificationService::request (challenge to the CURRENT
// address; nil map when already verified).
func (s *EmailVerifyService) Request(ctx context.Context, userID int64) (map[string]any, error) {
	var email sql.NullString
	var verifiedAt sql.NullTime
	if err := s.database.DB.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users
		WHERE id = ?`, userID).Scan(&email, &verifiedAt); err != nil {
		return nil, err
	}
	if !email.Valid || email.String == "" {
		return nil, errors.New("Akun ini belum memiliki email. Tambahkan email di profil terlebih dahulu.")
	}
	if verifiedAt.Valid {
		return nil, nil
	}
	var out map[string]any
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, `SELECT id FROM users WHERE id = ? FOR UPDATE`, userID); err != nil {
			return err
		}
		var recent int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
			WHERE subject_user_id = ? AND purpose = ?
			AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE`, userID, emailVerifyPurpose).Scan(&recent); err != nil {
			return err
		}
		if recent >= 3 {
			return ErrRecoveryLocked // 429 semantics: rate limited
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET invalidated_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE subject_user_id = ? AND purpose = ?
			AND consumed_at IS NULL AND invalidated_at IS NULL`, userID, emailVerifyPurpose); err != nil {
			return err
		}
		code := randomSix()
		res, ierr := tx.ExecContext(ctx, `INSERT INTO identity_otp_challenges (agent_id, subject_user_id,
			created_by, purpose, code_hash, sent_to_email, attempts, max_attempts, expires_at,
			created_at, updated_at)
			VALUES ((SELECT agent_id FROM users WHERE id = ?), ?, ?, ?, ?, ?, 0, 5,
			UTC_TIMESTAMP() + INTERVAL 10 MINUTE, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			userID, userID, userID, emailVerifyPurpose, strings.Repeat("0", 64), email.String)
		if ierr != nil {
			return ierr
		}
		id, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET code_hash = ? WHERE id = ?`,
			otpHash(s.appKey, id, userID, emailVerifyPurpose, code), id); err != nil {
			return err
		}
		var expiresAt sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT expires_at FROM identity_otp_challenges WHERE id = ?`,
			id).Scan(&expiresAt); err != nil {
			return err
		}
		out = map[string]any{"challenge_id": id,
			"expires_at":   expiresAt.Time.UTC().Format("2006-01-02T15:04:05.000000Z"),
			"max_attempts": 5}
		return nil
	})
	if err != nil {
		return nil, err
	}
	return out, nil
}

// Confirm ports EmailVerificationService::confirm (subject-bound challenge, shared
// verifier semantics, stale-address guard, single consume, audit).
func (s *EmailVerifyService) Confirm(ctx context.Context, userID, challengeID int64, code string) (map[string]any, error) {
	var chSubject, chCreator, attempts, maxAttempts int64
	var codeHash string
	var consumed, invalidated, verified, expiresAt sql.NullTime
	err := s.database.DB.QueryRowContext(ctx, `SELECT subject_user_id, created_by, code_hash,
		attempts, max_attempts, consumed_at, invalidated_at, verified_at, expires_at
		FROM identity_otp_challenges WHERE id = ? AND subject_user_id = ? AND purpose = ?`,
		challengeID, userID, emailVerifyPurpose).
		Scan(&chSubject, &chCreator, &codeHash, &attempts, &maxAttempts, &consumed, &invalidated,
			&verified, &expiresAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrRecovery
	}
	if err != nil {
		return nil, err
	}
	if chCreator != userID {
		return nil, errors.New("Anda tidak memiliki akses untuk melakukan aksi ini.")
	}
	if err := s.verifyChallenge(ctx, challengeID, userID, emailVerifyPurpose, code, codeHash,
		attempts, maxAttempts, consumed, invalidated, verified, expiresAt); err != nil {
		return nil, err
	}
	err = s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var c2, i2, v2 sql.NullTime
		var sentTo string
		if err := tx.QueryRowContext(ctx, `SELECT consumed_at, invalidated_at, verified_at, sent_to_email
			FROM identity_otp_challenges WHERE id = ? FOR UPDATE`, challengeID).
			Scan(&c2, &i2, &v2, &sentTo); err != nil {
			return err
		}
		if c2.Valid || i2.Valid || !v2.Valid {
			return ErrChallengeNotUsable
		}
		var email sql.NullString
		var verifiedAt sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users WHERE id = ? FOR UPDATE`,
			userID).Scan(&email, &verifiedAt); err != nil {
			return err
		}
		if !email.Valid || email.String != sentTo {
			return ErrEmailStale
		}
		if verifiedAt.Valid {
			return errors.New("Email sudah terverifikasi.")
		}
		if _, err := tx.ExecContext(ctx, `UPDATE users SET email_verified_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, userID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET consumed_at = UTC_TIMESTAMP(6),
			invalidated_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, challengeID); err != nil {
			return err
		}
		props, _ := json.Marshal(map[string]any{"email": maskEmail(email.String)})
		_, aerr := tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, ?, ?, 'auth.email_verified', NULL, ?, UTC_TIMESTAMP(6))`,
			userID, subjectUser, userID, string(props))
		return aerr
	})
	if err != nil {
		return nil, err
	}
	return s.State(ctx, userID)
}

// verifyChallenge runs the shared OtpChallengeService::verify state machine.
func (s *EmailVerifyService) verifyChallenge(ctx context.Context, challengeID, subjectID int64,
	purpose, code, codeHash string, attempts, maxAttempts int64, consumed, invalidated,
	verified, expiresAt sql.NullTime) error {
	if consumed.Valid || invalidated.Valid || verified.Valid {
		return ErrRecovery
	}
	if expiresAt.Valid && expiresAt.Time.Before(time.Now().UTC()) {
		return errors.New("expired")
	}
	if attempts >= maxAttempts {
		return ErrRecoveryLocked
	}
	mismatch := false
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET attempts = attempts + 1,
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, challengeID); err != nil {
			return err
		}
		if !hmac.Equal([]byte(otpHash(s.appKey, challengeID, subjectID, purpose, code)), []byte(codeHash)) {
			mismatch = true
			return nil
		}
		_, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET verified_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, challengeID)
		return err
	})
	if err != nil {
		return err
	}
	if mismatch {
		return ErrRecovery
	}
	return nil
}
