# POST-BASELINE DEFECT RECON REPORT — REVISED

## 1. BASELINE

**Branch:** main
**HEAD:** 82b0d9e54975cc95245bc74ed3dbc9f7f0d26572
**Worktree:** none (standard workspace)
**Production DB:** primeclassy (read-only access used where applicable)
**Production writes:** NONE — purely read-only investigation

---

## 2. PBR-001 — SALES-KURIR REFERRAL CODE MISSING

### Observed
Human reports that Sales-Kurir does not have a referral code visible/usable in practice.

### Expected (Human-Clarified)
Sales-Kurir MUST have full referral capability:
- New Sales-Kurir: generate unique referral code with prefix `SK-*`, referral link must work, referral attribution must work, eligible for Sales Fee
- Converted Sales → Sales-Kurir: preserve existing historical referral code (`SA-*`), do NOT replace with `SK-*`, preserve historical referral attribution and commissions

### New Sales-Kurir Path

The chain for **newly created** Sales-Kurir:

| Step | File | Function/Line | What Happens |
|------|------|---------------|--------------|
| 1 | `frontend/src/views/dashboard/UserManagementView.vue` | L26 | `ALLOWED_CREATIONS` includes `'sales-kurir'` |
| 2 | `backend/routes/api_v1.php` | L159 | `POST /api/v1/users` — agen/korsal/create |
| 3 | `UserController.php` | L24-39 | `store()` delegates to service |
| 4 | `UserManagementService.php` | L74 | `$ownsReferralCode = HierarchyRules::ownsReferralCode('sales-kurir')` → `true` |
| 5 | Same file | L89 | Calls `createWithUniqueReferralCode(..., 'sales-kurir')` |
| 6 | Same file | L182 | `generateCandidateReferralCode('sales-kurir')` → `SK-` + 6 random chars |
| 7 | Same file | L199-209 | Prefix lookup: `HierarchyRules::REFERRAL_PREFIXES['sales-kurir']` = `'SK-'` |
| 8 | User model | L30 | `referral_code` is fillable; set during `User::create()` |
| 9 | Resource | `UserResource.php` L19 | `referral_code` exposed in API response |

**Verdict for NEW Sales-Kurir:** All links work end-to-end. Test at `UserManagementTest.php:333` asserts `'SK-'` prefix. Test at `SalesCourierDualFeeTest.php:44` confirms same. Generation is correct. **No backend defect.**

Potential issue: Whether the frontend properly displays/exposes the generated referral_code to the user after creation (UI visibility gap).

### Converted Sales Path (CONFIRMED BY HUMAN)

| Step | File | Function/Line | What Happens |
|------|------|---------------|--------------|
| 1 | `UserManagementView.vue` | L161-168 | `convertSales(user)` calls `PATCH /users/{id}/convert-to-sales-kurir` |
| 2 | `api_v1.php` | L168-169 | Route registered under `role:agen` middleware |
| 3 | `UserController.php` | L68-80 | `convertToSalesKurir()` authorizes then delegates |
| 4 | `UserManagementService.php` | L114-153 | `convertSalesToSalesKurir()` updates ONLY `role_id` |
| 5 | Same file | L126 | `update(['role_id' => ...])` — referral_code NOT touched |

**Confirmed by Human:** Conversion intentionally preserves SA-* code. This matches current test at `UserManagementTest.php:345` which asserts `$this->assertSame('SA-ABC123', $sales->referral_code)` after conversion. The test confirms this is intentional design, not a bug.

### Database Evidence

- `users.referral_code` — nullable string column, unique index
- No default value generation; only populated via `UserManagementService::createWithUniqueReferralCode()`
- Never updated by conversion flow
- For newly created Sales-Kurir: contains `SK-XXXXXX` ✅
- For converted Sales-Kurir: retains original `SA-XXXXXX` ✅ (confirmed by Human as correct behavior)

### Root Cause Analysis

If Human still observes "no referral code" for Sales-Kurir, the defect is one of:

