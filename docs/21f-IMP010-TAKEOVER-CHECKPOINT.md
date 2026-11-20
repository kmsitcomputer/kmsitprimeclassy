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

## EXACT NEXT ACTION (updated 2026-11-20, HEAD 6c003e4)

- Matrix: **243 GO / 0 PARTIAL / 132 Laravel-only** (375 routes).
- Completed this session:
  1. ef25a87 — shipment status transitions (2 routes, compare_shipment_status.py 19/19).
  2. 6c003e4 — courier assignment + eligible-couriers (2 routes, compare_courier_assign.py 20/20):
     assignCourier full port incl. SKS executor resolve, SC-03 gate, carve of wholly-unaccepted
     lines (assignFreshShipment reuse), A1-01 sentinel, idempotent replay, already_assigned raw key.
- Schema facts learned: activity_logs has NO updated_at (MariaDB 1054 "in 'INSERT INTO'");
  couriers HAS updated_at; stock_requests needs request_number; stock_request_items has no status col.
- Harness note: gen_i18n.py has an explicit KEYS whitelist — add new message groups there
  (courier.added: "assigned"); i18n catalog JSON is {locale: {key: text}}.
- NEXT: continue Shipping & delivery — PATCH /shipments/{shipment}/expedition-handover
  (multipart evidence upload → media + provider_meta.handover, REM-003), GET
  /shipments/{shipment}/receipt (ShipmentReceiptResource + ActivityLogger shipment.receipt_printed),
  delivery-verifications routes, then dispatch writes (2) + return-items courier assign (1).
- Harness rules unchanged: one runner at a time; restart pc_go_imp010 after code changes;
  Go container mounts go-backend at /src and runs `go run ./cmd/api` (rebuild on restart).
