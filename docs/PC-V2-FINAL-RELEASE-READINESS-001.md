# PC-V2-FINAL-RELEASE-READINESS-001 — Final Remediation, Inventory & Release Package

Task ID: `PC-V2-GLM-FINAL-RELEASE-READINESS-001` · Agent GLM (OpenCode) · 2026-10-31
Baseline: branch `main`, HEAD `152e222`, all release work uncommitted on top. DEV local only. Owner has declared **PASS ALL** (no UAT repetition requested). This document consolidates the eight required release outputs. Generic production procedures are NOT duplicated here — canonical procedure lives in `docs/OPERATIONS.md` §6, §7.2–§7.6 and is referenced below.

---

## §1 Final Remediation Report

### 1.1 `CmsHomepageTest` 2 MiB → 10 MiB banner mismatch (STALE EXPECTATION — fixed)
- **Defect:** `test_image_upload_rejects_files_over_the_size_limit` uploaded a 4 096 KB fixture with the comment "4MB > 2MB limit", against the product-approved banner contract of 10 MiB (`config/media.php` `homepage_block.max_size = 10 * 1024 * 1024` and `StoreHomepageBlockRequest`/`UpdateHomepageBlockRequest` `max:10240`). Under the real contract the 4 MiB file was *legal*, breaking the test.
- **Fix:** fixture raised to `->size(11264)` (11 MiB > 10 MiB) with an updated comment; no business rule touched.
- **Upload-limit contract verified across ALL validators** (rule ↔ `config/media.php` alignment):
| Slot | Validator bound | Config bound | Match |
|---|---|---|---|
| homepage_block banner | `max:10240` (Store + Update) | 10 MiB | ✓ |
| product_image | `max:5120` | 5 MiB | ✓ |
| payment proofs (bank/cod/installment receipt), shipment proof, return/consent evidence | `max:5120` | 5 MiB each | ✓ |
| profile/logo (`site_logo`, `user_avatar`) | MediaService config-driven (no FormRequest copy) | 2 MiB | ✓ |
| favicon | 512 KiB | ✓ |
| region CSV import (`max:20480`) — intentionally separate | ✓ |
| SVG refusal — every raster-only, per Blueprint script-injection rule | ✓ |
- **Evidence:** `php artisan test tests/Feature/CmsHomepageTest.php` → **19 passed (56 assertions)** (was the only known failure cluster).

### 1.2 WhatsApp number normalization (client-side — added)
- **Requirement:** WA numbers must be normalized `^[1-9][0-9]{7,14}$` (backend rule `HomepageBlockTypes::WHATSAPP_CHAT`). The admin form previously submitted whatever was typed (`+62…`/`08…` would be rejected server-side).
- **Fix:** `CmsHomepageBlockFormView.vue` now composes `phone` through `normalizeWhatsappPhone()`: strips non-digits/separators, repairs the common local prefix `08…` → `628…`; invalid shapes still fail server-side validation (never guessed away).
- **Evidence:** `npm run type-check` → exit 0.

### 1.3 Other findings in this final pass
- v-html usage: only the 3 pre-existing rich-text fields (article/page body, product description); none of the new CMS native blocks use v-html (attribute-bound, auto-escaped).
- All three native block renderers (`SocialMediaBlock`, `MarketplaceLinksBlock`, `WhatsappChatBlock`) open URLs with `target="_blank" rel="noopener noreferrer"`; URL values are HTTPS-only + per-domain allowlist (instagram.com/tiktok.com/facebook.com/tokopedia.com/shopee.co.id regexes in `HomepageBlockTypes`), so no `javascript:` scheme can reach the store.
- **No source defect found in payment, invoice, piutang, or Sheets code in this final pass.**

---

## §2 Repository Change Inventory (classification)

