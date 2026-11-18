# PrimeClassy V2 — Codex technical remediation and Owner handoff

Date: 12 November 2026 (Asia/Jakarta). Local working tree only.

## A. Executive verdict

**PARTIAL overall.** Safe local remediation continues Claude's work, without replacing it. Stock ABA detection, CMS bestseller integration, variant editor verification and SKS source continuity are implemented. Historical canonical identity has additive persistence; **historical admission remains disabled** because the independent evidence/recognition and Finance opening/report reconciliation contracts are not implemented or evidenced. No claim of REM-11 PASS or final invoice/POS visual PASS.

This report is implementation evidence, not an independent review or production approval. No commit, push, merge, production access, deployment, DEV migration, DEV frontend rebuild/sync or Full Test was performed. Frontend builds run in a disposable verification copy.

| REM | Verdict | Scope / remaining boundary |
|---|---|---|
| REM-01 | PASS — IMPLEMENTED & VERIFIED | Local import/approval revision, ABA, replay and scoped inventory tests; production preflight remains separate. |
| REM-02 | PARTIAL | Backend gallery isolation tested; storefront variant gallery switching remains absent. |
| REM-03 | PARTIAL | Management detail/image loading retained and exercised; complete no-change image-save/browser UAT not claimed. |
| REM-04 | PARTIAL | Guarded product deletion and Admin denial tested; no new permanent variation-delete path was added. |
| REM-05 | PASS — IMPLEMENTED & VERIFIED | Existing management filter UI retained, default Semua and storefront/API status behavior covered. |
| REM-06 | PARTIAL | Backend hierarchy/filtering and reparent concurrency covered; complete category-tree editor UX not verified. |
| REM-07 | PASS — IMPLEMENTED & VERIFIED | Backend ranking plus existing CMS settings/feed/frontend rendering integration. |
| REM-08 | PASS — IMPLEMENTED & VERIFIED | Existing editor tested with API errors, sibling/gallery isolation and persisted OrderItem snapshot. |
| REM-09 | PASS — IMPLEMENTED & VERIFIED | Source-aware catalogue/detail/cart/checkout; canonical resolver and forged-source rejection. |
| REM-10 | PARTIAL | Official logo verified; approved final visual/layout acceptance BLOCKED. |
| REM-11 | BLOCKED | Nullable canonical identity implemented; safe admission/financial reconciliation and POS UI not complete. |

## B. Git baseline and changed files

Branch: `fix/transaction-reset-financial-dependencies`. Baseline and final HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

The baseline **already contained Claude's dirty tracked/untracked implementation**, RECON documents and Architecture Lock. HEAD alone does not identify that input diff. No reset, clean, stash, checkout, commit or overwrite of that input was used. The tree intentionally remains dirty for Owner review, as explicitly requested.

Codex additions/edits, relative to that dirty input:

| Area | Files |
|---|---|
| Stock revisions | `backend/database/migrations/2026_11_14_110000_add_physical_stock_revision.php`; `backend/app/Services/Stock/PhysicalStockRevision.php`; `StockOpnameService.php`; `StockOpnameItem.php`; `.phpunit-concurrency-actor.php` |
| CMS bestseller | `backend/app/Services/Catalog/BestsellerAgentResolver.php`; `BestsellerService.php`; `BestsellerController.php`; `backend/app/Http/Controllers/Api/V1/Cms/HomepageController.php`; `backend/app/Support/HomepageBlockTypes.php`; `frontend/src/api/cms.ts`; `frontend/src/components/cms/HomepageBlockRenderer.vue`; `frontend/src/views/admin/CmsHomepageBlockFormView.vue` |
| SKS source continuity | `backend/app/Http/Controllers/Api/V1/Catalog/Concerns/AttachesAgentAvailability.php`; `frontend/src/api/catalog.ts`; `frontend/src/stores/cart.ts`; `frontend/src/components/shop/StockSourceSelector.vue`; `frontend/src/views/ProductListingView.vue`; `ProductDetailView.vue`; `CheckoutView.vue` |
| Historical foundation | `backend/database/migrations/2026_11_14_120000_add_historical_identity_to_orders.php`; `backend/app/Models/Order.php`; `backend/app/Services/HistoricalPos/HistoricalDossierPreflight.php` |
| Backend verification | `CatalogImportExportTest.php`; `CatalogImportConcurrencyTest.php`; `CatalogRemediationTest.php`; `ItemReceiptBestsellerTest.php`; `HistoricalPreflightTest.php`; `SubStockSourceTest.php`; new `StockRevisionMigrationTest.php` (all under `backend/tests/Feature/`) |
| Frontend verification | `frontend/src/views/dashboard/__tests__/ProductFormView.variants.spec.ts`; `frontend/src/views/__tests__/CheckoutView.stockSource.spec.ts`; `CatalogStockSource.spec.ts`; `frontend/src/api/__tests__/orderStockSource.spec.ts`; `frontend/src/components/cms/__tests__/BestsellerBlock.spec.ts`; extended `frontend/src/views/admin/__tests__/CmsHomepageBlockFormView.slider.spec.ts` |
| Documentation | This report (including the folded continuation checkpoint), and a superseding notice in `REM-V2-IMPLEMENTATION-REPORT.md` |

