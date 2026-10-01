<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\ShipmentGroupingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Package C / production UAT: brings EXISTING orders onto the locked grouping rule
 * (shipment / resi = ORDER + REQUESTED DELIVERY DATE).
 *
 * Only MUTABLE shipments (pending, no courier, not shipped/delivered, no proof, no delivery
 * verification) with the same delivery date are merged. Assigned, in-flight, delivered and verified
 * shipments are historical/operational truth and are never touched. Idempotent. The default is a DRY RUN
 * (everything is rolled back); `--apply` commits. Running it against production needs explicit Human
 * authorization and a backup like any other production data operation.
 */
class RegroupShipments extends Command
{
    protected $signature = 'shipments:regroup
        {--apply : Commit the merges (default is a dry run that rolls everything back)}
        {--order= : Restrict to one order id}';

    protected $description = 'Merge mutable same-date shipments of existing orders (Order + delivery date grouping); dry-run by default.';

    public function handle(ShipmentGroupingService $grouping): int
    {
        $apply = (bool) $this->option('apply');

        $orderIds = DB::table('shipments')
            ->where('status', 'pending')->whereNull('courier_id')->whereNull('shipped_at')->whereNull('delivered_at')
            ->when($this->option('order'), fn ($q) => $q->where('order_id', (int) $this->option('order')))
            ->groupBy('order_id')->havingRaw('COUNT(*) > 1')->pluck('order_id');

        $totals = ['orders' => 0, 'merged' => 0, 'skipped_mixed' => 0];
        foreach ($orderIds as $orderId) {
            $order = Order::withoutGlobalScopes()->find($orderId);
            if (! $order) {
                continue;
            }

            DB::beginTransaction();
            try {
                $result = $grouping->reconcileOrder($order);
                $apply ? DB::commit() : DB::rollBack();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("Order {$orderId}: ".$e->getMessage());

                continue;
            }

            if ($result['merged'] > 0 || $result['skipped_mixed'] > 0) {
                $totals['orders']++;
                $totals['merged'] += $result['merged'];
                $totals['skipped_mixed'] += $result['skipped_mixed'];
                $this->line(sprintf('Order %s (#%d): merged %d shipment(s)%s', $order->order_no, $order->id, $result['merged'], $result['skipped_mixed'] ? ", {$result['skipped_mixed']} mixed-date shipment(s) left untouched" : ''));
            }
        }

        $this->info(($apply ? 'APPLIED' : 'DRY RUN (nothing changed)').": {$totals['merged']} shipment(s) merged across {$totals['orders']} order(s).");

        return self::SUCCESS;
    }
}
