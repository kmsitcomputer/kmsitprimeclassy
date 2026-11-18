<?php

namespace App\Services\Catalog;

use App\Models\OrderItemReceipt;
use App\Models\Product;
use App\Models\ReturnItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * REM-07 / ODR-04 A / D-06 A.
 *
 * Units counted =  actual per-item receipt events
 *                  on orders that are FULLY PAID (canonical payment_status = paid, nothing remaining)
 *                  and not cancelled, owned by ONE Agent,
 *                  minus ACCEPTED physical returns (approved + inspected; quantity_received, good AND damaged),
 *                  summed by parent product (variants roll up), active saleable products only.
 *
 * Rules that are deliberately conservative (unknown ⇒ excluded, never guessed):
 *  - shipments.delivered_at / delivery_verifications / planned or handover dates are NEVER used as receipt.
 *  - Legacy items with no receipt event contribute nothing.
 *  - A return is deducted ONCE, from the receipt event it is attributed to: the only event of the item, or —
 *    with several events — the receipt event(s) explicitly recorded in return_item_receipt_allocations (evidence,
 *    never FIFO/pro-rata). Several events + accepted returns WITHOUT a complete allocation are unresolved ⇒ the
 *    item is excluded from the ranking.
 *  - A refund without an accepted physical return deducts nothing; a merely requested/rejected return deducts nothing.
 *  - Late payment does not move the receipt date: the window is the actual receipt date (original cohort).
 *
 * Window = Asia/Jakarta calendar dates, INCLUSIVE of today: N days ⇒ [today-(N-1), today].
 * Tie-break (engineering choice, no Owner rule exists): net desc → most recent receipt desc → product id asc.
 */
class BestsellerService
{
    public const PERIODS = ['7', '30', '90', 'all'];

    public const LIMITS = [4, 8, 12];

    public const TZ = 'Asia/Jakarta';

    /** @return Collection<int, Product> ranked products (already eager-loaded for ProductResource) */
    public function rank(int $agentId, string $period, int $limit, ?CarbonImmutable $now = null): Collection
    {
        $today = ($now ?? CarbonImmutable::now(self::TZ))->setTimezone(self::TZ)->startOfDay();
        $from = $period === 'all' ? null : $today->subDays((int) $period - 1)->toDateString();
        $to = $today->toDateString();

        $receipts = OrderItemReceipt::query()
            ->where('order_item_receipts.agent_id', $agentId)
            ->join('orders', 'orders.id', '=', 'order_item_receipts.order_id')
            ->where('orders.agent_id', $agentId)
            ->where('orders.payment_status', 'paid')
            ->where('orders.remaining_amount', '<=', 0)
            ->where('orders.status', '!=', 'dibatalkan')
            ->select('order_item_receipts.*')
            ->get();
        if ($receipts->isEmpty()) {
            return collect();
        }

        $byItem = $receipts->groupBy('order_item_id');
        $items = \App\Models\OrderItem::query()->whereIn('id', $byItem->keys())->get(['id', 'product_id'])->keyBy('id');
        $returned = ReturnItem::query()->whereIn('order_item_id', $byItem->keys())
            ->where('status', 'approved')->whereNotNull('inspected_at')
            ->selectRaw('order_item_id, SUM(quantity_received) as qty')->groupBy('order_item_id')
            ->pluck('qty', 'order_item_id');

        // Evidenced allocations (explicitly recorded) of accepted returns to receipt events.
        $allocated = \App\Models\ReturnItemReceiptAllocation::query()
            ->whereIn('order_item_receipt_id', $receipts->pluck('id'))
            ->selectRaw('order_item_receipt_id, SUM(quantity) as qty')->groupBy('order_item_receipt_id')
            ->pluck('qty', 'order_item_receipt_id');
        $allocatedByItem = [];
        foreach ($receipts as $r) {
            $allocatedByItem[$r->order_item_id] = ($allocatedByItem[$r->order_item_id] ?? 0) + (int) ($allocated[$r->id] ?? 0);
        }

        /** @var array<int, array{units:int, last:string}> $byProduct */
        $byProduct = [];
        foreach ($byItem as $itemId => $events) {
            $returns = (int) ($returned[$itemId] ?? 0);
            $single = $events->count() === 1;
            // Several receipt events + accepted returns: only the explicitly allocated case is resolvable.
            if (! $single && $returns > 0 && ($allocatedByItem[$itemId] ?? 0) !== $returns) {
                continue;
            }
            foreach ($events as $event) {
                $date = $event->received_on->toDateString();
                if (($from !== null && ($date < $from)) || $date > $to) {
                    continue;
                }
                $deduct = $single ? $returns : (int) ($allocated[$event->id] ?? 0);
                $net = max(0, $event->quantity - $deduct);
                if ($net === 0) {
                    continue;
                }
                $pid = (int) $items[$itemId]->product_id;
                $byProduct[$pid]['units'] = ($byProduct[$pid]['units'] ?? 0) + $net;
                $byProduct[$pid]['last'] = max($byProduct[$pid]['last'] ?? '', $date);
            }
        }
        if ($byProduct === []) {
            return collect();
        }

        $products = Product::query()->where('status', 'active')->whereIn('id', array_keys($byProduct))
            ->with(['variations' => fn ($q) => $q->where('is_active', true), 'category', 'images' => fn ($q) => $q->where('is_primary', true)->whereNull('product_variation_id')])
            ->get()->keyBy('id');

        $ranked = [];
        foreach ($byProduct as $pid => $agg) {
            if ($products->has($pid)) {
                $ranked[] = ['pid' => $pid, 'units' => $agg['units'], 'last' => $agg['last']];
            }
        }
        usort($ranked, fn ($a, $b) => ($b['units'] <=> $a['units']) ?: strcmp($b['last'], $a['last']) ?: ($a['pid'] <=> $b['pid']));

        return collect(array_slice($ranked, 0, $limit))->map(fn ($r) => $products[$r['pid']])->values();
    }
}
