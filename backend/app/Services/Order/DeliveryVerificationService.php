<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\DeliveryVerification;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * R-03 / decision B: Admin final delivery verification.
 *
 * APPEND-ONLY: every action inserts a new row. A later outcome (e.g. not_received -> received)
 * never updates or deletes the previous record, so the full history is preserved with actor,
 * timestamp, outcome, note and shipment reference (proof is reached via shipments.proof_media_id).
 *
 * This is deliberately separate from payment verification, transaction verification and the
 * courier's own shipment delivery action.
 */
class DeliveryVerificationService
{
    public const OUTCOMES = [
        DeliveryVerification::RECEIVED,
        DeliveryVerification::NOT_RECEIVED,
        DeliveryVerification::RETURN,
    ];

    public function record(Shipment $shipment, User $actor, string $outcome, ?string $note, string $idempotencyKey): DeliveryVerification
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new ApiException(__('messages.delivery_verification.invalid_outcome'), 422);
        }

        $normalizedNote = $note !== null ? trim($note) : null;
        if ($normalizedNote === '') {
            $normalizedNote = null;
        }

        try {
            return DB::transaction(function () use ($shipment, $actor, $outcome, $normalizedNote, $idempotencyKey) {
                $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

                // A verification is only meaningful once the shipment has actually been delivered;
                // the delivery proof itself lives on the shipment (proof_media_id).
                if ($shipment->delivered_at === null) {
                    throw new ApiException(__('messages.delivery_verification.not_delivered'), 422);
                }

                // `delivered_at` is a derived aggregate (CourierService::syncShipmentAggregate), but this
                // record is APPEND-ONLY and can never be corrected, so re-derive the verdict from the
                // shipment's own items rather than trusting a stored column alone. A shipment whose
                // goods did not all arrive has no delivery to verify — recording "received" for it
                // would assert receipt of goods that never came.
                $unarrived = OrderItem::query()->where('shipment_id', $shipment->id)
                    ->whereNotIn('status', ['terkirim', 'pengembalian', 'kembali'])
                    ->exists();
                if ($unarrived) {
                    throw new ApiException(__('messages.delivery_verification.not_delivered'), 422);
                }

                $existing = DeliveryVerification::query()
                    ->where('verified_by', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    // A replay is only a replay of the SAME request; a reused key for a different
                    // shipment/outcome/note is a conflict, never an unrelated record.
                    $this->assertReplayMatches($existing, (int) $shipment->id, $outcome, $normalizedNote);

                    return $existing;
                }

                $verification = DeliveryVerification::create([
                    'shipment_id' => $shipment->id,
                    'outcome' => $outcome,
                    'note' => $normalizedNote,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                ActivityLogger::log($actor->id, $shipment, 'shipment.delivery_verified', $normalizedNote, [
                    'order_id' => $shipment->order_id,
                    'outcome' => $outcome,
                    'verification_id' => $verification->id,
                    'actor_role' => $actor->role?->slug,
                ]);

                return $verification;
            });
        } catch (QueryException $e) {
            // Two concurrent requests with the same Idempotency-Key both passed the pre-check —
            // the unique (verified_by, idempotency_key) index rejected the loser. Return the
            // winner's row when it is the same request, otherwise surface the conflict.
            if (str_contains($e->getMessage(), 'delivery_verifications_verified_by_idempotency_key_unique')) {
                $existing = DeliveryVerification::query()
                    ->where('verified_by', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                $this->assertReplayMatches($existing, (int) $shipment->id, $outcome, $normalizedNote);

                return $existing;
            }

            throw $e;
        }
    }

    /** A reused idempotency key is only valid for an identical canonical request. */
    private function assertReplayMatches(DeliveryVerification $existing, int $shipmentId, string $outcome, ?string $note): void
    {
        if ((int) $existing->shipment_id !== $shipmentId
            || $existing->outcome !== $outcome
            || $existing->note !== $note) {
            throw new ApiException(__('messages.delivery_verification.idempotency_conflict'), 409);
        }
    }

    /** Full append-only history (oldest first) for a shipment. */
    public function history(Shipment $shipment)
    {
        return DeliveryVerification::query()
            ->where('shipment_id', $shipment->id)
            ->with('verifiedBy')
            ->orderBy('id')
            ->get();
    }

    /** The current operational outcome = the latest verification row, or null when never verified. */
    public function latest(Shipment $shipment): ?DeliveryVerification
    {
        return DeliveryVerification::query()
            ->where('shipment_id', $shipment->id)
            ->orderByDesc('id')
            ->first();
    }
}
