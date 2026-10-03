<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    private const SECRET_KEYS = [
        'ai_anthropic_api_key',
        'ai_compat_api_key',
        'mail_password',
        'sso_google_client_secret',
        'sso_github_client_secret',
        'slack_bot_token',
        'slack_signing_secret',
        's3_secret',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        if (in_array($this->key, self::SECRET_KEYS, true) && isset($array['value'])) {
            $array['value'] = '********';
        }

        return $array;
    }
}
