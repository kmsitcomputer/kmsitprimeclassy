<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_method_id', 'event_id', 'gateway_reference',
        'headers', 'payload', 'signature_valid', 'processed', 'note',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