Baseline diff: **125 tracked files + 46 untracked entries**, all uncommitted. Classification legend: REL = RELEASE_REQUIRED, TEST = TEST_REQUIRED, DOC = DOCUMENTATION_REQUIRED, LOCAL = LOCAL_ONLY, SENS = SENSITIVE_DO_NOT_COMMIT, DEC = NEEDS_OWNER_DECISION.

### 2.1 Tracked — backend application (33 files) → **REL**
`PaymentController.php` (+installment/setDueDate/proofFile endpoints), `PaymentService.php` (installment, verify bounds, cash receipt, due-date), `InvoiceIssuanceService` caller wiring, `OrderResource.php`, `ReportController.php` + `ReportService.php` (+due-date/is_overdue/piutang filters), `CheckoutStepResolver.php`, `ShippingQuoteService` labels, `AvailablePaymentMethodService`, `OrderStaffWorksheetService.php` + `DatasetRegistry.php` (AV column), `HomepageController.php`, `HomepageBlockTypes.php`, `HomePageController/Homepage` CMS contracts, `MediaService`, `StoreProductImageRequest` and 9 other FormRequests (limit/whatsapp/verified_amount rules), plus the remaining V2-touch services/controllers.

### 2.2 Tracked — backend tests (56 files) → **TEST**
All 55 verified-green scoped files (see `MASTER-V2-15-IMPROVEMENTS-CHECKPOINT.md` "Test evidence") + `CmsHomepageTest` (19/19 this pass) + `InvoiceIssuanceConcurrencyTest` (untracked, 2/2).

### 2.3 Tracked — frontend (18 files) → **REL**
`cms.ts` (HomepageBlockContent native-block extension), `CheckoutView.vue`, `OrderDetailView.vue`, `ProductDetailView.vue`, `CmsHomepageBlockFormView.vue`, `CmsHomepageBlocksView.vue`, `payments.ts`, `types.ts`, `ImageUploader/PrimeFileUpload/StockBadge`, locales ×4, `router/index.ts`, `dashboard/navConfig.ts`, `reports.ts`, 1 more.

### 2.4 Untracked — source/test (11 files) → **REL / TEST**
`StoreInstallmentRequest.php`, `OrderDueDateChange.php`, `OrderInvoiceSnapshot.php`, `InvoiceIssuanceService.php`, migration `2026_11_02_100000_…`, `MasterV2PaymentCoreTest.php`, `RoleBasedPaymentMethodsTest.php`, `InvoiceIssuanceConcurrencyTest.php`, `OrderPaymentTools.vue`, `ReceivablesView.vue`, `ReceivablesView.spec.ts`, `blocks/SocialMediaBlock.vue`, `MarketplaceLinksBlock.vue`, `WhatsappChatBlock.vue` → **REL/TEST by nature; must be staged together with their tracked dependents** (a release without e.g. `InvoiceIssuanceService.php` cannot boot).

### 2.5 Documentation → **DOC**
Checkpoint `docs/MASTER-V2-15-IMPROVEMENTS-CHECKPOINT.md` (tracked-modified + this release package), the historical session/audit docs under `docs/` (untracked: audit reports, checkpoints, PHASE2/RECON/SPACEBUNNY series), `docs/06-review/` (1 file), `SECURITY-AUDIT-20261004.md`, `code review artifacts` — decision framework: release commits usually carry the release-relevant subset; Owner picks per `DOC-INVENTORY-CLEANUP-PROPOSAL-001.md`. Not blocking.

