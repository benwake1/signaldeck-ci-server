<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSlackSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'slack_notifications_enabled' => ['sometimes', 'boolean'],
            'slack_bot_token'             => ['sometimes', 'nullable', 'string'],
            'slack_signing_secret'        => ['sometimes', 'nullable', 'string'],
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
