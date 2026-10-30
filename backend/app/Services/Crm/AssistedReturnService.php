<?php

namespace App\Services\Crm;

use App\Exceptions\ApiException;
use App\Models\ConsentEvent;
use App\Models\CrmCustomer;
use App\Models\Order;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Consent\ConsentAccess;
use App\Services\Consent\ConsentService;
use App\Services\Order\ReturnService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * OD-02 / WS-9 gate 7: the owning Sales/SKS may INITIATE a return for a CRM-origin order — and only inside
 * the item/quantity scope of a currently valid consent. Nothing else changes: the request enters the
 * existing return flow unchanged (review by super_admin/agen/admin, inspection by gudang, refund marking
 * by keuangan/super_admin) and refund money still needs the recipient evidence guard (G17-D4).
 */
class AssistedReturnService
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly ConsentService $consent,
        private readonly ConsentAccess $access,
    ) {}

    /** @param  array<int, array{order_item_id:int, quantity:int, restock?:bool}>  $items */
    public function initiate(User $actor, Order $order, ConsentEvent $root, array $items, string $reason, UploadedFile $evidence): ReturnRequest
    {
        if (! $order->isCrmOrigin()) {
            throw new ApiException(__('messages.crm.not_crm_order'), 422);
        }

        $customer = CrmCustomer::withoutGlobalScopes()->findOrFail($order->crm_customer_id);
        if (! $this->access->canCaptureCrm($actor, $customer)) {
            throw new ApiException(__('messages.system.unauthorized_action'), 403);
        }

        return DB::transaction(function () use ($actor, $order, $root, $items, $reason, $evidence, $customer) {
            // Serialize concurrent initiations that draw on the same consent quota.
            DB::table('consent_events')->where('id', $root->id)->lockForUpdate()->first();

            $valid = $root->root_event_id === null
                && $root->event_type === ConsentEvent::CAPTURED
                && $root->crm_customer_id === $customer->id
                && $root->order_id === $order->id
                && $root->purpose === 'assisted_return'
                && in_array($this->consent->currentState($root), [ConsentEvent::CAPTURED, ConsentEvent::REINSTATED], true);

            if (! $valid) {
                throw new ApiException(__('messages.crm.consent_required'), 422);
            }

            $granted = collect($root->scope ?? [])->pluck('quantity', 'order_item_id');
            $used = ReturnItem::query()
                ->join('returns', 'returns.id', '=', 'return_items.return_id')
                ->where('returns.consent_event_id', $root->id)
                ->where('return_items.status', '!=', 'rejected')
                ->selectRaw('return_items.order_item_id, SUM(return_items.quantity_returned) as qty')
                ->groupBy('return_items.order_item_id')
                ->pluck('qty', 'order_item_id');

            foreach ($items as $line) {
                $remaining = (int) ($granted[$line['order_item_id']] ?? 0) - (int) ($used[$line['order_item_id']] ?? 0);
                if ((int) $line['quantity'] > $remaining) {
                    throw new ApiException(__('messages.crm.consent_scope_exceeded'), 422, [
                        'items' => [__('messages.crm.consent_scope_exceeded')],
                    ]);
                }
            }

            $return = $this->returns->requestReturn($order, $actor, $items, $reason, $evidence);
            $return->forceFill(['consent_event_id' => $root->id])->save();

            return $return->fresh();
        });
    }
}
