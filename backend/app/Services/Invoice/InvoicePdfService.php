<?php

namespace App\Services\Invoice;

use App\Models\Order;
use App\Services\Payment\PaymentSummaryService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * IMP-002 — dynamic invoice PDF.
 *
 * NOT Print Resi (Package B ShipmentReceipt is untouched). This is the
 * order-level commercial invoice. Financial truth derives exclusively from
 * canonical services: OrderTotalCalculator totals + PaymentSummaryService
 * (verified/paid/remaining/DP) — never recomputed here.
 *
 * Rendering is a Locale-scoped Blade view (no arbitrary template execution;
 * the config is structured JSON display settings only).
 *
 * Viewer-aware projection (A1-13): the consumer-facing PDF must never
 * contain internal referral identities — the same boundary the generic
 * OrderResource enforces. `$viewerIsOwner` (true only when the requesting
 * user is the order's own konsumen) drops the Sales/Korsal block regardless
 * of branch/global show flags; staff viewers retain whatever the branch
 * config permits.
 *
 * A1-22: the persisted presentation config is honored — show_konsumen toggles
 * the recipient block, and a logo_media_id resolves to its public URL only
 * when the media row exists and belongs to the branch's own uploads (an
 * unauthorized/cross-agent logo can never be embedded).
 */
class InvoicePdfService
{
    /**
     * @param  bool  $viewerIsOwner  true when the requesting user is the order's own konsumen
     */
    public function render(Order $order, array $config, bool $viewerIsOwner = false): \Barryvdh\DomPDF\PDF
    {
        $summary = PaymentSummaryService::summarize($order);

        $data = [
            'order' => $order,
            'config' => $config,
            // A1-13: internal referral identity never reaches a consumer PDF.
            'showInternalReferral' => ! $viewerIsOwner,
            // A1-22: recipient block toggle is honored (never at the cost of a
            // consumer learning someone else's identity — this toggle only
            // ever HIDES content, which is always privacy-safe).
            'showRecipient' => (bool) ($config['show_konsumen'] ?? true),
            // A1-22: resolved logo URL — null when missing/foreign/disabled.
            'logo_url' => $this->resolveLogoUrl($config, (int) $order->agent_id),
            'summary' => $summary,
            'items' => $order->items()->with('shipment')->get(),
            'now' => now(),
        ];

        return Pdf::loadView('pdf.invoice', $data)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', false);
    }

    /**
     * A logo is only embedded when:
     *  - the config explicitly set logo_media_id;
     *  - the media row exists and was uploaded by this branch's agent
     *    (cross-agent media is rejected — same ownership rule as every other
     *    media-scoped read).
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveLogoUrl(array $config, int $agentId): ?string
    {
        $mediaId = (int) ($config['logo_media_id'] ?? 0);
        if ($mediaId <= 0) {
            return null;
        }

        $media = \App\Models\Media::query()->find($mediaId);

        // The uploader must be the branch's own agent (or its super_admin):
        // a foreign-uploaded logo is never embedded.
        if (! $media) {
            return null;
        }

        $owner = $media->uploadedBy;
        $legit = $owner !== null
            && ($owner->isRole('super_admin')
                || ($owner->isRole('agen') && (int) $owner->agent_id === $agentId));

        if (! $legit || ! in_array($media->mime_type, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($media->disk);
        if (! $disk->exists($media->path)) {
            return null;
        }
        $bytes = $disk->get($media->path);
        $image = @getimagesizefromstring($bytes);
        if (! $image || ! in_array($image['mime'] ?? null, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        // Embed validated image bytes: DomPDF remote loading remains disabled.
        return 'data:'.$image['mime'].';base64,'.base64_encode($bytes);
    }
}