<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AgentShippingProviderConfig extends Model
{
    protected $fillable = ['agent_id', 'shipping_provider_id', 'config'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array'];
    }

    /**
     * Replace a config even when its previous encrypted value was produced
     * with another APP_KEY. Eloquent updateOrCreate() reads the old cast while
     * checking dirty attributes, which makes recovery from a key rotation
     * impossible because that read throws DecryptException.
     */
    public static function replaceConfig(int $agentId, int $providerId, array $config): void
    {
        $existingId = DB::table('agent_shipping_provider_configs')
            ->where('agent_id', $agentId)
            ->where('shipping_provider_id', $providerId)
            ->value('id');

        if (! $existingId) {
            static::query()->create([
                'agent_id' => $agentId,
                'shipping_provider_id' => $providerId,
                'config' => $config,
            ]);

            return;
        }

        $encrypted = new static;
        $encrypted->setAttribute('config', $config);

        DB::table('agent_shipping_provider_configs')->where('id', $existingId)->update([
            'config' => $encrypted->getAttributes()['config'],
            'updated_at' => now(),
        ]);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function shippingProvider(): BelongsTo
    {
        return $this->belongsTo(ShippingProvider::class);
    }
}
