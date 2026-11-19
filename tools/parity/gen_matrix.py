#!/usr/bin/env python3
"""Generate the IMP-010 API parity matrix from the embedded route inventory.

Source of truth: go-backend/internal/api/parity_routes.json (produced from `php artisan route:list --json` on
the IMP-008/IMP-009 baseline). The IMPLEMENTED column is read from the same list the router consults, kept here
as an explicit table so the document and the code cannot disagree silently — the Go test
`TestImplementedRoutesAreRealRoutes` enforces the reverse direction.

Usage: python3 tools/parity/gen_matrix.py > /tmp/matrix.md
"""
import json
import os
import sys
from collections import Counter, OrderedDict

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ROUTES = os.path.join(ROOT, "go-backend", "internal", "api", "parity_routes.json")

# Endpoints the Go service serves. Anything absent is NOT PORTED.
IMPLEMENTED = {
    ("GET", "/api/v1/install/status"): "",
    ("GET", "/api/v1/settings"): "",
    ("GET", "/api/v1/languages"): "",
    ("GET", "/api/v1/categories"): "",
    ("POST", "/api/v1/categories"): "global taxonomy create; super_admin/admin/agen; normalized name, sibling duplicate guard and server-generated slug",
    ("PATCH", "/api/v1/categories/{category}"): "super_admin only; parent-cycle and sibling-duplicate guards",
    ("DELETE", "/api/v1/categories/{category}"): "super_admin only; children are promoted by ON DELETE SET NULL",
    ("POST", "/api/v1/orders/{order}/payment/proof"): "bank-transfer proof; konsumen/branch staff/payOnBehalf; MIME-sniffed storage on the private local disk",
    ("POST", "/api/v1/orders/{order}/payment/cod-proof"): "COD proof via the media pipeline (cod_payment_proof); partial amount optional",
    ("POST", "/api/v1/orders/{order}/payment/installment"): "transfer/cash; idempotent replay; cash refuses the buyer and requires a receipt",
    ("PATCH", "/api/v1/orders/{order}/payment/cod"): "keuangan/super_admin; idempotent; single-full-collection undo only",
    ("POST", "/api/v1/orders/{order}/payment/verify"): "keuangan/super_admin; self-verification forbidden; verified_amount ≤ outstanding/submitted",
    ("POST", "/api/v1/orders/{order}/payment/settle"): "keuangan/super_admin; DP only; equivalent replay, material-mismatch 422",
    ("PATCH", "/api/v1/admin/cod-payment-proofs/{codPaymentProof}/confirm"): "keuangan/super_admin; confirm settles the real collection",
    ("GET", "/api/v1/payment-proofs/{verification}"): "ownership-gated private proof download; Cache-Control private no-store",
    ("PUT", "/api/v1/orders/{order}/due-date"): "admin/keuangan/super_admin; audited old/new + reason; no-op when unchanged",
    ("GET", "/api/v1/users"): "",
    ("POST", "/api/v1/users"): "",
    ("GET", "/api/v1/users/{user}"): "",
    ("PATCH", "/api/v1/users/{user}"): "",
    ("DELETE", "/api/v1/users/{user}"): "",
    ("PATCH", "/api/v1/users/{user}/reassign-referral"): "",
    ("PATCH", "/api/v1/users/{user}/convert-to-sales-kurir-sub"): "",
    ("PATCH", "/api/v1/users/{user}/convert-to-sales-kurir"): "",
    ("GET", "/api/v1/agent/payment-methods"): "",
    ("PATCH", "/api/v1/agent/payment-methods/{method}/toggle"): "role gates, toggle response shape, and resulting setting rows compared by compare_agentadmin.py",
    ("PUT", "/api/v1/agent/payment-methods/{method}/config"): "FormRequest validation order, localized messages, encrypted config writes, and RBAC compared by compare_agentadmin.py",
    ("GET", "/api/v1/agent/store-profile"): "",
    ("PUT", "/api/v1/agent/store-profile"): "",
    ("GET", "/api/v1/agent/shipping-tariff"): "",
    ("PUT", "/api/v1/agent/shipping-tariff"): "pricing validation, Laravel response message, audit properties and resulting tariff row compared by compare_shipping_admin.py",
    ("GET", "/api/v1/agent/shipping-providers"): "",
    ("PATCH", "/api/v1/agent/shipping-providers/{provider}/toggle"): "five-key response projection, localized message, audit and setting row compared by compare_shipping_admin.py",
    ("PUT", "/api/v1/agent/shipping-providers/{provider}/config"): "provider-specific validation, SSRF refusal, encrypted config side effect and localized response compared by compare_shipping_admin.py",
    ("GET", "/api/v1/agent/shipping-providers/{provider}/couriers"): "",
    ("PUT", "/api/v1/agent/shipping-providers/{provider}/couriers"): "supported-courier validation, response, and encrypted config side effect compared by compare_shipping_admin.py",
    ("GET", "/api/v1/agent/whatsapp/scope"): "",
    ("GET", "/api/v1/agent/whatsapp/events"): "",
    ("PUT", "/api/v1/agent/whatsapp/events/{event}"): "",
    ("DELETE", "/api/v1/agent/whatsapp/events/{event}/template"): "",
    ("GET", "/api/v1/agent/whatsapp/test-numbers"): "",
    ("POST", "/api/v1/agent/whatsapp/test-numbers"): "",
    ("DELETE", "/api/v1/agent/whatsapp/test-numbers/{id}"): "",
    ("PATCH", "/api/v1/profile"): "",
    ("GET", "/api/v1/profile/email/verification"): "",
    ("PATCH", "/api/v1/profile/referral-code"): "",
    ("POST", "/api/v1/profile/referral-code/regenerate"): "",
    ("DELETE", "/api/v1/profile/referral-code"): "",
    ("GET", "/api/v1/cms/homepage-blocks"): "",
    ("POST", "/api/v1/cms/homepage-blocks"): "",
    ("PATCH", "/api/v1/cms/homepage-blocks/reorder"): "",
    ("PATCH", "/api/v1/cms/homepage-blocks/{block}"): "",
    ("PATCH", "/api/v1/cms/homepage-blocks/{block}/toggle"): "",
    ("DELETE", "/api/v1/cms/homepage-blocks/{block}"): "",
    ("GET", "/api/v1/cms/articles"): "",
    ("POST", "/api/v1/cms/articles"): "",
    ("PATCH", "/api/v1/cms/articles/{article}"): "",
    ("DELETE", "/api/v1/cms/articles/{article}"): "",
    ("GET", "/api/v1/cms/pages"): "",
    ("POST", "/api/v1/cms/pages"): "",
    ("PATCH", "/api/v1/cms/pages/{page}"): "",
    ("DELETE", "/api/v1/cms/pages/{page}"): "",
    ("POST", "/api/v1/products"): "",
    ("GET", "/api/v1/manage/products"): "",
    ("GET", "/api/v1/manage/products/{product}"): "",
    ("PATCH", "/api/v1/products/{product}"): "",
    ("DELETE", "/api/v1/products/{product}"): "",
    ("DELETE", "/api/v1/products/{id}/permanent"): "",
    ("POST", "/api/v1/products/{product}/images"): "",
    ("PUT", "/api/v1/products/{product}/images/order"): "",
    ("PATCH", "/api/v1/products/{product}/images/{image}"): "",
    ("DELETE", "/api/v1/products/{product}/images/{image}"): "",
    ("POST", "/api/v1/products/{product}/variations"): "",
    ("PATCH", "/api/v1/products/{product}/variations/{variation}"): "",
    ("DELETE", "/api/v1/products/{product}/variations/{variation}"): "",
    ("GET", "/api/v1/products/{product}/fees"): "",
    ("PUT", "/api/v1/products/{product}/fees"): "",
    ("GET", "/api/v1/products/{product}/variations/{variation}/fees"): "",
    ("PUT", "/api/v1/products/{product}/variations/{variation}/fees"): "",
    ("GET", "/api/v1/referral/{code}"): "",
    ("GET", "/api/v1/crm/customers"): "",
    ("GET", "/api/v1/crm/customers/{customer}"): "",
    ("GET", "/api/v1/crm/customers/{customer}/visits"): "",
    ("GET", "/api/v1/crm/follow-ups"): "",
    ("GET", "/api/v1/commissions"): "",
    ("GET", "/api/v1/commissions/summary"): "",
    ("GET", "/api/v1/reports/transactions"): "",
    ("GET", "/api/v1/agents"): "",
    ("GET", "/api/v1/regions/provinces"): "",
    ("GET", "/api/v1/regions/regencies"): "",
    ("GET", "/api/v1/regions/districts"): "",
    ("GET", "/api/v1/regions/villages"): "",
    ("POST", "/api/v1/auth/login"): "",
    ("POST", "/api/v1/auth/logout"): "",
    ("GET", "/api/v1/auth/me"): "",
    ("PATCH", "/api/v1/profile/password"): "",
    ("POST", "/api/v1/auth/register"): "user + referral chain + closures; welcome e-mail/WA notification not emitted (queue bridge outstanding)",
    ("GET", "/api/v1/products"): "anonymous exact; authenticated agent/Sub availability (stock) not ported",
    ("GET", "/api/v1/products/{slug}"): "anonymous exact; authenticated agent/Sub availability (stock) not ported",
    ("GET", "/api/v1/articles"): "",
    ("GET", "/api/v1/articles/{slug}"): "",
    ("GET", "/api/v1/pages/{slug}"): "",
    ("GET", "/api/v1/homepage"): "\"bestseller\" block returns [] for anonymous visitors (identical to the reference); authenticated ranking not ported",
    # --- checkpoint N: checkout wizard contract (compare_checkout.py, 38 checks over 11 scenarios)
    ("GET", "/api/v1/checkout/steps"): "public; dynamic step list with role-derived flags, agent provider switches, KMSIT fallback and the konsumen payment filter",
    ("POST", "/api/v1/checkout/quote"): "read-only Review preview through the same money/pricing arithmetic as order creation; never reserves stock or consumes a voucher",
    # --- checkpoint W: order read surface (compare_orders_read.py, 11 checks)
    ("GET", "/api/v1/orders"): "role-scoped list; a kurir is refused; operational roles see the minimized projection; status/payment_status/search/region/delivery_date/paid filters",
    ("GET", "/api/v1/orders/regions"): "region facets derived only from the caller's own scoped orders; each level empty until its parent is supplied",
    ("GET", "/api/v1/orders/{order}"): "policy-gated detail (own buyer, branch roles, korsal/sales team + the reassigned-owner leg, gudang queue-only); loads payment_transaction + returns, which the list omits",
    # --- checkpoint V: order lifecycle (compare_order_lifecycle.py, 10 checks)
    ("PATCH", "/api/v1/orders/{order}/status"): "super_admin/agen/admin bulk diproses→dikirim→terkirim; payment eligibility under the Order lock; Sub items never advance here; first handover stamps the basis",
    ("POST", "/api/v1/orders/{order}/cancel"): "konsumen/branch-agen/super_admin; reverses reservations (idempotent per item), returns accepted Shipping→Transit, cancels the Stock Request, books the sales reduction, freezes the financial snapshot and creates the refund obligation — all in ONE transaction",
    # --- checkpoint X: per-item fulfillment lifecycle (compare_fulfillment.py)
    ("PATCH", "/api/v1/orders/{order}/items/{item}/fulfillment"): "super_admin/branch agen/admin while diproses; reduce consumes frozen snapshot units, books the sales reduction + refund obligation (or the needs_verification variant for a provisional-legacy basis), reverses inventory Order→Stock Request→stock; increase restores cancelled units first, reserves stock, then raises the total and creates ONE additional-payment obligation only when the order was fully settled",
    ("GET", "/api/v1/orders/{order}/items/{item}/cancel-eligibility"): "read-only projector: order window, stock source, item status, open refusal, remaining quantity and a pending additional payment decide `eligible` + `reason_code`; max_quantity is fulfilled_quantity only when eligible",
    ("POST", "/api/v1/orders/{order}/items/{item}/cancel-undelivered"): "REM-003 Owner Option A: cancels part of the undelivered/undispatched remainder (also after first dispatch); expected_quantity mismatch is a 409 stale-quantity guard; recomputes the order status forward-only",
    ("PATCH", "/api/v1/orders/{order}/items/{item}/reschedule"): "re-dates a line; LOCKED to Kurir Online + Self Delivery/Sub (expedition/pickup refused), a dikirim order only for the line with an open shortage; a partial quantity splits the line onto a new OrderItem + Shipment, and the shipment is re-homed onto the canonical shipment for the date",
    # --- checkpoint U: Sub stock-request workflow (compare_subrequests.py, 16 checks)
    ("POST", "/api/v1/sub-stock/requests"): "sales-kurir-sub; direction replenish/return; the create response serialises ONLY the insert-time attributes (Eloquent fresh-create shape), an idempotent replay serialises fully",
    ("GET", "/api/v1/sub-stock/requests/{subStockRequest}"): "policy view: super_admin all, requester own, branch roles same agent; denial is a 404",
    ("POST", "/api/v1/sub-stock/requests/{subStockRequest}/approve"): "admin; requested→approved only",
    ("POST", "/api/v1/sub-stock/requests/{subStockRequest}/reject"): "admin; reason required",
    ("POST", "/api/v1/sub-stock/requests/{subStockRequest}/cancel"): "the requester only, while still requested",
    ("POST", "/api/v1/sub-stock/requests/{subStockRequest}/execute"): "gudang — the ONLY stock-moving step, idempotent; Sub Location EXCLUSIVE lock then buckets; replenish passes the Transit-reservation guard, return refuses below active Sub reservations",
    ("POST", "/api/v1/sub-stock/requests/{subStockRequest}/receive"): "the requester, replenish only; closes the handover",
    # --- checkpoint T: handover read/print (compare_handovers.py)
    ("GET", "/api/v1/warehouse/handovers/{handover}"): "the full model with transfer.items.product/variation and both actors (users serialize with NO hidden fields here)",
    ("GET", "/api/v1/warehouse/handovers/{handover}/print"): "the A4 print page, HTML; the Blade template is ported 1:1 including its escaping",
    # --- checkpoint S: stock-request proposals (compare_proposals.py, 20 checks)
    ("POST", "/api/v1/warehouse/stock-requests/{stockRequest}/fulfill"): "the reference REFUSES direct Gudang fulfilment (the proposal gate is the only path); reproduced verbatim",
    ("POST", "/api/v1/warehouse/stock-requests/{stockRequest}/proposals"): "gudang; multipart with a REQUIRED proof image when goods are handed over; shortage-only reports carry none and create no proposal row",
    ("GET", "/api/v1/warehouse/fulfillment-proposals"): "index loads product.images (key renders even when empty), unlike the propose/settle responses",
    ("GET", "/api/v1/warehouse/fulfillment-proposals/{proposal}"): "same detail shape as the index",
    ("POST", "/api/v1/warehouse/fulfillment-proposals/{proposal}/receive"): "koordinator-kurir — the routine settlement; accepted+short+damaged must equal the prepared quantity; stock moves transit→shipping for accepted units only; damaged units are held on the line",
    ("POST", "/api/v1/warehouse/fulfillment-proposals/{proposal}/approve"): "admin EXCEPTION path; a reason (min 5 chars) is mandatory and audited; settles the whole prepared quantity",
    ("POST", "/api/v1/warehouse/fulfillment-proposals/{proposal}/reject"): "admin; moves no stock",
    ("POST", "/api/v1/warehouse/stock-request-items/{item}/resolve-discrepancy"): "F07: write_off reduces Transit by an adjustment movement and lifts the hold under a UUID idempotency key (retry answers 409); restock is refused pending an Owner inspection rule",
    # --- checkpoint R: warehouse stock-request two-party gate (compare_warehouse_requests.py, 22 checks)
    ("GET", "/api/v1/warehouse/stock-addition-requests"): "agent-scoped list; sub_location is loaded here (unlike show)",
    ("POST", "/api/v1/warehouse/stock-addition-requests"): "gudang only; target is product XOR variation; quantity > 0",
    ("GET", "/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}"): "show does NOT eager-load sub_location, so that key is absent — the reference's own asymmetry",
    ("POST", "/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}/approve"): "admin only; idempotent (a second approve moves no stock); transit receipt or factory_plan change under the commitment guard",
    ("POST", "/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}/reject"): "admin only; leaves stock untouched; a missing id answers the endpoint-not-found sentence",
    ("POST", "/api/v1/warehouse/sub-adjustment-requests"): "gudang only; a negative delta may never cut Sub physical below the active reservations",
    # --- checkpoint Q: order creation (compare_orders.py, 43 checks: response + DB state + idempotency + atomicity)
    ("POST", "/api/v1/orders"): "full chain: canonical target locks, reservation, fee/commission snapshots, voucher redemption, invoice sequence, shipment grouping, payment transaction, Stock Request (COD), initial report snapshot; idempotent replay and total rollback verified",
    # --- checkpoint G: inventory read surface (contract-compared in tools/parity/compare_inventory.py)
    ("GET", "/api/v1/stock/products"): "",
    ("GET", "/api/v1/stock/variations"): "",
    ("GET", "/api/v1/warehouse-stock"): "Laravel paginates without ORDER BY (upstream defect); Go orders by id, so cross-page SET is compared instead of page bytes",
    ("GET", "/api/v1/warehouse/sellable"): "Go answers 422 when neither product_id nor variation_id is given; Laravel 500s there (upstream defect, documented divergence)",
    ("GET", "/api/v1/warehouse/settings"): "reproduces Laravel's firstOrCreate side effect (a GET may create the row)",
    ("GET", "/api/v1/warehouse/sub-locations"): "",
    ("GET", "/api/v1/warehouse/sub-locations/{subLocation}"): "",
    ("GET", "/api/v1/warehouse/sub-locations/{subLocation}/stocks"): "",
    ("GET", "/api/v1/warehouse/stock-cards"): "reads warehouse buckets only (no legacy fallback) — same asymmetry as the reference",
    ("GET", "/api/v1/warehouse/sub-locations/{subLocation}/stock-cards"): "",
    ("GET", "/api/v1/sub-stock/my"): "reserved always 0 in the verified fixture set (needs an order_item; belongs to the orders checkpoint)",
    ("GET", "/api/v1/sub-stock/replenishment-targets"): "",
    ("GET", "/api/v1/sub-stock/requests"): "list only; create/approve/execute/receive/cancel/reject are mutation domain and not ported",
    # --- checkpoint H: inventory mutations (behaviour + DB state compared in compare_inventory_mutations.py)
    ("POST", "/api/v1/stock/adjust"): "403 for every caller in warehouse-authoritative mode (the default) — identical to the reference; deviation register D-3",
    ("PATCH", "/api/v1/warehouse/settings/factory-plan"): "",
    ("GET", "/api/v1/warehouse/transfers"): "",
    ("POST", "/api/v1/warehouse/transfers"): "physical buckets only; factory_plan needs /transfers/plan-to-transit (not ported)",
    ("GET", "/api/v1/warehouse/transfers/{transfer}"): "",
    ("POST", "/api/v1/warehouse/transfers/{transfer}/approve"): "executes + writes the handover; canonical lock order preserved, verified by two-connection tests",
    ("POST", "/api/v1/warehouse/transfers/{transfer}/cancel"): "",
    ("POST", "/api/v1/warehouse/transfers/{transfer}/reject"): "",
    # --- checkpoint I: admin system configuration (compare_admin_config.py, 17 checks)
    ("GET", "/api/v1/admin/languages"): "admin listing: every language, active or not",
    ("POST", "/api/v1/admin/languages"): "",
    ("PATCH", "/api/v1/admin/languages/{language}"): "",
    ("PATCH", "/api/v1/admin/languages/{language}/set-default"): "exactly one default; the target must be active",
    ("PATCH", "/api/v1/admin/languages/{language}/toggle-active"): "the default language can never be deactivated",
    ("GET", "/api/v1/admin/settings"): "identical to the public read (single service in the reference)",
    ("PUT", "/api/v1/admin/settings"): "unknown keys ignored, JSON keys encoded, returns the resolved settings",
    # --- checkpoint J: opname + plan transfer + eligible owners (compare_inventory_mutations.py, 65 checks)
    ("POST", "/api/v1/warehouse/transfers/plan-to-transit"): "requires the factory plan to be enabled",
    ("GET", "/api/v1/warehouse/sub-locations/eligible-owners"): "active Sales-Kurir-Sub without a sub-location",
    ("GET", "/api/v1/warehouse/opnames"): "",
    ("POST", "/api/v1/warehouse/opnames"): "snapshots system_quantity + stock id + physical revision per target",
    ("GET", "/api/v1/warehouse/opnames/{opname}"): "",
    ("PATCH", "/api/v1/warehouse/opnames/{opname}/count"): "",
    ("POST", "/api/v1/warehouse/opnames/{opname}/submit"): "refuses while any item is uncounted",
    ("POST", "/api/v1/warehouse/opnames/{opname}/approve"): "writes the correction; 409 when the stock changed after counting (quantity OR revision); idempotent",
    ("POST", "/api/v1/warehouse/opnames/{opname}/cancel"): "",
    ("POST", "/api/v1/warehouse/opnames/{opname}/reject"): "",
    # --- checkpoint K: sub-location ownership
    ("POST", "/api/v1/warehouse/sub-locations"): "owner must be an active Sales-Kurir-Sub of the network that owns no other location",
    ("PATCH", "/api/v1/warehouse/sub-locations/{subLocation}"): "",
    ("POST", "/api/v1/warehouse/sub-locations/{subLocation}/assign-owner"): "unowned + active only; owner row locked before the location on create",
    # --- checkpoint M: promotions (vouchers + discounts)
    ("GET", "/api/v1/promo/vouchers"): "",
    ("POST", "/api/v1/promo/vouchers"): "code upper-cased/trimmed; super_admin must name the branch",
    ("PATCH", "/api/v1/promo/vouchers/{voucher}"): "",
    ("DELETE", "/api/v1/promo/vouchers/{voucher}"): "",
    ("GET", "/api/v1/promo/discounts"): "",
    ("POST", "/api/v1/promo/discounts"): "a discount MUST name a product; a variation must belong to it (audit A1-17)",
    ("PATCH", "/api/v1/promo/discounts/{discount}"): "",
    ("DELETE", "/api/v1/promo/discounts/{discount}"): "",
    # --- checkpoint L: konsumen address book
    ("GET", "/api/v1/addresses"): "own rows only; list ordering differs (deviation D-10)",
    ("POST", "/api/v1/addresses"): "",
    ("PATCH", "/api/v1/addresses/{address}"): "promotes the default correctly where the reference leaves none (deviation D-9)",
    ("DELETE", "/api/v1/addresses/{address}"): "a foreign id is a 404",
    ("POST", "/api/v1/warehouse/sub-locations/{subLocation}/deactivate"): "refused while physical stock remains; releases the owner and remembers them in previous_owner_user_id",
}


