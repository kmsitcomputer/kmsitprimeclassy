# IMP-010 GLM CHECKPOINT — ZERO-PARTIAL REMEDIATION (PAUSED 2026-11-21)

Status: **PAUSED by Owner request.** All implementation state below is exact.

## Objective & phase

Master mandate: **375 GO / 0 PARTIAL / 0 Laravel-only** via autonomous
audit→implement→test→differential→re-audit. Currently in
**Phase 3/4 (notification foundation + lifecycle integration) — wiring complete,
verification partially done, NOT yet committed.**

## Repository state

- Branch `imp010/go-backend`, HEAD `47c5438` ("feat(notifications): payment
  proof-uploaded + payment-failed + return-requested lifecycle events").
- Working tree: **14 files changed/untracked — WIP, DO NOT DISCARD** (mandate §2.1).
- Containers: `pc_laravel_imp010` (8899) + `pc_go_imp010` (8082) running on
  shared `primeclassy_testing` (mariadb `primeclassy-mariadb`). Docker host was
  restarted externally once during the session — if parity endpoints are dead,
  `docker start pc_laravel_imp010 pc_go_imp010`.
- Baseline matrix at HEAD: **351 GO / 24 PARTIAL / 0 Laravel-only** (audit
  `docs/21aa-IMP010-POST-MIGRATION-AUDIT-20261121.md` is authoritative).

## Committed this session (verified evidence)

`47c5438` — Batch A: in-app admin notifications:
- `notifications.RecordPaymentProofUploaded` (Keuangan-of-branch only, dedupe
  `payment.proof:{verificationID}:{proofHash}:{recipientID}`), `RecordPaymentFailed`,
  `RecordReturnRequested`; wired in `orders/payment.go` (proof upload + verify reject)
  and `orders/return_request.go`.
- `compare_payments.py` extended with `admin_notifications` state query →
  **16/16 PASS** (twice). `compare_return_request.py` extended (notifications compare
  + TestDatabaseLock) → **9/9 PASS**.
- Documented: `refund.requested` (pending obligation) is UNREACHABLE — every Laravel
  `createForwardObligation` caller uses `autoApprove=true`. No Go change needed.

## Uncommitted WIP (build + `go vet` PASS: `go build ./...` clean)

**New package `go-backend/internal/whatsappnotify/`** — full Laravel notification
engine port (files: events.go, templates.go, settings.go, resolver.go, vars.go,
recipients.go, ledger.go, notifier.go, proof_wa.go, email.go, email_senders.go,
email_finance.go, wa_events.json):
- Event catalog dumped **verbatim from the Laravel runtime** (`/tmp/dump_events.php`
  → `wa_events.json`, 16 events + FOOTER).
- MessageTemplates port (line-drop on missing optional var, `nama_pelanggan`→
  "Pelanggan" fallback, brace neutralisation, 200-rune cap).
- Per-agent event switches (`agent_whatsapp_event_settings` → global
  `wa.event.*`, default OFF), template overrides, max_attempts (1..5 def 3).
- OrderRecipientResolver + WhatsAppNumberProof::msisdnFor + RestrictedDeliveryPolicy
  (fixed server text template, checkout-recipient status-only allow-list).
- Ledger enqueue/skip (`whatsapp_messages` + `jobs.EnqueueWhatsAppTx`) inside the
  caller's transaction (rollback notifies nobody).
- Email ledger (`email_messages` + `jobs.EnqueueEmailTx` — NEW
  `go-backend/internal/jobs/email_dispatch.go`, Laravel-compatible
  SendTransactionalEmailJob payload), 16 subjects, PROOF_BODY, plainBody/subject
  ports, verified-email gate, finance recipients.
- Entry points: `NotifyOrderEvent`, `NotifyReturnEvent`, `NotifyRefundPayout`,
  `NotifyObligationEvent(ByID)`, `NotifyPayoutByID`, `NotifyPaymentRejected`,
  `NotifyPaymentProofUploadedWhatsApp`, `NotifyEmailOrderEvent`,
  `NotifyEmailPaymentProofUploaded`, `NotifyOrderStatusChanged`.

**Wired mutation sites (all in-transaction, gated by wa.event.*/smtp.transactional_enabled
which are OFF in the isolated env, so no observable rows, existing harnesses unaffected):**

- `orders/payment.go` `recalculatePaymentStatus`: central hook that dispatches
  payment_paid/payment_partial (covers verify-approve/settle/installment/COD);
  proof upload dispatches WA+email finance lanes; verify reject dispatches WA+email
  payment_failed.
- `orders/lifecycle.go` UpdateStatus + cancel; `dispatch/shipments.go` (shipment
to order status); `orders/fulfillment.go` (fulfillment to order status) dispatch
  order_processed/shipped/delivered/cancelled on both channels.
- `orders/return_review.go`: approved dispatches RETURN_APPROVED (reject is silent,
  Laravel parity; RETURN_REJECTED is the completed+all-rejected path, unreachable on review).
- Refund obligations dispatch REFUND_APPROVED at: `financial.go`
  createForwardObligation (created-approved), `lifecycle.go` +
  `cancellation_financial.go` (cancellation auto-approved), `refund_obligation.go:45`
  (pending to approved) and `:537` (needs_verification to approved); `:549`
  needs_verification to cancelled dispatches REFUND_REJECTED.
- `orders/refund_obligation.go:375` payout proven dispatches REFUND_COMPLETED;
  `return_refund.go:249` is `legacy_unverified` and correctly dispatches NOTHING.
- `admininfra.SendAccountMail` (NEW) + `auth/service.go` registration welcome email
  (dashboard-SMTP-gated, process-lifetime dedupe map; Laravel uses a 30-day file
  cache, same observable effect); `auth.NewService().WithAppKey(cfg.AppKey)` wired
  in `api/server.go`.

## Verification state

- `compare_payments.py` re-run AFTER the full lifecycle wiring: **CONFIRMED
  16 passed / 0 failed** (result in `/tmp/pay2.out`) - no regression from the
  whatsappnotify wiring (channels gated OFF in the isolated env, in-app rows match).
- NOT yet re-run: `compare_fulfillment.py`, `compare_return_request.py`,
  returns/review/refund harnesses, orders harness.
- Unit/integration: `go test ./internal/notifications` PASS; no new tests written
  for whatsappnotify yet.

## EXACT NEXT ACTION (resume here)

1. `cat /tmp/pay2.out` (or re-run `python3 tools/parity/compare_payments.py`); must
   be **16 passed, 0 failed**. If FAIL: diff the admin_notifications/
   whatsapp_messages rows (wa.event.* is OFF so channels are silent; only the
   in-app rows from Batch A can differ).
2. Re-run `python3 tools/parity/compare_fulfillment.py` and
   `python3 tools/parity/compare_return_request.py` (no-regression on order-status
   and return wiring).
3. Add focused differential evidence for the NEW lanes: temporarily set settings
   `wa.event.order_created='1'` (+ `wa.enabled='1'`) and
   `smtp.*`/`smtp.transactional_enabled='1'` in `primeclassy_testing`, run an
   order-create scenario on both runtimes, compare `whatsapp_messages`/
   `email_messages`/`jobs` rows (scrub ids/timestamps), then DELETE the settings
   rows. Without this, the engine is ported but has no differential proof.
4. Implement the `jobs.Handler` concrete worker (HandleWhatsApp: the
   WhatsAppDelivery::deliver port — claim queued to sending, gateway-config check
   to skip gateway_disabled, proof re-check, RestrictedDeliveryPolicy check, agent
   device slot, volume throttles, GOWA sendText, apply result; HandleEmail: the
   SendTransactionalEmailJob::handle port — claim queued to sending, smtp re-check
   at send time, SMTP send via admininfra, failure records error_class/message)
   + worker entrypoint (e.g. `cmd/worker`), graceful shutdown, ONE runner at a time.
   NOTE: no GOWA sendText adapter exists yet in Go (only a status probe in
   admininfra) — port GowaClient::sendText from
   `backend/app/Services/WhatsApp/GowaClient.php` (+ GatewayResult semantics).
5. Google Sheets sync differential (route 280, last PARTIAL blocker): fake/stub
   provider harness comparing sync-log success/failure + provider payloads
   (`compare_sheets_sync.py` currently only covers the credential-gate failure path).
6. Update `tools/parity/gen_matrix.py` notes, flip verified routes GO, regenerate
   `docs/21a-IMP010-API-PARITY-MATRIX.md`, commit; re-audit all 375 routes
   (Batch H); final report per mandate section 19.

## Locked decisions (this session)

- Notification dispatch runs INSIDE the business transaction (atomic with the
  mutation). Laravel defers to after-commit; the committed set is identical, and
  the atomic variant can never strand a job on rollback. Documented in code.
- `NotifyOrderStatusChanged` no-ops unless the status VALUE changed (mirrors
  `wasChanged`); reject-review fires no return event (Laravel parity).
- WhatsApp/email channels stay OFF by default (`wa.event.*`, `smtp.transactional_enabled`)
  exactly like Laravel — enabling them is an operator action, not a code path.
- Registration email dedupe = in-process map (documented deviation from Laravel's
  30-day file cache; no schema addition without checking migrations).

## Risks / open findings

- compare_payments post-wiring CONFIRMED 16/0; fulfillment/returns harness re-runs still outstanding.
- `link_pesanan` var: Go falls back APP_FRONTEND_URL, then FRONTEND_URL, then
  APP_URL; Laravel uses APP_FRONTEND_URL, then APP_URL. Both containers' APP_URL
  is `http://127.0.0.1:8899` so URLs match in differential, but a FRONTEND_URL env
  set only on Go would diverge.
- `crm_orders` table assumed for `isCrmOrigin` (vars.go) — verify the table/column
  used by Laravel `Order::isCrmOrigin` before trusting CRM-origin skips in harnesses.
- No worker yet: queued `whatsapp_messages`/`email_messages` rows never send —
  acceptable for matrix parity (rows+jobs are the observable contract) but required
  for the mandate's durable-delivery completion criterion.

## Production state

Untouched (no deploy/push/mutation). Laravel runtime intact.

## Constraints reminder

Test DB `primeclassy_testing` only; ONE harness runner at a time; restart
`pc_go_imp010` after any Go change (`docker restart pc_go_imp010`, wait ~17s);
harnesses with login-throttle retries take minutes — poll `/tmp/*.out`, do not
stack runners.
