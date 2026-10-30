# PRIMECLASSY-IMP-REM-BLUEPRINT — Consolidated Workstreams (revision 2)

Revision 2, 2026-10-28 (post RECON-002 consolidation, documentation-only mandate).
Revision 1 proposed packages A–F; **A–F were not adopted as IMP/REM numbers** — they are regrouped
below into cohesive workstreams with dependency-aware execution. Test / review / documentation
activities are gates inside workstreams, not new IMP packages.

## 1. Numbering register (`RESOLVED` as policy — Owner mandate 2026-10-28, G-09 verified reconciliation;
single legacy-label ambiguity remains open as **G-09a**, see below)

| Label | First known use | Status | Note |
|---|---|---|---|
| `IMP-001`…`IMP-004` | Historical: Codex-integrated audit era; register in `docs/CODEX-FINAL-INTEGRATED-AUDIT.md` §6–9; IMP-001 = Stock Sync / Payment Authority / Order Filters change request (`PRIMECLASSY_CHANGE_REQUEST_STOCK_PAYMENT_ORDER_FILTERS.md`), implemented per `BUSINESS-RULES.md` §24/§24a | historical, closed, **FROZEN** | **Must never be reused.** Source-embedded (133 docblock refs across backend/frontend; verified 2026-10-28). The Owner package list that mentioned `IMP-001` (see G-09a) is a distinct, unallocated legacy label — it does **not** re-point these historical IDs |
| `REM-001` | Sales/SKS parity (backend+frontend) | in progress (uncommitted) | live label, not reassigned |
| `REM-002` | External gateway removal | implemented, test pending | live label |
| `REM-003` (from `SALES-PARITY-CHECKPOINT.md`) | UserManagementView candidate list incl. SKS | implemented (verified by source); test outcome pending | live label; status was stale in the checkpoint |
| `IMP-005` | Scoped test verification gate for REM-001/002 | blocked→unblocked: test DB free at recon time | live label; **FROZEN — never reuse** |
| `IMP-006` | CRM / Sales Visit / Prospect development | scope **APPROVED** (OD-01…OD-06, 2026-10-28) / implementation **NOT_AUTHORIZED** | live label; **FROZEN — never reuse** |
| `IMP-007` | Docs update for parity/payment decisions | done (verified: `BUSINESS-RULES.md` §15/§20a/§25/§26) | live label; **FROZEN — never reuse** |
| `IMP-008` | **Dashboard Visual Isolation Foundation (DB-P01)** — Owner-allocated 2026-10-28 (`docs/PRIMECLASSY-IMP-008-OWNER-DECISION-HANDOFF.md`) | **`ACCEPTED WITH REMAINING VERIFICATION`** (Owner review 2026-10-28): scoped checks PASS; desktop dashboard + public homepage verified by Owner; responsive, dark mode and other public pages **NOT YET VERIFIED** | Scope: dashboard-only `.pc-internal` root + one dashboard-scoped Premium Maroon token stylesheet; public frontend frozen; does **not** include DB-P02…DB-P08, routes, navConfig, backend, RBAC or database changes. Acceptance: CSS isolation, shared-component safety, visual/public-parity verification, Git integrity. Consumed the moment declared; **FROZEN — never reuse** |
| `REM-004` | AU-01 / G-07 (no-active-Agen registration guard fix) | **IMPLEMENTED 2026-10-29** (503 + `registration_unavailable`; 2 regressions in `AuthTest`) — uncommitted | not to be repurposed for other work |
| `REM-005` | **WS-9 identity/consent foundation** — allocated by the Owner 2026-10-29 ("pake rem aja jangan imp") | **slice 1 IMPLEMENTED, scoped tests PASS** (`ConsentOtpTest` 12 / 114 assertions on `primeclassy_testing`); not migrated on DEV; CRM Customer link/unlink table NOT built (needs the CRM Customer entity, WS-5) | Owner decisions 2026-10-29: OTP = existing Laravel mail (SMTP); consent media = photo/screenshot + text note; retention = no automatic deletion until Owner sets a period. **FROZEN — never reuse** |
| `REM-006+` | next free remediation range | reserved | no package declared yet by guessing |

### 1a. Numbering policy (Owner-approved 2026-10-28; resolves G-09 numbering; evidence-locked)

