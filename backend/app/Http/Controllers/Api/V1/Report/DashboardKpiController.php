<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Services\Report\DashboardKpiService;
use Illuminate\Http\Request;

/** Role-shaped KPI payload; keys not defined for a role are never computed (Admin never receives `own_fee`). */
class DashboardKpiController extends Controller
{
    public function __invoke(Request $request, DashboardKpiService $kpis)
    {
        return $this->ok($kpis->forActor($request->user(), $request->only(['from', 'to'])));
    }
}