# Routes whose OWN behaviour is incomplete. This is deliberately a separate, explicit set: a Notes entry alone
# (e.g. "admin listing: every language, active or not") documents a COST or a deviation, not a gap — marking
# every annotated route PARTIAL made the status column meaningless and produced two different published counts
# (the matrix said 31 PARTIAL while the execution state said 6). Anything here must name the missing behaviour
# in its note.
GAPS = {
    # Copilot Domain E/F surface — served, but only the listed reads + RBAC probes were
    # differentially verified; every write path still needs its contract comparison.
    ("PUT", "/api/v1/agent/store-profile"): "served; write contract probe pending",
    ("GET", "/api/v1/agent/whatsapp/scope"): "served; contract probe pending",
    ("GET", "/api/v1/agent/whatsapp/events"): "served; contract probe pending",
    ("PUT", "/api/v1/agent/whatsapp/events/{event}"): "served; write contract probe pending",
    ("DELETE", "/api/v1/agent/whatsapp/events/{event}/template"): "served; write contract probe pending",
    ("GET", "/api/v1/agent/whatsapp/test-numbers"): "served; contract probe pending",
    ("POST", "/api/v1/agent/whatsapp/test-numbers"): "served; write contract probe pending",
    ("DELETE", "/api/v1/agent/whatsapp/test-numbers/{id}"): "served; write contract probe pending",
    ("PATCH", "/api/v1/profile"): "served; write contract probe pending",
    ("GET", "/api/v1/profile/email/verification"): "served; contract probe pending",
    ("PATCH", "/api/v1/profile/referral-code"): "served; write contract probe pending",
    ("POST", "/api/v1/profile/referral-code/regenerate"): "served; write contract probe pending",
    ("DELETE", "/api/v1/profile/referral-code"): "served; write contract probe pending",
    ("GET", "/api/v1/cms/homepage-blocks"): "served; contract probe pending",
    ("POST", "/api/v1/cms/homepage-blocks"): "served; HTMLPurifier policy NOT ported — write paths must not be treated as parity-complete",
    ("PATCH", "/api/v1/cms/homepage-blocks/reorder"): "served; HTMLPurifier policy NOT ported",
    ("PATCH", "/api/v1/cms/homepage-blocks/{block}"): "served; HTMLPurifier policy NOT ported",
    ("PATCH", "/api/v1/cms/homepage-blocks/{block}/toggle"): "served; HTMLPurifier policy NOT ported",
    ("DELETE", "/api/v1/cms/homepage-blocks/{block}"): "served; HTMLPurifier policy NOT ported",
    ("POST", "/api/v1/cms/articles"): "served; HTMLPurifier policy NOT ported",
    ("PATCH", "/api/v1/cms/articles/{article}"): "served; HTMLPurifier policy NOT ported",
    ("DELETE", "/api/v1/cms/articles/{article}"): "served; HTMLPurifier policy NOT ported",
    ("POST", "/api/v1/products"): "served; write contract probe pending",
    ("GET", "/api/v1/manage/products"): "served; contract probe pending",
    ("GET", "/api/v1/manage/products/{product}"): "served; contract probe pending",
    ("PATCH", "/api/v1/products/{product}"): "served; write contract probe pending",
    ("DELETE", "/api/v1/products/{product}"): "served; write contract probe pending",
    ("DELETE", "/api/v1/products/{id}/permanent"): "served; write contract probe pending",
    ("POST", "/api/v1/products/{product}/images"): "served; write contract probe pending",
    ("PUT", "/api/v1/products/{product}/images/order"): "served; write contract probe pending",
    ("PATCH", "/api/v1/products/{product}/images/{image}"): "served; write contract probe pending",
    ("DELETE", "/api/v1/products/{product}/images/{image}"): "served; write contract probe pending",
    ("POST", "/api/v1/products/{product}/variations"): "served; write contract probe pending",
    ("PATCH", "/api/v1/products/{product}/variations/{variation}"): "served; write contract probe pending",
    ("DELETE", "/api/v1/products/{product}/variations/{variation}"): "served; write contract probe pending",
    ("GET", "/api/v1/products/{product}/fees"): "served; contract probe pending",
    ("PUT", "/api/v1/products/{product}/fees"): "served; write contract probe pending",
    ("GET", "/api/v1/products/{product}/variations/{variation}/fees"): "served; contract probe pending",
    ("PUT", "/api/v1/products/{product}/variations/{variation}/fees"): "served; write contract probe pending",
    ("GET", "/api/v1/referral/{code}"): "served; contract probe pending",
    ("GET", "/api/v1/crm/customers/{customer}"): "served; only index verified",
    ("GET", "/api/v1/crm/customers/{customer}/visits"): "served; only index verified",
    ("GET", "/api/v1/crm/follow-ups"): "served; only index verified",
    ("GET", "/api/v1/commissions"): "served; only empty-branch shapes + RBAC verified (needs a data-backed differential run)",
    ("GET", "/api/v1/commissions/summary"): "served; only empty-branch shapes + RBAC verified (needs a data-backed differential run)",
    ("GET", "/api/v1/reports/transactions"): "served; only empty-branch shapes + RBAC verified (needs a data-backed differential run)",

    ("POST", "/api/v1/auth/register"): "welcome e-mail / WA notification (queue bridge) not emitted",
    ("GET", "/api/v1/homepage"): "authenticated bestseller ranking not ported",
    ("GET", "/api/v1/products"): "authenticated agent/Sub stock availability not ported",
    ("GET", "/api/v1/products/{slug}"): "authenticated agent/Sub stock availability not ported",
    ("GET", "/api/v1/sub-stock/my"): "`reserved` not yet derived from order_items",
    ("POST", "/api/v1/warehouse/opnames"): "pending_catalog_change publication not triggered",
}

