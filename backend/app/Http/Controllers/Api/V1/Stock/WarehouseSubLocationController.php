<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use App\Services\Stock\SubLocationOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseSubLocationController extends Controller
{
    public function __construct(private readonly SubLocationOwnershipService $ownership) {}

    public function index(Request $request)
    {
        return $this->ok(WarehouseSubLocation::query()->with('owner:id,name')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $this->authorize('create', WarehouseSubLocation::class);
        $data = $request->validate(['owner_user_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:40', Rule::unique('warehouse_sub_locations', 'code')->where('agent_id', $request->user()->agent_id)], 'name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:255'], 'contact_number' => ['nullable', 'string', 'max:30'], 'description' => ['nullable', 'string']]);
        $ownerId = (int) $data['owner_user_id'];
        unset($data['owner_user_id']);

        return $this->created($this->ownership->create($request->user(), $data, $ownerId)->load('owner:id,name'));
    }

    public function assignOwner(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('assignOwner', $subLocation);
        $data = $request->validate(['owner_user_id' => ['required', 'integer']]);

        return $this->ok($this->ownership->assignOwner($request->user(), $subLocation, (int) $data['owner_user_id'])->load('owner:id,name'));
    }

    public function show(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('view', $subLocation);

        return $this->ok($subLocation);
    }

    public function update(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('update', $subLocation);
        $subLocation->update($request->validate(['code' => ['sometimes', 'string', 'max:40', Rule::unique('warehouse_sub_locations', 'code')->where('agent_id', $subLocation->agent_id)->ignore($subLocation->id)], 'name' => ['sometimes', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:255'], 'contact_number' => ['nullable', 'string', 'max:30'], 'description' => ['nullable', 'string']]));

        return $this->ok($subLocation->fresh());
    }

    public function deactivate(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('update', $subLocation);

        return $this->ok($this->ownership->deactivate($request->user(), $subLocation));
    }

    public function stocks(Request $request, WarehouseSubLocation $subLocation)
    {
        $this->authorize('view', $subLocation);

        return $this->ok(WarehouseStock::withoutGlobalScopes()->where('agent_id', $subLocation->agent_id)->where('sub_location_id', $subLocation->id)->with(['product', 'variation'])->get());
    }
}