### 2.6 Artifacts / temp / backups → **LOCAL / SENS / DEC**
| Item | Size/notes | Class | Disposition (no action taken) |
|---|---|---|---|
| `backend-fulltest-remediation-001.log` (root) | run log from earlier remediation | LOCAL | keep, exclude from release |
| `backend/2048])!` | 95 B — stray text of a PHP parse error | LOCAL/DEC | keep; delete at commit time with Owner consent |
| `frontend/src/api/cms.ts.bak-14480520-092313` | 2 296 B pre-edit backup | SENS-adjacent | keep, never commit |
| `frontend/pnpm-lock.yaml` (untracked) | project uses `package-lock.json` (tracked) + restored node_modules | DEC | keep file; exclude from release |
| `artifacts/` (127 files) | test/compiler artifacts incl. concurrency runtime dirs | LOCAL | keep; never commit |
| `design/` (8 files) | brand/design assets; part of a licensing question (official brand-asset review) | DEC | exclude until Owner resolves licensing |
| `docs/06-review/` | 1 review file | DOC | Owner choice |
| Safety rule | none of the above is deleted by this task | — | — |

### 2.7 Secrets & environment → **SENS**
`backend/.env`, `frontend/.env.local` (Google Maps keys), production `.env`/`config.js` — gitignored, NEVER staged/committed. No real credentials anywhere in the diff (verified: no new env keys; secrets live in DB per OPERATIONS §2).

### 2.8 Recommended staging lists ( Owner executes; **no `git add` run by GLM**)
- **Stage with the release:** ALL files in §2.1–§2.4 as one atomic commit (tracked dependents + untracked new sources/tests are one inseparable unit), plus `docs/MASTER-V2-15-IMPROVEMENTS-CHECKPOINT.md` and this document.
- **Never stage:** §2.6 local artifacts + backups + `pnpm-lock.yaml` (unless Owner decides to adopt pnpm), any `.env*`, `node_modules`, `dist`.

---

## §3 Release Manifest

| Aspect | Content |
|---|---|
| Git baseline | `main` @ `152e222` + uncommitted diff (125 tracked + 46 untracked). No dependency changes: `composer.json`/`package.json` untouched → same `composer.lock`/`package-lock.json` as deployed Phase-3 baseline. |
| Feature content | 15 Master-V2 improvements, 7 Codex audit remediations, 5 Codex enhancements, 9 new backend sources/tests, invoice two-connection regression, centralized Piutang menu + its data contract, CMS native blocks (social/WhatsApp/marketplace), WhatsApp normalization, `CmsHomepageTest` banner-limit realignment. |
| Database migrations | Exactly **one pending production migration: `2026_11_02_100000_add_payment_amounts_due_date_and_invoice_issuance`** (additive only: nullable/defaulted columns `orders.due_date`, `orders.invoice_issued_at`, `bank_transfer_verifications.channel/submitted_amount/verified_amount/proof_disk` + audit rows; new tables `order_due_date_changes`, `order_invoice_snapshots` with `UNIQUE(order_id, version)`; FK child columns nullable; no backfill, no destructive op). Already executed & verified on DEV (backup 4.46 MB, schema + data invariance verified). |
| Environment variables | **No new keys.** Existing production `.env` untouched. `GOOGLE_SHEETS_ENABLED` + service-account path per OPERATIONS §2 must stay as-is. |
| Scheduler / workers | **None added** (`routes/console.php` has no registered schedule; no queue workers introduced). No queue-namespace/worker change for this release. |
| Google Sheets | Dataset `transaction_worksheet` = legacy 47 columns + appended `order_status` header `Status Pesanan` (A–AU preserved, column 48 = AV); grid auto-expand (+1 column) exists in `SheetsClient` when destination < 48 columns; staff-owned MANUAL columns are never cleared/overwritten (protected by 0-based indexes computed from the written row keys). Production activation is a DEPLOY STEP, not yet executed. |
| Upload requirements | Banner 10 MiB (`homepage_block`), generic images 5 MiB, profile/logo 2 MiB, favicon 512 KiB; private proof storage with disk-aware `proofFile` gate; content-based MIME check (finfo). PHP `upload_max_filesize`/`post_max_size` + Nginx `client_max_body_size` must be raised — see §7 Pre-preflight. |
| Frontend build | Build from `frontend/` (`npm run type-check` exit 0; `npm run build-only` = release artifact step), deploy `frontend/dist` via the canonical `rsync … --exclude=laravel.php` command (OPERATIONS §7.2). Runtime API override `public_html/config.js` unchanged. |
| Assets | New block-renderer components; no new static assets requiring CDN work beyond `dist`. |
| Compatibility constraints | Backend → older frontend OK (new fields additive); frontend REQUIRES the new backend (installment endpoints, piutang fields, CMS contracts). Historical financial rows: NULL/default defaults only — old orders render as before. |
| Security-sensitive | Payment verification authority (keuangan/super_admin, no self-verification), `verified_amount` bounds, private payment proofs (disk-gated), role-gated checkout/payment-method rules, `invoice_issued_at` immutability, order-status-only AV semantics. Reviewed in the two prior continuation passes + this pass; no defect found. |

