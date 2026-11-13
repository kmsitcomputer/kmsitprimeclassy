<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Controller;
use App\Models\ReturnItem;
use App\Services\Order\ReturnReceiptAllocationService;
use Illuminate\Http\Request;

/** REM-07: Admin (own Agent) / Super Admin records which receipt events an accepted return came from. */
class ReturnReceiptAllocationController extends Controller
{
    public function __construct(private readonly ReturnReceiptAllocationService $service) {}

    public function store(Request $request, ReturnItem $returnItem)
    {
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:50'],
            'allocations.*.receipt_id' => ['required', 'integer'],
            'allocations.*.quantity' => ['required', 'integer', 'min:1'],
            'evidence_note' => ['nullable', 'string', 'max:500'],
        ]);
        $rows = $this->service->record($returnItem, $request->user(), $data['allocations'], $data['evidence_note'] ?? null);

        return $this->created(array_map(fn ($r) => ['receipt_id' => $r->order_item_receipt_id, 'quantity' => $r->quantity], $rows));
    }
}
