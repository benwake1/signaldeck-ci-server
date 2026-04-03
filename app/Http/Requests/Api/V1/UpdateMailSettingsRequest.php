<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Auth\Access\AuthorizationException;

class UpdateMailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'mail_driver'       => ['nullable', 'in:smtp,sendmail,ses,mailgun,log'],
            'mail_host'         => ['nullable', 'string', 'regex:/^[a-zA-Z0-9.\-]+$/'],
            'mail_port'         => ['nullable', 'integer', 'between:1,65535'],
            'mail_username'     => ['nullable', 'string'],
            'mail_password'     => ['nullable', 'string'],
            'mail_encryption'   => ['nullable', 'in:tls,ssl,null'],
            'mail_from_address' => ['nullable', 'email'],
            'mail_from_name'    => ['nullable', 'string'],
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
