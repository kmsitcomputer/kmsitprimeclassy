# OWNER-DECISION-TRACEABILITY (RECON-002)

Rule: a decision gets a trace row only when verifiable in local documents. Decisions stated in the
RECON-002 mandate but found only in the mandate text (not in local docs) are marked
`OWNER DECISION PROVIDED — LOCAL DOCUMENT TRACE MISSING` — they are NOT downgraded to UNAPPROVED.

| ID | Decision (owner meaning) | Local trace source | Implementation (source path) | Classification |
|---|---|---|---|---|
| D-01 | Sales-Kurir-Sub = 100% Sales rights + own SKS rights, separate role | `docs/SALES-PARITY-CHECKPOINT.md` Decision Register; `docs/REFUND-LEDGER-CHECKPOINT.md` governance | `Role::sellerSlugs()`; `ReportService::dashboardSummary`; `routes/api_v1.php` gates; `OrderService` commission beneficiary; `ReferralReassignmentService`; frontend `navConfig/DashboardHomeView/UserManagementView` | PARTIAL (code verified; tests unrun) |
| D-02 | SKS self-purchase sales fee to itself; agent/courier fees separate | `docs/SALES-PARITY-CHECKPOINT.md` D-02 (recorded as Owner implication) + `docs/BUSINESS-RULES.md` §15 note (now "resolved, Owner decision") | `OrderService::recordCommission` | PARTIAL (test pending) |
| D-03 | Payments: manual bank transfer + COD only; DP/cicilan/pelunasan are schemes | `docs/BUSINESS-RULES.md` § 15/24/25 + `SALES-PARITY-CHECKPOINT.md` D-03 | `PaymentService` (manual/COD/DP-scheme paths verified) | COMPLIANT |
| D-04 | Remove Xendit/Tripay/Stripe, keep history | `SALES-PARITY-CHECKPOINT.md` D-04 + `BUSINESS-RULES.md` ("removed by Owner decision") | 9 deleted files; routes; seeder; frontend; per-agen bank config retained | PARTIAL (test pending; i18n leftovers LOW) |
| D-05 | CRM Customer/Prospect/Sales-Visit: Owner has NOT approved as implemented | `SALES-PARITY-CHECKPOINT.md` D-05 ("not Owner-approved; not implemented") | absent (verified) | COMPLIANT with "not implemented" state; awaiting decision (IMP-006) |
| D-06 | Assisted ordering for no-account customers: restricted to authorized sellers only; NO public guest checkout | Not found in local docs as a recorded decision | `CheckoutTest`, checkout policy in `BUSINESS-RULES.md` § order creation (sellers create orders; registered checkout for konsumen) | OWNER DECISION PROVIDED — LOCAL DOCUMENT TRACE MISSING (implementation appears aligned; classify after trace) |
| D-07 | No public guest checkout | Same as D-06 (implicit in mandate + `BUSINESS-RULES.md` requires login/role for checkout paths) | route auth middleware (verified: `auth:sanctum` on order routes) | OWNER DECISION PROVIDED — LOCAL DOCUMENT TRACE MISSING |
| D-08 | Actor/customer/orderer/recipient identity clarity | `docs/BUSINESS-RULES.md` §3 + §20a (snapshot rules for names/addresses) | `StoreOrderRequest`, `OrderService` snapshot logic | COMPLIANT (docs) |
| D-09 | PrimeClassy stock ledger/warehouse authoritative (no SimpleV2 stock semantics) | `docs/ARCHITECTURE.md` + `BUSINESS-RULES.md` inventory sections | `StockService`, `StockRequestService` (verified paths; invariants tested in suite) | COMPLIANT |
| D-10 | Payment = manual transfer + COD; gateway history preserved (see D-04) | SaaS docs + `BUSINESS-RULES.md` | `payment_transactions` historical rows preserved; `AgentPaymentGatewayConfig` retained for bank accounts | COMPLIANT |
| D-11 | Commission & refund corrections have audit trail | `docs/REFUND-LEDGER-CHECKPOINT.md` + `BUSINESS-RULES.md` §26 (LOCKED) | `CommissionAdjustment`, `RefundLedgerEvent`, `RefundLedgerController` | PARTIAL (immutable-audit test present, unrun) |
| D-12 | COD custody & settlement controlled | `BUSINESS-RULES.md` (COD confirm races tested), `CourierService` | `CodPaymentProof`, COD confirm path | PARTIAL (test outcomes unverified) |
| D-13 | Dashboards/reports per role & scope | `BUSINESS-RULES.md` §20a (LOCKED 2026-10-21), `ReportService` | `ReportService::scopeToActor`, `dashboardSummary`, reports A/B | PARTIAL (suite evidence pending) |
| D-14 | Excel/CSV primary; Google Sheets optional | `BUSINESS-RULES.md` §20a ("Google Sheets hanya tujuan ekspor satu arah") | `SheetsController`, `DatasetRegistry` | COMPLIANT (docs) |
| D-15 | DEV local → isolated staging → production only with Owner approval | `docs/OPERATIONS.md`; `AGENTS.md` §9-10 | n/a (governance) | COMPLIANT (process) |
| D-16 | Safe, reconcilable DB migrations | `AGENTS.md` §9 | refund migrations are additive; none executed on DEV | COMPLIANT (design); execution pending |
| D-17 | Full test run by Owner in PowerShell | `SALES-PARITY-CHECKPOINT.md` D-06 + `AGENTS.md` §7 | n/a (process) | PENDING owner action (GAP-01) |
| D-18 | Refund Ledger scope/ledger invariants (§26 set) | `BUSINESS-RULES.md` §26 (Owner mandate 2026-10-22), `docs/REFUND-LEDGER-CHECKPOINT.md` | `config/refunds.php`, Refund services/models, ledger migrations | PARTIAL (implementation unmigrated, unverified) |

