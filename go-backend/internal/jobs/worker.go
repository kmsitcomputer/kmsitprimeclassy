package jobs

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

const NotificationQueue = "primeclassy-notify"
const databaseQueueRetryAfter = 90 // Laravel config/queue.php database.retry_after default.

var ErrNoJob = errors.New("no queued job")

type Job struct {
	ID       int64
	Queue    string
	Payload  string
	Attempts int
}

type Handler interface {
	HandleWhatsApp(context.Context, int64) error
	HandleEmail(context.Context, int64) error
}

type Payload struct {
	UUID        string `json:"uuid"`
	DisplayName string `json:"displayName"`
	Job         string `json:"job"`
	Data        struct {
		CommandName string `json:"commandName"`
		Command     string `json:"command"`
	} `json:"data"`
}

var messageIDPattern = regexp.MustCompile(`s:\d+:["]messageId["];(?:i:(-?\d+);|s:\d+:["](-?\d+)["];)`)

func MessageID(payload []byte) (int64, string, bool) {
	var p Payload
	if err := json.Unmarshal(payload, &p); err != nil {
		return 0, "", false
	}
	name := p.Data.CommandName
	if name == "" {
		name = p.DisplayName
	}
	m := messageIDPattern.FindSubmatch([]byte(p.Data.Command))
	if len(m) == 0 {
		return 0, name, false
	}
	value := string(m[1])
	if value == "" {
		value = string(m[2])
	}
	id, err := strconv.ParseInt(value, 10, 64)
	return id, name, err == nil
}

func ClaimNext(ctx context.Context, d *db.DB) (*Job, error) {
	now := time.Now().Unix()
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	var j Job
	err = tx.QueryRowContext(ctx, `SELECT id, queue, payload, attempts
		FROM jobs
		WHERE queue = ? AND available_at <= ?
		  AND (reserved_at IS NULL OR reserved_at <= ?)
		ORDER BY id LIMIT 1 FOR UPDATE`, NotificationQueue, now, now-databaseQueueRetryAfter).Scan(
		&j.ID, &j.Queue, &j.Payload, &j.Attempts)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNoJob
	}
	if err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx,
		"UPDATE jobs SET attempts = attempts + 1, reserved_at = ? WHERE id = ?",
		now, j.ID); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	j.Attempts++
	return &j, nil
}

func DeleteClaimed(ctx context.Context, d *db.DB, id int64) error {
	_, err := d.ExecContext(ctx, "DELETE FROM jobs WHERE id = ?", id)
	return err
}

func Release(ctx context.Context, d *db.DB, id int64, availableAt time.Time) error {
	_, err := d.ExecContext(ctx,
		"UPDATE jobs SET reserved_at = NULL, available_at = ? WHERE id = ?",
		availableAt.Unix(), id)
	return err
}

func FailClaimed(ctx context.Context, d *db.DB, job *Job, cause error) error {
	var payload Payload
	if err := json.Unmarshal([]byte(job.Payload), &payload); err != nil || payload.UUID == "" {
		return errors.New("cannot record failed Laravel job without a valid payload UUID")
	}
	tx, err := d.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `DELETE FROM jobs WHERE id=?`, job.ID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO failed_jobs (uuid,connection,queue,payload,exception,failed_at)
		VALUES (?, 'database', ?, ?, ?, UTC_TIMESTAMP(6))`, payload.UUID, job.Queue, job.Payload, cause.Error()); err != nil {
		return err
	}
	return tx.Commit()
}

func Process(ctx context.Context, d *db.DB, h Handler) (bool, error) {
	j, err := ClaimNext(ctx, d)
	if errors.Is(err, ErrNoJob) {
		return false, nil
	}
	if err != nil {
		return false, err
	}

	id, name, ok := MessageID([]byte(j.Payload))
	if !ok {
		cause := errors.New("unable to decode Laravel queued job payload")
		if err := FailClaimed(ctx, d, j, cause); err != nil {
			return true, err
		}
		return true, cause
	}
	if j.Attempts > 1 && (name == "App\\Jobs\\SendWhatsAppMessageJob" || name == "App\\Jobs\\SendTransactionalEmailJob") {
		cause := errors.New("Laravel notification job exceeded its maximum attempt count of one")
		if err := FailClaimed(ctx, d, j, cause); err != nil {
			return true, err
		}
		return true, cause
	}
	switch name {
	case "App\\Jobs\\SendWhatsAppMessageJob":
		err = h.HandleWhatsApp(ctx, id)
	case "App\\Jobs\\SendTransactionalEmailJob":
		err = h.HandleEmail(ctx, id)
	default:
		cause := errors.New("unsupported Laravel queued job: " + name)
		if err := FailClaimed(ctx, d, j, cause); err != nil {
			return true, err
		}
		return true, cause
	}
	if err != nil {
		if failErr := FailClaimed(ctx, d, j, err); failErr != nil {
			return true, failErr
		}
		return true, err
	}
	if err := DeleteClaimed(ctx, d, j.ID); err != nil {
		return true, err
	}
	return true, nil
}
