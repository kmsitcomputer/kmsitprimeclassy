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

**LOCKED: exactly 11 roles.** `sales-kurir` was renamed in place to `sales-kurir-sub`; the legacy slug is only a compatibility alias. IMP-003 added the 11th role `koordinator-kurir` (branch delivery dispatcher, Human-approved in the IMP-002→IMP-004 master plan, 2026-10-05).

| Role | Position | Created by | Referral code | Data scope |
|---|---|---|---|---|
| `super_admin` | Platform owner | installer / tinker | — | All branches |
| `agen` | Branch owner | `super_admin` | `AG-` | Own branch |
| `admin` | Branch office staff | `agen` | — | Own branch |
| `keuangan` | Branch finance | `agen` | — | Own branch (financial surface only) |
| `gudang` | Branch warehouse | `agen` | — | Own branch (operational projection, no money) |
| `kurir` | Branch courier | `agen` or `koordinator-kurir` | — | Assigned shipments only |
| `koordinator-kurir` | Branch delivery dispatcher | `agen` | — | Own branch (dispatch queue + courier assignment; operational projection, no money, no fulfillment) |
| `korsal` | Sales coordinator | `agen` | `KO-` | Own downline |
| `sales` | Seller | `agen` (with a branch Korsal) or `korsal` | `SA-` | Own referred consumers |
| `sales-kurir-sub` | Seller + owner-deliverer | `agen` or `korsal`; or Sales converted by the owning `agen` | `SS-` (new only) | Own / referral scope + own Sub Location |
| `konsumen` | Buyer | self-registration (optional referral) or Google registration (**mandatory** referral, §23) | — | Own orders |

Who may create whom (**LOCKED**, `HierarchyRules`): `super_admin` → `agen`; `agen` → `korsal`, `sales`, `admin`, `keuangan`, `kurir`, `gudang`, `sales-kurir-sub`, `koordinator-kurir`; `korsal` → `sales`, `sales-kurir-sub`; `koordinator-kurir` → `kurir` (same Agent, parent = actor; explicit Human A1-02 approval). Nobody else creates users.

Authority by action (route role gate **and** a policy/service ownership check apply; "same Agent" is always enforced):

| Action | Allowed roles |
|---|---|
| Place an order | `konsumen`, `agen`, `korsal`, `sales`, `sales-kurir-sub` (policy narrows to own network / self) |
| View orders | `super_admin` all; `agen`/`admin`/`keuangan`/`koordinator-kurir` branch; `korsal` own `korsal_id`; `sales`/`sales-kurir-sub` own `sales_id`; `konsumen` own; `gudang` list only (operational projection); `kurir` none (uses `/kurir/orders`) |
| Manage courier accounts | `koordinator-kurir` may list/edit only its own child `kurir` accounts in the same Agent; cannot delete, reassign referrals or change roles/hierarchy |
| Dispatch workspace (`GET /dispatch`, `GET /dispatch/couriers`) | `super_admin` (with `?agent_id=`), `agen`, `admin`, `koordinator-kurir` — operational rows only, never financial amounts |
| Assign a courier to a shipment | `super_admin`; `agen`/`admin`/`koordinator-kurir` branch (kurir self-assigns via status); assignment locks Order→Shipment, rejects a different existing executor / terminal work, and is idempotent for the same executor (A1-03) |
| Move shipment status (`diproses`→`dikirim`→`terkirim`) | `super_admin`, `agen`, `admin`, `kurir`, `sales-kurir-sub` (owner of `self_sub`), and `koordinator-kurir` **only as the recorded executor of their own shipment** (proof required `dikirim`→`terkirim`); dispatcher authority never covers another courier's work |
| Move order status (`diproses` …) | `super_admin`, `agen`, `admin` |
| Cancel an order | `super_admin`; `agen`/`admin` branch; `korsal`/`sales`/`sales-kurir-sub`/`konsumen` for their own (business rule §14 decides when) |
| Adjust fulfilled quantity, reschedule/split | `super_admin`, `agen`, `admin` |
| **Add a product line to an existing order (SC-03)** | **`admin` only**, same Agent |
| Verify (approve/reject) payment proof / request pelunasan / confirm COD proof | `super_admin`, `keuangan` only. **`korsal`/`sales`/`sales-kurir-sub` are NOT payment approvers** (Human rule, supersedes UAT-008); route gate and controller both refuse them (§24) |
| Mark COD paid/unpaid directly (no proof workflow) | `super_admin`, `keuangan` (unchanged — deliberately excluded from the UAT-008 scoped extension) |
| Refund and additional-payment status; mark return refunded | `super_admin`, `keuangan` (lists also `agen`, `admin`) |
| Operate a shipment (pickup/deliver, receipt) | `super_admin`, `agen`, `admin`, `kurir` (own/claimable), `sales-kurir-sub` (own `self_sub` only), `koordinator-kurir` (own executor profile only) |
| Delivery verification | `admin` (same Agent) and `super_admin` |
| Return: request / review / inspect / finalize | `konsumen` / `super_admin`,`agen`,`admin` / `gudang` / `admin` (courier pickup+confirm by `kurir`) |
| Product & Variation create/read/update | `super_admin`, `agen`, `admin` |
| Product & Variation delete; fee write; category write | delete `super_admin`,`agen` (Admin **denied**); fees `super_admin`,`agen`; categories `super_admin` |
| Warehouse stock reads, sellable, transfers/opnames (read) | `super_admin`, `agen`, `admin`, `gudang` |
| Gudang "Order Diproses" queue + order/stock-request detail (only `diproses` AND no courier assigned) | `gudang` (own branch; server-side scope rule) |
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
| Submit a payment proof (bank transfer / DP / settlement / COD) | `konsumen` (own); `super_admin`, `agen`/`admin`/`keuangan` (branch); **`korsal`/`sales`/`sales-kurir-sub` on behalf of a konsumen currently in their own referral scope** (§24; upload only, never approve) |

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