Architecture Lock files, logo assets, existing RECON documents and unrelated pre-existing dirty work are preserved.

## C. Claude claims independently checked/corrected

- Confirmed: import publication is inside Admin opname approval; row fingerprint/key persistence exists; accepted return allocations use the existing receipt/allocation domain; receipt eligibility uses actual per-item facts rather than shipment dates.
- Confirmed: the second-round variant attribute editor and checkout source radios already existed. They were preserved and tested, rather than rebuilt.
- Corrected: quantity equality alone misses ABA. Query-level and raw SQL stock writers bypass Eloquent observer-only solutions. See D.
- Corrected: bestseller backend support was not a completed homepage integration. The existing CMS registry/form/feed/renderer now supports it.
- Corrected: a checkout source radio alone did not make the catalogue/cart source-aware. Sub-only inventory could remain unavailable to add because the catalogue displayed Agent stock. See F.
- Corrected: the earlier test named “does not touch order snapshots” had no persisted OrderItem fixture. It now compares the full persisted row before/after the variant edit, and checks the variant image remains.
- Corrected: “no new POS tables” is not a prohibition on additive canonical fields. See G.
- Confirmed, with qualification: Laporan A stores immutable initial item snapshots; that does **not** prove a report is issued/closed. Invoice issuance snapshots exist, but are not an issued-report register. Laporan B is intentionally live.
- Corrected two bounded preflight defects: today's receipt/payment dates inherited time-of-day during parsing; repeated SKUs could overwrite quantity lookup entries. SKU aliases now normalize to the registry identity for duplicate checks and stable fingerprints. Admission is still explicitly unavailable.
- Confirmed: there is no verified final invoice/POS mockup in the checked repository assets. Purple candidates remain non-final.

## D. REM-01 — ABA mechanism and coverage

### Options assessed

| Option | Coverage and consequence | Decision |
|---|---|---|
| Eloquent observer | Misses query-builder increments/updates, raw SQL and legacy command inserts | Rejected |
| Explicit increments in each writer | All current and future branches must remember the increment; no enforceable DB coverage | Rejected for this pass |
| Refactor every writer through one mutator | Broad changes to fulfilment/transfer/return locks and behavior; still bypassable through SQL | Rejected as unnecessary scope/risk |
| Database triggers + durable per-target token | Covers INSERT, quantity/identity UPDATE and explicit DELETE, in the same InnoDB transaction as stock | Implemented and tested on MariaDB 10.11 |

`warehouse_stock_revisions` contains **change tokens**, not stock balances or financial entries. Its key identifies the existing Agent/bucket/location/product-or-variant target. Triggers bump the counter for insert/delete and actual quantity changes; an identity change invalidates both old and new target tokens. No-op updates do not increment. Counter changes roll back with the stock transaction. Tombstones remain after deletion, so `absent → insert → delete → absent` is detectable.

Each new opname captures physical quantity, row identity and token under target locks. Approval uses current locking reads and rejects quantity, identity or token mismatch with **HTTP 409**, before stock movement or publication. An import staged before token support has NULL snapshot metadata and fails closed; no history is guessed. Pre-existing non-import opnames with no revision metadata retain the legacy quantity check. New regular opnames also capture revisions.

