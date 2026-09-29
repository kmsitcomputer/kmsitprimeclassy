<?php

namespace App\Support;

use App\Models\Role;

/**
 * Read-only capability hints for the frontend (nav visibility, showing/
 * hiding buttons) — never a security boundary. Every capability listed here
 * is independently re-checked server-side by a Middleware/Policy/Gate; this
 * map only saves the SPA from having to hardcode "which roles see which
 * menu item" on its own. Exposed via GET /api/v1/auth/me (see AuthController).
 */
class PermissionMap
{
    public static function forRole(string $roleSlug): array
    {
        $capabilities = match (Role::canonicalSlug($roleSlug)) {
            'super_admin' => [
                'system.config.manage', 'system.payment.manage', 'system.shipping.manage', 'system.cms.manage',
                'users.view.all', 'orders.view.all', 'orders.manage.status', 'orders.manage.payment',
                'orders.manage.fulfillment', 'orders.manage.shipment', 'orders.cancel',
            ],
            'agen' => [
                'users.view.network', 'stock.view.own', 'stock.manage',
                'orders.view.network', 'orders.manage.status', 'orders.manage.payment',
                'orders.manage.fulfillment', 'orders.manage.shipment', 'orders.cancel', 'orders.create',
            ],
            'korsal' => [
                'users.view.network', 'orders.view.network', 'orders.create',
            ],
            'sales' => [
                'users.view.network', 'orders.view.network', 'orders.create',
            ],
            'konsumen' => [
                'orders.view.own', 'orders.create',
            ],
            'admin' => [
                'users.view.network', 'stock.view.own', 'stock.manage',
                'orders.view.network', 'orders.manage.status',
                'orders.manage.fulfillment', 'orders.manage.shipment', 'orders.cancel',
            ],
            // KEUANGAN = financial operations only (separation of duties):
            // payment/DP/COD verification & settlement, refunds and additional
            // payments, financial reports. It deliberately has NO operational
            // capabilities (status/fulfillment/cancel) — those stay with ADMIN
            // — and never the system.* config capabilities.
            'keuangan' => [
                'users.view.network', 'orders.view.network',
                'finance.view', 'finance.payment.verify', 'finance.cod.settle',
                'finance.dp.verify', 'finance.dp.settle',
                'finance.refund.manage', 'finance.additional.manage', 'finance.report.view',
            ],
            'kurir' => [
                'orders.view.assigned', 'orders.manage.shipment',
            ],
            'gudang' => [
                'stock.view.own', 'orders.view.assigned',
            ],
            'sales-kurir-sub' => [
                'users.view.network', 'orders.create', 'orders.view.assigned', 'orders.manage.shipment',
            ],
            default => [],
        };

        if (in_array($roleSlug, ['super_admin', 'agen', 'admin'], true)) {
            $capabilities[] = 'sheets.manage';
        }

        // L-003: once the warehouse is the physical-stock authority, Agent/Admin
        // may no longer write quantity_on_hand directly — POST /stock/adjust is
        // denied by AdjustStockRequest::authorize() and StockService. This hint
        // is derived from that same cutover switch (config('warehouse.authoritative'))
        // rather than a second, independently settable flag, and only tells the
        // SPA whether to offer the legacy form; the server still enforces it.
        if (in_array($roleSlug, ['agen', 'admin'], true) && ! config('warehouse.authoritative')) {
            $capabilities[] = 'stock.adjust.legacy';
        }

        foreach (HierarchyRules::ALLOWED_CREATIONS[$roleSlug] ?? [] as $creatable) {
            $capabilities[] = "users.create.{$creatable}";
        }

        return array_values(array_unique($capabilities));
    }
}
