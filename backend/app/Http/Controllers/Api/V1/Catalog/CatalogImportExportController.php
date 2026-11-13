<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Services\Catalog\CatalogImportService;
use App\Services\Catalog\CatalogSpreadsheet;
use App\Services\Hierarchy\ValidAgentResolver;
use Illuminate\Http\Request;

/** REM-01. Roles: super_admin / agen / admin (ProductPolicy::manage) — enforced by the route group AND the policy. */
class CatalogImportExportController extends Controller
{
    public function __construct(
        private readonly CatalogSpreadsheet $sheets,
        private readonly CatalogImportService $imports,
        private readonly ValidAgentResolver $agents,
    ) {}

    public function export(Request $request)
    {
        $this->authorize('manage', Product::class);
        $data = $request->validate([
            'format' => ['nullable', 'in:csv,xlsx'],
            'agent_id' => ['nullable', 'integer'],
            'stock_type' => ['nullable', 'in:'.implode(',', CatalogImportService::STOCK_TYPES)],
            'sub_location_id' => ['nullable', 'integer'],
        ]);
        $format = $data['format'] ?? 'csv';

        $stock = null; // never a sum across Agent/Sub — exactly one explicit context, or no stock column
        if (! empty($data['stock_type'])) {
            $agent = $this->agents->forActor($request->user(), isset($data['agent_id']) ? (int) $data['agent_id'] : null);
            $stock = ['agent_id' => $agent->id, 'type' => $data['stock_type'], 'loc' => isset($data['sub_location_id']) ? (int) $data['sub_location_id'] : null];
            if (($stock['type'] === 'sub') !== ($stock['loc'] !== null)) {
                throw new ApiException('Konteks stok tidak valid.', 422);
            }
        }

        $rows = (function () use ($stock) {
            foreach (Product::query()->with(['variations.compositions.attribute', 'variations.compositions.option'])->orderBy('id')->cursor() as $p) {
                if (! $p->has_variations) {
                    yield ['simple', $p->sku, $p->slug, $p->name, $p->category_id, $p->status, $p->base_price, $p->weight_grams, '', $this->stockOf($stock, $p->id, null)];

                    continue;
                }
                foreach ($p->variations as $v) {
                    $attrs = $v->compositions->map(fn ($c) => $c->attribute?->name.'='.$c->option?->value)->implode(';');
                    yield ['variant', $v->sku, $p->slug, $p->name, $p->category_id, $v->is_active ? 'active' : 'inactive', $v->price, $v->weight_grams, $attrs, $this->stockOf($stock, null, $v->id)];
                }
            }
        })();

        $name = 'katalog-'.now()->format('Ymd-His').'.'.$format;

        return $format === 'xlsx' ? $this->sheets->streamXlsx($name, $rows) : $this->sheets->streamCsv($name, $rows);
    }

    public function preview(Request $request)
    {
        $this->authorize('manage', Product::class);
        [$rows, $context] = $this->input($request);

        return $this->ok($this->imports->preview($request->user(), $rows, $context));
    }

    public function apply(Request $request)
    {
        $this->authorize('manage', Product::class);
        $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);
        [$rows, $context] = $this->input($request);

        return $this->ok($this->imports->apply($request->user(), $rows, $context, (string) $request->input('idempotency_key')));
    }

    /** @return array{0:list<array<string,string>>,1:array<string,mixed>} */
    private function input(Request $request): array
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'agent_id' => ['nullable', 'integer'],
            'stock_type' => ['nullable', 'in:'.implode(',', CatalogImportService::STOCK_TYPES)],
            'sub_location_id' => ['nullable', 'integer'],
        ]);
        $parsed = $this->sheets->read($request->file('file')->getRealPath());

        return [$parsed['rows'], [
            'agent_id' => $request->filled('agent_id') ? $request->integer('agent_id') : null,
            'stock_type' => $request->input('stock_type'),
            'sub_location_id' => $request->filled('sub_location_id') ? $request->integer('sub_location_id') : null,
        ]];
    }

    private function stockOf(?array $stock, ?int $productId, ?int $variationId): int|string
    {
        if ($stock === null) {
            return '';
        }

        return (int) (WarehouseStock::withoutGlobalScopes()->where('agent_id', $stock['agent_id'])->where('stock_type', $stock['type'])
            ->where('sub_location_id', $stock['loc'])
            ->when($productId, fn ($q) => $q->where('product_id', $productId)->whereNull('product_variation_id'))
            ->when($variationId, fn ($q) => $q->where('product_variation_id', $variationId)->whereNull('product_id'))
            ->value('quantity') ?? 0);
    }
}
