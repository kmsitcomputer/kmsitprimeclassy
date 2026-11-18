# OWNER LOCK — Decision Register
Approved by Owner, 2026-11-11. This is the authoritative post-closure decision overlay; underlying source documents are preserved.

## Immutable scope
REM-01 import/export; REM-02 variant images; REM-03 edit images; REM-04 guarded hard delete (Admin cannot delete); REM-05 product filters default Semua; REM-06 category hierarchy; REM-07 verified delivered bestseller; REM-08 variant attributes; REM-09 SKS regular checkout stock source; REM-10 invoice; REM-11 historical-only POS.

## ODR-01…05
- **ODR-01 A**: POS Admin own valid linked Agen; Super Admin explicitly selects valid Agen; Agen own Agen stock. One source per order; POS does not use SKS Sub source.
- **ODR-02 A**: requester required existing account or manual independent snapshot, consumer optional referral-scoped, recipient independent; no fake account/CRM.
- **ODR-03 B**: import stock via existing inventory adjustment/approval; `import=target`, `duplicate=update` global SKU, `atomic=rows`; no direct balance overwrite.
- **ODR-04 A**: bestseller delivered eligible units net legitimate returns, fully paid and verified, Agent scoped, 7/30/90/all, 4/8/12.
- **ODR-05 revised**: POS records **only actual historical transactions**, no new checkout; manual business date unlimited past, no future. Keep system creation, verification, shipping and audit timestamps truthful. Historical order record may be created in canonical orders, but must not generate new real-world sale/stock/payment/commission effects.

## D-01…07 — ALL OPTION A, FINAL
- **D-01 A**: proof-based per-item stock classification and authorized reconciliation **before** admitting historical order; no quarantine option B.
- **D-02 A**: existing standard payment evidence only, independent authorized verification; no substitute attestation option B.
- **D-03 A**: evidenced **full** external commission settlement only; unsupported external partial settlement remains blocked; no extension option B.
- **D-04 A**: preserve closed/issued reports; separate clearly labelled historical-business and current-recording reconciliation views; no automatic restatement.
- **D-05 A**: reject historical price/discount discrepancy against canonical current server values; no evidence-based override option B.
- **D-06 A**: actual **per-item recipient receipt** event for bestseller, not aggregate shipment completion.
- **D-07 A**: withhold affected catalog row publication until stock approval; no published catalog with pending stock option B.

## Additional design/operational locks
- **POS–CO parity**: shared transaction presentation contract, consistent field semantics, statuses, filtering, sorting, pagination, search, and RBAC-appropriate actions; same canonical List Order. Existing CO/list-card presentation is not automatically a column-header table. Backend flows differ.
- **Invoice Design Lock**: Owner-approved original white/maroon invoice, official PrimeClassy logo, browser/print/PDF consistency; no finance math changes.
- **POS Design Lock**: Owner-approved original Modern Dark Edition maroon, standalone two-column, no sidebar or extra dashboard; historical-only.
- **No new POS tables**; only justified additive typed nullable fields in existing canonical tables subject to compatibility review.
- **No new Owner questions** for these already-decided topics. Evidence gaps/engineering specifications are not new business decisions.
- **No implementation authorization**.
