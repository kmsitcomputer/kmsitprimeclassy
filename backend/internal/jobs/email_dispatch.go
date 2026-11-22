package jobs

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
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

const RegistrationMailableName = "Illuminate\\Mail\\SendQueuedMailable"
const RegistrationMailableDisplayName = "App\\Mail\\AccountRegisteredMail"

// EnqueueRegistrationEmail writes Laravel's queued AccountRegisteredMail shape. The Go
// notification worker also understands this mailable so the durable queue remains useful
// after Laravel is removed from the runtime.
func EnqueueRegistrationEmail(ctx context.Context, d *db.DB, recipient, name string) error {
	return d.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		command := registrationMailableCommand(recipient, name)
		var uuidBytes [16]byte
		if _, err := rand.Read(uuidBytes[:]); err != nil {
			return err
		}
		uuidBytes[6] = (uuidBytes[6] & 0x0f) | 0x40
		uuidBytes[8] = (uuidBytes[8] & 0x3f) | 0x80
		jobUUID := fmt.Sprintf("%s-%s-%s-%s-%s", hex.EncodeToString(uuidBytes[0:4]), hex.EncodeToString(uuidBytes[4:6]),
			hex.EncodeToString(uuidBytes[6:8]), hex.EncodeToString(uuidBytes[8:10]), hex.EncodeToString(uuidBytes[10:16]))
		payload, err := json.Marshal(map[string]any{
			"uuid": jobUUID, "displayName": RegistrationMailableDisplayName, "job": "Illuminate\\Queue\\CallQueuedHandler@call",
			"maxTries": 3, "maxExceptions": nil, "failOnTimeout": false, "backoff": "30,120", "timeout": nil, "retryUntil": nil,
			"data": map[string]string{"commandName": RegistrationMailableName, "command": command},
		})
		if err != nil {
			return err
		}
		now := time.Now().Unix()
		_, err = tx.ExecContext(ctx, `INSERT INTO jobs (queue,payload,attempts,reserved_at,available_at,created_at)
			VALUES (?, ?, 0, NULL, ?, ?)`, NotificationQueue, string(payload), now, now)
		return err
	})
}

func registrationMailableCommand(recipient, name string) string {
	phpString := func(value string) string { return fmt.Sprintf(`s:%d:"%s";`, len([]byte(value)), value) }
	var fields strings.Builder
	fields.WriteString(`O:34:"Illuminate\Mail\SendQueuedMailable":15:{s:8:"mailable";O:30:"App\Mail\AccountRegisteredMail":33:{`)
	fields.WriteString(`s:6:"locale";N;s:4:"from";a:0:{}s:2:"to";a:1:{i:0;a:2:{s:4:"name";N;s:7:"address";`)
	fields.WriteString(phpString(recipient))
	fields.WriteString(`}}s:2:"cc";a:0:{}s:3:"bcc";a:0:{}s:7:"replyTo";a:0:{}s:7:"subject";N;s:8:"markdown";N;s:7:"`)
	fields.WriteByte(0)
	fields.WriteString(`*`)
	fields.WriteByte(0)
	fields.WriteString(`html";N;s:4:"view";N;s:8:"textView";N;s:8:"viewData";a:0:{}s:11:"attachments";a:0:{}s:14:"rawAttachments";a:0:{}s:15:"diskAttachments";a:0:{}s:7:"`)
	fields.WriteByte(0)
	fields.WriteString(`*`)
	fields.WriteByte(0)
	fields.WriteString(`tags";a:0:{}s:11:"`)
	fields.WriteByte(0)
	fields.WriteString(`*`)
	fields.WriteByte(0)
	fields.WriteString(`metadata";a:0:{}s:9:"callbacks";a:0:{}s:5:"theme";N;s:6:"mailer";s:3:"log";s:29:"`)
	fields.WriteByte(0)
	fields.WriteString(`*`)
	fields.WriteByte(0)
	fields.WriteString(`assertionableRenderStrings";N;s:5:"tries";i:3;s:7:"backoff";a:2:{i:0;i:30;i:1;i:120;}s:4:"name";`)
	fields.WriteString(phpString(name))
	fields.WriteString(`s:10:"connection";N;s:5:"queue";s:18:"primeclassy-notify";s:5:"delay";N;s:11:"afterCommit";N;s:10:"middleware";a:0:{}s:7:"chained";a:0:{}s:15:"chainConnection";N;s:10:"chainQueue";N;s:19:"chainCatchCallbacks";N;}s:5:"tries";i:3;s:7:"timeout";N;s:13:"maxExceptions";N;s:17:"shouldBeEncrypted";b:0;s:10:"connection";N;s:5:"queue";s:18:"primeclassy-notify";s:5:"delay";N;s:11:"afterCommit";N;s:10:"middleware";a:0:{}s:7:"chained";a:0:{}s:15:"chainConnection";N;s:10:"chainQueue";N;s:19:"chainCatchCallbacks";N;s:3:"job";N;}`)
	return fields.String()
}
