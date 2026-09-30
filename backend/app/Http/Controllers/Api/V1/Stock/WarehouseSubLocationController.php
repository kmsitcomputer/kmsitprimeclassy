<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
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

    /**
     * R-01: server-authoritative Sub Location owner candidates for Agen/Admin.
     *
     * Every Sales-Kurir-Sub in this Agent's network that could legally be assigned a Sub Location
     * right now: active, not deleted, and not already owning one. Paginated and searchable so an
     * eligible owner on any page is reachable — the generic /users listing returns page 1 only and
     * mixes in candidates the create/assign endpoints would reject. Those endpoints stay
     * authoritative and re-check all of these conditions on write.
     */
    public function eligibleOwners(Request $request)
    {
        $actor = $request->user();
        if (! $actor->isRole('agen', 'admin') || ! $actor->agent_id) {
            throw new ApiException('Hanya Agen/Admin yang dapat melihat calon pemilik Sub Location.', 403);
        }

        $query = User::query()->with('role')
            ->where('agent_id', $actor->agent_id)
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::slugsFor(Role::SALES_KURIR_SUB)))
            ->whereDoesntHave('ownedSubLocation');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 50);
        $owners = $query->orderBy('name')->paginate($perPage);

        return $this->ok(UserResource::collection($owners)->resolve(), meta: [
            'current_page' => $owners->currentPage(),
            'last_page' => $owners->lastPage(),
            'total' => $owners->total(),
        ]);
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
