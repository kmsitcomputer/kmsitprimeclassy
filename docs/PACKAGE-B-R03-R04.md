# Package B — R-03 + R-04

**Status:** DOCUMENTED / NOT YET IMPLEMENTED  
**Sequence:** R-03 first, then R-04 on the same Package B branch.  
**Package A dependency:** R-01/R-02 are CLOSED and deployed to production.

## 1. Objective

Package B completes the order-fulfillment/delivery model around the new Sales-Kurir-Sub domain and then hardens role authority, operational-vs-financial visibility, product authority, and reporting.

R-03 is the behavioral foundation. R-04 must consume the final R-03 state model rather than inventing parallel order/delivery rules.

## 2. Locked cross-package rules

These rules are already decided and must not be re-litigated during implementation.

### Role / hierarchy
- Exactly 10 roles.
- Canonical role is `sales-kurir-sub`; legacy `sales-kurir` is compatibility only.
- Historical SA-/SK- referral codes stay unchanged.
- New Sales-Kurir-Sub referral codes use SS-.
- 1 active Sales-Kurir-Sub user = at most 1 active owned Sub Location.

### Stock
- Agent Sellable = Transit + active Factory Plan - Agent Reserved.
- Sub Sellable = Sub Physical - active Sub Reserved.
- Transit -> Sub moves physical stock between domains; never subtract Sub again from Agent sellable.
- Stock source is resolved server-side.
- Sub stock is only for the owning Sales-Kurir-Sub's own purchase or an order they create for a consumer in their own referral network.
- Consumer self-checkout continues to use Agent stock.
- Sub reservation lifecycle remains reserve -> consume/release.
- Preserve the canonical lock ordering established in Package A.

### Payment
- Payment truth remains **Order-level**.
- DP, total paid, remaining balance, refund, and additional payment belong to the Order financial ledger.
- Invoice/delivery splitting must not create independent payment truth per invoice.
- Admin may need operational visibility of paid/unpaid, but canonical financial truth remains the payment ledger / Keuangan domain.
- Shipping must not be silently blocked merely because an order is unpaid unless an explicitly locked business rule says so for a specific transition.

## 3. R-03 — Fulfillment, delivery, invoice grouping

### 3.1 Delivery lifecycle

Implement an explicit, auditable delivery lifecycle that separates:

1. fulfillment / preparation;
2. shipment/delivery execution;
3. delivery proof;
4. Admin final delivery verification;
5. return/follow-up outcome where applicable.

Payment verification, transaction verification, and delivery verification are separate concerns.

### 3.2 Sales-Kurir-Sub self-delivery

For an order item sourced from Sub stock:

- the owning Sales-Kurir-Sub self-fulfills/self-delivers it;
- it must not be routed through the normal Gudang fulfillment path;
- it must not be assigned to another courier;
- Sub reservation is consumed only by the authorized owner-delivery path when physical shipment actually occurs;
- delivery proof must be recorded through the authorized delivery flow;
- Admin performs final delivery verification.

Do not weaken Package A's generic shipment guards.

### 3.3 Admin final delivery verification

Admin must have a final verification action after delivery evidence/state is available.

Required outcomes:

- **received** — delivery accepted/confirmed;
- **not received / follow-up** — operational follow-up required;
- **return** — transition into the existing/extended return process.

The implementation must preserve auditability: actor, timestamp, outcome, and relevant evidence/reference.

Do not collapse this into payment verification.

### 3.4 Delivery-date split and invoice grouping

Initial order starts with one requested delivery date.

Admin may adjust delivery dates at item level. If items end up on different delivery dates:

- group invoice/delivery documents by **Order + delivery date**;
- do not create one invoice per product;
- items sharing the same order and delivery date belong to the same delivery invoice/group;
- payment remains attached to the Order as a whole.

The model must support one Order -> one or more delivery-date invoice groups without duplicating the Order financial ledger.

### 3.5 Quantity adjustment / split / return boundaries

Package A deliberately blocks unsafe Sub-sourced quantity adjust/split/return paths with 422. R-03 must replace those guards only where a correct inventory-safe workflow exists.

Required principles:

- every quantity decrease/increase must reconcile reservations, physical stock, shipment state, and financial totals correctly;
- already-shipped quantity cannot be treated as unshipped stock;
- Sub-sourced cancellation/adjustment before shipment releases reservation rather than inventing physical stock;
- after shipment, return handling must follow physical return evidence and movement rules;
- split operations must preserve source `agent|sub`, Sub Location, delivery date, and audit lineage;
- operations must be idempotent or reject duplicate state transitions safely.

Do not simply remove the current 422 guards.

### 3.6 Order-generated warehouse request

Where Gudang stock fulfillment is needed:

- the fulfillment/stock request originates from the Order/domain workflow, not as a free-form Gudang invention;
- Gudang sees the generated operational request and proposes/executes quantities within authority;
- Admin may confirm pending, partial cancellation due to stock, or full cancellation due to no stock;
- retain existing authoritative stock checks and movement audit.

## 4. R-04 — Authority, data exposure, product authority, reporting

R-04 starts only after the R-03 state model is implemented and tested.

### 4.1 Order authority / security

