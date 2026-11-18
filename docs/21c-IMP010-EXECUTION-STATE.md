# 21c — IMP-010 execution state and resumable continuation record

**Written at commit `0469508` + checkpoint I (admin configuration).** This file exists because the 100% target is
**not reached**: 327 of 375 routes are still Laravel-only. It records exactly where the work stands, what was
verified, and what remains — so the next run continues rather than re-deriving.

Do not read this as a completion report.

## 1. Verified position

| Metric | Value |
|---|---|
| Routes `/api/v1` in the baseline | 375 |
| Served by Go | **48 (12.8%)** |
| Fully GO | 42 |
| PARTIAL (documented gap) | 6 |
| Laravel-only (Go answers 501) | **327** |
| Go tests | 82+ green, `go vet` clean, gofmt clean |
| Cross-runtime comparison checks | 133 passing (26 public + 24 auth + 29 inventory read + 37 inventory mutations + 17 admin config) |
| Laravel files modified by IMP-010 | **0** |
| Production / remote side effects | none |

## 2. Domains completed and verified

| Domain | Routes | Evidence |
|---|---|---|
| Identity, session, CSRF, RBAC | 6 | `compare_auth.py` (24) |
| Public catalog, content, site config | 16 | `compare_public_api.py` (26) |
| Inventory reads (canonical sellable + grids) | 13 | `compare_inventory.py` (29) |
| Inventory mutations (transfers, adjustment gate) | 8 | `compare_inventory_mutations.py` (37) + 7 two-connection concurrency tests |
| Admin system configuration (languages, settings) | 7 | `compare_admin_config.py` (17) |
| Ops (`/up`, `/healthz`, parity report) | 3 | integration tests |

## 3. What remains (327 routes) — ordered, with the real obstacle for each

The order below is dependency-correct. **Start at the top of §3.1.**

### 3.1 Phase A remainder — inventory (41 routes)

| Area | Routes | Obstacle / notes |
|---|---|---|
| Stock opnames | 8 | `StockOpnameService::approve` is the hardest single function ported so far: per-item `system_quantity` stale-check with **409** ("Stock changed after this opname was counted"), `PhysicalStockRevision::current()` comparison, Sub physical-decrease floor via `SubStockService::assertPhysicalDecreaseAllowed`, plan-reconciliation bounds, **and** publishing `pending_catalog_change` through `CatalogImportService::applyChange` inside the same transaction. Cannot be done correctly without `PhysicalStockRevision` + `CatalogImportService` first. |
| Stock requests / proposals / handovers | ~20 | `StockRequestProposalService` (propose/approve/receive/settle/resolveDiscrepancy) — the §16/§31 two-party gate. Requires the `stock_requests*` chain plus `StockTransfer` handover linkage (already built). |
| Sub stock-request workflow | 6 | `SubStockRequestService` (create/approve/execute/receive/cancel/reject) — needs `stock_transfers` execution (built) + `SubStockService` reservation lifecycle. |
| `POST /warehouse/transfers/plan-to-transit` | 1 | `StockTransferService::createPlanTransfer` — small, blocked only on nothing; do it first. |
| `GET /warehouse/sub-locations/eligible-owners` | 1 | Simple query (`Manager-of` filter + `whereDoesntHave ownedSubLocation`). |
| `PATCH /warehouse/sub-locations/{id}` + `/assign-owner` + `/deactivate` | 3 | `SubLocationOwnershipService` — locks the owner user row then the location; `previous_owner_user_id` history. |

### 3.2 Phase B — orders & fulfilment (43 routes)

`POST /orders` is the single most consequential endpoint in the system. Port in this order:

1. `OrderTotalCalculator` + `PricingService` + `FeeService` (money: exact decimal, `round(.., 2)` at the same
   points as the reference).
2. `StockService::reserveFor*` + `provisionProductCommitment` + `lockReservationTargets` (canonical order
   **already extracted** in `internal/inventory/transfers.go` — reuse `sortTargets`/`targetKey`, do not write a
   second ordering).
