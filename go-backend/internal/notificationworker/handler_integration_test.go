package notificationworker

import (
	"bufio"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
)

func TestNotificationHandlerSendsWhatsAppAndEmail(t *testing.T) {
	envPath := os.Getenv("GO_ENV_FILE")
	if envPath == "" {
		envPath = filepath.Join("..", "..", ".env")
	}
	cfg, err := config.Load(config.FileGetter(envPath))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Env != config.EnvTesting || !config.IsTestingDatabase(cfg.DB.Database) {
		t.Fatalf("refusing non-testing database %q", cfg.DB.Database)
	}
	database, err := db.Open(context.Background(), cfg.DB)
	if err != nil {
		t.Skipf("isolated test database unavailable: %v", err)
	}
	defer database.Close()
	ctx := context.Background()
	lockConn, err := database.Conn(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer lockConn.Close()
	var acquired sql.NullInt64
	if err := lockConn.QueryRowContext(ctx, `SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing',0)`).Scan(&acquired); err != nil {
		t.Fatal(err)
	}
	if !acquired.Valid || acquired.Int64 != 1 {
		t.Fatal("test database is already owned by another runner")
	}
	defer func() {
		_, _ = lockConn.ExecContext(context.Background(), `SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing')`)
	}()

	testRecipientID, err := ensureNotificationTestSuperAdmin(t, database)
	if err != nil {
		t.Fatal(err)
	}

	waKeys := []string{"wa.enabled", "wa.base_url", "wa.username", "wa.password", "wa.device_id"}
	smtpKeys := []string{"smtp.enabled", "smtp.host", "smtp.port", "smtp.from_address", "smtp.from_name", "smtp.username", "smtp.password", "smtp.encryption", "smtp.timeout", "smtp.transactional_enabled"}
	waBefore := snapshotSettings(t, database, "integration.whatsapp", waKeys)
	smtpBefore := snapshotSettings(t, database, "integration.smtp", smtpKeys)
	var waID, failureWAID, mailID int64
	defer func() {
		if waID != 0 {
			_, _ = database.ExecContext(context.Background(), `DELETE FROM whatsapp_messages WHERE id=?`, waID)
		}
		if failureWAID != 0 {
			_, _ = database.ExecContext(context.Background(), `DELETE FROM admin_notifications WHERE subject_type='whatsapp_message' AND subject_id=?`, failureWAID)
			_, _ = database.ExecContext(context.Background(), `DELETE FROM whatsapp_messages WHERE id=?`, failureWAID)
		}
		if mailID != 0 {
			_, _ = database.ExecContext(context.Background(), `DELETE FROM email_messages WHERE id=?`, mailID)
		}
		restoreSettings(t, database, "integration.whatsapp", waKeys, waBefore)
		restoreSettings(t, database, "integration.smtp", smtpKeys, smtpBefore)
	}()

	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	appKey := cfg.AppKey
	var gatewayDevice, gatewayPhone, gatewayBody string
	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.URL.Path != "/send/message" {
			t.Errorf("GOWA request %s %s", r.Method, r.URL.Path)
		}
		user, password, ok := r.BasicAuth()
		if !ok || user != "worker" || password != "worker-secret" {
			t.Errorf("unexpected GOWA auth")
		}
		gatewayDevice = r.Header.Get("X-Device-Id")
		var payload map[string]string
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			t.Errorf("decode GOWA body: %v", err)
		}
		gatewayPhone, gatewayBody = payload["phone"], payload["message"]
		if gatewayBody == "force-failure" {
			w.WriteHeader(http.StatusServiceUnavailable)
			_, _ = w.Write([]byte(`{"message":"provider failure"}`))
			return
		}
		_, _ = w.Write([]byte(`{"code":"SUCCESS","results":{"message_id":"gowa-worker-1"}}`))
	}))
	defer gateway.Close()
	waPassword, err := cryptox.EncryptString(appKey, "worker-secret")
	if err != nil {
		t.Fatal(err)
	}
	writeSettings(t, database, "integration.whatsapp", map[string]string{
		"wa.enabled": "1", "wa.base_url": gateway.URL, "wa.username": "worker", "wa.password": waPassword, "wa.device_id": "global-test-slot",
	})
	result, err := database.ExecContext(ctx, `INSERT INTO whatsapp_messages
		(idempotency_key,purpose,event,template_class,recipient_phone,body,status,max_attempts,queued_at,created_at,updated_at)
		VALUES (?,?,?,?,?,'worker body','queued',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		"worker-test-wa:"+strconv.FormatInt(time.Now().UnixNano(), 10), "test", "test", "full", "628123456789")
	if err != nil {
		t.Fatal(err)
	}
	waID, err = result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	handler := New(database, appKey)
	if err := jobs.EnqueueWhatsApp(ctx, database, waID); err != nil {
		t.Fatal(err)
	}
	staleResult, err := database.ExecContext(ctx, `UPDATE jobs SET reserved_at=? WHERE queue=? AND payload LIKE CONCAT('%messageId%;i:',?,';%')`, time.Now().Unix()-91, jobs.NotificationQueue, waID)
	if err != nil {
		t.Fatal(err)
	}
	if affected, _ := staleResult.RowsAffected(); affected != 1 {
		t.Fatalf("expected to stale-reserve one WhatsApp queue job, affected %d", affected)
	}
	processed, err := jobs.Process(ctx, database, handler)
	if err != nil || !processed {
		t.Fatalf("WhatsApp queue process processed=%v err=%v", processed, err)
	}
	var waStatus, providerID string
	if err := database.QueryRowContext(ctx, `SELECT status,provider_message_id FROM whatsapp_messages WHERE id=?`, waID).Scan(&waStatus, &providerID); err != nil {
		t.Fatal(err)
	}
	if waStatus != "sent" || providerID != "gowa-worker-1" || gatewayDevice != "global-test-slot" || gatewayPhone != "628123456789@s.whatsapp.net" || gatewayBody != "worker body" {
		t.Fatalf("WhatsApp worker mismatch: status=%s provider=%s device=%s phone=%s body=%s", waStatus, providerID, gatewayDevice, gatewayPhone, gatewayBody)
	}
	result, err = database.ExecContext(ctx, `INSERT INTO whatsapp_messages
		(idempotency_key,purpose,event,template_class,recipient_phone,body,status,max_attempts,queued_at,created_at,updated_at)
		VALUES (?,?,?,?,?,'force-failure','queued',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		"worker-test-wa-failure:"+strconv.FormatInt(time.Now().UnixNano(), 10), "transaction", "order_created", "full", "628123456789")
	if err != nil {
		t.Fatal(err)
	}
	failureWAID, err = result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	if err := jobs.EnqueueWhatsApp(ctx, database, failureWAID); err != nil {
		t.Fatal(err)
	}
	processed, err = jobs.Process(ctx, database, handler)
	if err != nil || !processed {
		t.Fatalf("failed WhatsApp queue process processed=%v err=%v", processed, err)
	}
	var failedStatus, dedupe string
	if err := database.QueryRowContext(ctx, `SELECT status FROM whatsapp_messages WHERE id=?`, failureWAID).Scan(&failedStatus); err != nil {
		t.Fatal(err)
	}
	if err := database.QueryRowContext(ctx, `SELECT dedupe_key FROM admin_notifications WHERE subject_type='whatsapp_message' AND subject_id=? ORDER BY id DESC LIMIT 1`, failureWAID).Scan(&dedupe); err != nil {
		t.Fatal(err)
	}
	if failedStatus != "unknown" || dedupe != fmt.Sprintf("delivery.failed:whatsapp_message:%d:%d", failureWAID, testRecipientID) {
		t.Fatalf("failure audit mismatch: status=%s dedupe=%s", failedStatus, dedupe)
	}

	address, closeSMTP := startSMTPStub(t)
	defer closeSMTP()
	parts := strings.Split(address, ":")
	port := parts[len(parts)-1]
	writeSettings(t, database, "integration.smtp", map[string]string{
		"smtp.enabled": "1", "smtp.host": "127.0.0.1", "smtp.port": port,
		"smtp.from_address": "noreply@imp010.test", "smtp.from_name": "PrimeClassy Test", "smtp.username": "", "smtp.password": "",
		"smtp.encryption": "none", "smtp.timeout": "5", "smtp.transactional_enabled": "1",
	})
	result, err = database.ExecContext(ctx, `INSERT INTO email_messages
		(idempotency_key,event,recipient_role,recipient_user_id,recipient_email,subject,body,status,created_at,updated_at)
		VALUES (?,'order_created','creator',?,'konsumen@imp010.test','Worker subject','Worker email body','queued',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`,
		"worker-test-mail:"+strconv.FormatInt(time.Now().UnixNano(), 10), testRecipientID)
	if err != nil {
		t.Fatal(err)
	}
	mailID, err = result.LastInsertId()
	if err != nil {
		t.Fatal(err)
	}
	queueTx, err := database.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := jobs.EnqueueEmailTx(ctx, queueTx, mailID); err != nil {
		_ = queueTx.Rollback()
		t.Fatal(err)
	}
	if err := queueTx.Commit(); err != nil {
		t.Fatal(err)
	}
	processed, err = jobs.Process(ctx, database, handler)
	if err != nil || !processed {
		t.Fatalf("email queue process processed=%v err=%v", processed, err)
	}
	var mailStatus string
	if err := database.QueryRowContext(ctx, `SELECT status FROM email_messages WHERE id=?`, mailID).Scan(&mailStatus); err != nil {
		t.Fatal(err)
	}
	if mailStatus != "sent" {
		t.Fatalf("email status=%s, want sent", mailStatus)
	}
}

