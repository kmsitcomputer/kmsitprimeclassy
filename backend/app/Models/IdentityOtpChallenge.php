<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityOtpChallenge extends Model
{
    protected $fillable = [
        'agent_id', 'subject_user_id', 'crm_customer_id', 'created_by', 'purpose', 'code_hash', 'sent_to_email',
        'attempts', 'max_attempts', 'expires_at', 'verified_at', 'consumed_at', 'invalidated_at',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
