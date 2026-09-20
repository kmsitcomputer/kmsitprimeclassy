<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseSubLocationController extends Controller
{
    public function index(Request $request)
    {
        return $this->ok(WarehouseSubLocation::query()->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $this->authorize('create', WarehouseSubLocation::class);
        $data = $request->validate(['code' => ['required', 'string', 'max:40', Rule::unique('warehouse_sub_locations', 'code')->where('agent_id', $request->user()->agent_id)], 'name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string']]);
        $data['agent_id'] = $request->user()->agent_id;
        $data['created_by'] = $request->user()->id;

        return $this->created(WarehouseSubLocation::create($data));
    }

    public function show(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('view', $subLocation);

        return $this->ok($subLocation);
    }

    public function update(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('update', $subLocation);
        $subLocation->update($request->validate(['code' => ['sometimes', 'string', 'max:40', Rule::unique('warehouse_sub_locations', 'code')->where('agent_id', $subLocation->agent_id)->ignore($subLocation->id)], 'name' => ['sometimes', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string']]));

        return $this->ok($subLocation->fresh());
    }

    public function deactivate(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('update', $subLocation);
        $hasStock = WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $subLocation->id)->where('quantity', '>', 0)->exists();
        if ($hasStock) {
            throw new ApiException('Sub location masih memiliki stok fisik.', 422);
        }
        $subLocation->update(['is_active' => false]);

        return $this->ok($subLocation->fresh());
    }

    public function stocks(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('view', $subLocation);

        return $this->ok(WarehouseStock::withoutGlobalScopes()->where('agent_id', $subLocation->agent_id)->where('sub_location_id', $subLocation->id)->with(['product', 'variation'])->get());
    }
}
