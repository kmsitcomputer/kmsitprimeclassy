# IMP-010 Post-Migration Zero-Omission Audit — 2026-11-21

## Verdict

**HTTP inventory: 375 routes are registered as Go-served, but the accurate status is 351 GO / 24 PARTIAL / 0 Laravel-only.** The previous 375 GO claim was not supported by full side-effect coverage. The canonical generator and matrix were corrected from source-level evidence in this audit.

**Zero-omission audit: BLOCKED. Non-HTTP parity: GAP. Full Test: PENDING. Cutover: BLOCKED. IMP-010 closure: NOT READY.** No cutover, production access, Laravel shutdown, database mutation, or remote Git action was performed.

## Repository baseline

- Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD before this audit change: `0c9c329e6bff7dfb6515c8053f98a7844ea07d2b`
- Starting worktree: clean.
- Canonical source: `go-backend/internal/api/parity_routes.json` — 375 records, 375 unique method/path pairs.
- Canonical generator: `tools/parity/gen_matrix.py`.
- No other agent was writing to this worktree.
- The Owner-reported 91 PASS / 0 FAIL differential total was not found as a reproducible aggregate artifact in repository docs. Individual committed harness evidence exists; do not treat the aggregate as independently verified by this audit.

## HTTP parity evidence and corrections

The generator confirms all 375 method/path records are mapped to Go handlers. That verifies route coverage only. Source review found observable Laravel behavior missing from Go:

1. **Google Sheets sync:** `handleGoogleSheetsConfigSync` writes a sync-log row and queries datasets, but does not call a Sheets write/replace operation. If credentials exist and metadata succeeds, the current handler still records a failed sync with the missing-credential message. `transaction_worksheet` is reduced to a partial transaction projection, and the Go row limit is 2,000 while Laravel config uses 10,000. The committed `compare_sheets_sync.py` proves only failure paths in the isolated no-credential environment (7 comparisons), not a successful sheet write.
2. **Laravel Eloquent lifecycle observers:** `WhatsAppLifecycleObserver` emits after-commit order, payment, return, refund-obligation, refund-payout and rejected-verification notifications. Go SQL handlers do not invoke an equivalent lifecycle dispatcher/outbox across these affected mutations. `AdminNotificationObserver` also watches WhatsApp delivery and Agent device transitions; the Go notification package is a recipient-scoped inbox store, not an observer/delivery implementation.
3. **Registration:** `auth.Service.notifyRegistration` writes a WhatsApp ledger row only. It does not enqueue that row for delivery, and the Laravel account-registration email is not sent.

The generated matrix now marks 24 affected routes PARTIAL, including Google Sheets sync, registration, order creation/status/payment/cancellation, return creation/review, and proven refund payout paths. See `docs/21a-IMP010-API-PARITY-MATRIX.md` for the exact route-level list and notes. This is a minimum evidence-backed correction; the remaining mutation routes still need a systematic observer-to-handler trace before the matrix can be considered fully audited.

## Zero-omission component inventory

| Component | Status | Evidence / remaining work |
|---|---|---|
| `/api/v1` route inventory | VERIFIED (inventory only) | 375 records and 375 unique method/path pairs; generator maps all 375 to Go. Behavior is 351 GO / 24 PARTIAL. |
| HTTP auth/RBAC/validation/response | PASS WITH LIMITATIONS | Per-endpoint differential harnesses and existing route tests; aggregate 91/0 was not independently reproduced in this audit. Re-run scoped affected harnesses after remediation. |
| Laravel web `GET /` welcome page | NOT_APPLICABLE to API cutover; Laravel retained | Go service is an API server; Laravel must remain available for this web route until an explicit web-surface decision. |
| Google OAuth redirect/callback | PARTIAL verification | Go OAuth implementation and unit tests exist. Successful provider round-trip with sandbox credentials was not verified in this audit. |
| Database schema | VERIFIED for current dual-runtime use; CUTOVER NOT VERIFIED | Go shares Laravel MySQL/MariaDB schema and has one additive Go session table. No fresh restore/compatibility rehearsal in this audit. |
| Sessions / CSRF | PASS WITH LIMITATIONS | Go uses `pc_go_sessions` and `pc_sid`; Laravel session continuity is not shared (documented forced re-login). A cutover continuity/recovery rehearsal remains outstanding. |
| Cache / locking / rate limiting | PASS WITH LIMITATIONS | Laravel-compatible DB lock for Sheets config mutations; some Go rate limits and shipping caches are per-process (deviation register D-5/D-11), unsuitable for multi-instance without shared storage. |
| Laravel queue jobs | GAP | Laravel has `SendWhatsAppMessageJob` and `SendTransactionalEmailJob`. Go has DB job claim/dispatch skeleton, but `Handler` has no concrete implementation, no worker command/entrypoint, and no complete transactional email enqueue/send path. Do not run Laravel and Go consumers on the same queue simultaneously. |
| WhatsApp/email notifications | GAP | Laravel observers/notifiers/jobs are active. Go has settings/test-send and recipient inbox components, but lifecycle notification dispatch and actual durable delivery worker are incomplete. |
| Admin notification observers | GAP | Laravel observes failed/unknown transactional WhatsApp rows and Agent WhatsApp connected-state changes. No equivalent Go observer/outbox was found. |
| Scheduler | NOT_APPLICABLE (verified) | `backend/routes/console.php` registers only the `inspire` command; Go scheduler inventory reports zero scheduled application events. |
| Artisan operational commands | GAP for Laravel archival | 17 command classes remain in PHP. They include read-only reports/audits, import/reconcile/backfill operations, retention, and destructive DEV/reset tooling. No equivalent Go CLI inventory exists; classify/port safe operational commands before any PHP archive/removal. Destructive commands must retain their existing safety gates. |
| Installer/bootstrap | PASS WITH LIMITATIONS | Go installer package and route differential exist. First-install filesystem/database rehearsal in an isolated disposable environment is not repeated here. Laravel remains intact. |
| Media upload/storage/protected streaming | PASS WITH LIMITATIONS | Go media primitives and tests exist, and protected proof route is in the matrix. Cross-runtime restore, filesystem permissions, and production storage behavior were not tested here. |
| PDF invoice / XLSX export | PASS WITH LIMITATIONS | Go document/export packages and unit tests exist. Full generated-file parity and production-scale output were not re-run in this audit. |
| Google Sheets | GAP | Failure-path differential only; successful write, protected manual-column merge, validation, and row-cap behavior unverified and source implementation incomplete. No live Google credentials/provider call was used. |
| External shipping providers | PASS WITH LIMITATIONS | Go local stub tests cover provider calculations/guards; real provider credentials and live sandbox calls were not exercised. |
| GOWA | PASS WITH LIMITATIONS | Prior endpoint harness evidence uses local gateway stubs. Real configured delivery is not verified; delivery worker gap remains. |
| SMTP | PASS WITH LIMITATIONS | Prior local SMTP harness evidence exists for test endpoints. Transactional queue worker integration is not complete. |
| File uploads / database side effects | PASS WITH LIMITATIONS | Existing scoped endpoint harnesses compare selected response/DB/file state; they do not establish every lifecycle observer effect. |
| Logging / audit | PASS WITH LIMITATIONS | Handler-level activity/audit writes are compared by scoped harnesses; lifecycle notification/audit observer coverage needs systematic trace. |
| Artisan migration / operational bootstrap | GAP for PHP-free operation | Laravel owns the business schema migrations and remains the documented migration authority. A future PHP-free runtime needs a verified migration/provisioning procedure, not deletion of Laravel migrations. |

