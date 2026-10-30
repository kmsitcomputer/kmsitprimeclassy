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
| Move shipment status (`diproses`→`dikirim`→`terkirim`) | `super_admin`, `agen`, `admin`, `kurir`, `sales-kurir-sub` (owner of `self_sub`, or recorded executor of an explicitly assigned `standard` shipment — CR-001), and `koordinator-kurir` **only as the recorded executor of their own shipment** (proof required `dikirim`→`terkirim`); dispatcher authority never covers another courier's work |
| Move order status (`diproses` …) | `super_admin`, `agen`, `admin` |
| Cancel an order | `super_admin`; `agen`/`admin` branch; `korsal`/`sales`/`sales-kurir-sub`/`konsumen` for their own (business rule §14 decides when) |
| Adjust fulfilled quantity, reschedule/split | `super_admin`, `agen`, `admin` |
| **Add a product line to an existing order (SC-03)** | **`admin` only**, same Agent |
| Verify (approve/reject) payment proof / request pelunasan / confirm COD proof | `super_admin`, `keuangan` only. **`korsal`/`sales`/`sales-kurir-sub` are NOT payment approvers** (Human rule, supersedes UAT-008); route gate and controller both refuse them (§24) |
| Mark COD paid/unpaid directly (no proof workflow) | `super_admin`, `keuangan` (unchanged — deliberately excluded from the UAT-008 scoped extension) |
| Refund and additional-payment status; mark return refunded | `super_admin`, `keuangan` (lists also `agen`, `admin`) |
| Operate a shipment (pickup/deliver, receipt) | `super_admin`, `agen`, `admin`, `kurir` (own/claimable), `sales-kurir-sub` (own `self_sub`, or a `standard` shipment explicitly assigned to their own Courier profile — CR-001), `koordinator-kurir` (own executor profile only) |
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

- **LOCKED self-delivery (amended by CR-SALES-KURIR-SUB-DELIVERY-001, Human-approved 2026-10-14):** a Sub-sourced item's shipment is `self_sub` with `self_delivered_by_user_id` = the owning Sales-Kurir-Sub and `courier_id = NULL` (DB CHECK + triggers). It is never routed through Gudang fulfilment and never assigned to another courier. The Sub reservation is consumed only by the owner's own shipment path; the generic office status endpoint cannot ship or deliver a Sub item.
- **CR-001 standard delivery by Sales-Kurir-Sub (Human-approved 2026-10-14):** an Agent-sourced (`standard`) shipment may be assigned to a Sales-Kurir-Sub Courier row **only by an authorized dispatcher** (`super_admin`, same-branch `agen`/`admin`/`koordinator-kurir`) and **only when the Sales-Kurir-Sub is eligible for that order**: same Agent, active account, and (own purchase `orders.konsumen_id` = them) OR (own sales snapshot `orders.sales_id` = them) OR (konsumen currently in their referral scope `users.sales_id` = them, same Agent) — checked server-side under the Order→Shipment lock, never from frontend input. No automatic assignment at order creation; no self-assignment (pickup-claim and the assign route both refuse them). A deactivated executor cannot continue a delivery. Once assigned, the Sales-Kurir-Sub progresses exactly like a normal Kurir (recorded-executor ownership, mandatory delivery proof, one courier-fee row on delivery, never merged with the sales fee).
- **Recorded Human interpretation of "no automatic assignment" (2026-10-14):** the `self_sub` executor (`self_delivered_by_user_id` set at order creation from Sub Location ownership) is **ownership attribution, not a dispatch assignment** — the DB CHECK makes an ownerless `self_sub` shipment impossible, so no office assignment step can exist there. The no-automatic-assignment rule governs the `standard` dispatch path.
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
- Verification roles: `super_admin`, `keuangan`. External payment gateways (Xendit/Tripay/Stripe) are removed by Owner decision; there are no gateway webhooks. Payable methods are manual bank transfer and COD only (DP/pelunasan are payment schemes, not gateways).

## 14. Returns and cancellation

- Return: konsumen files a per-item request (evidence) for a `terkirim` item → Kurir pickup/confirm → Admin/Agen review → Gudang inspects (received/good/damaged) → Admin finalizes. Return capacity = `fulfilled_quantity − returned_quantity`. A fulfilment increase restores cancelled quantity before counting additional.
- Good units go to Agent **Transit** (`return_restock`), or — for Sub-sourced items — to the **original Sub Location**; if that location cannot accept them the return is rejected, never silently redirected to Transit. Damaged units never become sellable.
- Cancellation: COD while `diterima`/`diproses`; non-COD only while `diterima`. Stock Requests are marked `cancelled` with history preserved; cancellation is transactional and idempotent.

## 15. Commission

