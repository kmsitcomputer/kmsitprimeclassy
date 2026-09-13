<?php

namespace App\Http\Controllers\Api\V1\Region;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Province;
use App\Models\Regency;
use App\Models\Village;
use App\Support\SafeSchema;
use Illuminate\Http\Request;

/**
 * Public cascading lookups for the checkout address form's
 * Provinsi -> Kota/Kabupaten -> Kecamatan -> Kelurahan selects. Read-only —
 * the data itself is populated by Super Admin via RegionImportExportController.
 *
 * Reachable on a completely fresh, unmigrated deployment (installer wizard
 * page, bots, monitoring) before the region tables exist. Every method here
 * checks the table it queries — including the ones an `exists:...` request
 * rule would hit below — before touching the database at all: a missing
 * table means "no such region data yet", the same clean empty/invalid
 * result the table's own emptiness would already produce, never a raw
 * QueryException (see SafeSchema's docblock).
 */
class RegionController extends Controller
{
    public function provinces()
    {
        if (! SafeSchema::hasTable('provinces')) {
            return $this->ok([]);
        }

        return $this->ok(Province::query()->orderBy('name')->get(['id', 'name']));
    }

    public function regencies(Request $request)
    {
        if (! SafeSchema::hasTable('provinces')) {
            return $this->ok([]);
        }

        $request->validate(['province_id' => ['required', 'string', 'exists:provinces,id']]);

        return $this->ok(
            Regency::query()->where('province_id', $request->string('province_id'))
                ->orderBy('name')->get(['id', 'name'])
        );
    }

    public function districts(Request $request)
    {
        if (! SafeSchema::hasTable('regencies')) {
            return $this->ok([]);
        }

        $request->validate(['regency_id' => ['required', 'string', 'exists:regencies,id']]);

        return $this->ok(
            District::query()->where('regency_id', $request->string('regency_id'))
                ->orderBy('name')->get(['id', 'name'])
        );
    }

    public function villages(Request $request)
    {
        if (! SafeSchema::hasTable('districts')) {
            return $this->ok([]);
        }

        $request->validate(['district_id' => ['required', 'string', 'exists:districts,id']]);

        return $this->ok(
            Village::query()->where('district_id', $request->string('district_id'))
                ->orderBy('name')->get(['id', 'name'])
        );
    }
}
