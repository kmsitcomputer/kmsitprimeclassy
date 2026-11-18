# Domain architecture and acceptance contract — LOCKED intent
This document consolidates Owner locks and RECON source findings. **Technical approaches are specifications to verify, not claims of implemented code or runtime PASS.**

## Canonical data ownership
- Order domain: one canonical `orders` record per admitted historical transaction; original `OrderService::createOrder` cannot be reused blindly because it reserves stock, creates pending financial/shipment/commission state. Design a bounded historical admission path inside existing Order domain; not a parallel POS order system.
- Inventory domain: existing stock ledger, reservations, `StockOpnameService` and Gudang/Admin approvals. Already-reflected historic units have zero fresh reserve/deduct; not-reflected units need proof and approved reconciliation first. Outstanding future fulfilment cannot be silently conflated with already delivered units.
- Payment domain: `PaymentService`/canonical payment summary and independent verifier. Actual historical receipt time distinct from system verification time. COD delivered ≠ paid; DP/partial ≠ fully paid. Do not create a second cash receipt.
- Commission domain: one valid entitlement per actual beneficiary/item/type; proven full external settlement only; no duplicate payable; paid field is not an authorization to fabricate settlement.
- Fulfilment: item-level actual receipt separate from shipment aggregate `delivered_at`, `handed_over_at`, planned delivery date and verification timestamp. No synthetic historical courier events.
- Reporting: explicit business date, system recorded date, actual receipt date, verified date, delivery date; closed reports untouched; separate reconciliation presentation.
- Invoice: canonical invoice numbering, issued date and totals unchanged; only approved visual layout applied consistently across browser/print/PDF.
- Product import: global SKU owner resolution, row-level atomic result, durable source identity, stale target conflict (409), Gudang count and Admin approval, no catalog publication before approval.
- Bestseller: fully paid verified, Agent-scoped net delivered item units by actual per-item recipient receipt date; accepted physical returns once; do not infer receipt from aggregate shipment date or invent missing legacy dates.
- Security: POS recorder roles Super Admin/Admin/Agen only; valid active Agent required; stale Admin→deleted Agent fails closed; independent Finance/Gudang/commission authority; cross-Agent access forbidden.

## Historical admission: design-stage gate sequence
1. Validate authenticated POS role and active Agent source.
2. Validate historical business date (past or today, no lookback cap), required independent requester, optional scoped consumer and recipient.
3. Match trusted source reference and candidate duplicates; stable idempotency key + fingerprint.
4. Validate catalog canonical prices/discounts; D-05 A rejects mismatch.
5. Reconcile item-level stock with evidence and authorized approval before admission (D-01 A).
6. Validate standard old receipt evidence and independent payment verification; no fresh cash-in from recording (D-02 A).
7. Reconcile external fully settled commission evidence and unpaid entitlement without duplicate payable; unsupported partial external settlement blocks (D-03 A).
8. Record one canonical historical order with typed event facts and truthful audit times; do not replay regular checkout/delivery notifications.
9. Project to existing List Order, invoice, separate historical reports, and Agent-scoped bestseller only when eligible.

**Important sequencing detail**: pre-admission checks must not create unapproved side effects. Design transaction boundaries and independent approval dependencies so no order is saved in an unauthorized quarantine mode.

## POS/CO frontend parity — acceptance
- Map existing Checkout and List Order presentation as actual list/card UI, not invented column headers.
- Reuse shared formatters/status vocabulary and transaction view model; same displayed fields and comparable behavior where roles permit.
- Separate origin and business-vs-recorded date visibly where applicable, without changing legacy CO semantics.
- One canonical List Order; historical POS source never gets a second duplicate list or alternate order store.
- Same source-of-truth values; never claim finance verified by toggling a POS-only control.

## REM-01…11 acceptance inventory
1. Import/export CSV/XLSX preview, errors, global SKU update, per-row atomicity, stock approval.
2. Parent and variant galleries isolated; variant selector updates images; no cross-parent attachment.
3. Edit product loads complete management detail, no loss/duplication on no-change save.
4. Hard delete only without critical references, guarded under concurrent writes; Admin denied.
5. Management status filters Semua/Aktif/Draft/Nonaktif; default Semua; storefront active only.
6. Category/subcategory parent_id hierarchy, anti-cycle checks, descendant filtering.
7. Bestseller 7/30/90/all and 4/8/12, verified paid, Agent scope, per-item actual receipt and returns once.
8. Edit attributes/values without changing sibling variants or historic order snapshots.
9. SKS regular checkout own Sub or parent Agent stock, one source/order, source revalidated; unrelated to historical POS.
10. Invoice official white/maroon browser/PDF/print, financial invariants unchanged.
11. Historical-only POS dark maroon two-column, no sidebar; referral/requester/consumer/recipient separation, truthful backdate, evidence reconciliation, canonical List Order, POS–CO presentation parity.

## Test/verification plan — NOT RUN
Plan only: order duplicate replay and changed fingerprint 409; concurrent stock approval and changed target; historical stock reflected/no movement; payment proof/self-verification; COD unpaid; external full/partial commission; old price mismatch rejected; closed report invariance; per-item delivery split, returns/refunds; Agent RBAC and deleted branch; import row failure/retry; invoice browser/print/PDF comparison; POS–CO parity; regression of regular checkout and SKS. **No test has been executed by creating this package.**