Explicitly checked and **not registered** locally: "historical hierarchy immutable", "immutable
historical transactions" found as internal evidence in service implementations and §20a (snapshot
immutability); no standalone decision document found — treated as doc-level rules, not fabricated
decision IDs. No decision numbering beyond this file was invented; D-IDs are local to this recon.

No `IMP###`/`REM###`-numbering decisions exist in authoritative docs besides the temporary checkpoint
(see `PRIMECLASSY-IMP-REM-BLUEPRINT.md` §numbering).

---

# Appendix — Post-RECON-002 consolidation register (2026-10-28)

Documentation-only consolidation of the RECON-002 trace. Decision contents are unchanged; only the
source-trace status is restated under the consolidation taxonomy. No numbered decision register exists
beyond this file yet (the "33 decisions" figure from Owner discussion history was NOT verified against
any local source and is therefore not adopted here).

| Trace | Decision | New status | Local source evidence |
|---|---|---|---|
| D-01 | SKS = 100% Sales rights + own rights, separate role | `VERIFIED_OWNER_DECISION` | `SALES-PARITY-CHECKPOINT.md` Decision Register D-01; `BUSINESS-RULES.md` §25 + § roles table (`sales-kurir-sub` row) |
| D-02 | SKS self-purchase sales fee to itself; agent/courier fees separate | `VERIFIED_OWNER_DECISION` | `SALES-PARITY-CHECKPOINT.md` D-02; `BUSINESS-RULES.md` §15 commission rule incl. `sales-kurir-sub` buyers; §25 note "resolved, Owner decision" |
| D-03 | Manual bank transfer + COD only; DP/cicilan/pelunasan as schemes | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §15/§24/§25 ("External gateway methods were removed (Owner decision); manual transfer is the only proof-based method"); `PaymentService` |
| D-04 | Remove Xendit/Tripay/Stripe without losing history | `VERIFIED_OWNER_DECISION` | `SALES-PARITY-CHECKPOINT.md` D-04; `BUSINESS-RULES.md` ("removed by Owner decision"); 9 deleted source files |
| D-05 | CRM Customer/Prospect/Sales-Visit not Owner-approved; not implemented | `DOCUMENTED_BUT_NOT_APPROVED` | `SALES-PARITY-CHECKPOINT.md` D-05 (agent classification explicitly pending Owner) — absence verified in code |
| D-06 | Assisted ordering/payment by authorized internal roles only | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §24 "Assisted consumer payment (IMP-001)" + route gate `konsumen,agen,korsal,sales,sales-kurir-sub` on `POST /orders` (`OrderPolicy::create`); the historical IMP-001 change request (`PRIMECLASSY_CHANGE_REQUEST_STOCK_PAYMENT_ORDER_FILTERS.md`) |
| D-07 | No public guest checkout | `VERIFIED_OWNER_DECISION` — with limitation note: **no standalone decision paragraph** exists locally; the code-level proof is complete (order creation is role-gated; only quote/steps/regions lookups are public) | `routes/api_v1.php` middleware group at `POST /orders`; `BUSINESS-RULES.md` §24 |
| D-08 | Actor/customer/orderer/recipient identity + audit trio | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §3 + §24 (Pembayar/Bukti/Verifikasi trio; snapshot rules §20a) |
| D-09 | PrimeClassy stock ledger authoritative (warehouse-first, reservations) | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §8/§25 ("warehouse-authoritative mode is the default"); `ARCHITECTURE.md` |
| D-10 | Payment verification authority split (submit ≠ verify) | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §24 ("Authority (Human change request, supersedes UAT-008)") |
| D-11 | Commission & refund corrections append-only with audit trail | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §26 (**LOCKED**, Owner mandate 2026-10-22); `REFUND-LEDGER-CHECKPOINT.md` §26 trace |
| D-12 | COD custody & settlement controlled | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` cod rules (§24 trio; confirm races covered by suite) |
| D-13 | Dashboards/reports scoped per role & Agent | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §20a (LOCKED, Owner 2026-10-21); `ReportService::scopeToActor` |
| D-14 | Excel/CSV primary; Google Sheets one-way optional, 10k cap | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §20a ("Database tetap sumber kebenaran; Google Sheets hanya tujuan ekspor satu arah") |
| D-15 | DEV → isolated staging → production only with Owner approval | `VERIFIED_OWNER_DECISION` | `OPERATIONS.md`; `AGENTS.md` §9–10 |
| D-16 | Additive, reconcilable migrations only | `VERIFIED_OWNER_DECISION` | `AGENTS.md` §9; refund migrations designed additive (2026_10_21/22) |
| D-17 | Agent runs scoped tests; full suite runs only Owner-side | `VERIFIED_OWNER_DECISION` | `SALES-PARITY-CHECKPOINT.md` D-06 (governance update); `AGENTS.md` §7 |
| D-18 | Refund Ledger §26 invariant set (one snapshot, one writer, R_c/R_u split, deferred containers) | `VERIFIED_OWNER_DECISION` | `BUSINESS-RULES.md` §26; `REFUND-LEDGER-CHECKPOINT.md` |
| — | "33 Owner decisions" register | `OWNER_MANDATE_SOURCE_PENDING` | Discussed in Owner history; **no local register found containing that roster** — treated as a claim to verify, not a register |
| — | Owner package list (`REM-001`, `IMP-001`, `REM-002`) | `RESOLVED (G-09a CLOSED 2026-10-30)` — G-09 numbering **policy** resolved by Owner 2026-10-28 (single global ascending IMP sequence; `IMP-001…007` FROZEN; `IMP-008` next available; `REM-004` reserved for AU-01/G-07). Final G-09a decision (Owner 2026-10-30): the ambiguous legacy Owner label `IMP-001` is retired as a documentation-only alias — never re-points the historical `IMP-001` ID, no alias invented into code/DB/API/enum; only display labels were terminology-fixed (Sales-Kurir → Sales-Kurir-Sub in UserManagementView/DashboardHomeView) | `docs/PRIMECLASSY-IMP-REM-BLUEPRINT.md` §1a item 5; `docs/PRIMECLASSY-GAP-DEPENDENCY-LEDGER.md` G-09 row |
| — | REM-003 label (UserManagementView candidate list) | statuses previously stale; implementation `VERIFIED` by source in `CLAUDE-CHANGE-VERIFICATION.md` §3; test outcome still UNVERIFIED | `SALES-PARITY-CHECKPOINT.md` ledger + code |

Counts after consolidation: **18/18 traces resolved to a local source** (D-07 carries a documented
minor limitation), **0 decisions silently altered**, 2 meta-items flagged
(`OWNER_MANDATE_SOURCE_PENDING`, `CONFLICT_REQUIRES_OWNER`).

# Appendix B — SimpleV2 adaptation Owner decisions OD-01…OD-06 (2026-10-28)

Source document: `docs/adaptation/PC-SV2-DOCS-ONLY-CONSOLIDATION-20261028.md` (Owner-placed in repo,
2026-10-28). Applied into `docs/BUSINESS-RULES.md` §20b/§27, `docs/ARCHITECTURE.md` §16,
`docs/MASTER-SYSTEM.md` §42/§43. Docs-only alignment; no implementation authorized.

| ID | Decision | Status | Canonical location |
|---|---|---|---|
| OD-01 | Agen branch-level CRM read; Admin operational branch CRM read; read separated from edit/reassign/export/verify | `VERIFIED_OWNER_DECISION` — approved, NOT IMPLEMENTED | `BUSINESS-RULES.md` §27 |
| OD-02 | Owning Sales/SKS may initiate CRM-only Customer return with Customer-consent evidence; approval/inspection/finalization/settlement unchanged | `VERIFIED_OWNER_DECISION` — approved, NOT IMPLEMENTED | `BUSINESS-RULES.md` §27; `ARCHITECTURE.md` §16 (returns row) |
| OD-03 | CRM↔Konsumen linking requires verified account control + OTP on already-verified channel + audit; never contact-info-only inference | `VERIFIED_OWNER_DECISION` — approved, NOT IMPLEMENTED; no OTP provider currently exists in-repo (do not assume one) | `BUSINESS-RULES.md` §27 |
| OD-04 | Authorized audited unlink; historical orders/commissions/attribution unchanged | `VERIFIED_OWNER_DECISION` — approved, NOT IMPLEMENTED (non-rewrite posture already enforced for referral reassignment) | `BUSINESS-RULES.md` §27 |
| OD-05 | Refund commission-adjustment formula: drafting only; **no formula approved**; no automatic reversal/deduction/posting | `SUPERSEDED_AS_FRAMEWORK 2026-10-28` — policy framework approved as OD-05a…OD-05g (Appendix D); prohibition facts unchanged (no reversal/deduction/posting in source; exception entries PENDING; implementation NOT_IMPLEMENTED) | `BUSINESS-RULES.md` §26/§27; framework §15a |
| OD-06 | Adapt all relevant SimpleV2 report/Sheets formats within role/Agent scope, with per-column mapping and parity gates | `VERIFIED_OWNER_DECISION` — approved, NOT IMPLEMENTED (presentation authority only) | `BUSINESS-RULES.md` §20b |

Unresolved items (targeted evidence or Owner decision — not guessed facts):

- `OD-07 (proposed)` — meanings of SimpleV2 visit-status `FU` and `D`: `SEMANTICS_NOT_VERIFIED`
  (source enum exists at `HEAD:database/migrations/2024_11_18_062701_create_kunjungans_table.php:20`
  of the SimpleV2 bare repo; no definition found anywhere in its source).
- Actual Google Sheets workbook headers/formulas/freeze/format: `EXTERNAL_EVIDENCE_REQUIRED`
  (SimpleV2 code writes data from row 4 without writing headers; PrimeClassy worksheet `tab` names are
  admin free-text).
- Canonical outstanding metric conflict: Laporan A/B use `max(0, total_amount − paid_amount)`
  (LOCKED §20a) while datasets `financial_summary`/`payment_status` aggregate
  `orders.remaining_amount` → `OWNER_DECISION_REQUIRED` to unify.
  - **Dated supersession (2026-10-28, S-1):** the policy framework is **APPROVED** as OD-K5-1…OD-K5-5 —
    canonical authority `BUSINESS-RULES.md` §20c, this Appendix C, `G-15`, workstream WS-7. Status split:
    APPROVED_DESIGN · operational parameters + Keuangan reconciliation still pending (OD-K5-4) ·
    implementation **NOT_IMPLEMENTED** · **no historical backfill** (OD-K5-5). KF-1…KF-3 remain **not fixed**.
- "Posisi uang" semantics: §20a already closed OQ-8 by removing the column; the consolidation
  package lists it as unresolved → §20a takes precedence; reopening is an explicit Owner decision.
- Exact authorized-actor list and evidence-retention design for link revocation and assisted-return
  consent evidence: `OWNER_DECISION_REQUIRED`.
  - **Dated supersession (2026-10-28, S-1):** the consent and identity framework is **APPROVED** as
    CI-01…CI-06 — canonical authority `BUSINESS-RULES.md` §27a, `ARCHITECTURE.md` §16a, Appendix E,
    `G-17…G-23`, workstream WS-9. Still open dependencies (not resolved): exact evidence media / identity
    verification (CI-02), provider/delivery infrastructure (CI-01), dispute implementation details (CI-04),
    retention period (CI-06), and FU/D definition (**CI-07 = `FU_D_DEFINITION_UNVERIFIED / OPEN`**).

Input/consistency notes recorded during the docs-only alignment:

- The uploaded audit `PC-SV2-WORKFLOW-001 / PC-SV2-RBAC-001` was **not found** in the repository
  (searched repo-wide `*SV2*|*WORKFLOW*|*RBAC*` 2026-10-28; only the consolidation package and
  `PC-SV2-DOCS-ONLY-APPLY-INSTRUCTIONS.md` were present at that time — at the root for the latter).
  Its `OR-###`/29-workflow references are
  kept as Owner-provided citations. Status: `OWNER_MANDATE_SOURCE_PENDING`.
  Evidence-recovery update (2026-10-28, PC-SV2-WORKFLOW-RBAC-EVIDENCE-RECOVERY-001, read-only): both
  audit bodies are confirmed **`ORIGINAL_AUDIT_NOT_RECOVERED`** — pickaxe across all Git refs (13
  branches, local+remote) shows the identifiers and filenames never existed in history; stash empty; no
  copy in any linked worktree or in the previously recorded local backups/temp artifacts. The **29-group
  register** survives only as the Owner-transcribed, audit-derived table `WF-01…WF-29` in
  `design/FINAL-MOCKDESIGN-SPEC.md` §4 (declared "source-level only, runtime not tested"); it is
  registered as the **interim preservation reference** and must not be represented as the recovered
  original. `OWNER_MANDATE_SOURCE_PENDING` stands for the original bodies; the minimum resolving
  evidence is the Owner placing the original audit document into `docs/adaptation/`.
  Location update (2026-10-28, PC-SV2-DOC-CLEANUP-002 F-2): the completed instructions file was moved
  byte-identically from the repository root to `docs/adaptation/PC-SV2-DOCS-ONLY-APPLY-INSTRUCTIONS.md`;
  it is the authoritative copy and no root duplicate exists.
