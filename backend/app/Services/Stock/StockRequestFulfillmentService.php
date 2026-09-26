<?php

namespace App\Services\Stock;

use App\Exceptions\ApiException;
use App\Models\StockRequest;
use App\Models\User;

class StockRequestFulfillmentService
{
    public function fulfill(User $actor, StockRequest $request, array $quantities, string $idempotencyKey): StockRequest
    {
        throw new ApiException('Fulfillment langsung oleh Gudang tidak tersedia. Ajukan proposal untuk persetujuan Admin.', 422);
    }
}
