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
    ("GET", "/api/v1/orders/{order}/invoice"): "OrderPolicy view+viewInvoice audience, lazy locked invoice issuance, viewer-safe PDF projection and dynamic branch/global configuration; compare_order_invoice.py",
    ("GET", "/api/v1/admin/additional-payments"): "Branch-scoped operational/finance oversight list, current buyer/requester names, manual payment instructions, latest-first pagination; compare_admin_additional_payments.py",
    ("GET", "/api/v1/admin/order-refunds"): "Branch-scoped fulfillment-shortfall refunds, current buyer/adjuster names, item snapshots, decimal refund amount and status, latest-first pagination; compare_admin_order_refunds.py",
    ("PATCH", "/api/v1/admin/order-refunds/{adjustment}/status"): "Keuangan/Super Admin pending-only status update with Order-first lock, CRM refund-recipient guard, ledger legacy settlement or payment reversal/invoice snapshot, branch scope and audit; compare_admin_order_refund_status.py",
    ("POST", "/api/v1/admin/order-sales-reductions/{reduction}/reinstate"): "Owner-only order-scoped reinstatement with evidence validation, order/reduction locks, in-flight and financial-bound checks, durable idempotent refund-ledger event, preserved replay response; compare_admin_reinstate_reduction.py",
    ("POST", "/api/v1/admin/orders/{order}/cancellation/finalize"): "Finance-only financial finalization under Order lock, defer-on-in-flight, supersedable forward-obligation closure, available paid refund obligation creation and append-only ledger audit; compare_admin_cancellation_finalize.py",
    ("POST", "/api/v1/admin/refund-obligations/{obligation}/approve"): "Branch owner/Super Admin pending-only approval, owner authorization, idempotent approved replay and unique append-only ledger event; compare_admin_refund_obligation_approve.py",
    ("POST", "/api/v1/admin/refund-obligations/{obligation}/attempts"): "Finance-only attempt creation with CRM recipient evidence guard, Order/obligation locking, scoped key replay/collision protection, reservation arithmetic and durable attempt-created event; compare_admin_refund_attempt_create.py",
    ("POST", "/api/v1/admin/refund-attempts/{attempt}/initiate"): "Finance-only created-to-processing transition, Order-first locking, external reference preservation on replay, initiated timestamps and idempotent append-only audit; compare_admin_refund_attempt_initiate.py",
    ("POST", "/api/v1/admin/refund-attempts/{attempt}/cancel"): "Finance-only created-attempt cancellation window, required reason, finished timestamp and append-only audit; compare_admin_refund_attempt_terminals.py",
    ("POST", "/api/v1/admin/refund-attempts/{attempt}/unknown"): "Finance-only processing-to-unknown transition, optional note, replay preservation, unknown_since and durable audit; compare_admin_refund_attempt_terminals.py",
    ("POST", "/api/v1/admin/refund-attempts/{attempt}/fail"): "Finance-only processing-to-failed transition, validated failure details, preserved replay, attempt audit and cancelled-order finalization re-entry; compare_admin_refund_attempt_fail.py",
    ("POST", "/api/v1/admin/refund-attempts/{attempt}/complete"): "Finance-only processing-to-succeeded transition, self-exclusion reservation, payout + paid-amount effect, idempotent replay and durable audit; compare_admin_refund_attempt_complete.py",
    ("POST", "/api/v1/admin/refund-attempts/{attempt}/reconcile"): "Finance reconciliation of unknown attempts to succeeded payout or failed state, evidence, audit and cancellation re-entry in one transaction; compare_admin_refund_attempt_reconcile.py",
    ("GET", "/api/v1/invoice/config"): "Super Admin global or Agen branch-scoped resolved invoice configuration with built-in defaults; compare_invoice_config.py",
    ("PUT", "/api/v1/invoice/config"): "Agen own-scope or Super Admin global display settings, Laravel validation, media existence and defaults resolution; compare_invoice_config.py",
    ("GET", "/api/v1/dashboard/summary"): "Role/branch scoped order, revenue, customer and roster totals with date filters; compare_dashboard_summary.py",
    ("GET", "/api/v1/dashboard/kpis"): "Role-shaped own/team commission, attributed deals, follow-up, canonical stock and courier task KPIs; compare_dashboard_kpis.py",
    ("GET", "/api/v1/bestsellers"): "Agent-resolved receipt-based product ranking with accepted-return deductions, unresolved-allocation exclusion, WIB date windows and hidden metadata; compare_bestsellers.py",
    ("GET", "/api/v1/catalog/export"): "Product and variation CSV/XLSX export, formula-safe cells, explicit single-Agent stock context and Sub-location ownership; compare_catalog_export.py",
    ("GET", "/api/v1/agent/whatsapp/messages"): "One branch Agent's latest 50 delivery rows, masked destination and no message body; compare_agent_whatsapp_messages.py",
    ("GET", "/api/v1/profile/whatsapp"): "Self-only masked phone, derived verification proof, active challenge and WhatsApp OTP delivery availability; compare_profile_whatsapp.py",
    ("GET", "/api/v1/agent/whatsapp"): "Scoped Agent device status refresh, masked pairing state and gateway readiness; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/device"): "Idempotent one-device-per-Agent GOWA slot creation, ownership audit and refreshed masked status; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/logout"): "Agent-owned slot logout, persistent slot state transition and actor/target audit; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/reconnect"): "Agent-owned GOWA reconnect, refreshed connection state and actor/target audit; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/replace"): "Accepted-confirmation guard, create-before-delete slot replacement and replacement audit; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/pair/code"): "Validated MSISDN pairing code request, pairing state and audit without storing the full number; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/pair/qr"): "QR pairing with server-fetched allow-listed PNG path, data URI response and pairing state audit; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/agent/whatsapp/test-send"): "Allow-listed Agent test message with per-Agent limits, paired-device enforcement, sanitized payload, durable ledger, delivery attempt and audit; compare_agent_whatsapp_status.py",
    ("POST", "/api/v1/admin/refund-obligations/{obligation}/needs-verification/resolve"): "Finance/Owner exits A/B with historical-owner enforcement, evidence, idempotent no-op, state audit and branch authorization; compare_admin_refund_needs_verification.py",
    ("POST", "/api/v1/admin/refund-obligations/{obligation}/deferred/request"): "Finance deferred materialization request, Order/obligation locks, idempotent event key and available-entitlement snapshot; compare_admin_refund_deferred.py",
    ("POST", "/api/v1/admin/refund-obligations/{obligation}/deferred/materialize"): "Owner-only deferred entitlement materialization with replay-first idempotency, cash cap, child approval, parent balance update and append-only audit; compare_admin_refund_deferred.py",
    ("PATCH", "/api/v1/admin/additional-payments/{payment}/status"): "Keuangan/Super Admin pending-only settlement; proof→order→transaction lock order, transfer verification, COD and transfer balances, invoice snapshots, audit and stale replay; compare_admin_additional_payment_status.py",
    ("GET", "/api/v1/admin/shipping-providers"): "Super Admin global provider list limited to supported codes, id order, no per-Agent secrets; compare_admin_shipping_providers.py",
    ("GET", "/api/v1/admin/shipping-couriers"): "Super Admin provider-supported courier master list ordered by name; compare_admin_shipping_couriers.py",
    ("POST", "/api/v1/admin/shipping-couriers"): "Super Admin courier create with lowercase code, regex/max/unique validation and audit; compare_admin_shipping_couriers.py",
    ("PATCH", "/api/v1/admin/shipping-couriers/{shippingCourier}/toggle"): "Super Admin courier active-state toggle and audit; compare_admin_shipping_couriers.py",
    ("PATCH", "/api/v1/admin/shipping-providers/{provider}/toggle"): "Super Admin global active toggle, same response and audited state; compare_admin_shipping_providers.py",
    ("GET", "/api/v1/admin/google-auth"): "Super Admin global Google OAuth settings; secret is never returned; compare_google_auth_settings.py",
    ("PUT", "/api/v1/admin/google-auth"): "Global Google OAuth config validation, Laravel-compatible secret preservation/clear, encrypted storage, audit; compare_google_auth_settings.py",
    ("GET", "/api/v1/agent/google-auth"): "Agent/Admin own-branch Google OAuth config; no cross-branch identifier accepted, secret masked; compare_google_auth_settings.py",
    ("PUT", "/api/v1/agent/google-auth"): "Agent/Admin own-branch config validation, encrypted secret preserve/clear, scoped audit; compare_google_auth_settings.py",
    ("GET", "/api/v1/admin/integrations/whatsapp"): "Super Admin GOWA configuration snapshot, masked password/test numbers, event defaults and OTP/rate settings; compare_admin_whatsapp_settings.py",
    ("GET", "/api/v1/admin/integrations/whatsapp/messages"): "filtered Super Admin WhatsApp message ledger, masked recipients, retry flags and pagination; compare_admin_whatsapp_messages.py",
    ("POST", "/api/v1/admin/integrations/whatsapp/messages/{message}/retry"): "Super Admin failed/unknown retry, duplicate-risk gate, CAS, durable audit, Laravel-compatible primeclassy-notify database job containing only message ID, and gateway-disabled skip; compare_admin_whatsapp_retry.py + jobs payload test",
    ("POST", "/api/v1/admin/integrations/whatsapp/test-message"): "Super Admin allow-listed test send, normalized destination, safe template cleaning, synchronous GOWA delivery and ledger result; compare_admin_whatsapp_test_message.py uses a local stub",
    ("POST", "/api/v1/admin/integrations/whatsapp/test-connection"): "read-only authenticated GOWA status/device probe with state audit; compare_admin_whatsapp_test_connection.py uses a local stub",
    ("PUT", "/api/v1/admin/integrations/whatsapp/templates/{event}"): "Super Admin global message template validation, save, snapshot and audit; compare_admin_whatsapp_templates.py",
    ("DELETE", "/api/v1/admin/integrations/whatsapp/templates/{event}"): "idempotent Super Admin global template reset to Laravel default with before/after audit; compare_admin_whatsapp_templates.py",
    ("POST", "/api/v1/admin/integrations/whatsapp/templates/{event}/preview"): "Super Admin global template preview, token validation and optional-line rendering; compare_admin_whatsapp_preview.py",
    ("POST", "/api/v1/agent/whatsapp/events/{event}/preview"): "Agen/Admin branch-authorized template preview with shared validation and sample rendering; compare_admin_whatsapp_preview.py",
    ("GET", "/api/v1/admin/integrations/smtp"): "Super Admin SMTP snapshot with write-only password state and active environment/dashboard source; compare_admin_smtp_settings.py",
    ("PUT", "/api/v1/admin/integrations/smtp"): "validated SMTP config, encrypted write-only secret preserve/clear, completeness guard and redacted audit; compare_admin_smtp_update.py",
    ("POST", "/api/v1/admin/integrations/smtp/test-connection"): "global saved SMTP connect, encryption/auth negotiation and sanitized status only; compare_admin_smtp_test.py against a local SMTP server",
    ("POST", "/api/v1/admin/integrations/smtp/test-email"): "global SMTP test mail sent exclusively to the authenticated Super Admin address; compare_admin_smtp_test.py captures recipient using local SMTP server",
    ("GET", "/api/v1/admin/regions/export"): "Super Admin four-level joined reference export with Laravel CSV quoting, filename and UTF-8 content type; compare_admin_regions_csv.py",
    ("POST", "/api/v1/admin/regions/import"): "Super Admin multipart CSV import, validated hierarchy rows, one transaction, exact upsert counts and audit; compare_admin_regions_csv.py",
    ("GET", "/api/v1/admin/orders/{order}/refunds"): "Order-scoped refund obligations, attempts, payouts, children, reductions and integer-cent financial metrics; compare_admin_order_refund_ledger.py",
    ("PUT", "/api/v1/admin/integrations/whatsapp"): "Validated GOWA config, encrypted write-only password, normalized test-number allow-list, rate/OTP/event settings and redacted before/after audit; compare_admin_whatsapp_update.py",
    ("POST", "/api/v1/crm/customers/{customer}/orders"): "CRM assisted order through canonical order creation; origin-scoped idempotency, owning seller attribution, Agent stock only; compare_crm_assisted_order.py",
    ("POST", "/api/v1/orders/{order}/assisted-returns"): "CRM-origin only; owner Sales/SKS/Korsal, append-only consent current-state + item/quantity quota, public image evidence, transactional return items/order state/audit; compare_assisted_return.py",
    ("POST", "/api/v1/orders/{order}/returns"): "Konsumen own-order return request; required public image evidence, item delivery/quantity checks, transactional item/status/refund/audit updates; compare_return_request.py",
    ("POST", "/api/v1/return-items/{returnItem}/receipt-allocations"): "Admin own-Agent/Super Admin; approved inspected return only, exact quantity allocation across same-item receipts, capacity locks, one-shot identical replay, conflict on changed replay, audit; compare_return_receipt_allocations.py",
    ("GET", "/api/v1/admin/returns"): "Admin/Agen branch-scoped, Super Admin optional agent_id, status list filter, latest-first pagination, full ReturnRequestResource + pagination meta; compare_admin_returns.py",
    ("GET", "/api/v1/admin/returns/{return}"): "Super Admin or same-Agent Admin/Agen branch authorization; complete ReturnRequestResource, 404 for missing model and 403 for foreign branch; compare_admin_return_show.py",
    ("PATCH", "/api/v1/admin/returns/{return}/review"): "Admin/Agen branch scope, Laravel validation and localized responses, order-first review transaction, return state reversal/approval, frozen financial units, refund obligations and audit; compare_return_review.py",
    ("PATCH", "/api/v1/admin/returns/{return}/pickup-schedule"): "Admin/Agen branch scope, exact Y-m-d and WIB not-past validation, pending/approved item updates only, no-eligible-item error, response and activity audit; compare_return_pickup_schedule.py",
    ("PATCH", "/api/v1/admin/return-items/{item}/refund"): "Keuangan/Super Admin and same-Agent gates, approved/full-paid/CRM-recipient checks, idempotent legacy refund attempt and payout, paid balance/invoice reversal, refund completion, Sales-only fee adjustment and audit; compare_return_item_refund.py",
    ("GET", "/api/v1/dispatch/returns"): "Operational pickup queue, same-Agent and Super Admin agent_id validation, pending/approved return items whose order item is pengembalian, courier projection and capped pagination; compare_dispatch_returns.py",
    ("GET", "/api/v1/dispatch/history"): "Delivered shipment history, same-Agent and Super Admin agent_id validation, WIB date bounds, courier/region/order search filters, executor and proof projection, capped pagination; compare_dispatch_history.py",
    ("GET", "/api/v1/dispatch"): "Branch-scoped dispatchable standard shipments, matching order and shipment delivery-date/region/payment filters, per-shipment item/resource projection, stock fulfillment and paid-in-full signal; compare_dispatch_queue.py",
    ("POST", "/api/v1/orders/{order}/items"): "Admin-only SC-03; order-first locking, Agent reservation, canonical shipment grouping, Stock Request reopen/reconcile, additional-payment ledger and replay/conflict; compare_order_line_addition.py",
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
    ("PUT", "/api/v1/agent/store-profile"): "Laravel validation messages, decimal:7 response strings, ignored client ownership fields, activity old/new projection and owner-only scope compared by compare_agentadmin.py",
    ("GET", "/api/v1/agent/shipping-tariff"): "",
    ("PUT", "/api/v1/agent/shipping-tariff"): "pricing validation, Laravel response message, audit properties and resulting tariff row compared by compare_shipping_admin.py",
    ("GET", "/api/v1/agent/shipping-providers"): "",
    ("PATCH", "/api/v1/agent/shipping-providers/{provider}/toggle"): "five-key response projection, localized message, audit and setting row compared by compare_shipping_admin.py",
    ("PUT", "/api/v1/agent/shipping-providers/{provider}/config"): "provider-specific validation, SSRF refusal, encrypted config side effect and localized response compared by compare_shipping_admin.py",
    ("GET", "/api/v1/agent/shipping-providers/{provider}/couriers"): "",
    ("PUT", "/api/v1/agent/shipping-providers/{provider}/couriers"): "supported-courier validation, response, and encrypted config side effect compared by compare_shipping_admin.py",
    ("GET", "/api/v1/agent/whatsapp/scope"): "Agen self-scope, Admin branch-scope, denied roles, ignored client agent_id, and read-only DB state compared by compare_whatsapp_scope.py",
    ("GET", "/api/v1/agent/whatsapp/events"): "RBAC, agent_id selector, event ordering and full payload compared with database read-only evidence by compare_whatsapp_events.py",
    ("PUT", "/api/v1/agent/whatsapp/events/{event}"): "boolean/string/max validation, template token validation, role and agent scope, persisted settings and AgentWhatsAppEventSetting audit compared by compare_whatsapp_event_updates.py",
    ("DELETE", "/api/v1/agent/whatsapp/events/{event}/template"): "idempotent reset, no-op persistence, event validation, branch scope and conditional AgentWhatsAppEventSetting audit compared by compare_whatsapp_event_resets.py",
    ("GET", "/api/v1/agent/whatsapp/test-numbers"): "masked numbers, max limit, Agen/Admin scope, forbidden roles and agent_id selector compared with read-only DB state by compare_whatsapp_test_numbers.py",
    ("POST", "/api/v1/agent/whatsapp/test-numbers"): "Laravel accepted/required/max validation, MSISDN normalization, duplicate and 5-number cap behavior, branch scope, resulting rows and conditional audit compared by compare_whatsapp_test_number_add.py",
    ("DELETE", "/api/v1/agent/whatsapp/test-numbers/{id}"): "owned/missing/foreign row behavior, selector scope, resulting rows and conditional AgentWhatsAppTestNumber audit compared by compare_whatsapp_test_number_delete.py",
    ("PATCH", "/api/v1/profile"): "accumulated field validation, phone normalization, email verification reset, uniqueness, protected fields, self-scope, response and audit compared by compare_profile_update.py",
    ("GET", "/api/v1/profile/email/verification"): "masked current address, verified and active-pending state across roles, and read-only OTP state compared by compare_profile_email_verification_state.py",
    ("PATCH", "/api/v1/profile/referral-code"): "format/required/max/unique validation, success projection, self-scope and audit compared by compare_profile_referral.py",
    ("POST", "/api/v1/profile/referral-code/regenerate"): "generated role prefix/code format, response, DB update and audit compared by compare_profile_referral.py",
    ("DELETE", "/api/v1/profile/referral-code"): "response, cleared code, and old-only audit projection compared by compare_profile_referral.py",
    ("GET", "/api/v1/cms/homepage-blocks"): "resource projection, sort order, Super Admin gate, and read-only state compared by compare_cms_homepage_blocks.py",
    ("POST", "/api/v1/cms/homepage-blocks"): "JSON + multipart validation, HTML sanitization, persistence and upload side effects compared with Laravel",
    ("PATCH", "/api/v1/cms/homepage-blocks/reorder"): "ordering, partial and duplicate ID lists, ID coercion, per-item existence validation, RBAC, response and persisted sort_order compared with Laravel",
    ("PATCH", "/api/v1/cms/homepage-blocks/{block}"): "",
    ("PATCH", "/api/v1/cms/homepage-blocks/{block}/toggle"): "",
    ("DELETE", "/api/v1/cms/homepage-blocks/{block}"): "block and referenced image files, missing IDs, Super Admin gate and response compared with Laravel",
    ("GET", "/api/v1/cms/articles"): "",
    ("POST", "/api/v1/cms/articles"): "",
    ("PATCH", "/api/v1/cms/articles/{article}"): "",
    ("DELETE", "/api/v1/cms/articles/{article}"): "",
    ("GET", "/api/v1/cms/pages"): "",
    ("POST", "/api/v1/cms/pages"): "",
    ("PATCH", "/api/v1/cms/pages/{page}"): "",
    ("DELETE", "/api/v1/cms/pages/{page}"): "",
    ("POST", "/api/v1/products"): "store validation, SKU uniqueness, sanitizer, variation mode, response and persisted rows compared by compare_products.py",
    ("GET", "/api/v1/manage/products"): "status/category/search/pagination filters, RBAC and per-agent availability compared by compare_products.py",
    ("GET", "/api/v1/manage/products/{product}"): "simple/variation detail, stock projection, missing ID and RBAC compared by compare_products.py",
    ("PATCH", "/api/v1/products/{product}"): "partial updates, immutable variation mode, validation, sanitization, audit and persisted rows compared by compare_products.py",
    ("DELETE", "/api/v1/products/{product}"): "soft-delete behavior, authorization and resulting product state compared by compare_products.py",
    ("DELETE", "/api/v1/products/{id}/permanent"): "confirmation, guarded references and hard-delete behavior compared by compare_products.py",
    ("POST", "/api/v1/products/{product}/images"): "multipart upload, gallery scope, primary image and stored file compared by compare_products.py",
    ("PUT", "/api/v1/products/{product}/images/order"): "exact gallery membership, response order and persisted sort_order compared by compare_products.py",
    ("PATCH", "/api/v1/products/{product}/images/{image}"): "primary promotion limited to the image gallery compared by compare_products.py",
    ("DELETE", "/api/v1/products/{product}/images/{image}"): "primary promotion, row removal and uploaded-file cleanup compared by compare_products.py",
    ("POST", "/api/v1/products/{product}/variations"): "variation rules, SKU uniqueness, composition attributes and resulting rows compared by compare_products.py",
    ("PATCH", "/api/v1/products/{product}/variations/{variation}"): "partial variation update and persisted values compared by compare_products.py",
    ("DELETE", "/api/v1/products/{product}/variations/{variation}"): "variation deletion and persisted rows compared by compare_products.py",
    ("GET", "/api/v1/products/{product}/fees"): "role-scoped fee projection and response compared by compare_products.py",
    ("PUT", "/api/v1/products/{product}/fees"): "fee validation, authorization and persisted fee rows compared by compare_products.py",
    ("GET", "/api/v1/products/{product}/variations/{variation}/fees"): "variation fee projection and authorization compared by compare_products.py",
    ("PUT", "/api/v1/products/{product}/variations/{variation}/fees"): "variation fee validation, authorization and persisted fee rows compared by compare_products.py",
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
    ("GET", "/api/v1/products"): "anonymous and authenticated Agent/Sub stock projections, source authorization and filters compared by compare_products.py",
    ("GET", "/api/v1/products/{slug}"): "anonymous and authenticated Agent/Sub stock projections, source authorization, and missing slug compared by compare_products.py",
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
    ("GET", "/api/v1/warehouse/orders/diproses"): "Gudang branch queue with requested-order priority, no-courier/self-delivery exclusion, warehouse OrderResource projection and fulfillment-request overlay; compare_warehouse_orders.py",
    ("GET", "/api/v1/warehouse/orders/{order}/stock-request"): "same-branch warehouse-scope guard, StockRequestResource/product+variation projection and Transit/Shipping/reserved buckets; compare_warehouse_orders.py",
    ("GET", "/api/v1/warehouse/returns"): "approved and uninspected return lines only, same-branch queue, ReturnRequestResource projection and pagination; compare_warehouse_orders.py",
    ("POST", "/api/v1/warehouse/returns/{item}/inspect"): "Gudang same-branch validation and transactional received/good/damaged inspection update; compare_warehouse_orders.py",
    ("POST", "/api/v1/warehouse/returns/{item}/finalize"): "Admin same-branch idempotent disposition; good units restock Agent Transit or original active Sub Location with return_restock audit, damaged-only adds no stock; compare_warehouse_orders.py",
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
    # --- GLM Laravel-only integration (docs/21e; harnesses in tools/parity-glm/, isolated DB primeclassy_testing_glm)
    ("GET", "/api/v1/notifications/admin"): "per-recipient projection, pagination meta, unread filter, strict boolean rule; 97/0 compared by compare_notifications_admin.py",
    ("GET", "/api/v1/notifications/admin/unread-count"): "owned unread count; 97/0 compared by compare_notifications_admin.py",
    ("POST", "/api/v1/notifications/admin/read-all"): "idempotent; updated count; 97/0 compared by compare_notifications_admin.py",
    ("POST", "/api/v1/notifications/admin/{id}/read"): "own rows only, foreign/missing id is a 404, replay keeps read_at; 97/0 compared by compare_notifications_admin.py",
    ("GET", "/api/v1/media"): "super_admin-only route gate (narrower than the policy — verified), user_avatar excluded, collection filter, pagination; 73/0 compared by compare_media.py",
    ("POST", "/api/v1/media"): "FormRequest validation before MediaPolicy, MIME/extension sniffing, uuid storage, per-collection policy (user_avatar open to every role); 73/0 compared by compare_media.py",
    ("DELETE", "/api/v1/media/{media}"): "policy per collection, avatar owner-only delete, file cleanup, binding-miss 404; 73/0 compared by compare_media.py",
    ("GET", "/api/v1/admin/agents"): "AgentDirectoryResource projection ordered by name (set-compared), soft-delete excluded; 107/0 compared by compare_admin_agents.py",
    ("POST", "/api/v1/admin/agents"): "accumulated store validation, not_an_agent / profile_already_exists 422s, 201 + directory resource, agent_profile.created audit; 107/0 compared by compare_admin_agents.py",
    ("PATCH", "/api/v1/admin/agents/{agentProfile}"): "sometimes-field partial update under lock, decimal:7 strings, old/new audit properties; 107/0 compared by compare_admin_agents.py",
    ("DELETE", "/api/v1/admin/agents/{agentProfile}"): "profile delete with SUBJECT=acting-user audit and localized response; 107/0 compared by compare_admin_agents.py",
    ("PATCH", "/api/v1/admin/agents/{agent}/toggle-status"): "active<->inactive flip both directions, not_an_agent 422, binding-miss 404, status audit; 107/0 compared by compare_admin_agents.py",
    ("GET", "/api/v1/admin/audit-logs"): "super_admin paged audit trail compared by compare_reports.py (GLM reporting slice, 150/150)",
    ("GET", "/api/v1/reports/cancellations-refunds"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/couriers"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/couriers-per-agent"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/crm-visits"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/customers"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/fees/agent"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/fees/courier"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/fees/sales-korsal"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/finance-orders"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/finance-summary"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/korsal"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/my-customers"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/network-summary"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/network-transactions"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/payment-status"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("GET", "/api/v1/reports/sales"): "GLM reporting slice compared by compare_reports.py (150/150)",
    ("POST", "/api/v1/crm/customers"): "CRM write slice: validation, duplicate phone normalization, 201 + resource, audit; 36/0 compared by compare_crm_writes.py",
    ("PATCH", "/api/v1/crm/customers/{customer}"): "CRM write slice: partial update, no-change PATCH writes no audit row; 36/0 compared by compare_crm_writes.py",
    ("POST", "/api/v1/crm/customers/{customer}/archive"): "CRM write slice: archive/unarchive lifecycle + audit; 36/0 compared by compare_crm_writes.py",
    ("POST", "/api/v1/crm/customers/{customer}/visits"): "CRM write slice: visit validation (status set, date, plan_next_before rule) + audit; 36/0 compared by compare_crm_writes.py",
    ("PATCH", "/api/v1/crm/visits/{visit}"): "CRM write slice: visit update + audit; 36/0 compared by compare_crm_writes.py",
    ("GET", "/api/v1/crm/link-candidates"): "CRM linking: masked candidates, referral reach; 26/0 compared by compare_crm_link.py",
    ("POST", "/api/v1/crm/customers/{customer}/link-otp"): "CRM linking: OTP gates (unknown konsumen, unverified email, channel, rate limit), identity_otp_challenges row; 26/0 compared by compare_crm_link.py",
    ("POST", "/api/v1/crm/customers/{customer}/link"): "CRM linking: link consumption, double-link 409/422, link event + audit; 26/0 compared by compare_crm_link.py",
    ("POST", "/api/v1/crm/customers/{customer}/unlink"): "CRM linking: unlink scope + event + audit; 26/0 compared by compare_crm_link.py",
    ("POST", "/api/v1/crm/customers/{customer}/consent-otp"): "CRM consent: channel gates (unlinked 409, unverified email 422, whatsapp 409), challenge row; 17/0 compared by compare_crm_consent.py",
    ("POST", "/api/v1/crm/customers/{customer}/consent-events"): "CRM consent: multipart evidence capture, real evidence file + hash, key idempotency, consumed challenge 422; 17/0 compared by compare_crm_consent.py",
    # --- GLM Phase 5b: dispatch workspace reads (internal/dispatch, tools/parity/compare_dispatch.py)
    ("GET", "/api/v1/dispatch/couriers"): "branch active couriers, for_returns narrows to kurir, super_admin agent_id gate; compared by compare_dispatch.py",
    ("GET", "/api/v1/dispatch/regions"): "cascading region options from ELIGIBLE dispatch orders only, level composition; compared by compare_dispatch.py",
    ("GET", "/api/v1/dispatch/expeditions"): "expedition shipments + four readiness gates; koordinator-kurir only; compared by compare_dispatch.py",
    ("GET", "/api/v1/dispatch/refusals"): "open refusals branch projection + status filter; koordinator-kurir only; compared by compare_dispatch.py",
    ("POST", "/api/v1/delivery-refusals/{refusal}/hand-back"): "executor custody transfer, idempotent replay, audit; compared by compare_refusals.py (14/0)",
    ("POST", "/api/v1/delivery-refusals/{refusal}/receive"): "koordinator receipt, state machine handed_back→received, audit; compared by compare_refusals.py (14/0)",
    ("POST", "/api/v1/delivery-refusals/{refusal}/disposition"): "redelivery/return_to_warehouse: shipping→transit reversal, reservation restore, fresh shipment, aggregate resync; compared by compare_refusals.py (14/0)",
    ("PATCH", "/api/v1/shipments/{shipment}/status"): "bulk diproses→dikirim / dikirim→terkirim transition: executor authorization, refusal guard, handover timestamps, Sub consume, aggregate sync, commissions; compared by compare_shipment_status.py",
    ("PATCH", "/api/v1/shipments/{shipment}/items/{orderItem}/status"): "per-ITEM work-unit transition (LOCKED courier work-unit model): one item, siblings untouched; compared by compare_shipment_status.py",
    ("PATCH", "/api/v1/shipments/{shipment}/courier"): "CourierService::assignCourier: self_sub ban, coordinator-only, eligibility under Order→Shipment lock, SKS executor resolve, SC-03 gate, carve of wholly-unaccepted lines, idempotent replay; compared by compare_courier_assign.py (20/0)",
    ("GET", "/api/v1/shipments/{shipment}/eligible-couriers"): "per-shipment assignable couriers (branch kurir/koordinator profiles + eligible SKS executors), read-only; compared by compare_courier_assign.py (20/0)",
    ("PATCH", "/api/v1/shipments/{shipment}/expedition-handover"): "REM-003 carrier handover: provider gate (rajaongkir), carrier mismatch, idempotent replay, 409 tracking conflict, evidence media + provider_meta.handover, audit; multipart success via POST _method=PATCH spoofing (PHP does not parse multipart PATCH); compared by compare_handover_receipt.py (17/0)",
    ("GET", "/api/v1/shipments/{shipment}/receipt"): "thermal receipt (pre/post-pickup mode derived from shipped_at), printReceipt policy, read-only + shipment.receipt_printed audit (is_reprint); compared by compare_handover_receipt.py (17/0)",
    ("GET", "/api/v1/shipments/{shipment}/delivery-verifications"): "R-03 append-only verification history (admin/super_admin, same-agent policy → 404), DeliveryVerificationResource; compared by compare_delivery_verifications.py (20/0)",
    ("POST", "/api/v1/shipments/{shipment}/delivery-verifications"): "R-03 record outcome: Idempotency-Key required/≤100, outcome validation, not_delivered gate (delivered_at + item re-derive), append-only, idempotent replay, 409 conflict, audit; compared by compare_delivery_verifications.py (20/0)",
    ("POST", "/api/v1/dispatch/order-items/{orderItem}/split-batch"): "REM-002 batch split: koordinator gates, window/sub/provider/quantity gates, stock-request demand carve, fresh shipment, proportional courier-fee; compared by compare_dispatch_writes.py (19/0)",
    ("POST", "/api/v1/dispatch/orders/{order}/request-fulfillment"): "REM-001 coordinator fulfillment request: warehouse-scope gate, outstanding gate, coordinator_* columns, audit, StockRequestResource projection; compared by compare_dispatch_writes.py (19/0)",
    ("PATCH", "/api/v1/return-items/{item}/courier"): "return pickup courier assignment (never self-claimed): office/dispatch gates, already_reviewed gate, courier eligibility, idempotent replay, 409 stale-assignment with expected_courier_id, audit; compared by compare_return_assign.py (15/0)",
    ("POST", "/api/v1/consent/otp"): "REM-005 OTP issue: ConsentAccess capture scope, verified-email channel gate, per-subject issuance serialization + rate limit, HMAC code hash, invalidate prior live challenges; compared by compare_consent.py (26/0)",
    ("POST", "/api/v1/consent/otp/{challenge}/verify"): "OTP verify: issuing-actor binding, attempt counter persists on mismatch, expiry/lock states; compared by compare_consent.py (26/0)",
    ("POST", "/api/v1/consent-events"): "append-only consent capture: capture scope, order/subject binding, usable-challenge window (15 min, single-use), evidence sha256, scope validation, consent_key idempotency + 409 conflict; compared by compare_consent.py (26/0)",
    ("POST", "/api/v1/consent-events/{consentEvent}/withdraw"): "append-only chain withdraw (root-only, allowed-from captured/reinstated); compared by compare_consent.py (26/0)",
    ("POST", "/api/v1/consent-events/{consentEvent}/dispute"): "append-only chain dispute (allowed-from captured/reinstated/withdrawn); compared by compare_consent.py (26/0)",
    ("POST", "/api/v1/consent-events/{consentEvent}/reinstate"): "authorized review reinstate (agen/admin/super_admin, allowed-from disputed); compared by compare_consent.py (26/0)",
    ("GET", "/api/v1/orders/{order}/consent-events"): "consent history per order (order view policy), ConsentEventResource; compared by compare_consent.py (26/0)",
    ("GET", "/api/v1/orders/{order}/consent-context"): "capture panel context (subject id only to capturing actor; CRM mode + channel availability); compared by compare_consent.py (26/0)",
    ("POST", "/api/v1/shipments/{shipment}/items/{orderItem}/refuse"): "courier on-the-spot refusal: executor gates (policy-vs-service denial split), not_in_delivery, already_open, partial-refusal unit carving (fees/subtotals, stock-request demand move, sibling item), audit; compared by compare_item_refuse.py (15/0)",
    ("GET", "/api/v1/kurir/orders"): "assignment-only delivery queue (kurir/koordinator own profile standard shipments; SKS own self_sub creator-only + assigned standard), CourierOrderResource projection; compared by compare_kurir.py (17/0)",
    ("GET", "/api/v1/kurir/returns"): "assignment-only return queue (return_items.courier_id = own profile, pengembalian items); compared by compare_kurir.py (17/0)",
    ("GET", "/api/v1/kurir/returns/pickup-today"): "WIB business-date scheduled pickup count (branch + assignment gates); compared by compare_kurir.py (17/0)",
    ("PATCH", "/api/v1/kurir/returns/{item}/pickup"): "physical pickup claim: same-branch + assignment gates, condition_note, audit; pickup_scheduled_for Carbon datetime; compared by compare_kurir.py (17/0)",
    ("PATCH", "/api/v1/kurir/returns/{item}/confirm"): "pengembalian → kembali (received) or → terkirim with return rejected/not_required and returned_quantity reversal; audit; compared by compare_kurir.py (17/0)",
    ("GET", "/api/v1/kurir/reports/delivered"): "delivered report (terkirim items on own shipments, from/to date filters, own courier commission sum as fee_amount); compared by compare_kurir.py (17/0)",
    ("GET", "/api/v1/orders/{order}/refund-recipient"): "G17-D4 refund-recipient evidence index (destination full only for keuangan/super_admin, masked tail otherwise); compared by compare_recipient.py (13/0)",
    ("POST", "/api/v1/orders/{order}/refund-recipient"): "append-only recipient record: CRM-origin gate, canRecord authority, method validation, destination required for bank/ewallet, authorization (consent or evidence) gate, consent/return binding, supersedes chain, audit; compared by compare_recipient.py (13/0)",
    ("GET", "/api/v1/order-items/{item}/receipts"): "REM-07 per-item receipt events index (admin own-agent / super_admin), date + verifier projection; compared by compare_item_receipts.py (16/0)",
    ("POST", "/api/v1/order-items/{item}/receipts"): "REM-07 record actual receipt: Idempotency-Key 8–100, arrived-status gate, date window (not future/before order), time-date consistency, quantity bounded by fulfilled across all events, fingerprint replay/409; compared by compare_item_receipts.py (16/0)",
}


# Routes whose OWN behaviour is incomplete. This is deliberately a separate, explicit set: a Notes entry alone
# (e.g. "admin listing: every language, active or not") documents a COST or a deviation, not a gap — marking
# every annotated route PARTIAL made the status column meaningless and produced two different published counts
# (the matrix said 31 PARTIAL while the execution state said 6). Anything here must name the missing behaviour
# in its note.
GAPS = {}

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
