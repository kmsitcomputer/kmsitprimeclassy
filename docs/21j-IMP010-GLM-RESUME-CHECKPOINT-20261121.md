# IMP-010 GLM Continuation Checkpoint — 2026-11-21

Status: **AUDIT COMPLETE — 375 GO / 0 PARTIAL / 0 Laravel-only.** All final scoped differentials passed. No full suite ran.

## Objective and phase

Continue from `docs/21i-IMP010-GLM-PAUSE-CHECKPOINT-20261121.md`. At committed HEAD `5d4c157`, the matrix was 352 GO / 23 PARTIAL / 0 Laravel-only. This audit verified the lifecycle notification routes, fixed the last underpaid refund message mismatch, ported registration welcome mail to the Laravel queue contract, and regenerated the canonical matrix to **375 GO / 0 PARTIAL / 0 Laravel-only** (375 total; 45/45 areas).

## Repository state

- Branch: `imp010/go-backend`
- Final local commit on this branch: `fix(parity): complete final partial endpoint audit`, based on `5d4c157a312007024cb72af089cb12e7d80354a7`.
- Commits in this continuation: `7585321` (durable Go notification delivery worker), `58b372b` (worker checkpoint), `ea29f34` (one-shot worker mode), `3f9650d` (notification worker and Sheets parity harnesses), `1f7f794` (Laravel-compatible transactional email HTML), `06024ea` (Sheets missing-config response parity), `b3a72a2` (Sheets and notification parity checkpoint), `bc368a1` (notification failure parity), `50389df` (Sheets provider payload differential), `0daf174` (ambiguous GOWA differential), `2d042b9` (failed queue jobs).
- Final source changes add post-commit `return_requested` WhatsApp/email dispatch on consumer returns and branch-scoped in-app + WhatsApp skip dispatch on CRM-assisted returns. Registration now writes a Laravel-compatible queued welcome mailable; the Go worker decodes and sends it with Laravel retry/backoff. Notification faults do not undo registration or return state.
- Prior checkpoint's order-notification changes and differential harness are in commit `18135aa`.
- A generated worker binary was moved outside the repository to `/tmp/imp010-notification-worker-check`; it is not part of source changes.

## Work and verification

- `cd go-backend && go test ./internal/orders` — PASS (package has no tests); `go test ./internal/crm` — PASS (package has no tests); `go test ./internal/api -run '^TestParseAssistedReturnItemsReportsEveryMissingRequiredField$'` — PASS (1 test); `go vet ./internal/orders ./internal/crm ./internal/api` — PASS; `git diff --check` — PASS.
- `python3 tools/parity/compare_return_request.py` — BLOCKED before any endpoint request: required `product_stocks` fixture `(agent_id=10, product_id=3)` is absent in `primeclassy_testing`; script raised `IndexError` while reading its baseline. No harness writes occurred.
- Differential coverage still required for the current consumer and CRM-assisted return notification changes; no source change is claimed GO-verified yet.

- `python3 tools/parity/compare_notification_queue.py` — PASS against the Laravel and Go parity servers noted in the final audit below; enabled notification ledgers, admin inbox rows, and normalized queue payloads matched. This verifies the committed order-created queue fan-out.
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

## Retur audit update — 2026-11-22

- Starting inventory for this audit: **352 GO / 23 PARTIAL / 0 Laravel-only**.
- The 21 lifecycle-notification PARTIAL notes were stale: Go dispatch paths were added for order create/CRM assisted order, return request/review, order status/cancel/fulfillment, shipment/item status, payment/COD transitions, cancellation finalization, refund obligation transitions, and refund payouts. Route differential evidence passed for those routes: order create 43/43; CRM assisted order 7/7; normal return 9/9; assisted return 15/15; return review 7/7; lifecycle 10/10; fulfillment 14/14; shipment status 19/19; payment 16/16; cancellation finalize 8 comparisons; refund obligation approve 8; needs-verification resolve 8; refund attempt complete 9; reconcile 8. Existing notification queue differential passed for order-created WhatsApp/email ledger and queue state. These results support removing the stale notes; they do not claim all possible notification transport settings were exercised for every transition.
- Refund-item differential found Go returned raw `payment.not_fully_paid` for unpaid orders. Added the missing key to the generator/catalog; final `compare_return_item_refund.py` run: **10 passed, 0 failed**.
- Registration differential verified two cases (WhatsApp disabled/enabled), comparing HTTP payload, user/referral closure/activity rows, WhatsApp ledger and serialized queue jobs: **2 scenarios passed, 0 failed**. Laravel's PHP runtime successfully unserialized the Go mailable; its serialized command was byte-identical to Laravel at 1211 bytes. The Go notification worker integration test also passed, covering queue decode/dispatch; queue retries follow 3 attempts with 30/120-second backoff.
- Lifecycle differential evidence retained for 21 stale-note endpoints: order create 43/0; CRM assisted order 7/0; consumer return 9/0; assisted return 15/0; return review 7/0; lifecycle 10/0; fulfillment 14/0; shipment status 19/0; payments 16/0; cancellation finalize 8 comparisons; refund obligation approval 8; needs-verification resolve 8; refund attempt completion 9; reconcile 8. Existing notification queue differential validates order-created WhatsApp/email ledger and durable queue behavior.
- Final current-source differentials: `compare_auth_registration.py` **2 scenarios passed**; `compare_return_item_refund.py` **10 passed**. The latter compares error responses plus payout, invoice snapshot, commission adjustment, refund ledger, audit and replay state.
- Scoped Go checks in the `primeclassy_testing` container, run serially: `go test ./internal/jobs -count=1`, `go test ./internal/auth -count=1`, `go test ./internal/admininfra -count=1`, `go test ./internal/notificationworker -count=1`, `go test ./internal/orders -count=1`, `go test ./internal/crm -count=1`, `go test ./internal/api -run '^TestParseAssistedReturnItemsReportsEveryMissingRequiredField$' -count=1`, and `go test ./internal/i18n -count=1` — PASS. `go vet ./internal/jobs ./internal/auth ./internal/admininfra ./internal/notificationworker ./internal/orders ./internal/crm ./internal/api ./internal/i18n` — PASS.
- Canonical matrix regenerated at `docs/21a-IMP010-API-PARITY-MATRIX.md`: **375 GO / 0 PARTIAL / 0 Laravel-only**, 45/45 areas. `git diff --check` — PASS. No Full Test ran.
- The final audit commit is local on `imp010/go-backend`; the worktree is clean. No push, deployment, production access or Laravel removal occurred. No container was stopped or removed.

