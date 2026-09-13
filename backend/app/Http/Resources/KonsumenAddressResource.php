<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KonsumenAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'address_line' => $this->address_line,
            'village' => $this->whenLoaded('village', fn () => $this->village ? [
                'id' => $this->village->id,
                'name' => $this->village->name,
                'district' => $this->village->district?->name,
                'regency' => $this->village->district?->regency?->name,
                'province' => $this->village->district?->regency?->province?->name,
            ] : null),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->is_default,
        ];
    }
}
