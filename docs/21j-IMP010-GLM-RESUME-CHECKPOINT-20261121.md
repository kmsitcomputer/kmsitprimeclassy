# IMP-010 GLM Continuation Checkpoint — 2026-11-21

Status: **IN PROGRESS — notification success transport and Sheets sync failure-path differentials pass; provider-failure parity and Sheets success-provider cross-runtime comparison remain open.**

## Objective and phase

Continue from `docs/21i-IMP010-GLM-PAUSE-CHECKPOINT-20261121.md`. Active work is Phase 3/4 notification delivery worker and lifecycle verification, followed by Google Sheets sync. The canonical matrix remains **351 GO / 24 PARTIAL / 0 Laravel-only**; no endpoint classification was changed.

## Repository state

- Branch: `imp010/go-backend`
- HEAD: `06024ea5f92fdfa3a5b115575acc85f7118f3d2b` (`fix(sheets): match Laravel sync missing-config response`).
- Commits in this continuation: `7585321` (durable Go notification delivery worker), `58b372b` (worker checkpoint), `ea29f34` (one-shot worker mode), `3f9650d` (notification worker and Sheets parity harnesses), `1f7f794` (Laravel-compatible transactional email HTML), `06024ea` (Sheets missing-config response parity).
- No source changes are pending after `06024ea`; this checkpoint update is pending commit.
- Prior checkpoint's order-notification changes and differential harness are in commit `18135aa`.
- A generated worker binary was moved outside the repository to `/tmp/imp010-notification-worker-check`; it is not part of source changes.

## Work and verification

- `python3 tools/parity/compare_notification_queue.py` — PASS against Laravel `http://127.0.0.1:8899` and Go `http://127.0.0.1:8083`; enabled notification ledgers, admin inbox rows, and normalized queue payloads matched. This verifies the committed order-created queue fan-out.
- `python3 tools/parity/compare_payments.py` — 16 passed, 0 failed.
- `python3 tools/parity/compare_fulfillment.py` — 14 passed, 0 failed.
- `python3 tools/parity/compare_return_request.py` — 9 passed, 0 failed.
- `python3 tools/parity/compare_notification_worker.py` — PASS for Laravel and Go one-shot workers on WhatsApp test-mode and transactional email with channels disabled. Both produced the same skip status/reason, deleted the queue row, and created zero admin notices.
- `python3 tools/parity/compare_notification_transport.py` — PASS on Laravel and Go current-source workers: both WhatsApp rows became `sent` with provider ID `fake-message-1`; GOWA method/path/auth/device/payload matched; both email rows became `sent`; SMTP recipient, subject, `text/html` MIME type and complete rendered HTML body matched; queue rows were deleted and no admin notices were produced.
- `cd go-backend && go test ./internal/admininfra ./internal/jobs ./internal/notifications ./internal/notificationworker -count=1` — PASS, 0 failures. The integration fixture now creates and cleans up a temporary super-admin when the testing schema has no matching fixture account.
- `cd go-backend && go vet ./internal/admininfra ./internal/jobs ./internal/notifications ./internal/notificationworker` — PASS.
- `python3 tools/parity/compare_sheets_sync.py` against Laravel 8899 and current-source Go test API 8084 — **7/7 PASS**. This includes missing config, mapping rejection, and product/transaction log/result contracts. The live API test surfaced a 404 locale mismatch (`Endpoint tidak ditemukan.` vs Laravel `Endpoint not found.`); Go now returns the reference English 404 and a scoped regression assertion covers it.
- `cd go-backend && go test ./internal/api -run '^TestGoogleSheetsSyncWritesAndCompletesLog$' -count=1` — PASS after the 404 fix; also exercises stub-provider success and provider-forbidden failure sync logs.
- `cd go-backend && go vet ./internal/api` — PASS.
- `docker exec pc_laravel_imp010 sh -lc 'cd /app && APP_ENV=testing php artisan test --filter=GoogleSheetsTest'` — **15 passed, 87 assertions**, 0 failures (25.70s).
- `GOOS=linux GOARCH=amd64 go -C go-backend build -o /tmp/imp010-notify-worker ./cmd/worker` — PASS; copied into the Go test container for transport parity.
- `GOOS=linux GOARCH=amd64 go -C go-backend build -o /tmp/imp010-migrate ./cmd/migrate` — PASS; applied its additive test-only `pc_go_sessions` migration after Laravel's scoped test reset.
- `python3 -m py_compile tools/parity/compare_sheets_sync.py tools/parity/compare_notification_worker.py tools/parity/compare_notification_transport.py` — PASS.
- `git diff --check` — PASS.
- All Go DB integration tests use `primeclassy_testing` and the repository named test DB lock; no full suite ran.