- `D-05` (CRM "not Owner-approved") is superseded **as an approval statement** by OD-01…OD-04:
  the scope is now approved. Its factual half stands: the module remains **not implemented**.
  No decision text was silently altered — this line is the explicit trace.
- **Evidence correction (2026-10-28 G-09 reconciliation) — reverses an earlier wrong note:** the consolidation
  package's `StoreOrderRequest` reference is **valid**. The class exists and is active:
  `backend/app/Http/Requests/Order/StoreOrderRequest.php`, namespace `App\Http\Requests\Order`,
  `class StoreOrderRequest extends BaseFormRequest`; used as the `POST /orders` validator via
  `OrderController.php:8` (import) + `OrderController.php:127` (`public function store(StoreOrderRequest $request)`).
  Its docblock (`:9-13`) confirms it accepts only identifiers/quantities and recomputes amounts server-side, and
  (`:13`) that `konsumen_id` is meaningful only for `agen/korsal/sales/sales-kurir-sub` — reinforcing the §16 buyer-origin
  contract. The account-backed consumers to map for CRM origin remain: `konsumen_id`, `Controller::resolveKonsumen()`
  (`app/Http/Controllers/Controller.php:32`), `orders_konsumen_idempotency_unique` (`OrderService.php:72-107`),
  and `OrderPolicy::create` (`OrderPolicy.php:156-169`). Not a rule change.