Review every order list/detail/action endpoint and frontend surface for role authority.

Locked intent:

- Super Admin: global administrative authority according to existing system rules.
- Agen/Admin: same-Agent operational authority as explicitly allowed.
- Keuangan: financial/payment authority; do not infer unrelated warehouse/delivery powers.
- Gudang: needs operational order detail required for fulfillment, but not unnecessary financial information.
- Kurir: minimal information for an available queue; full operational detail only when assigned/authorized.
- Sales-Kurir-Sub: own/referral operational scope only, never blanket Agent-branch access.
- Sales/Korsal/Konsumen: retain their established scoped visibility.

No special-case for a specific Order ID.

### 4.2 Operational vs financial data separation

Create/adjust API resources/projections so roles receive only data required for their task.

Gudang/Kurir operational views should not leak unnecessary:

- DP amount;
- total paid;
- remaining balance;
- refund amount/status;
- additional-payment details;
- payment gateway/bank evidence;
- commissions/fees not required for delivery/warehouse work.

Admin may see operational paid/unpaid status where required, but financial truth remains canonical in the payment layer.

### 4.3 Product / Variation authority

Admin authority is:

- Create;
- Read;
- Update;
- **NO Delete**.

Apply this consistently to both Product and Product Variation routes/policies/UI. Do not hide a backend delete permission only in the frontend; server policy must enforce it.

### 4.4 Operational transaction report

Provide an operational report with one row per `order_item`.

Canonical columns:

1. Order No
2. Tanggal
3. SKU
4. Produk
5. Harga
6. Qty
7. Status Item
8. Subtotal
9. Konsumen
10. Tgl Kirim
11. Kurir
12. Status Order
13. Sales
14. Korsal

Scope must follow role/Agent authority. Historical display names follow the current/latest actor-name rule below.

### 4.5 Finance / payment report

Provide a financial report/projection centered on Order-level payment truth, including as applicable:

- Grand Total
- Metode Bayar
- DP Dibayar
- Total Dibayar
- Sisa
- Status Pembayaran
- Refund
- Additional Payment
- allowed fees / commissions according to role authority

Do not recompute a competing payment truth if `PaymentSummaryService` already owns that calculation.

### 4.6 Current/latest actor-name rule

Reports show the **current/latest name** for actors referenced by stable IDs.

Do not add actor-name snapshot columns solely for reports.

This applies to Sales, Korsal, Kurir, and other referenced users where the stable relationship already exists.

## 5. API / model design constraints

- Prefer additive schema changes.
- Reuse existing Order, OrderItem, Shipment, Return, Payment, Media, ActivityLog, and warehouse models where they represent the same domain truth.
- Introduce a new table only when a real independent lifecycle/audit entity exists.
- Do not duplicate payment totals in an invoice-group table.
- Avoid nullable flags that encode multiple hidden states; use explicit states when lifecycle semantics require them.
- State transitions must be validated server-side.
- Use policies/query scoping/resources together; do not rely on frontend hiding.
- Every mutating endpoint must enforce authorization and same-Agent/ownership boundaries server-side.

## 6. Migration / historical-data requirements

Production already contains historical Orders/OrderItems/shipments/payment records.

Any Package B migration must:

- preserve existing rows;
- provide safe defaults for historical records;
- avoid silent identity/ownership remapping;
- avoid destructive cleanup;
- be compatible with the actual production DB engine;
- include pre/post reconciliation criteria in the checkpoint;
- follow expand -> backfill -> verify -> constrain where a transition cannot be safely expressed as one additive change.

## 7. Required test areas

At minimum, cover:

- R-03 delivery state transitions and invalid transitions;
- Sales-Kurir-Sub self-delivery ownership/foreign-location attempts;
- proof upload/reference authorization;
- Admin final verification outcomes;
- delivery-date grouping and split behavior;
- Order-level payment remaining invariant across multiple invoice groups;
- Sub quantity adjust/cancel/return inventory invariants;
- idempotent duplicate delivery/verification actions;
- Gudang financial-field non-exposure;
- Kurir available-vs-assigned detail scope;
- Sales-Kurir-Sub own/referral isolation;
- Admin Product/Variation delete denied while CRU allowed;
- operational report row/item scoping;
- finance report payment truth;
- current/latest actor names;
- migration compatibility for existing historical rows.

Add deterministic concurrency tests for any new multi-row inventory transition whose correctness depends on lock ordering.

## 8. Explicitly out of scope

Unless required to make R-03/R-04 correct:

- unrelated CMS redesign;
- unrelated payment gateway changes;
- unrelated shipping-provider redesign;
- new roles;
- changing historical referral codes;
- reworking Package A stock formulas;
- global UI redesign;
- destructive production cleanup of legacy Sub Locations;
- future features not documented in this package.

## 9. Stage gates

Package B is not complete until all of the following exist:

1. R-03 implementation + focused tests.
2. R-04 implementation + focused tests.
3. Full backend regression.
4. Frontend type-check + production build.
5. Claude independent final review; any real in-scope findings fixed directly.
6. Clean working tree / final diff reviewed.
7. DEV manual UAT.
8. Human Stage Gate approval.

Production deployment is a separate, explicitly authorized stage after the package is closed.
