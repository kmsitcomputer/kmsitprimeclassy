<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id',
        'parent_id',
        'agent_id',
        'korsal_id',
        'sales_id',
        'name',
        'email',
        'phone',
        'password',
        'referral_code',
        'status',
        'avatar_media_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Hierarchy FK columns are compared with strict === / !== against
            // other models' ->id (e.g. UserManagementService, UserPolicy,
            // OrderPolicy) — on some PDO/MySQL driver builds an uncast
            // integer column comes back as a string while ->id does not,
            // making an otherwise-correct match fail. Casting removes that
            // driver-dependent footgun entirely.
            'role_id' => 'integer',
            'parent_id' => 'integer',
            'agent_id' => 'integer',
            'korsal_id' => 'integer',
            'sales_id' => 'integer',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Immediate upline in the organisation tree (adjacency list). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Denormalised: the AGEN who owns this user's branch. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'agent_id');
    }

    /** Denormalised: the KORSAL this user (sales/konsumen) falls under. */
    public function korsal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'korsal_id');
    }

    /** Denormalised: the SALES this konsumen was referred by. */
    public function sales(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sales_id');
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function konsumenAddresses(): HasMany
    {
        return $this->hasMany(KonsumenAddress::class);
    }

    public function courierProfile(): HasOne
    {
        return $this->hasOne(Courier::class);
    }

    public function ordersAsKonsumen(): HasMany
    {
        return $this->hasMany(Order::class, 'konsumen_id');
    }

    public function isRole(string ...$slugs): bool
    {
        return in_array($this->role?->slug, $slugs, true);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_media_id ? $this->avatar?->url() : null;
    }
}
