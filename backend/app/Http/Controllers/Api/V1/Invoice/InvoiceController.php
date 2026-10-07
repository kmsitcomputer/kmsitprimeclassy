<?php

namespace App\Http\Controllers\Api\V1\Invoice;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoice\InvoiceConfigService;
use App\Services\Invoice\InvoicePdfService;
use Illuminate\Http\Request;

/**
 * IMP-002 — dynamic invoice view/download.
 *
 * Authorization: the order owner (konsumen) or same-branch
 * super_admin/agen/admin/keuangan may view the invoice PDF. Uses OrderPolicy
 * via the generic Order authorization so cross-agent/unauthorized access is
 * rejected server-side. Reprints use the latest InvoiceConfig resolution.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoicePdfService $pdfService,
    ) {}

    public function show(Request $request, Order $order)
    {
        // Same-agent enforcement via the canonical Order policy (the global
        // BelongsToAgentScope already scopes the model; the policy adds the
        // role/ownership check).
        $this->authorize('view', $order);

        // The invoice is a FINANCIAL document — narrower than OrderPolicy::view.
        // Operational roles that can view an order (e.g. koordinator-kurir) must
        // never download the invoice PDF; the owner (konsumen) + financial
        // roles only.
        if (! $request->user()->isRole('super_admin', 'agen', 'admin', 'keuangan', 'konsumen')) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }
        $isOwner = $request->user()->isRole('konsumen');
        if ($isOwner && (int) $order->konsumen_id !== (int) $request->user()->id) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        $agentId = (int) ($order->agent_id);
        $config = InvoiceConfigService::resolved($agentId);

        // A1-13: a consumer PDF must never carry internal referral identities,
        // even if the branch toggles show_sales/show_korsal on.
        return $this->pdfService->render($order, $config, viewerIsOwner: $isOwner)->stream('invoice-'.$order->order_no.'.pdf');
    }
}