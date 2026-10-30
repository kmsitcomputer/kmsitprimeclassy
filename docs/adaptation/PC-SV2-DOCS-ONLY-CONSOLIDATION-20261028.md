# PrimeClassy ← SimpleV2 — Documentation Consolidation Package

**Date:** 2026-10-28  
**Mode:** DOCS-ONLY / source changes prohibited  
**Authority:** Owner decisions OD-01–OD-06, plus previously locked business invariants  
**Status:** Proposed canonical documentation amendments; **not applied to the local PrimeClassy repository**.  
**Source:** `PrimeClassy — Targeted Workflow & Role Coverage Audit`, PC-SV2-WORKFLOW-001 / PC-SV2-RBAC-001, 2026-10-28 (uploaded audit). This audit is source-only, no runtime verification; repo `C:\ServBay\www\primeclassy`, branch `feat/refund-ledger-v1`, HEAD `218db8cd28cce53f8252379b9899722c96cb5dd8`, dirty 139 entries at audit time.

> **Status addendum — applied (2026-10-28, same day; PC-SV2-DOCS-ONLY canonical alignment, Owner-approved docs-only execution):**
> The amendments proposed in this package were subsequently applied to the canonical documentation of the local repository:
> `docs/BUSINESS-RULES.md` §20b (OD-06 reporting/Sheets format parity) and §27 (OD-01…OD-06 CRM ownership, linking,
> CRM-origin assistance); `docs/ARCHITECTURE.md` §16 (buyer-origin architectural contract + downstream coverage matrix);
> `docs/MASTER-SYSTEM.md` §42 (feature-state ledger) and §43 (Premium Maroon design-reference index);
> `docs/OWNER-DECISION-TRACEABILITY.md` Appendix B (OD-01…OD-06 register, unresolved items and evidence corrections).
> The line-6 statement above is preserved verbatim as historical provenance of the document's state at authoring time.
> This addendum changes no Owner decision and no feature implementation status: everything adapted from SimpleV2 remains
> **approved — NOT IMPLEMENTED**, and implementation of any package still requires separate explicit Owner authorization.

## 1. Rules for integrating into canonical documents

1. **RECON ONCE:** no fresh broad audit. Before editing, read AGENTS/governance, active specs/checkpoint, `git status --short`, and inspect only relevant document sections/diffs. Do not assume the working tree is unchanged.
2. **Append or surgically amend**, preserving all unrelated tracked and untracked content. Never reset, clean, delete, overwrite, or auto-format unrelated files.
3. The existing 29-workflow inventory is a **source-audit baseline**, not a runtime-PASS certificate. Existing permissions come from policies/services, not frontend menus.
4. All 11 roles retain their existing authorized business flows. New workspaces may reuse shared components but must have distinct role experiences. No new permissions by redesign.
5. Docs-only means no code, migrations, DB, tests, runtime config, secrets, commits, remote actions, or production operations.
6. If canonical documents conflict with locked Owner decisions, produce an evidence-backed conflict note and bounded proposed diff. Do not silently rewrite the earlier business rule; escalate genuine conflicts.

## 2. Owner Decision Register — canonical additions

| ID | Locked decision | Consequence |
|---|---|---|
| OD-01 | Agen sees CRM Customers within own Agent; Admin sees operational CRM within own Agent | Separate read from edit/reassign/export; Agent scoping mandatory |
| OD-02 | Owning Sales/SKS may initiate CRM-only Customer return with proof of Customer consent | Initiation only; existing return review, pickup, inspection, finalization, refund authorities unchanged |
| OD-03 | CRM↔Konsumen linking requires verified account control + OTP to a verified channel + audit | No matching by name/phone/email alone; replay/rate-limit/expiry controls |
| OD-04 | Authorized actor may revoke link with audit; historical transactions unchanged | Revocation changes future authorization only; no retroactive snapshot/commission rewrite |
| OD-05 | Draft separate refund commission-adjustment formula using historical snapshots and refund category; submit to Owner | **No formula approved**; no automatic reversal, deduction or posting authorized |
| OD-06 | Adapt all relevant SimpleV2 report and Google Sheets formats within role/Agent scope | Source-level column mapping + real workbook evidence for visual parity |

