<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatarUrl(),
            'role' => $this->role?->slug,
            'referral_code' => $this->referral_code,
            'agent_id' => $this->agent_id,
            'korsal_id' => $this->korsal_id,
            'sales_id' => $this->sales_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