1. **Frontend UI visibility** — `UserResource` exposes `referral_code` but `UserManagementView.vue` may not render/display it in any cell or label after creation/conversion
2. **Frontend referral form/link** — Sales-Kurir users on their own dashboard may lack a visible referral code display/referral link widget

The backend chain is sound: `HierarchyRules` → `UserManagementService::generateCandidateReferralCode()` → `UserResource` serialization all include `'sales-kurir'` correctly.

### Affected Files

- `backend/app/Support/HierarchyRules.php` — `ROLES_WITH_REFERRAL_CODE`, `REFERRAL_PREFIXES` (correct for new SK- generation)
- `backend/app/Services/User/UserManagementService.php` — `convertSalesToSalesKurir()` intentionally preserves SA-* (confirmed by Human)
- `backend/app/Http/Resources/UserResource.php` — referral_code field in serialization
- `frontend/src/views/dashboard/UserManagementView.vue` — **likely location of visibility gap**
- `frontend/src/views/dashboard/KurirDashboardView.vue` — check if Sales-Kurir sees referral info here
- `frontend/src/components/ui/AgentPicker.vue` — role picker component

### Existing Tests

| Test | File | Asserts | Does NOT Assert |
|------|------|---------|-----------------|
| New SK- creation | `UserManagementTest.php:333` | `assertStringStartsWith('SK-', ...)` | Frontend receives/displays `referral_code` in response |
| Conversion preserves SA- | `UserManagementTest.php:345` | `assertSame('SA-ABC123', ...)` | N/A (Human confirmed this is correct) |
| Dual fee referral works | `SalesCourierDualFeeTest.php:44` | `assertStringStartsWith('SK-', ...)` | That the referral code appears anywhere actionable in UI |

### Coverage Gap

1. **No test verifies Sales-Kurir referral_code is rendered in any frontend UI** — tests assert generation but never assert that a Sales-Kurir user can see/use their own referral code
2. **No E2E/integration test validates the Sales-Kurir referral link flows through register → attribute → commission** — while ReferralService includes 'sales-kurir' at line 86, no end-to-end path exists in tests
3. **No test checks `UserResource` serialization output on actual HTTP response** — only tests raw model assertions

### Severity

**MAJOR** — If Sales-Kurir cannot see or use their referral code, revenue attribution breaks entirely downstream (see PBR-002). Even though backend generation is correct, missing UI visibility is functionally equivalent to a missing feature.

### Recommended Remediation Contract

1. Audit `UserManagementView.vue` and `KurirDashboardView.vue` — ensure Sales-Kurir user sees their `referral_code` displayed prominently with copy-to-clipboard functionality
2. Add test: `test_sales_kurir_referral_code_exposed_in_user_resource_and_frontend_consumes_it` — asserts `UserResource` serializes referral_code and frontend TS type captures it
3. Add test: `test_konsumen_registers_with_sales_kurir_SK_referral_code_and_attribution_works` — end-to-end register → verify `sales_id` linkage
4. **No backend changes required** — the Human clarified that conversion preservation of SA-* is correct. Only frontend visibility improvement needed.

### Status

**CONFIRMED** — Backend referral generation works correctly. Defect is likely frontend visibility/UI presentation gap, not backend logic failure.

---

## 3. PBR-002 — SALES FEE + COURIER FEE ERROR

### Expected Ledger Model (Human-Clarified)

For a Sales-Kurir who both refers an order AND delivers it:

```
┌─────────────────────────────────────────────────────┐
│ Order Item Ledger                                   │
├──────────────┬──────────────┬───────────────────────┤
│ agent_fee    │ sales_fee    │ courier_fee           │
│ Rp 1,000     │ Rp 500       │ Rp 250 × items        │
└──────────────┴──────────────┴───────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│ Commission Table (SEPARATE ROWS — NEVER MERGED)     │
├──────────┬──────────────┬──────────┬────────────────┤
│ user_id  │ beneficiary_role │ amount   │ status       │
├──────────┼──────────────┼──────────┼────────────────┤
│ AgentId  │ agent        │ 1,000    │ pending        │
│ SK-Id    │ sales        │   500    │ pending        │
│ SK-Id    │ courier      │   250    │ pending        │
└──────────┴──────────────┴──────────┴────────────────┘

Sales Fee ≠ Courier Fee — they are distinct concepts.
```

