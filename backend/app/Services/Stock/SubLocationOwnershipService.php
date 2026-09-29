<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Models\WarehouseSubLocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * R-01: the only writer of warehouse_sub_locations.owner_user_id.
 *
 * Rules (all server-side, never trusting client ids):
 *  - only Agen/Admin of the branch manage ownership — Gudang never creates/assigns Sub Locations
 *  - the owner must be an ACTIVE Sales-Kurir-Sub of the SAME Agent (no cross-Agent ownership)
 *  - 1 user = 1 Sub Location and 1 Sub Location = 1 owner (locked here, enforced by the UNIQUE index)
 *  - an already-owned location is never silently reassigned; legacy NULL-owner rows are only
 *    mapped through an explicit assignOwner() call
 */
class SubLocationOwnershipService
{
    public function create(User $actor, array $data, int $ownerUserId): WarehouseSubLocation
    {
        $this->assertManager($actor);

        return $this->guarded(fn () => DB::transaction(function () use ($actor, $data, $ownerUserId) {
            $owner = $this->lockEligibleOwner($actor->agent_id, $ownerUserId);
            $location = new WarehouseSubLocation($data + ['agent_id' => $actor->agent_id, 'created_by' => $actor->id]);
            $location->forceFill(['owner_user_id' => $owner->id])->save();
            $this->log($actor, $location, 'sub_location.created', ['owner_user_id' => $owner->id]);

            return $location->fresh();
        }));
    }

    public function assignOwner(User $actor, WarehouseSubLocation $location, int $ownerUserId): WarehouseSubLocation
    {
        $this->assertManager($actor);

        return $this->guarded(fn () => DB::transaction(function () use ($actor, $location, $ownerUserId) {
            $locked = WarehouseSubLocation::withoutGlobalScopes()->whereKey($location->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw new ApiException('Sub location tidak aktif.', 422);
            }
            if ($locked->owner_user_id !== null) {
                throw new ApiException('Sub location sudah memiliki pemilik dan tidak dapat dipindahkan.', 422);
            }
            $owner = $this->lockEligibleOwner($locked->agent_id, $ownerUserId);
            $locked->forceFill(['owner_user_id' => $owner->id])->save();
            $this->log($actor, $locked, 'sub_location.owner_assigned', ['owner_user_id' => $owner->id]);

            return $locked->fresh();
        }));
    }

    public function deactivate(User $actor, WarehouseSubLocation $location): WarehouseSubLocation
    {
        return DB::transaction(function () use ($actor, $location) {
            $locked = WarehouseSubLocation::withoutGlobalScopes()->whereKey($location->id)->where('agent_id', $actor->agent_id)->lockForUpdate()->firstOrFail();
            if (WarehouseStock::withoutGlobalScopes()->where('sub_location_id', $locked->id)->where('quantity', '>', 0)->exists()) {
                throw new ApiException('Sub location masih memiliki stok fisik.', 422);
            }
            $previous = $locked->owner_user_id;
            $locked->forceFill(['is_active' => false, 'owner_user_id' => null, 'previous_owner_user_id' => $previous ?? $locked->previous_owner_user_id])->save();
            $this->log($actor, $locked, 'sub_location.deactivated', ['released_owner_user_id' => $previous]);

            return $locked->fresh();
        });
    }

    /** The one active Sub Location owned by this Sales-Kurir-Sub, or null. */
    public function locationOf(User $user): ?WarehouseSubLocation
    {
        return WarehouseSubLocation::withoutGlobalScopes()->where('owner_user_id', $user->id)->where('agent_id', $user->agent_id)->where('is_active', true)->first();
    }

    private function lockEligibleOwner(int $agentId, int $ownerUserId): User
    {
        $owner = User::query()->whereKey($ownerUserId)->lockForUpdate()->first();
        $eligible = $owner
            && $owner->status === 'active'
            && $owner->agent_id === $agentId
            && $owner->isRole(Role::SALES_KURIR_SUB)
            && ! $owner->trashed();
        if (! $eligible) {
            throw new ApiException('Pemilik harus Sales-Kurir-Sub aktif pada network Agen yang sama.', 422, ['owner_user_id' => 'Pemilik tidak valid.']);
        }
        if (WarehouseSubLocation::withoutGlobalScopes()->where('owner_user_id', $owner->id)->exists()) {
            throw new ApiException('Sales-Kurir-Sub ini sudah memiliki Sub Location.', 422, ['owner_user_id' => 'Satu Sales-Kurir-Sub hanya boleh memiliki satu Sub Location.']);
        }

        return $owner;
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->isRole('agen', 'admin') || ! $actor->agent_id) {
            throw new ApiException('Hanya Agen/Admin yang dapat mengelola kepemilikan Sub Location.', 403);
        }
    }

    private function guarded(callable $callback): WarehouseSubLocation
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            // Concurrent double-assignment lost the race on the UNIQUE index.
            if (str_contains($e->getMessage(), 'warehouse_sub_locations_owner_unique')) {
                throw new ApiException('Sales-Kurir-Sub ini sudah memiliki Sub Location.', 422, ['owner_user_id' => 'Satu Sales-Kurir-Sub hanya boleh memiliki satu Sub Location.']);
            }
            throw $e;
        }
    }

    private function log(User $actor, WarehouseSubLocation $location, string $event, array $extra): void
    {
        ActivityLog::create(['causer_id' => $actor->id, 'subject_type' => WarehouseSubLocation::class, 'subject_id' => $location->id, 'event' => $event, 'properties' => ['agent_id' => $location->agent_id] + $extra]);
    }
}