3. `OrderService::create` incl. the `Idempotency-Key` replay (`UNIQUE(konsumen_id, key)`, `UNIQUE(crm_customer_id,key)`).
4. `OrderFulfillmentService` + `FulfillmentShortageService` + `ShipmentCanonicalizationService`
   (note the two DB triggers on `shipments`; they run in MySQL and need no Go code, but the CHECK
   `shipments_delivery_mode_consistent` must be honoured).
5. `CourierService` (+ commission writes), `DispatchController`, `DeliveryVerificationService` (`UNIQUE(verified_by,idempotency_key)`).

### 3.3 Phase C — financial (~40 routes)

**Do not start before the Owner confirms the money type (D-02).** The reference mixes `decimal(12,2)` + float
`round()` for orders/payments with integer cents inside `Refund/Money.php`. Pick one at the boundary and test
rounding edge cases (`.005`, negative adjustments) against the reference.

Highest-risk units: `RefundLedgerService` (14 Order-first lock transitions), `RefundObligation::remaining()`
(unlocked read — must not become an independently callable read model), `InvoiceNumberService` (row-locked
sequence in `settings`, 409 on namespace exhaustion), `CommissionFinalizer` (idempotent on `pending`),
`SalesFeeRefundAdjuster` (`UNIQUE(commission_id, return_item_id)`).

### 3.4 Phase D — remaining application domains (~150 routes)

| Area | Routes | Notes |
|---|---|---|
| admin (agents, SMTP, WhatsApp gateway, shipping config, regions import/export, audit log, media, refunds, additional payments, order adjustments) | 52 | Mostly CRUD; `MediaController` needs the upload validation set (MIME + extension + `getimagesize` + dimension caps) |
| agent (contacts, shipping providers/tariffs, store profile, payment methods, Google auth, WhatsApp) | 33 | `encrypted` casts must become a Go encryption boundary — **not** plaintext columns |
| products management (+ variations, images, fees, bestsellers, import/export) | 17 | `ProductHardDeleteService` uses `information_schema` guards; `HtmlSanitizerService` is used for descriptions |
| reports | 17 | Many use `DATE_FORMAT`/`JSON_EXTRACT`; the Go port must keep the same output strings |
| CRM + consent | 16 | `ConsentService`/`CrmAccess`; consent events are append-only |
| CMS admin (articles, pages, homepage blocks) | 14 | **Blocker: `HtmlSanitizerService`.** The reference uses HTMLPurifier with a specific allowlist. A Go sanitizer (e.g. bluemonday) will not produce byte-identical output; this needs either a documented deviation with fixture tests, or an allowlist port. Block create/update additionally need multipart image handling. |
| profile (referral code, WhatsApp/email verification) | 11 | OTP challenges (`identity_otp_challenges`), rate-limited; `phone` change invalidates prior proofs |
| promo (vouchers, discounts) | 8 | Straightforward CRUD; `resolveAgentId` scoping |
| users | 8 | `UserManagementService` — closure rebuild on parent change, referral-code generation |
| dispatcher/kurir/shipments | 24 | Depends on Phase B |
| install (8) | 8 | Owner decision needed: keep the installer in Go, or drop it (a Go-only deployment can be provisioned by env + migrations). `EnvFileWriter`/`SafeSchema` are pure bootstrap. |
| google-sheets (7) | 7 | Service-account JWT; needs a Go Sheets client |
| addresses, categories admin, regions | 9 | Simple CRUD |

### 3.5 Phase E — non-HTTP (no route count, still mandatory before removal)