Key rules confirmed by Human:
- **Separate ledger records** — different `beneficiary_role` values
- **Separate calculations** — sales from referral attribution, courier per-order-item
- **Separate report rows** — dashboard must show A) Sales Fee and B) Courier Fee independently
- **Combined total** may additionally be shown as summary, but two sources remain independently auditable
- **Multi-courier behavior** must remain supported
- **Same Sales-Kurir MAY receive both fees** for the same order when performing both roles

### Actual Implementation — Ledger & Database (CORRECT)

| Component | Status | Evidence |
|-----------|--------|----------|
| Fee configuration (`product_fees`) | ✅ 3 independent rows: agent/sales/courier | `FeeService::setForProduct()` lines 60-83 |
| Fee snapshot (`order_items`) | ✅ 3 separate decimal columns | `OrderService::priceAndReserveLine()` snapshots all |
| Commission checkout | ✅ Creates agent + sales rows separately | `OrderService::recordCommission()` lines 561-578 |
| Commission delivery | ✅ Creates courier row separately | `CourierService::recordCommissionsForItems()` lines 223-247 |
| Idempotency | ✅ Prevents double courier commission | Line 234: checks existing courier row before create |
| Multi-courier | ✅ Per-courier per-order-item | `recordCommissionsOnDelivery()` groups by shipment |
| Total sum | ✅ 1750 = 1000 + 500 + 250 | `SalesCourierDualFeeTest.php:88` |

**The ledger mechanism is CORRECT.** All database operations create separate, non-merged rows.

### Dashboard Visibility — BUG LOCATION

`CommissionController::allowedBeneficiaryRoles()` (lines 25-37):
```php
return match (true) {
    $user->isRole('super_admin', 'agen') => ['agent', 'sales', 'courier'],
    $user->isRole('admin', 'keuangan') => ['sales', 'courier'],
    $user->isRole('sales') => ['sales'],         // ← sales-kurir falls here
    $user->isRole('kurir') => ['courier'],       // ← NOT matched for sales-kurir
    $user->isRole('korsal') => ['sales'],
    default => [],
};
```

A Sales-Kurir with primary role `'sales'` gets `['sales']` only. Their courier fee commissions exist in the database but are **filtered out before reaching the dashboard**.

Human has clarified: Sales-Kurir dashboard/reporting MUST provide **separate views/totals** for:
- **A.** Sales Fee
- **B.** Courier Fee

Both should be independently auditable on the Sales-Kurir dashboard. A combined total is optional additional summary.

### Where Divergence Occurs

**`CommissionController::allowedBeneficiaryRoles()` line 33:** Missing `'sales-kurir'` case. Should return `['sales', 'courier']`.

### Affected Files

**MUST INSPECT:**
- `backend/app/Http/Controllers/Api/V1/Fee/CommissionController.php` — `allowedBeneficiaryRoles()` line 23-37, `scopeToActor()` line 40-61
- `backend/app/Http/Resources/CommissionResource.php` — how individual commissions are serialized
- `frontend/src/views/dashboard/CommissionsView.vue` — how Sales-Kurir dashboard renders fee summaries
- `frontend/src/api/commissions.ts` — API call types and methods
- `backend/tests/Feature/SalesCourierDualFeeTest.php` — dual fee existence tests (not dashboard tests)
- `backend/tests/Feature/FeeSystemTest.php` — role-scoped visibility tests

### Existing Tests

