<?php

namespace App\Http\Requests\Consent;

use App\Http\Requests\BaseFormRequest;
use App\Services\Consent\ConsentService;
use Illuminate\Validation\Rule;

class IssueOtpRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isRole('sales', 'sales-kurir-sub', 'korsal', 'agen') ?? false;
    }

    public function rules(): array
    {
        return [
            'subject_user_id' => ['required', 'integer', 'exists:users,id'],
            'purpose' => ['required', 'string', Rule::in(ConsentService::PURPOSES)],
            // email (default) | whatsapp. WhatsApp is refused unless the number is PROVEN (409), see WhatsAppOtpChannel.
            'channel' => ['nullable', 'string', Rule::in(['email', 'whatsapp'])],
        ];
    }
}
