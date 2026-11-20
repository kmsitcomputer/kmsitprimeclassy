# 21f — IMP-010 GLM TAKEOVER CHECKPOINT (Phase 0–4 complete)

Status: Phase 0–4 DONE, Phase 5 in progress. This is the resumable state; read BEFORE touching code.

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

## EXACT NEXT ACTION (Phase 5 continues)

1. Port the 3 remaining PARTIAL routes (small): sub-stock/my reserved derivation; opnames pending_catalog_change publication; register queue bridge (may need the jobs worker — verify scope first).
2. Then the 143 Laravel-only routes from `tools/parity-glm/laravel_only_inventory.tsv` (STALE — regenerate it from the matrix before use). Domain order suggestion: Dispatch (9), Shipments (9), kurir (6), order-items (2), invoices (2), consent (6), dashboard (2), catalog reads (4), checkout (2), auth register bits, profile whatsapp/email 5, google-sheets (7), install (7), admin remainder (~40: integrations SMTP/WhatsApp/google-auth, agents CRUD remainder, regions export/import, refunds obligations/attempts ~14, returns, delivery-refusals, orders remainder incl. assisted-returns/invoice/items/receipts/refund-recipient, CRM assisted-order).
3. Environment: canonical containers pc_laravel_imp010:8899 + pc_go_imp010:8082, DB `primeclassy_testing` (korsal user 20 seeded by compare_users.py — CRM harnesses depend on it). GLM env (8898/8083, `primeclassy_testing_glm`) still up as a second runtime.
4. Harness pattern: `tools/parity/compare_partials_glm.py` (compare_fulfillment helpers; remember tuple-vs-list in normalizers).
5. After all routes GO: regenerate matrix → Phase 6 checks → Phase 7 retirement gates (compare backups vs canonical, then remove `primeclassy-imp010-glm` worktree + `imp010/glm-laravel-only` + `imp010/integration` branch) → Phase 8 final report. Do NOT delete the canonical worktree or the copilot worktree without its own gate check.

## Known gotchas

- `pc_go_imp010` must be restarted after canonical code changes (it binds the canonical worktree).
- Harnesses share `primeclassy_testing`: NEVER run two concurrently; reset (f.reset()+seed_full()) between scenario runs.
- gen_matrix.py IMPLEMENTED/GAPS are explicit dicts — every new route must be added there, then `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md`.
- DashboardSummary and other reporting.go handlers may share the []byte bug — audit remaining raw-Scan handlers in reporting.go during the reporting verification pass (DashboardSummary not yet data-backed compared).
