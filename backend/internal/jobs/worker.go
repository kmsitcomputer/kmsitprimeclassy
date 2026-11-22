package jobs

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
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

type RegistrationEmailHandler interface {
	HandleRegistrationEmail(context.Context, string, string) error
}

type Payload struct {
	UUID        string          `json:"uuid"`
	DisplayName string          `json:"displayName"`
	Job         string          `json:"job"`
	MaxTries    int             `json:"maxTries"`
	Backoff     json.RawMessage `json:"backoff"`
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

	var payload Payload
	if err := json.Unmarshal([]byte(j.Payload), &payload); err != nil {
		cause := errors.New("unable to decode Laravel queued job payload")
		if err := FailClaimed(ctx, d, j, cause); err != nil {
			return true, err
		}
		return true, cause
	}
	name := payload.Data.CommandName
	if name == "" {
		name = payload.DisplayName
	}
	maxTries := payload.MaxTries
	if maxTries < 1 {
		maxTries = 1
	}
	if j.Attempts > maxTries {
		cause := fmt.Errorf("Laravel queued job exceeded its maximum attempt count of %d", maxTries)
		if err := FailClaimed(ctx, d, j, cause); err != nil {
			return true, err
		}
		return true, cause
	}
	if name == RegistrationMailableName && bytes.Contains([]byte(payload.Data.Command), []byte(`O:30:"App\Mail\AccountRegisteredMail":33:{`)) {
		recipient, recipientOK := phpSerializedString(payload.Data.Command, "address")
		accountName, nameOK := phpSerializedString(payload.Data.Command, "name")
		handler, handlerOK := h.(RegistrationEmailHandler)
		if !recipientOK || !nameOK || !handlerOK {
			err = errors.New("unsupported or malformed AccountRegisteredMail job")
		} else {
			err = handler.HandleRegistrationEmail(ctx, recipient, accountName)
		}
	} else {
		id, messageName, ok := MessageID([]byte(j.Payload))
		if !ok {
			err = errors.New("unsupported Laravel queued job: " + name)
		} else {
			switch messageName {
			case "App\\Jobs\\SendWhatsAppMessageJob":
				err = h.HandleWhatsApp(ctx, id)
			case "App\\Jobs\\SendTransactionalEmailJob":
				err = h.HandleEmail(ctx, id)
			default:
				err = errors.New("unsupported Laravel queued job: " + name)
			}
		}
	}
	if err != nil && j.Attempts < maxTries {
		seconds := backoffSeconds(payload.Backoff, j.Attempts)
		if releaseErr := Release(ctx, d, j.ID, time.Now().Add(time.Duration(seconds)*time.Second)); releaseErr != nil {
			return true, releaseErr
		}
		return true, err
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

func backoffSeconds(raw json.RawMessage, attempt int) int {
	var values []int
	if err := json.Unmarshal(raw, &values); err != nil {
		var commaSeparated string
		if json.Unmarshal(raw, &commaSeparated) == nil {
			for _, field := range strings.Split(commaSeparated, ",") {
				value, err := strconv.Atoi(strings.TrimSpace(field))
				if err != nil {
					return 0
				}
				values = append(values, value)
			}
		}
	}
	if len(values) == 0 {
		return 0
	}
	index := attempt - 1
	if index >= len(values) {
		index = len(values) - 1
	}
	if index < 0 {
		return 0
	}
	return values[index]
}

func phpSerializedString(serialized, property string) (string, bool) {
	needle := []byte(fmt.Sprintf("s:%d:\"%s\";s:", len([]byte(property)), property))
	data := []byte(serialized)
	start := bytes.Index(data, needle)
	if start < 0 {
		return "", false
	}
	start += len(needle)
	colon := bytes.IndexByte(data[start:], ':')
	if colon < 1 {
		return "", false
	}
	length, err := strconv.Atoi(string(data[start : start+colon]))
	if err != nil || length < 0 {
		return "", false
	}
	valueStart := start + colon + 1
	if valueStart >= len(data) || data[valueStart] != '"' {
		return "", false
	}
	valueStart++
	valueEnd := valueStart + length
	if valueEnd+1 >= len(data) || data[valueEnd] != '"' || data[valueEnd+1] != ';' {
		return "", false
	}
	return string(data[valueStart:valueEnd]), true
}
