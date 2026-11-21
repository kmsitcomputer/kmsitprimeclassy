# IMP-010 GLM Continuation Checkpoint — 2026-11-21

Status: **IN PROGRESS — notification success/failure/ambiguous transport, queue failure/reclaim/crash replay, and Sheets provider success/403 response + payload differentials have scoped parity evidence. Sheets route is ready for canonical matrix reclassification.**

## Objective and phase

Continue from `docs/21i-IMP010-GLM-PAUSE-CHECKPOINT-20261121.md`. Active work is Phase 3/4 notification delivery worker and lifecycle verification, followed by Google Sheets sync. The canonical matrix remains **351 GO / 24 PARTIAL / 0 Laravel-only**; no endpoint classification was changed.

## Repository state

- Branch: `imp010/go-backend`
- HEAD: `imp010/go-backend` at `2d042b9` (`fix(worker): record failed Laravel queue jobs`), with reservation and harness response-capture changes currently uncommitted.
- Commits in this continuation: `7585321` (durable Go notification delivery worker), `58b372b` (worker checkpoint), `ea29f34` (one-shot worker mode), `3f9650d` (notification worker and Sheets parity harnesses), `1f7f794` (Laravel-compatible transactional email HTML), `06024ea` (Sheets missing-config response parity), `b3a72a2` (Sheets and notification parity checkpoint), `bc368a1` (notification failure parity), `50389df` (Sheets provider payload differential), `0daf174` (ambiguous GOWA differential), `2d042b9` (failed queue jobs).
- Current uncommitted files: `go-backend/internal/jobs/worker_test.go`, `go-backend/internal/api/google_sheets_sync_integration_test.go`, `tools/parity/compare_notification_worker.py`, and `tools/parity/compare_sheets_provider_payload.py`, plus this checkpoint. The diffs cover Laravel's 90-second reservation recovery, non-queued WhatsApp crash/replay, and wrapped Sheets endpoint response comparisons. No production API logic changes are in this WIP.
- Prior checkpoint's order-notification changes and differential harness are in commit `18135aa`.
- A generated worker binary was moved outside the repository to `/tmp/imp010-notification-worker-check`; it is not part of source changes.

## Work and verification

- `python3 tools/parity/compare_notification_queue.py` — PASS against Laravel `http://127.0.0.1:8899` and Go `http://127.0.0.1:8083`; enabled notification ledgers, admin inbox rows, and normalized queue payloads matched. This verifies the committed order-created queue fan-out.
- `python3 tools/parity/compare_payments.py` — 16 passed, 0 failed.
- `python3 tools/parity/compare_fulfillment.py` — 14 passed, 0 failed.
- `python3 tools/parity/compare_return_request.py` — 9 passed, 0 failed.
- `python3 tools/parity/compare_notification_worker.py` — PASS for Laravel and Go one-shot workers on WhatsApp test-mode and transactional email with channels disabled. Both produced the same skip status/reason, deleted the queue row, and created zero admin notices.
- `python3 tools/parity/compare_notification_transport.py` — PASS on Laravel and Go current-source workers: both WhatsApp rows became `sent` with provider ID `fake-message-1`; GOWA method/path/auth/device/payload matched; both email rows became `sent`; SMTP recipient, subject, `text/html` MIME type and complete rendered HTML body matched; queue rows were deleted and no admin notices were produced.
- `python3 tools/parity/compare_notification_transport.py` — PASS after `bc368a1`: successful GOWA/SMTP delivery, terminal GOWA 401 ledger, SMTP recipient 550 error class and exact message, and admin-notice state all match. A purpose=`test` WhatsApp failure correctly creates no Laravel or Go admin alert; Go mirrors Laravel's transactional-purpose observer gate.
- `python3 tools/parity/compare_notification_transport.py` — PASS after `0daf174`: GOWA 503 and timeout after the fake provider receives the request both remain `unknown`, attempt count 1, no retry/job row; 503 error fields match exactly and alert state matches. Timeout detail wording differs between cURL and Go HTTP client, so parity compares status/class/attempts/queue/alerts for that transport-dependent field.
- `cd go-backend && go test ./internal/admininfra ./internal/jobs ./internal/notifications ./internal/notificationworker -count=1` — PASS, 0 failures. The integration fixture now creates and cleans up a temporary super-admin when the testing schema has no matching fixture account.
- `cd go-backend && go vet ./internal/admininfra ./internal/jobs ./internal/notifications ./internal/notificationworker` — PASS.
- `python3 tools/parity/compare_sheets_sync.py` against Laravel 8899 and current-source Go test API 8084 — **7/7 PASS**. This includes missing config, mapping rejection, and product/transaction log/result contracts. The live API test surfaced a 404 locale mismatch (`Endpoint tidak ditemukan.` vs Laravel `Endpoint not found.`); Go now returns the reference English 404 and a scoped regression assertion covers it.
- `cd go-backend && go test ./internal/api -run '^TestGoogleSheetsSyncWritesAndCompletesLog$' -count=1` — PASS after the 404 fix; also exercises stub-provider success and provider-forbidden failure sync logs.
- `cd go-backend && go vet ./internal/api` — PASS.
- `python3 tools/parity/compare_sheets_provider_payload.py` — PASS for provider success and 403 denial. Both runtimes made matching metadata GET and batchUpdate POST requests, including a tagged one-row product export; success and failed sync logs (including error summary) matched exactly. The local fakes made no Google API calls.
- `python3 tools/parity/compare_sheets_provider_payload.py` — PASS extended: controller success and provider-failure HTTP response envelopes also match after dynamic IDs/timestamps are normalized, along with provider payloads and sync logs.
- `docker exec pc_go_imp010 sh -lc 'cd /src && PATH=/usr/local/go/bin:$PATH go vet ./internal/api'` — PASS after adding request capture to the scoped Sheets integration test.
- `docker exec pc_go_imp010 sh -lc 'cd /src && PATH=/usr/local/go/bin:$PATH go test ./internal/jobs -run ^TestProcessLogsLaravelFailedJobsAfterOneAttempt$ -count=1 -v'` — PASS; the throwing handler writes one UUID-keyed `failed_jobs` row and deletes the queue row; a payload already at attempts=1 is failed before handler invocation.
- Sequential scoped tests in the Go container: `go test ./internal/jobs -count=1`, `go test ./internal/notificationworker -count=1`, `go test ./internal/notifications -count=1` — PASS; `go vet` the three packages — PASS. A first multi-package invocation ran DB integration packages concurrently and the notification-worker lock guard correctly rejected the overlap; rerunning each package sequentially passed.
- `docker exec pc_go_imp010 sh -lc 'cd /src && PATH=/usr/local/go/bin:$PATH go test ./internal/jobs -run ^TestClaimNextReclaimsOnlyLaravelExpiredReservations$ -count=1 -v'` — PASS; Go reclaims an expired 90-second reservation and leaves a 10-second-old reservation untouched. Fixtures are removed by exact IDs before releasing the named DB lock.
- `python3 tools/parity/compare_notification_worker.py` — PASS for disabled-channel processing and sending-ledger replay. When WhatsApp ledger is already `sending`, both workers leave state/attempts unchanged (`sending`, 1), delete the replayed queue job and do not send again.
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
- Notification success and covered failure-path transport parity are verified cross-runtime. Covered failures are definitive GOWA 401 and SMTP 550 recipient rejection; Go's transactional WhatsApp terminal-alert path is also database-integration-tested.
- Sheets success and provider-denial API request payloads and resulting sync logs are compared cross-runtime with a one-row product fixture and local fake provider. Route auth/contract remains separately checked by `compare_sheets_sync.py`.

