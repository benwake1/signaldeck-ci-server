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

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'client_id'      => ['sometimes', 'exists:clients,id'],
            'name'           => ['sometimes', 'string', 'max:255'],
            'repo_url'       => ['sometimes', 'string', 'regex:/^(https?:\/\/.+|git@.+:.+\.git)$/'],
            'repo_provider'  => ['nullable', 'string', 'in:github,gitlab,bitbucket'],
            'default_branch' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\-_.\/]+$/'],
            'runner_type'    => ['sometimes', 'string', 'in:cypress,playwright'],
            'env_variables'  => ['nullable', 'array'],
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
