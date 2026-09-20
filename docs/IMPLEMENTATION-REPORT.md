# Implementation report — 2026-09-13

## User hierarchy

- Super Admin can create only Agent accounts.
- Agent can create Admin, Keuangan, Korsal, Sales, and Kurir in its own network.
- Agent-created Sales requires a selected Korsal from the same network and is
  stored with `parent_id = korsal_id`, while `agent_id` is derived from the
  authenticated Agent.
- Korsal-created Sales derives all ownership fields from the authenticated
  Korsal.
- Request validation, policy, service, API middleware, frontend choices, and
  capability hints apply the same matrix.

## Catalog SKU

- Simple products require SKU; variation products cannot carry a parent SKU.
- Products and variants reserve values in one `catalog_skus` namespace.
- Existing variant SKUs populate that registry during migration.
- New simple and variant orders copy the applicable SKU into the immutable
  order-item snapshot.
- Audit and explicit dry-run/apply backfill commands were added.

## Google Sheets

- Central service-account authentication is server-side and environment based.
- Spreadsheet destinations have global or Agent ownership. Super Admin assigns
  either scope; Agent/Admin registrations are forced to their authenticated Agent.
- Access is limited to Super Admin, Agent, and Admin; backend policies enforce
  ID-based access.
- Fifteen application-defined datasets expose explicit allowed fields.
- Manual synchronization creates a missing valid tab and replaces its values, is
  concurrency locked, limited to 10,000 rows, and records sanitized success or
  failure logs. There is no Sheets-to-MySQL path.

## Database migrations

- `2026_09_24_090000_add_global_catalog_skus.php`
- `2026_09_24_090001_create_google_sheets_tables.php`

The migrations and SKU backfill were not applied to the existing database.

## Cleanup

Six verified Finder `.DS_Store` metadata files were removed. Archives,
documentation, deployment files, build output, SQLite data, logs, and Laravel
runtime directories were retained. See `REPOSITORY-CLEANUP-REPORT.md`.

## Verification

- User hierarchy/network suite: 24 tests, 93 assertions passed.
- Final backend regression: 377 tests, 1,872 assertions passed on an isolated
  MySQL database.
- Pre-install DDL isolation suite: 11 tests, 29 assertions passed.
- Frontend production build and TypeScript checking passed.
- Google API behavior was tested with a mocked client. Live connection and
  write verification require a deployed service-account key and shared test
  spreadsheet.

## Known dependency risk

`composer audit --locked --no-dev` reports three advisories against the locked
Laravel 11.56.1 framework, including CVE-2026-48019. The published affected
range covers Laravel 11 and does not provide an 11.x patched version. Moving to
Laravel 12 is a major framework upgrade and was not bundled into this scoped
implementation. The newly added `google/auth` 1.53.0 package was not identified
by the audit as vulnerable.

## Phase C — Transit + Factory Plan + Sellable Stock Foundation

- Added per-Agent `warehouse_stocks` bucket rows for Transit, Factory Plan, Sub,
  and Shipping; Sales remains derived and is not an editable bucket.
- Added movement-backed Gudang Transit receipt and Factory Plan delta APIs with
  row locking, same-network server-side scope, reference idempotency for
  repeated Transit requests, and atomic stock-plus-ledger transactions.
- Added per-Agent `warehouse_settings.factory_plan_enabled`. Only Admin may
  enable or disable it; disabling preserves stored Plan quantity and rejects
  the change when current reservations exceed Transit capacity.
- Added `WarehouseStockService` structured sellable output using Transit plus
  active Factory Plan minus existing Product/ProductVariation reservations.
  Physical stock excludes Factory Plan and commitment deficits are surfaced.
- Extended `warehouse:reconcile` to label PHYSICAL, PLANNED, DERIVED, and
  LEGACY quantities. Existing legacy stock remains untouched; no backfill or
  storefront stock cutover was performed.
- Added the minimum Gudang/Admin warehouse UI for Transit receipt, Factory Plan
  changes, backend-provided warehouse rows, and the Admin-only toggle.
- Phase C tests and relevant regressions passed; frontend type-check and build
  passed. The production bundle retains the existing large-chunk warning.