- AU-01 defect (503 contract vs current `RuntimeException`/500 on manual register without an active
  Agen) recorded as `G-07` in `docs/PRIMECLASSY-GAP-DEPENDENCY-LEDGER.md`; not fixed here.

Counts after Appendix B: OD-01…OD-04, OD-06 `VERIFIED_OWNER_DECISION` (all approved-not-implemented);
OD-05 pending formula decision; 6 unresolved items registered; 1 input document missing and flagged;
2 evidence corrections recorded; **0 decisions silently altered**; no application file touched.

**Reconciliation update (2026-10-28, G-09 verified reconciliation — Owner-approved docs-only):**
The G-09 numbering **policy** is now resolved (Owner mandate): a single global, monotonically increasing IMP
sequence with `IMP-001…007` FROZEN and `IMP-008` established as the next available number (verified no higher
allocation/reservation exists); `REM-004` remains reserved for AU-01/G-07. The one ambiguous legacy Owner label
`IMP-001` (from the `REM-001 / IMP-001 / REM-002` package list) was closed as sub-issue **G-09a** on 2026-10-30
(Owner): the label is retired as a documentation-only alias — no historical ID renumbered, no alias invented;
only business-terminology display labels were fixed (see `docs/PRIMECLASSY-IMP-REM-BLUEPRINT.md` §1a item 5). The earlier Appendix B "StoreOrderRequest absent" evidence note is hereby
**reversed** by verified source evidence (the class exists and is active — see the corrected bullet above). This update
changes numbering policy and one evidence fact only: **0 decisions and 0 implementation statuses silently altered.**

