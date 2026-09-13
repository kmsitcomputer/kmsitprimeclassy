<?php

namespace App\Http\Controllers\Api\V1\Address;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\StoreKonsumenAddressRequest;
use App\Http\Resources\KonsumenAddressResource;
use App\Models\KonsumenAddress;
use App\Models\Village;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A konsumen's own saved addresses only — never another konsumen's (scoped by user_id on every query). */
class KonsumenAddressController extends Controller
{
    public function index(Request $request)
    {
        $addresses = KonsumenAddress::query()
            ->where('user_id', $request->user()->id)
            ->with('village.district.regency.province')
            ->latest()
            ->get();

        return $this->ok(KonsumenAddressResource::collection($addresses)->resolve());
    }

    public function store(StoreKonsumenAddressRequest $request)
    {
        $village = Village::query()->with('district.regency.province')->findOrFail($request->input('village_id'));

        $address = DB::transaction(function () use ($request, $village) {
            if ($request->boolean('is_default')) {
                KonsumenAddress::query()->where('user_id', $request->user()->id)->update(['is_default' => false]);
            }

            return KonsumenAddress::create([
                'user_id' => $request->user()->id,
                'label' => $request->input('label', 'Rumah'),
                'recipient_name' => $request->input('recipient_name'),
                'phone' => $request->input('phone'),
                'address_line' => $request->input('address_line'),
                'village_id' => $village->id,
                'district_id' => $village->district_id,
                'regency_id' => $village->district->regency_id,
                'province_id' => $village->district->regency->province_id,
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        return $this->created(new KonsumenAddressResource($address->load('village.district.regency.province')), __('messages.address.created'));
    }

    public function update(StoreKonsumenAddressRequest $request, KonsumenAddress $address)
    {
        $this->authorizeOwnership($request, $address);

        $village = Village::query()->with('district.regency.province')->findOrFail($request->input('village_id'));

        DB::transaction(function () use ($request, $address, $village) {
            if ($request->boolean('is_default')) {
                KonsumenAddress::query()->where('user_id', $request->user()->id)->update(['is_default' => false]);
            }

            $address->update([
                'label' => $request->input('label', $address->label),
                'recipient_name' => $request->input('recipient_name'),
                'phone' => $request->input('phone'),
                'address_line' => $request->input('address_line'),
                'village_id' => $village->id,
                'district_id' => $village->district_id,
                'regency_id' => $village->district->regency_id,
                'province_id' => $village->district->regency->province_id,
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'is_default' => $request->boolean('is_default'),
            ]);
        });

        return $this->ok(new KonsumenAddressResource($address->fresh('village.district.regency.province')), __('messages.address.updated'));
    }

    public function destroy(Request $request, KonsumenAddress $address)
    {
        $this->authorizeOwnership($request, $address);
        $address->delete();

        return $this->ok(null, __('messages.address.deleted'));
    }

    private function authorizeOwnership(Request $request, KonsumenAddress $address): void
    {
        if ($address->user_id !== $request->user()->id) {
            abort(404);
        }
    }
}
