package jobs

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

const WhatsAppJobName = "App\\Jobs\\SendWhatsAppMessageJob"

// EnqueueWhatsApp writes Laravel-compatible database queue payload for the shared notify worker.
// The job carries only the durable ledger ID; recipient/body/credentials stay out of queue payloads.
func EnqueueWhatsApp(ctx context.Context, database *db.DB, messageID int64) error {
	return EnqueueWhatsAppAt(ctx, database, messageID, time.Now())
}

func EnqueueWhatsAppAt(ctx context.Context, database *db.DB, messageID int64, availableAt time.Time) error {
	var uuidBytes [16]byte
	if _, err := rand.Read(uuidBytes[:]); err != nil {
		return err
	}
	uuidBytes[6] = (uuidBytes[6] & 0x0f) | 0x40
	uuidBytes[8] = (uuidBytes[8] & 0x3f) | 0x80
	jobUUID := fmt.Sprintf("%s-%s-%s-%s-%s", hex.EncodeToString(uuidBytes[0:4]), hex.EncodeToString(uuidBytes[4:6]), hex.EncodeToString(uuidBytes[6:8]), hex.EncodeToString(uuidBytes[8:10]), hex.EncodeToString(uuidBytes[10:16]))
	serialized := fmt.Sprintf(`O:31:"App\Jobs\SendWhatsAppMessageJob":2:{s:9:"messageId";i:%d;s:5:"queue";s:18:"primeclassy-notify";}`, messageID)
	payload, err := json.Marshal(map[string]any{
		"uuid": jobUUID, "displayName": WhatsAppJobName, "job": "Illuminate\\Queue\\CallQueuedHandler@call",
		"maxTries": 1, "maxExceptions": nil, "failOnTimeout": false, "backoff": nil, "timeout": 60, "retryUntil": nil,
		"data": map[string]string{"commandName": WhatsAppJobName, "command": serialized},
	})
	if err != nil {
		return err
	}
	now := time.Now().Unix()
	_, err = database.ExecContext(ctx, `INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at) VALUES (?,?,0,NULL,?,?)`, NotificationQueue, string(payload), availableAt.Unix(), now)
	return err
}
