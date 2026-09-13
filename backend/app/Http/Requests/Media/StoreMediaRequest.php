<?php

namespace App\Http\Requests\Media;

use App\Http\Requests\BaseFormRequest;

class StoreMediaRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // MediaPolicy::create checked explicitly in the controller.
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'collection' => ['required', 'string', 'in:'.implode(',', array_keys(config('media.collections')))],
        ];
    }
}