- At the Phase C boundary, explicitly not implemented: transfers, handovers, opname, stock requests,
  fulfillment, shipping movement, cancellation/return warehouse flows, Sub
  workflow, Sheets, production backfill, and full storefront cutover.

## Phase D — Stock Transfer + Handover

- Added `stock_transfers`, `stock_transfer_items`, and `stock_handovers` with
  Agent ownership, unique human-readable numbers, item target constraints, and
  transfer/handover indexes.
- Added `StockTransferService` for pending creation, pending cancellation, and
  atomic completion between physical Transit and Shipping buckets only.
  Factory Plan, Sales, Sub, and same-bucket transfers are rejected.
- Completion locks the transfer and sorted source/destination targets, validates
  source availability, applies source decrement plus destination increment in
  one transaction, writes paired `transfer_out`/`transfer_in` movements, and
  creates one linked handover. Repeated completion is idempotent.
- Added Agent-scoped transfer/handover policies and APIs. A4 browser handover
  print is read-only and does not modify stock, status, or movement history;
  shipment thermal receipt behavior is unchanged.
- Added minimum Gudang transfer monitoring, completion/cancellation, and print
  UI plus four-locale transfer/handover labels. No arbitrary transfer creation
  form exposes future fulfillment semantics.
- Added transfer/handover tests, reconciliation checks for movement pairs,
  orphan records, status mismatch, and scope violations.