| Item | Notes |
|---|---|
| Queue + 2 jobs | `SendWhatsAppMessageJob`, `SendTransactionalEmailJob` — `$tries=1`, claim-then-send, dedicated lane. Requires a Go worker with the same claim semantics. |
| Scheduler | The reference has **no** scheduler (`routes/console.php` = `inspire`), so nothing to port; confirm before removal. |
| Notifications | `WhatsAppLifecycleObserver`, `AdminNotificationObserver`, `OrderNotifier::defer` (after-commit only) |
| Media/protected streaming | `GET /payment-proofs/{id}` — authorization-before-stream + `private, no-store` |
| PDF | `invoice.blade.php` → HTML→PDF engine; 10 PDF fixtures in `docs/unified-rem-evidence/invoice/after/` are the contract |
| Excel/Sheets | `PhpSpreadsheet` exports + Sheets push (10k row fail-closed bound) |
| Google OAuth | state/nonce/PKCE, 10-minute single use, tokens not persisted |

## 4. Blockers that need a decision (not ordinary implementation detail)

| # | Question | Why it cannot be derived |
|---|---|---|
| B-1 | **Money type for Phase C** — `decimal` + exact decimal arithmetic (current), or integer minor units | Changes every financial query and the rounding boundary; the reference is internally inconsistent |
| B-2 | **`HtmlSanitizerService` parity** — accept a Go sanitizer with a registered output deviation, or port the allowlist | Output differs byte-for-byte; affects 4 CMS/description write paths |
| B-3 | **Installer** — port to Go, or replace with env-driven provisioning | Product decision about first-run UX |
| B-4 | **Session continuity at cutover** — accept one forced re-login (documented as D-4), or implement Laravel cookie decryption | Affects every logged-in user at cutover |

## 5. How to resume (exact steps)

```bash
cd /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go
git log --oneline -3                     # expect 0469508+ and the checkpoint-I commit
docker start primeclassy-mariadb primeclassy-fpm 2>/dev/null

# 1. Rebuild the isolated test DB from THIS worktree's migrations (never DEV):
docker run --rm --network primeclassy-net -v $PWD/backend:/app \
  -v /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy/backend/vendor:/app/vendor \
  -w /app --entrypoint php kmsit-php:8.3-fpm artisan migrate --env=testing --force

# 2. Seed fixtures
for f in tools/parity/seed_test_fixtures.sql tools/parity/seed_inventory_fixtures.sql; do
  docker exec -i primeclassy-mariadb sh -lc \
    'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing' < $f
done

# 3. Run both runtimes
docker run -d --name pc_laravel_imp010 --network primeclassy-net -p 8899:8899 \
  -v $PWD/backend:/app -v /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy/backend/vendor:/app/vendor \
  -w /app --entrypoint php kmsit-php:8.3-fpm artisan serve --env=testing --host 0.0.0.0 --port 8899
cd go-backend && docker run -d --name pc_go_imp010 --network primeclassy-net -p 8082:8082 \
  -v $PWD:/src -w /src -e APP_URL=http://127.0.0.1:8899 golang:1.27.4-alpine \
  sh -c 'export PATH=$PATH:/usr/local/go/bin; go run ./cmd/api'

# 4. Baseline before any new domain
cd .. && for s in compare_public_api compare_auth compare_inventory compare_inventory_mutations compare_admin_config; do
  python3 tools/parity/$s.py | tail -1; done
```

Then implement the next unit from §3.1 (`POST /warehouse/transfers/plan-to-transit`), following the established
pattern: **capture from the running Laravel → implement → compare responses AND database state → add invariant/
concurrency tests → register any deviation → bump `gen_matrix.py` → commit**.

## 6. Laravel removal gate — status

| Gate | Status |
|---|---|
| 375/375 implemented | ✗ 48/375 |
| Zero PARTIAL | ✗ 6 |
| Non-API functionality in Go (queues, PDF, Sheets, OAuth, protected streaming) | ✗ not started |
| Vue SPA + Nuxt verified against Go | ✗ not attempted |
| Recoverable Laravel archive created | ✗ not created (must be done **before** any deletion) |
| Laravel removed from the active repo | ✗ **deliberately not done** — removing it now would delete 87% of the working backend |

The archive step, whenever it is authorised, should be: `git branch archive/laravel-imp010-final <last-commit-with-backend>`
**plus** an external `tar` of `backend/` with a recorded digest, because Git history alone does not protect
untracked runtime files (`storage/app/installed.lock`, uploads).