## 11. Shipment lifecycle and the work-unit model (**LOCKED**)

**LOCKED MODEL (Human, 2026-10-07).** A Shipment is **NOT** per OrderItem.

One canonical Shipment represents the compatible delivery work of one **grouping key**:

> `Order` + `requested_delivery_date` + compatible `delivery_mode` + compatible owner (the owning Sales-Kurir-Sub, for `self_sub`)

Multiple OrderItems sharing that grouping key **MUST converge into ONE canonical Shipment**, and no path may mint a second same-key Shipment. Different dates ⇒ different shipments (a date is a real pickup); `self_sub` never merges with `standard` and two different Sub owners never merge. An order therefore spans several shipments/couriers through **dates or Sub owners, never item count**. Every path that can put a new item on a shipment — checkout, whole-line reschedule, partial-quantity reschedule split and SC-03 Add Product — converges on this rule through the single implementation `ShipmentCanonicalizationService` / `OrderFulfillmentService::consolidateIntoShipmentForDate`.

Which work is done at which grain (**LOCKED**, deliberately different per concern):

| Concern | Work unit |
|---|---|
| Gudang fulfillment proposal / warehouse queue | per **`OrderItem`** |
| Courier pickup / delivery progress | per **`OrderItem`** |
| Dispatch workspace (Koordinator) and courier assignment | per **canonical Shipment** |
| Thermal receipt (Resi) | per **canonical Shipment** |
| Shipment lifecycle (`status`/`shipped_at`/`delivered_at`) | **derived aggregate** over the canonical Shipment's own items |

