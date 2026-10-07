<?php

namespace App\Http\Requests\Courier;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class AssignCourierRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // OrderPolicy::assignCourier checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            // A1-01: `courier_id: 0` is the explicit self-executor sentinel — a
            // Koordinator-Kurir assigning the delivery to ITSELF. It never reaches
            // the DB; ShipmentController::assign resolves the actor's own Courier
            // profile (created lazily) before calling CourierService. Any other
            // courier_id must reference an existing Courier row.
            'courier_id' => [
                'required', 'integer',
                Rule::when(fn () => $this->integer('courier_id') !== 0, ['exists:couriers,id']),
            ],
        ];
    }

    public function wantsCourier(): bool
    {
        // A1-01: only a Koordinator-Kurir may use the 0 self-executor sentinel.
        return (int) $this->integer('courier_id') !== 0;
    }
}