AREA_TITLES = OrderedDict([
    ("auth", "Identity & session"),
    ("profile", "Profile"),
    ("install", "Installer"),
    ("products", "Catalog — products"),
    ("categories", "Catalog — categories"),
    ("bestsellers", "Catalog — bestsellers"),
    ("manage", "Catalog — management"),
    ("migrate", "Catalog — legacy data"),
    ("articles", "CMS — articles"),
    ("pages", "CMS — pages"),
    ("homepage", "CMS — homepage"),
    ("cms", "CMS — admin"),
    ("media", "Media"),
    ("languages", "Site configuration — languages"),
    ("settings", "Site configuration — website"),
    ("agents", "Agent directory"),
    ("regions", "Regions"),
    ("referral", "Referral"),
    ("orders", "Orders"),
    ("order-items", "Orders — item receipts"),
    ("checkout", "Checkout"),
    ("stock", "Inventory — agent"),
    ("sub-stock", "Inventory — sub"),
    ("warehouse", "Inventory — warehouse"),
    ("sub-stock", "Inventory — sub stock"),
    ("warehouse-stock", "Inventory — warehouse stock"),
    ("shipments", "Shipping & delivery"),
    ("kurir", "Courier"),
    ("dispatch", "Dispatch"),
    ("returns", "Returns"),
    ("return-items", "Returns — items"),
    ("payment-proofs", "Payments — proofs"),
    ("promo", "Promotions"),
    ("fee", "Commissions & fees"),
    ("invoice", "Invoices"),
    ("refund", "Refunds"),
    ("admin", "Admin operations"),
    ("reports", "Reporting"),
    ("crm", "CRM"),
    ("consent", "Consent"),
    ("notifications", "Notifications"),
    ("users", "User management"),
    ("google-sheets", "Integrations — Google Sheets"),
])


