<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Services\Logging\ActivityLogger;
use App\Services\Shipping\KurirTokoTariff;
use Illuminate\Http\Request;

/**
 * The signed-in Agen's own Kurir Toko tariff. Always `$request->user()`'s row — no id parameter, so another Agen's tariff is not
 * addressable. Independent of ORS/KMSIT: it can be read and edited while either (or both) is off or unconfigured.
 */
class AgentShippingTariffController extends Controller
{
    public function show(Request $request)
    {
        return $this->ok(['tariff' => KurirTokoTariff::present(KurirTokoTariff::for($request->user()->id))]);
    }

    public function update(Request $request)
    {
        $v = $request->validate([
            'price_per_km' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'minimum_distance_km' => ['required', 'numeric', 'min:0', 'max:100000'],
            'minimum_charge' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'free_shipping_enabled' => ['sometimes', 'boolean'],
            'free_shipping_min_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
        ]);

        $agentId = $request->user()->id;
        $before = KurirTokoTariff::present(KurirTokoTariff::for($agentId));
        $row = KurirTokoTariff::save($agentId, $v);

        ActivityLogger::log($agentId, $row, 'agent_shipping_tariff.updated', null, ['old' => $before, 'new' => KurirTokoTariff::present($row)]);

        return $this->ok(['tariff' => KurirTokoTariff::present($row)], __('messages.shipping.provider_config_saved'));
    }
}
