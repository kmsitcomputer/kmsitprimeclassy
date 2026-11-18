# RECON CLOSURE — IMPORT & BESTSELLER

Closure inspection: 2026-11-11T21:40:57+07:00 (Asia/Jakarta). Repository `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`; branch `fix/transaction-reset-financial-dependencies`; HEAD `6a14041c06e6258c9f011fcb11271c7d34d65d17`. Branch/HEAD unchanged from prior RECON.

**RECON CLOSURE ONLY. NO IMPLEMENTATION.** New Owner directive supersedes conflicting assumptions in RECON-01…07. All schema/API/action names in proposals are **PROPOSED**, not implemented or architecture-approved. Source evidence is not runtime PASS.

[01 OWNER-DECISIONS](RECON-CLOSURE-OWNER-DECISIONS.md) · [02 HISTORICAL-POS](RECON-CLOSURE-HISTORICAL-POS.md) · [03 IMPORT-BESTSELLER](RECON-CLOSURE-IMPORT-BESTSELLER.md) · [04 TRACEABILITY-MATRIX](RECON-CLOSURE-TRACEABILITY-MATRIX.md) · [05 ARCHITECTURE-READINESS](RECON-CLOSURE-ARCHITECTURE-READINESS.md) · [06 OWNER-REVIEW](RECON-CLOSURE-OWNER-REVIEW.md)

## REM-01 — locked policy and current support

**OWNER LOCK:** ODR-03 B, import=target, duplicate=update, atomic=rows. Stock cell means final counted physical quantity at explicit Agent/bucket/location, never delta or sellable quantity. Existing SKU updates its registry owner; new SKU uses existing validation. Inventory mutation must use existing authorized adjustment/approval; never write balance/reservation directly from spreadsheet.

**VERIFIED:** catalog_skus PRIMARY(sku), UNIQUE(owner_type,owner_id); HasGlobalSku transaction and GlobalSku owner guard (CL-E08/09, CL-DB02/03). Local registry3, product/variant matching-registry mismatches0. Variant owner_type=variant + owner_id identifies product_variations.id and its existing parent. A variation product parent has no simple SKU. Soft-deleted/retired registry owner must not be bypassed to create a new product or silently revive a historical identity.

**PARTIAL:** no catalogue CSV/XLSX import/export/preview/apply exists (prior EV-01-001/003). PhpSpreadsheet dependency does not supply an import workflow, permissions or idempotency. Existing opname supports physical target counts, but not all locked import semantics.

## Existing opname flow — verified source, not executed

POST /warehouse/opnames → StockOpnameController::store → StockOpnamePolicy create Gudang → StockOpnameService::create physical_opname with source bucket/location → system_quantity snapshot → count(counted_quantity) → submit → Admin approve → document+stock locking read → current quantity compared to snapshot → delta=counted−current → target warehouse quantity=counted plus StockMovement opname_adjustment linked opname → approved.

Evidence CL-E03…07/34; backend/routes/api_v1.php:650–663. Changed current quantity rejects409/recount. Same approved document replay returns existing; this does not stop a retry creating another document with a random opname_number. CL-DB03 finds no durable import-row key. Approval transaction spans all items in that document: treating one file as one document is whole-document atomic, not independently approved rows. PROPOSED alternative: one single-target opname per independent row, with durable row-source identity; review approval-volume cost. Do not silently alter existing multi-item approval semantics.

## Field/validation and per-row contract — PROPOSED

