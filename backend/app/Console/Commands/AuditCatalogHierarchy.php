<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditCatalogHierarchy extends Command
{
    protected $signature = 'system:audit-catalog-hierarchy';

    protected $description = 'Read-only SKU and Sales hierarchy audit (JSON; may contain names).';

    public function handle(): int
    {
        $hasSku = Schema::hasColumn('products', 'sku');
        $products = DB::table('products')->where('has_variations', false)->whereNull('deleted_at');
        if ($hasSku) {
            $products->where(fn ($q) => $q->whereNull('sku')->orWhereRaw("TRIM(sku) = ''"));
        }
        $sales = DB::table('users as s')->join('roles as r', 'r.id', '=', 's.role_id')
            ->leftJoin('users as k', 'k.id', '=', 's.korsal_id')->leftJoin('roles as kr', 'kr.id', '=', 'k.role_id')
            ->where('r.slug', 'sales')->whereNull('s.deleted_at')
            ->where(fn ($q) => $q->whereNull('k.id')->orWhereNotNull('k.deleted_at')->orWhere('kr.slug', '!=', 'korsal')->orWhereColumn('k.agent_id', '!=', 's.agent_id')->orWhereNull('s.agent_id')->orWhereNull('s.parent_id')->orWhereColumn('s.parent_id', '!=', 's.korsal_id'))
            ->get(['s.id as sales_id', 's.name as sales_name', 's.agent_id', 's.parent_id as current_parent', 's.korsal_id as current_korsal'])
            ->map(fn ($s) => [...(array) $s, 'recommended_action' => 'NEEDS MANUAL ASSIGNMENT / VERIFY CURRENT KORSAL']);
        $this->line(json_encode([
            'product_sku_column' => $hasSku,
            'simple_products_missing_sku' => $products->count(),
            'duplicate_variant_skus' => DB::table('product_variations')->select('sku')->selectRaw('COUNT(*) as count')->groupBy('sku')->havingRaw('COUNT(*) > 1')->get(),
            'product_variant_collisions' => $hasSku ? DB::table('products as p')->join('product_variations as v', 'p.sku', '=', 'v.sku')->get(['p.id as product_id', 'v.id as variant_id', 'p.sku']) : [],
            'sales_needing_review' => $sales,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
