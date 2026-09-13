<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductSku extends Command
{
    protected $signature = 'products:backfill-sku {--apply : Write changes; default is dry run}';

    protected $description = 'Deterministic simple product SKU backfill; preserves existing SKU and order snapshots.';

    public function handle(): int
    {
        Product::withTrashed()->where('has_variations', false)->where(fn ($q) => $q->whereNull('sku')->orWhereRaw("TRIM(sku) = ''"))
            ->orderBy('id')->chunkById(100, function ($products) {
                foreach ($products as $product) {
                    DB::transaction(function () use ($product) {
                        $current = Product::withTrashed()->lockForUpdate()->findOrFail($product->id);
                        if (trim((string) $current->sku) !== '') {
                            return;
                        }
                        $base = 'PRD-'.str_pad((string) $current->id, 10, '0', STR_PAD_LEFT);
                        $sku = $base;
                        $suffix = 0;
                        while (DB::table('catalog_skus')->where('sku', $sku)->exists()) {
                            $sku = $base.'-'.++$suffix;
                        }
                        $this->line($current->id.' -> '.$sku.($this->option('apply') ? ' APPLY' : ' DRY RUN'));
                        if ($this->option('apply')) {
                            $current->update(['sku' => $sku]);
                        }
                    });
                }
            });

        return self::SUCCESS;
    }
}
