<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\OrderItemReceipt;
use App\Services\Order\OrderItemReceiptService;
use Illuminate\Http\Request;

/** REM-07: per-item actual receipt events. Admin (own Agent) / Super Admin only. */
class OrderItemReceiptController extends Controller
{
    public function __construct(private readonly OrderItemReceiptService $receipts) {}

    public function index(Request $request, OrderItem $item)
    {
        $this->authorizeScope($request, $item);

        return $this->ok(OrderItemReceipt::query()->where('order_item_id', $item->id)->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'order_item_id' => $r->order_item_id, 'quantity' => $r->quantity,
            'received_on' => $r->received_on->toDateString(), 'received_at' => $r->received_at?->toIso8601String(),
            'verified_by' => $r->verified_by, 'verified_at' => $r->verified_at->toIso8601String(),
        ])->all());
    }

    public function store(Request $request, OrderItem $item)
    {
        $key = (string) $request->header('Idempotency-Key');
        if (strlen($key) < 8 || strlen($key) > 100) {
            throw new ApiException('Header Idempotency-Key (8–100 karakter) wajib.', 422);
        }
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date'],
            'evidence_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'evidence_note' => ['nullable', 'string', 'max:500'],
        ]);

        $receipt = $this->receipts->record(
            $item, $request->user(), (int) $data['quantity'], $data['received_on'], $data['received_at'] ?? null,
            $data['evidence_media_id'] ?? null, $data['evidence_note'] ?? null, $key
        );

        return $this->created(['id' => $receipt->id, 'quantity' => $receipt->quantity, 'received_on' => $receipt->received_on->toDateString()]);
    }

    private function authorizeScope(Request $request, OrderItem $item): void
    {
        $user = $request->user();
        $agentId = \App\Models\Order::withoutGlobalScopes()->whereKey($item->order_id)->value('agent_id');
        abort_unless($user->isRole('super_admin') || ($user->isRole('admin') && (int) $agentId === (int) $user->agent_id), 403);
    }
}