---

## §4 Production Deployment Plan (this release only — canonical steps per OPERATIONS §7.3)

Presuppositions: Human Stage Gate approval; records from DEV UAT exist (PASS ALL).

1. **Preflight (read-only)** — server-side: `git`-less production tree is untouched; `php artisan migrate:status` on production shows Pending = exactly [the §3 migration]; confirm MariaDB 10.11.10 compatibility of the migration SQL (additive, no triggers, plain DDL — verify `SHOW VARIABLES like 'log_bin'` is OFF as before; no trigger/creator permission is required here); confirm Nginx/FPM `upload_max_filesize`/`post_max_size`/`client_max_body_size` (see §7) if banner uploads are unblocked pre-release.
2. **Backup** (OPERATIONS §6): database + `backend` + `public_html` — mandatory and verified before step 4.
3. **Maintenance ON** (`php artisan down`) for the migrate + code-swap window.
4. **Deploy backend by whitelist**: all §2.1 + §2.4 backend files (+ `composer install --no-dev --optimize-autoloader`, no dependency change expected); never copy DEV `.env`/storage/uploads/sessions/logs; never delete `public_html/laravel.php`.
5. **Migrate**: `php artisan migrate --force` → confirm the one migration is *Ran*; afterwards verify: `orders.due_date`/`invoice_issued_at` NULL on historical rows, new tables empty, FK list as designed, `migrations` row recorded.
6. **Reconcile before go-live**: roles = 11; `orders`/`order_items` counts unchanged; `bank_transfer_verifications` rows unchanged (submitted_amount mirrored = amount for historical rows); payment ledger invariants (`payment_status`, `paid_amount`) untouched; invoices: historical orders get `invoice_issued_at` NULL until lazy issue (allowed state, see `ensureIssuedIfEligible`).
7. **Caches**: `config:clear && config:cache`, `route:clear && route:cache`, `view:clear && view:cache`.
8. **Deploy frontend** from a locally verified `npm run build-only` `dist/` via the canonical rsync (§7.2, exclusions mandatory).
9. **Google Sheets AV activation** per §6.
10. **Maintenance OFF** (`php artisan up`), then **Smoke** per OPERATIONS §7.4 + release-specific: place one staff COD order → invoice issued + `order_invoice_snapshots` v1; verify banner upload ≤10 MiB; open `/dashboard/piutang` as admin/keuangan; CMS admin saves a `marketplace_links` block (HTTPS URL) and a WhatsApp block with `08…` (auto-normalized to `62…`); Sheets sync dry-run: row A–AU unchanged + AV populated for the staff worksheet (see §6.7).
11. **Monitoring 24 h**: payment verification + invoice issuance + synchronize `order_status` AV values match `orders.status`; watch for duplicate-version violations (would surface as 500s on snapshots).

---

## §5 Production Rollback Plan (release-specific)

Separate three classes as required (never blind-rollback once real traffic writes; OPERATIONS §7.5 is canonical):