**Unresolved:** OD-07 SimpleV2 `FU`/`D` meanings; actual Sheets formatting/formulas/freeze/header; money-position semantics; canonical outstanding metric; refund adjustment formula; exact actor list and evidence-retention design for link revocation and assisted returns. Treat these as targeted evidence/Owner decisions, not guessed facts.

## 3. Suggested amendment: `docs/BUSINESS-RULES.md`

### CRM ownership and authorization

- CRM Customer is a business contact/prospect and **not** a fabricated User or a public guest checkout identity.
- CRM Customers belong to an Agent and an owning Sales/SKS. Sales/SKS may create, view, and update owned CRM records, subject to policy and immutable audit of sensitive changes. Korsal has read/monitoring scope for their authorized downline. Agen has branch-level read; Admin has operational branch-level read. Neither branch read grants automatic ownership reassignment, export, or financial verification.
- CRM visits, prospects, and follow-ups are owned/scoped similarly. Stage vocabulary is preserved from verified SimpleV2 evidence; do not invent definitions for `FU` and `D`.
- Assisted order may originate from an owned CRM Customer without a login. The order service must remain authoritative for price, stock, idempotency, financial snapshot, and audit; this is **approved-but-not-implemented**.
- For CRM-only Customer returns, owning Sales/SKS may submit the request with verifiable Customer consent and actor evidence. They may not approve the return, inspect stock, finalize, or settle a refund unless independently permitted by existing policy.
- CRM↔Konsumen account link requires authenticated account-control proof, OTP to an already verified channel, rate limiting, expiry, replay protection, uniqueness/conflict checks, and audit. A link cannot be inferred from shared phone/name/email. Authorized revocation is audited and cannot rewrite historical order/commission attribution.
- Existing payment verification remains Super Admin/Keuangan only. Payment-proof submission does not constitute verification.
- Exactly 11 fixed roles; no arbitrary dynamic role creation, public guest checkout, or new payment gateways. Only manual bank transfer and COD.

### Workflow preservation

- All 29 audited existing workflow groups remain in preservation scope, including registration/referral, hierarchy, product/promo, checkout, order/status/fulfillment/SC-03, Gudang proposal/approval, stock transfers/opname, Sub ownership/replenishment, dispatch/delivery/verification, returns/refunds/additional payments, commission, reports/Sheets, CMS, and Agent configuration.
- Existing role-specific exceptions are not negotiable by UI redesign: Admin-only SC-03; Gudang proposes/Admin approves; Super Admin/Keuangan verify payments; Admin/Super Admin verify delivery; SKS preserves Sales parity plus Sub stock/delivery and self-purchase.
- Every existing business action needs an authorized frontend entry point or an explicitly documented background/system operation. Every move has before→after route/action/policy mapping and regression coverage.

## 4. Suggested amendment: `docs/ARCHITECTURE.md`

### Buyer origin — architectural contract, not migration plan

- Conceptual buyer origins: `account` (authenticated Konsumen) and `crm` (Agent-scoped CRM Customer without account). A transaction must have **exactly one authoritative buyer origin** and an immutable buyer identity snapshot.
- Existing source expects `orders.konsumen_id` and account-backed idempotency. **Do not** implement by merely making the FK nullable. Before any schema proposal, map all consumers of `konsumen_id`, `resolveKonsumen():User`, `OrderPolicy`, `StoreOrderRequest`, returns, payments, stock, commissions, reporting joins, and `(konsumen_id,idempotency_key)` uniqueness.
- Idempotency must be isolated by authoritative buyer origin and scope; avoid cross-customer collisions. Buyer linking must not change historical order origin, payer/uploader/verifier identities, financial/commission snapshots, or attribution.
- Assisted-order action is permitted only from an owned CRM Customer and a role authorized by the existing checkout/assisted order policy, with explicit Agent scope. Backend service checks are authoritative.
- Returns from CRM-origin orders require a consent-evidenced delegated initiation path while retaining existing return approval, inspection, delivery, and refund authorities.
- Reporting must support both origins without silently dropping CRM orders via inner joins to users. Sheets and Excel must use role-scoped projections; DB is the source of truth.

### Buyer-origin downstream coverage matrix

