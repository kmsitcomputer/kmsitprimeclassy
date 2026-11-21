// Command worker processes only Laravel's dedicated primeclassy-notify queue.
package main

import (
	"context"
	"errors"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/jobs"
	"github.com/kmsitcomputer/primeclassy-go/internal/notificationworker"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	cfg, err := config.Load(config.FileGetter(".env"))
	if err != nil {
		slog.Error("worker configuration failed", "error", err)
		os.Exit(1)
	}
	database, err := db.Open(ctx, cfg.DB)
	if err != nil {
		slog.Error("worker database connection failed", "error", err)
		os.Exit(1)
	}
	defer database.Close()
	lock, err := database.Conn(ctx)
	if err != nil {
		slog.Error("worker lock connection failed", "error", err)
		os.Exit(1)
	}
	defer lock.Close()
	var acquired int
	if err := lock.QueryRowContext(ctx, `SELECT GET_LOCK('primeclassy:notify:worker',0)`).Scan(&acquired); err != nil || acquired != 1 {
		slog.Error("notification worker is already running", "error", err)
		os.Exit(1)
	}
	defer func() {
		_, _ = lock.ExecContext(context.Background(), `SELECT RELEASE_LOCK('primeclassy:notify:worker')`)
	}()

	handler := notificationworker.New(database, cfg.AppKey)
	slog.Info("notification worker started", "queue", jobs.NotificationQueue)
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		processed, err := jobs.Process(ctx, database, handler)
		if err != nil && !errors.Is(err, context.Canceled) {
			slog.Error("notification job failed", "error", err)
		}
		if ctx.Err() != nil {
			break
		}
		if !processed {
			select {
			case <-ctx.Done():
			case <-ticker.C:
			}
		}
	}
	slog.Info("notification worker stopped")
}
