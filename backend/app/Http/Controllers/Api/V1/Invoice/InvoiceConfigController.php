<?php

namespace App\Http\Controllers\Api\V1\Invoice;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\InvoiceConfig;
use App\Services\Invoice\InvoiceConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IMP-002 — invoice configuration management.
 *
 * super_admin manages the GLOBAL row (agent_id=0); each agen (and only the
 * agen) manages its OWN branch row. Admin/keuangan/kurir/etc are not allowed
 * to configure invoices (display-level, but still authorization-scoped).
 */
class InvoiceConfigController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->isRole('super_admin')) {
            return $this->ok(InvoiceConfigService::global());
        }

        if ($user->isRole('agen')) {
            return $this->ok(InvoiceConfigService::forAgent((int) $user->agent_id));
        }

        throw new ApiException('Tidak diizinkan.', 403);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:60'],
            'contact' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'footer' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'show_items' => ['nullable', 'boolean'],
            'show_konsumen' => ['nullable', 'boolean'],
            'show_sales' => ['nullable', 'boolean'],
            'show_korsal' => ['nullable', 'boolean'],
            'show_payment_summary' => ['nullable', 'boolean'],
            'show_voucher' => ['nullable', 'boolean'],
            'logo_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ]);

        if ($user->isRole('super_admin')) {
            $saved = InvoiceConfigService::upsert(InvoiceConfig::GLOBAL_AGENT_ID, $data);

            return $this->ok($saved);
        }

        if ($user->isRole('agen')) {
            $saved = InvoiceConfigService::upsert((int) $user->agent_id, $data);

            return $this->ok($saved);
        }

        throw new ApiException('Tidak diizinkan.', 403);
    }
}