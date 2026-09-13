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
                    'status' => $this->bankTransferVerification->status,
                    // The actual photo — an admin verifying a transfer must be
                    // able to look at it, not just know one was submitted.
                    'proof_url' => $this->bankTransferVerification->proof_image_path
                        ? Storage::disk('public')->url($this->bankTransferVerification->proof_image_path)
                        : null,
                    'rejection_reason' => $this->bankTransferVerification->rejection_reason,
                ] : null
            ),
            'cod_payment_proof' => $this->whenLoaded(
                'codPaymentProof',
                fn () => $this->codPaymentProof ? [
                    'id' => $this->codPaymentProof->id,
                    'status' => $this->codPaymentProof->status,
                    'proof_url' => $this->codPaymentProof->proof?->url(),
                    'rejection_reason' => $this->codPaymentProof->rejection_reason,
                ] : null
            ),
        ];
    }
}
