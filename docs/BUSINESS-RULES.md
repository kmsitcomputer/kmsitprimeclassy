# Prime Classy — Business Rules

Canonical business invariants of the system as implemented. Source of truth for roles, authority, stock, fulfilment, payment and SC-03. System overview: [MASTER-SYSTEM.md](MASTER-SYSTEM.md); mechanics: [ARCHITECTURE.md](ARCHITECTURE.md).

> **LOCKED** marks an invariant that developers and AI agents **must not change without explicit Human business approval**. If source contradicts a LOCKED rule, report it — do not pick a side silently. Unmarked rows are implemented behaviour that follows from the locked rules.

---

## 1. Conventions

- Backend is the only security boundary. Frontend hiding, `PermissionMap` hints and request fields are never authority.
- Money, price, fee, SKU, weight, shipping and stock source are **server-derived**; clients send ids and quantities only.
- Orders and items store **snapshots**; later catalog/fee/name changes never rewrite them (actor names are the exception: current/latest name from stable IDs, no name snapshots).
- Status vocabulary: order/item `diterima, diproses, dikirim, terkirim, pengembalian, kembali, dibatalkan`; warehouse documents use English snake_case (`pending, partial, fulfilled, cancelled, approved, rejected, completed, executed, received …`).

## 2. Role and authority matrix

**LOCKED: exactly 10 roles.** `sales-kurir` was renamed in place to `sales-kurir-sub`; there is no 11th role; the legacy slug is only a compatibility alias.

| Role | Position | Created by | Referral code | Data scope |
|---|---|---|---|---|
| `super_admin` | Platform owner | installer / tinker | — | All branches |
| `agen` | Branch owner | `super_admin` | `AG-` | Own branch |
| `admin` | Branch office staff | `agen` | — | Own branch |
| `keuangan` | Branch finance | `agen` | — | Own branch (financial surface only) |
| `gudang` | Branch warehouse | `agen` | — | Own branch (operational projection, no money) |
| `kurir` | Branch courier | `agen` | — | Assigned shipments only |
| `korsal` | Sales coordinator | `agen` | `KO-` | Own downline |
| `sales` | Seller | `agen` (with a branch Korsal) or `korsal` | `SA-` | Own referred consumers |
| `sales-kurir-sub` | Seller + owner-deliverer | `agen` or `korsal`; or Sales converted by the owning `agen` | `SS-` (new only) | Own / referral scope + own Sub Location |
| `konsumen` | Buyer | self-registration (optional referral) | — | Own orders |

Who may create whom (**LOCKED**, `HierarchyRules`): `super_admin` → `agen`; `agen` → `korsal`, `sales`, `admin`, `keuangan`, `kurir`, `gudang`, `sales-kurir-sub`; `korsal` → `sales`, `sales-kurir-sub`. Nobody else creates users.

Authority by action (route role gate **and** a policy/service ownership check apply; "same Agent" is always enforced):