| Concern | Existing evidence | Proposed bounded approach | Gap / status |
|---|---|---|---|
| Global SKU upsert | CL-E08/09; CL-DB02/03 | Resolve registry before update, preserve owner type/parent; no SKU theft or historic owner remap; new variant needs validated parent | VERIFIED registry; adapter absent |
| Duplicate SKU inside file | CL-E03:32–40 rejects duplicated opname targets | duplicate=update means existing DB owner. PROPOSED reject all repeated normalized SKU+same stock-context groups with row errors, including conflicting price/target; no hidden last-row-wins or double adjustments | PROPOSED policy for Architecture Review, not reopening DB upsert lock |
| Category ID vs path | products.category_id; product_categories.parent_id, prior EV-06-001 | Canonical category_id for same install; optional fully-qualified path for portable files must resolve uniquely; supplied ID/path must agree; no inferred hierarchy creation | PROPOSED; REM-06 dependency |
| Stock context | CL-E04 physical bucket transit/shipping/sub, location required only Sub | One explicitly validated Agent/bucket/location context per import, or fully validated row context; no sum Agent+Sub, no Factory Plan as physical count; Sub owner/permission checked | PARTIAL; D-07 authority/handoff |
| Catalog attributes | Prior RECON-03 product/variant fields and validators | Typed values; omitted preserves, empty differs zero; preserve unchanged fee/SKU/weight; external image URL import not assumed | PROPOSED |
| Price valid, stock invalid (or inverse) | Catalogue save and opname transactions separate | Validate entire row/source before any immediate write; immediate failure leaves whole row unchanged while other valid rows proceed | PROPOSED; pending approval needs next rule |
| atomic=rows with deferred approval | CL-E03 and CL-E05 separate creators/approvers | Proposed row acceptance atomically links catalogue outcome+authorized stock intent, later approval applies stock atomically; return catalog_saved/stock_pending/stock_applied/conflict distinctly. Alternative defer catalogue publication until inventory approval | PARTIAL D-07; never label stock pending as applied or claim entire row final success |
| Reservations | CL-E03:122–124 checks Sub floor; :126–134 protects plan commitments | Never modify reserved to fit target. Agent physical Transit opname can reflect actual shortage below commitments: no blanket floor in this physical branch; accepting that imported target versus blocking requires D-07 | PARTIAL; not a guarantee all reserve conflicts blocked |
| Concurrent change awaiting approval | CL-E03:100–118 stock lock + current==system_quantity | Preserve existing409/recount, never silently rebase an absolute target. Equality is not a full stock-revision/ABA detector; use proposed revision/evidence link if needed | VERIFIED stale rejection; extra freshness guarantee PROPOSED |
| Retry per row | Same-document approved replay only; random document identifier; CL-DB03 | Durable source/row key and canonical fingerprint on existing opname-related table; unique key prevents second document; same key/payload returns outcome, changed payload409; correlate file/row/preview/context | PROPOSED additive index/columns, no new import/POS ledger table |
| File/volume limits | PhpSpreadsheet installed; existing image5MiB is not spreadsheet limit | Explicit compressed/uncompressed bytes, archive expansion, row/column/cell count, time/memory caps; disable formulas/external links/macros; safe CSV encoding, neutralize export formula injection; exact thresholds require representative evidence | PARTIAL sizing evidence; no arbitrary supported limit claimed |
| Export | Schema mapping exists, product exporter absent | Role-gated CSV/XLSX with explicit stock source and as-of time; no summed Agent/Sub or unauthorized fees; versioned template | PROPOSED |
| Audit/result | StockMovement opname link, ActivityLogger | Actual creator/Gudang/approver actors; current audit timestamps, per-row validation codes and stock application state; no SQL/secrets in errors | PROPOSED |

### Authority mismatch and design alternatives

Catalog actors super_admin/agen/admin differ opname creator/count/submit Gudang and approver Admin (CL-E05). Owner approval of inventory reuse does not authorize impersonating Gudang or broadening every inventory action.

PROPOSED A: catalogue-authorized actor previews/updates through agreed row contract; Gudang explicitly adopts validated physical count targets using its normal workflow; Admin approves; durable typed source link returns stock outcome to row. PROPOSED B: bounded import-stock-intent permission for catalogue actors while count/admission/approval remain inventory roles; this needs explicit authority approval. D-07 must settle actor handoff and catalogue publication while approval pending. Existing SuperAdmin stock-write restrictions remain.

Already resolved, do not re-ask: physical target not delta; DB duplicate=update; atomic rows; existing stale changed quantity→409/recount, not auto rebase; Admin approves stock operations; Sub physical cannot fall below active Sub reservations; no arbitrary reservation releases. Unresolved: durable row idempotency, two-stage row outcome, safe creator handoff, Agent Transit shortage against current commitments, and revision/freshness evidence. No import applied.

## REM-07 — OWNER LOCK verified/delivery/Agent

Fully paid with verified payments only, actual eligible delivery/receipt event period, Agent-scoped orders.agent_id ownership, legitimate return reduces once, variant summed into parent, active saleable products only, hide empty section. COD unpaid/unverified excluded; partial/DP not fully paid excluded. Referral/requester does not choose stock or sales Agent scope. Public feed must resolve a valid storefront/referral Agent server-side; unresolved Agent hides section rather than publishing global sales. SuperAdmin explicitly selects valid Agent. Feed never exposes customer/receipt PII.

### Event evidence: item receipt differs shipment completion

CL-E10/11: item transition to terkirim is arrival-domain evidence, but shipment.delivered_at is an aggregate timestamp stamped now only when all items arrive. Earlier items have no dedicated receipt date column (CL-E12, CL-DB03). Per-item action logs order_item.status_changed; bulk action logs shipment.status_changed. activity_logs.created_at is recording time and not a uniform actual per-item received_at, especially for newly recorded old facts.