1. **Single global, monotonically increasing IMP sequence.** One IMP number line covers both
   remediation-era and adaptation-era implementation packages. There is no parallel adaptation namespace.
   Allocation is strictly ascending; a number is consumed the moment a package is declared.
2. **`IMP-001`…`IMP-007` are FROZEN** against any reuse — historical (`IMP-001`…`IMP-004`, source-embedded)
   and live (`IMP-005`…`IMP-007`). Reuse is forbidden regardless of a package closing or being superseded.
3. **`IMP-008` was the next available number**, verified 2026-10-28: no `IMP-008` or higher package existed or
   was reserved at verification time (the only `IMP-008+` strings were forward-looking range pointers in
   this blueprint and `docs/RECON-002-CHECKPOINT.md`). **On 2026-10-28 the Owner allocated `IMP-008` to the
   Dashboard Visual Isolation Foundation (DB-P01)** — see the §1 register row and
   `docs/PRIMECLASSY-IMP-008-OWNER-DECISION-HANDOFF.md`. The next free number is now `IMP-009`
   (forward-looking pointer only — not a declaration; allocation happens only by explicit Owner instruction).
4. **`REM-###` is the remediation label family** (no historical `REM` existed, so no `REM` renumbering is
   needed). `REM-001`…`REM-003` are live/frozen in place; **`REM-004` stays reserved for AU-01 / G-07**; the
   next free remediation label is now `REM-006` (`REM-005` allocated to WS-9 on 2026-10-29).
5. **`G-09a` (CLOSED, Owner decision 2026-10-30):** the Owner package list `REM-001 / IMP-001 / REM-002`
   (2026-10-28) used `IMP-001` in a sense that cannot be reconciled from existing evidence. **Final decision:
   the legacy Owner-package label `IMP-001` is retired as a documentation-only alias** — it never re-points
   the historical `IMP-001` ID (register row §1 stays FROZEN) and **no alias, DB identifier, API contract or
   enum value was invented**. No user-facing UI surface displays `IMP-001` (source search: only historical
   code-comment/doc references, all preserved). The only display fixes applied under this decision were
   business-terminology labels unrelated to IDs: "Sales-Kurir" → "Sales-Kurir-Sub" in
   `frontend/src/views/dashboard/UserManagementView.vue` (convert confirm text + button) and
   `DashboardHomeView.vue` (role label); the `convert-to-sales-kurir` endpoint identifier and the
   `sales-kurir-sub` enum stay untouched. The numbering *policy* above was already fully resolved; with
   G-09a closed the whole numbering register is settled.
6. **Ambiguity rule for future mandates:** when an Owner instruction or any document cites a bare IMP/REM
   number, agents MUST resolve it against this register before acting; a new package is assigned the next free
   number from this register, never an existing or reserved one.

## 2. Workstreams

### WS-1 — REM-001 + REM-003 + REM-002 leftovers: feature remediation closure
- **Objective / scope:** close the three open remediation packages to a reviewable state.
- **Existing implementation:** REM-001 parity changes and REM-002 removal verified in source
  (`CLAUDE-CHANGE-VERIFICATION.md`); REM-003 frontend candidate list implemented.
- **Remaining gaps:** G-02 (parity tests unrun), G-04 (removal tests unrun), G-05 (Sheets-report tests
  unrun), G-11 (i18n `paymentGateway` leftovers), G-12 (optional `gateway_reference` rename), G-10
  (stale checkpoint statuses).
- **Dependencies:** single test-writer rule (WS-2 rule R-1). Independent items: G-11/G-12 may proceed
  while tests are blocked.
- **Risk:** dashboard/commission behavior unproven until G-02 runs; historical SKS self-purchase
  attribution question (G-13) stays with Owner.
- **Acceptance criteria:** each REM package has (a) real scoped-test counts, (b) no new failing
  surface, (c) doc statuses match evidence; REM-002 = gateway routes/webhook absent + manual/COD/DP
  payable (negative tests included in test file).
- **Required scoped tests:** `SalesKurirSubParityTest`, `PaymentGatewayRemovalTest`,
  `GoogleSheetsOrderReportsTest` (+ sheets order-reports regression set already in the file).
- **Independent review criteria:** diff-first vs HEAD `218db8c`; reviewer ≠ implementer; check
  authorization scope unchanged (no new cross-Agen rights), uniqueness/validation not weakened.