| Action | Allowed roles |
|---|---|
| Place an order | `konsumen`, `agen`, `korsal`, `sales`, `sales-kurir-sub` (policy narrows to own network / self) |
| View orders | `super_admin` all; `agen`/`admin`/`keuangan` branch; `korsal` own `korsal_id`; `sales`/`sales-kurir-sub` own `sales_id`; `konsumen` own; `gudang` list only (operational projection); `kurir` none (uses `/kurir/orders`) |
| Move order status (`diproses` …) | `super_admin`, `agen`, `admin` |
| Cancel an order | `super_admin`; `agen`/`admin` branch; `korsal`/`sales`/`sales-kurir-sub`/`konsumen` for their own (business rule §14 decides when) |
| Adjust fulfilled quantity, reschedule/split | `super_admin`, `agen`, `admin` |
| **Add a product line to an existing order (SC-03)** | **`admin` only**, same Agent |
| Verify payment / mark COD / settle DP / confirm COD proof | `super_admin`, `keuangan` |
| Refund and additional-payment status; mark return refunded | `super_admin`, `keuangan` (lists also `agen`, `admin`) |
| Assign a courier to a shipment | `super_admin`, `agen`, `admin` |
| Operate a shipment (pickup/deliver, receipt) | `super_admin`, `agen`, `admin`, `kurir` (own/claimable), `sales-kurir-sub` (own `self_sub` only) |
| Delivery verification | `admin` (same Agent) and `super_admin` |
| Return: request / review / inspect / finalize | `konsumen` / `super_admin`,`agen`,`admin` / `gudang` / `admin` (courier pickup+confirm by `kurir`) |
| Product & Variation create/read/update | `super_admin`, `agen`, `admin` |
| Product & Variation delete; fee write; category write | delete `super_admin`,`agen` (Admin **denied**); fees `super_admin`,`agen`; categories `super_admin` |
| Warehouse stock reads, sellable, stock requests (read), transfers/opnames (read) | `super_admin`, `agen`, `admin`, `gudang` |
| Request stock addition / Sub adjustment | `gudang` (Admin approves/rejects) |
| Create transfers; create/count/submit opnames; propose fulfilment | `gudang` |
| Approve/reject transfers, opnames, fulfilment proposals, stock-addition requests; toggle Factory Plan | `admin` only |
| Create Sub Location / assign owner / eligible owners | `agen`, `admin` |
| Sub stock request: create / cancel / receive | `sales-kurir-sub` (own) |
| Sub stock request: approve / reject | `admin` |
| Sub stock request: execute (physical move) | `gudang` |
| Legacy manual `POST /stock/adjust` | route open to `agen`/`admin` but **disabled** in warehouse-authoritative mode (always 403 by default) |
| Operational reports (`transactions`, `sales`) | `super_admin`, `agen`, `admin`, `korsal` |
| Finance reports (`finance-orders`, `finance-summary`, `payment-status`, fee/refund) | `super_admin`, `agen`, `admin`, `keuangan` (network summary: `super_admin`,`agen`,`keuangan`; agent fees: `super_admin`,`agen`) |
| Google Sheets | `super_admin` (global); `agen`/`admin` (own Agent; Admin cannot touch `financial_summary`) |
| CMS, media library, languages, website settings, audit log, global gateway/shipping toggles, region import/export | `super_admin` |
| Agent payment-method config / shipping-provider config & store profile | `agen`,`admin` / `agen` |
| Referral-code management | `agen`, `korsal`, `sales`, `sales-kurir-sub` |

## 3. Agent hierarchy and creation