# Appendix C — K-5 financial contract Owner decisions OD-K5-1…OD-K5-5 (approved 2026-10-28)

Source: PC-SV2-K5-FINANCIAL-CONTRACT-001 read-only verification + Owner decision pack of 2026-10-28. Canonical
rules: `docs/BUSINESS-RULES.md` §20c. Technical lineage: `docs/ARCHITECTURE.md` §17. Remediation dependency:
`G-15` in the gap ledger + `WS-7` in the blueprint (`OWNER_ALLOCATION_REQUIRED`). Everything below is
**APPROVED_DESIGN / NOT_IMPLEMENTED** — no report, dataset, column, stored field, or payment workflow has been
changed.

| ID | Decision | Rationale (verified evidence) | Affected financial surfaces | Status | Verification required before implementation |
|---|---|---|---|---|---|
| OD-K5-1 | Canonical collectible AR = derived `GREATEST(total − COALESCE(paid,0), 0)` with order-status eligibility; cancelled orders never contribute to collectible AR and stay auditable via separate cancellation/refund reporting categories; exclusion does not extinguish outstanding refund liabilities | KF-1: stored M-1 is 0 for never-mutated non-DP orders (`OrderService.php:291-296` DP-only + `PaymentService.php:618-644` sole money-event writer); cancelled phantom: consumers filter payment_status only, none order status (`ReportService.php:447-451, 738-741, 803`; D17 unfiltered `DatasetRegistry.php:171-176`) | financeOrders, networkSummary, per-Agent report, D13, D17; Laporan B already conforms (§20a) | APPROVED_DESIGN / NOT_IMPLEMENTED | OD-K5-4 reconciliation incl. per-status difference attribution + Keuangan sign-off |
| OD-K5-2 | Pending verification is not a paid amount: the balance stays in collectible AR for eligible orders; pending-verification remains a separate reporting dimension; payment authorization, verification roles and payment-event logic untouched | Proof upload creates pending verification without amount mutation (`PaymentService.php:224-235`); verification is the only paid writer (§13) | AR queries/datasets; payment status dashboards | APPROVED_DESIGN / NOT_IMPLEMENTED | Reconciliation comparing current vs proposed with pending_verification isolated; no event-logic change permitted |
| OD-K5-3 | `overpayment_liability = GREATEST(paid − total, 0)` as **proposed read-model definition only**; never auto-equal gross overpayment to refundable cash where refunds executed; separate category from AR; no netting; refund/payment ledger + snapshots untouched | `paid_amount` deliberately unclamped; floor applies to remaining only (`PaymentService.php:612-622`); ledger is truth of money out (§26) | future liability report/dataset (none exists today — read-model not yet built) | APPROVED_DESIGN / NOT_IMPLEMENTED | Reconcile against `refund_payouts`/`order_additional_payments` settlement records + Owner approval of any new column |
| OD-K5-4 | Reconciliation-first rollout (7 steps: consumer inventory → value comparison → difference attribution → defect-vs-semantics separation → Keuangan review/sign-off → Owner rollout + presentation-contract approval → audit evidence + rollback) before any report change; report values and Sheets columns remain exactly as today until complete | KF-1/KF-2 findings; §20b workbook-evidence gate | all AR consumers + Sheets export contract | APPROVED_DESIGN / PROCESS (gates required) | The reconciliation itself is the gate; deliverable evidence recorded in `G-15` |
| OD-K5-5 | No backfill of stored `orders.remaining_amount`; canonical reporting derives M-2 read-side; existing stored-field usage and payment-status workflows preserved; any future data correction = new explicit Owner decision | Migration `2026_09_23_090000` was one-time; API/settlement logic depends on M-1 semantics (`:470-477` DP settle guard; `ShipmentReceiptResource.php:80`) | database (none), API, reports | APPROVED_DESIGN / NOT_IMPLEMENTED | n/a — prohibition is immediate; violation requires Owner decision reversal |

