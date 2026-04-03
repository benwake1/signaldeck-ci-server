<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Authenticate a user and issue a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Refresh user to pick up any role changes that occurred after Auth::attempt
        $user = Auth::user()->fresh();

        // Revoke any existing desktop-app tokens (single active token policy)
        $user->tokens()->where('name', 'desktop-app')->delete();

        $abilities = match ($user->role) {
            'admin' => ['desktop:read', 'desktop:write', 'desktop:admin'],
            'pm' => ['desktop:read', 'desktop:write'],
            default => ['desktop:read'],
        };

        $token = $user->createToken('desktop-app', $abilities)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'abilities' => $abilities,
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Refresh the current token (revoke and re-issue with same abilities).
     */
    public function refresh(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();
        $abilities = $currentToken->abilities;

        $currentToken->delete();

        $newToken = $request->user()->createToken('desktop-app', $abilities);

        return response()->json([
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at,
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
