<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Requests\Api\V1;

use App\Services\SsoConfigService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Auth\Access\AuthorizationException;

class UpdateSsoSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'provider'      => ['required', 'string', 'in:' . implode(',', array_keys(SsoConfigService::PROVIDERS))],
            'enabled'       => ['required', 'boolean'],
            'client_id'     => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
        ];
    }

    protected function failedAuthorization()
    {
        throw new AuthorizationException(
            json_encode(['message' => 'You are not authorized to perform this action.']),
            403
        );
    }
}