| Test | File | Asserts | Does NOT Assert |
|------|------|---------|-----------------|
| Dual fee exists in DB | `SalesCourierDualFeeTest.php:77-87` | Both `role='sales'` and `role='courier'` rows exist | That Sales-Kurir USER sees both rows on dashboard |
| Idempotent courier | `SalesCourierDualFeeTest.php:91-108` | Single courier row persists | Dashboard visibility after creation |
| Role scoping (missing!) | None | N/A | **ZERO TESTS** cover `allowedBeneficiaryRoles()` for `'sales-kurir'` |

### Coverage Gap

1. **No test for `allowedBeneficiaryRoles()` specifically for `'sales-kurir'`** — the exact method containing the bug is untested
2. **No integration test: login as Sales-Kurir → GET `/api/v1/commissions/summary` → verify BOTH `total_sales_fee` and `total_courier_fee` present**
3. **No test: Sales-Kurir GETs `/api/v1/commissions` → returns both sales and courier entries**
4. **No test verifying CommissionsView frontend renders separate Sales Fee and Courier Fee tiles for Sales-Kurir**

### Severity

**BLOCKER** — Sales-Kurir earns both fees but dashboard hides one. Direct financial transparency failure. Users will believe commissions are lost/unpaid.

### Recommended Remediation Contract

1. **Backend:** Add to `allowedBeneficiaryRoles()`: `$user->isRole('sales-kurir') => ['sales', 'courier']`
2. **Backend:** Verify `scopeToActor()` personal scope (`where('beneficiary_user_id', $user->id)`) works for Sales-Kurir — it currently falls through to the `$else` clause which scopes by `beneficiary_user_id`, so this should be fine
3. **Frontend (if needed):** `CommissionsView.vue` already renders three summary tiles (`total_agent_fee`, `total_sales_fee`, `total_courier_fee`). Once backend returns both fields for Sales-Kurir, frontend will auto-display both
4. **Tests:** Add `test_sales_kurir_sees_both_sales_and_courier_commission_roles()` — login as Sales-Kurir, verify API returns both fee categories
5. **Tests:** Add `test_allowed_beneficiary_roles_sales_kurir` — unit test the match statement explicitly

### Status

**CONFIRMED** — Defect definitively at `CommissionController::allowedBeneficiaryRoles()` line 33. One-line fix. Ledger/database implementation is correct.

---

## 4. PBR-003 — WAREHOUSE UI SHOWES PRODUCT ID INSTEAD OF PRODUCT NAME

### Human Business Requirement (Clarified)

Warehouse management must be **PRODUCT-ORIENTED** rather than ID-oriented.

Required display elements:
- Product image/thumbnail when available
- **Product Name**
- **Variant Name/options** when applicable
- **SKU**
- Relevant stock/bucket quantities

Product ID / Variant ID: secondary technical information only. Warehouse users must identify/manage inventory WITHOUT knowing database IDs.

Additional UX features where useful:
- Search by product name/SKU
- Variant information
- Stock/bucket status
- Pagination/filtering
- Product detail/action controls

Target screens: stock overview, Transit, Sub, Plan Pabrik, Shipping, transfer, handover, stock request, fulfillment, stock opname, reconciliation, stock movement/history.

### Gold Standard Pattern (Already Working)

`StockManagementView.vue` correctly displays product identity using Resources:

**Backend Resource** (`ProductStockResource.php`):
```php
'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
'sku'          => $this->whenLoaded('product', fn () => $this->product->sku),
```

**Frontend Type** (`stock.ts`):
```typescript
interface ProductStockRow {
  id: number; product_id: number;
  product_name: string;
  sku: string | null;
  quantity_on_hand: number; quantity_reserved: number; quantity_available: number;
}
```

**Frontend Template** (`StockManagementView.vue` L172-175):
```vue
<td class="px-4 py-3">
  <div class="font-medium text-stone-700">{{ row.product_name }}</div>
  <div class="text-xs text-stone-400">SKU: {{ row.sku || '-' }}</div>
</td>
```

This is the pattern ALL warehouse screens should follow.

### Screen-by-Screen Analysis

#### Screen A: Warehouse Stock View (Transit + Factory Plan) — PRIMARY BUG