handed_over_at indicates handover/outgoing custody, requested_delivery_date is planned, delivery verification.verified_at is a verifier action: none may be substituted for actual recipient receipt. Using shipment.delivered_at delays early items into final sibling arrival period and may omit partially delivered shipments. Requiring latest DeliveryVerification outcome received is an additional business gate, not assumed from courier terkirim alone (CL-E13).

D-06 therefore remains a narrow real decision: event/grain and late-payment/refund restatement. A typed nullable per-item receipt fact/date can be proposed on existing item table. No reconstructed logs/timestamps presented as historical fact; unknown legacy event stays unknown or explicitly ineligible until evidence admitted.

### Verified payment, return/refund and period edge cases

| Case | Locked outcome | Evidence / remaining interpretation |
|---|---|---|
| Delivered COD but unpaid/unverified | Exclude | CL-E15/33: shipment does not pay order |
| DP/partial not fully paid | Exclude until full verified balance | Canonical PaymentSummaryService; requested/submitted DP not paid |
| Verified transfer | Require canonical full balance and authorized successful receipt verification | CL-E14: verified_amount/by/at, transaction paid; preaggregate receipts, never fanout units |
| COD legacy finance mark | No UI-paid shortcut | markCod authorized/audited may not create cod_payment_proof confirmed; D-02/06 specifies accepted verification provenance. Absence of a single proof-table row is not automatically fraud |
| Payment completes after delivery | Cannot enter ranking before verification; period remains delivery-based | Proposed live cohort admits sale under original actual delivery date once fully paid; whether frozen/closed-period restates D-04/06 |
| Refund without physical return | Do not subtract returned units twice or infer physical return | CL-E23 cash refund distinct. Refunded/partially_refunded eligibility versus retained units requires D-06 under paid+verified lock |
| Return requested then rejected | Not a proven physical unit loss | CL-E21 returned_quantity increments on request, can reverse on rejection; do not use raw counter alone |
| Accepted inspected/finalized return | Reduce valid accepted quantity once | CL-E22 quantity_received includes good+damaged; restock-good alone omits damaged goods. D-06 chooses lawful stage, not both returned_quantity+refund_quantity |
| Return in later period | Deduct once against eligible units with explicit cohort policy | Proposed original-sale-cohort restatement preserves net units; closed-period policy D-04/06 |
| Partial/split fulfilment | Only disjoint actually delivered units | fulfilled_quantity means active billed, not physical arrival. Item partition conservation; no sibling units credited early |
| Historical POS | Only reconciled verified historical delivery/payment evidence | Business date is not actual delivery date; unresolved historical records excluded |

### Ranking flow — PROPOSED pending D-06

Resolve valid Agent → select canonical item delivery facts in timezone WIB [start inclusive,end exclusive) for7/30/90 days or all time → require agreed fully-paid verified eligibility without multiplying item rows by receipt rows → subtract eligible physical returns once with floor0 at item grain → aggregate disjoint split/variant units by parent product_id → active eligible product/variant filter → net quantity sort with stable tie-break → limit4/8/12 → hide zero/empty.

Use Asia/Jakarta calendar boundaries converted to storage timezone, not server-local implicit dates. Proposed calendar contract must explicitly state whether N-day windows include today; not claimed as a finalized Owner rule. No history-date lookback restriction on all-time.

CMS reuse cms_homepage_blocks type/content/is_active/sort_order; content remains existing validated CMS settings JSON, not arbitrary transaction storage. Title/description/limit/period/grid-carousel registry+renderer extension proposed. Cache key includes Agent, period boundaries, policy version, limit, catalogue eligibility; invalidate on payment verification/refund/accepted return/delivery/catalog eligibility changes. CL-E31 proves no existing ranking/cache/query plan, not production performance.

## Validation strategy — plans only

Import: row invalid price/stock zero-write; other valid row succeeds; repeated SKU group deterministic error; same row retry same approved document, changed fingerprint409; concurrent checkout+opname approval/recount, Sub floor and Agent deficit; explicit actors; XLSX archive/formula limits; CSV/XLSX roundtrip.

Bestseller: early delivered item vs late sibling across windows; unpaid COD/DP exclusion; late payment original delivery cohort; damaged return exactly once; refund-only/cross-period returns; repeated events idempotent; split conservation; two Agents same products; historical admission actual date; no PII. No tests/builds/domain writes executed.
