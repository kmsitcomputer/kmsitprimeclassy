<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\BaseFormRequest;
use App\Support\WebsiteSettingKeys;

class UpdateWebsiteSettingsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage-system-config') checked explicitly in the controller.
    }

    public function rules(): array
    {
        $rules = [];

        foreach (WebsiteSettingKeys::keys() as $key) {
            $rules[$key] = WebsiteSettingKeys::isJson($key) ? ['sometimes', 'array'] : ['sometimes', 'nullable', 'string', 'max:2000'];
        }

        $rules['site_logo_media_id'] = ['sometimes', 'nullable', 'integer', 'exists:media,id'];
        $rules['site_favicon_media_id'] = ['sometimes', 'nullable', 'integer', 'exists:media,id'];
        $rules['site_contact_email'] = ['sometimes', 'nullable', 'email', 'max:255'];

        return $rules;
    }
}
