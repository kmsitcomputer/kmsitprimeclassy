# IMP-010 GLM Continuation Checkpoint — 2026-11-21

Status: **IN PROGRESS — notification delivery worker implemented and scoped-tested; Laravel differential for actual worker delivery and Sheets sync remain outstanding.**

## Objective and phase

Continue from `docs/21i-IMP010-GLM-PAUSE-CHECKPOINT-20261121.md`. Active work is Phase 3/4 notification delivery worker and lifecycle verification, followed by Google Sheets sync. The canonical matrix remains **351 GO / 24 PARTIAL / 0 Laravel-only**; no endpoint classification was changed.

## Repository state

- Branch: `imp010/go-backend`
- HEAD at start of this continuation: `18135aac6d07757638f4d2eecde384813319aa29`.
- Commit created during this continuation: `7585321` (`feat(notifications): add durable Go delivery worker`).
- Worktree changes to commit: `go-backend/internal/admininfra/admininfra.go`, `go-backend/internal/admininfra/admininfra_test.go`, `go-backend/internal/jobs/worker.go`, new `go-backend/internal/notifications/delivery.go`, `go-backend/internal/notificationworker/`, and `go-backend/cmd/worker/`.
- Prior checkpoint's order-notification changes and differential harness are in commit `18135aa`.
- A generated worker binary was moved outside the repository to `/tmp/imp010-notification-worker-check`; it is not part of source changes.

## Work and verification

- `python3 tools/parity/compare_notification_queue.py` — PASS against Laravel `http://127.0.0.1:8899` and Go `http://127.0.0.1:8083`; enabled notification ledgers, admin inbox rows, and normalized queue payloads matched. This verified the committed order-created queue fan-out, not delivery by the new worker.
- `python3 tools/parity/compare_payments.py` — 16 passed, 0 failed.
- `python3 tools/parity/compare_fulfillment.py` — 14 passed, 0 failed.
- `python3 tools/parity/compare_return_request.py` — 9 passed, 0 failed.
- `cd go-backend && go test ./internal/notificationworker -count=1` — PASS with real `primeclassy_testing` DB, fake GOWA HTTP server and SMTP stub. Exercises Laravel queue-job decoding/claim/process, WhatsApp success, stale reservation reclaim after 90 seconds, terminal failure ledger plus deduplicated admin notice, and email success.
- `cd go-backend && go test ./internal/admininfra ./internal/notifications ./internal/jobs ./internal/notificationworker -count=1` — PASS, 0 failures.
- `cd go-backend && go vet ./internal/admininfra ./internal/notifications ./internal/jobs ./internal/notificationworker` — PASS.
- `cd go-backend && go build ./cmd/worker` — PASS.
- `git diff --check` — PASS.
- All Go DB integration tests use `primeclassy_testing` and the repository named test DB lock; no full suite ran.

## Implementation details and boundaries

- Added Go notification delivery handler for existing WhatsApp and transactional email ledger rows, including Agent device slot selection, restricted recipient revalidation, volume limits, retries, result persistence, and delivery-failure admin notifications.
- Added worker command with a MariaDB singleton lock for the `primeclassy-notify` queue.
- Matched Laravel database queue `retry_after` default (90 seconds) by reclaiming stale reserved jobs.
- Added GOWA device-scoped send support that rejects missing device IDs without fallback, plus conservative retry classification for clearly disconnected devices and safe duplicate behavior for ambiguous provider failures.
- Added `notifications.RecordDeliveryFailed` to record failure notices within the delivery result transaction.
- API notification queue differential passed before the worker was added. The actual worker has only Go integration evidence so far; do not mark worker lane or any endpoint GO on this basis.

## Environment and test database

- Docker daemon is currently available. Test-only Laravel server `pc_laravel_imp010` is on port 8899; Go differential server `imp010-go-notify-diff` is on port 8083. The pre-existing Go API container `pc_go_imp010` is on port 8082. No container was stopped or removed.
- Prior cleanup under `primeclassy:testrun:primeclassy_testing` found the database clean (no orders/jobs, notification settings restored, users 11/13 email verification NULL). Later scoped harnesses may leave their documented final order fixture; inspect under the named lock before any new DB operation.
- Never run multiple test DB runners concurrently; no full backend acceptance suite.

## Known risks / open work

- No Laravel-vs-Go delivery-worker differential harness exists yet. The fake GOWA/SMTP integration verifies internal behavior but cannot establish exact Laravel worker parity, especially SMTP exception classification and message bodies.
- Worker currently does not implement Laravel `failed_jobs`/max-attempts semantics for malformed or repeatedly failing queue records; inspect exact project queue configuration and Laravel worker policy before production use.
- A process crash after a WhatsApp ledger transitions to `sending` can leave that ledger row non-queued; Laravel delivery also treats replay of a non-queued row as a no-op, but this crash path needs explicit parity evidence and operational treatment.
- Google Sheets sync differential remains outstanding from checkpoint `21i`.
- Canonical matrix unchanged at 351 GO / 24 PARTIAL / 0 Laravel-only.
- No production access, deployment, push, Laravel removal, or GLM worktree modification occurred.

## EXACT NEXT ACTION

1. Inspect the Laravel job classes, queue configuration and activity/failure semantics; add a scoped Laravel-vs-Go worker differential for a test-mode WhatsApp message and transactional email, comparing ledger rows, queue rows and admin notices under the test DB lock.
2. Fix concrete parity mismatches and rerun only the relevant worker integration/differential checks.
3. Continue the documented Google Sheets sync differential; do not regenerate the canonical matrix until its assigned work is fully verified.