Metrics preserved distinct (never conflated): **M-1** stored lifecycle balance · **M-2** derived running balance
(collectible-AR basis) · **M-3** immutable checkout snapshot (Laporan A). Laporan A vs Laporan B semantics of §20a
unchanged; §20c extends the same locked derivation basis to other AR consumers after reconciliation.

Historical records above (Appendices A–B) remain untouched; this appendix is additive. 0 prior decisions altered;
0 implementation statuses upgraded by this task.

# Appendix D — OD-05a…OD-05g Refund commission-adjustment decisions (Owner-approved 2026-10-28)

Basis: PC-SV2-OD05-REFUND-COMMISSION-ANALYSIS-001 (read-only, source-verified). Canonical rules:
`docs/BUSINESS-RULES.md` §15a; architecture `docs/ARCHITECTURE.md` §15/§15a; remediation dependency `G-16`
(ledger); workstream WS-8 (blueprint, working label — **no IMP/REM allocated**). All statuses are design-level;
**no implementation, schema change, or adjustment generation is authorized**. Appendix C (OD-K5) and the
K-5/workflow-RBAC records are untouched and remain independent of this commission policy.

| ID | Decision | Approval | Rationale (verified evidence) | Affected financial surfaces | Status | Remaining implementation prerequisites |
|---|---|---|---|---|---|---|
| OD-05a | Hybrid adjustment policy: deterministic, original per-unit snapshot-based; category exceptions only via explicitly Owner-approved entries; no auto fee-rate changes; no snapshot rewriting | Owner 2026-10-28 | Per-unit snapshot lineage CONFIRMED (`FeeService.php:14-31`; `OrderService.php:856-869`; `CourierService.php:742`); refund→commission edge does not exist today (0 writers) | `commission_adjustments` (dormant table), entitlement reporting, fee datasets D10–D12 | APPROVED_DESIGN / NOT_IMPLEMENTED | §15a chain implementation package + gates (blueprint WS-8) |
| OD-05b | Eligibility basis = product-level fee snapshots × eligible quantity/value reductions; deterministic discount-allocation rule REQUIRED as documented design (none invented here); shipping & retained admin fees not automatically commission-bearing; DP/installment amounts never redefine unit snapshots | Owner 2026-10-28 | No source discount-allocation formula exists; snapshot immutability is a locked §15/§26 rule | reduction records (`order_sales_reductions`), fee rows on `order_items` | APPROVED_DESIGN / NOT_IMPLEMENTED | Discount-allocation design + Owner acceptance before coding |
| OD-05c | Versioned responsibility matrix — dimensions: reason · responsible party · affected beneficiary · treatment · required evidence · approver+policy_version; **no individual exception approved**; no automatic courier clawback on refund; SKS sales/courier lines stay separate | Owner 2026-10-28 | Courier earned-on-delivery + separate-lines rules are LOCKED (§15); responsibility assignment is a business policy the code cannot invent | exception configuration/policy table (future), courier fee handling | APPROVED_DESIGN / **EXCEPTION_ENTRIES_PENDING** | Owner approves each exception entry (with policy_version) before any becomes active |
| OD-05d | Approved refund obligation is the SOLE trigger; request never triggers; idempotent creation; reversal/reinstatement as compensating entries only; reconcile against actual payout events (R_c); no duplicate adjustment; entitlement ≠ cash execution | Owner 2026-10-28 | Obligation statuses & unique keys exist (§26 ledger CONFIRMED); compensating-entry pattern mirrors payout immutability | `refund_obligations` → `commission_adjustments` edge | APPROVED_DESIGN / NOT_IMPLEMENTED | Unique adjustment key (OD-05g) + replay/idempotency regression suite at gate list WS-8 |
| OD-05e | Where commission was already paid OUTSIDE the application: reconciliation may create a SEPARATE recovery balance; no automatic cash deduction, no automatic offset against future commission, no mutation of historical paid transactions; **app does not know paid state — no payout knowledge may be assumed**; external-payment verification is a mandatory future prerequisite | Owner 2026-10-28 | No `status`/`paid_at` writer exists (0 hits); enum values `approved/paid/reversed` are reserved-only | recovery balance surface (future), payout reconciliation | APPROVED_DESIGN / NOT_IMPLEMENTED | External payout verification design + Owner-approved recovery treatment |
| OD-05f | Monetary adjustments at 2-decimal precision; deterministic allocation; explicit rounding/remainder handling; cumulative adjustment bounded by original entitlement; no duplicate reversal; no negative payable commission without separately approved recovery treatment; no silently introduced rounding algorithm | Owner 2026-10-28 | Ledger amounts are cents/decimal canonical (§26); bounded journal prevents entitlement drift | adjustment arithmetic layer | APPROVED_DESIGN / NOT_IMPLEMENTED | Rounding spec document + bounded-set tests at WS-8 gates |
| OD-05g | Schema-readiness requirements recorded (courier role in `commission_adjustments` where required; unique adjustment identity/idempotency key; Agent isolation preserved; original commission rows immutable; policy version/source event/obligation ref/actor/audit provenance stored) — documentation only | Owner 2026-10-28 | Gap CONFIRMED: `2026_10_22_100006` enum `agent\|sales` vs commissions enum incl. `courier` (`2026_09_10_090000`); no unique key on adjustments table | future migration only | APPROVED_DESIGN / **MIGRATION_NOT_AUTHORIZED** | Migration proposal + Owner authorization + backup/reconcile procedure (AGENTS §9) before any schema work |