func ensureNotificationTestSuperAdmin(t *testing.T, database *db.DB) (int64, error) {
	t.Helper()
	var userID int64
	err := database.QueryRow(`SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='super_admin' ORDER BY u.id LIMIT 1`).Scan(&userID)
	if err == nil {
		return userID, nil
	}
	if err != sql.ErrNoRows {
		return 0, err
	}
	var roleID int64
	roleErr := database.QueryRow(`SELECT id FROM roles WHERE slug='super_admin' LIMIT 1`).Scan(&roleID)
	createdRole := false
	if roleErr == sql.ErrNoRows {
		result, insertErr := database.Exec(`INSERT INTO roles (slug,name,created_at,updated_at) VALUES ('super_admin','Super Admin',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`)
		if insertErr != nil {
			return 0, insertErr
		}
		roleID, _ = result.LastInsertId()
		createdRole = true
	} else if roleErr != nil {
		return 0, roleErr
	}
	if createdRole {
		t.Cleanup(func() { _, _ = database.Exec(`DELETE FROM roles WHERE id=?`, roleID) })
	}
	email := fmt.Sprintf("notification-worker-test-%d@imp010.test", time.Now().UnixNano())
	result, err := database.Exec(`INSERT INTO users (role_id,name,email,password,status,created_at,updated_at)
		VALUES (?,'Notification Worker Test',?,'unused-test-password','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, roleID, email)
	if err != nil {
		return 0, err
	}
	userID, err = result.LastInsertId()
	if err != nil {
		return 0, err
	}
	t.Cleanup(func() { _, _ = database.Exec(`DELETE FROM users WHERE id=?`, userID) })
	return userID, nil
}

func snapshotSettings(t *testing.T, database *db.DB, group string, keys []string) map[string]string {
	t.Helper()
	out := map[string]string{}
	for _, key := range keys {
		var value string
		err := database.QueryRow(`SELECT value FROM settings WHERE `+"`group`"+`=? AND `+"`key`"+`=?`, group, key).Scan(&value)
		if err == nil {
			out[key] = value
		} else if err != sql.ErrNoRows {
			t.Fatal(err)
		}
	}
	return out
}
func writeSettings(t *testing.T, database *db.DB, group string, values map[string]string) {
	t.Helper()
	for key, value := range values {
		if _, err := database.Exec(`INSERT INTO settings (`+"`key`"+`,value,`+"`group`"+`,is_public,created_at,updated_at) VALUES (?,?,?,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE value=VALUES(value),`+"`group`"+`=VALUES(`+"`group`"+`),updated_at=UTC_TIMESTAMP(6)`, key, value, group); err != nil {
			t.Fatal(err)
		}
	}
}
func restoreSettings(t *testing.T, database *db.DB, group string, keys []string, before map[string]string) {
	t.Helper()
	for _, key := range keys {
		if value, ok := before[key]; ok {
			result, err := database.Exec(`UPDATE settings SET value=?,updated_at=UTC_TIMESTAMP(6) WHERE `+"`group`"+`=? AND `+"`key`"+`=?`, value, group, key)
			if err != nil {
				t.Fatal(err)
			}
			count, _ := result.RowsAffected()
			if count == 0 {
				writeSettings(t, database, group, map[string]string{key: value})
			}
		} else if _, err := database.Exec(`DELETE FROM settings WHERE `+"`group`"+`=? AND `+"`key`"+`=?`, group, key); err != nil {
			t.Fatal(err)
		}
	}
}

func startSMTPStub(t *testing.T) (string, func()) {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	done := make(chan struct{})
	go func() {
		defer close(done)
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		_, _ = fmt.Fprint(conn, "220 test smtp\r\n")
		reader := bufio.NewReader(conn)
		for {
			line, err := reader.ReadString('\n')
			if err != nil {
				return
			}
			upper := strings.ToUpper(strings.TrimSpace(line))
			switch {
			case strings.HasPrefix(upper, "EHLO"), strings.HasPrefix(upper, "HELO"):
				_, _ = fmt.Fprint(conn, "250 test\r\n")
			case strings.HasPrefix(upper, "MAIL FROM"), strings.HasPrefix(upper, "RCPT TO"):
				_, _ = fmt.Fprint(conn, "250 ok\r\n")
			case upper == "DATA":
				_, _ = fmt.Fprint(conn, "354 continue\r\n")
				for {
					data, err := reader.ReadString('\n')
					if err != nil {
						return
					}
					if strings.TrimRight(data, "\r\n") == "." {
						break
					}
				}
				_, _ = fmt.Fprint(conn, "250 queued\r\n")
			case upper == "QUIT":
				_, _ = fmt.Fprint(conn, "221 bye\r\n")
				return
			default:
				_, _ = fmt.Fprint(conn, "250 ok\r\n")
			}
		}
	}()
	return listener.Addr().String(), func() {
		_ = listener.Close()
		select {
		case <-done:
		case <-time.After(time.Second):
		}
	}
}