| Consumer | Existing dependency / risk | Required design proof |
|---|---|---|
| Registration/referral | User creation and attribution | CRM does not fabricate User or mutate historic referral |
| Checkout/order creation | `konsumen_id`, `resolveKonsumen`, account-backed idempotency | Origin-aware identity, uniqueness, authorization, stock/pricing snapshot |
| Order list/detail/cancel | Owner checks and role scopes | CRM owner/Agent visibility, correct cancellation gates |
| Proof upload/payment | Payer/uploader/verifier distinct | Authorized Sales/SKS submitter; finance-only verification |
| DP/COD/settlement | Payment projection and custody | No CRM bypass of financial state machine |
| Order fulfillment/SC-03 | Item snapshots and obligations | Same stock, additional-payment and refund invariants |
| Gudang requests/transfers/opname | Stock authority | CRM origin has no stock privilege |
| Sub stock and SKS delivery | Sub owner/source | SKS ownership, reserved stock and dual-fee parity preserved |
| Dispatch/delivery verification | Shipment executor and proof | Same authorized dispatch and append-only verification |
| Returns | Authenticated Konsumen request currently | Consent-backed CRM delegated request; remaining actors unchanged |
| Refund ledger | Obligation/attempt/payout | CRM-origin refund payee identity verified; no invented commission formula |
| Commission/fees | Historical snapshots and attribution | Ledger source, no recompute from current product fees |
| Reports/dashboard | Some joins depend on User | Both origins visible within scoped reports |
| Google Sheets/XLSX | Existing datasets and exports | 43-column/47-column SimpleV2 reference mapped; no manual data loss |
| Audit/CMS/settings | Global vs Agent boundaries | Event traceability; no unintended permissions |

## 5. Suggested amendment: `docs/MASTER-SYSTEM.md`

### Feature-state ledger

| Feature | State | Evidence boundary |
|---|---|---|
| Existing PrimeClassy 29 workflow groups | SOURCE-AUDITED / RUNTIME-NOT-VERIFIED | Audit 2026-10-28, not test PASS |
| 11 role workspaces | EXISTING ROUTES WITH TARGET REDESIGN | Dedicated UX target; backend permissions preserved |
| CRM Customer CRUD/Visit/prospect/follow-up | OWNER-APPROVED / NOT IMPLEMENTED | Audit OR-01/02 |
| CRM-origin assisted order | OWNER-APPROVED / NOT IMPLEMENTED | Audit OR-03 |
| Verified CRM↔Konsumen linking/revocation | OWNER-APPROVED / NOT IMPLEMENTED | Audit OR-04 |
| CRM delegated return request | OWNER-APPROVED / NOT IMPLEMENTED | Existing returns require Konsumen |
| Refund ledger complete workbench | INCOMPLETE / NEEDS EVIDENCE | Audit OR-06; dirty working tree |
| SimpleV2 Sheets visual parity | NOT VERIFIED | Real workbook missing |
| AU-01 no-active-Agent 503 contract | LOCKED / SOURCE CONTRADICTION | Current source throws RuntimeException |
| Refund commission adjustment | FORMULA PENDING OWNER APPROVAL | OD-05 only approves formula drafting |

## 6. Role × business action preservation — compact authority baseline

| Role | Preserve existing authorized workflows | CRM adaptation |
|---|---|---|
| super_admin | Global users/catalog/CMS/settings/audit, orders, finance verification, dispatch, delivery verification, reports | Global CRM oversight; no new bypass |
| agen | Branch users, product/promo, order status/fulfillment, dispatch, fee settings, Agent config, scoped reporting | Branch CRM read/monitor |
| admin | Office orders, Admin-only SC-03, warehouse approval, delivery verification, operational reports | Branch operational CRM read |
| keuangan | Payment verify/reject, COD/DP settlement, refund financial actions, financial reports | No general CRM access |
| gudang | Stock queue/proposal, transfers/opname requests, Sub execution, return inspection | No CRM access |
| kurir | Assigned shipment, delivery proof, return pickup, commission view | No CRM access |
| koordinator-kurir | Dispatch, child courier hierarchy, own assigned execution | No CRM access |
| korsal | Own team hierarchy, assisted order/proof, downline reports and commissions | Team CRM monitoring |
| sales | Own referral/assisted order/proof/self-purchase/commissions | Own CRM CRUD/Visit/follow-up, CRM-assisted order, consent-backed return |
| sales-kurir-sub | Sales parity + Sub stock + eligible delivery + self-purchase | Same CRM/return rights as Sales |
| konsumen | Own checkout, orders, proof, cancellation, return, profile | No internal CRM access |