Counts after Appendix D: 7 decisions recorded (framework approved; exceptions pending; implementation and
migrations not authorized); 0 historical decisions rewritten; 0 statuses upgraded; the Appendix B/C tally lines
above describe their own contemporaneous snapshots and are intentionally left as written.

# Appendix E — CI-01…CI-07 Customer consent & identity decisions (Owner-approved 2026-10-28)

Basis: PC-SV2-FUD-CONSENT-LINKING-ANALYSIS-001 (read-only, source-verified). The `CI-0x` namespace was
**verified collision-free** across the documentation set before use (0 pre-existing occurrences); no historical
decision identifier was renumbered. Canonical rules: `docs/BUSINESS-RULES.md` §27a; architecture
`docs/ARCHITECTURE.md` §16a; remediation `G-17…G-23` (ledger);
workstream WS-9 (blueprint, working label — **no IMP/REM allocated**). FU/D is recorded as **OPEN**, not
resolved.

| ID | Decision | Approval | Evidence/rationale | Affected surfaces | Status | Remaining prerequisites |
|---|---|---|---|---|---|---|
| CI-01 | Linking requires proof of target-account control + verified email + single-use OTP (expiry, attempt/rate limits, replay protection, challenge binding, immutable audit); email/phone match alone never authorizes | Owner 2026-10-28 | No OTP/verification-code infra exists (sweeps = 0); email is not verified anywhere; Google identity is the only external channel (`user_social_identities`) | linking flow, auth/verification, audit log | APPROVED_DESIGN / NOT_IMPLEMENTED | Channel/provider choice (Owner); OTP challenge design; email-verification design |
| CI-02 | Assisted-return initiation requires digital consent evidence bound to customer/order/return/item-scope/purpose/media/actor/timestamp/Agent/audit, with integrity+retention references and replay protection; checkbox/contact-match/verbal alone is insufficient | Owner 2026-10-28 | Current `returns` only stores `reason` + nullable `evidence_path` + actor columns — no consent semantics (grep `consent` = 0) | returns, media evidence, audit | APPROVED_DESIGN / NOT_IMPLEMENTED | Accepted media + identity-verification mechanism (technical dependency) |
| CI-03 | Sales/SKS may capture consent strictly within their Agent/Customer/referral/order scope; initiation only after valid evidence; no review/inspection/refund/commission authority | Owner 2026-10-28 | OD-02 boundary + `OrderPolicy` network scope; refund authority currently `keuangan`/`super_admin` only (CONFIRMED) | assisted returns, role authority | APPROVED_DESIGN / NOT_IMPLEMENTED | Consent contract (CI-02) + implementation package |
| CI-04 | Withdrawal/dispute uses append-only auditable events; suspend only new consent-dependent actions; preserve prior evidence; authorized review before resume; no silent reversal of completed financial events | Owner 2026-10-28 | Ledger guarantees completed payouts are immutable (§26); no consent state exists today | consent events, returns, audit | APPROVED_DESIGN / NOT_IMPLEMENTED | Event model + dispute/withdrawal policy Owner approval |
| CI-05 | Reject cross-Agent CRM↔Konsumen linking; Agent transfer requires a separate authorized process; no auto-reassignment/merge | Owner 2026-10-28 | `BelongsToAgentScope` + `agent.linked` enforce isolation today; no link concept exists | linking, tenant isolation | APPROVED_DESIGN / NOT_IMPLEMENTED | Transfer process definition (Owner) |
| CI-06 | Consent evidence follows the future approved retention policy + legal hold; no invented retention period; no automatic deletion | Owner 2026-10-28 | No retention policy exists in the repository | media/evidence storage, audit | APPROVED_DESIGN / **RETENTION_PERIOD_PENDING** | Retention policy + legal-hold decision (Owner) |
| CI-07 | FU/D meanings remain unresolved: `FU, N, D, 1…7` verified in SimpleV2 enum; `FU`/`D` have no label/definition anywhere; no rename/removal/restriction without Owner approval | Owner 2026-10-28 | `HEAD:database/migrations/2024_11_18_062701_create_kunjungans_table.php:20` (SimpleV2); UI offers only `N,1…7`; PC docs carry it as unverified | future visit/funnel package only | **FU_D_DEFINITION_UNVERIFIED / OPEN** | Explicit Owner semantic decision |

