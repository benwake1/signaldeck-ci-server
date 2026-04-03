<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'avatar_url'    => $this->avatar_url,
            'slack_user_id' => $this->slack_user_id,
            'role'          => $this->role,
            'created_at'    => $this->created_at,
        ];
    }
}
