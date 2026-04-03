<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

class SsoConfigService
{
    /**
     * Supported SSO providers and their display metadata.
     */
    public const PROVIDERS = [
        'google' => [
            'label' => 'Google',
            'color' => '#4285F4',
            'redirect_path' => '/admin/oauth/callback/google',
            'callback_scheme' => 'cypressdashboard',
        ],
        'github' => [
            'label' => 'GitHub',
            'color' => '#24292F',
            'redirect_path' => '/admin/oauth/callback/github',
            'callback_scheme' => 'cypressdashboard',
        ],
    ];

    /**
     * Check if a specific SSO provider is enabled.
     */
    public function isProviderEnabled(string $provider): bool
    {
        return AppSetting::get("sso_{$provider}_enabled", '0') === '1';
    }

    /**
     * Check if SSO settings have ever been saved via the admin UI.
     * When false, the .env fallback is used for backwards compatibility.
     * Once any provider toggle has been explicitly set, DB takes over.
     */
    public function hasDbSettings(): bool
    {
        try {
            foreach (array_keys(self::PROVIDERS) as $provider) {
                if (AppSetting::get("sso_{$provider}_enabled") !== null) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Table doesn't exist yet (pre-migration) — fall back to .env
            return false;
        }

        return false;
    }

    /**
     * Get decrypted client ID for a provider.
     */
    public function getClientId(string $provider): string
    {
        return $this->decryptSetting("sso_{$provider}_client_id");
    }

    /**
     * Get decrypted client secret for a provider.
     */
    public function getClientSecret(string $provider): string
    {
        return $this->decryptSetting("sso_{$provider}_client_secret");
    }

    /**
     * Get the redirect URI for a provider.
     */
    public function getRedirectUri(string $provider): string
    {
        $meta = self::PROVIDERS[$provider] ?? null;

        return $meta
            ? url($meta['redirect_path'])
            : '';
    }

    /**
     * Check whether a provider has all required credentials configured.
     */
    public function isProviderConfigured(string $provider): bool
    {
        return $this->getClientId($provider) !== ''
            && $this->getClientSecret($provider) !== '';
    }

    /**
     * Get the list of providers that are both enabled and configured.
     */
    public function getActiveProviders(): array
    {
        $active = [];

        foreach (array_keys(self::PROVIDERS) as $provider) {
            if ($this->isProviderEnabled($provider) && $this->isProviderConfigured($provider)) {
                $active[] = $provider;
            }
        }

        return $active;
    }

    /**
     * Save an encrypted credential for a provider.
     */
    public function setCredential(string $provider, string $key, string $value): void
    {
        AppSetting::set("sso_{$provider}_{$key}", $value !== '' ? Crypt::encryptString($value) : '');
    }

    /**
     * Apply active SSO provider configs into Laravel's services config at runtime.
     * This allows Socialite to read credentials from the database instead of .env.
     */
    public function applyRuntimeConfig(): void
    {
        foreach ($this->getActiveProviders() as $provider) {
            config([
                "services.{$provider}.client_id"     => $this->getClientId($provider),
                "services.{$provider}.client_secret"  => $this->getClientSecret($provider),
                "services.{$provider}.redirect"        => $this->getRedirectUri($provider),
            ]);
        }
    }

    /**
     * Decrypt a stored setting, gracefully handling unencrypted legacy values.
     */
    private function decryptSetting(string $key): string
    {
        $stored = AppSetting::get($key, '');

        if (! $stored) {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Exception) {
            // Value stored before encryption was added — return as-is
            return $stored;
        }
    }
}
