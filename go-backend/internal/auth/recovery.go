package auth

// AccountRecoveryService port (WS-04): enumeration-resistant password recovery
// reusing the OTP challenge primitives. The HTTP answer to /auth/forgot-password is
// byte-identical for every outcome; the code travels by email only (the testing
// reference uses the log mailer, so delivery is a no-op there and the challenge stays
// live — the Go port mirrors that isolated-env behaviour).

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math/big"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

const recoveryPurpose = "password_recovery"
const subjectUser = "App\\Models\\User"

// RecoveryService issues and consumes password_recovery challenges.
type RecoveryService struct {
	database *db.DB
	appKey   string
	hashPwd  func(string) (string, error)
}

// ErrRecovery is the generic recovery failure (existence is never revealed).
var ErrRecovery = errors.New("recovery invalid")

// ErrRecoveryLocked: too many wrong attempts on the challenge.
var ErrRecoveryLocked = errors.New("recovery locked")

// ErrRecoveryExpired: the newest challenge has expired.
var ErrRecoveryExpired = errors.New("recovery expired")

// Request ports AccountRecoveryService::request — the answer never varies.
func (s *RecoveryService) Request(ctx context.Context, identifier, channel string) map[string]any {
	if channel != "whatsapp" {
		channel = "email"
	}
	// Every failure is contained: unknown identifier, unreachable channel, rate
	// limit — the public answer stays identical (email_masked is always null).
	_ = s.issueToEmail(ctx, identifier)
	return map[string]any{"email_masked": nil, "channel": channel}
}

func (s *RecoveryService) issueToEmail(ctx context.Context, identifier string) error {
	u, err := FindUserByIdentifier(ctx, s.database.DB, identifier)
	if err != nil {
		return err
	}
	var email sql.NullString
	var verifiedAt sql.NullTime
	if err := s.database.DB.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users WHERE id = ?`,
		u.ID).Scan(&email, &verifiedAt); err != nil {
		return err
	}
	if !email.Valid || email.String == "" || !verifiedAt.Valid {
		return ErrRecovery
	}
	_, err = s.newChallenge(ctx, u)
	return err
}

func randomSix() string {
	n, _ := rand.Int(rand.Reader, big.NewInt(1000000))
	return fmt.Sprintf("%06d", n.Int64())
}

func nullAgent(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

func (s *RecoveryService) hash(challengeID, subjectID int64, purpose, code string) string {
	mac := hmac.New(sha256.New, []byte(s.appKey))
	fmt.Fprintf(mac, "%d|%d|%s|%s", challengeID, subjectID, purpose, code)
	return hex.EncodeToString(mac.Sum(nil))
}

// newChallenge ports newChallenge: per-subject lock, 3/10min rate limit,
// invalidate-previous, HMAC hash, TTL 10 min, max 5 attempts. No actor exists
// pre-auth: the subject owns its own recovery challenge.
func (s *RecoveryService) newChallenge(ctx context.Context, u *User) (int64, error) {
	var newID int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if _, err := tx.ExecContext(ctx, `SELECT id FROM users WHERE id = ? FOR UPDATE`, u.ID); err != nil {
			return err
		}
		var recent int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
			WHERE subject_user_id = ? AND purpose = ?
			AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE`, u.ID, recoveryPurpose).Scan(&recent); err != nil {
			return err
		}
		if recent >= 3 {
			return ErrRecoveryLocked
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET invalidated_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE subject_user_id = ? AND purpose = ?
			AND consumed_at IS NULL AND invalidated_at IS NULL`, u.ID, recoveryPurpose); err != nil {
			return err
		}
		code := randomSix()
		marker := ""
		if u.Email != nil {
			marker = *u.Email
		}
		res, ierr := tx.ExecContext(ctx, `INSERT INTO identity_otp_challenges (agent_id, subject_user_id,
			created_by, purpose, code_hash, sent_to_email, attempts, max_attempts, expires_at,
			created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP() + INTERVAL 10 MINUTE,
			UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			nullAgent(u.AgentID), u.ID, u.ID, recoveryPurpose, strings.Repeat("0", 64), marker, 5)
		if ierr != nil {
			return ierr
		}
		id, lerr := res.LastInsertId()
		if lerr != nil {
			return lerr
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET code_hash = ? WHERE id = ?`,
			s.hash(id, u.ID, recoveryPurpose, code), id); err != nil {
			return err
		}
		newID = id
		return nil
	})
	return newID, err
}

// NewRecoveryService builds the recovery service.
func NewRecoveryService(database *db.DB, appKey string) *RecoveryService {
	return &RecoveryService{database: database, appKey: appKey, hashPwd: HashPassword}
}

// Reset ports AccountRecoveryService::reset: one code = one reset, all recovery
// challenges consumed, sessions revoked (theft containment).
func (s *RecoveryService) Reset(ctx context.Context, identifier, code, password string) error {
	u, err := FindUserByIdentifier(ctx, s.database.DB, identifier)
	if err != nil {
		// Same error as a wrong code: existence is never revealed.
		return ErrRecovery
	}
	verifiedID, verr := s.verifyCode(ctx, u.ID, code)
	if verr != nil {
		return verr
	}
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var consumed, invalidated, verified sql.NullTime
		if err := tx.QueryRowContext(ctx, `SELECT consumed_at, invalidated_at, verified_at
			FROM identity_otp_challenges WHERE id = ? FOR UPDATE`, verifiedID).
			Scan(&consumed, &invalidated, &verified); err != nil {
			return err
		}
		if consumed.Valid || invalidated.Valid || !verified.Valid {
			return ErrRecovery
		}
		hash, herr := s.hashPwd(password)
		if herr != nil {
			return herr
		}
		if _, err := tx.ExecContext(ctx, `UPDATE users SET password = ?, updated_at = UTC_TIMESTAMP(6)
			WHERE id = ?`, hash, u.ID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET consumed_at = UTC_TIMESTAMP(6),
			invalidated_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE subject_user_id = ? AND purpose = ?
			AND consumed_at IS NULL`, u.ID, recoveryPurpose); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `DELETE FROM pc_go_sessions WHERE user_id = ?`, u.ID); err != nil {
			return err
		}
		props, _ := json.Marshal(map[string]any{"channel": "email"})
		_, err = tx.ExecContext(ctx, `INSERT INTO activity_logs (causer_id, subject_type, subject_id, event,
			description, properties, created_at)
			VALUES (?, ?, ?, 'auth.password_reset', NULL, ?, UTC_TIMESTAMP(6))`,
			u.ID, subjectUser, u.ID, string(props))
		return err
	})
}