Sub locking is parent `WarehouseSubLocation` → warehouse target → revision token → existing reservation checks. Multi-target opname snapshot/approval uses `StockService::canonicalReservationTargets` / its canonical key, not a second target order. Existing writer inventory locks are otherwise unchanged. Missing physical rows remain missing: staging must not create zero placeholders that disable legacy sellable fallback. A zero *token* may be initialized after locking its physical target.

Audited physical writers (the trigger covers all statements against the table, including creation branches):

| Writer | Mutation |
|---|---|
| `StockOpnameService::approve` | Physical/plan reconciliation quantity update |
| `StockRequestProposalService` | Transit→Shipping acceptance, damaged-unit write-off |
| `WarehouseStockRequestService` | Approved Sub adjustment |
| `WarehouseStockService` | Factory receipt, plan adjustment |
| `StockTransferService` | Both sides of physical and plan transfers |
| `InventoryCancellationService` | Shipping→Transit reversal |
| `ReturnService` | Agent Transit / originating Sub restock |
| `SubStockService::consume` | Sub shipment physical decrement |
| `SubStockRequestService` | Executes through the existing stock transfer service |
| `MigrateLegacyWarehouseStock` | Raw `warehouse_stocks` insert |
| `ReconcileWarehouseStock`, reset services | Reconciliation/read checks or preserve physical balances; product reset is maintenance deletion, not a sale writer |

Reservations change their existing reservation tables/counters, not physical quantity. Raw SQL and model/query-builder writes are tested. Parent FK deletion cannot leave an approvable product/Agent/location opname dangling: those opname references are restrictive. Administrative table truncation/schema reset is outside runtime inventory workflows and never an acceptable way to retain pending approvals.

Tests cover quantity ABA, absent-row insert/delete ABA, rollback/no-op semantics, legacy balance preservation, simultaneous import replay/approval, and deterministic two-connection approval waiting behind an ABA mutation. Replay after approval remains idempotent: no second adjustment/publication.

Migration prerequisites: TRIGGER privilege and applicable binary-log permissions on each deployment target. The isolated test environment has TRIGGER privilege, MariaDB `10.11.19`, `log_bin=0`, `log_bin_trust_function_creators=0`; trigger creation and execution succeeded. **Production privileges/binlog are not verified.** MariaDB DDL auto-commits; retry guards retain already-added components and retry missing triggers rather than silently declaring success. Deploy only under the existing authorized maintenance/backup procedure. Rollback must coordinate code/schema; removing revision columns invalidates staged import snapshots on re-install. No token/history backfill claims are made.

## E. REM-07 — CMS integration

The existing `cms_homepage_blocks` registry now accepts `bestseller`, with validated periods `7/30/90/all` and limits `4/8/12`. The existing CMS form creates/edits those settings. `/homepage` resolves the viewer Agent through the same `BestsellerAgentResolver` as `/bestsellers`; ordinary signed-in users cannot override their branch. Guests use the existing persisted referral code; unresolved/invalid scope yields no products, never a global ranking.

The ranking keeps `order_item_receipts`, `return_item_receipt_allocations` and the accepted-return allocation endpoint. It counts fully paid canonical Order truth, actual item receipts, and accepted inspected physical returns once. Multiple receipt events require evidenced return allocation; unresolved allocation excludes the item. Late payment keeps the original actual-receipt cohort. Windows include today in Asia/Jakarta; deterministic sort is net units descending, latest receipt descending, product ID ascending.

The homepage uses the existing product-card renderer, including active-variant starting prices. Empty/no-eligible rankings hide the section and heading. No CMS row was auto-created, no legacy receipt was invented, no navigation/page added, no shared/global cache introduced. The Owner can configure the block through the existing CMS after review and authorized schema rollout.

## F. REM-08 / REM-09 — frontend and API verification

**REM-08:** preserved the existing editor; focused component tests edit only the selected variation, omit unchanged attributes/image fields, reject empty attributes, retain the editor and API error on validation failure. Backend regression verifies sibling composition, gallery and a persisted historical OrderItem snapshot remain unchanged.

**REM-09:** a single actor-bound, session-persisted source applies to the whole cart. The same choice is available on existing catalogue/detail pages and checkout. Changing source invalidates old display ceilings without silently changing requested quantities; failure retains cart/source; successful cart clear resets both. Another account does not inherit the choice. No per-line mixed source or client location ownership is created.

