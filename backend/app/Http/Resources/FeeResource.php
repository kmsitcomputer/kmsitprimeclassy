<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only ever constructed after ProductPolicy::viewFees has already authorized
 * the request (super_admin/agen/sales may call the endpoint at all) — but
 * viewFees is only the "can you reach this at all" gate, not "which fee
 * types may you see": sales must never see agent_fee (only its own
 * sales_fee), and courier_fee is narrower still (super_admin/agen only).
 */
class FeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isSuperAdminOrAgen = $request->user()?->isRole('super_admin', 'agen') ?? false;

        return [
            'agent_fee' => $this->when($isSuperAdminOrAgen, $this['agent']),
            'sales_fee' => $this['sales'],
            'courier_fee' => $this->when($isSuperAdminOrAgen, $this['courier']),
        ];
    }
}
