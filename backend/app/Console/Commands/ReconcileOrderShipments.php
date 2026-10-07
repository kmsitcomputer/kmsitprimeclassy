<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\ShipmentCanonicalizationService;
use Illuminate\Console\Command;

/**
 * DEV-ONLY reconciliation for the LOCKED grouping rule (Human UAT-005): consolidate duplicate
 * same-date Shipments of one order into one canonical Shipment.
 *
 * SAFETY — no override flag exists:
 *   - refuses a production environment;
 *   - requires the connected database to be exactly `primeclassy_dev`;
 *   - requires the confirmation phrase;
 *   - always prints a dry-run plan first and asks for explicit confirmation;
 *   - the domain service itself aborts the WHOLE order on any unsafe candidate, so a refusal can
 *     never leave the order partially merged.
 *
 * It NEVER touches an order whose shipments are assigned, in transit, delivered, proven or tracked.
 */
class ReconcileOrderShipments extends Command
{
    public const REQUIRED_DATABASE = 'primeclassy_dev';

    public const CONFIRMATION = 'RECONCILE SHIPMENTS';

    protected $signature = 'primeclassy:reconcile-order-shipments
        {order? : Order number or id (omit to list eligible orders)}
        {--apply : Actually write; without it this is a read-only plan}
        {--confirm= : The exact confirmation phrase}';

    protected $description = 'DEV ONLY: consolidate duplicate same-date shipments of one order into one canonical shipment.';

    public function handle(ShipmentCanonicalizationService $service): int
    {
        if (app()->environment('production')) {
            $this->error('REFUSED: DEV-only tool and the environment is production.');

            return self::FAILURE;
        }

        if (DB_DATABASE() !== self::REQUIRED_DATABASE) {
            $this->error('REFUSED: connected database is not '.self::REQUIRED_DATABASE.'.');

            return self::FAILURE;
        }

        $reference = $this->argument('order');

        if ($reference === null) {
            $this->listEligible();

            return self::SUCCESS;
        }

        $order = ctype_digit((string) $reference)
            ? Order::withoutGlobalScopes()->find((int) $reference)
            : Order::withoutGlobalScopes()->where('order_no', $reference)->first();

        if (! $order) {
            $this->error('Order not found: '.$reference);

            return self::FAILURE;
        }

        $this->line("<info>Order #{$order->id} {$order->order_no}</info>  (status: {$order->status})");

        // Always show the read-only plan before anything is considered.
        $plan = $service->reconcileOrder($order, dryRun: true);
        $this->renderPlan($plan);

        if ($plan['conflicts'] !== []) {
            $this->error('REFUSED: unsafe candidate(s) found — nothing was changed.');
            foreach ($plan['conflicts'] as $conflict) {
                $this->line('  - shipment #'.$conflict['shipment_id'].': '.$conflict['reason']);
            }

            return self::FAILURE;
        }

        if ($plan['moved_items'] === [] && $plan['deleted_empty_shipments'] === []) {
            $this->info('Nothing to reconcile: this order already has one shipment per delivery date.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->comment('Read-only plan. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        $phrase = $this->option('confirm') ?? $this->ask('Type "'.self::CONFIRMATION.'" to write');
        if ($phrase !== self::CONFIRMATION) {
            $this->error('REFUSED: confirmation phrase mismatch.');

            return self::FAILURE;
        }

        $report = $service->reconcileOrder($order);
        $this->line('');
        $this->line('<info>Applied.</info>');
        $this->renderPlan($report);

        return self::SUCCESS;
    }

    private function renderPlan(array $report): void
    {
        $this->line('  dry run: '.($report['dry_run'] ? 'yes' : 'no'));
        $this->line('  canonical shipment: #'.($report['canonical_shipment_id'] ?? 'n/a'));

        if ($report['moved_items'] !== []) {
            $this->line('  items to move:');
            foreach ($report['moved_items'] as $move) {
                $this->line("    item #{$move['item_id']}: shipment #{$move['from']} -> #{$move['to']}");
            }
        }

        if ($report['deleted_empty_shipments'] !== []) {
            $this->line('  empty shipments to remove (emptied by this merge): '
                .implode(', ', array_map(fn ($id) => '#'.$id, $report['deleted_empty_shipments'])));
        }

        if ($report['conflicts'] !== []) {
            $this->line('  CONFLICTS:');
            foreach ($report['conflicts'] as $conflict) {
                $this->line('    shipment #'.$conflict['shipment_id'].': '.$conflict['reason']);
            }
        }
    }

    /** Lists DEV orders that still carry more than one shipment for a single delivery date. */
    private function listEligible(): void
    {
        $rows = Order::withoutGlobalScopes()
            ->whereHas('shipments')
            ->with('shipments')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $found = 0;
        foreach ($rows as $order) {
            $plan = app(ShipmentCanonicalizationService::class)->reconcileOrder($order, dryRun: true);
            if ($plan['moved_items'] === []) {
                continue;
            }
            $found++;
            $this->line(sprintf(
                '  #%d %-18s status=%-10s duplicate shipments: %d, canonical: #%s%s',
                $order->id,
                $order->order_no,
                $order->status,
                count($plan['merged_shipment_ids']),
                $plan['canonical_shipment_id'] ?? 'n/a',
                $plan['conflicts'] !== [] ? '  [CONFLICTS — will be refused]' : '',
            ));
        }

        if ($found === 0) {
            $this->info('No order needs shipment reconciliation.');
        }
    }
}

if (! function_exists('DB_DATABASE')) {
    /**
     * Small helper so the command reads the CONFIGURED database name. Kept local (not a helper
     * autoload) so the guard stays visible right where the refusal happens.
     */
    function DB_DATABASE(): ?string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
    }
}