Catalogue source selection is resolved by the existing **`StockSourceResolver`** and `SubStockService::sellable`, including active ownership. `stock.available` reflects the selected source; `agent_available_quantity` keeps its existing Agent meaning. Forged location/source and unauthorized roles fail. Detail/list request guards prevent a slow former-source response from replacing current availability. The backend still revalidates every line and owns pricing, payment, reservations and final source. Regular financial calculations were not changed.

## G. REM-11 — canonical persistence and admission status

Additive nullable fields on `orders`:

- `historical_origin`, `historical_business_date`, `historical_source_reference`, `historical_dossier_fingerprint`;
- `historical_requester_user_id`, independent requester name/phone snapshots;
- `historical_recorded_at`.

Existing `konsumen_id` remains the optional consumer relation, `placed_by_user_id` the recorder, existing recipient snapshots the recipient and `idempotency_key` the request identity. A unique Agent/origin/source-reference index supports one canonical source identity; these fields are typed/write-once once populated and excluded from public mass assignment. Legacy rows remain NULL. No parallel POS transaction table or fabricated Order/payment/payout is created.

**Admission: BLOCKED and unavailable.** Preflight is read-only and now returns explicit `admission_blockers` separately from dossier-shape validation. `ready_for_review=true` does not mean approved/admissible. No admission endpoint, regular checkout replay or quarantine Order is introduced.

Further canonical persistence must follow the eventual reviewed technical contracts: actual payment received date distinct from verification, private evidence content hashes and immutable review binding, one-use stock correction claims, evidenced external full commission recognition, and Finance reconciliation metadata tied to an actual approved opening treatment. Adding arbitrary nullable “approved” fields would not prove these invariants.

## H. D-01–07 compliance

| Decision | Evidence / boundary |
|---|---|
| D-01 | Unknown stock classification blocks preflight; not-reflected items require correction before admission. No duplicate reserve/deduction occurs because admission is disabled. Proof-bound correction claiming remains unimplemented. |
| D-02 | Preflight checks payment evidence references/amounts/dates; these are claims, not verified credits. Existing PaymentService independent-verifier rules remain intact. Historical immutable evidence review and canonical credit-once integration are not implemented. |
| D-03 | Unsupported partial external settlement blocks; full settlement requires claimed evidence/date. Existing entitlements untouched; no payout fabricated. Historical entitlement/settlement recognition is not implemented. |
| D-04 | Current reports and issued invoice snapshots unchanged; separate business/recording dates have storage. No automatic restatement/opening receivable/cash-in occurs. Finance-approved reconciliation and issued-report preservation gate remain incomplete. |
| D-05 | Current server canonical effective prices required; mismatch rejected, no override path. |
| D-06 | Regular admitted orders rank from actual item receipt facts; accepted physical return allocations retained. Historical preflight facts cannot enter ranking without admission. |
| D-07 | Stock approval and withheld catalogue publication remain atomic; stale/ABA 409 prevents both; approved replay does not publish or adjust twice. |

## I. D-04 — Finance/report boundary

1. **Existing behavior preserved:** `OrderInitialSnapshotService::capture` writes initial rows once inside checkout; `OrderInitialReportService::query` projects them with live actor names and recording-date filters. `OrderPaymentPositionReportService::query` is deliberately live over canonical Orders/payments. Google Sheets exports are one-way projections. Invoice issuance has its own immutable versioned balance snapshots; an issued invoice is not proof that a monthly report has been issued or closed.
2. **New technical persistence still needed:** identify actual issued/closed report artifacts/coverage and immutable output or version references; bind independent Finance review to dossier fingerprint, evidence hashes, approved values, Agent, reviewer, timestamp and rule version; record the approved opening/reconciliation reference without duplicate receivable or current cash recognition. This must integrate canonical payment/commission/report readers and protect both business-date and recording-date views. No arbitrary report-close state is inferred from existing snapshot presence.
3. **Facts/authority that cannot be invented:** which historical source is already represented in external/issued financial records, its actual approved opening treatment, and proof of full external commission settlement. Existing `keuangan` payment verification authority does not establish an implemented period-close/reopen or commission settlement procedure. No new Finance role or fictional historical artifact was created.

