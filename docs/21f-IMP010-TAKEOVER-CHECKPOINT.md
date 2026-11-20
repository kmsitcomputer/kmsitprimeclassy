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

## EXACT NEXT ACTION (updated 2026-11-20, HEAD 5ca162e)

- Matrix: **250 GO / 0 PARTIAL / 125 Laravel-only** (375 routes). Domain 1 (Shipping, dispatch,
  courier, returns) COMPLETE — all 18 routes of the shipping block are GO:
  shipments status (2), courier assign + eligible (2), expedition-handover + receipt (2),
  delivery-verifications (2), dispatch writes split-batch + request-fulfillment (2),
  return-items courier (1), plus earlier refusal/status/refusal routes.
- Commits this session: 28a03de (handover+receipt), 4aa51e1 (delivery-verifications),
  08ad48f (dispatch writes), 5ca162e (return courier assign). All harnesses PASS:
  compare_handover_receipt 17/17, compare_delivery_verifications 20/20,
  compare_dispatch_writes 19/19, compare_return_assign 15/15.
- Key parity facts learned:
  - Laravel multipart PATCH: PHP does not populate $_POST for multipart PATCH — the success
    path is only reachable via POST + _method=PATCH spoofing; Go mirrors this exactly
    (plain multipart PATCH → 422 all-required, like the reference).
  - Provider codes are 'rajaongkir'/'pickup' (NOT 'expedition'); Shipping label map
    {openroute: Kurir Lokal, rajaongkir: Ekspedisi}.
  - whenLoaded semantics: assign/handover OrderResource loads ONLY items + shipments.courier.user
    (no konsumen/sales/korsal/returns/payment, no item shipment context); StockRequestResource
    without product/variation/order loaded omits those keys entirely.
  - ApiException errors payload is a SCALAR string (not array) — use httpx.Error.ErrorsAny.
  - delivery_verifications has updated_at; return_items requires return_id + refund_amount
    (NOT NULL) and has no 'reason'/'quantity'/'requested_by' columns (those live on 'returns').
  - i18n: message whitelist lives in gen_i18n.py KEYS; courier group added.
- NEXT DOMAIN (per plan): orders/consent chain (16 routes) — inspect Laravel
  OrderController (update, cancel, payment-proof, COD, bank-transfer verification,
  assisted orders, CRM order linking, customer consent) then continue the per-batch loop:
  inspect → implement (orders package or new internal package) → wire GLM slice + gen_matrix
  IMPLEMENTED dict → harness → scoped go test/vet → commit → regen matrix.
- Harness rules unchanged: ONE runner at a time (shared DB primeclassy_testing), restart
  pc_go_imp010 after Go changes (go run ./cmd/api, mounts /src), seed via
  compare_fulfillment.seed_full + domain seeds; scrub timestamps (TS regex) and scrub
  ids in dict payloads; heredocs eat backticks — use editor tool or printf|python3 for
  SQL/code containing quotes.
