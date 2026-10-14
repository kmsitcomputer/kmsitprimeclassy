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
                'nullable', 'integer',
                Rule::requiredIf(fn () => ! $this->filled('executor_user_id')),
                Rule::when(fn () => $this->filled('courier_id') && $this->integer('courier_id') !== 0, ['exists:couriers,id']),
                Rule::when(fn () => $this->filled('executor_user_id'), ['prohibits:executor_user_id']),
            ],
            // CR-SALES-KURIR-SUB-DELIVERY-001: an eligible Sales-Kurir-Sub executor
            // WITHOUT a Courier row yet is referenced by user id; the controller
            // resolves (and safely creates) the profile on this write path —
            // never on GET. Exactly one of courier_id / executor_user_id.
            'executor_user_id' => [
                'nullable', 'integer', 'exists:users,id',
                Rule::requiredIf(fn () => ! $this->filled('courier_id')),
            ],
        ];
    }

    public function wantsCourier(): bool
    {
        // A1-01: only a Koordinator-Kurir may use the 0 self-executor sentinel.
        return (int) $this->integer('courier_id') !== 0;
    }

    public function wantsSalesKurirSubExecutor(): bool
    {
        return $this->filled('executor_user_id');
    }
}