Exact source boundary: `HistoricalDossierPreflight::run` explicitly returns `persisted=false`, `admission_available=false`. `PaymentService::verifyBankTransfer` validates an existing canonical order/transaction and applies money; invoking it merely to register an already-received opening receipt risks fresh recognition. `CommissionFinalizer` only finalizes delivered entitlements; it explicitly has no payout writer. Generic `MediaService::replace/delete/attachTo` is mutable and its default disk/`MediaPolicy` does not satisfy pre-admission private, scoped immutable review. These are engineering integration gaps; they are not a request to reopen D-01–07.

## J. REM-10 — invoice/POS evidence

Both official logo files rehashed to:

`e2d724eef81645aee42328cc89b8f1a5ff3cd140cf92e70731a00fde26b552d3`

The existing official-logo embedding tests pass. Claude's white/maroon PDF color change is preserved; canonical financial math is untouched. Architecture Lock manifest verifies **15/15** entries. Checked `design/`, invoice resources/frontend paths and locked reference assets: only the generic dashboard spec and explicitly unverified invoice/purple POS candidates are present. No replacement image/design was generated or substituted.

**Final browser/print/PDF visual parity and final historical POS layout are BLOCKED pending verified approved reference evidence.** A successful logo test or PDF color inspection is not visual acceptance.

## K. Scoped commands and results

All DB commands ran serially with existing harness guards, only on `primeclassy_testing`. Final backend scoped command:

```sh
docker exec -e APP_ENV=testing -e DB_HOST=primeclassy-mariadb -e DB_DATABASE=primeclassy_testing -w /var/www/app/backend primeclassy-fpm php scripts/run-tests-serialized.php --filter='CatalogImportExportTest|ItemReceiptBestsellerTest|HistoricalPreflightTest|CatalogRemediationTest|SubStockSourceTest|StockOpnameTest|StockOpnameSubOwnershipTest|StockRevisionMigrationTest|CatalogImportConcurrencyTest|CmsHomepageTest|CmsHomepageStartingPriceTest|ValidAgentResolverTest|InvoiceOfficialLogoTest|CategoryHierarchyConcurrencyTest|WarehouseFulfillmentProposalTest|SubStockRequestFlowTest'
```

**PASS: 119 tests / 966 assertions**, 226.0 seconds runner time, exit 0; environment/migration/concurrency/test failure counts all 0. Run `d56a0c1f-f56c-4402-9f60-8e6af02bb824`. Three PHPUnit deprecations, not failures. JSON/JUnit evidence is under `backend/storage/framework/testing/runs/` (git-ignored).

Additional scoped inventory/concurrency command:

```sh
docker exec -e APP_ENV=testing -e DB_HOST=primeclassy-mariadb -e DB_DATABASE=primeclassy_testing -w /var/www/app/backend primeclassy-fpm php scripts/run-tests-serialized.php --filter='CatalogImportConcurrencyTest|TransferOpnameConcurrencyTest|StockTransferTest|WarehouseStockTest|WarehouseStockAdditionApprovalTest|InventoryCancellationReturnTest|SubReturnDomainTest|WarehouseReturnDispositionTest'
```

**PASS: 46 tests / 456 assertions**, 162.5 seconds, exit 0, all failure categories 0; run `a7ab2c9c-d1e0-4dba-a3bb-904f24f18e39`. This earlier inventory run complements the final run; overlapping test counts are not distinct coverage totals. Its transfer/opname race exercised both legal serial orders across five iterations, distinct real DB connections, no negative/lost/duplicate stock movement. New token initialization/normal-opname snapshot changes were subsequently covered by final opname/import tests.

Final bounded normalization follow-up:

```sh
docker exec -e APP_ENV=testing -e DB_HOST=primeclassy-mariadb -e DB_DATABASE=primeclassy_testing -w /var/www/app/backend primeclassy-fpm php scripts/run-tests-serialized.php tests/Feature/HistoricalPreflightTest.php
```

**PASS: 8 tests / 49 assertions**, 20.0 seconds, exit 0; all failure categories 0. Run `a00b69d9-c47f-4747-8ef3-ddc6a28ce43d`. This verifies canonical SKU aliases and repeated-item rejection after the final larger scoped run.

Frontend: Node `24-alpine`, `npm ci --no-audit --no-fund` in an isolated `/app` copy, source mounted read-only from the repository; existing repository `node_modules`, lockfile and `frontend/dist` were not replaced. Commands inside that copy:

