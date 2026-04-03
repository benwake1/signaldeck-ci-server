<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SsoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

class SsoAuthController extends Controller
{
    /**
     * List active SSO providers with their display metadata.
     */
    public function providers(SsoConfigService $ssoConfig): JsonResponse
    {
        $active = $ssoConfig->getActiveProviders();

        $providers = collect($active)->map(function (string $provider) {
            $meta = SsoConfigService::PROVIDERS[$provider];

            return [
                'name'            => $provider,
                'label'           => $meta['label'],
                'color'           => $meta['color'],
                'callback_scheme' => $meta['callback_scheme'],
            ];
        })->values();

        return response()->json($providers);
    }

    /**
     * Return the OAuth redirect URL for the given provider.
     */
    public function redirect(string $provider, SsoConfigService $ssoConfig): JsonResponse
    {
        if (! $this->isValidProvider($provider, $ssoConfig)) {
            return response()->json(['message' => 'Invalid or inactive SSO provider.'], 422);
        }

        config(["services.{$provider}.redirect" => $this->apiCallbackUrl($provider)]);

        $redirectUrl = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

        return response()->json(['redirect_url' => $redirectUrl]);
    }

    /**
     * Handle the OAuth callback from the provider.
     */
    public function callback(string $provider, SsoConfigService $ssoConfig): RedirectResponse
    {
        if (! $this->isValidProvider($provider, $ssoConfig)) {
            abort(422, 'Invalid or inactive SSO provider.');
        }

        config(["services.{$provider}.redirect" => $this->apiCallbackUrl($provider)]);

        $socialiteUser = Socialite::driver($provider)->stateless()->user();

        $user = User::firstOrCreate(
            ['email' => $socialiteUser->getEmail()],
            [
                'name' => $socialiteUser->getName(),
                'avatar_url' => $socialiteUser->getAvatar(),
                'password' => bcrypt(str()->random(32)),
                'role' => 'pm',
            ]
        );

        // Revoke any existing desktop-app tokens
        $user->tokens()->where('name', 'desktop-app')->delete();

        $abilities = match ($user->role) {
            'admin' => ['desktop:read', 'desktop:write', 'desktop:admin'],
            'pm' => ['desktop:read', 'desktop:write'],
            default => ['desktop:read'],
        };

        $token = $user->createToken('desktop-app', $abilities)->plainTextToken;

        $params = http_build_query([
            'token' => $token,
            'user_name' => $user->name,
            'user_email' => $user->email,
        ]);

        $scheme = SsoConfigService::PROVIDERS[$provider]['callback_scheme'];

        return redirect("{$scheme}://auth?{$params}");
    }

    /**
     * Check that the provider key exists and is currently active.
     */
    private function isValidProvider(string $provider, SsoConfigService $ssoConfig): bool
    {
        return array_key_exists($provider, SsoConfigService::PROVIDERS)
            && in_array($provider, $ssoConfig->getActiveProviders(), true);
    }

    private function apiCallbackUrl(string $provider): string
    {
        return url("/api/v1/auth/sso/{$provider}/callback");
    }
}
