<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\WarehouseSetting;
use App\Services\Stock\WarehouseStockService;
use Illuminate\Http\Request;

class WarehouseSettingsController extends Controller
{
    public function __construct(private readonly WarehouseStockService $stocks) {}

    public function show(Request $request)
    {
        $setting = WarehouseSetting::firstOrCreate(['agent_id' => $request->user()->agent_id]);

        return $this->ok($setting);
    }

    public function update(Request $request)
    {
        $request->validate(['factory_plan_enabled' => ['required', 'boolean']]);
        $setting = $this->stocks->updateFactoryPlanSetting($request->user(), $request->boolean('factory_plan_enabled'));

        return $this->ok($setting);
    }
}