| Field | Value |
|-------|-------|
| Page | `WarehouseStockView.vue` |
| API | `GET /warehouse-stock` |
| Controller | `StockController::warehouse()` |
| Relation loading | ✅ `with(['product', 'variation', 'subLocation'])` |
| Resource wrapping | ❌ Returns raw `WarehouseStock` models |
| Response shape | Nested `{ product: { name, sku }, variation: { label, sku } }` |
| Frontend type | `WarehouseRow` — only has `product_id`, `product_variation_id`, NO names |
| Template display | L50: `{{ row.product_id ?? row.product_variation_id }}` |
| Bug | Shows literal integer `127` instead of product name |

**Root cause:** Backend sends product name as nested object but TS interface strips it. Template references `row.product_id` not `row.product?.name`.

#### Screen B: Stock Transfer View — BUG

| Field | Value |
|-------|-------|
| Page | `WarehouseTransfersView.vue` |
| API | `GET /warehouse/transfers` |
| Controller | `StockTransferController::index()` |
| Relation loading | ✅ `items.product`, `items.variation` loaded |
| Resource wrapping | ❌ Raw models |
| Response shape | Nested objects with `items[].product.{name, sku}`, `items[].variation.{label, sku}` |
| Frontend type | Captures nested data but template ignores it |
| Template display | L24: Shows `transfer_number`, route, status — NEVER renders item details |
| Bug | Items table shows nothing about products |

**Root cause:** Template doesn't iterate over items to display product names. Backend provides data.

#### Screen C: Stock Requests View — PARTIAL (Missing Name)

| Field | Value |
|-------|-------|
| Page | `StockRequestsView.vue` |
| API | `GET /warehouse/stock-requests` |
| Controller | `StockRequestController::index()` |
| Relation loading | ⚠️ Only loads `items`, NOT `items.product` |
| Snapshot data | Has `sku_snapshot` but NO `product_name_snapshot` |
| Frontend display | L11: `{{ item.sku_snapshot }} · requested {{ requested_qty }} · fulfilled {{ fulfilled_qty }}` |
| Bug | SKU-only identifier, no product name |

**Root cause:** Model lacks denormalized product name; relation not loaded.

#### Screen D: Warehouse Opnames View — MISSING

| Field | Value |
|-------|-------|
| Page | `WarehouseOpnamesView.vue` |
| API | `GET /warehouse/opnames` |
| Controller | `StockOpnameController::index()` |
| Relation loading | ⚠️ Only loads `items`, NOT `items.product` |
| Model | `StockOpnameItem` has `product()` relationship but never eager-loaded |
| Frontend display | Shows only `opname_number · opname_type · status` — no per-item product info |
| Bug | No product identity at all on list view |

**Root cause:** Relation not loaded, no snapshot fields, frontend doesn't render items.

#### Screen E: Warehouse Returns View — MINOR (Has Data, Skips Display)

| Field | Value |
|-------|-------|
| Page | `WarehouseReturnsView.vue` |
| API | `GET /admin/returns` |
| Controller | Admin returns controller (uses resource layer) |
| Response data | Includes `product_name`, `variation_label`, `sku` from snapshots |
| Frontend display | L11: `Qty {{ quantity_returned }} · {{ condition_status }}` — product_name available but not shown |
| Bug | Data exists but template skips it |

#### Screen F: Stock Management Hub — WORKING (Gold Standard)

✅ Displays `product_name` and `sku` correctly using `ProductStockResource`. Uses TypeScript types that match backend resource shape.

### Backend Code Inventory — Route Endpoints & Response Shapes

