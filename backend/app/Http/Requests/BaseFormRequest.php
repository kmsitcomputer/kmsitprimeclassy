<?php

namespace App\Http\Requests;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Every Form Request in the app extends this so validation and authorization
 * failures both render through the standard { success, message, data, errors,
 * meta } envelope instead of Laravel's default redirect/JSON shape.
 */
abstract class BaseFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                message: __('messages.system.validation_failed'),
                errors: $validator->errors()->toArray(),
                status: 422,
            )
        );
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            ApiResponse::error(
                message: __('messages.system.unauthorized_action'),
                errors: null,
                status: 403,
            )
        );
    }
}