## Implementation details and boundaries

- Added Go notification delivery handler for existing WhatsApp and transactional email ledger rows, including Agent device slot selection, restricted recipient revalidation, volume limits, retries, result persistence, and delivery-failure admin notifications.
- Added worker command with a MariaDB singleton lock for the `primeclassy-notify` queue.
- Matched Laravel database queue `retry_after` default (90 seconds) by reclaiming stale reserved jobs.
- Added GOWA device-scoped send support that rejects missing device IDs without fallback, plus conservative retry classification for clearly disconnected devices and safe duplicate behavior for ambiguous provider failures.
- Added `notifications.RecordDeliveryFailed` to record failure notices within the delivery result transaction.
- Notification success and covered failure-path transport parity are verified cross-runtime. Covered failures are definitive GOWA 401 and SMTP 550 recipient rejection; Go's transactional WhatsApp terminal-alert path is also database-integration-tested.
- Sheets success and provider-denial API request payloads and resulting sync logs are compared cross-runtime with a one-row product fixture and local fake provider. Route auth/contract remains separately checked by `compare_sheets_sync.py`.

## Environment and test database

- Docker daemon is currently available. Final scoped differentials used test-only Laravel `pc_laravel_imp010_registration` on host port 8895 and current-source Go `imp010-go-parity-final` on host port 8099. Other existing project containers were not stopped or removed.
- Laravel's scoped `GoogleSheetsTest` schema reset removed Go's additive `pc_go_sessions` table from the shared testing schema. Restored it using `cmd/migrate` after confirming the mounted Go config was `APP_ENV=testing`, `DB_DATABASE=primeclassy_testing`; output `apply 001_create_pc_go_sessions.sql`, `done: 1 applied, 1 total`. This migration is `CREATE TABLE IF NOT EXISTS` only. A source-mounted sidecar `imp010-go-sheets-parity2` currently serves current-source Go on port 8084 for parity; the earlier failed startup container `imp010-go-sheets-parity` exited with `go: not found` and was left untouched. No existing service container was stopped/removed.
- Final isolated test database is `primeclassy_testing`, with documented synthetic baseline restored after parity harnesses: users=11, orders=0, jobs=65, failed_jobs=1, `pc_go_sessions`=113. Test-only fixture jobs and sessions were left intact; no blanket cleanup was run. Notification settings changed by harnesses were restored.
- Never run multiple test DB runners concurrently; no full backend acceptance suite.

## Known risks / open work

- GOWA ambiguous outcomes now have cross-runtime evidence for HTTP 503 and timeout after request receipt; both preserve UNKNOWN, do not retry, and leave no queued job. Timeout detail text is client-library specific.
- Worker handles existing notification jobs with `$tries=1` and registration mail with Laravel's `$tries=3` / `backoff=[30,120]`; queued-mailable payload serialization and worker dispatch are verified.
- A process crash after a WhatsApp ledger transitions to `sending` leaves that ledger row non-queued. Both runtimes delete the replayed job without resending; operator recovery remains a manual concern consistent with Laravel's behavior.
- Additional Sheets cases (protected columns / row-anchored merge and Google API error variants) remain subject to broader scoped feature/integration evidence; this cross-runtime harness covers unprotected product replacement plus success and 403 failure.
- Canonical matrix at the pre-audit committed HEAD was 352 GO / 23 PARTIAL / 0 Laravel-only. The final audit commit records the regenerated, differentially verified matrix at **375 GO / 0 PARTIAL / 0 Laravel-only**.
- No production access, deployment, push, Laravel removal, or GLM worktree modification occurred.

## EXACT NEXT ACTION

1. Stop this IMP-010 continuation at **375 GO / 0 PARTIAL / 0 Laravel-only** and await the Owner's next mandate. Full Test and cutover are not part of this continuation.