```sh
npm test -- src/views/dashboard/__tests__/ProductFormView.variants.spec.ts src/views/__tests__/CheckoutView.stockSource.spec.ts src/views/__tests__/CatalogStockSource.spec.ts src/api/__tests__/orderStockSource.spec.ts src/components/cms/__tests__/BestsellerBlock.spec.ts src/views/admin/__tests__/CmsHomepageBlockFormView.slider.spec.ts
npm run type-check
npm run build-only
```

**PASS: 6 files / 22 tests**, 4.87 seconds, no unhandled errors. Type-check exit 0. Build/PWA exit 0, Vite 6.74 seconds; existing large-chunk advisory only. Build output stayed in the verification container. Its PWA version was `8967e742eaef333a`.

`git diff --check`: PASS (only the existing Git CRLF-normalization advisory for the edited registry file).

Iteration record: an initial anchored filter selected 0 tests (not counted as PASS evidence); a host-default test connection was refused before explicit host selection; host node/npm were absent and mounted platform-specific dependencies failed to start in Linux, so verification moved to a clean isolated install. Initial new frontend fixture failures (missing named route and incomplete quote object) were corrected; the new persisted-snapshot assertion initially compared an unsaved-shape model with a complete DB row, fixed to compare two freshly persisted rows. The intermediate backend run `7425675c-47bb-4963-ace4-1458ec78b30e` had that single assertion failure; the final 119-test run is green. No application guard/test was weakened or removed.

No Full Test/full regression was run.

## L. Migration and data safety

Existing Claude migrations preserved: import opname metadata (`2026_11_13_100000`), item receipts (`2026_11_13_100100`), return receipt allocations (`2026_11_14_100000`). Codex adds stock token/snapshot migration (`2026_11_14_110000`) and nullable historical Order identity (`2026_11_14_120000`). No DEV migration has been run.

DB preflight: `APP_ENV=testing`, host `primeclassy-mariadb`, actual selected database `primeclassy_testing`, local Docker backend mount verified, no competing test runner; existing serial harness/DB named lock retained. Only that isolated database was migrated/rebuilt by the scoped test harness. No grants were changed. No DEV/production schema, order, payment, stock, uploads or caches were intentionally mutated. Initial host-default test connection refusal was corrected by explicit test host before execution.

DDL lifecycle verification exercises retry, down/up, trigger recreation and unchanged existing quantity; historical identity down/up preserves Order row count. No schema rollback is a production instruction. Any future production migration remains subject to separate explicit authorization, backup and maintenance requirements.

## M. Remaining blockers and limitations

- REM-11 critical financial/evidence/admission work and D-04 integration remain blocked as detailed in G–I. Stored metadata is a foundation, not admission proof.
- Final approved Invoice/POS reference evidence remains missing; no visual PASS.
- Production TRIGGER privilege/binlog/deployment preflight remains unverified and was not accessed.
- Existing non-import opnames created before revision support retain their old quantity-only contract. Pending imports without revision snapshots must be cancelled/re-staged/recounted rather than backfilled with guessed tokens.
- REM-02 storefront variant-specific gallery switching remains absent in the existing detail UI; backend gallery isolation is implemented/tested. This pass does not claim whole REM-02 PASS.
- Generic Media infrastructure remains mutable; regular receipt evidence references do not constitute a historical sealed dossier/review implementation.
- A browser/manual UAT and independent review remain Owner handoff work; automated tests establish only the stated scope.

## N. Owner handoff

Review the uncommitted diff against `6a14041c` **with the pre-existing dirty implementation in mind**. Inspect the two new additive migrations, stock token/lock changes, CMS settings/feed and source-aware catalogue/cart/checkout. Retain all ODR/D decisions. Do not enable historical admission from the added fields or preflight flags.

No request is made to repeat settled business decisions. No merge/push/deploy/migration step is authorized by this report. Work stops for Owner review with the working tree preserved. **Exact next action:** Owner/independent reviewer reviews this uncommitted working diff and its test evidence; no production action or historical admission is enabled. The temporary continuation checkpoint was folded into this report and removed. The isolated frontend verification container was removed after its successful checks. Final Architecture Lock 15/15 and both official-logo hashes were rechecked successfully.