- A Shipment is created `standard`, `pending`, with no courier; its provider/route/rate/ETD/weight are immutable snapshots and the shipping fee snapshot lands on exactly one canonical shipment.
- **Courier progress is PER `OrderItem`.** Acting on one item never moves its shipment siblings, even though they share the order, the shipment, the date and the courier. The Shipment stays the assignment/delivery container (executor authorization, delivery proof, aggregates); the Office per-shipment and per-order bulk paths remain available to `agen`/`admin`/`super_admin`.
- A Shipment's `status` / `shipped_at` / `delivered_at` are a **derived aggregate over its own items**, never a copy of whichever item moved last: it is `delivered` (and `delivered_at` set) only when EVERY item has arrived, and it never walks backwards once delivered. A partially delivered shipment is `in_transit` and is **not** verifiable for delivery.
- Kurir may claim an unassigned `diproses` shipment (becomes `dikirim`); another courier can then no longer see or act on it. `delivered` (`terkirim`) requires **photo proof**.
- Delivery verification is only possible for a shipment whose items have ALL arrived (it is append-only, so the verdict is re-derived from the items, never trusted from a stored column alone).
- Thermal receipt is per shipment (pre/post pickup derived from state), read-only, audited, never for a cancelled order.

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
- **"DP Diajukan" / "Menunggu Verifikasi" (UAT-004)** is a read-only triage projection, never money: submitted-but-unverified nominal is exposed as `submitted_dp` and is NEVER added to `verified_dp`/`total_paid` or subtracted from `remaining_balance`. It is read from the order's **current** manual/COD transaction — the same row the verification action applies to (`PaymentController::latestManualTransaction`) — so a verification row left behind on a superseded transaction can never make a settled order look like it is still awaiting verification.
- **Settlement request idempotency (Human decision):** a DP settlement request asks for one specific outstanding amount. An equivalent request for the same canonical financial context (same order, same still-pending `dp_settlement` transaction, same nominal as the order's **current** `remaining_amount`) is an **idempotent replay**: no second transaction, no ledger movement, no duplicate financial side effect — the existing pending settlement is returned with HTTP 200. Two simultaneous requests converge on **one** canonical pending settlement (the decision runs under the canonical Order-first lock and re-reads through a locking read). If the outstanding balance has **moved** since a settlement was requested, that pending settlement is **preserved untouched** and the different request is refused by the existing duplicate-obligation rule (`messages.payment.already_processed`) instead of silently replacing it; resolving it uses the existing reject/verify action — no new workflow.
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

- **LOCKED chain:** order enters `diproses` → one Stock Request (+ items for Agent-sourced order items) → **Gudang proposes** quantities (≤ remaining) → **Admin approves** → physical move + reservation release (§10). Direct Gudang fulfilment is disabled. Partial fulfilment keeps the remainder on the same request/item; status is derived `pending / partial / fulfilled`.
- **IMP-001 Gap 2 (user-facing surface):** there is no standalone user-facing "Stock Request" menu/feature anymore. Gudang works the **"Order Diproses"** queue (`GET /warehouse/orders/diproses`): an order is visible to Gudang **only while** its status is exactly `diproses` **and** no courier has been assigned (no shipment with `courier_id`, and no `self_sub` shipment with `self_delivered_by_user_id`). This invariant is enforced **server-side** (queue query, `OrderPolicy::view` for the order detail, and `StockRequestProposalService::propose`) — the moment either becomes false the order disappears from Gudang's queue and direct API/detail access is refused. The internal `stock_requests` / `stock_request_proposals` tables and the proposal/approval endpoints remain the canonical persistence behind this workflow (Gudang proposes via `POST /warehouse/stock-requests/{request}/proposals`; Admin reviews via `GET /warehouse/fulfillment-proposals` + approve/reject) and are never exposed as a standalone feature.
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

- Quantity adjustment and reschedule: only while the order is still in the fulfillment window — order status `diproses` (and `diterima` for a reschedule), never once it has started shipping (`dikirim`/`terkirim`/… ) or been cancelled; `super_admin`/`agen`/`admin`. The window is enforced on the **Order** row under its lock, not only on the item, because per-item courier progress means an item's status no longer mirrors its order's. Sub-sourced adjustments reconcile the Sub reservation (reduce/increase/split preserving `sub_location_id`, `self_sub` actor and lineage); post-shipment quantities are never rewritten (use returns / additional items).
- **LOCKED (Human, final 2026-10-07):** a requested delivery date is re-datable ONLY for KURIR ONLINE (`shipping_provider_code = 'openroute'`) and SELF DELIVERY / Sub (`delivery_mode = 'self_sub'`). EKSPEIDISI/RajaOngkir and PICKUP/Ambil di Tempat are **forbidden**. It is an allowlist decided by `Shipment::isDeliveryDateReschedulable()` from the canonical persisted fields, enforced server-side inside the Order lock (frontend gating is convenience only).
- A partial split creates a child item (`split_from_order_item_id`) with its own shipment; for Agent items the Stock Request is split conservatively, or the split is rejected 422 if the request has progressed beyond what can be split deterministically.

## 22. SC-03 — add-line to an existing order

**HUMAN BUSINESS DECISION — APPROVED (2026-10-06):** new additions are forbidden after any courier or self-delivery executor is assigned anywhere on the Order. No automatic unassignment or Gudang exception. If SC-03 wins before assignment, added demand must be fulfilled before explicit assignment or implicit pickup assignment can close Gudang access. Existing identical-request replay is preserved.

**LOCKED (Human decision):** only the **`admin`** role, same Agent/branch, may add a **new** product/variation line to an existing order. `super_admin`, `agen` and every other role are denied; `OrderPolicy::manageFulfillment` is unchanged and is not reused.

| Rule | Behaviour |
|---|---|
| Eligibility | Order status `diproses` only; no shipment has `courier_id` or `self_delivered_by_user_id`; Agent stock only; Sub-sourced orders are rejected 422 |
| Resolution | Shared `resolveLine`; server price/fees/SKU/snapshot (same code path as checkout); client price/stock-source fields rejected |
| Quantity / date | `quantity` integer ≥ 1; `requested_delivery_date` optional (defaults to the order's estimate), must not be in the past for a **new** addition |
| Fulfilment | The added line MUST reuse/consolidate into the compatible existing **canonical Shipment** for its own grouping key (§11) instead of creating another same-key Shipment; a fresh `standard` pending Shipment is created only when no canonical unit for that key exists yet. SC-03 already requires that no executor exists anywhere on the order, so the joined unit is always unassigned and mutable. The order's single Stock Request gains one item (and is re-opened to `pending`/`partial` if it was `fulfilled`; history preserved); missing/cancelled request ⇒ 422 |
| Money | Totals via `OrderTotalCalculator`, payment via `PaymentService::reconcileTotals`; unpaid/partial ⇒ remaining grows; fully paid ⇒ exactly one pending `OrderAdditionalPayment` for the delta linked to the new item; nothing is silently marked paid |
| Commission | Agent + sales commission rows like checkout; courier fee on delivery |
| Idempotency | `Idempotency-Key` required (non-blank, ≤ 100 chars); unique per order. Replay of the **same original request** (product, variation, quantity, requested date, payment method; `reason` excluded) returns `200` without any new side effect — even after the line was adjusted/split, the order moved on, or the date elapsed; a materially different request under the same key ⇒ `409` |
| Concurrency | Lock order Order → Stock Request → inventory (§ARCHITECTURE 7); executor eligibility checked by a current locking read inside the Order boundary; safe against courier assignment, warehouse approval, checkout and other additions |
| Response | `201` (replay `200`) with the freshly persisted canonical `OrderResource` |
| Audit | `order_item.added` activity log |

## 23. Google sign-in (IMP-001, konsumen only)

- Google is an **identity provider only**; it never bypasses referral rules. Email/password registration is unchanged (referral still optional there).
- An already-linked Google identity simply logs in (the provider identity always wins over an email collision). A local account is **auto-linked only if its own email is verified** (`email_verified_at` set) and equals the verified Google email; local registration does not verify email, so an **unverified local account is never auto-linked** (`local_account_exists`, nothing created or linked) — otherwise a pre-registered victim email would hand the account to whoever signs in with it.
- **Secure explicit linking:** an authenticated konsumen may link a Google account from *My Account* (`mode=link`, requires the web session; Google email need not match). A Google identity owned by anyone else (`identity_in_use`) or a second identity on the same user (`already_linked`) is refused. Linking never touches referral/ownership.
- Whatever path is used, an existing account's referral/ownership fields (`agent_id`, `korsal_id`, `sales_id`, `parent_id`) are **never** written; a referral link opened alongside is recorded as ignored in the audit log. Reassignment stays exclusively `ReferralReassignmentService`.
- Google **login never creates** a konsumen. A **new** konsumen is created only from `register` mode with a valid referral code that is re-validated and re-resolved server-side at callback time (`ReferralService::resolveChainByCode`); no valid referral ⇒ no account.
- An email that belongs to a non-konsumen, inactive or soft-deleted account is refused (`email_in_use`) — never linked, never duplicated.
- OAuth state: server-side session (`state`, `nonce`, PKCE verifier, mode, referral *code*), one-time use, 10-minute TTL; `state`/`nonce`/`aud`/`iss`/`exp`/`email_verified` are checked. No Google token is stored or logged; only `provider`, `provider_user_id`, `provider_email`, `last_login_at` are kept (`user_social_identities`, UNIQUE `(provider, provider_user_id)` and `(user_id, provider)`).

## 24. Assisted consumer payment (IMP-001)

- `korsal`, `sales` and `sales-kurir-sub` may submit a payment **proof** for an order of a konsumen who is **currently** inside their own referral scope (`OrderPolicy::payOnBehalf`: same Agent; Korsal ⇒ `konsumen.korsal_id = actor`, Sales/Sales-Kurir-Sub ⇒ `konsumen.sales_id = actor`). Knowing an order id grants nothing; cross-Agent and cross-referral access is denied; after an audited reassignment the scope follows the konsumen's current chain.
- Order owner (`orders.konsumen_id`) is never rewritten. The payer actor is recorded on the proof (`submitted_by_user_id`, `submitted_on_behalf`) and in the audit log (`payment.proof_submitted` / `payment.cod_proof_submitted`, with `on_behalf_of_konsumen_id`). Historical proofs keep `NULL` (unknown, not backfilled).
- The payer **cannot** mark anything paid on their own: `PaymentService` remains the only writer of paid/remaining/status. **Authority (Human change request, supersedes UAT-008):** `korsal`/`sales`/`sales-kurir-sub` may only SUBMIT proof in their `payOnBehalf` scope. `verify`, DP-settlement request and COD-proof confirm are `super_admin`/`keuangan` only (route `role:` gate + `assertMayVerifyPayment`). **Payment audit trio** stored on `bank_transfer_verifications` / `cod_payment_proofs`: *Pembayar* `paid_by_role` (`konsumen`|`sales`|`korsal`; a Sales/Sales-Kurir-Sub may claim only `sales`, a Korsal only `korsal`, everyone else only `konsumen`; default `konsumen`), *Bukti di-upload oleh* `submitted_by_user_id` + `submitted_at`, *Diverifikasi oleh* `verified_by`/`confirmed_by` + timestamp. Historical rows keep NULL (never backfilled).
- **Hardened invariant (applies to every submitter, including the konsumen):** a proof is refused (422) once the transfer is verified/paid, the COD proof is confirmed, or the order is cancelled. Previously a re-upload silently reset a *verified* proof to `pending` while `paid_amount` stayed applied, so a second verification would add the same amount again (double count). No authoritative rule permits replacing verified evidence, so this is kept deliberately; a *rejected* proof can still be re-submitted. Writers lock verification (or COD proof) row → transaction → order.
- **Resolved in IMP-004 (2026-10-05):** the deferred gap above is closed — `OrderPolicy::view` now ALSO grants read access to the Sales whose konsumen is **currently** in its referral scope (mirroring `payOnBehalf`; same Agent), so the new Sales can open the older order's detail after an audited reassignment. The order's historical `sales_id` snapshot is never rewritten (audit continuity, AGENTS.md §9) — the old Sales keeps its snapshot access, and a Sales that is neither snapshot owner nor current scope is still denied.
- Ordinary email/password registration is unchanged in IMP-001 (referral remains optional there); only Google registration requires a referral.
- A proof is refused (422) when the above conditions hold. Gateway methods (Xendit/Tripay/Stripe) have no proof step and remain webhook-only.

## 24a. Stock pages and order filters (change request)

- `GET /stock/products|variations` list every target the viewed Agent holds in legacy OR warehouse rows with ADA/DITAHAN/TERSEDIA from `SellableStockService` — the same truth as catalog, storefront and checkout. `StockService::reserveFor*` provisions a ZERO-quantity commitment row for a warehouse-only target so such a target is orderable; availability is still decided canonically (nothing invented).
- `GET /orders` accepts `status`, `payment_status` (canonical settlement states: unpaid, pending_verification, partially_paid, paid, failed, refunds), `search`, and the Dispatch-equivalent `delivery_date`, `province_id…village_id`, `paid` (one shared `OrderFilterService`); `GET /orders/regions` gives cascading options from the caller's own scoped orders. Filters only narrow the role scope (scope is a nested group); operational roles cannot use payment filters.

## 25. Observations to confirm (not changed by documentation)

- **Self-purchase sales fee for Sales-Kurir-Sub:** the beneficiary rule (§15) treats `agen`/`korsal`/`sales` buyers as self-referrers but not `sales-kurir-sub`, so a Sales-Kurir-Sub buying for itself routes the sales fee to its Korsal (or the Agent) rather than itself. Confirm with the business whether this is intended before relying on or changing it.
- **Legacy stock fallback:** warehouse-authoritative mode is the default, but a target with no warehouse rows still falls back to legacy on-hand (§8).