## Scoped verification actually run in this audit

All commands below ran locally; no full suite was run.

- `python3 tools/parity/gen_matrix.py > /tmp/imp010-matrix-audit.md` — PASS; 375 unique route records, initial classification 375 GO before source audit corrections.
- `go test ./internal/jobs ./internal/oauth ./internal/document ./internal/export ./internal/install ./internal/installer ./internal/media ./internal/notifications` — PASS (packages with no tests reported as such).
- `go vet ./internal/jobs ./internal/oauth ./internal/document ./internal/export ./internal/install ./internal/installer ./internal/media ./internal/notifications` — PASS (command completed with exit 0).
- `go test ./internal/api` — PASS (cached).
- The audit did not run a differential harness or any Full Test. The last committed Google Sheets sync evidence is `compare_sheets_sync.py` 7/7, limited to missing-config, invalid-mapping and missing-credential failure behavior.

## Business invariants and security

- No business rule was changed by this audit.
- No new payment gateway was introduced; the system continues to use manual payment workflows.
- Existing documentation and source retain Order-first financial locks, canonical inventory lock ordering, append-only ledger evidence, idempotency keys, tenant scoping, and CSRF/RBAC checks.
- The audit found no evidence permitting an invariant change. Financial/inventory concurrency correctness remains dependent on the existing scoped real-database tests; no fresh Full Test evidence is claimed.

## Full Test authorization and status

The Owner's 2026-11-21 attachment explicitly supersedes the earlier Full Test prohibition for IMP-010. **No Full Test has been run yet.** Run it only after the known parity gaps are corrected, using the test-only database and single-runner lock in `docs/14-TESTING-STATUS.md`. Capture Go tests, Laravel suite, differential harnesses, frontend checks, security/concurrency evidence, and external-provider sandbox results as separate evidence. A test report must list failures and skips rather than changing expectations to force green.

## Cutover readiness gates

| Gate | Status |
|---|---|
| 375 route inventory covered | PASS (route mapping only) |
| Canonical matrix 375/375 GO | FAIL — corrected to 351 GO / 24 PARTIAL / 0 Laravel-only |
| Zero-omission audit | BLOCKED — observer/queue/CLI map incomplete |
| Non-HTTP parity | GAP — worker and lifecycle notification delivery incomplete |
| Full Test | PENDING |
| Financial/inventory business invariants | NOT VERIFIED for cutover |
| No duplicate worker processing | BLOCKED — Go worker entrypoint/ownership strategy absent |
| DB restore/schema compatibility rehearsal | PENDING |
| Session continuity/re-login rehearsal | PENDING |
| External integration configuration and success paths | PENDING |
| Backup and restore evidence | PENDING; no backup action performed |
| Reverse proxy/routing and observability rehearsal | PENDING |
| Rollback rehearsal preserving post-cutover writes | PENDING |
| Production cutover | NOT ATTEMPTED; Laravel and all containers remain running |

## EXACT NEXT ACTION

Implement a single-writer Go notification pipeline before any cutover: (1) port Laravel lifecycle event selection and after-commit dispatch to an idempotent outbox; (2) implement concrete WhatsApp and transactional email job handlers plus a worker entrypoint with exclusive queue ownership; (3) add stubbed provider tests and real-database idempotency/concurrency regressions; (4) extend differential harnesses to compare notification rows, queued jobs and failure notifications; (5) port Google Sheets `replace`/manual-column merge and the full `transaction_worksheet` projection, correct the 10,000-row bound, and add a local OAuth+Sheets stub differential; (6) regenerate the matrix only after each affected route's response, DB, queue and provider behavior is verified. Then classify and port required operational Artisan commands, and re-evaluate all cutover gates. Do not cut over while any gate above is BLOCKED or PENDING.