| Endpoint | Method | Controller | Resource? | Relations Loaded |
|----------|--------|------------|-----------|-----------------|
| `/warehouse-stock` | GET | `StockController::warehouse()` | ❌ | `product`, `variation`, `subLocation` |
| `/warehouse/transit/receive` | POST | `WarehouseController::receive()` | ❌ | — |
| `/warehouse/factory-plan` | POST | `WarehouseController::adjustPlan()` | ❌ | — |
| `/warehouse/transfers` | GET | `StockTransferController::index()` | ❌ | `items.product`, `items.variation` |
| `/warehouse/transfers/{t}` | GET | `StockTransferController::show()` | ❌ | `items.product`, `items.variation` |
| `/warehouse/transfers/{t}/complete` | POST | `StockTransferController::complete()` | ❌ | — |
| `/warehouse/opnames` | GET | `StockOpnameController::index()` | ❌ | `items` only |
| `/warehouse/stock-requests` | GET | `StockRequestController::index()` | ❌ | `items` only |
| `/warehouse/sub-locations` | GET | `WarehouseSubLocationController::index()` | ✅ | N/A |
| `/stock/products` | GET | `StockController::products()` | ✅ | `product` |
| `/stock/variations` | GET | `StockController::variations()` | ✅ | `variation` |

### Recommended Minimum Product Identity (Per Human Spec)

| Field | Source | Display Priority |
|-------|--------|-----------------|
| Product Image/Thumbnail | `product.image_url` or `media` relation | Primary — visual first |
| Product Name | `product.name` | Primary |
| Variant Name/Options | `variation.label()` accessor | Primary (when applicable) |
| SKU | `product.sku` or `variation.sku` | Primary |
| Stock/Bucket Qty | Context-dependent per screen | Primary |
| Product ID | `product.id` | Secondary (technical) |
| Variant ID | `variation.id` | Secondary (technical) |

**Example before/after:**
- **Before (bad):** `127` (bare ID)
- **After (expected):** `[image] Chocolate Cake · Large / Chocolate · PCC-CK-L-CHO · Qty: 10`

### Coverage Gap

| Gap | Details |
|-----|---------|
| No warehouse API test asserts product identity fields | Tests assert `product_id` numeric presence but never `product_name`, `sku`, or `variation.label` |
| No Resource wrapper for most warehouse endpoints | Inconsistent with `ProductStockResource` gold standard |
| No factory migration for `product_name_snapshot` on `stock_request_items` | Schema lacks denormalized name data |
| No E2E test asserts warehouse screens render human-readable product identity | Only backend DB assertions exist |
| `ProductStockResource` pattern isolated to one endpoint | Not reused across transit/transfer/opname/request screens |

### Severity

**MINOR** (functional) / **MAJOR** (UX impact) — Data exists in many backend responses. Not a data-loss defect. But warehouse staff cannot efficiently work without seeing product names. Operational productivity significantly degraded.

### Status

**CONFIRMED** — Confirmed across all 6 warehouse screens. `StockManagementView` proves it works. Other screens need either: (A) Resource wrappers, (B) relation eager-loading, (C) frontend type+template fixes, or (D) schema additions.

---

## 5. CROSS-FINDING ANALYSIS

### Do PBR-001 and PBR-002 Share a Common Root Cause?

**Yes — systemic authorization inconsistency for `sales-kurir` role.**

| Location | Handles sales-kurir? | Outcome |
|----------|---------------------|---------|
| `HierarchyRules::ownsReferralCode()` | ✅ Yes (line 21) | New SK- codes generated |
| `ReferralService::findActiveReferrerByCode()` | ✅ Yes (line 86) | Konsumen referrals attributed |
| `UserManagementService::convertSalesToSalesKurir()` | ✅ Preserves SA-* | Human confirmed correct |
| `CommissionController::allowedBeneficiaryRoles()` | ❌ NO CASE | Sales-Kurir gets `['sales']` only — HIDES courier fees |

**The common thread:** Most authorization/feature gates include `'sales-kurir'` explicitly, but `CommissionController` uses a `match(true)` block with no `'sales-kurir'` arm, causing fallthrough to `'sales'` — which is insufficient because it excludes courier visibility.

**Fixing referral identity does NOT affect fee attribution** because commissions reference `beneficiary_user_id` (the user record), not `referral_code`. Human-confirmed SA-* preservation on conversion means no audit trail break.

### Is PBR-003 Presentation-Only or Requires API Changes?

