<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing agent contact directory row — includes the internal fields
 * (referral_code, status, user id) a super_admin needs to manage the
 * record. Never used on the public endpoint — see AgentContactResource for
 * that narrower, public-safe shape.
 */
class AgentDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'referral_code' => $this->referral_code,
            'status' => $this->status,
            'profile' => $this->whenLoaded('agentProfile', fn () => $this->agentProfile ? [
                'id' => $this->agentProfile->id,
                'store_name' => $this->agentProfile->store_name,
                'address' => $this->agentProfile->address,
                'phone' => $this->agentProfile->phone,
                'latitude' => $this->agentProfile->latitude,
                'longitude' => $this->agentProfile->longitude,
            ] : null),
        ];
    }
}
