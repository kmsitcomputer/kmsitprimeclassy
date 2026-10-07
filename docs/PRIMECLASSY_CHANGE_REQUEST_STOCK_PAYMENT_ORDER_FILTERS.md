# PRIMECLASSY — Change Request: Stock Sync, Payment Authority & Order Filters

**Status:** Ready for implementation in DEV
**Scope:** PrimeClassy
**Repository:** `/www/dev/primeclassy`
**Environment:** DEV first; production is out of scope until Human approval.

## Objective

Implement the collected Human requirements as one coherent change set without redesigning unrelated workflows.

## ISSUE-01 — Product / Variation Stock Synchronization

### Observed problem

For an Agent account:

- `Katalog → Produk & Kategori` shows product **Kastangel**, type **Varian (3)**, stock **216**, status **Aktif**.
- `Katalog → Stok Produk → Varian` shows **Tidak ada data**.
- Human reports the inconsistency prevents the product from being ordered.

### Required behavior

1. Determine the actual canonical stock source used by product management, stock management, storefront availability, cart, checkout, and order validation.
2. Product aggregate stock and variation stock must derive from the same canonical truth.
3. For variation products, each active/orderable variation must appear correctly in `Stok Produk → Varian` with `ADA`, `DITAHAN`, and `TERSEDIA` according to existing business rules.
4. Agent/location ownership must be respected. Stock belonging to another Agent/location must not be exposed as available stock.
5. An in-stock variation must be orderable; an out-of-stock variation must remain blocked.
6. Do not solve this by hiding the stock column, forcing stock to zero, creating fake stock, or bypassing checkout stock validation.
7. Do not delete/reset data as a workaround.

### Investigation requirement

RECON ONCE against actual code/schema. Trace `Product`, `ProductVariation`, `ProductStock`, `ProductVariationStock`, `WarehouseStock`, `CatalogSku`, relevant stock services/resources/queries, Agent/location scoping, storefront availability, cart, checkout, and order validation.

Report the exact source of the displayed `216`, why the variation stock table is empty, and why ordering fails.

---

## ISSUE-02 — Sales/Korsal Payment Authority and Settlement Audit Trail

### Human business rule

Sales and Korsal are **not payment approvers**.

For consumers within their permitted referral/scope, Sales/Korsal may submit/upload payment proof. They must not approve or reject that payment.

### Required authorization behavior

For Sales and Korsal:

- Remove/hide the `Verifikasi bukti transfer` approval controls including `Setujui` and `Tolak` where they are acting only as Sales/Korsal.
- Preserve the ability to submit/upload payment proof for consumers/orders within their authorized referral/scope.
- Enforce this server-side as well as in the UI. A hidden button alone is insufficient.
- Direct calls to approval/rejection endpoints by unauthorized Sales/Korsal must be rejected.
- Do not broaden their order/customer scope.

### Payment / settlement actor tracking

For DP and especially pelunasan, the system must distinguish at least:

1. **Pembayar / paid by** — who actually supplied the payment: `Konsumen`, `Sales`, or `Korsal`.
2. **Bukti di-upload oleh / proof uploaded by** — authenticated user who submitted the proof.
3. **Diverifikasi oleh / verified by** — authorized verifier/approver.

Example: consumer transfers money and sends screenshot to Sales; Sales uploads it. Store `paid_by = Konsumen`, `proof_uploaded_by = Sales`, and later `verified_by = authorized verifier`.

If Sales or Korsal actually pays on behalf of the consumer, `paid_by` must record Sales or Korsal respectively.

Preserve/audit the relevant amount, payment method, payment/proof timestamps, verification timestamp, and existing payment history semantics. Reuse the existing payment transaction/verification architecture where possible; do not invent a parallel payment system.

---

## ISSUE-03 — Order Filters by Role

Use the existing **Koordinator Kurir → Dispatch** filtering UX/query pattern as the design reference and reuse existing filter components/query logic where practical.

### Admin

Admin `Order` must have:

- **Status Order** filter.
- **All applicable filter contents/options currently available in Koordinator Kurir → Dispatch**, exposed on Admin Order as well, with equivalent meaning and behavior.

Admin and Dispatch must not interpret the same filter differently.

### Keuangan

Keuangan `Order` must include a clear **Status Pelunasan / Payment Settlement Status** filter based on the actual payment state model. Use existing canonical states; do not create cosmetic frontend-only states.

### Sales and Korsal

Sales and Korsal `Order` must have useful filtering for orders inside their existing authorized referral/scope. Filtering must never broaden access to orders outside their scope.

The filter work must remain compatible with ISSUE-02: Sales/Korsal can work with payment-proof submission in scope but cannot approve/reject payment.

---

## Cross-cutting constraints

- DEV only for implementation/testing.
- Do not touch production.
- Do not run product or transaction reset as a workaround.
- No `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `TRUNCATE`, FK disabling, or destructive cleanup.
- Preserve existing order, payment, shipment, fulfillment, commission, warehouse history, PWA, OAuth, CMS, and unrelated business rules.
- Reuse existing architecture/components/services before creating new abstractions.
- If a schema change is genuinely required for `paid_by`/audit attribution, make it additive and backward-compatible; preserve existing historical rows safely.
- Backend authorization is mandatory for role restrictions.
- No full backend suite for this work unit. Use focused/relevant tests only.
- Do not commit, push, or deploy until Human reviews the result.

## Acceptance criteria

1. Product/variation stock views, storefront availability, and order validation agree on canonical stock and Agent/location scope.
2. The observed variation-stock-empty/order-unavailable inconsistency is fixed at the root cause.
3. Sales/Korsal cannot approve/reject payment through UI or API, but can upload proof for permitted referrals/orders.
4. DP/pelunasan records distinguish payer, proof uploader, and verifier, including `Konsumen` / `Sales` / `Korsal` payer attribution.
5. Admin Order has Status Order plus applicable Dispatch filters.
6. Keuangan Order has Status Pelunasan filter.
7. Sales/Korsal Order filtering respects their existing authorization scope.
8. Focused tests pass; frontend typecheck/build validation is run only if relevant to changed frontend files; `git diff --check` is clean.
