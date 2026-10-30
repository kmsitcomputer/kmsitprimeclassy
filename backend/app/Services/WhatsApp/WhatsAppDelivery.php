<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Durable, idempotent, duplicate-safe delivery of WhatsApp messages.
 *
 *  - ONE row per `idempotency_key` (DB-unique): re-dispatching an event, refreshing a page or replaying a job can never
 *    create a second message.
 *  - A gateway outage NEVER touches the business transaction: callers enqueue AFTER commit and swallow any failure here.
 *  - Retry is bounded (settings max_attempts) and ONLY for outcomes that provably did not reach WhatsApp
 *    (GatewayResult::RETRY). An uncertain outcome becomes `unknown` and is never auto-retried; an operator may force one
 *    retry after reading the explicit duplicate-risk warning.
 */
class WhatsAppDelivery
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly GowaClient $client,
        private readonly AgentDeviceService $devices,
    ) {}

    /**
     * Idempotently create a QUEUED message and dispatch its job. Returns the existing row for a duplicate key.
     *
     * @param  array<string,mixed>  $attrs
     */
    public function enqueue(array $attrs, #[\SensitiveParameter] ?string $secretBody = null, bool $dispatch = true): ?WhatsAppMessage
    {
        if (! WhatsAppMessage::tableReady()) {
            return null;
        }

        $existing = WhatsAppMessage::query()->where('idempotency_key', $attrs['idempotency_key'])->first();
        if ($existing) {
            return $existing;
        }

        try {
            $message = WhatsAppMessage::create($attrs + [
                'status' => WhatsAppMessage::QUEUED,
                'max_attempts' => $this->settings->maxAttempts(),
                'secret_body' => $secretBody,
                'queued_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Lost a race against the same key: the winner owns the message.
            $winner = WhatsAppMessage::query()->where('idempotency_key', $attrs['idempotency_key'])->first();
            if ($winner) {
                return $winner;
            }

            throw $e;
        }

        if ($dispatch) {
            SendWhatsAppMessageJob::dispatch($message->id);
        }

        return $message;
    }

    /** Record that a business event was deliberately NOT sent (and why). Idempotent. */
    public function skip(array $attrs, string $reason): ?WhatsAppMessage
    {
        if (! WhatsAppMessage::tableReady()) {
            return null;
        }

        return WhatsAppMessage::query()->firstOrCreate(
            ['idempotency_key' => $attrs['idempotency_key']],
            $attrs + ['status' => WhatsAppMessage::SKIPPED, 'skip_reason' => $reason, 'queued_at' => now()],
        );
    }

    /** Called by the queue job (or synchronously for test messages / OTP). Safe to call twice. */
    public function deliver(int $messageId): ?WhatsAppMessage
    {
        // Claim the row. Only a QUEUED message may be sent, so a replayed/duplicated job is a no-op.
        $message = DB::transaction(function () use ($messageId) {
            $m = WhatsAppMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if (! $m || $m->status !== WhatsAppMessage::QUEUED) {
                return null;
            }
            $m->forceFill(['status' => WhatsAppMessage::SENDING, 'attempts' => $m->attempts + 1, 'last_attempt_at' => now()])->save();

            return $m;
        });

        if (! $message) {
            return WhatsAppMessage::query()->find($messageId);
        }

        // A gateway that was switched off after queuing must not send. Nothing was transmitted, so this is a clean skip.
        if (! $this->settings->reload()->isConfigured()) {
            return $this->finish($message, WhatsAppMessage::SKIPPED, ['skip_reason' => 'gateway_disabled']);
        }

        // Proof is re-checked at SEND time, not only when the message was queued: a delayed job, a throttled retry or an operator's
        // manual retry hours later must never reach a number the account no longer holds a valid ownership proof for.
        if ($message->purpose === WhatsAppMessage::PURPOSE_TRANSACTION) {
            $recipient = $message->recipient_user_id ? User::query()->find($message->recipient_user_id) : null;
            if (WhatsAppNumberProof::msisdnFor($recipient) !== $message->recipient_phone) {
                return $this->finish($message, WhatsAppMessage::SKIPPED, ['skip_reason' => 'number_not_verified']);
            }
        }

        // Per-Agent isolation: a message is sent ONLY from the slot of the Agent that owns it (message.agent_id = the order's / the
        // challenge's Agent). No slot that was ever paired => nothing is transmitted (a clean skip, visible in the history); there is
        // deliberately no fallback to the console's default device or to another Agent's slot. Dashboard test messages are the one
        // platform-level exception (Super Admin console, allow-listed numbers).
        $deviceId = null;
        if ($message->purpose !== WhatsAppMessage::PURPOSE_TEST) {
            $deviceId = $this->devices->sendingDeviceId($message->agent_id);
            if ($deviceId === null) {
                return $this->finish($message, WhatsAppMessage::SKIPPED, ['skip_reason' => 'agent_device_not_ready']);
            }
        }

        if ($limited = $this->volumeBlock($message)) {
            return $this->throttle($message, $limited);
        }

        $text = $message->secret_body ?? (string) $message->body;
        $result = $this->client->sendText((string) $message->recipient_phone, $text, $deviceId);

        return $this->apply($message, $result);
    }

    /** Longest a throttled notification may wait: a stale "your order shipped" is worse than none. */
    private const THROTTLE_MAX_AGE_HOURS = 6;

    /**
     * Volume protection. Counts what actually reached (or may have reached) the gateway — sent / sending / unknown — from the
     * durable log, so it holds across workers and restarts. Test messages are operator-driven and allow-listed (exempt); OTP is
     * exempt from the per-recipient cap (it has its own per-account / per-number limits) but not from the gateway-wide one.
     *
     * @return array{scope:string, retry_after:int}|null
     */
    private function volumeBlock(WhatsAppMessage $message): ?array
    {
        if ($message->purpose === WhatsAppMessage::PURPOSE_TEST) {
            return null;
        }

        $limits = $this->settings->rateLimits();
        $counted = fn () => WhatsAppMessage::query()
            ->where('id', '!=', $message->id)
            ->whereIn('status', [WhatsAppMessage::SENT, WhatsAppMessage::SENDING, WhatsAppMessage::UNKNOWN]);

        if ($counted()->where('last_attempt_at', '>=', now()->subMinute())->count() >= $limits['global_per_minute']) {
            return ['scope' => 'global', 'retry_after' => 60];
        }

        if ($message->purpose !== WhatsAppMessage::PURPOSE_OTP
            && $counted()->where('recipient_phone', $message->recipient_phone)->where('last_attempt_at', '>=', now()->subHour())->count() >= $limits['per_recipient_per_hour']) {
            return ['scope' => 'recipient', 'retry_after' => 600];
        }

        return null;
    }

    /**
     * A throttled message was NOT transmitted, so the attempt is handed back. Transactional notifications wait (bounded);
     * an OTP is time-boxed and cannot wait, so it ends as a clean skip and its challenge is invalidated by the channel.
     *
     * @param  array{scope:string, retry_after:int}  $block
     */
    private function throttle(WhatsAppMessage $message, array $block): WhatsAppMessage
    {
        $first = ! str_starts_with((string) $message->error_class, 'rate_limited');
        $class = 'rate_limited:'.$block['scope'];

        if ($first) {
            // Audited once per message (never per retry tick), without any recipient number.
            ActivityLogger::log(null, $message, 'whatsapp.message.rate_limited', null, [
                'scope' => $block['scope'], 'purpose' => $message->purpose, 'event' => $message->event,
            ]);
        }

        if ($message->purpose !== WhatsAppMessage::PURPOSE_TRANSACTION) {
            return $this->finish($message, WhatsAppMessage::SKIPPED, ['skip_reason' => 'rate_limited_'.$block['scope'], 'error_class' => $class]);
        }

        if ($message->queued_at !== null && $message->queued_at->lt(now()->subHours(self::THROTTLE_MAX_AGE_HOURS))) {
            return $this->finish($message, WhatsAppMessage::FAILED, ['error_class' => 'rate_limited_expired', 'error_message' => 'Not sent: volume limit held it past its useful life.']);
        }

        $message->forceFill([
            'status' => WhatsAppMessage::QUEUED, 'attempts' => max(0, $message->attempts - 1), 'error_class' => $class,
            'next_attempt_at' => now()->addSeconds($block['retry_after']),
        ])->save();

        SendWhatsAppMessageJob::dispatch($message->id)->delay(now()->addSeconds($block['retry_after']));

        return $message->refresh();
    }

    private function apply(WhatsAppMessage $message, GatewayResult $r): WhatsAppMessage
    {
        return match ($r->outcome) {
            GatewayResult::SENT => $this->finish($message, WhatsAppMessage::SENT, [
                'provider_message_id' => $r->providerMessageId, 'sent_at' => now(), 'error_class' => null, 'error_message' => null,
            ]),
            GatewayResult::FAILED => $this->finish($message, WhatsAppMessage::FAILED, ['error_class' => $r->errorClass, 'error_message' => $r->message]),
            GatewayResult::UNKNOWN => $this->finish($message, WhatsAppMessage::UNKNOWN, ['error_class' => $r->errorClass, 'error_message' => $r->message]),
            default => $this->retryOrExhaust($message, $r),
        };
    }

    private function retryOrExhaust(WhatsAppMessage $message, GatewayResult $r): WhatsAppMessage
    {
        // A code is time-boxed and its challenge is invalidated the moment delivery fails: re-sending it later would only deliver a dead code.
        if ($message->purpose === WhatsAppMessage::PURPOSE_OTP) {
            return $this->finish($message, WhatsAppMessage::FAILED, ['error_class' => 'otp_not_delivered:'.$r->errorClass, 'error_message' => $r->message]);
        }

        if ($message->attempts >= $message->max_attempts) {
            return $this->finish($message, WhatsAppMessage::FAILED, ['error_class' => 'retries_exhausted:'.$r->errorClass, 'error_message' => $r->message]);
        }

        $delay = $this->settings->backoffSeconds() * $message->attempts;
        $message->forceFill([
            'status' => WhatsAppMessage::QUEUED, 'error_class' => $r->errorClass, 'error_message' => $r->message,
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        SendWhatsAppMessageJob::dispatch($message->id)->delay(now()->addSeconds($delay));

        return $message->refresh();
    }

    /** @param  array<string,mixed>  $fields */
    private function finish(WhatsAppMessage $message, string $status, array $fields): WhatsAppMessage
    {
        // The OTP text is wiped as soon as the message reaches ANY final state.
        $message->forceFill($fields + ['status' => $status, 'secret_body' => null, 'next_attempt_at' => null])->save();

        return $message->refresh();
    }

    /**
     * Operator-controlled retry of a `failed` message, or of an `unknown` one after explicitly accepting the duplicate risk.
     * OTP messages are never re-sent: a fresh challenge must be issued instead.
     */
    public function manualRetry(WhatsAppMessage $message, User $actor, bool $acceptDuplicateRisk): WhatsAppMessage
    {
        if ($message->purpose === WhatsAppMessage::PURPOSE_OTP) {
            throw new ApiException(__('messages.whatsapp.retry_otp_forbidden'), 422);
        }
        if ($message->body === null || $message->body === '') {
            throw new ApiException(__('messages.whatsapp.retry_not_allowed'), 422);
        }

        if ($message->status === WhatsAppMessage::UNKNOWN && ! $acceptDuplicateRisk) {
            throw new ApiException(__('messages.whatsapp.retry_unknown_needs_confirm'), 409, ['code' => 'duplicate_risk_confirmation_required']);
        }
        if (! in_array($message->status, [WhatsAppMessage::FAILED, WhatsAppMessage::UNKNOWN], true)) {
            throw new ApiException(__('messages.whatsapp.retry_not_allowed'), 422);
        }

        $claimed = DB::table('whatsapp_messages')
            ->where('id', $message->id)->whereIn('status', [WhatsAppMessage::FAILED, WhatsAppMessage::UNKNOWN])
            ->update(['status' => WhatsAppMessage::QUEUED, 'attempts' => 0, 'next_attempt_at' => null, 'updated_at' => now()]);

        if ($claimed === 0) {
            throw new ApiException(__('messages.whatsapp.retry_not_allowed'), 409);
        }

        ActivityLogger::log($actor->id, $message, 'whatsapp.message.retried', null, [
            'previous_status' => $message->status, 'accepted_duplicate_risk' => $acceptDuplicateRisk,
        ]);

        SendWhatsAppMessageJob::dispatch($message->id);

        return $message->refresh();
    }

    /** Real test message to an allow-listed number, delivered synchronously so the dashboard shows the true outcome. */
    public function sendTest(User $actor, string $msisdn, string $text): WhatsAppMessage
    {
        if (! WhatsAppMessage::tableReady()) {
            throw new ApiException(__('messages.whatsapp.migration_pending'), 503);
        }

        $message = $this->enqueue([
            'idempotency_key' => 'test:'.bin2hex(random_bytes(10)),
            'purpose' => WhatsAppMessage::PURPOSE_TEST, 'event' => 'test',
            'recipient_phone' => $msisdn, 'body' => $text, 'created_by' => $actor->id,
        ], dispatch: false);

        return $this->deliver($message->id) ?? $message->refresh();
    }
}
