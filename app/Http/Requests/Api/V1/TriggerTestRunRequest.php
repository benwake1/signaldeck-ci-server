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
use Illuminate\Validation\Rule;

class TriggerTestRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id'    => ['required', 'exists:projects,id,deleted_at,NULL'],
            'test_suite_id' => ['required', Rule::exists('test_suites', 'id')
                ->where('project_id', $this->input('project_id'))
                ->whereNull('deleted_at')],
            'branch'        => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\-_.\/]+$/'],
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