- **Application rollback** (no schema reversal): restore the pre-deploy `backend/` and `frontend/dist` backups; keep the migration in place (the OLD code is forward/backward-compatible with the additive schema: nullable columns + new tables are simply unused). Re-cache config/routes/views; verify `/up`, `/api/v1/install/status`, homepage.
- **Sheet rollback**: reverse is defined AV-value-wise, not schema-wise: restore column AV by overwriting its `order_status` cells with `''` from the §6.3 pre-activation copy (or skip — AV being one additive informational column, a restored old SyncService simply stops writing it; staff-edited A–AU must never be touched in either direction). Restore grid note: do NOT shrink the grid.
- **Database recovery** — only if the migrate step itself corrupted something BEFORE any write traffic (verified by forecast): restore the §0 verified dump. **If real transactions arrived post-migrate**: NEVER reload an old dump (would erase payment ledger rows, invoice snapshots, idempotency keys, uploads-bound rows). Forward-fix instead: the schema is additive; a currency-consistency defect is corrected with a controlled, logged correction workflow (reconcile `orders ↔ payment_transactions ↔ bank_transfer_verifications ↔ snapshots` by ids). Invoice snapshots are append-only with `UNIQUE(order_id, version)` — a bad row is superseded by a correct next version, never deleted. Queued job considerations: `QUEUE_CONNECTION` unchanged; if any deferred jobs ran between backup and rollback they belong to the recovered-era data — reconcile rather than replay. Idempotency keys survive in the restored tables — replays revalidate and are refused or reused exactly as audited.
- **File uploads**: proof uploads live outside Git (`storage/app/public/…`) — never restore directory trees over newer upload files (append-only recovery, no overwrite).

---

## §6 Google Sheets AV — Production Deployment Plan

**Facts (code-verified):** `order_status` appends as the LAST dataset key → written-row index 47 → column 48 = **AV**; header label `Status Pesanan`; values mirror `orders.status` at sync time, nothing else, no timestamps; legacy keys 1–47 (A–AU) never reordered/renamed; `SheetsClient::replace()` writes only the dedicated tab, clears only non-protected DATA cells, hero zero-shrink; grid auto-`appendDimension` when `column_count < 48`; staff MANUAL columns (DP/CICILAN/PELUNASAN/KETERANGAN etc. via `OrderStaffWorksheetService::MANUAL_FIELDS`) are never written.

1. **Pre-flight checklist (Owner, production)** — read-only: identify the spreadsheet ID + `transaction_worksheet` tab from prod DB (google_sheets settings marker mechanism); read C1:1..AU1 headers and record them; record grid row/column counts; verify service-account scopes; record `last synced row` anchor.
2. **Backup** — download (API export or manual copy) the FULL `transaction_worksheet` tab → store as CSV/XLSX outside the server (`backup-<ts>-worksheet-AU.csv`); record the exact header string of A1:AU1.
3. **Worksheet schema validation** — assert current `columnCount ≥ 48` (auto-expand will add the column anyway); assert the 47 legacy headers are byte-identical to the pre-deploy list (a mismatch = STOP, report).
4. **Dry-run plan** — sandbox: run the same sync against a throwaway copy spreadsheet (duplicate the worksheet) using a temporary env override `GOOGLE_SHEETS_*` pointing to the copy; verify: A–AU headers unchanged, AV header appears at index 48, staff manual cells untouched by diff-check before/after, rows count identical, no cache probleoffsets (`metadata->column_count` now ≥ 48).
5. **Deployment steps** — with release authorized: deploy backend code (§4 step 4) → run the normal Scheduled/manual sync once → verify header + a sample of AV values against `orders.status` for the same order numbers.
6. **Rollback** — per §5 Application/Sheet rollback classes; specifically the pre-deploy copy of the tab is the authority; never hand-edit staff-managed columns to "undo" AV.
7. **Post-deployment verification** — 24 h monitor: sampled orders' AV == `orders.status`; A–AU header fingerprint unchanged from pre-deploy; manual DP/DP/CICILAN/PELUNASAN cellsdiff-check unchanged on re-sync; grading of `GoogleSheetsStaffWorksheetTest` + `GoogleSheetsCatalogTest` (21 tests / 156 assertions) already passing scoped.