## Environment and test database

- Docker daemon is currently available. Test-only Laravel server `pc_laravel_imp010` is on port 8899; Go differential server `imp010-go-notify-diff` is on port 8083. The pre-existing Go API container `pc_go_imp010` is on port 8082. No container was stopped or removed.
- Laravel's scoped `GoogleSheetsTest` schema reset removed Go's additive `pc_go_sessions` table from the shared testing schema. Restored it using `cmd/migrate` after confirming the mounted Go config was `APP_ENV=testing`, `DB_DATABASE=primeclassy_testing`; output `apply 001_create_pc_go_sessions.sql`, `done: 1 applied, 1 total`. This migration is `CREATE TABLE IF NOT EXISTS` only. A source-mounted sidecar `imp010-go-sheets-parity2` currently serves current-source Go on port 8084 for parity; the earlier failed startup container `imp010-go-sheets-parity` exited with `go: not found` and was left untouched. No existing service container was stopped/removed.
- Latest audit under `primeclassy:testrun:primeclassy_testing`: orders=0, jobs=0, failed_jobs=0, users=0, `pc_go_sessions`=0; all tagged notification/Sheets fixtures=0 and test notification settings absent/restored. The three Go sessions created by this test login were deleted by exact session IDs after confirming they referenced only temporary harness users 36–38.
- Never run multiple test DB runners concurrently; no full backend acceptance suite.

## Known risks / open work

- GOWA ambiguous outcomes now have cross-runtime evidence for HTTP 503 and timeout after request receipt; both preserve UNKNOWN, do not retry, and leave no queued job. Timeout detail text is client-library specific.
- Worker now handles queue failures according to notification jobs' `$tries=1` and Laravel `database-uuids` provider. Strict byte-for-byte exception trace parity is not claimed; the stored fields, UUID, original payload, queue and failure lifecycle are verified.
- A process crash after a WhatsApp ledger transitions to `sending` leaves that ledger row non-queued. Both runtimes delete the replayed job without resending; operator recovery remains a manual concern consistent with Laravel's behavior.
- Additional Sheets cases (protected columns / row-anchored merge and Google API error variants) remain subject to broader scoped feature/integration evidence; this cross-runtime harness covers unprotected product replacement plus success and 403 failure.
- Canonical matrix unchanged at 351 GO / 24 PARTIAL / 0 Laravel-only.
- No production access, deployment, push, Laravel removal, or GLM worktree modification occurred.

## EXACT NEXT ACTION

1. Commit reviewed reservation/replay and wrapped Sheets endpoint-response differential evidence after scoped checks and `git diff --check`.
2. Update the parity generator note for `POST /api/v1/google-sheets/configs/{config}/sync`, regenerate the canonical matrix, and verify count delta plus all other statuses unchanged.
3. Continue from the next active phase/checkpoint for remaining PARTIAL routes. Do not declare migration completion until the whole 375-route matrix and non-HTTP parity evidence meet the mandate.