Counts after Appendix E: 6 approved-design decisions + 1 open semantic item; 0 resolved FU/D claims; 0
historical decisions altered; 0 statuses upgraded; 0 schema or provider selected.

# Appendix F — Owner sign-off record — PC-SV2-MASTER-REM-OWNER-SIGNOFF-003 (2026-10-28)

**Status:** `OWNER_SIGNED_OFF — ARCHITECTURE_AND_DOCUMENTATION_ONLY`
**Approval date:** 2026-10-28 · **Recording option:** A (this Decision Traceability appendix is the single
repository record of the sign-off)
**Baseline reference:** branch `feat/refund-ledger-v1`, HEAD `218db8cd28cce53f8252379b9899722c96cb5dd8`.
**Baseline caveat:** several canonical documents are **untracked** in this working tree, so HEAD alone does **not**
capture the approved baseline; the approved baseline is the current working-tree Markdown content that this record
references, and it must be preserved as reviewed.

## F.1 Baseline covered by this sign-off

- PrimeClassy business rules and the Owner decisions already recorded in Appendices A–E (OD-01…OD-06,
  OD-K5-1…OD-K5-5, OD-05a…OD-05g, CI-01…CI-07, numbering policy G-09/G-09a).
- Preservation of the 29 historical workflow groups (WF-01…WF-29) and the 11 roles, with source-audited-by-sample
  vs runtime-verified distinction retained.
- Gap register **G-01…G-23, including all open statuses** (nothing is closed by this sign-off).
- Workstreams **WS-1…WS-10** as documented (WS-10 remains a documentation working label).
- IMP/REM numbering governance: historical identities unchanged; `IMP-008` and `REM-005` remain unallocated.
- Financial contracts: **K-5 / WS-7** collectible-AR policy and **OD-05 / WS-8** refund commission-adjustment
  framework (both APPROVED_DESIGN / NOT_IMPLEMENTED).
- CRM Customer, consent, identity, linking/unlinking contracts (BR §27/§27a, ARCH §16/§16a, Appendix E).
- Reporting and Google Sheets adaptation (OD-06) as documented design work.
- Premium Maroon design reference (reference-only; no frontend authorization).

## F.2 Explicit exclusions (sign-off does NOT assert)

- That any gap has been resolved or any register closed.
- That CRM, OTP, consent, linking, commission adjustment, or AR remediation has been implemented.
- That the original workflow/RBAC audit was recovered (`ORIGINAL_AUDIT_NOT_RECOVERED` stands).
- That the Google Sheets workbook was visually verified (still `EXTERNAL_EVIDENCE_REQUIRED`).
- That the full backend suite has passed (G-01 remains Owner-run; no runtime PASS is claimed).
- That the application is production-ready.

All open dependencies, Owner decisions, and NOT_IMPLEMENTED statuses remain exactly as recorded above.

## F.3 Authorization boundaries (unchanged)

This sign-off authorizes **no** source-code change, migration/database change, test execution, commit/push,
Google Sheets modification, staging or production operation, and **no** IMP/REM allocation or execution. Every
implementation package requires separate explicit Owner authorization.

## F.4 Recording statement

This appendix is the only documentation change made to record the Owner approval (Option A). It preserves all
historical decisions, open gaps, and implementation statuses as-is; it does not upgrade any status, close any gap,
or renumber anything.
