package jobs

import (
	"context"
	"database/sql"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
)

type registrationCaptureHandler struct {
	whatsappCalls int
	emailCalls    int
	recipient     string
	name          string
}

func (h *registrationCaptureHandler) HandleWhatsApp(context.Context, int64) error {
	h.whatsappCalls++
	return nil
}

func (h *registrationCaptureHandler) HandleEmail(context.Context, int64) error {
	return nil
}

func (h *registrationCaptureHandler) HandleRegistrationEmail(_ context.Context, recipient, name string) error {
	h.emailCalls++
	h.recipient, h.name = recipient, name
	return nil
}

func TestRegistrationMailableCommandMatchesLaravelShape(t *testing.T) {
	command := registrationMailableCommand("welcome@example.test", "Rina Test")
	for _, expected := range []string{
		`O:34:"Illuminate\Mail\SendQueuedMailable":15:{`,
		`O:30:"App\Mail\AccountRegisteredMail":33:{`,
		`address";s:20:"welcome@example.test";`,
		`name";s:9:"Rina Test";`,
		`s:18:"primeclassy-notify";`,
	} {
		if !strings.Contains(command, expected) {
			t.Fatalf("registration mailable missing %q in command %q", expected, command)
		}
	}
	if !strings.Contains(command, "\x00*\x00html") || !strings.Contains(command, "\x00*\x00tags") {
		t.Fatalf("protected Laravel mailable properties are not serialized correctly: %q", command)
	}
	recipient, ok := phpSerializedString(command, "address")
	if !ok || recipient != "welcome@example.test" {
		t.Fatalf("recipient parse = %q, %v", recipient, ok)
	}
	name, ok := phpSerializedString(command, "name")
	if !ok || name != "Rina Test" {
		t.Fatalf("name parse = %q, %v", name, ok)
	}
}

func TestPHPSerializedStringUsesUTF8ByteLength(t *testing.T) {
	command := registrationMailableCommand("rina@example.test", "Rinā")
	name, ok := phpSerializedString(command, "name")
	if !ok || name != "Rinā" {
		t.Fatalf("multibyte name parse = %q, %v", name, ok)
	}
}

func TestBackoffSecondsAcceptsLaravelJSONShapes(t *testing.T) {
	for _, tc := range []struct {
		name    string
		raw     string
		attempt int
		want    int
	}{
		{name: "Laravel comma string first attempt", raw: `"30,120"`, attempt: 1, want: 30},
		{name: "Laravel comma string second attempt", raw: `"30,120"`, attempt: 2, want: 120},
		{name: "Laravel comma string clamps later retry", raw: `"30,120"`, attempt: 3, want: 120},
		{name: "array", raw: `[7,19]`, attempt: 2, want: 19},
		{name: "null", raw: `null`, attempt: 1, want: 0},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := backoffSeconds([]byte(tc.raw), tc.attempt); got != tc.want {
				t.Fatalf("backoffSeconds(%s, %d) = %d, want %d", tc.raw, tc.attempt, got, tc.want)
			}
		})
	}
}

func TestProcessDispatchesLaravelRegistrationMailable(t *testing.T) {
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
	lockConn, err := database.Conn(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	defer lockConn.Close()
	var acquired sql.NullInt64
	if err := lockConn.QueryRowContext(context.Background(), `SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing',0)`).Scan(&acquired); err != nil {
		t.Fatal(err)
	}
	if !acquired.Valid || acquired.Int64 != 1 {
		t.Fatal("test database is already owned by another runner")
	}
	defer func() {
		_, _ = lockConn.ExecContext(context.Background(), `SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing')`)
	}()
	defer deferPendingNotificationJobs(t, database)()
	const recipient, name = "worker-registration@example.test", "Worker Registration"
	if err := EnqueueRegistrationEmail(context.Background(), database, recipient, name); err != nil {
		t.Fatal(err)
	}
	var jobID int64
	if err := database.QueryRowContext(context.Background(), `SELECT id FROM jobs WHERE queue=? ORDER BY id DESC LIMIT 1`, NotificationQueue).Scan(&jobID); err != nil {
		t.Fatal(err)
	}
	defer func() { _, _ = database.ExecContext(context.Background(), `DELETE FROM jobs WHERE id=?`, jobID) }()
	handler := &registrationCaptureHandler{}
	processed, err := Process(context.Background(), database, handler)
	if err != nil || !processed {
		t.Fatalf("Process() = %v, %v", processed, err)
	}
	if handler.emailCalls != 1 || handler.recipient != recipient || handler.name != name || handler.whatsappCalls != 0 {
		t.Fatalf("handler calls = %+v", handler)
	}
	var remaining int
	if err := database.QueryRowContext(context.Background(), `SELECT COUNT(*) FROM jobs WHERE id=?`, jobID).Scan(&remaining); err != nil {
		t.Fatal(err)
	}
	if remaining != 0 {
		t.Fatalf("processed queue job %d was not deleted", jobID)
	}
}
