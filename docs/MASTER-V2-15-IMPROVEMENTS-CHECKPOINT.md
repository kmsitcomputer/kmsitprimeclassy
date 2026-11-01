# MASTER V2 — 15 Improvements — CHECKPOINT

Branch `main`, HEAD `152e222` (all work uncommitted on top). Local DEV only. No full suite run (Owner rule). No push/deploy/production.

## PRODUCTION RELEASE EXECUTED (PC-V2-GLM-PRODUCTION-RELEASE-001, 2026-10-31) — DEPLOYED & VERIFIED

- **Release commit `38cc75d`** "release(primeclassy-v2): checkout finance cms and sheets enhancements" — 126 files, +3028/−222, atomik (termasuk `backend/config/media.php`, `backend/routes/api_v1.php`, `backend/lang/*`, `.phpunit-concurrency-actor.php`, `docs/BUSINESS-RULES.md` §29 yang sempat terlewat staging awal; file `.bak`/log/lockfile TIDAK ikut). **Pushed: `152e222..38cc75d main → main`** (remote `github-primeclassy`, tanpa force).
- **Production preflight** (read-only, SSH `kmsit` → 103.119.237.51:1210 root): stack Docker `primeccookies_nginx` (nginx:alpine, port 127.0.0.1:18084, TLS oleh aaPanel nginx :443 `client_max_body_size 100m`, certbot) + `primeccookies_php` (image custom php:8.3-fpm-bookworm, PHP 8.3.35, ekstensi lengkap) + `primeccookies_db` (mysql:8.0 healthy). Laravel v11.56.1. Disk 68G bebas. `laravel.php` utuh. TANPA queue worker & TANPA scheduler untuk primeccookies (queue sync; tidak ada risiko double worker). DB `sql_prime`: users 7, roles 11, orders 0 (pre-launch), migrations 156. `GOOGLE_SHEETS_AUTO_SYNC_CONFIGS=4,5` (legacy; sync manual via admin).
- **Backup pre-deploy** `/opt/primeccookies/deploy-backups/PRE-PCV2-20261031T035858Z/`: `sql_prime-full-…sql.gz` (gzip -t OK, 116 CREATE TABLE; md5 e946a34c5dd5b77ae884fa0618306e86), `backend-…tar.gz` (md5 ae70de7ed40a64cad5371aa12257a15e), `public_html-…tar.gz` (md5 29dfd37165f449d4bbc0a438edacb11c; laravel.php+config.js dalam tar), `compose.yaml`, `docker/` (nginx.conf+Dockerfile), vhost aaPanel `primeccookies.com.conf`, plus post-aktivasi `sheets-pre-cfg04/05-*.json` (backup worksheet pre-sync).
- **Deployment**: maintenance `down` → ekstrak `git archive HEAD -- backend` (tracked-only; `.env`/`storage`/`vendor` tak tersentuh) → `config:clear` → **migrate --force: `2026_11_02_100000…` DONE 692ms** (row id 157 batch 17; schema: `orders.due_date` date NULL, `orders.invoice_issued_at` timestamp NULL; snapshot 0 rows) → `config:cache`+`route:cache`+`view:cache` → restart FPM → konfigurasi upload: `docker/php-uploads.ini` (upload 12M/post 32M/mem 256M/exec 60) dimount via `compose.yaml` (backup `compose.yaml.bak-pcv22`), `nginx.conf` +`client_max_body_size 32m` (backup `.bak-pcv2`), `docker compose up -d php` (recreate hanya php), `nginx -s reload`, **efektif terverifikasi: upload=12M post=32M mem=256M** → frontend `dist` (build `npm run build-only`, sw v5847853972c8a052) diekstrak TANPA delete (laravel.php/config.js/storage symlink utuh) → `artisan up`.
- **Insiden kecil + perbaikan**: `/up` sempat 500 — compiled views di `storage/framework/views` bertuan root (akibat `view:cache` via docker exec root). Diperbaiki `chown -R www-data:www-data /var/www/backend/storage` (dalam container). Catatan prosedur: setelah artisan CLI via `docker exec` (root), jalankan chown storage agar FPM tetap bisa menulis.
- **Google Sheets AV DIAKTIFKAN (log #903 COOKIES_2026, #904 CAKE_2026, status success)**: preflight 47 header **byte-identical** kedua tab (13 & 2 baris); backup full JSON; **dry-run pada tab duplikat** (PCV2-DRYRUN-*) jalur `SheetsClient::replace` produksi — semua asersi PASS (48 header, AV='Status Pesanan', 12+1 baris verbatim, kolom manual imut, dry tab dihapus); aktivasi real: mapping `sheets_configs.columns` 47→48 (append `order_status`/'Status Pesanan' — sesuai validasi `columns.*.field` ⊂ registry definitions) lalu `SyncService::sync(super_admin)`; verifikasi pasca-sync: header 48, 0-46 byte-identical, baris verbatim, AV kosong (orders=0), kolom manual imut.
- **Post-deploy verification PASS**: homepage 200 + judul SPA; `/api/v1/homepage` 200 (CMS blocks), `/api/v1/products` 200 (no agent-price leak — hanya `price`/`base_price`), `/api/v1/install/status` 200, asset baru `index-DPitHCoh.js` 200; endpoint terlindungi 401; caches terbangun (config.php/routes-v7.php); maintenance file absen; **NOL error Laravel baru** sejak 11:03; invariant DB: users 7/roles 11/orders 0/migrations 157; containers: php Up, nginx Up, db healthy.
- **Rollback tersedia** (tidak diperlukan): backup manifest di atas; skema additive → app lama tetap kompatibel; NO auto-restore DB (transaksi baru → forward-fix).
- **Remaining Owner**: Full Test via PowerShell lokal (ditunda sesuai keputusan), UAT smoke interaktif (checkout render, piutang by role, invoice read), monitoring 24 jam, disposisi artifact lokal (`backend/2048])!`, log, `pnpm-lock.yaml`, `design/`). Checkpoint ini diedit pasca-deploy dan **belum di-commit** (menunggu disposisi Owner).


## Owner decisions applied (2026-10-31 resume)
1. Repair fixtures incrementally (Option 1). 2. §6 preserved. 3. Verification = `super_admin`/`keuangan`; no self-approval. 4. Invoice = `orders.order_no`; COD at creation, transfer at first verified payment; versioned snapshots. 5. New proofs private, legacy untouched. 6. Staff checkout: COD / Transfer Bank; under Transfer: Bayar Lunas / Bayar DP.

## Implemented (source)
| # | Item | State |
|---|---|---|
| 01 | GPS button above Nama Penerima (`CheckoutView.vue`) | done, not browser-verified |
| 02 | `referral` ("Info Agen") step inactive unless staff order on behalf (`CheckoutStepResolver`) — VERIFIED in diff: `REFERRAL => $onBehalfOfKonsumen` | done |
| 03 | Labels `Kurir Toko` / `Ambil ke Toko` / `Ekspedisi` (`ShippingQuoteService`, codes unchanged) | done |
| 04 | Role-based methods (backend + staff UI sub-option) | done |
| 05/06/08 | `POST /orders/{order}/payment/installment` (transfer + cash), `OrderPaymentTools.vue` | done |
| 07 | `verified_amount` + reason, no overwrite of submitted amount, no self-approval | done |
| 09 | Receivables: per-order fields + **centralized Piutang menu IMPLEMENTED (GLM, 2026-10-31)**: `ReceivablesView.vue` + route `/dashboard/piutang` + nav (super_admin/admin/keuangan) + i18n ×4 + finance-orders rows now carry `due_date`/`is_overdue` (`ReportService::financeOrders`) — SAME financial source, no second truth | done |
| 10 | `orders.due_date`, `PUT /orders/{order}/due-date`, audit table, overdue flag | done |
| 11 | `InvoiceIssuanceService`, `orders.invoice_issued_at`, `order_invoice_snapshots`, 409 before issuance | done |
| 12 | Fulfilment not gated by payment after `diproses` (existing rule kept; regression added) | done |
| 13 | Limits in `config/media.php` + FormRequests + UI messages; private proofs + `GET /payment-proofs/{id}`; MIME/content validation is content-based (finfo) for real uploads — Laravel `Testing\File` mimes are NAME-based, so the spoof test now uses a plain UploadedFile (see fixture notes) | done; PHP ini = deployment prerequisite |
| 14 | `StockBadge.vue` red/white/bold 16px→18px | done |
| 15 | Prices public incl. range (Rp0 fix) done + verified. **Discount display BLOCKED on Owner decision**: `product_discounts.agent_id` is NOT NULL (IMP-002 locked, agent-scoped; `PricingService::activeDiscount(agentId, ...)` requires an agent) — an anonymous visitor has NO resolvable discount; showing any branch's discount publicly would violate the agent-scoped pricing rule. Options: (a) keep discounts quote/checkout-only (current), (b) Owner authorizes a global/public discount design. | PARTIAL (blocked leg documented) |

## Fixture repair (Decision 1)
Codemod rewrote 98 direct `/api/v1/orders` posts in 56 files: konsumen+COD/DP → branch `agen` places the same order with `konsumen_id`. GLM 2026-10-31 completed the residual repair (see Test evidence): the codemod had left **9 broken helper sites** (undefined `$agen` variable or unconverted konsumen actor) + 2 fixture gaps (missing agen self `agent_id`) + 1 intent-weakened fee-leak test (restored to konsumen viewer via bank_transfer) + 1 self-purchase policy mismatch (ReportSystemTest helper now: konsumen buyer → agen actor; staff buyer → self actor) + 1 racing-oversell pair converted to staff actors.

## Test evidence (GLM 2026-10-31, per-file scoped runs, ALL GREEN)
MasterV2PaymentCore 13/131 · RoleBasedPaymentMethods 2/9 · Invoice 9/42 · DownPayment 14/104 · SettlementRequestIdempotency 10/137 · PaymentGateway 4/18 · Checkout 12/67 · FeeSystem 10/102 · CourierSystem 34/278 · AssistedConsumerPayment 14/88 · AssistedOrder 10/81 · ConsentOtp 13/124 · CourierFeeSplit 4/28 · CourierItemProgression 3/41 · CrmReturnConsent 11/222 · CrossAgentIsolation 7/26 · DeliveryDateGrouping 2/16 · DeliveryVerification 13/94 · DispatchDateFilter 5/42 · DispatchRegionFilter 7/48 · Dispatch 9/61 · DispatchRemediation 8/81 · DispatchAssignmentConcurrency 9/120 · AgentShippingCourierSettings 14/43 · FinancialObligationReconciliation 5/44 · FulfillmentCounterReconciliation 2/20 · FulfillmentWindowAfterShipment 4/27 · GoogleSheetsOrderReports 3/23 · KeuanganReporting 4/53 (incl. NEW Piutang data-contract test) · ProductSystem 23/95 · R04OrderProjection 13/176 · OrderFulfillment 23/174 · R04Report 4/49 · RefundBackfill 3/59 · RefundLedgerIdor 6/75 · RefundLedger 6/43 · RefundMetrics 2/42 · OrderTest 7/26 · ReportSystem 42/351 · ReturnPickupSchedule 7/50 · ReturnSystem 11/83 · SalesFeeRefundAdjustment 6/53 · SettledOrderPendingProofProjection 4/49 · ShipmentAggregateLifecycle 4/46 · ShippingProvider 30/114 · StructuredAddress 3/12 · Voucher 5/20 · WarehouseReturnDisposition 7/109 · WarehouseReturnQueue 4/56 · WhatsAppNotification 17/107 · WhatsAppOtp 11/80 · FinancialConcurrency 3/153 · OrderLineAdditionConcurrency 4/35 · RefundLedgerConcurrency 4/62.
Frontend: type-check exit 0 (isolated tree incl. all WIP + GLM fixes) · vitest PrimeFileUpload+OrderDetailView+CrmAssistedOrder 27/27 · **NEW ReceivablesView.spec 6/6**.

## Defects fixed by GLM (source, proven)
1. `OrderResource` mangled class ref `AppServicesInvoiceInvoiceIssuanceService` → `\App\Services\Invoice\InvoiceIssuanceService` (caused the "500 at CheckoutTest:349" class of failures).
2. `PaymentController::proofFile` cross-branch 500 → scope-free order load + explicit gate (403).
3. `ReportService::financeOrders` rows + due_date/is_overdue (Piutang menu data).
Frontend: locales ar/zh missing `payFull`/`payDp` (TS2739) → added; CrmAssistedOrderView spec 'Pickup' → 'Ambil ke Toko' (label change #03).

## Migration status
`2026_11_02_100000_add_payment_amounts_due_date_and_invoice_issuance` — **EXECUTED on DEV 2026-10-31** (backup `primeclassy_dev_before_masterV2migration_*.sql` 4.46 MB verified, dump exit 0; migrate exit 0; schema verified: orders.due_date/invoice_issued_at, btv.channel/verified_amount/proof_disk + order_due_date_changes + order_invoice_snapshots; data invariance orders=8 users=44). NOT run on production.

## Frontend dependency status
Working-tree `node_modules` was locked/partial (55 entries, no .bin) by a long-running local process — NOT force-killed. All compilation checks ran in an ISOLATED copy (robocopy of `frontend/` minus node_modules + `npm ci`); repo itself unchanged by that process.

## Blockers
- Item 15 discount display: Owner decision required (agent-scoped discount model vs anonymous visibility) — see table.
- Browser-level UAT of all UI items (GPS position, labels, staff DP sub-option, Piutang menu) remains Owner-side.
- `php.ini` upload limits (post_max_size/upload_max_filesize) = production deployment prerequisite, unchanged.

## EXACT NEXT ACTION
Owner review of the working-tree diff (93→~100 tracked files + 9 new WIP files + GLM's fixes), decision on item-15 discount visibility, then browser UAT; commit/push only after Owner approval.

## Codex remediation and enhancements continuation (2026-10-31)

- Branch/HEAD remain `main` / `152e222`; all prior tracked and untracked WIP preserved. No commit, push, deployment, production access, migration, or spreadsheet access.
- Group A source remediation: verified amount independently bounded by the locked canonical outstanding and submitted amount; lazy invoice issuance now runs in a transaction with an Order row lock; finance-orders gained server-side receivable/search/due-date filters, WIB due logic, full-filter summary and pagination metadata; standalone referral step disabled for every role and staff customer selection moved into Address; payment authority comments corrected. Existing Rp0 implementation retained.
- Group B source: worksheet column 48/AV `Status Pesanan` appended after the legacy 47 columns; category blocks preserve stored `category_ids` order; native `social_media`, `whatsapp_chat`, and `marketplace_links` contracts, admin fields and frontend renderers added with HTTPS/domain/phone validation and noopener/noreferrer links.
- Scoped PASS evidence: `MasterV2PaymentCoreTest` 13 tests / 131 assertions; `InvoiceTest` 9 / 42; `GoogleSheetsStaffWorksheetTest` + `GoogleSheetsCatalogTest` 21 / 156; receivables focused regression 1 / 32; native CMS validation 1 / 6; category ordering 1 / 2; checkout referral-step regression 1 / 3. `git diff --check` clean; changed PHP files pass `php -l`.
- Scoped known noise: full `CmsHomepageTest` has a pre-existing WIP mismatch (test expects 2 MiB while current config permits 10 MiB). It was not changed here. An early overlapping test invocation raced `primeclassy_testing`; sequential reruns passed.
- Frontend type-check could not execute because the repository's partial/locked `node_modules` has no `vue-tsc` executable (`exit 1`); no dependency install or process termination was attempted.
- Remaining UAT: responsive CMS previews, floating WhatsApp collision checks, receivables pagination/search, staff checkout attribution, and lawful/official brand-asset review. Production Sheet AV expansion remains an Owner-gated deployment step; no production Sheet was read or modified.

### EXACT NEXT ACTION (current)

Owner reviews this diff and performs browser UAT. Before any production action: approve official brand assets/licensing, back up the database and target Sheets, verify existing worksheet grid/column mapping, then separately authorize deployment. Do not commit/push/deploy without Owner approval.

## GLM audit continuation (PC-V2-GLM-AUDIT-CONTINUATION-001, 2026-10-31)

Owner declared PASS ALL on the inherited Codex baseline; no UAT repetition requested. RECON ONCE done: branch/HEAD unchanged (`main`/`152e222`, 110 tracked changed + 46 untracked incl. WIP artifacts), all Codex remediation/enhancement work preserved.

**(A) Invoice concurrency — deterministic two-connection regression NOW EXISTS (was open).**
New `backend/tests/Feature/InvoiceIssuanceConcurrencyTest.php` (2 tests / 63 assertions, **PASS**) plus one new actor op `invoice-ensure` in `.phpunit-concurrency-actor.php`. Real barrier-overlapped subprocesses with two distinct MySQL connections (harness enforces `different_connections` + `true_overlap`):
1. **Lazy legacy race** (`invoice-ensure` ×2): an order with `invoice_issued_at` stripped to simulate pre-tracking data; both actors racing `ensureIssuedIfEligible` → invoice stamped exactly ONCE (exactly one `invoice.issued` audit row), exactly one snapshot at version 1, no duplicate-version violation, both actors success, no deadlock. 3 iterations.
2. **Verify race** (`verify-bank-transfer` ×2 same proof): interleavings `['failed','success']` alternate across iterations (proved genuine overlap); money applied exactly once (`paid_amount == total`), invoice issued exactly once, exactly one snapshot version 1 with payment_status `paid` and outstanding `0`. 3 iterations.
Notes: production keeps at most ONE pending transaction per order (`requestInstallment` refuses a second with `already_processed`), so a "two different proofs of one order concurrently" race is unreachable by design — the same-proof race is the reachable first-verification race. A defect found while building this test was in MY OWN actor op (common tail reading `$result?->status` on an array result), fixed in the actor file; **no source defect** was found in InvoiceIssuanceService/PaymentService.

**(B) Final consistency audit — diff-first review of the Codex changes; NO proven defects, no source changes made.**
- Payment verify bounds (`VerifyBankTransferRequest verified_amount` optional numeric lookup >0; service bounds it by locked canonical outstanding + submitted amount) — consistent with locked rules.
- Payment controller installment/cash paths: cash receiver must be a branch staff role and never the konsumen themselves; receipt reference/photo required; auth via `authorizeProofSubmission`; nobody records+verifies in one path (verify authority Keuangan/super_admin only as previously proven).
- Piutang server-side filters (`financeOrders`): "search" now matches the order_no + snapshot phone recipient + consumer name; the `receivable` filter via `CollectibleReceivable`; `due_filter` due/overdue on the WIB business day; date ranges; summary counts with the filtered clone; pagination metadata untouched — same single financial source.
- Invoice issuance (code re-read): `verifyBankTransfer` locks the verification row then the ORDER row inside one transaction and calls `syncAfterChange` under the lock; the lazy path re-locks orders itself — issuance idempotency is lock-backed end-to-end (now proven by A).
- Google Sheets: `order_status` appended as the 48th key (column index 48 = AV exactly); appended only — legacy columns 1–47 (A–AU) never re-ordered/removed; manual cells protection upheld; registry text updated to 48-column descriptions.
- CMS: backend `HomepageBlockTypes` requires `platforms.<p>.enabled` booleans and HTTPS-only URLs with per-platform domain regex (instagram/tiktok/facebook/tokopedia/shopee), WhatsApp phone/mode/message validated; native block names `social_media`/`whatsapp_chat`/`marketplace_links`.
- Category ordering: category blocks honor the stored `category_ids` order (sortBy on array_search position); degradation for decayed ids is benign.
- `frontend/src/task C`: `HomepageBlockContent` extension (layout/mode/phone/message/platforms) matches the backend contract exactly; `npm run type-check` in-repo now **exit 0** (Owner restored dependencies) — contract verified at compile level.

**(C) Frontend**: type-check exit 0 in the working tree; no dependency reinstall or lockfile change made by GLM (dependencies restored by the Owner).

**Artifacts/notes for Owner**: stray `backend-fulltest-remediation-001.log` sits at the repo root from earlier work — left untouched per no-deletion rule. `git diff --check` clean. One caveat noted in Codex's evidence still stands: full `CmsHomepageTest` WIP mismatch (expects 2 MiB vs config 10 MiB) is pre-existing and unchanged.

**EXACT NEXT ACTION**: Owner reviews the complete diff and `docs/MASTER-V2-15-IMPROVEMENTS-CHECKPOINT.md`; scoped tests remain single-file only. Await Owner instructions — no commit/push/deploy.

## GLM final release readiness (PC-V2-GLM-FINAL-RELEASE-READINESS-001, 2026-10-31)

Full release package consolidated in **`docs/PC-V2-FINAL-RELEASE-READINESS-001.md`** (§1 remediation report, §2 repository change inventory with classification + staging lists, §3 release manifest, §4 deployment plan, §5 rollback plan, §6 Google Sheets AV deployment plan, §7 production config preflight, §8 verdict). Additively, in this pass:

- `CmsHomepageTest` banner-limit remediation: stale 4 MiB fixture/2 MiB comment → 11 MiB fixture; upload-limit contract verified across ALL validators (banner 10 MiB, generic 5 MiB, profile/logo 2 MiB, favicon 512 KiB; SVG refused). Evidence: **19 passed / 56 assertions**. `php -l` clean.
- WhatsApp phone normalization added client-side (`CmsHomepageBlockFormView.vue` `normalizeWhatsappPhone`, 08… → 628…, separators stripped, server regex remains authoritative); type-check **exit 0**.
- Audit pass: no proven defects in payment/invoice/piutang/CMS/Sheets; v-html untouched (3 pre-existing rich-text fields only); noopener+noreferrer confirmed on all three native block renderers; SheetsClient grid auto-expand + manual-column protection verified code-level (protected indexes derive from the written row key order, so the appended AV key cannot break legacy A–AU).
- One pending production migration: `2026_11_02_100000…` (DEV already migrated). No composer/package/env key changes. No scheduler/worker additions.
- Verdict **READY FOR OWNER RELEASE APPROVAL** — remaining items (production migrate, Sheets AV activation, php.ini/Nginx raise, artifact dispositions incl. `backend-fulltest-remediation-001.log`, `backend/2048])!`, `cms.ts.bak-*`, untracked `pnpm-lock.yaml`, design/ licensing) are Owner-executed steps, fully planned in the release document. Nothing deleted; no commit/push/deploy performed.
