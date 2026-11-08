<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Drives the payment-method-specific confirmation view (COD reminder, bank transfer instructions, gateway instructions). */
class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,
            'status' => $this->status,
            'gateway_reference' => $this->gateway_reference,
            'instructions' => $this->raw_payload,
            'paid_at' => $this->paid_at,
            'bank_transfer_verification' => $this->whenLoaded(
                'bankTransferVerification',
                fn () => $this->bankTransferVerification ? [
                    'id' => $this->bankTransferVerification->id,
                    'status' => $this->bankTransferVerification->status,
                    // The actual photo — an admin verifying a transfer must be
                    // able to look at it, not just know one was submitted.
                    // Legacy proofs keep their public URL; new ones are private and fetched through an
                    // authorization-checked endpoint (never a public storage URL).
                    'proof_url' => $this->bankTransferVerification->proof_image_path
                        ? ($this->bankTransferVerification->proof_disk === 'local'
                            ? '/api/v1/payment-proofs/'.$this->bankTransferVerification->id
                            : Storage::disk('public')->url($this->bankTransferVerification->proof_image_path))
                        : null,
                    'proof_private' => $this->bankTransferVerification->proof_disk === 'local',
                    'channel' => $this->bankTransferVerification->channel,
                    'submitted_amount' => $this->bankTransferVerification->submitted_amount ?? $this->amount,
                    'verified_amount' => $this->bankTransferVerification->verified_amount,
                    'amount_adjustment_reason' => $this->bankTransferVerification->amount_adjustment_reason,
                    'cash_received_by' => $this->payerSummary($this->bankTransferVerification->cashReceivedBy),
                    'cash_received_at' => $this->bankTransferVerification->cash_received_at,
                    'receipt_reference' => $this->bankTransferVerification->receipt_reference,
                    'rejection_reason' => $this->bankTransferVerification->rejection_reason,
                    // IMP-001: payer actor vs. order owner (the konsumen) — the owner is never rewritten.
                    'submitted_on_behalf' => (bool) $this->bankTransferVerification->submitted_on_behalf,
                    'submitted_by' => $this->payerSummary($this->bankTransferVerification->submittedBy),
                    // Audit trio: Pembayar (paid_by) / Bukti di-upload oleh (submitted_by) / Diverifikasi oleh (verified_by).
                    'paid_by' => $this->bankTransferVerification->paid_by_role,
                    'submitted_at' => $this->bankTransferVerification->submitted_at,
                    'verified_by' => $this->payerSummary($this->bankTransferVerification->verifiedBy),
                    'verified_at' => $this->bankTransferVerification->verified_at,
                ] : null
            ),
            'cod_payment_proof' => $this->whenLoaded(
                'codPaymentProof',
                fn () => $this->codPaymentProof ? [
                    'id' => $this->codPaymentProof->id,
                    'status' => $this->codPaymentProof->status,
                    'proof_url' => $this->codPaymentProof->proof?->url(),
                    'rejection_reason' => $this->codPaymentProof->rejection_reason,
                    'submitted_on_behalf' => (bool) $this->codPaymentProof->submitted_on_behalf,
                    'submitted_by' => $this->payerSummary($this->codPaymentProof->submittedBy),
                    'paid_by' => $this->codPaymentProof->paid_by_role,
                    'submitted_at' => $this->codPaymentProof->submitted_at,
                    'verified_by' => $this->payerSummary($this->codPaymentProof->confirmedBy),
                    'verified_at' => $this->codPaymentProof->confirmed_at,
                ] : null
            ),
        ];
    }

    /** @return array{id:int,name:string,role:?string}|null */
    private function payerSummary(?\App\Models\User $payer): ?array
    {
        return $payer ? ['id' => $payer->id, 'name' => $payer->name, 'role' => $payer->role?->slug] : null;
    }
}
