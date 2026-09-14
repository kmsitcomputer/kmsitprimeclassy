<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingCourier;
use App\Services\Logging\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Super Admin-only: the provider-supported courier master list (see the
 * shipping_couriers migration's docblock) — this is the "SUPPORTED" half
 * an agen's own courier checkboxes are validated against. Deliberately not
 * hardcoded in PHP (seeded as a researched starting point by
 * ShippingCourierSeeder, but correctable here without a code deploy if
 * Komerce's actual account entitlement differs).
 */
class ShippingCourierController extends Controller
{
    public function index()
    {
        return $this->ok(ShippingCourier::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', 'unique:shipping_couriers,code'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $courier = ShippingCourier::create(['code' => strtolower($validated['code']), 'name' => $validated['name'], 'is_active' => true]);

        ActivityLogger::log($request->user()->id, $courier, 'shipping_courier.created', null, ['code' => $courier->code, 'name' => $courier->name]);

        return $this->created($courier);
    }

    public function toggle(Request $request, ShippingCourier $shippingCourier)
    {
        $shippingCourier->update(['is_active' => ! $shippingCourier->is_active]);

        ActivityLogger::log($request->user()->id, $shippingCourier, 'shipping_courier.toggled', null, ['is_active' => $shippingCourier->is_active]);

        return $this->ok($shippingCourier->fresh());
    }
}
