# 21f — IMP-010 GLM TAKEOVER CHECKPOINT (Phase 0–5a complete)

Status: Phase 0–4 DONE, Phase 5a (3 PARTIAL closed) DONE at commit `5948405`. Next: Phase 5b — 143 Laravel-only routes.


## Position (all verified, 2026-11-20)

| Metric | Value |
|---|---|
| Canonical branch / HEAD | `imp010/go-backend` @ **`1de3f54`** |
| Canonical matrix | **375** = **229 GO** + **3 PARTIAL** + **143 Laravel-only** |
| Integration branch | `imp010/integration` (fully merged into canonical; kept as reference) |
| GLM branch | `imp010/glm-laravel-only` @ `845c9e4` (all work merged; retirement pending Phase 7) |
| Working tree (canonical) | clean |

## What the takeover did

1. **Backups** (verified, incl. untracked WIP): `/Users/kmsitcomputer/KMSIT-Server/backups/imp010-takeover-20261120-064545/`
   — imp010-glm-worktree.tgz SHA256 `17cb3828…`, imp010-go-worktree.tgz `b44f54c2…` (see SHA256SUMS).
2. **GLM CRM consent WIP verified + committed** (`845c9e4`): STORAGE_ROOT wiring, real evidence-file behavior — 17/17 PASS.
3. **Codex forensics**: checkpoint figures were STALE — Codex had already completed Product & Catalog (19 routes, commit `11daac7`, compare_products.py). The "12-endpoint Owner assignment" has no explicit record; nearest evidence = the Owner-priority product-catalog batch (19) + CMS homepage-block update WIP (1).
4. **Codex CMS homepage-block update WIP verified + committed** (`3eeeca8`): compare_cms_homepage_block_update.py 1/0 PASS.
5. **Integration**: clean merge GLM→canonical (0 conflicts), 1 stale test pin fixed (`3c50007`), matrix regenerated (`b7d19f7`).
6. **Integration regression suite** (merged runtime): notifications 97/0, media 73/0, admin-agents 107/0, reporting+audit 150/0, CRM writes 36/0, link 26/0, consent 17/0. Harness gotcha: run ONE at a time (shared DB); compare_crm_link changes user 13's email and now self-restores via atexit.
7. **PARTIAL verification round** (`compare_partials_glm.py`, 13/13): fixed real bugs —
   - reporting Transactions: `[]byte` fields serialized as base64 → normalized to strings; added missing `agent_id`, `sales_referrer_id`, `courier_id`, `sales_id`, `korsal_id` (exact Laravel projection, OrderTransactionReportService.php:44-68);
   - commissions index/summary: DECIMAL → "5000.00" strings, Carbon microsecond timestamps, summary top-level totals as ints;
   - referral preview 422: upstream defect — `'referral'` key declared TWICE in lang/{en,id}/messages.php (L98/L394, L109/L405) so the second array silently wins and `messages.referral.invalid_code` / `.code_not_found` never resolve; Laravel emits RAW KEYS in every locale (tinker-verified). Go reproduces the raw-key contract (documented in-code; consider docs/21b entry).
8. Reclassified 13 PARTIAL → GO. Remaining GAPS (3): register (welcome e-mail/WA queue bridge), sub-stock/my (`reserved` from order_items), warehouse/opnames (pending_catalog_change publication).

## EXACT NEXT ACTION (Phase 5b — 139 Laravel-only routes, resumed after `f09d819`)

Progress this session: 3 PARTIAL closed (`5948405`, 12/12) + dispatch reads (4 routes, `f09d819`, 31/31).
Regenerate the Laravel-only inventory from the CURRENT matrix before each domain (stale TSVs are dangerous).
Verified environment facts: orders/order_items/delivery_refusals have NO deleted_at column; couriers.type
enum('internal','external'); DeliveryRefusal::OPEN_STATUSES = ['with_courier','handed_back','received'];
courier fixtures need type='internal'. Harness: tools/parity/compare_dispatch.py (pattern reuse).

Domain order (dependency-aware):
1. **Shipping & delivery** (9: shipment status/items status/refuse/receipt/expedition-handover/courier assign/eligible-couriers/delivery-verifications GET+POST) + **delivery-refusals** writes (3: receive/dispose/hand-back) — same shipments domain, ShipmentPolicy executor rules.
2. **Courier** kurir (6: orders, reports/delivered, returns, returns/pickup-today, returns/{item}/confirm, returns/{item}/pickup).
3. **Dispatch writes** (2: split-batch, request-fulfillment) + **return-items courier assign** (1).
4. **Orders remainder** (8: assisted-returns, consent-context, consent-events, invoice, items, refund-recipient GET+POST, returns) + **order-items receipts** (2).
5. **consent-events** (4) + **consent** (2) — REM-005 chain.
6. **Invoices** (2), **dashboard** (2), **catalog** (3), **bestsellers** (1), **checkout** (2: courier-options, geocode).
7. **Admin operations** (~47 remaining: integrations SMTP 4, WhatsApp admin 10, google-auth 2, agents CRUD remaining 0 (done), audit-logs done, regions export/import 2, refunds obligations/attempts ~14, returns 2, return-items 1, others).
8. **Google Sheets** (7), **Installer** (7), **Profile** whatsapp/email (5).

Each domain: inspect Laravel controller/service/policy → implement (prefer a new internal/<domain> package
following internal/dispatch) → wire routes into the GLM slice + matchesGLMLaravelOnlyRoute + gen_matrix
IMPLEMENTED dict → write compare_<domain>.py (RBAC matrix + data-backed + DB state) → run against
pc_laravel_imp010:8899 / pc_go_imp010:8082 / primeclassy_testing (restart pc_go_imp010 after code changes;
NEVER run two harnesses concurrently) → scoped go test/vet → commit → regenerate matrix.

Phase 6 (final verification gate) then Phase 7 (retirement gates) then Phase 8 (final report) remain.

Phase 5a fixes landed (`5948405`):
- auth.Register → RegistrationNotifier port: waMsisdn + wa.enabled-gated whatsapp_messages ledger enqueue
  (idempotency key txn:account_registered:user:{id}, max_attempts clamped 1–5, after-commit, never fails signup).
- ApproveOpname → REM-01/D-07 catalog publication loop: reads pending_catalog_change + catalog_published_at,
  calls catalog.PublishCatalogChange (column-whitelisted UPDATE; attributes rewrite via replaceVariationAttributes),
  stamps catalog_published_at, all inside the approve transaction (rollback-safe).
- CreateOpname → LoadOpnameStoreResponse: the store response shape is `$opname->load('items')` — scalar-only
  opname (no status/approved/rejected) + scalar items (no product/variation) + intOrZero for the integer cast.
- Harness: tools/parity/compare_partials_final.py — 12/12 PASS.

Phase 6 verification gate and Phase 7 retirement remain gated on 375/375.

## Known gotchas

- `pc_go_imp010` must be restarted after canonical code changes (it binds the canonical worktree).
- Harnesses share `primeclassy_testing`: NEVER run two concurrently; reset (f.reset()+seed_full()) between scenario runs.
- gen_matrix.py IMPLEMENTED/GAPS are explicit dicts — every new route must be added there, then `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md`.
- DashboardSummary and other reporting.go handlers may share the []byte bug — audit remaining raw-Scan handlers in reporting.go during the reporting verification pass (DashboardSummary not yet data-backed compared).
