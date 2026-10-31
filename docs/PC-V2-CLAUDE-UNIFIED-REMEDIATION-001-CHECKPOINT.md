# PC-V2-CLAUDE-UNIFIED-REMEDIATION-001 — Checkpoint

Temporary working checkpoint (AGENTS.md §8). Fold into the authoritative docs / remove when the Owner closes the work.

## Objective / phase
1. Inventory + remediate Owner Full Test failures (backend 211 FAIL, frontend 6 FAIL) by root cause.
2. Add read-only **Nama Korsal** to *Akun Saya* for `sales` and `sales-kurir-sub`.
3. DEV-local only. No production action, no commit/push, **no Full Test run by the agent** (Owner runs Full Test).

Phase: remediation implemented; scoped tests run; awaiting Owner Full Test.

## Branch / baseline
- Branch `main`, HEAD `38cc75d` (= production release baseline). All changes uncommitted in the working tree.
- Pre-existing WIP preserved untouched: `docs/MASTER-V2-15-IMPROVEMENTS-CHECKPOINT.md` (modified), all untracked files listed in `git status` before the session (logs, `artifacts/`, `design/`, `docs/*` audits, `frontend/pnpm-lock.yaml`, `frontend/src/api/cms.ts.bak-*`, and the stray `backend/2048])!` shell artefact).

## Evidence sources (read-only)
- `primeclassy-fulltest-backend-20261031.log` — `Tests: 211 failed, 1297 passed (10434 assertions)`, 9401.35s.
- `primeclassy-fulltest-frontend-20261031.log` — `Tests 6 failed | 117 passed (123)`, 6 unhandled errors.
- All 211 backend FAILED blocks were parsed (header + assertion + "exception during the last request"); 211/211 accounted for, 30 test classes.

## Backend root-cause matrix (211)

| # | Root cause | Class | Count | Evidence | Action |
|---|---|---|---|---|---|
| RC-1 | Stale fixtures: a **konsumen** places a COD/DP order. Owner rule *Improvement 04* (BUSINESS-RULES §594, `AvailablePaymentMethodService::KONSUMEN_ALLOWED_CODES`, enforced in `OrderService` since `38cc75d`) makes konsumen Bank-Transfer-only; release `38cc75d` migrated ~45 test files but not these. | Test fixture | 188 (174 HTTP 422 + 5 direct `ApiException` + 9 cascaded `ModelNotFoundException` where `->json('data.id')` was null) | `messages.payment.method_not_available_for_role` at `OrderService.php:263` | Fixture fix, no source change (see below) |
| RC-1b | `AgentScopedSettingsTest` asserted a konsumen *sees* COD in `/checkout/steps` | Test fixture | 2 | `Failed asserting that false is true` (:164, :187) | Observe the per-agen COD toggle through branch Sales (who keeps COD) |
| RC-2 | `openssl_pkey_new` fails: Windows PHP has no `OPENSSL_CONF`; `openssl.cnf` exists at `<php>/extras/ssl/openssl.cnf` | Environment | 19 (`GoogleAuthTest`) | `error:07000072:configuration file routines::no such file` reproduced with `php -r` | Test key helper falls back to the bundled config when `OPENSSL_CONF` is unset |
| RC-3 | `homepage_block` limit intentionally raised 2 MiB → 10 MiB in `38cc75d` (§601 promotional banner); test still uploads 5000 KB expecting 422 | Test fixture | 1 (`MediaSystemTest`) | `Expected 422 but received 201` | Upload 11000 KB, comment updated |
| RC-4 | `SubCheckoutConcurrencyTest::test_real_sub_checkout_and_sub_execution_never_deadlock_and_keep_invariants` — the `38cc75d` codemod in the concurrency actor (`backend/.phpunit-concurrency-actor.php`, op `checkout-order`) made the branch **agen** the actor for **every** buyer; for an SKS self-checkout from Sub stock, `StockSourceResolver` refuses a non-SKS actor (403), so one race side was rejected | Test harness defect (reproduced locally, deterministic) | 1 | `both operations have legitimate capacity and must succeed — 1 is identical to 2` | Agen substitutes the actor only for a konsumen buyer; staff buyers keep the requested `actor_id` |

### RC-1 fix rule (no business rule touched)
New test trait `backend/tests/Concerns/ResolvesCheckoutActor.php`:
- `checkoutActorFor($buyer, $method)` / `postOrderAs($buyer, $payload, $key)`: only when the buyer **is a konsumen AND the method is not `bank_transfer`**, the buyer's branch agen places the order with `konsumen_id` (exactly the precedent `38cc75d` used in `DownPaymentTest`/`DispatchTest`/`CourierFeeSplitTest`). Bank Transfer and staff self-purchases keep the original actor. Buyer, stock source (konsumen always draws Agent stock — `StockSourceResolver`), fee chain (from buyer, `OrderService::recordCommission`) unchanged.
- `configureBankTransfer($agentId)`: for tests whose **subject is the konsumen as actor**, the fixture switches to `bank_transfer` (the only method a konsumen may use) instead of changing the actor, so negative/security assertions are not masked: `SubStockSourceTest` (referred self-checkout + DB consistency), `SensitiveFieldMassAssignmentTest` (konsumen payload injection), `SelfPurchaseAndFeeAttributionTest` (`konsumen can checkout`, forged `sales_id`).
- Service-level fixtures (`CancellationReplenishmentConcurrencyTest`, `OrderInventoryTargetResolutionTest`, `PaymentProofConcurrencyTest`) pass the branch agen as `$actor` for COD only.
- Replacements were applied only at the evidenced failing call sites, not file-wide.

