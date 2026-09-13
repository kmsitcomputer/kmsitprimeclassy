<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportRegionCsvRequest;
use App\Services\Logging\ActivityLogger;
use App\Services\Region\RegionCsvService;

/** Super Admin-only: export/import the province/regency/district/village reference data as CSV. */
class RegionImportExportController extends Controller
{
    public function __construct(private readonly RegionCsvService $regionCsvService) {}

    public function export()
    {
        return $this->regionCsvService->export();
    }

    public function import(ImportRegionCsvRequest $request)
    {
        $counts = $this->regionCsvService->import($request->file('file'));

        ActivityLogger::log($request->user()->id, $request->user(), 'region.csv_imported', null, $counts);

        return $this->ok($counts, __('messages.region.imported', [
            'provinces' => $counts['provinces'], 'regencies' => $counts['regencies'],
            'districts' => $counts['districts'], 'villages' => $counts['villages'],
        ]));
    }
}
