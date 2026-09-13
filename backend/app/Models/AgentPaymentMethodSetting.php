<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPaymentMethodSetting extends Model
{
    protected $fillable = ['agent_id', 'payment_method_id', 'is_active', 'active_environment'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