- Explicitly not implemented: D-sub, stock opname, stock requests, fulfillment,

  ## Phase D-SUB — Sub-location Stock

  - Added Agent-owned `warehouse_sub_locations` metadata with unique per-Agent
    codes, active status, safe deactivation, and no login/user/hierarchy/referral
    identity.
  - Extended warehouse balances, movements, and transfer headers with
    `sub_location_id` context and location-aware uniqueness/constraints.
  - Extended the existing transfer service for Transit <-> Sub and same-network
    Sub <-> Sub physical movement. Factory Plan, Sales, and Shipping-to/from-Sub
    paths remain rejected. Transit-to-Sub validates projected sellable stock
    against reservations; Sub remains excluded from sellable calculation.
  - Reused paired transfer movements, row locking, idempotent completion,
    handover records, and A4 print. Handover print includes Sub code/name.
  - Added scoped Sub-location APIs, stock reads, transfer context, reconciliation
    location reporting, and minimum Agent/Admin/Gudang UI.
  - D-SUB tests and Phase C/D regression tests passed. Phase E behavior remains
    unimplemented: no opname, stock requests, fulfillment, cancellation/return
    redesign, Sheets, or production backfill.

  ## Phase E — Stock Opname + Reconciliation

  - Added normalized `stock_opnames` and `stock_opname_items` for separate
    `physical_opname`, `plan_reconciliation`, and read-only sellable diagnostics.
  - Gudang creates drafts, records counts, and submits. Only same-network Admin
    may approve or reject; Agent and Super Admin are not default approvers.
  - Physical approval supports Transit, Shipping, and Sub balances, applies the
    counted physical truth atomically, and records immutable `opname_adjustment`
    movements linked by `opname_id`. Reservations are preserved even when a
    genuine shortage creates a commitment deficit.
  - Approval locks the opname and target balance and rejects stale snapshots with
    a conflict instead of rebasing counts. Repeated approval is idempotent.
  - Plan reconciliation uses Plan-specific movement types and preserves the
    Phase C commitment guard. Sellable reconciliation remains derived/read-only;
    no Sales bucket is stored or adjusted.
  - Extended `warehouse:reconcile` with opname orphan, missing/duplicate
    application, invalid target, stale submission, Sales-row, and commitment
    deficit diagnostics. No automatic repair is performed.
  - Added the minimum Gudang/Admin opname UI and four-locale labels.
  - Explicitly not implemented: Stock Request, order `diproses` trigger,
    fulfillment, partial fulfillment, shipping order movement, cancellation or
    return redesign, Sheets, and production backfill.

  ## Phase F — Stock Request + Partial Fulfillment

  - Added one-per-order `stock_requests`, immutable order-item snapshots in
    `stock_request_items`, and persisted fulfillment idempotency operations.
  - Stock Requests are created exactly when an order first enters `diproses`,
    including COD orders created directly in that state. Database uniqueness and
    service locking make repeated handling idempotent.
  - Added Gudang-only fulfillment from physical Transit to Shipping. Fulfillment
    locks the request, item, Transit, Shipping, and reservation rows; decrements
    Transit and reservation together, increments Shipping, writes paired
    `fulfillment` movements, and derives pending/partial/fulfilled status.
  - Factory Plan is never used as a physical fulfillment source. Partial
    fulfillment remains on the same request and later completion closes it.
  - Added scoped Stock Request API/UI and read-only reconciliation diagnostics for
    missing requests, duplicate requests, item quantity drift, and missing
    fulfillment movement provenance.
  - Phase F tests and B/C/D/D-SUB/E/order fulfillment regressions passed.
  - Explicitly not implemented: cancellation reversal, return inspection/restock,
    damaged return disposition, storefront cutover, Sheets, and production
    backfill. Those remain outside Phase F.

  ## Phase G — Cancellation + Return + Shipping Reversal

  - Added centralized `InventoryCancellationService` and one-per-item reversal
    records. Unfulfilled quantities release reservations only; fulfilled
    quantities reverse Shipping to Transit with paired immutable
    `cancellation_release` movements. Stock Requests are marked cancelled while
    requested/fulfilled history remains intact.
  - Cancellation is transaction-safe and idempotent. Dispatched physical stock
    is not fabricated back into warehouse inventory when Shipping is unavailable;
    the operation is rejected for the return workflow instead.
  - Return approval no longer restocks automatically. Gudang must inspect received
    quantity and classify good/damaged. Only good units enter Transit through a
    `return_restock` movement; damaged units remain auditable and non-sellable.
  - Added quantity conservation, cross-Agent inspection authorization,
    inspection idempotency, and warehouse return UI/API. Existing payment/refund
    and courier/commission ledgers remain separate.
  - Extended `warehouse:reconcile` with cancellation reversal, reservation,
    return inspection, and Stock Request consistency diagnostics.
  - Explicitly not implemented: storefront cutover, Sheets, production backfill,
    and Phase H behavior.

  ## Phase H — Frontend Stock Cutover

  - Customer-facing Product and Variation resources now expose stable
    `stock.available` and `stock.in_stock` values for authenticated network
    viewers. Guest responses do not include numeric stock or legacy quantity
    fields.
  - Product listing/detail batch resolution uses `WarehouseStockService` and the
    approved formula: Transit plus enabled Factory Plan minus reservations.
    Sub, Shipping, and Sales are excluded.
  - Before Phase K backfill, targets with no warehouse row use the legacy
    `quantity_on_hand - quantity_reserved` value as an explicit compatibility
    fallback. Legacy and warehouse values are never added together.
  - Checkout quote and reservation admission use the same sellable service while
    preserving existing reservation storage and lifecycle.
  - Product dashboard summaries consume the canonical stock payload, and legacy
    Product-form stock mutation controls are disabled; Admin Product/Variation
    catalog CRUD remains available.
  - Added simple/variation formula and guest privacy coverage. Phase H tests,
    order/checkout regression, frontend type-check, and production build passed.
  - Explicitly not implemented: Phase I Sales-Kurir work, Sheets, production
    backfill, and comprehensive final audit.

  ## Phase I — Sales-Kurir Completion

  - Reconciled Sales-Kurir creation and conversion rules: Agent creation requires
    a same-network Korsal; Korsal creation attaches under self; only the owning
    Agent may convert an existing Sales account.
  - Preserved conversion identity, hierarchy, historical referral code, and
    history. New accounts use `SK-`; converted Sales codes are not regenerated.
  - Added Sales-Kurir to referral attribution, checkout eligibility, scoped order
    policy, user listing/profile routes, commission visibility, and responsive
    courier dashboard access. Sales and Courier ledgers remain separate.
  - Enforced idempotent internal Courier profile creation with a unique database
    constraint. Sales-Kurir remains outside warehouse permissions and cannot
    create hierarchy users.
  - Added permission/conversion regression coverage and preserved existing Sales,
    Courier, referral, fee, checkout, and self-purchase behavior.
  - Explicitly not implemented: Phase J reports/Sheets, production backfill,
    Phase K/L hardening, or comprehensive final audit.

  ## Phase J — Reports + Google Sheets

  - Reconciled the canonical item-level transaction dataset and existing XLSX
    exporter. The dataset preserves one row per OrderItem, historical SKU/name/
    price/subtotal snapshots, item courier assignment, Sales/Korsal attribution,
    and separate fee ledgers.
  - Completed the existing one-way Google Sheets architecture: whitelisted
    datasets, Agent-scoped destinations/configs, centralized Service Account
    credentials, deterministic tab replacement, sync locks, safe failure logs,
    and no Sheets-to-MySQL mutation path.
  - Added missing Sheets destination metadata migration and retained the existing
    authorized controller/UI route group. Sales-Kurir is included in Sales actor
    attribution and own-customer/fee reporting without opening branch-wide reports.
  - Phase J frontend type-check/build passed. Backend report regression was
    blocked by the pre-existing testing database migration repository/schema
    state (`migrations` table missing, then historical duplicate `province_id`
    migration); no production migration or backfill was run.
  - Explicitly not implemented: Phase K production backfill and Phase L final
    comprehensive audit.

  ## Phase L — Hardening & Full Regression

  - Completed a read-only requirements/security/inventory review across the
    cumulative Phase A-K implementation and ran PHP syntax diagnostics on changed
    PHP files. Frontend type-check and production build pass.
  - Hardened `warehouse:migrate-legacy-stock` and `warehouse:reconcile` to return
    explicit `BLOCKED` readiness errors when required warehouse schema is missing,
    instead of leaking raw SQL exceptions.
  - Production migration was not executed. No backup was claimed or assumed.
  - Full backend regression is not complete: the suite exceeded the execution
    window, and the focused warehouse suite hit a MySQL deadlock while dropping
    test tables before assertions. This is a test-environment blocker requiring
    database cleanup/serialization before a final audit can be declared ready.
  - Phase L status: PARTIAL — final comprehensive audit and production-readiness
    verdict remain outstanding.

  ## Final Audit Remediation

  - Configured PHPUnit to force the isolated MySQL database `primeclassy_testing`
    and verified the database name before destructive test reset operations.
  - Corrected Sheets migration ordering/duplication so a clean MySQL migration
    lifecycle completes successfully.
  - Added warehouse-authoritative legacy adjustment lockdown via
    `config/warehouse.php`; legacy Agent/Admin adjustment is denied when the
    authoritative flag is enabled while compatibility mode remains available.
  - Centralized sellable calculation in `SellableStockService`; warehouse read
    orchestration delegates to it. The canonical transaction report now exposes
    exactly the required 14 columns, while financial data remains separate.
  - Fixed the two Vue computed side-effect lint errors. Oxlint, TypeScript, and
    frontend build pass.
  - Phase K dry-run on clean isolated MySQL passed with zero rows and zero
    mutation. No production migration was executed.
  - Focused remediation tests passed: 53 tests, 393 assertions. The full suite
    was started but remained active beyond the execution window; final full-suite
    counts were not available at report time.

  ## Phase K — Legacy Stock Migration Tooling

  - Added durable `warehouse_migration_runs` and `warehouse_migration_markers`
    tables with source-row identity, run status, source quantity, destination,
    conflict/error state, and aggregate counters.
  - Added `warehouse:migrate-legacy-stock` with mandatory-safe `--dry-run`,
    `--agent`, `--batch-size`, and resumable `--run-id` options. Simple Product
    and Variation legacy `quantity_on_hand` map deterministically to Transit only;
    reservations remain logical and are never copied into Transit.
  - Existing warehouse targets are treated as conflicts rather than overwritten
    or incremented. Successful non-zero mappings create auditable
    `legacy_backfill` movements. Plan, Sub, Shipping, and Sales are never guessed.
  - Added a production cutover runbook with backup verification, write-freeze,
    reconciliation, rollback boundary, and explicit human authorization gate.
  - Production migration was intentionally not executed. Phase L comprehensive
    audit remains unimplemented.
  - The read-only dry-run was attempted and stopped because the current local
    database does not contain the Phase B `warehouse_stocks` table. No quantity,
    marker, or migration-run write occurred; migrations must be applied to an
    isolated test database before dry-run validation can proceed.
  partial fulfillment, cancellation/return warehouse flows, storefront
  cutover, production backfill, Google Sheets, and Phase E+ behavior.