**NO production Google Sheets was read or written during this task. Every sheet interaction above is an Owner-side, separately authorized step.**

---

## §7 Production Configuration Preflight (upload limits)

Production stack per OPERATIONS §1: Nginx (SPA + `public_html/laravel.php` bridge) → PHP-FPM → MariaDB 10.11.10,建造 not executed by GLM.

| Setting | Recommendation | Rationale |
|---|---|---|
| PHP `upload_max_filesize` | **12M** | banner contract 10 MiB (10 240 KiB) + small margin; proofs 5 MiB comfortably below |
| PHP `post_max_size` | **32M** | ≥ several largest uploads (one CMS POST carries one image; headroom for future multi-file payloads + JSON body) |
| PHP `memory_limit` | **≥ 256M** (keep existing if higher) | MediaService validates with `getimagesize()` + storage checks (no pixel-decode reservoir); 256M is the safe Laravel default |
| FPM `request_terminate_timeout` / PHP `max_execution_time` | **≥ 60s** | 10 MiB multipart via FPM + MediaService I/O per request; embed under insignificant normal traffic |
| Nginx `client_max_body_size` | **32M** | must be ≥ `post_max_size` or Nginx rejects 413 BEFORE PHP sees it |
| `fastcgi_read_timeout` | **≥ 60s** | same coverage as terminate timeout |
| Scope | php-fpm pool + nginx vhost for the production domain only; restart/reload services after change | — |

Verification after change: `php -i | grep -E 'upload_max_filesize|post_max_size|memory_limit'` via the FPM pool (CLI value is irrelevant to uploads); client-side upload of a 9–10 MiB test banner in a maintenance window, then of an 11 MiB (expect a clean Laravel 422 field error, not a 500/413; note exact 413 = Nginx bound not yet raised).

---

## §8 Final Release Readiness Report (verdict)

| Readiness area | Status |
|---|---|
| Feature work (15 + Codex remediation + enhancements) | ✅ inherited PASS ALL, preserved |
| Known test mismatch (CmsHomepageTest 2→10 MiB) | ✅ FIXED this pass, 19/19 (56 assertions) |
| Invoice two-connection concurrency regression | ✅ EXISTS & PASS (2 tests / 63 assertions, 2 scenarios × 3 iterations) |
| Security/consistency audit (payment/invoice/piutang/CMS/Sheets) | ✅ no proven defects; contracts verified |
| `npm run type-check` | ✅ exit 0 (in-repo working tree) |
| `php -l` on touched files; `git diff --check` | ✅ clean |
| DEV migration 2026_11_02 | ✅ executed & verified (see §3) |
| PRODUCTION migration | ⏳ Owner-authorized step (plan §4/§5 ready) |
| Google Sheets AV production activation | ⏳ Owner-authorized step (plan §6 ready, sandbox pattern included) |
| php.ini / Nginx raise for 10 MiB banners | ⏳ Owner-authorized step (§7 ready) |
| Repo artifact dispositions (log, `2048])!`, `.bak`, `pnpm-lock.yaml`, design/licensing) | ⏳ NEEDS_OWNER_DECISION — nothing deleted |

### Verdict: **READY FOR OWNER RELEASE APPROVAL**

All technical work GLM is authorized to perform is complete; the三种 remaining items are Owner-executed, separately authorized deployment steps whose plans are executable as written (§4–§7). Nothing in the release package requires further coding.

**Release commit must be ATOMIC:** everything from §2.1–§2.4 (+ this document + checkpoint) staged as one commit — the untracked new backend sources are compile-required by the tracked code. NEVER stage the §2.6/§2.7 items.

**STOP — waiting on Owner release authorization. No commit/push/deploy was executed.**
