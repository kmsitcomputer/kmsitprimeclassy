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
 * Authorization: OrderPolicy::viewInvoice — the Pemesan, same-branch super_admin/agen/admin/keuangan, and the
 * Sales/Korsal whose scope the order is in may view the invoice PDF. Uses OrderPolicy
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

        // The invoice is a FINANCIAL document — narrower than OrderPolicy::view (see OrderPolicy::viewInvoice):
        // the Pemesan, same-branch financial roles, and the Sales/Korsal whose referral scope the order is in.
        // Operational roles that can view an order (e.g. koordinator-kurir) never get the PDF.
        $this->authorize('viewInvoice', $order);
        // Pemesan = the order's own buyer; their copy never carries internal referral identities.
        $isOwner = (int) $order->konsumen_id === (int) $request->user()->id;

        // Owner-locked policy: no invoice before it is issued (COD at creation, transfer after the first
        // verified payment). Legacy eligible orders are stamped lazily; the number is always orders.order_no.
        if (! app(\App\Services\Invoice\InvoiceIssuanceService::class)->ensureIssuedIfEligible($order)) {
            throw new ApiException(__('messages.payment.invoice_not_issued'), 409);
        }

        $agentId = (int) ($order->agent_id);
        $config = InvoiceConfigService::resolved($agentId);

        // A1-13: a consumer PDF must never carry internal referral identities,
        // even if the branch toggles show_sales/show_korsal on.
        return $this->pdfService->render($order, $config, viewerIsOwner: $isOwner)->stream('invoice-'.$order->order_no.'.pdf');
    }
}