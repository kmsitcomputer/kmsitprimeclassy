<?php

namespace App\Http\Controllers\Api\V1\Courier;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRefusal;
use App\Services\Order\DeliveryRefusalService;
use Illuminate\Http\Request;

/** REM-003 — reverse custody of refused goods: courier hand-back, Koordinator receipt + disposition. */
class DeliveryRefusalController extends Controller
{
    public function __construct(private readonly DeliveryRefusalService $refusals) {}

    private function payload(DeliveryRefusal $r): array
    {
        return ['id' => $r->id, 'status' => $r->status, 'quantity' => $r->quantity, 'order_item_id' => $r->order_item_id, 'shipment_id' => $r->shipment_id];
    }

    public function handBack(Request $request, DeliveryRefusal $refusal)
    {
        return $this->ok($this->payload($this->refusals->handBack($refusal, $request->user())));
    }

    public function receive(Request $request, DeliveryRefusal $refusal)
    {
        return $this->ok($this->payload($this->refusals->receive($refusal, $request->user())));
    }

    public function dispose(Request $request, DeliveryRefusal $refusal)
    {
        $data = $request->validate(['action' => ['required', 'in:redelivery,return_to_warehouse'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->ok($this->payload($this->refusals->dispose($refusal, $request->user(), $data['action'], $data['note'] ?? null)));
    }
}