def area_of(uri):
    parts = [p for p in uri.split("/") if p]
    return parts[2] if len(parts) >= 3 else "(root)"


def main():
    routes = json.load(open(ROUTES))
    by_area = OrderedDict()
    for r in routes:
        by_area.setdefault(area_of(r["uri"]), []).append(r)

    total = len(routes)
    implemented = 0

    print("# IMP-010 — Complete API parity matrix\n")
    print("Generated from `go-backend/internal/api/parity_routes.json` "
          "(375 `/api/v1` routes on the IMP-008/IMP-009 baseline, REM-11 excluded) by "
          "`tools/parity/gen_matrix.py`. **Do not edit by hand.**\n")
    print("Status legend:\n")
    print("- **GO** — served by the Go service, contract-compared against Laravel (`tools/parity/compare_*.py`).")
    print("- **PARTIAL** — served, but the route's own behaviour is still incomplete (see the note). A note "
          "alone does NOT make a route partial: informational notes and documented deviations stay **GO**.")
    print("- **LARAVEL** — still served by Laravel only; the Go service answers `501 not_implemented`.\n")

    for area, items in by_area.items():
        items.sort(key=lambda r: (r["uri"], r["method"]))
        title = AREA_TITLES.get(area, area)
        go = sum(1 for r in items if (r["method"], r["uri"]) in IMPLEMENTED)
        implemented += go
        print(f"## {title} — `{area}` ({go}/{len(items)} in Go)\n")
        print("| Method | Path | Auth | Roles | Go status | Notes |")
        print("|---|---|---|---|---|---|")
        for r in items:
            key = (r["method"], r["uri"])
            if key in IMPLEMENTED:
                note = IMPLEMENTED[key]
                status = "**PARTIAL**" if key in GAPS else "**GO**"
            else:
                note = ""
                status = "LARAVEL"
            roles = ", ".join(r["roles"]) if r["roles"] else "—"
            auth = "session" if r["auth"] else "public"
            print(f"| {r['method']} | `{r['uri']}` | {auth} | {roles} | {status} | {note} |")
        print()

    pct = implemented / total * 100
    print("## Totals\n")
    print(f"| Metric | Value |\n|---|---|")
    print(f"| Laravel `/api/v1` routes on this baseline | {total} |")
    partially = sum(1 for k in GAPS if k in IMPLEMENTED)
    print(f"| Served by Go now | {implemented} ({pct:.1f}%) |")
    print(f"|   of which fully GO | {implemented - partially} |")
    print(f"|   of which PARTIAL (incomplete behaviour) | {partially} |")
    print(f"| Still Laravel-only | {total - implemented} |")
    print(f"| Areas fully ported | "
          f"{sum(1 for a, i in by_area.items() if all((r['method'], r['uri']) in IMPLEMENTED for r in i))}"
          f" / {len(by_area)} |")


if __name__ == "__main__":
    sys.exit(main())
