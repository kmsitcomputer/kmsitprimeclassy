<?php

namespace Tests\Concerns;

use App\Services\WhatsApp\AgentDeviceService;
use App\Services\WhatsApp\GowaDeviceClient;
use App\Services\WhatsApp\WhatsAppSettings;

/**
 * For test classes that exercise OTHER WhatsApp behaviour (templates, retry classes, volume caps, OTP rules…) and only need "the owning
 * Agent has a paired device". The per-Agent device rules themselves are tested against the REAL service in AgentWhatsAppDeviceTest.
 */
trait AssumesPairedAgentDevices
{
    protected function assumeEveryAgentHasAPairedDevice(): void
    {
        $this->app->bind(AgentDeviceService::class, fn ($app) => new class($app->make(GowaDeviceClient::class), $app->make(WhatsAppSettings::class)) extends AgentDeviceService
        {
            public function sendingDeviceId(?int $agentId): ?string
            {
                return $agentId ? 'test-device-'.$agentId : null;
            }
        });
    }
}