- Agent and sales commission rows are created at order creation (and for SC-03 lines), per item, from the fee snapshot. The sales beneficiary is: the buyer if the buyer is an `agen`/`korsal`/`sales`/`sales-kurir-sub` (self-purchase); otherwise the buyer's `sales_id`, else `korsal_id`, else the Agent. Agent fee and sales fee are separate rows even for the same person.
- Courier commission is written per item on delivery (`terkirim`), idempotent, credited to the shipment's courier (or to `self_delivered_by_user_id` for `self_sub`). **LOCKED:** sales fee and courier fee are never merged; a Sales-Kurir-Sub may earn both.
- Courier fee follows the active fulfilled quantity (scaled on reduce/increase, conserved exactly on split, always from the historical snapshot); Agent/Sales snapshots and existing commission rows are untouched by adjustments.
- Fee visibility: `agent_fee` only `super_admin`/`agen`; `courier_fee` only `super_admin`/`agen`/`admin`/`keuangan` and to the courier-function beneficiary itself (`kurir`, `koordinator-kurir`, `sales-kurir-sub`); sales fee to the beneficiary chain and finance; konsumen never see fee/price fields.
- **Fee model (Owner decision 2026-10-29, confirmed against source).** Every fee is computed and recorded PER ORDER ITEM and PER TYPE (`agent`, `sales`, `courier` are distinct rows, each traceable to order · item · type · beneficiary · amount · status). A person may hold several rights on the same item (e.g. a Sales-Kurir-Sub earns the sales fee AND the courier fee; an Agen earns its agent fee AND, as direct referrer, a separate sales fee) — rows are never merged and never de-duplicated by beneficiary; the only uniqueness is per item + type (courier) so a replayed delivery cannot pay twice. A self-purchase does NOT zero the fee: the buyer is its own sales beneficiary when it is `agen`/`korsal`/`sales`/`sales-kurir-sub` (§25). Rights follow the FUNCTION actually performed (courier fee: Kurir, Koordinator Kurir, Sales-Kurir-Sub; sales fee: Sales, Sales-Kurir-Sub, Korsal, Agen; agent fee: the Agen), never merely who created the order.
- **Pending → final.** Every fee row is born `pending`. It becomes final (`approved`, the existing non-paid / non-reversed state) when ITS order item reaches `terkirim` — per item, so delivering one item of a multi-item order finalises only that item's agent / sales / courier rows (`CommissionFinalizer`, called from every delivery path). Finalisation is idempotent and never touches `paid` or `reversed` rows. `paid` is an external payout state with no writer in the application (§15a OD-05e). Pending and final amounts, type, item and status are visible to the roles above within their data scope.

### 15a. Refund commission-adjustment policy (**OD-05a…OD-05g approved — generation NOT_IMPLEMENTED: BLOCKED by pending policy entries**, Owner 2026-10-28; audited again 2026-10-29)