- **Owner gate:** acceptance after WS-2 full suite is green.

### WS-2 — Evidence closure: verification discipline + Review gate
- **Objective / scope:** turn current evidence debt into clean evidence; prerequisite to any review.
  Rules (from the mandate, restated as standing rules): single write owner per resource; **one writer
  for the shared test DB at a time**; agent runs scoped tests only, Owner runs full suites; bounded
  evidence-based retry; no repeated broad RECON; checkpoint before context compaction; no PASS claim
  without real evidence.
- **Existing implementation:** test DB currently free; concurrency incident documented (G-03).
- **Remaining gaps:** G-01 (full-suite artifact contaminated, Owner re-runs), G-03 (codify the rule in
  `OPERATIONS.md`), refund scoped-set evidence (part of G-06), G-14 (pnpm lockfile question → Owner).
- **Dependencies:** WS-1 (so the tree is stable during the Owner's run).
- **Risk:** another concurrent run would reproduce the missing-table fatal — R-1 is hard.
- **Acceptance criteria:** fresh full-suite record (pass/fail exact, run by Owner) + failure
  reconciliation with the prior artifact; every remaining failure classified with evidence.
- **Required scoped tests:** refund set `RefundLedger{Test,InvariantTest,IdorTest,AuditImmutabilityTest}` first (agent), full suite (Owner).
- **Independent review criteria:** review happens only on the settled tree after this WS.
- **Owner gate:** the full-suite run itself.

### WS-3 — Refund Ledger + Reports activation on DEV (Owner-gated, additive only)
- **Objective / scope:** run the 9 pending refund-ledger migrations and activate the two Sheets
  reports on DEV, with backup + reconciliation — each step individually authorized (AGENTS §9).
- **Existing implementation:** code + migrations + test files present, uncommitted; PC-GS reporting
  checkpoint awaiting Owner activation (`PC-GS-REPORTING-CHECKPOINT.md`); DEV currently unmigrated.
- **Remaining gaps:** G-06 (verify → migrate → verify), config/refunds.php activation decision,
  `GOOGLE_SHEETS_ENABLED`/report flip stays Owner-decision (D4/D5 automation and gateway refund-out
  remain OFF).
- **Dependencies:** WS-1/WS-2 evidence; explicit Owner approval per step.
- **Risk:** schema/financial surface — hence backup + row-count signature before/after; no backfill
  without separate approval; nohistorical production backfill at any point.
- **Acceptance criteria:** migrate:status clean on DEV; row-count + FK audit unchanged for existing
  tables; ledger invariants (§26) hold in scoped tests before the DEV run.
- **Required scoped tests:** refund set above + `RefundBackfillTest` (isolated test DB only).
- **Independent review criteria:** migration safety review (additive-only proof, idempotency, lock
  order) before execution.
- **Owner gate:** backup → approve migration → reconcile → approve activation.

### WS-4 — Decision-driven small fixes (after Owner decisions)
- **Objective / scope:** implement the two recorded product decisions once made:
  G-07 (registration without active Agen: 422 vs unlinked konsumen) and G-13 (historical SKS
  self-purchase attribution: additive reconciliation only, never a silent remap).
- **Existing implementation:** G-07 currently throws bare `RuntimeException` → 500.
- **Dependencies:** Owner decision per item; otherwise idle.
- **Risk:** low (service/test-level, no schema).
- **Acceptance criteria:** smallest change + regression test + doc statement updated in the same
  change (AGENTS §2).
- **Required scoped tests:** registration tests set / `SensitiveFieldMassAssignmentTest` (G-07);
  dashboard/consumer scope tests (G-13).
- **Owner gate:** each fix counted as its own authorized step.

### WS-5 — CRM / Sales Visit / Prospect (IMP-006 — scope APPROVED / implementation NOT AUTHORIZED)
- **Objective / scope:** new development per the SimpleV2 reference matrix
  (`PRIMECLASSY-SIMPLEV2-FEATURE-MATRIX.md` §1–3): login-independent CRM customer records, prospect
  tracking, visit log with history, seller-scoped CRM lists/reports, conversion flows, audited
  transfers that follow the current chain without rewriting historical orders (§24 semantics).
- **Existing implementation:** none (verified absent).
- **Dependencies:** scope approved under OD-01…OD-06 (design only); **buyer-origin G-17** and
  **identity/consent WS-9 (G-18…G-21, G-23)** remain; additive schema design DoR; FU/D **OPEN** (CI-07).
- **Risk:** highest new-surface risk; must not copy SimpleV2 stock/commission semantics; PrimeClassy
  invariants (stock source, hierarchy, ledger) remain authoritative.
- **Acceptance criteria:** package proposal approved by Owner before any schema work; authorization
  matrix written for every CRM/visit endpoint; positive + negative tests per endpoint.
- **Owner gate:** design approval → implementation → WS-1-style review → full suite.
- **Consent/identity prerequisites — OWNED BY WS-9 (single owner; do not restate here):** WS-5 consumes the
  WS-9 gate set (CI-01…CI-07; `BUSINESS-RULES.md` §27a, `ARCHITECTURE.md` §16a) as prerequisites. Concretely:
  CRM-only records are never fabricated Users; assisted checkout stays blocked on the buyer-origin contract
  (§16); assisted return initiation stays blocked until WS-9's consent contract exists (CI-02/CI-03);
  verified-email + single-use OTP linking (CI-01) is owned by WS-9 and is the only authorized linking mechanism;
  cross-Agent linking is rejected there (CI-05).
- **WS-5-owned acceptance gates:** CRM Customer model/workflows + prospect/visit/follow-up with per-endpoint
  source evidence; exactly one authoritative buyer origin with immutable buyer snapshot (G-17); CRM-specific
  Agent/role scoping; no rewrite of historical order/payment/refund/commission attribution; **FU/D remains OPEN**
  (CI-07) — do not finalize the visit stage list. All consent/OTP/link/unlink acceptance is delegated to the WS-9
  gate set (single owner) — WS-5 cites it rather than duplicating it.

### WS-6 — Documentation maintenance (not an IMP/REM package)
- **Objective / scope:** execute the RECON-002↔consolidation chain (this document set), fold temporary
  checkpoints into canonical docs when their packages close, and carry the cleanup proposal to
  completion **only after per-candidate Owner approval**
  (`DOC-INVENTORY-CLEANUP-PROPOSAL-001.md` §C; all candidates `PENDING`).
- **Current state:** no document deleted; inventory + proposal exist; decision register extended.
- **Blockers:** none for documentation work; deletions require Owner approval.

### WS-7 — Canonical collectible-AR remediation (K-5 / `G-15`) — **APPROVED_DESIGN / PENDING_IMPLEMENTATION**
- **Objective / scope:** unify all collectible-AR consumers (`financeOrders`, `networkSummary`, per-Agent report,
  datasets `payment_status`/D13 and `financial_summary`/D17, plus any reader discovered in step 1) onto the M-2
  derived basis with OD-K5-1 eligibility rules, without touching M-1 stored-field semantics, M-3 snapshots, the
  payment/refund ledgers, or payment-event logic.
- **Label:** `OWNER_ALLOCATION_REQUIRED` — **no IMP/REM number is assigned to WS-7 by this document**; the next
  free numbers (after `IMP-008` was consumed by DB-P01: `IMP-009`, `REM-005`) remain untouched per the numbering policy (§1/§1a). Allocation happens only
  by explicit Owner instruction.
- **Dependencies:** OD-K5-4 reconciliation-first (7 steps, TRACE Appendix C), authorized **Keuangan review and
  sign-off**, Owner approval for rollout and any presentation-contract (Sheets/report column) change with
  workbook evidence per `BUSINESS-RULES.md` §20b.
- **Findings to resolve (source-confirmed, not yet runtime-tested):** KF-1 AR undercount for never-mutated
  non-DP orders; KF-2 missing order-status eligibility (cancelled phantom / refund-pending distortion); KF-3
  misleading stored-0 rows exported by D13.
- **Future acceptance gates (design; not tests authorized for execution now):**
  1. Non-DP unpaid orders appear correctly in collectible AR.
  2. Pending-verification payments remain outstanding until approved (§OD-K5-2).
  3. Cancelled orders excluded from collectible AR but remain auditable; refund/settlement liabilities stay visible.
  4. Partially paid and refunded orders are categorized correctly (AR vs payable vs settled).
  5. Overpayment liabilities separated from AR without double-counting executed refunds.
  6. Laporan A initial snapshots remain unchanged (M-3 immutable).
  7. Laporan B reflects the approved running-receivable semantics (already conformant; must not regress).
  8. Agent isolation and the existing 11-role authority matrix remain enforced on every surface.
  9. Every report/Sheets change reconciled and signed off by Keuangan; rollout approved by Owner.
  10. No silent overwriting of manually maintained Google Sheets columns (workbook evidence per §20b).
  11. Historical transaction and payment records remain intact — no backfill, no rewrite (OD-K5-5).
- **Owner gate:** reconciliation artifact + Keuangan sign-off + explicit label allocation before any implementation.

### WS-8 — Refund commission adjustment (OD-05 / `G-16`) — **APPROVED_DESIGN / NOT_IMPLEMENTED** — working label, **no IMP/REM allocated**
- **Objective / scope:** future implementation of the §15a hybrid commission-adjustment policy — compensating
  journal generation on approved refund obligations, bounded 2-decimal allocation, reversal/reinstatement, and
  (post external-payout-verification) separate recovery balances. **Deliberately separate workstream from WS-7:
  OD-05 commission policy and OD-K5 receivable policy are independent and must not be co-implemented to save
  effort.**
- **Label:** working identifier `WS-8` is a blueprint-internal workstream label only. `OWNER_ALLOCATION_REQUIRED`
  for any implementation package number — `REM-005` and all other numbers remain unallocated (`IMP-008` is consumed by DB-P01; see §1).
- **Dependencies:** Owner approval of each exception entry (OD-05c); discount-allocation design (OD-05b);
  external payout verification design (OD-05e); migration authorization for OD-05g schema readiness
  (courier role + unique adjustment key + provenance columns); rounding spec (OD-05f).
- **Future acceptance gates (documentation only — not authorized for execution now):**
  1. Full and partial refunds adjust entitlements exactly per §15a basis.
  2. Multiple partial refunds — cumulative adjustment bounded by original entitlement.
  3. Refund approval, cancellation, reversal, and reinstatement each behave per OD-05d (compensating entries only).
  4. Duplicate events produce no double adjustment (unique adjustment identity/idempotency key).
  5. Product discount allocation is deterministic and documented (no silent algorithm).
  6. Retained shipping and administration fees are excluded per OD-05b.
  7. COD, DP, and installment flows never redefine per-unit snapshots.
  8. Self-purchase commissions adjust toward the buyer-beneficiary identically to any Sales.
  9. Sales-Kurir-Sub sales/courier lines stay separate; no automatic courier clawback.
  10. Responsibility exceptions apply only with registered Owner-approved policy_version entries.
  11. External payout reconciliation verified before any recovery balance exists; no assumed paid knowledge.
  12. Recovery balances never auto-deduct or auto-offset (OD-05e).
  13. Agent isolation and the 11-role authority matrix remain enforced on every adjustment path.
  14. Original commission rows and historical snapshots are immutable (byte-level invariant checks).
  15. Rounding bounded; remainders explicit; no negative payable commission without approved recovery treatment.
  16. Integration with the refund ledger never double-counts (R_c reconciliation + `config/refunds.php` read-switch respected).
- **Owner gate:** exception approvals + migration authorization + label allocation + full WS-8 gate pass on the
  settled tree (Owner-run full suite) before any production effect.

### WS-9 — Identity, consent & linking (CI-01…CI-07) — **APPROVED_DESIGN / NOT_IMPLEMENTED** — working label, **no IMP/REM allocated**
- **Objective / scope:** build the identity/consent foundation required by WS-5 (CRM) and assisted returns:
  verified-email + single-use OTP channel, CRM↔Konsumen link/unlink lifecycle, append-only consent events,
  withdrawal/dispute handling, cross-Agent rejection, and evidence retention references. **Distinct from WS-5**
  because these mechanisms span assisted orders, returns and identity rather than CRM CRUD alone.
- **Label:** `WS-9` is a blueprint-internal working label only; any implementation package number is
  `OWNER_ALLOCATION_REQUIRED` (`REM-005` and later numbers remain unallocated; `IMP-008` is consumed by DB-P01).
- **Dependencies:** Owner choices for OTP/email provider (CI-01), accepted consent media + identity
  verification (CI-02), retention & legal hold (CI-06), Agent-transfer process (CI-05), dispute policy (CI-04);
  buyer-origin schema approval (ARCHITECTURE §16 / G-17) where linking touches orders.
- **Future acceptance gates (documentation only — not authorized for execution now):**
  1. Link possible only via verified email + single-use OTP with expiry/attempt limits/replay protection.
  2. Email/phone equality alone never links and never discloses a link.
  3. Cross-Agent linking rejected; no automatic reassignment or merge.
  4. Unlink is append-only, audited, prospective; historical orders/payments/refunds/commissions unchanged.
  5. Consent evidence binds customer/order/return/item-scope/purpose/media/actor/timestamp/Agent.
  6. Consent withdrawal/dispute suspends new dependent actions only; completed events untouched.
  7. Assisted return initiation blocked without valid consent; Sales/SKS gain no approval/execution authority.
  8. Retention follows the approved policy; no automatic evidence deletion; legal hold respected.
  9. FU/D remains OPEN — no naming, removal, or restriction decisions inside this workstream.
  10. Agent isolation and the 11-role authority matrix enforced on every new endpoint.
- **Owner gate:** provider/media/retention/transfer decisions + label allocation + full acceptance-gate pass on a
  settled tree (Owner-run suite) before production effect.

### WS-10 — Reporting & Google Sheets presentation parity (OD-06) — **PARTIALLY IMPLEMENTED (structure + functional + manual-cell protection) / VISUAL GATE BLOCKED (`EXTERNAL_EVIDENCE_REQUIRED`)** — **no IMP/REM allocated; executed under Owner's 2026-10-29 Phase 2 reporting directive**
- **Classification:** documentation working label; executed only for the scope the Owner explicitly unblocked
  (column mapping, data sources, RBAC, exports, consolidation, manual-cell protection). **Visual parity remains
  gated on the actual workbook.** Scope item 10 (visual) = NOT_VERIFIED by definition. No K-5/WS-7 AR-policy or
  OD-05/WS-8 commission change was introduced; the two financial-adjacent alignments made here are to the
  ALREADY-approved canonical semantics only: Laporan B "Sisa Piutang" now uses `CollectibleReceivable` (was
  inline, KF-2-divergent) and Sheets `financial_summary.refund_amount` honours `refunds.reporting_source`
  (default `legacy` → zero behaviour change today, divergence-free when the ledger is activated).
- **Implementation status (updated 2026-10-29, second reporting session):** scope 1–7 + 11 + 12 delivered and
  corrected per the Owner's mapping decisions. **Laporan C `transaction_worksheet`** (47 columns, SV2 order) now
  classifies only FIVE columns as MANUAL (`money_position`, `update_payment_status`, `update_money_position`,
  `settlement_note`, `settlement_total`); DP / Cicilan 1 (+date) / Pelunasan (+date) are **DERIVED** from
  verified `payment_transactions` rows carrying the canonical `raw_payload.note` markers (`down_payment`,
  `dp_settlement`; status='paid' only — pending money never counted), Driver/phone are **SYSTEM** (Owner:
  Driver=Kurir via `couriers.user_id`), Hampers is **DERIVED** from the product category, region columns 44–47
  are **SYSTEM** (order region snapshots). Sync for protected datasets switched from column-protection to
  **row-anchored merge**: existing rows update in place keyed by the `No` identity column (order_items.id),
  staff cells are never cleared nor rewritten, unseen rows append, unmatched old rows persist verbatim,
  repeated syncs are idempotent, and a protected config without the identity column fails safe. New reports:
  `/reports/network-transactions` (§7 network totals; order-grained, TANPA SALES/KORSAL groups separated,
  canonical outstanding) and `/reports/crm-visits` (§6 funnel 0–7/N verbatim from SV2 labels) — both with
  xlsx exports, role-scoped routes and dashboard views; finance-orders gained its xlsx; dashboard homes show
  the Pending/Approved/Paid/Reversed commission strip for every entitled role (gudang excluded).
- **Explicitly NOT available:** cash-position buckets (SV2 `bca_herdi` etc.) have no PrimeClassy field — the
  column stays a clearly labelled manual/BLOCKED cell; nothing was invented. Visual workbook parity remains
  `EXTERNAL_EVIDENCE_REQUIRED`.
- **Scope:**
  1. SimpleV2 reporting-format inventory (reports and Sheets payloads).
  2. Existing PrimeClassy report/dataset inventory.
  3. Source-level **43-column** transaction-export mapping.
  4. Source-level **47-column** Sheets payload mapping.
  5. Worksheet/column mapping where supported by evidence (worksheet names are admin free-text today).
  6. Agent/role-scoped export and report visibility.
  7. Protection of manually maintained Google Sheets cells.
  8. Structural parity acceptance gate.
  9. Functional parity acceptance gate.
  10. Visual parity acceptance gate using the **actual workbook**.
  11. Source-versus-design evidence classification for every column.
  12. Preservation of existing report and financial semantics.
- **Explicit constraints:** the actual Google Sheets workbook is **not yet available** for visual verification →
  `EXTERNAL_EVIDENCE_REQUIRED`; **no automatic replacement** of manually maintained cells; **no Google Sheets
  modification** is authorized by this document; no financial-policy change (see WS-7/WS-8 boundaries).
- **Owner gate:** the 2026-10-29 Phase 2 directive lifted the pre-implementation gate for everything except
  visual parity; the workbook evidence + per-column mapping approval remain required **before any WS-10 PASS
  claim or production Sheets activation**. Sheets modifications stay one-way export only.

## 2a. Interim workflow-preservation register (WF-01…WF-29)

- The 29 workflow-group register is maintained as an **interim preservation reference** in
  `design/FINAL-MOCKDESIGN-SPEC.md` §4 (Owner-transcribed, audit-derived record, IDs/names/roles/gates
  verbatim). The original `PC-SV2-WORKFLOW-001 / PC-SV2-RBAC-001` audit bodies remain
  `ORIGINAL_AUDIT_NOT_RECOVERED` (see `docs/OWNER-DECISION-TRACEABILITY.md` Appendix B); this register is
  not the original audit and must not be cited as such.
- **Every future adaptation package (WS-5 CRM/Visit, WS-7 financial reporting, Sheets parity, role dashboard
  work) treats the affected WF-### gates as preservation requirements.** No gate in §4 may be weakened by a
  design artifact or UI change.
- **Authority boundaries are unchanged:** all 11 roles keep their existing backend authorizations
  (`BUSINESS-RULES.md` §2 + policies/middleware). WF gates describe what must remain true; they grant nothing.
- **Verification rule before implementation approval:** for each workflow touched by a package, the proposer
  must present current source evidence (`route → controller → policy/service → test`, file:line) proving the
  gate holds today, plus the plan's regression coverage proving it will still hold after; runtime evidence is
  produced only through the normal DEV UAT → Owner stage gate. Sample-level verification of the register to
  date is explicitly NOT complete verification and NOT runtime validation.
- If the original audit document is later placed in the repository, this section is re-pointed to it and the
  interim provenance note is retired by a dated amendment (no silent replacement).

## 3. Execution graph

```
WS-1 (feature closure)          ── scoped tests by agent, G-11/G-12 in parallel
      │
      ▼ (tree settled)
WS-2 (Owner full suite + review gate)  ── single test-writer R-1 mandatory
      │
      ▼
WS-3 (Owner-gated DEV migration + activation)   [parallel-safe doc work in WS-6]
      │
      ▼
Owner stage gate (DEV UAT → staging; production only with explicit Owner approval)
      ║
WS-4 (needs 2 Owner decisions)   ·   WS-5 (scope APPROVED — implementation NOT_AUTHORIZED; depends on WS-9 identity/consent and G-17 buyer-origin)   ·   WS-7 (K-5 AR remediation; label OWNER_ALLOCATION_REQUIRED, gated by OD-K5-4 reconciliation + Keuangan sign-off)   ·   WS-8 (OD-05 commission adjustment; independent of WS-7; label OWNER_ALLOCATION_REQUIRED; gated by exception approvals + OD-05g migration authorization + payout-verification design)   ·   WS-9 (identity/consent/linking; label OWNER_ALLOCATION_REQUIRED; gated by provider/media/retention/transfer decisions)   ·   WS-10 (reporting/Sheets presentation parity — documentation working label only; label OWNER_ALLOCATION_REQUIRED; visual gate EXTERNAL_EVIDENCE_REQUIRED)
```

Nothing in this blueprint is executed by this document. No PASS/complete claim is made for any
workstream.
