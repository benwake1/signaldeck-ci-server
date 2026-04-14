<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

class S3ConfigService
{
    /**
     * Overlay S3 filesystem config from DB-stored AppSettings.
     *
     * Called from AppServiceProvider::boot() — same lifecycle as applyMailSettings()
     * and applySsoSettings(). Must NOT be called from bootstrap/app.php because
     * Eloquent is not available at that point.
     *
     * Wrapped in try/catch by the caller so migrations don't break the boot cycle.
     */
    public static function loadFromSettings(): void
    {
        $bucket = AppSetting::get('s3_bucket');

        if (!$bucket) {
            return; // S3 not configured; honour FILESYSTEM_DISK / .env as-is.
        }

        Config::set('filesystems.disks.s3', array_merge(
            Config::get('filesystems.disks.s3', []),
            [
                'driver'                  => 's3',
                'key'                     => AppSetting::get('s3_key'),
                'secret'                  => self::decryptSecret(AppSetting::get('s3_secret', '')),
                'region'                  => AppSetting::get('s3_region'),
                'bucket'                  => $bucket,
                'endpoint'                => AppSetting::get('s3_endpoint') ?: null,
                'use_path_style_endpoint' => (bool) AppSetting::get('s3_use_path_style', false),
            ]
        ));

        Config::set('filesystems.default', 's3');
    }

    /**
     * Decrypt the stored S3 secret, falling back to the raw value for any
     * pre-encryption plaintext already in the database.
     */
    private static function decryptSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value; // legacy plaintext — will be re-encrypted on next save
        }
    }
}
