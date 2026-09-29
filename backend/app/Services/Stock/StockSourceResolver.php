<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseSubLocation;

/**
 * R-02: decides — server-side, authoritatively — which stock domain an order draws from.
 *
 * Sub stock is usable ONLY by the owning Sales-Kurir-Sub, and ONLY for
 *   A. their own purchase (actor === buyer), or
 *   B. an order they place on behalf of a consumer in their own referral network (buyer.sales_id === actor).
 * A referred consumer checking out for themselves, any other Sales/Korsal/Sales-Kurir-Sub, and any
 * other branch actor always use Agent stock. The Sub Location is derived from the actor's ownership;
 * a client-supplied sub_location_id is only ever compared against it, never trusted.
 */
class StockSourceResolver
{
    public const AGENT = 'agent';

    public const SUB = 'sub';

    /** @return array{source:string, sub_location:?WarehouseSubLocation} */
    public function resolve(User $actor, User $buyer, ?string $requestedSource, ?int $requestedSubLocationId = null): array
    {
        $source = $requestedSource ?: self::AGENT;
        if (! in_array($source, [self::AGENT, self::SUB], true)) {
            throw new ApiException('Sumber stok tidak valid.', 422, ['stock_source' => 'Sumber stok tidak valid.']);
        }
        if ($source === self::AGENT) {
            if ($requestedSubLocationId !== null) {
                throw new ApiException('sub_location_id hanya berlaku untuk sumber stok Sub.', 422, ['sub_location_id' => 'Tidak valid untuk sumber stok Agent.']);
            }

            return ['source' => self::AGENT, 'sub_location' => null];
        }

        if (! $actor->isRole(Role::SALES_KURIR_SUB)) {
            throw new ApiException('Hanya Sales-Kurir-Sub yang dapat memakai stok Sub.', 403);
        }
        $ownsBuyerOrder = $buyer->id === $actor->id
            || ($buyer->isRole('konsumen') && $buyer->sales_id === $actor->id && $buyer->agent_id === $actor->agent_id);
        if (! $ownsBuyerOrder) {
            throw new ApiException('Stok Sub hanya dapat dipakai untuk pembelian sendiri atau konsumen referral sendiri.', 403);
        }
        $location = app(SubLocationOwnershipService::class)->locationOf($actor);
        if (! $location) {
            throw new ApiException('Anda belum memiliki Sub Location aktif.', 422, ['stock_source' => 'Sub Location tidak tersedia.']);
        }
        if ($requestedSubLocationId !== null && $requestedSubLocationId !== $location->id) {
            throw new ApiException('Sub Location bukan milik Anda.', 403);
        }

        return ['source' => self::SUB, 'sub_location' => $location];
    }
}