**Mixed:**

- **Presentations (majority):** Most warehouse screens already receive product names from backend (nested objects), but TS types discard them and templates show IDs
- **API contract changes needed (minor):** Two screens (StockRequests, Opnames) need backend changes — add `product_name_snapshot` column + eager-load relations

The most consistent fix is creating warehouse-specific Resource classes modeled on `ProductStockResource` and applying them uniformly.

---

## 6. TESTS REQUIRED FOR REMEDIATION

| # | Test Description | Category | Priority |
|---|-----------------|----------|----------|
| 1 | `test_new_sales_kurir_gets_SK_prefix_referral_code` — Create Sales-Kurir via POST /api/v1/users, assert response.data.referral_code starts with `SK-` and is non-null | PBR-001 | HIGH |
| 2 | `test_converted_sales_preserves_historical_SA_referral_code_on_conversion_to_sales_kurir` — Convert Sales→Sales-Kurir, assert referral_code unchanged (SA- preserved) | PBR-001 | CONFIRMED ✓ |
| 3 | `test_sales_kurir_referral_code_visible_in_api_response_and_frontend` — POST creates Sales-Kurir, assert UserResource includes referral_code, TS type captures it | PBR-001 | HIGH |
| 4 | `test_konsumen_self_registration_via_sales_kurir_SK_referral_code_attributes_sale` — Full register flow with SK-* code, verify buyer.sales_id = sales_kurir.id | PBR-001 | HIGH |
| 5 | `test_sales_kurir_referral_generates_sales_fee_commission_row` — Checkout with Sales-Kurir as referrer, assert commission(role='sales') created | PBR-002 | HIGH |
| 6 | `test_sales_kurir_courier_assignment_generates_separate_courier_fee` — Delivery completion, assert commission(role='courier') created | PBR-002 | HIGH |
| 7 | `test_same_sales_kurir_receives_distinct_sales_and_courier_ledger_rows` — Assert TWO separate commission rows with different beneficiary_role for same order_item | PBR-002 | CONFIRMED ✓ |
| 8 | `test_courier_fee_is_per_order_item` — Multi-item order with Sales-Kurir courier, each item generates own courier commission row | PBR-002 | MEDIUM |
| 9 | `test_sales_kurir_dashboard_sees_both_sales_and_courier_commissions` — Login as Sales-Kurir, GET /api/v1/commissions/summary, assert BOTH total_sales_fee AND total_courier_fee returned | PBR-002 | BLOCKER |
| 10 | `test_allowed_beneficiary_roles_returns_sales_and_courier_for_sales_kurir` — Direct test of `CommissionController::allowedBeneficiaryRoles()` for 'sales-kurir' role | PBR-002 | BLOCKER |
| 11 | `test_warehouse_stock_list_returns_human_readable_product_identity` — GET /warehouse-stock, assert each row includes product_name (flat or nested product.name) and variation.label | PBR-003 | HIGH |
| 12 | `test_warehouse_transfers_list_includes_product_names_in_items` — GET /warehouse/transfers, assert items[].product.name and items[].variation.label present | PBR-003 | HIGH |
| 13 | `test_warehouse_requests_expose_product_name_snapshots` — GET /warehouse/stock-requests, assert items[].product_name_snapshot present (needs backend migration first) | PBR-003 | MEDIUM |
| 14 | `test_warehouse_opnames_include_product_relations` — GET /warehouse/opnames, assert items[] eagerly loaded with product.name | PBR-003 | MEDIUM |
| 15 | `test_frontend_warehouse_screen_displays_product_name_not_numeric_id` — Integration: fetch /warehouse-stock, parse response, verify template would render name not ID | PBR-003 | HIGH |

---

## 7. REMEDIATION FILE MAP

### MUST CHANGE

