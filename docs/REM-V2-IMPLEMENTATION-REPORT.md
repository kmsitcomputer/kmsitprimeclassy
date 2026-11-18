> **Superseded continuation status:** see [Codex final technical remediation report](REM-V2-CODEX-FINAL-REPORT.md). The first/second-round notes below are preserved as implementation history; their ABA, CMS, source-aware catalogue/cart and canonical-field blockers are updated by the Codex report. Historical admission and final visual acceptance remain incomplete.

# REM-01–REM-11 — Implementation Report (working tree, NOT committed)

Baseline: branch `fix/transaction-reset-financial-dependencies`, HEAD `6a14041c`. No commit, push, deploy or production access. Owner Lock / Architecture Lock files untouched. Tests ran only on `primeclassy_testing` (container `primeclassy-fpm`, `DB_HOST=primeclassy-mariadb`); no Full Test.

## Status

| Item | Status |
|---|---|
| Group 1 — valid-Agent resolver, stale-link fail-closed, payload fingerprint | PASS — IMPLEMENTED & VERIFIED |
| Group 1 — private immutable evidence contract (generic) | PARTIAL — receipt evidence uses existing `media` FK + note; no new generic evidence store or checksum |
| REM-01 import/export | PARTIAL — see "Known limits" (no stock revision/ABA, no two-connection concurrency test, no spreadsheet UI beyond a compact panel) |
| REM-02 variant images | PASS — IMPLEMENTED & VERIFIED (backend); storefront variant-selector UI not changed |
| REM-03 edit images | PASS (backend + form now loads management detail); UI verified by type-check/build only |
| REM-04 guarded hard delete | PASS — product only; variation hard delete not added |
| REM-05 status filters | PASS (API + view); default Semua |
| REM-06 category hierarchy | PASS |
| REM-07 bestseller | PASS for backend contract; no CMS block/cache/UI wiring; legacy items have no receipts ⇒ section hidden until receipts are recorded |
| REM-08 variant attributes | PASS (API); no dedicated edit UI |
| REM-09 SKS stock source | PARTIAL — backend resolver unchanged and already covered (`SubStockSourceTest`); source-aware catalogue/cart/checkout UI NOT built |
| REM-10 invoice | PARTIAL — official logo bytes verified (SHA-256 `e2d724ee…52d3`); layout BLOCKED — FINAL DESIGN EVIDENCE MISSING |
| REM-11 historical POS | PARTIAL — preflight only (no writes). Admission, review/approval binding, payment/commission/report reconciliation, POS UI: BLOCKED/NOT STARTED |

## Technical deviations from the proposals

1. **Receipts are a new additive table `order_item_receipts`, not columns on `delivery_verifications`.** That table is shipment-keyed (NOT NULL `shipment_id`, enum outcome, append-only latest/history readers in `Order`, `Shipment`, `ShipmentCanonicalizationService`, resources, reset tooling); mixing item facts in would contaminate every reader. No synthetic rows for legacy data. FKs cascade from `order_items`/`orders` so existing reset commands need no change (MigrationVerification/reset suites pass).
2. **Import stock rows = one single-target physical opname per row** (carried by additive nullable columns on `stock_opname_items`: `import_row_key` UNIQUE, `import_fingerprint`, `import_target_quantity`, `pending_catalog_change`, `catalog_published_at`). Approval stays the existing Admin approval; the catalogue change is published inside the approving transaction (D-07), stale ⇒ existing 409.
3. Stock revision/ABA detector was NOT implemented (not all physical writers identified) — stale protection is the existing `current == system_quantity` check only.

## Known limits / residual risks

- Existing `StockOpnameService::create` does not verify a Sub Location belongs to the Agent (found, not changed — out of scope; import path does verify). Record as technical debt.
- Returns across multiple receipt events of one item are not allocated (no evidence-based rule): such items are excluded from the ranking rather than guessed.
- N-day window includes today (Asia/Jakarta); tie-break (units → latest receipt → product id) is an engineering choice, not an Owner rule.
- Import duplicate-row race is guarded by the DB unique index; no two-connection concurrency regression was written.
- `Super Admin`/`Admin`/`Agen` authority for product hard delete follows existing `ProductPolicy::delete` (Admin denied).
- Design assets under `architecture-lock/.../03-DESIGN-REFERENCES` are unverified candidates (purple POS, candidate invoice); nothing was built from them.
- The Lock README's "unmodified" claim for the six RECON closure copies is not byte-verifiable: they differ from the copies in `docs/` (escaping). Not edited (locked package).

## Remediation round 2 (local, uncommitted)

Done: Sub Location ownership enforced in `StockOpnameService::assertItem` (all callers); return→receipt allocation (`return_item_receipt_allocations`, `POST /return-items/{id}/receipt-allocations`) used by `BestsellerService`; two-connection tests for import-row replay, concurrent approval and category re-parenting (additive `catalog-import-rows`/`category-reparent` ops in `.phpunit-concurrency-actor.php`); REM-08 variant attribute editor and REM-09 SKS stock-source selector in the existing views; import panel with stock context, outcome/error labels, next-step guidance; invoice PDF accents changed from purple `#7c3aed` to the documented maroon token `#8B2635` (colour only, no layout change).

Blocked with evidence:
- **Stock revision/ABA:** physical `warehouse_stocks.quantity` is written by ≥11 code paths via query-level `increment/decrement/update` (StockOpnameService, StockRequestProposalService ×2, WarehouseStockRequestService, InventoryCancellationService, WarehouseStockService ×2, StockTransferService ×4, ReturnService ×2, SubStockService, MigrateLegacyWarehouseStock raw insert). No choke point, Eloquent events do not cover them. A DB trigger would prove coverage but needs TRIGGER privilege/binlog settings on every environment — not verified, so nothing partial was shipped.
- **REM-11 admission / D-04:** no closed/issued-report artifact, period lock, Finance reconciliation record or opening-balance mechanism exists (reports are live queries over snapshot tables; `orders` has no business-date or source-identity field). Admission and review binding need persistence that would be a new POS table or an invented accounting artifact — not created.
- **Design:** `design/` holds only the generic Premium Maroon dashboard spec; no approved POS or invoice mockup in the repo.
