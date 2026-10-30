<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Evidence files live on a private disk; only the integrity hash and reference are exposed. */
class ConsentEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'root_event_id' => $this->root_event_id,
            'event_type' => $this->event_type,
            'purpose' => $this->purpose,
            'subject_user_id' => $this->subject_user_id,
            'crm_customer_id' => $this->crm_customer_id,
            'order_id' => $this->order_id,
            'return_id' => $this->return_id,
            'scope' => $this->scope,
            'has_evidence' => $this->evidence_path !== null,
            'evidence_sha256' => $this->evidence_sha256,
            'note' => $this->note,
            'actor_id' => $this->actor_id,
            'policy_version' => $this->policy_version,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
