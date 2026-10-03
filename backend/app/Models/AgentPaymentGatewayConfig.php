<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AgentPaymentGatewayConfig extends Model
{
    protected $fillable = ['agent_id', 'payment_method_id', 'environment', 'config'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array'];
    }

    /**
     * Replace a config even when its previous encrypted value was produced
     * with another APP_KEY. Avoid Eloquent updateOrCreate() reading the old
     * encrypted cast while determining dirty attributes.
     */
    public static function replaceConfig(
        int $agentId,
        int $paymentMethodId,
        string $environment,
        array $config
    ): void {
        $existingId = DB::table('agent_payment_gateway_configs')
            ->where('agent_id', $agentId)
            ->where('payment_method_id', $paymentMethodId)
            ->where('environment', $environment)
            ->value('id');

        if (! $existingId) {
            static::query()->create([
                'agent_id' => $agentId,
                'payment_method_id' => $paymentMethodId,
                'environment' => $environment,
                'config' => $config,
            ]);

            return;
        }

        $encrypted = new static;
        $encrypted->setAttribute('config', $config);

        DB::table('agent_payment_gateway_configs')->where('id', $existingId)->update([
            'config' => $encrypted->getAttributes()['config'],
            'updated_at' => now(),
        ]);
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
