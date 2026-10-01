<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEV MAINTENANCE TOOL ONLY — NOT a production procedure.
 *
 * Wipes transactional data (orders → payments → fulfillment → stock-request/transfer/Sub chains,
 * stock movements, opnames) while preserving every master record and the CURRENT physical stock
 * balances (product_stocks/product_variation_stocks.quantity_on_hand, warehouse_stocks.quantity).
 * Only the *reserved* commitment counters are zeroed, because every Order that held them is gone.
 *
 * The environment/database guard lives in the Artisan command (ResetDevTransactions); this class has
 * no bypass flag and is only reachable from it (and from tests, which run on the isolated test DB).
 */
class DevTransactionResetService
{
    /** Child → parent deletion order, derived from the actual FK graph. */
    public const TRANSACTION_TABLES = [
        // Sub reservations / stock-request chain (reference order_items, stock_requests)
        'sub_stock_reservations',
        'stock_request_proposal_items',
        'stock_request_proposals',
        'stock_request_fulfillments',
        'inventory_cancellation_reversals',
        'stock_request_items',
        'stock_requests',
        // Returns / money / fulfillment evidence hanging off orders
        'return_items',
        'returns',
        'commissions',
        'order_item_adjustments',
        'delivery_verifications',
        'cod_payment_proofs',
        'bank_transfer_verifications',
        'order_items',
        'shipments',
        'order_additional_payments',
        'payment_webhook_logs',
        'payment_transactions',
        'orders',
        // Warehouse / Sub transfer chain
        'stock_handovers',
        'sub_stock_request_items',
        'sub_stock_requests',
        'stock_transfer_items',
        'stock_transfers',
        // Movement history, opnames, warehouse stock-addition requests
        'stock_movements',
        'stock_opname_items',
        'stock_opnames',
        'warehouse_stock_requests',
    ];

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $masters = [];
        foreach (['users', 'roles', 'agent_profiles', 'products', 'product_variations', 'warehouse_sub_locations'] as $table) {
            $masters[$table] = DB::table($table)->count();
        }

        $checksum = fn (string $table, string $column) => md5(DB::table($table)->orderBy('id')->get(['id', $column])
            ->map(fn ($r) => $r->id.':'.$r->{$column})->implode('|'));

        return [
            'masters' => $masters,
            'sub_location_ownership' => md5(DB::table('warehouse_sub_locations')->orderBy('id')->get(['id', 'agent_id', 'owner_user_id'])
                ->map(fn ($r) => "$r->id:$r->agent_id:$r->owner_user_id")->implode('|')),
            'product_stocks' => [
                'rows' => DB::table('product_stocks')->count(),
                'on_hand' => (int) DB::table('product_stocks')->sum('quantity_on_hand'),
                'reserved' => (int) DB::table('product_stocks')->sum('quantity_reserved'),
                'on_hand_checksum' => $checksum('product_stocks', 'quantity_on_hand'),
            ],
            'product_variation_stocks' => [
                'rows' => DB::table('product_variation_stocks')->count(),
                'on_hand' => (int) DB::table('product_variation_stocks')->sum('quantity_on_hand'),
                'reserved' => (int) DB::table('product_variation_stocks')->sum('quantity_reserved'),
                'on_hand_checksum' => $checksum('product_variation_stocks', 'quantity_on_hand'),
            ],
            'warehouse_stocks' => [
                'rows' => DB::table('warehouse_stocks')->count(),
                'quantity' => (int) DB::table('warehouse_stocks')->sum('quantity'),
                'quantity_checksum' => $checksum('warehouse_stocks', 'quantity'),
                'grouped' => DB::table('warehouse_stocks')->selectRaw('agent_id, stock_type, sub_location_id, COUNT(*) n, SUM(quantity) q')
                    ->groupBy('agent_id', 'stock_type', 'sub_location_id')->orderBy('agent_id')->orderBy('stock_type')->get()->map(fn ($r) => (array) $r)->all(),
            ],
        ];
    }

    /** @return array<string, int> */
    public function transactionCounts(): array
    {
        return collect(self::TRANSACTION_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /**
     * @return array{before: array, after: array, deleted: array<string,int>, counts_after: array<string,int>}
     */
    public function reset(): array
    {
        $before = $this->snapshot();
        $deleted = [];

        DB::transaction(function () use (&$deleted, $before) {
            // Break the only in-table / cross-table references among rows that are all being deleted.
            DB::table('order_items')->update(['split_from_order_item_id' => null, 'shipment_id' => null, 'additional_payment_id' => null]);

            foreach (self::TRANSACTION_TABLES as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            // Every Order/Sub reservation is gone, so the commitment counters go to zero. Physical
            // balances (quantity_on_hand / warehouse_stocks.quantity) are NEVER touched.
            DB::table('product_stocks')->update(['quantity_reserved' => 0]);
            DB::table('product_variation_stocks')->update(['quantity_reserved' => 0]);

            $after = $this->snapshot();
            foreach (['product_stocks', 'product_variation_stocks'] as $t) {
                if ($after[$t]['on_hand_checksum'] !== $before[$t]['on_hand_checksum']) {
                    throw new RuntimeException("Physical stock changed in {$t}; reset rolled back.");
                }
            }
            if ($after['warehouse_stocks']['quantity_checksum'] !== $before['warehouse_stocks']['quantity_checksum']
                || $after['masters'] !== $before['masters']
                || $after['sub_location_ownership'] !== $before['sub_location_ownership']) {
                throw new RuntimeException('Preserved master/warehouse data changed; reset rolled back.');
            }
        });

        // DDL (implicit commit) — only the deleted transaction tables, only after the data commit.
        foreach (self::TRANSACTION_TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
        }

        return ['before' => $before, 'after' => $this->snapshot(), 'deleted' => $deleted, 'counts_after' => $this->transactionCounts()];
    }
}