- An `agen` is its own branch (`agent_id = id`); every non-super-admin user carries a mandatory `agent_id` (**LOCKED**).
- Ownership fields are derived from the acting user, never from the request: an Agen-created Sales/Sales-Kurir-Sub must be placed under a Korsal of the same branch; a Korsal-created one is placed under that Korsal.
- Moving a Sales between Korsals or a konsumen between sellers is an explicit, audited reassignment — never automatic and never guessed.
- Only the **owning Agen** may convert an existing Sales into a Sales-Kurir-Sub (target must be in the actor's network with a valid Korsal).

## 4. Referral rules

- Prefixes: `AG-`, `KO-`, `SA-`, `SS-`. **LOCKED:** historical `SA-` / `SK-` codes remain valid and are never rewritten; a converted Sales keeps its existing code (`SS-` only if it has none); new Sales-Kurir-Sub users get `SS-`.
- konsumen, admin, keuangan, kurir, gudang have no code. Users manage (view/set/regenerate/delete) their own code.
- A konsumen's chain is snapshotted at registration; a direct Agen/Korsal referral leaves `sales_id`/`korsal_id` empty.

## 5. Product and catalog rules

- Catalog is global; stock is per Agent. A simple product requires a SKU; a variation product has no parent SKU and every variation has one. **All SKUs share one namespace** (`catalog_skus`); a SKU used by a product cannot be used by a variation and vice versa.
- An order line may reference only an **active** product; `has_variations = false` ⇒ product target (a variation id is rejected 422); `has_variations = true` ⇒ an active variation of that product is required.
- **LOCKED:** Admin may Create/Read/Update products and variations but **never Delete** (route group and policy both enforce it).
- Fees are per unit for `agent`, `sales`, `courier`, configured per product/variation and snapshotted onto the order item (× quantity).

## 6. Order and checkout authority

- Server resolves every line; client-supplied price/fee/SKU/subtotal/status/stock source is never accepted. Order creation needs an `Idempotency-Key`.
- COD starts at `diproses`; all other methods start at `diterima` and enter `diproses` only when the payment is verified (paid, or DP partially paid). Expedition shipping allows Manual Bank Transfer only.
- A shipment is **not** blocked merely because an order is unpaid after it is `diproses`; payment gating applies to entering `diproses` for non-COD orders.
- Only the transition table in `Order::TRANSITIONS` is legal, for every role including `super_admin`.

## 7. Stock-source decision rules (**LOCKED**)

Resolved server-side by `StockSourceResolver`; a client `stock_source`/`sub_location_id` is only compared, never trusted.

| Case | Stock source |
|---|---|
| Sales-Kurir-Sub buying for **themselves** (Sub explicitly chosen) | Sub stock of their own Sub Location |
| Sales-Kurir-Sub placing an order for a **consumer in their own referral network** (Sub chosen) | Sub stock of their own Sub Location |
| A referred consumer's **own** checkout | Agent stock |
| Any other Sales / Korsal / Agen / Sales-Kurir-Sub, other Agents, any default | Agent stock (Sub request ⇒ 403) |
| Sub chosen but no active owned Sub Location | 422 |
| Forged / foreign `sub_location_id` | 403 |

Mixed-source orders and SC-03 additions to a Sub-sourced order are out of scope (rejected).

## 8. Agent Sellable (**LOCKED**)

`Agent Sellable = Transit + (Factory Plan if enabled) − Agent Reserved`

- Factory Plan is committed/planned supply, usable only while the Admin toggle is on; it is never a physical-fulfilment source.
- Sub stock is **not** added to or subtracted from Agent sellable. Transit → Sub is a physical movement between domains.
- A target with no warehouse rows falls back to legacy `quantity_on_hand − quantity_reserved`.

## 9. Sub Sellable and Sub ownership (**LOCKED**)

`Sub Sellable = Sub Physical − active Sub Reserved`

- **1 Sales-Kurir-Sub user = at most 1 active Sub Location** (`owner_user_id` UNIQUE). The owner must be **active**, a Sales-Kurir-Sub and in the **same Agent**. Only `SubLocationOwnershipService` writes ownership.
- No silent reassignment or mapping; legacy unowned locations stay unowned until an Agen/Admin assigns one explicitly. Deactivation releases the owner (audit via `previous_owner_user_id` + activity log) and is blocked while stock remains.
- Gudang cannot create Sub Locations or assign owners. Owned Sub Locations are moved only through Sub stock requests, never generic transfers.
- A Sub's physical stock can never be decreased below active reservations (return, transfer, adjustment, opname).

## 10. Reservation and stock movement lifecycle (**LOCKED**)

- **Reserve** at order creation / quantity increase / SC-03 add: Agent → `quantity_reserved += qty` (physical unchanged); Sub → one `sub_stock_reservations` row per order item.
- **Physical movement** (Agent): an **Admin-approved** fulfilment proposal moves `transit −q`, `shipping +q`, `reserved −q` atomically; the same unit is never in both buckets.
- **Shipment** (Sub): the owner's shipment consumes the Sub reservation and decreases Sub physical stock once (`out` movement).
- **Pre-shipment cancellation / reduction** releases the reservation; it never invents physical stock. After fulfilment, cancellation reverses Shipping → Transit once (`cancellation_release`); dispatched goods return only through the return workflow.
- Reserve/consume/release are idempotent per item; every movement writes an immutable `stock_movements` row with actor and reference.
- Bucket rules: `shipping` changes only through approved fulfilment, cancellation or return reversal; `sales` is derived and never stored or edited; `factory_plan` is written only while active (via Admin-approved request).

## 11. Shipment lifecycle

- **LOCKED: shipment / delivery / resi grouping = ORDER + REQUESTED DELIVERY DATE** — never per product / per order item. Items of one order sharing a delivery date share ONE shipment (and print ONE resi); each distinct date is its own shipment. Checkout, SC-03 add-line, reschedule and split all use the single canonical resolver `ShipmentGroupingService` (new shipments are `standard`/`self_sub`, `pending`, no courier). Scheduling truth stays `order_items.requested_delivery_date`; a shipment's date is derived from its active items (no second editable date).
- Only a **mutable** shipment (pending, no courier, not shipped/delivered, no proof, **no assigned tracking/resi number**, no delivery verification) is reused or merged. Assigned, in-flight, delivered, tracked or verified shipments are historical/operational truth and are never regrouped or re-dated — a new/moved item gets its own new mutable shipment instead. Existing orders are brought onto the rule by `shipments:regroup` (dry-run default) or lazily when an order is rescheduled/added to.
- **Historical content immutability:** a shipment with finalized delivery evidence (delivered, in-transit, delivery proof, or a delivery verification) is frozen — **both** its identity and its content (item membership, represented quantity, delivery-date meaning) can never change. A full reschedule or a partial split against it is rejected 422, and the gate is driven by shipment-level evidence, so a stale/inconsistent item status cannot bypass it. An assigned/tracked shipment without delivery evidence keeps the pre-delivery latitude (a sibling may move to its own new shipment) but its own date/identity is never rewritten.
- **Shipping-fee conservation (fail-closed):** the order's shipping-fee snapshot is carried by a nonzero shipment. When mutable shells are consolidated the nonzero snapshot must survive — never lost, never double-counted (the column is `NOT NULL DEFAULT 0`, so a "carrier" is the nonzero one). Several nonzero carriers are deduplicated **only** when their material quote identity is identical, compared **per canonical provider** (round 5):
  - `rajaongkir`: requires a **complete** normalised `provider_meta.courier` **and** `provider_meta.service` (plus fee / `rate_per_km` / provider / distance). A missing / empty / whitespace-only courier or service can never be proven equivalent — two such carriers conflict (a single legacy carrier is still preserved, never guessed by price or shipment id). So `25,000 JNE/REG` is never equivalent to `25,000 J&T/EZ`, and two `25,000` quotes missing courier/service conflict.
  - `openroute` (Kurir Online): identity is the quote **provenance** — pricing rule (`price_per_km`, `minimum_distance_km`, `minimum_charge`), `chargeable_distance_km`, `routing_profile` and distance. The persisted fee is excluded (a canonical equal-allocation rewrites it), so a previous allocation never becomes a new independent quote; incomplete provenance on more than one carrier conflicts.
  - a nonzero fee under `free` / `pickup` / any unknown code is inconsistent data and is never treated as equivalent.
  **Conflicting** nonzero carriers abort the operation (interactive 422 / that order marked failed by `shipments:regroup`) rather than guessing an id, summing, or silently discarding a value. Incidental metadata (etd, description, timestamps, debug data) never affects equivalence.
- One order may still span several shipments/couriers (different dates, or committed shipments); order status summarizes them.
- Kurir may claim an unassigned `diproses` shipment (becomes `dikirim`); another courier can then no longer see or act on it. `delivered` (`terkirim`) requires **photo proof**.
- Shipment provider/route/rate/ETD/weight are immutable snapshots. Thermal receipt is per shipment = per Order + delivery date group and lists every active line of the group (pre/post pickup derived from state), read-only, audited, never for a cancelled order.

## 12. Delivery lifecycle, self-delivery and verification

- **LOCKED self-delivery:** a Sub-sourced item's shipment is `self_sub` with `self_delivered_by_user_id` = the owning Sales-Kurir-Sub and `courier_id = NULL` (DB CHECK + triggers). It is never routed through Gudang fulfilment and never assigned to another courier; a Sales-Kurir-Sub cannot operate a standard (Agent-sourced) shipment. The Sub reservation is consumed only by the owner's own shipment path; the generic office status endpoint cannot ship or deliver a Sub item.
- Delivery proof is recorded through the authorized delivery action.
- **LOCKED delivery verification:** an **append-only** record (`received`, `not_received`, `return`) with actor, time, note and an `Idempotency-Key`; the latest row is the current outcome; a replay is valid only for the identical `(shipment, outcome, note)` else 409. Admin (same Agent) / Super Admin only. It is separate from payment verification and does not itself create a return.
- Delivery grouping is **derived**: `Order + requested_delivery_date`; there is no invoice table and grouping never duplicates payment truth.

## 13. Payment truth, additional payment and refund (**LOCKED**)

- Payment truth is **Order-level**; `PaymentSummaryService` is the single canonical summary (grand total, DP, total paid, remaining, overpaid, additional-payment and refund status). Item/invoice/group views never carry independent payment state.
- `orders.total_amount` is recomputed only by `OrderTotalCalculator` (Σ `unit_price_snapshot × fulfilled_quantity` + shipping + admin − discount); `paid_amount`/`remaining_amount`/`payment_status` are written only through `PaymentService`.
- `remaining > 0` always means not fully paid. **Additional payment** appears only when an order that was already fully paid (remaining ≤ 0) later owes more than was paid; unpaid/partial orders simply carry a larger remaining balance. **Refund** appears only for real overpayment, incrementally. A DP settlement is a `payment` transaction, not an `additional_payment`.
- A pending obligation freezes further quantity increase/reduction on that item until settled. Settlement/refund processing is idempotent and never edits historical payment rows.
- Verification roles: `super_admin`, `keuangan`. Gateway webhooks are signature-verified, amount-checked and idempotent.

## 14. Returns and cancellation

- Return: konsumen files a per-item request (evidence) for a `terkirim` item → Kurir pickup/confirm → Admin/Agen review → Gudang inspects (received/good/damaged) → Admin finalizes. Return capacity = `fulfilled_quantity − returned_quantity`. A fulfilment increase restores cancelled quantity before counting additional.
- Good units go to Agent **Transit** (`return_restock`), or — for Sub-sourced items — to the **original Sub Location**; if that location cannot accept them the return is rejected, never silently redirected to Transit. Damaged units never become sellable.
- Cancellation: COD while `diterima`/`diproses`; non-COD only while `diterima`. Stock Requests are marked `cancelled` with history preserved; cancellation is transactional and idempotent.

## 15. Commission

- Agent and sales commission rows are created at order creation (and for SC-03 lines), per item, from the fee snapshot. The sales beneficiary is: the buyer if the buyer is an `agen`/`korsal`/`sales` (self-purchase); otherwise the buyer's `sales_id`, else `korsal_id`, else the Agent. Agent fee and sales fee are separate rows even for the same person.
- Courier commission is written per item on delivery (`terkirim`), idempotent, credited to the shipment's courier (or to `self_delivered_by_user_id` for `self_sub`). **LOCKED:** sales fee and courier fee are never merged; a Sales-Kurir-Sub may earn both.
- Courier fee follows the active fulfilled quantity (scaled on reduce/increase, conserved exactly on split, always from the historical snapshot); Agent/Sales snapshots and existing commission rows are untouched by adjustments.
- Fee visibility: `agent_fee` only `super_admin`/`agen`; `courier_fee` only `super_admin`/`agen`/`admin`/`keuangan`; sales fee to the beneficiary chain and finance; konsumen and kurir never see fee/price fields.

## 16. Warehouse requests and fulfilment

- **LOCKED chain:** order enters `diproses` → one Stock Request (+ items for Agent-sourced order items) → **Gudang proposes** quantities (≤ remaining) → **Admin decides PER PRODUCT (proposal line)** → physical move + reservation release for approved lines only (§10). Direct Gudang fulfilment is disabled. Partial fulfilment keeps the remainder on the same request/item; status is derived `pending / partial / fulfilled`.
- **Proposal item vs order demand (LOCKED distinction):** a `StockRequestItem` is the inventory REQUIREMENT caused by the order (requested / fulfilled / remaining); a proposal item is Gudang's PROPOSED fulfilment of it. Admin approval/rejection is per proposal item (`decision_status` pending/approved/rejected, with actor/time/reason). **Rejecting a proposed fulfilment never changes the order demand** — requested/fulfilled/remaining stay and Gudang may propose again. The proposal header status is derived (`pending`, `partial` = mixed/incomplete, `approved`, `rejected`); the whole-proposal endpoints only act on lines still pending. Approving one line never executes another.
- **Stock Request follows the order:** `requested_qty` tracks the order line's current active quantity and `remaining = requested − fulfilled` (never negative). Approval/rejection always re-reads the current demand and the proposal-item decisions under a row lock — never a stale REPEATABLE READ snapshot — so a concurrent quantity change can never corrupt the counters. An idempotent **replay** likewise returns the CURRENT committed decision graph — proposal, proposal items, Stock Request demand and the related `OrderItem` fields that the response projects (`order_quantity`, `delivery_date`) — via locking reads, while remaining exactly-once on every stock/audit effect. Quantity increase adds demand (re-opening a fulfilled request); a reduction may only consume the unfulfilled remainder — reducing below what the warehouse already fulfilled is rejected 422 (warehouse history is never rewritten). Gudang sees Jumlah Order / Diajukan / Dipenuhi / Sisa.
- Stock Request is created exactly once (idempotent); Sub-sourced items are excluded; an order made only of Sub items has none.
- Gudang cannot change stock by itself: stock addition (Transit/Factory Plan) and Sub adjustments are **requests approved by Admin**; Super Admin and Agen are not inventory approvers.

## 17. Sub stock requests (**LOCKED chains**)

- **Replenish:** Sales-Kurir-Sub requests → Admin approves → Gudang executes (Transit → Sub, transfer + movements + handover) → Sub confirms **receive**.
- **Return:** Sales-Kurir-Sub requests (≤ Sub sellable) → Admin approves → Gudang executes (Sub → Transit, movements + handover).
- A request is demand only; nothing moves before `execute`; execute is idempotent and fails 422 (request stays `approved`) if Transit/capacity is insufficient. Targets: any active product/variation (no Transit > 0 needed to ask).

## 18. Transfers and handovers

- Gudang creates a `pending` transfer; **Admin approves** to execute. Allowed buckets: Transit ↔ Sub, Sub ↔ Sub (different locations), and Factory Plan → Transit (Plan toggle on, Admin-approved). Shipping is closed to transfers; owned Sub Locations are rejected here.
- Completion is atomic and idempotent, writes paired `transfer_out`/`transfer_in` movements, and creates exactly one handover document. Handover printing (A4) is read-only apart from print audit.

## 19. Opname

- Types: physical (Transit / Shipping / Sub) and plan reconciliation; sellable is read-only diagnostics. **Gudang counts and submits; Admin approves/rejects**; Gudang cannot self-approve; Super Admin is not an approver by default.
- Approval applies the counted physical truth atomically, writes immutable `opname_adjustment` movements, preserves reservations (a shortage becomes a visible commitment deficit), rejects stale snapshots, and is idempotent.

## 20. Reports and visibility

- Operational transaction report: one row per `order_item`, exactly 14 columns (Order No, Tanggal, SKU, Produk, Harga, Qty, Status Item, Subtotal, Konsumen, Tgl Kirim, Kurir, Status Order, Sales, Korsal). `Kurir` for a `self_sub` shipment is the self-delivering Sales-Kurir-Sub. Order-level payment columns repeat per item and must **not** be summed.
- Finance report: one canonical row per order from `PaymentSummaryService`; Keuangan has no operational-report access.
- Gudang and Kurir receive an **operational projection** (no DP/paid/remaining/refund/payment evidence/price/commission); Kurir sees a minimal pre-claim item and full detail only once assigned. Sales-Kurir-Sub keeps the financial projection of its own orders.
- Scope is always applied in the backend query; names resolve live from stable IDs.

## 21. Existing-order adjustments

- Quantity adjustment and reschedule: only while the order is `diproses`; `super_admin`/`agen`/`admin`. Sub-sourced adjustments reconcile the Sub reservation (reduce/increase/split preserving `sub_location_id`, `self_sub` actor and lineage); post-shipment quantities are never rewritten (use returns / additional items). A quantity **increase or decrease** on a line whose shipment carries finalized delivery evidence (delivered / in-transit / proof / verification) is rejected 422 before any mutation, driven by shipment-level evidence (a stale item status cannot bypass it).
- **Delivery-date adjustment (Human-approved rule):**
  - The method is resolved server-side by ONE canonical classifier (`ShippingMethodClassifier`, from the canonical `shipping_provider_code` snapshots of the order's active shipment groups) — **no implicit fallback, never inferred from UI labels**. A null/empty/unknown/legacy code, or **mixed** provider codes, resolves to **UNKNOWN** and is treated exactly like a denial. Every denial is 422 **before any mutation**.
  - **Ekspedisi** (`shipping_provider_code = 'rajaongkir'`) and **Pickup** (`'pickup'`): Admin **cannot** change the requested delivery date — any full reschedule or partial split is rejected 422 before any mutation.
  - **Kurir Online** (`'openroute'`): date changes allowed while shipment lifecycle permits. `orders.shipping_fee_amount` is **unchanged** and the total is redistributed **evenly across all active delivery-date groups** using deterministic integer arithmetic — `base = floor(total / N)`, the first `total mod N` groups (ordered by `requested_delivery_date ASC`, then shipment id) take `base + 1`. Postcondition: `SUM(active group snapshots) == orders.shipping_fee_amount` exactly (no Rp1 lost or created). Monetary DECIMAL is parsed directly to integer minor units — **never floating point** — for the allocation. Committed/historical groups are never rewritten — a redistribution that would require it is rejected 422.
  - **Free delivery** (`'free'`): date changes allowed while mutable; every active group and `orders.shipping_fee_amount` must stay zero. An inconsistent nonzero fee **fails closed** 422 (never silently erased).
  - **UNKNOWN** (null / empty / unknown / legacy code, or any mixture of different provider codes): **fail closed** 422 before any mutation.
  - **Cross-date quote preflight:** before any date/membership mutation, every nonzero OpenRoute fee snapshot a redistribution would overwrite must share the same canonical quote **provenance** (pricing rule `price_per_km` / `minimum_distance_km` / `minimum_charge`, `chargeable_distance_km`, `routing_profile`, distance). The persisted fee is deliberately excluded, so a **previous canonical equal-allocation is never mistaken for a new independent provider quote**; conflicting or incomplete provenance fails closed 422.
  - This even-allocation is a **new-valid-change** rule only; it never normalises ambiguous historical quote evidence (conflicting historical carriers stay fail-closed, §11).
- **Reschedule boundary:** a shipment with finalized delivery evidence (delivered / in-transit / proof / verification) is **historical**: neither a full reschedule nor a partial split may change its membership, represented quantity or date (422, atomic). An item sitting alone on an operationally **committed** shipment (courier assigned / tracking issued, no delivery evidence yet) likewise cannot be re-dated — 422 atomically. Such a committed shipment that still has other active items is left untouched; the rescheduled item moves onto its own new mutable shipment.
- A partial split creates a child item (`split_from_order_item_id`) with its own shipment; for Agent items the Stock Request is split conservatively, or the split is rejected 422 if the request has progressed beyond what can be split deterministically.

## 22. SC-03 — add-line to an existing order

**LOCKED (Human decision):** only the **`admin`** role, same Agent/branch, may add a **new** product/variation line to an existing order. `super_admin`, `agen` and every other role are denied; `OrderPolicy::manageFulfillment` is unchanged and is not reused.

| Rule | Behaviour |
|---|---|
| Eligibility | Order status `diproses` only; Agent stock only; Sub-sourced orders are rejected 422 |
| Resolution | Shared `resolveLine`; server price/fees/SKU/snapshot (same code path as checkout); client price/stock-source fields rejected |
| Quantity / date | `quantity` integer ≥ 1; `requested_delivery_date` optional (defaults to the order's estimate), must not be in the past for a **new** addition |
| Fulfilment | The line joins the order's mutable shipment for its delivery date (a new one only when that date has none or its shipment is committed); the order's single Stock Request gains one item (and is re-opened to `pending`/`partial` if it was `fulfilled`; history preserved); missing/cancelled request ⇒ 422 |
| Money | Totals via `OrderTotalCalculator`, payment via `PaymentService::reconcileTotals`; unpaid/partial ⇒ remaining grows; fully paid ⇒ exactly one pending `OrderAdditionalPayment` for the delta linked to the new item; nothing is silently marked paid |
| Commission | Agent + sales commission rows like checkout; courier fee on delivery |
| Idempotency | `Idempotency-Key` required (non-blank, ≤ 100 chars); unique per order. Replay of the **same original request** (product, variation, quantity, requested date, payment method; `reason` excluded) returns `200` without any new side effect — even after the line was adjusted/split, the order moved on, or the date elapsed; a materially different request under the same key ⇒ `409` |
| Concurrency | Lock order Order → Stock Request → inventory (§ARCHITECTURE 7); safe against warehouse approval, checkout and other additions |
| Response | `201` (replay `200`) with the freshly persisted canonical `OrderResource` |
| Audit | `order_item.added` activity log |

## 23. Observations to confirm (not changed by documentation)

- **Self-purchase sales fee for Sales-Kurir-Sub:** the beneficiary rule (§15) treats `agen`/`korsal`/`sales` buyers as self-referrers but not `sales-kurir-sub`, so a Sales-Kurir-Sub buying for itself routes the sales fee to its Korsal (or the Agent) rather than itself. Confirm with the business whether this is intended before relying on or changing it.
- **Legacy stock fallback:** warehouse-authoritative mode is the default, but a target with no warehouse rows still falls back to legacy on-hand (§8).