> **WS-8 status (2026-10-29 audit).** An adjustment cannot be generated without inventing policy: (1) the responsibility matrix (OD-05c) has dimensions but **no approved entry**, so which refunds adjust which beneficiary is undefined; (2) the deterministic discount-allocation rule (OD-05b) is undefined; (3) the application does not track which commissions were paid (no payout writer), so OD-05e recovery balances have no factual basis. Until the Owner approves matrix entries and the allocation rule, refunds keep NOT touching commission rows (today's behaviour). Nothing here is built on a guess; the table `commission_adjustments` remains unused and OD-05g schema work is deferred with it.

Design contract recorded by the Owner decision pack of 2026-10-28 (`docs/OWNER-DECISION-TRACEABILITY.md`
Appendix D; analysis basis PC-SV2-OD05-REFUND-COMMISSION-ANALYSIS-001). Precise status split:
**policy framework = APPROVED_DESIGN · exception entries = PENDING_OWNER_APPROVAL · adjustment generation,
schema changes and implementation = NOT_IMPLEMENTED.** Nothing in this section changes any existing §15 rule,
the refund ledger (§26), the K-5 receivable contract (§20c), or any live value.

- **OD-05a — Hybrid policy.** Commission adjustments are deterministic, computed from the ORIGINAL per-unit
  commission snapshots (§15) of the affected order item; responsibility/category exceptions apply ONLY through
  explicitly Owner-approved policy entries. No automatic change to fee rates; no rewriting of historical
  snapshots. Today's source behavior (refunds do not touch commission rows) stays fully in force until an
  authorized implementation package passes its own gates.
- **OD-05b — Snapshot-based eligibility.** Adjustment basis = product-level fee snapshots × eligible
  quantity/value reductions. A deterministic discount-allocation rule is a documented design requirement —
  none is invented here (no source evidence or Owner decision establishes one yet). Shipping charges and
  retained administration fees are NOT automatically commission-bearing product components. DP/installment
  payment amounts do NOT redefine the original per-unit commission snapshot.
- **OD-05c — Responsibility matrix (dimensions approved; entries PENDING).** Versioned matrix with columns:
  refund/return reason · responsible party · affected commission beneficiary · applicable adjustment treatment
  · required evidence · approver + policy version. **No individual exception entry is approved.** Courier
  commissions are never clawed back automatically because a refund occurred. Sales and courier commission
  lines of a Sales-Kurir-Sub remain separate (LOCKED §15).
- **OD-05d — Approved-obligation trigger.** A refund *request* alone never triggers a commission adjustment.
  The **approved refund obligation** (§26) is the policy trigger for entitlement adjustment. Design requires:
  idempotent adjustment creation; reversal/reinstatement via compensating journal entries only; no deletion or
  mutation of original commission rows; reconciliation against actual refund payout events (R_c proven); no
  duplicate adjustment across repeated events. Entitlement adjustment ≠ cash refund execution — different
  financial events, different authorities.
- **OD-05e — Separate recovery balance.** Where a commission was already paid OUTSIDE the application, a
  proposed adjustment may, after reconciliation, create a separate recovery balance — with **no automatic cash
  deduction, no automatic offset against future commission, no mutation of historical paid transactions.**
  Mandatory factual caveat preserved from the analysis: the application does **not** currently track which
  commissions were paid (no `status`/`paid_at` writer exists); external-payment verification is a documented
  future prerequisite, and no payout knowledge may be assumed or claimed.
- **OD-05f — Bounded decimal allocation.** Monetary adjustment values use 2-decimal precision with
  deterministic allocation, explicit rounding and remainder handling, cumulative adjustment bounded by the
  original commission entitlement, no duplicate reversal, and no negative payable commission without a
  separately approved recovery treatment (§15a OD-05e). No new rounding algorithm is introduced silently.
- **OD-05g — Schema readiness (requirements only; MIGRATION_NOT_AUTHORIZED).** Future implementation requires:
  `courier` support in `commission_adjustments.beneficiary_role` where needed; a unique adjustment
  identity/idempotency key; preserved Agent isolation; immutability of original commission rows; and stored
  policy version, source event, obligation reference, actor and audit provenance. **No ALTER TABLE, migration
  generation, or schema change is authorized by this section.**

Preserved financial invariants (restated, not new): commission amounts originate from per-unit fee snapshots,
not a percentage of gross sales · agent/sales/courier entries remain distinguishable · Korsal reporting stays a
downline aggregation with no standalone Korsal commission ledger · refund entitlement ≠ refund cash payout ·
commission entitlement ≠ commission payout · original commission records immutable · adjustment journals
append-only · refund ledger records never rewritten · cross-Agent access prohibited · Sales/SKS cannot approve
or execute refunds (OD-02 initiation ≠ approval) · no historical backfill without separate Owner authorization
· OD-K5 receivable policy remains independent of OD-05 commission policy.

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

### 20a. Laporan A "Transaksi Awal" dan Laporan B "Posisi Pembayaran Terkini" (**LOCKED**, Owner 2026-10-21)

- **Laporan A (Transaksi Awal)** — 24 kolom, satu baris per item produk **saat order dibuat**. Data immutable: ditulis dari snapshot di dalam transaksi checkout yang sama, tidak pernah ditulis ulang, dan hanya mencakup order baru setelah snapshot aktif (order lama tidak direkonstruksi, tidak ada nilai yang dikarang).
  - Kolom tingkat faktur (Ongkir, Diskon, Total Pembayaran, Total Bayar, Sisa) **hanya terisi pada baris item pertama** setiap faktur, supaya agregasi sheet tidak pernah menghitung dobel.
  - `AGN` = `orders.agent_id`. Nama aktor (Agen/Korsal/Sales) memakai **profil terkini** dari ID hierarki historis (kebijakan "actor names are the exception" di §3) — tidak ada snapshot nama.
  - Nama penerima, telepon penerima dan alamat berasal dari **snapshot order**; kategori produk memakai **snapshot kategori**.
  - "Total" = total **baris** item. "Sisa" = turunan `max(0, total − paid)` saat commit — **tidak** membaca `orders.remaining_amount`.
- **Laporan B (Posisi Pembayaran Terkini)** — 15 kolom, satu baris per faktur, mencakup order lama dan baru, nilai keuangan membaca **keadaan terkini** (`total_amount`, `paid_amount`, status pembayaran, status order, waktu pembaruan).
  - "Sisa Piutang" = `max(0, total_amount − paid_amount)`, **tidak** `orders.remaining_amount` (kolom itu 0 untuk order non-DP yang belum diverifikasi).
  - Laporan ini **tidak mengubah** aturan bisnis pembayaran/refund apa pun; ia hanya membaca state yang sah.
- Kedua laporan **tidak memiliki kolom "Posisi Uang"** (Owner menutup OQ-8 dengan menghapus kolom tersebut dari kontrak).
- Isolasi data per Agen dipertahankan: scoping memakai `agent_id` pada order/snapshot, bukan `users.agent_id` pihak lain.
- Periode filter laporan (`harian`/`mingguan`/`bulanan`/`kustom`) mengikuti **kalender bisnis Asia/Jakarta (WIB)**: minggu mulai Senin, rentang kustom menutup seluruh hari WIB terakhir. Database tetap menyimpan waktu UTC.
- Database tetap sumber kebenaran; Google Sheets hanya tujuan ekspor satu arah.
- Batas 10.000 baris per sinkronisasi. Melebihi batas ⇒ **sinkronisasi gagal total** dengan pesan agar pengguna mempersempit periode; tidak ada pemotongan senyap.


### 20b. SimpleV2 reporting & Google Sheets format parity (**OD-06 approved — NOT IMPLEMENTED**, Owner 2026-10-28)

- Scope: all relevant SimpleV2 report and Google Sheets presentation formats are approved for adaptation **within role and Agent scope**. SimpleV2 is presentation/operational-format authority only; PrimeClassy remains authoritative for pricing, payment, stock, commission, refund and tenant security (§13, §15, §20, §20a, §26; `AGENTS.md` §11–12).
- Source-level baselines (verified in the SimpleV2 bare repo at HEAD `f733911…`): the 43-column transaction Excel export and the 47-column `A:AU` Sheets payload written to the `COOKIES_2026`/`CAKE_2026` worksheets. Actual workbook headers, formulas, freeze rows and formatting are **not** verifiable from source alone → `EXTERNAL_EVIDENCE_REQUIRED` (real exported workbook/Sheets sample) before any visual-parity claim.
- PrimeClassy dataset baseline (verified, 17 keys): `products`, `stock`, `transactions`, `transaction_items`, `order_initial_transactions`, `order_payment_positions`, `sales`, `korsal`, `courier_deliveries`, `sales_fees`, `courier_fees`, `korsal_fees`, `payment_status`, `refunds`, `additional_payments`, `transaction_report`, `financial_summary` (`backend/app/Services/GoogleSheets/DatasetRegistry.php`).
- Any future format implementation must first produce a per-column inventory: original ordinal/header, worksheet, meaning, type/format, formula-vs-manual-vs-system origin, PrimeClassy source field, role/Agent scope, null semantics, parity verdict, `file:line` evidence.
- Format parity gates: structural **and** functional **and** visual. Source inspection alone never yields a parity PASS.
- Manually maintained columns must not be overwritten by the one-way export. SimpleV2's DP/Cicilan/Pelunasan/position columns were maintained by humans directly in the sheet, while PrimeClassy's current sync model is full-tab replace. The proposed SYSTEM / protected MANUAL (keyed by stable business IDs) / PRESENTATION separation is a **proposal only** — technical validation and Owner sign-off are required before implementation.
- Canonical outstanding: the former open inconsistency (Laporan A/B derived rule vs datasets `financial_summary`/`payment_status` aggregating `orders.remaining_amount`) is **decided** — Owner decisions OD-K5-1…OD-K5-5 (2026-10-28) establish the canonical collectible-AR contract in §20c. Unification of the report consumers remains **APPROVED_DESIGN / NOT IMPLEMENTED** and is gated by the reconciliation-first rollout (OD-K5-4; ledger `G-15`).
- "Posisi Uang": the Owner already closed OQ-8 by removing the column from the Laporan A/B contract (§20a). The consolidation package still lists money-position semantics as unresolved; §20a (LOCKED, Owner 2026-10-21) takes precedence. Reopening it is an explicit Owner decision, not implied by OD-06.
- Reports, exports and Sheets datasets must never silently drop CRM-origin orders through inner joins to `users` (see §27 and `docs/ARCHITECTURE.md` §16).

### 20c. Canonical collectible receivable contract (**OD-K5-1…OD-K5-5 approved — IMPLEMENTED in source 2026-10-29 (DEV); WS-7**)

**Implementation (Owner directive 2026-10-29, WS-7; no IMP/REM number).** `App\Support\CollectibleReceivable` is the single derivation: 0 for a cancelled order, otherwise `GREATEST(total_amount − COALESCE(paid_amount,0), 0)` (M-2). It replaces the stored `remaining_amount` in `financeOrders` (`total_outstanding`, `total_dp_outstanding`), `networkSummary.outstanding_amount`, the per-Agent outstanding, dataset D17 `financial_summary.outstanding_amount` and dataset D13 `payment_status.remaining_amount` (SAME column headers — only the value is now correct). The stored column, the payment lifecycle that writes it, historical snapshots, the refund ledger and every other report value are untouched (OD-K5-5: no backfill). `primeclassy:ar-reconciliation-report` is the read-only OD-K5-4 evidence (stored vs canonical by Agent / payment status / order status, plus overpayment). Keuangan sign-off remains a recommended gate before any PRODUCTION rollout (nothing here touches production).

**Three distinct financial metrics (preserve; never conflate):**

| Metric | Definition | Authority |
|---|---|---|
| **M-1 — stored balance** | `orders.remaining_amount` — payment lifecycle state, written only by the existing payment service logic (§13; single money-event writer) and the DP creation branch | Valid as **API/lifecycle state**; explicitly **not** authoritative as the sole source of collectible-AR reporting |
| **M-2 — derived running balance** | `GREATEST(total_amount − COALESCE(paid_amount, 0), 0)` evaluated live per order | The **basis** of canonical collectible AR, subject only to the eligibility rules below (OD-K5-1) |
| **M-3 — immutable initial snapshot** | `order_initial_report_items.initial_remaining_snapshot` (Laporan A; §20a) | Historical checkout position; **must never be recalculated** and must never be presented as current AR |

**OD-K5-1 — Canonical collectible AR.** The running accounts-receivable metric means amounts still legally and
operationally collectible. Basis M-2 with order-status eligibility: **cancelled orders never contribute to
collectible AR**, while remaining fully auditable through separate reporting categories (cancellations/refunds
report). Excluding cancelled orders from AR **does not extinguish** their outstanding refund/settlement
obligations — those liabilities keep being reported through their own existing surfaces (§26 ledger).

**OD-K5-2 — Pending verification is not payment.** A payment awaiting verification is not a verified paid amount;
for otherwise-eligible orders the balance **remains inside collectible AR** until authorized financial personnel
approve it (`super_admin`/`keuangan` unchanged — §13/§24; this decision does not alter payment authorization,
verification roles, or payment-event logic). Pending-verification visibility stays a **separate reporting
status/dimension**, never merged into "paid".

**OD-K5-3 — Overpayment liability is its own category.** `overpayment_liability = GREATEST(paid_amount − total_amount, 0)`
— a **proposed read-model definition** for documented design, subject to reconciliation against existing refund
obligations and actual settlement records before any reporting use. A gross overpayment must **not** be
automatically equated with currently refundable cash where refunds have already been executed (the refund ledger,
§26, is the truth of money that already left). Overpayment liabilities are kept **separate from AR**, are never
silently netted against receivables, and never alter the refund ledger, payment ledger, or historical snapshots.

**OD-K5-4 — Reconciliation before any rollout.** Before any future reporting change: (1) inventory affected report
consumers; (2) compare current vs proposed values; (3) attribute every difference by Agent, order, payment status
and order status; (4) separate confirmed financial defects (KF-1/KF-2/KF-3, `G-15`) from approved changes in
reporting semantics; (5) obtain authorized **Keuangan review and sign-off**; (6) obtain **Owner approval** for
rollout and any presentation-contract change (report columns, Sheets datasets/headers); (7) preserve audit
evidence and a rollback strategy. Affected consumers on record: `financeOrders`, `networkSummary`, per-Agent
report, datasets `payment_status` (D13) and `financial_summary` (D17), and any additional readers discovered
during step 1. *Superseded for DEV by the Owner's explicit WS-7 instruction of 2026-10-29 (above): the derivation is applied in source with unchanged column headers; the read-only reconciliation report is the evidence for steps 2–3, and Keuangan review (step 5) plus Owner rollout approval (step 6) are still required before any production deployment.*

**OD-K5-5 — No historical backfill.** Stored `orders.remaining_amount` is **not** backfilled. Canonical reporting
uses derived read-model computation (M-2). Existing stored-field usage (API, settlement guards, payment-status
workflows) is preserved unless separately authorized. Any later data-correction proposal requires a **new
explicit Owner decision**.

**Reporting semantics preserved:** §20a stays fully in force — Laporan A remains the immutable initial-position
report ("Sisa" at commit), Laporan B remains the live running-receivable report whose derived rule
`max(0, total − paid)` §20a already locks is exactly the M-2 basis; OD-K5 extends that same derivation basis to
the other AR consumers after OD-K5-4 reconciliation. The Google Sheets presentation contract (43/47-column
SimpleV2 reference, dataset headers/labels) is **unchanged pending separate approval** per §20b.

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
- A proof is refused (422) when the above conditions hold. External gateway methods were removed (Owner decision); manual transfer is the only proof-based method.

## 24a. Stock pages and order filters (change request)

- `GET /stock/products|variations` list every target the viewed Agent holds in legacy OR warehouse rows with ADA/DITAHAN/TERSEDIA from `SellableStockService` — the same truth as catalog, storefront and checkout. `StockService::reserveFor*` provisions a ZERO-quantity commitment row for a warehouse-only target so such a target is orderable; availability is still decided canonically (nothing invented).
- `GET /orders` accepts `status`, `payment_status` (canonical settlement states: unpaid, pending_verification, partially_paid, paid, failed, refunds), `search`, and the Dispatch-equivalent `delivery_date`, `province_id…village_id`, `paid` (one shared `OrderFilterService`); `GET /orders/regions` gives cascading options from the caller's own scoped orders. Filters only narrow the role scope (scope is a nested group); operational roles cannot use payment filters.

## 25. Observations to confirm (not changed by documentation)

- **Self-purchase sales fee for Sales-Kurir-Sub (resolved, Owner decision):** a Sales-Kurir-Sub buying for itself earns the sales fee itself, like Sales. Its agent and courier fees remain separate ledgers.
- **Legacy stock fallback:** warehouse-authoritative mode is the default, but a target with no warehouse rows still falls back to legacy on-hand (§8).

## 26. Refund ledger invariants (**LOCKED**, Owner mandate 2026-10-22)

- One immutable financial snapshot per Order. After the first financial event, quantity/add-line
  changes are refused unless the snapshot trigger is `fulfillment_shortfall` and the Order is still
  `diproses` (preserved Package A/B sequential-shortfall behaviour); SC-03 add-line is always refused.
- `paid_amount` keeps exactly one writer (`PaymentService`). Ledger payouts reverse through it; legacy
  historical reversals are recorded as `legacy_unverified` and **never** reverse `paid_amount` twice.
- Refund money leaves only through a succeeded attempt and its payout; obligations/attempts/payouts
  are never deleted, and duplicates are idempotent no-ops.
- Reports' refund cash flow is R_c (proven and affecting). Unverified legacy refunds are R_u and are
  displayed separately; the cancellation obligation `min(P, C)` is independent of R_c.
- Zero-amount deferred containers carry no cash and cannot receive an attempt; materialization and
  reinstatement are Owner-authorized, evidence-backed and capped.
- The backfill is insert-or-skip by stable `legacy:{source_type}:{source_id}` keys; it never invents a
  deferral, never auto-refunds a legacy return (stays `needs_verification`), and apply is test-DB only.

## 27. CRM Customer — ownership, linking and CRM-origin assistance (**OD-01…OD-06 LOCKED**, Owner 2026-10-28; **approved — NOT IMPLEMENTED**)

Status (2026-10-30): IMP-006 now also has Assisted Order (G17), CRM↔Konsumen linking, CRM-origin consent and consent-gated assisted returns, refund-recipient evidence and FU/D alignment — implemented in source with scoped tests; DEV migration `2026_10_30_*` pending Owner approval; ownership reassignment/transfer, export and Agent-transfer remain NOT implemented. Earlier status (2026-10-29): slice 1 of IMP-006 (CRM Customer, visits, follow-ups; see `docs/PHASE2-CHECKPOINT.md`) is implemented and scoped-tested in source, not yet migrated on DEV; assisted order, linking, CRM-origin returns and FU/D remain NOT implemented. Original statement: every rule in this section is a business rule for a future implementation package. No CRM code,
migration, route or screen exists in this repository (2026-10-28 source audits, both this repo and the
SimpleV2 bare repo; recorded as `IMP-006 BLOCKED` in `docs/SALES-PARITY-CHECKPOINT.md`). This section is
the approved scope basis; it is **not** implementation authorization.

- A CRM Customer is a business contact/prospect — **never** a fabricated `User`, and never a public
  guest-checkout identity. D-06/D-07 (no public guest checkout; assisted orders only by authorized
  sellers) continue to apply unchanged.
- A CRM Customer belongs to exactly one Agent and one owning Sales or Sales-Kurir-Sub. Sales/SKS may
  create, view and update their owned CRM records, subject to policy, with immutable audit for sensitive
  changes. Korsal receives read/monitoring scope over their authorized downline only.
- **OD-01** — Agen has branch-level CRM **read**; Admin has operational branch CRM **read**. Read is
  distinct from edit/reassign/export and from any financial verification; neither branch read grants
  ownership reassignment, export, or payment/delivery verification authority.
- Stage vocabulary (prospect percentages `0/25/50/75/90/100`; visit status `N,1…7` as verified in the
  SimpleV2 source) is preserved only from verified evidence. The meanings of `FU` and `D` are
  `SEMANTICS_NOT_VERIFIED` — do not implement, interpret or define them before an explicit Owner
  decision (open item; proposed ID `OD-07`).
- Assisted order may originate from an owned CRM Customer without a login (approved direction;
  architectural contract in `docs/ARCHITECTURE.md` §16). The order service remains authoritative for
  price, stock, idempotency, financial snapshot and audit exactly as for account-origin orders.
- **OD-02** — for CRM-only Customers, the owning Sales/SKS may **initiate** a return request with
  verifiable Customer-consent evidence and actor evidence. Initiation only: return approval, pickup,
  inspection, finalization and refund settlement keep their existing authorities unchanged (§14, §26;
  money authority stays `super_admin`/`keuangan`).
- **OD-03** — CRM↔Konsumen account linking requires authenticated account-control proof **plus** OTP to
  an already-verified channel **plus** audit, with rate limiting, expiry, replay protection and
  uniqueness/conflict checks. A link is never inferred from shared name, phone or email alone. No
  existing OTP/provider mechanism is assumed available; channel selection is an implementation-package
  decision on top of this rule.
- **OD-04** — an authorized actor may revoke a link, audited. Revocation changes future authorization
  only; historical orders, payments, commissions, identity snapshots and attribution are never rewritten
  (§13, §15; consistent with `ReferralReassignmentService` non-rewrite behavior). The exact authorized
  actor list and evidence-retention design remain open Owner items.
- **OD-05** — original state (2026-10-21 pack): the refund commission-adjustment formula was **not approved**;
  drafting design options only; no automatic reversal, deduction or posting. **Superseded as of 2026-10-28
  (dated):** the Owner approved the policy FRAMEWORK OD-05a…OD-05g — now canonical in §15a. The prohibition
  core remains in force: no automatic reversal/deduction/posting exists or is implemented, individual exception
  entries remain PENDING_OWNER_APPROVAL, and implementation still requires a separately Owner-authorized
  package. §26 keeps `commission_adjustments` a table with no generation logic.
- **OD-06** — reporting/Sheets format parity: see §20b.
- Workflow preservation: all 29 existing workflow groups keep their current authorities — registration/
  referral, hierarchy, catalog/promo, checkout, order/status/fulfillment/SC-03 (Admin-only), Gudang
  proposal→Admin approval, transfers/opname, Sub ownership/replenishment, dispatch/delivery/verification
  (Admin/Super Admin), returns/refunds/additional payments, commission, reports/Sheets, CMS, Agent
  configuration, SKS parity (Sales rights + Sub stock/delivery + self-purchase), immutable snapshots and
  Agent isolation. Exactly 11 roles (§2), no new permissions by UI redesign, frontend visibility never
  grants backend authority, payment verification stays `super_admin`/`keuangan` (§13, §24), and only
  manual bank transfer + COD remain (no external gateways).
- Known defect, recorded not fixed: AU-01 — the required HTTP 503 `registration_unavailable` response
  when no active Agen exists conflicts with current source (`AuthController::register` throws a raw
  `RuntimeException` → HTTP 500; `GoogleAuthService` does return `registration_unavailable`). Tracked as
  `G-07` in `docs/PRIMECLASSY-GAP-DEPENDENCY-LEDGER.md` (REM-004 candidate).

> **FU/D — DECIDED by the Owner 2026-10-30: `FU` = Follow Up, `D` = Deal (alias of visit status `7`; follow-up uses the existing `plan_next_date` mechanism). Evidence below (2026-10-29) is kept for traceability: no SimpleV2 source defined them.** SimpleV2 sweep (`git --git-dir=simpelv2.git`, HEAD `f733911`): the enum `FU,N,D,1…7` exists (`create_kunjungans_table.php:20`); the UI (`visits/IndexSales.vue`) labels `1…7` as BS/MS/IP/IH/MK/MKK/Deal and renders `N`, `D`, `FU` with the bare letters only (icons: FU = clock, D = check, N = dot). **No README, analysis doc, seeder, service or comment defines FU or D**, nor expands the 1…7 abbreviations. Icons are a UI hint, not a definition. Recommendation (needs Owner decision, not applied): keep `FU`/`D` out of `crm_visits.status`; express "follow-up" through `plan_next_date` (already implemented) and let the Owner state whether `D` means *done/deal-closed*, which would duplicate `7 = Deal`.

> **Owner decisions 2026-10-31 (LOCKED; implemented in source, DEV-migrated, browser-verified).**
> 1. **Assisted Order (D3):** only the owning Sales/SKS, the owner's Korsal (existing `korsal_id` authority) and the branch Agen; all checks server-side; no RBAC widening; no cross-branch access; no takeover of a konsumen identity.
> 2. **Consent for a CRM customer without an account:** photo + note are **never** sufficient identity verification. Consent requires an OTP on a **verified customer channel** — the verified email of the konsumen account the CRM customer is linked to. If no such channel exists the action is **blocked**; there is no automatic bypass, and a manual verification path may only be enabled after the Owner ratifies its procedure, evidence and authorization. Photo/screenshot, note and the item/quantity scope remain required additional evidence.
> 3. **Unlink:** allowed to the owning seller, the authorized Agen and the Admin within branch scope; reason, authorization and audit trail are mandatory; it never alters historical orders, payments, refunds, commissions or snapshots.
> Open Owner interpretation: whether refund-**payee** authorization (G17-D4) may rest on an uploaded document instead of a consent record (it is payee authorization, not consent identity verification).

### 27a. Customer consent, identity linking and FU/D (**CI-01…CI-07 approved — NOT_IMPLEMENTED**, Owner 2026-10-28)

Design contract only; basis PC-SV2-FUD-CONSENT-LINKING-ANALYSIS-001. No code, schema, endpoint, OTP
delivery, account link, or return/refund action exists or is authorized by this section. Statuses:
approved design = APPROVED_DESIGN; technical details/provider/retention = unresolved (marked inline).
Existing OD-01…OD-06, §27, §14/§26 and §20c remain unchanged; FU/D remains **OPEN**.

**CRM-only Customer vs authenticated Konsumen (identity contract).** An existing Konsumen is an
authenticated `users` row with role `konsumen` (Agent-scoped), and the existing buyer reference for orders.
A future CRM-only Customer is a business/CRM record **without mandatory login**, Agent-scoped, recorded by
authorized Sales/SKS, and **never a fabricated User**. It must not inherit historical order ownership
automatically. Existing `orders.konsumen_id` semantics, buyer/recipient snapshots, sales/korsal attribution
snapshots, financial/commission history and Agent isolation are preserved. CRM-only assisted checkout does
**not** currently work and a nullable buyer FK is **not** authorized here (buyer-origin contract in
`docs/ARCHITECTURE.md` §16).

- **CI-01 — Verified email + one-time OTP (APPROVED_DESIGN / NOT_IMPLEMENTED).** Linking a CRM Customer to an
  authenticated Konsumen requires proof of control of the target account **plus a verified email channel**
  plus a **single-use OTP challenge** with expiration, attempt limits/rate limiting, replay protection,
  challenge binding to the intended customer/account/action, and immutable audit evidence. **Matching email or
  phone alone never authorizes linking.** Email-verification and OTP infrastructure do not exist today
  (`otp`/verification-code sweeps = 0). No email service, OTP vendor or delivery provider is selected by this
  document — that remains a technical dependency awaiting Owner choice.
- **CI-02 — Digital consent evidence (APPROVED_DESIGN / NOT_IMPLEMENTED).** Assisted return initiation requires
  digital consent evidence bound to: the actual Customer · the relevant order and return request · the specific
  item/quantity or authorized scope · consent purpose · evidence media · capturing actor · timestamp · Agent
  ownership · audit provenance — with integrity/reference retention and replay protection. A checkbox,
  matching contact details, or a verbal assertion alone is **never** proof of authorization. Accepted media
  and the identity-verification mechanism remain **technical-design dependencies** (not invented here).
- **CI-03 — Sales/SKS consent capture (APPROVED_DESIGN / NOT_IMPLEMENTED).** Sales and Sales-Kurir-Sub may
  capture consent only within their authorized Agent/Customer/referral/order scope; the **Customer remains the
  consent giver**; assisted return initiation is permitted **only after** valid consent evidence exists. This
  grants **no** review approval, **no** warehouse inspection, **no** refund approval or execution, and **no**
  commission-adjustment approval (OD-02 boundaries preserved).
- **CI-04 — Withdrawal and dispute (APPROVED_DESIGN / NOT_IMPLEMENTED).** Withdrawal/dispute uses auditable
  **append-only** events; affected requests have new consent-dependent actions suspended while prior events and
  evidence are preserved; actor/reason/timestamp/scope/provenance recorded; resuming requires authorized
  review. Already-completed historical financial transactions are **never silently reversed** — prospective
  suspension only; no automatic refund reversal behaviour is invented.
- **CI-05 — Cross-Agent linking (APPROVED_DESIGN / NOT_IMPLEMENTED).** CRM Customer ↔ Konsumen linking across
  **different Agents is rejected**. Legitimate Agent transfer requires a separately authorized process; no
  automatic cross-Agent reassignment or merge exists. Tenant/Agent isolation stays enforced in every query and
  authorization gate.
- **CI-06 — Consent retention (DECIDED 2026-10-30, G-21; implemented read-only in RetentionPlanner).**
  Final policy: consent evidence is retained **while the customer relationship is active + 5 years after the
  relationship ends** (`RetentionPlanner::CONSENT_AFTER_RELATIONSHIP_YEARS`). A **legal hold
  (`retention.legal_hold`) suspends expiry**, and a **longer statutory/legal obligation always wins** — the
  5-year window is a floor, never a delete trigger. The only modelled relationship-end today is
  `crm_customers.archived_at` (CRM-origin evidence); account-bound evidence has **no modelled end date**
  (`users.status` carries no end timestamp) — recorded as a data dependency, no date is invented, and those rows
  are never period-computed until the column is Owner-approved. Implementation is **report-only**
  (`RetentionPlanner::consentEvidenceReport()`): no deletion mechanism exists, nothing is scheduled, and
  `consent_events` refuses UPDATE/DELETE at the model boundary. Any removal needs a separate Owner-approved
  mechanism. Scoped tests: `ConsentEvidenceRetentionTest` (period boundary, legal hold, data dependency,
  never-deletable evidence, nothing scheduled).
- **CI-07 — FU/D semantics (FU_D_DEFINITION_UNVERIFIED / OPEN).** SimpleV2 visit-status enum contains
  `FU, N, D, 1…7`; the original meaning of `FU`/`D` is not source-verified and the Owner explicitly keeps it
  unresolved. Do **not** invent meanings, rename statuses, remove `FU`/`D`, or restrict the future vocabulary
  to `N`/`1…7` without separate Owner approval. The dependency stays open for future visit/funnel adaptation.

**Authority separation preserved (source-confirmed today, unchanged by this section):** customer-origin return
initiation = `konsumen` only (route `role:konsumen` + ownership double-check); assisted initiation = future
design only (CI-02/CI-03); review = `super_admin/agen/admin`; warehouse inspection = `gudang`; finalization =
`super_admin/agen/admin`; refund obligation approval and refund cash execution = `keuangan`/`super_admin` under
the §26 ledger; commission adjustment = none (OD-05 framework only, §15a). Any separation-of-duties refinement is
a **future gate**, never a silent rewrite of current behaviour.


## 28. WhatsApp communication (**OWNER-DIRECTED 2026-10-31; implemented in source; DEV delivery log migrated 2026-10-29; real E2E not run**)

- **Purposes are separate.** `transaction` (order/payment/return/refund facts), `otp` (verification codes) and `test` (Owner-authorised numbers). **Promotion/marketing is out of scope** and may never reuse a recipient or consent obtained for transactional messages.
- **Who may be messaged.** Only the ACCOUNT buyer of the order, at the phone on that account. A CRM customer is **not** messaged (the number is typed by Sales and unverified) until a proven channel/consent exists; the event is recorded as skipped, not silently dropped.
- **Verified number is required.** A transactional notification goes ONLY to the order's account buyer at a number that holds a valid ownership proof (a WhatsApp code the holder entered correctly, bound to that exact number). Delivery success is NOT verification; an unverified or mistyped number is skipped (`number_not_verified`) and never messaged. Changing the account phone voids the proof; reverting to a previous number needs a new code. The code is never stored in clear, logged, returned by an API, shown in a screenshot or audited; failures never reveal whether an account or number exists.
- **Number verification is not consent and not CRM linking.** It proves control of a channel only. It creates no consent, never links a CRM customer to an account because the number matches, and changes no ownership, attribution, order, commission or referral. Consent for order/return actions still requires its own separate challenge on the verified account email (above).
- **Defaults.** Gateway off, every event off, OTP-by-WhatsApp off. Only a Super Admin can switch anything on; every change is audited.
- **Facts only.** A message states real order data. A courier or tracking number that does not exist yet is omitted, never invented. There is no "dikemas" notification because that status does not exist.
- **Business first.** A gateway outage, a template error or a rolled-back transaction must never fail or alter an order/return/refund operation.
- **No silent duplicates.** One business event = one message (idempotency key). An uncertain delivery result is never retried automatically; an operator must accept the duplicate risk explicitly. OTP messages are never re-sent.
- **OTP channel for consent and CRM linking (Owner 2026-10-29).** WhatsApp is an ALTERNATIVE to the verified account email, allowed only when (a) the konsumen ACCOUNT has proven its number through a `whatsapp_number` challenge, (b) that number is still the account's current number with a valid proof, and (c) the code is a NEW challenge bound to its purpose, the account, the order / CRM customer and the acting user. The number-verification code can never be reused as a consent/link code. Changing the number voids the proof and any WhatsApp code still in flight. A CRM customer's own number is never treated as the customer's; a CRM customer without a linked account gets no WhatsApp consent path; nothing is linked or consented automatically. Email OTP remains available as the safe fallback. Delivery of a WhatsApp message is never itself a verification, and if a code cannot be delivered the action stays blocked. No manual bypass.
- **Volume and retry.** Conservative per-recipient (6/hour) and gateway-wide (20/minute) caps, tunable only by a Super Admin within hard bounds; throttled notifications wait (bounded) rather than burst; an uncertain (`unknown`) outcome is never retried automatically; OTP is never retried; every throttle is audited. Notifications are re-checked against the recipient's number proof at send time.
- **Retention policy (contract; NOT yet executed).** `whatsapp_messages`: 90 days operational (minimise, keep the row). Expired/unused/voided OTP challenges: 30 days. Consent evidence (G-21 final policy 2026-10-30): retained while the customer relationship is active + 5 years after the relationship ends (floor; longer statutory obligations win; legal hold suspends expiry; report-only — no deletion mechanism exists, and account-bound rows have no modelled end date yet). Legal hold suspends everything. Any execution needs Owner approval, a verified backup and a re-read of the dry-run numbers.

## Korsal / Agen commission rules (Owner decision 2026-10-30, Phase 2 KPI contract)
- There is **no Korsal-role fee**: ledger roles are `agent`, `sales`, `courier` only. A Korsal earns the Sales fee only when it is itself the Sales beneficiary (own sale / direct referral); a downline sale never creates a Korsal row.
- A Korsal has every functional Sales permission within its OWN scope plus team read/coordination; it does **not** inherit SKS-only stock (`/sub-stock/*`) or courier surfaces.
- "Komisi Cabang" (Agen dashboard) = the Agen's own fee (`beneficiary_user_id` = the Agen), never the branch pool. "Komisi Tim" (Korsal) = per-recipient Sales-fee breakdown. Transaction counts/values come from attributed orders; commissions come only from the ledger; `GET /dashboard/kpis` is the contract (see docs/PHASE2-REPORT-PARITY-AND-KPI-MATRIX.md §B2).
