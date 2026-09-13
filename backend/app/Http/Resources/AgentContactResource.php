<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public "Kontak Agen" directory row — excludes email/status and every
 * other internal account field, but DOES include referral_code: it's
 * meant to be shared publicly so a konsumen can sign up under that agent's
 * network directly from this page. Only active agents ever reach this
 * resource (see AgentContactController).
 */
class AgentContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->store_name,
            'address' => $this->address,
            'phone' => $this->phone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'referral_code' => $this->whenLoaded('user', fn () => $this->user->referral_code),
        ];
    }
}
