<?php

namespace App\Http\Requests\Consent;

use App\Http\Requests\BaseFormRequest;
use App\Services\Consent\ConsentService;
use Illuminate\Validation\Rule;

class CaptureConsentRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('sales', 'sales-kurir-sub', 'korsal', 'agen') ?? false;
    }

    public function rules(): array
    {
        return [
            'subject_user_id' => ['required', 'integer', 'exists:users,id'],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'purpose' => ['required', 'string', Rule::in(ConsentService::PURPOSES)],
            'otp_challenge_id' => ['required', 'integer', 'exists:identity_otp_challenges,id'],
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'note' => ['required', 'string', 'max:1000'],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*.order_item_id' => ['required', 'integer'],
            'scope.*.quantity' => ['required', 'integer', 'min:1'],
            'consent_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
