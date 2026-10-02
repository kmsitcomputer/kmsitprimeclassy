<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\DeliveryGroupInvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class DeliveryGroupInvoiceController extends Controller
{
    public function __invoke(Request $request, Order $order, string $deliveryDate, DeliveryGroupInvoiceService $invoices)
    {
        $this->authorize('view', $order);
        $invoice = $invoices->forDate($order, $deliveryDate);
        $filename = $order->order_no.'-'.$deliveryDate.'.pdf';

        return Pdf::loadView('pdf.delivery-group-invoice', $invoice)
            ->setPaper('a4')
            ->stream($filename);
    }
}