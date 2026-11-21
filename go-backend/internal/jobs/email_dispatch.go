package jobs

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"time"
)

const EmailJobName = "App\\Jobs\\SendTransactionalEmailJob"

// EnqueueEmailTx writes a Laravel-compatible SendTransactionalEmailJob queue row
// in the caller's transaction ($tries = 1: a failure is recorded on the ledger row,
// never silently re-sent).
func EnqueueEmailTx(ctx context.Context, tx *sql.Tx, messageID int64) error {
	var uuidBytes [16]byte
	if _, err := rand.Read(uuidBytes[:]); err != nil {
		return err
	}
	uuidBytes[6] = (uuidBytes[6] & 0x0f) | 0x40
	uuidBytes[8] = (uuidBytes[8] & 0x3f) | 0x80
	jobUUID := fmt.Sprintf("%s-%s-%s-%s-%s", hex.EncodeToString(uuidBytes[0:4]), hex.EncodeToString(uuidBytes[4:6]),
		hex.EncodeToString(uuidBytes[6:8]), hex.EncodeToString(uuidBytes[8:10]), hex.EncodeToString(uuidBytes[10:16]))
	serialized := fmt.Sprintf(`O:34:"App\Jobs\SendTransactionalEmailJob":2:{s:9:"messageId";i:%d;s:5:"queue";s:18:"primeclassy-notify";}`, messageID)
	payload, err := json.Marshal(map[string]any{
		"uuid": jobUUID, "displayName": EmailJobName, "job": "Illuminate\\Queue\\CallQueuedHandler@call",
		"maxTries": 1, "maxExceptions": nil, "failOnTimeout": false, "backoff": nil, "timeout": 60, "retryUntil": nil,
		"data": map[string]string{"commandName": EmailJobName, "command": serialized},
	})
	if err != nil {
		return err
	}
	now := time.Now().Unix()
	_, err = tx.ExecContext(ctx, `INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at)
		VALUES (?, ?, 0, NULL, ?, ?)`, NotificationQueue, string(payload), now, now)
	return err
}
