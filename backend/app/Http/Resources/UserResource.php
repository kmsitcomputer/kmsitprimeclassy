<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\User;
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
            'role' => Role::canonicalSlug($this->role?->slug),
            'referral_code' => $this->referral_code,
            'agent_id' => $this->agent_id,
            'parent_id' => $this->parent_id,
            'korsal_id' => $this->korsal_id,
            'sales_id' => $this->sales_id,
            'status' => $this->status,
            // IMP-001: only ever for the authenticated user's own record (never leaked in user lists).
            'google_linked' => $this->when(
                $request->user()?->id === $this->id,
                fn () => $this->socialIdentities()->where('provider', 'google')->exists(),
            ),
            // Akun Saya: a Sales / Sales-Kurir-Sub sees the name of their assigned Korsal — own record
            // only, read from the canonical korsal_id and only when it really is a Korsal of the same
            // branch (null = not assigned). Read-only: ProfileController::update never accepts korsal_id.
            'korsal_name' => $this->when(
                $request->user()?->id === $this->id && $this->isRole('sales', Role::SALES_KURIR_SUB),
                fn () => $this->korsal_id === null ? null : User::query()
                    ->whereKey($this->korsal_id)
                    ->where('agent_id', $this->agent_id)
                    ->whereHas('role', fn ($q) => $q->where('slug', 'korsal'))
                    ->value('name'),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