**Authority caveat:** This is a condensed mapping from the audit. Exact action-by-action grants must be backed by existing route middleware, policy, service checks, and query scope before implementation. No generic `manage_crm` permission.

## 7. Reporting/Google Sheets parity requirements

- Source reference: SimpleV2 43-column transaction Excel and 47-column A:AU Sheets payload; 2026 worksheets `COOKIES_2026`, `CAKE_2026`; do **not** claim actual formatting/formulas verified.
- PrimeClassy dataset baseline (17): `products`, `stock`, `transactions`, `transaction_items`, `order_initial_transactions`, `order_payment_positions`, `sales`, `korsal`, `courier_deliveries`, `sales_fees`, `courier_fees`, `korsal_fees`, `payment_status`, `refunds`, `additional_payments`, `transaction_report`, `financial_summary`.
- Mandatory column inventory fields: original ordinal/header, worksheet, meaning, type/format, formula/manual/system origin, PrimeClassy source, role/Agent scope, null semantics, parity verdict, file:line evidence.
- Format parity: structural + functional + visual. Visual requires actual exported workbook/Sheets sample; no PASS by source inspection alone.
- One-way export must not overwrite manually maintained business inputs. Proposed separation: SYSTEM export, protected MANUAL keyed by stable business IDs, PRESENTATION combined; **proposal only**, requires technical validation and Owner sign-off before implementation.
- Canonical outstanding definition and financial money-position semantics remain unresolved; do not combine inconsistent report formulas without a decision.

## 8. Refund commission-adjustment design brief — no formula authorized

For Owner review, compare: (A) proportional refund amount, (B) returned-item historical commission snapshot, (C) category-specific rule. Evaluate full/partial refunds, fixed/per-item fees, shipping and discounts, commissions already paid/unpaid, event idempotency, corrections/reversals, beneficiary roles, tax implications where relevant, audit, and historical snapshot immutability. Produce example scenarios and edge-case table before requesting Owner decision. **Do not execute adjustments.**

## 9. Documentation-only acceptance checklist

- [ ] Confirm current repo branch/HEAD/dirty status and canonical doc contents without broad RECON.
- [ ] Produce bounded per-file diff for `docs/BUSINESS-RULES.md`, `docs/ARCHITECTURE.md`, `docs/MASTER-SYSTEM.md`; avoid duplicate authority documents.
- [ ] Preserve all existing workflow descriptions and 11 role authorizations; record every before→after mapping.
- [ ] Clearly label approved-not-implemented vs verified-source vs runtime-tested.
- [ ] Include six locked Owner decisions and all unresolved issues.
- [ ] Add buyer-origin consumer coverage and return/linking threat model.
- [ ] Register AU-01 contradiction and refund-ledger UI gap as defects, not silently fix code.
- [ ] Link reporting/Sheets format parity to required workbook evidence.
- [ ] Check internal references and markdown formatting; report changes, without running application tests.
- [ ] Stop and ask Owner approval before any implementation, migration, commit, or production action.

## 10. Evidence index from uploaded audit

- Audit baseline and limitations: lines 10–28.
- Registration/AU-01: lines 34–48.
- Account checkout and CRM origin gap: lines 123–143.
- Payments: lines 170–201.
- SC-03: lines 235–244.
- Gudang approvals and Sub stock: lines 248–353.
- Dispatch, delivery, returns: lines 357–414.
- Refund ledger: lines 418–432.
- Reporting/Sheets: lines 449–475.
- Role matrix: lines 512–528.
- Gap register: lines 571–588.
- State transition map: lines 592–674.
- Owner decisions: lines 741–773.
- Final qualified verdict: lines 779–810.

**End state:** A self-contained, owner-approved *proposed documentation amendment package*. No repository files have been edited by creation of this package.