## Implementation details and boundaries

- Added Go notification delivery handler for existing WhatsApp and transactional email ledger rows, including Agent device slot selection, restricted recipient revalidation, volume limits, retries, result persistence, and delivery-failure admin notifications.
- Added worker command with a MariaDB singleton lock for the `primeclassy-notify` queue.
- Matched Laravel database queue `retry_after` default (90 seconds) by reclaiming stale reserved jobs.
- Added GOWA device-scoped send support that rejects missing device IDs without fallback, plus conservative retry classification for clearly disconnected devices and safe duplicate behavior for ambiguous provider failures.
- Added `notifications.RecordDeliveryFailed` to record failure notices within the delivery result transaction.
- Notification success transport parity is now verified cross-runtime. Cross-runtime GOWA failure outcomes/admin notice payloads and SMTP failure classification/message parity remain open; Go's terminal failure audit is Go integration-tested only.
- The Sheets sync differential exercises the missing-credential environment and invalid mapping. Success/failure provider HTTP payload parity remains verified separately by the Go provider-stub integration test and Laravel scoped feature tests, not one shared cross-runtime fake-provider comparison.

## Environment and test database

- Docker daemon is currently available. Test-only Laravel server `pc_laravel_imp010` is on port 8899; Go differential server `imp010-go-notify-diff` is on port 8083. The pre-existing Go API container `pc_go_imp010` is on port 8082. No container was stopped or removed.
- Laravel's scoped `GoogleSheetsTest` schema reset removed Go's additive `pc_go_sessions` table from the shared testing schema. Restored it using `cmd/migrate` after confirming the mounted Go config was `APP_ENV=testing`, `DB_DATABASE=primeclassy_testing`; output `apply 001_create_pc_go_sessions.sql`, `done: 1 applied, 1 total`. This migration is `CREATE TABLE IF NOT EXISTS` only. A source-mounted sidecar `imp010-go-sheets-parity2` currently serves current-source Go on port 8084 for parity; the earlier failed startup container `imp010-go-sheets-parity` exited with `go: not found` and was left untouched. No existing service container was stopped/removed.
- Latest audit under `primeclassy:testrun:primeclassy_testing`: orders=0, jobs=0, failed_jobs=0, users=0, `pc_go_sessions`=0; all tagged notification/Sheets fixtures=0 and test notification settings absent/restored. The three Go sessions created by this test login were deleted by exact session IDs after confirming they referenced only temporary harness users 36–38.
- Never run multiple test DB runners concurrently; no full backend acceptance suite.

## Known risks / open work

- Cross-runtime transport failure differential remains open, especially SMTP exception class/message and provider retry classification.
- Worker currently does not implement Laravel `failed_jobs`/max-attempts semantics for malformed or repeatedly failing queue records; inspect exact project queue configuration and Laravel worker policy before production use.
- A process crash after a WhatsApp ledger transitions to `sending` can leave that ledger row non-queued; Laravel delivery also treats replay of a non-queued row as a no-op, but this crash path needs explicit parity evidence and operational treatment.
- Cross-runtime Sheets provider batch payload comparison remains open; current 7/7 evidence covers missing credentials, invalid mapping, missing config and sync logs, while Go provider success/failure is separately stub-tested.
- Canonical matrix unchanged at 351 GO / 24 PARTIAL / 0 Laravel-only.
- No production access, deployment, push, Laravel removal, or GLM worktree modification occurred.

## EXACT NEXT ACTION

1. Extend the local-only transport differential with GOWA terminal/ambiguous failure cases and an SMTP failure response; compare message ledger fields, retry/no-retry behavior, queue rows and admin notices across Laravel and Go.
2. Build a cross-runtime Sheets fake-provider harness that compares batch API request payloads plus success/failure log states; never call real Google. Keep test rows under `primeclassy_testing` and use `TestDatabaseLock`.
3. Fix any concrete mismatches and rerun scoped tests. Keep the canonical matrix at 351/24/0 until all required route evidence and the wider 375-route audit are complete; do not declare this lane GO prematurely.
