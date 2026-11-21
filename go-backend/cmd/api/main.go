// Command api runs the PrimeClassy API service.
//
// It replaces nothing yet: Laravel remains authoritative until a cutover is separately approved. This binary
// serves the endpoints listed by GET /api/v1/_parity and answers 501 for the rest, so a reverse proxy can be
// configured to send a chosen subset of traffic here without any endpoint silently changing behaviour.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/api"
	"github.com/kmsitcomputer/primeclassy-go/internal/asset"
	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/config"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/media"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

func main() {
	var showVersion bool
	flag.BoolVar(&showVersion, "version", false, "print version and exit")
	flag.Parse()

	if err := run(); err != nil {
		// Configuration and boot failures go to stderr in plain text: the structured logger may not exist yet.
		fmt.Fprintf(os.Stderr, "fatal: %v\n", err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load(config.FileGetter(".env"))
	if err != nil {
		return err
	}

	logger := newLogger(cfg.Log)
	slog.SetDefault(logger)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	database, err := db.Open(ctx, cfg.DB)
	if err != nil {
		return fmt.Errorf("database: %w", err)
	}
	defer func() { _ = database.Close() }()

	auth.SetPublicBaseURL(cfg.PublicURL)
	asset.SetBase(cfg.PublicURL)
	orders.SetPublicBase(cfg.PublicURL)
	media.SetRoot(cfg.MediaRoot)

	server := api.New(cfg, database, api.Options{
		InstallLockPath: installLockPath(),
		Logger:          logger,
	})

	httpServer := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           publicStorageHandler(server.Handler(), cfg.MediaRoot),
		ReadHeaderTimeout: cfg.HTTP.ReadHeaderTimeout,
		ReadTimeout:       cfg.HTTP.ReadTimeout,
		WriteTimeout:      cfg.HTTP.WriteTimeout,
		IdleTimeout:       cfg.HTTP.IdleTimeout,
	}

	errCh := make(chan error, 1)
	go func() {
		logger.Info("api listening",
			"addr", cfg.HTTPAddr,
			"env", string(cfg.Env),
			"database", cfg.DB.Database,
			"session_cookie", cfg.Session.CookieName,
			"stateful_domains", cfg.Session.StatefulDomains,
		)
		if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case err := <-errCh:
		return fmt.Errorf("listen: %w", err)
	case <-ctx.Done():
		logger.Info("shutdown signal received")
	}

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := httpServer.Shutdown(shutdownCtx); err != nil {
		return fmt.Errorf("shutdown: %w", err)
	}
	logger.Info("stopped")
	return nil
}

// publicStorageHandler serves the Laravel `public` disk at /storage/... (the same URL
// contract production nginx satisfies via the public/storage symlink), then falls back
// to the API mux. Paths stay inside MEDIA_ROOT: the file server's rooted http.Dir plus
// http.FileServer's own path cleaning block traversal.
func publicStorageHandler(api http.Handler, mediaRoot string) http.Handler {
	mux := http.NewServeMux()
	if trimmed := strings.TrimRight(strings.TrimSpace(mediaRoot), "/"); trimmed != "" {
		fs := http.FileServer(http.Dir(trimmed))
		mux.Handle("/storage/", http.StripPrefix("/storage/", fs))
	}
	mux.Handle("/", api)
	return mux
}

// newLogger builds the structured logger.
func newLogger(cfg config.LogConfig) *slog.Logger {
	level := slog.LevelInfo
	switch cfg.Level {
	case "debug":
		level = slog.LevelDebug
	case "warn":
		level = slog.LevelWarn
	case "error":
		level = slog.LevelError
	}
	opts := &slog.HandlerOptions{Level: level}
	var handler slog.Handler
	if cfg.Format == "text" {
		handler = slog.NewTextHandler(os.Stdout, opts)
	} else {
		handler = slog.NewJSONHandler(os.Stdout, opts)
	}
	return slog.New(handler)
}

// installLockPath resolves the same `storage/app/installed.lock` marker Laravel's InstallLock uses, so both
// runtimes answer GET /api/v1/install/status identically. NUXT-style env override keeps it testable.
func installLockPath() string {
	if p := os.Getenv("INSTALL_LOCK_PATH"); p != "" {
		return p
	}
	// The binary is expected to run with the Laravel backend directory as a sibling (`../backend`), both in
	// the repository layout and in the container image.
	for _, candidate := range []string{
		filepath.Join("storage", "app", "installed.lock"),
		filepath.Join("..", "backend", "storage", "app", "installed.lock"),
	} {
		if _, err := os.Stat(filepath.Dir(candidate)); err == nil {
			return candidate
		}
	}
	return filepath.Join("storage", "app", "installed.lock")
}
