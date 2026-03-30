<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Auth\Access\AuthorizationException;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'client_id'      => ['required', 'exists:clients,id'],
            'name'           => ['required', 'string', 'max:255'],
            'repo_url'       => ['required', 'string', 'regex:/^(https?:\/\/.+|git@.+:.+\.git)$/'],
            'repo_provider'  => ['nullable', 'string', 'in:github,gitlab,bitbucket'],
            'default_branch' => ['nullable', 'string', 'max:255'],
            'runner_type'    => ['required', 'string', 'in:cypress,playwright'],
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