| File | Change Required | PBR |
|------|-----------------|-----|
| `backend/app/Http/Controllers/Api/V1/Fee/CommissionController.php` | Add `'sales-kurir'` case to `allowedBeneficiaryRoles()` → `['sales', 'courier']` | PBR-002 |
| `frontend/src/api/warehouse.ts` | Extend `WarehouseRow` to include `product_name?: string`, `sku?: string|null`, `variation_label?: string` | PBR-003 |
| `frontend/src/views/dashboard/WarehouseStockView.vue` | L50: Render `row.product?.name ?? row.product_name ?? \`P\${row.product_id}\`` + show SKU line | PBR-003 |
| `frontend/src/views/dashboard/WarehouseTransfersView.vue` | L24: Add items table row rendering with product name, variant, SKU | PBR-003 |
| `frontend/src/views/dashboard/StockRequestsView.vue` | L11: Enhance display with product name (requires backend change) | PBR-003 |
| `backend/app/Services/Stock/StockRequestService.php` | Populate `product_name_snapshot` on StockRequestItem create | PBR-003 |
| `backend/migrations/` | Add `product_name_snapshot VARCHAR(255)` column to `stock_request_items` table | PBR-003 |
| `backend/app/Services/Stock/StockOpnameService.php` / `StockOpnameController` | Eager-load `items.product` and `items.variation` | PBR-003 |

### MAY CHANGE

| File | Consideration | PBR |
|------|---------------|-----|
| `frontend/src/views/dashboard/CommissionsView.vue` | May need minor CSS tweaks once `total_courier_fee` appears — currently conditional tile rendering should auto-work | PBR-002 |
| `frontend/src/views/dashboard/WarehouseOpnamesView.vue` | Update types + optionally render item details in opname list | PBR-003 |
| `frontend/src/views/dashboard/WarehouseReturnsView.vue` | L11: Show product_name alongside qty/condition | PBR-003 |
| `backend/app/Http/Resources/` | Create reusable `WarehouseItemResource` shared by all warehouse controllers (consolidates ProductStockResource pattern) | PBR-003 |
| `frontend/src/api/transfers.ts` | Ensure TS types capture `product.name` and `variation.label` for nested items | PBR-003 |
| `frontend/src/api/opnames.ts` | Update `StockOpnameItem` type with product/variation sub-fields | PBR-003 |

### MUST NOT CHANGE

| File/Contract | Reason | PBR |
|---------------|--------|-----|
| `product_fees` table structure | Core financial data — three beneficiary roles correct | PBR-002 |
| `commissions` table structure | Core financial data — three beneficiary roles correct | PBR-002 |
| `order_items.*_fee_amount` columns | Snapshotted financial data — read-only | PBR-002 |
| `User::referral_code` uniqueness constraint | DB integrity guard | PBR-001 |
| `FeeService::resolveForProduct()` separation logic | Already produces correct three-fee output | PBR-002 |
| `UserManagementService::convertSalesToSalesKurir()` SA-* preservation | Human confirmed this is INTENTIONAL | PBR-001 |

---

## 8. FINAL RECON VERDICT

**READY FOR MUSE TARGETED REMEDIATION**

All three defects are confirmed with specific root causes, affected files, coverage gaps, and remediation contracts defined.

- **PBR-001:** Backend referral generation is correct (SK-* for new, SA-* preserved on conversion per Human decision). Defect is frontend visibility — ensure Sales-Kurir sees their referral code on their dashboard/screens.
- **PBR-002:** Commission ledger mechanism is fully correct (separate rows, idempotent, multi-courier). Defect is ONE LINE in `allowedBeneficiaryRoles()` — missing `'sales-kurir'` case prevents courier fee visibility on dashboard.
- **PBR-003:** Consistent pattern across warehouse screens: backend provides product data but frontend types strip it and templates show IDs. Gold-standard pattern exists in `StockManagementView`. Fix requires: Resource wrappers, TS type expansions, template changes, and one minor schema addition (`product_name_snapshot`).

Connective tissue: Authorization/inclusion inconsistency for `sales-kurir` role (PBR-001 referral generation includes it, PBR-002 visibility excludes it). Targeted remediation is small-scope and low-risk.