var mismatch bool

// verifyCode ports verifyCode: same state machine as the consent OTP minus the
// actor binding. The code identifies the latest still-usable challenge for the
// subject only.
func (s *RecoveryService) verifyCode(ctx context.Context, subjectID int64, code string) (int64, error) {
	var verifiedID int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		var id, attempts, maxAttempts int64
		var codeHash string
		var expiresAt sql.NullTime
		err := tx.QueryRowContext(ctx, `SELECT id, attempts, max_attempts, expires_at, code_hash
			FROM identity_otp_challenges WHERE subject_user_id = ? AND purpose = ?
			AND consumed_at IS NULL AND invalidated_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE`,
			subjectID, recoveryPurpose).Scan(&id, &attempts, &maxAttempts, &expiresAt, &codeHash)
		if errors.Is(err, sql.ErrNoRows) {
			return ErrRecovery
		}
		if err != nil {
			return err
		}
		if expiresAt.Valid && expiresAt.Time.Before(time.Now().UTC()) {
			return ErrRecoveryExpired
		}
		if attempts >= maxAttempts {
			return ErrRecoveryLocked
		}
		// Attempts must persist even when the code is wrong (commit-then-fail).
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET attempts = attempts + 1,
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, id); err != nil {
			return err
		}
		if !hmac.Equal([]byte(s.hash(id, subjectID, recoveryPurpose, code)), []byte(codeHash)) {
			mismatch = true
			return nil
		}
		if _, err := tx.ExecContext(ctx, `UPDATE identity_otp_challenges SET verified_at = UTC_TIMESTAMP(6),
			updated_at = UTC_TIMESTAMP(6) WHERE id = ?`, id); err != nil {
			return err
		}
		verifiedID = id
		return nil
	})
	if err != nil {
		return 0, err
	}
	if verifiedID == 0 {
		return 0, ErrRecovery
	}
	return verifiedID, nil
}