## Frontend (6 FAIL + 6 unhandled) — single root cause
`ReceivablesView.spec.ts` mocked the **pre-release** contract (client-side filtering, no `meta.summary`). The view (since `38cc75d`) delegates `receivable`/`search`/`due_filter`/`page` to `GET /reports/finance-orders` and renders `meta.summary`, which `ReportService::financeOrders` always returns. `meta.summary` undefined → `TypeError ... reading 'count'` at `ReceivablesView.vue:51` → nothing rendered → all 6 assertions failed. Class: stale mock (no product defect). Spec rewritten against the real contract (server-mirroring mock, request params, summary, pagination, error, navigation). One assertion changed meaning by contract: a fully-paid order is not a receivable, so searching `D-3` now yields the empty state.

## Nama Korsal (Sales / SKS)
- Source of truth: canonical denormalised `users.korsal_id` (`User::korsal()`, the same field used for Korsal order scope, BUSINESS-RULES §41). No `parent_id` fallback, no new table/column.
- Backend `UserResource.korsal_name`: included only when the resource is the **authenticated user's own record** and the role is `sales`/`sales-kurir-sub` (`isRole` canonicalises legacy `sales-kurir`); value is the name only if `korsal_id` is a `korsal` of the **same agent_id**, else `null`. Staff viewing a seller (`GET /users/{id}`) never get it.
- `ProfileController::update` validation already whitelists `name/phone/email/avatar_media_id` → `korsal_id`/`korsal_name` in the payload are dropped (regression test added).
- Frontend `ProfileView.vue`: read-only block (`data-testid="profile-korsal"`) under Email, only for `sales`/`sales-kurir-sub`; shows `Belum ditugaskan` when null/absent; not part of `profileForm`. i18n keys `profile.korsalName` / `profile.korsalUnassigned` in id/en/ar/zh. `AuthUser.korsal_name?` type added.

## Files modified
Source: `backend/app/Http/Resources/UserResource.php`; `frontend/src/views/ProfileView.vue`; `frontend/src/api/types.ts`; `frontend/src/i18n/locales/{id,en,ar,zh}.ts`.
Tests (new): `backend/tests/Concerns/ResolvesCheckoutActor.php`, `backend/tests/Feature/ProfileKorsalNameTest.php`, `frontend/src/views/__tests__/ProfileView.spec.ts`.
Tests (modified): 29 backend Feature tests (see `git status`), `backend/.phpunit-concurrency-actor.php` (test harness), `frontend/src/views/dashboard/__tests__/ReceivablesView.spec.ts`.
Migrations: none.

## Scoped commands and results
Backend (testing DB `primeclassy_testing`, guarded; `php.exe` called directly because `php.cmd` breaks `|` in `--filter`):
- `php artisan test tests/Feature/OrderPaymentBadgeTest.php` → 6 passed.
- RC-1 group, 22 classes via `--filter "/Tests\\Feature\\(...)::/"` → first run `1 failed, 273 passed (1627 assertions)`; the one failure was a second konsumen-COD helper (`SalesKurirSubSelfDeliveryTest::secondBranchDeliveredOrder`) hidden behind the caller line in the Owner log → fixed and re-run in group 2.
- Group 2 (SalesKurirSubSelfDelivery, AgentScopedSettings, GoogleAuth, Media, CancellationReplenishmentConcurrency, OrderInventoryTargetResolution, PaymentProofConcurrency, SubCheckoutConcurrency) → `1 failed, 73 passed (534 assertions)`, 1103.49s; the failure = RC-4 (reproduced) → actor-script fix.
- Group 3 (SubCheckoutConcurrency + every other `checkout-order` user: CheckoutConcurrency, MultiTargetInventoryLockOrder, DispatchAssignmentConcurrency; plus ProfileKorsalNameTest) → `27 passed (483 assertions)`, 1928.51s.
- Net: every one of the 30 classes that failed in the Owner log passed in scoped runs after the fixes.
Frontend:
- `npx vitest run src/views/dashboard/__tests__/ReceivablesView.spec.ts src/views/__tests__/ProfileView.spec.ts` → 2 files, 20 passed, 0 errors.
- `npm run type-check` → clean. `npm run build-only` → built (pre-existing chunk-size warning only).
- `git diff --check` → clean.

## Production state
Untouched. No SSH, deploy, migration, cache, Google Sheets sync, commit or push.

## Open risks
- Classes outside the 30 failing ones were not re-run; the only shared change that can affect them is the new opt-in trait (used only where added) and the `checkout-order` actor fix (all four users re-run green).
- Environment: `GoogleAuthTest` now self-heals when `OPENSSL_CONF` is unset; setting `OPENSSL_CONF` in the Owner's PowerShell profile is an equivalent environment-level fix.
- Scoped tests do not prove the Full Test; only the Owner's Full Test can confirm 0 failures.

## EXACT NEXT ACTION
Owner: run backend + frontend Full Test in PowerShell; compare against this matrix.
