<?php

namespace App\Support;

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
        $capabilities = match ($roleSlug) {
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
            default => [],
        };

        if (in_array($roleSlug, ['super_admin', 'agen', 'admin'], true)) {
            $capabilities[] = 'sheets.manage';
        }

        foreach (HierarchyRules::ALLOWED_CREATIONS[$roleSlug] ?? [] as $creatable) {
            $capabilities[] = "users.create.{$creatable}";
        }

        return array_values(array_unique($capabilities));
    }
}
