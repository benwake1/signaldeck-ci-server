<?php

namespace App\Filament\Pages\Concerns;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Shared secret-field handling for settings pages.
 *
 * Secrets are never sent to the browser — a sentinel placeholder is shown instead.
 * On save, the placeholder is detected and the existing DB value is preserved.
 */
trait HandlesSecretFields
{
    private const SECRET_PLACEHOLDER = '••••••••';

    /**
     * Return the placeholder sentinel if a secret exists in the DB,
     * or empty string if not. The actual secret never reaches the browser.
     */
    protected function maskSecret(string $settingKey): string
    {
        $stored = AppSetting::get($settingKey, '');

        return ($stored !== '') ? self::SECRET_PLACEHOLDER : '';
    }

    /**
     * Only write a secret to the DB if the admin actually changed it.
     * Placeholder = keep existing. Empty = clear. Anything else = encrypt & store.
     */
    protected function saveSecretIfChanged(string $settingKey, string $value): void
    {
        if ($value === self::SECRET_PLACEHOLDER) {
            return;
        }

        if ($value === '') {
            AppSetting::set($settingKey, '');
            return;
        }

        AppSetting::set($settingKey, Crypt::encryptString($value));
    }
}
