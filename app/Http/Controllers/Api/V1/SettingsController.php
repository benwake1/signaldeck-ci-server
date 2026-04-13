<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMailSettingsRequest;
use App\Http\Requests\Api\V1\UpdateSettingsRequest;
use App\Http\Requests\Api\V1\UpdateSlackSettingsRequest;
use App\Http\Requests\Api\V1\UpdateSsoSettingsRequest;
use App\Jobs\MigrateArtifactsToS3Job;
use App\Models\AppSetting;
use App\Models\TestRun;
use App\Services\SlackService;
use App\Services\SsoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'notifications_enabled' => (bool) AppSetting::get('notifications_enabled', '1'),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated() as $key => $value) {
            AppSetting::set($key, $value);
        }

        return response()->json(['message' => 'Settings updated.']);
    }

    public function mail(): JsonResponse
    {
        return response()->json([
            'mail_driver'       => AppSetting::get('mail_driver', config('mail.default')),
            'mail_host'         => AppSetting::get('mail_host', config('mail.mailers.smtp.host')),
            'mail_port'         => AppSetting::get('mail_port', config('mail.mailers.smtp.port')),
            'mail_username'     => AppSetting::get('mail_username', config('mail.mailers.smtp.username')),
            'mail_has_password' => (bool) AppSetting::get('mail_password'),
            'mail_encryption'   => AppSetting::get('mail_encryption', config('mail.mailers.smtp.encryption')),
            'mail_from_address' => AppSetting::get('mail_from_address', config('mail.from.address')),
            'mail_from_name'    => AppSetting::get('mail_from_name', config('mail.from.name')),
        ]);
    }

    public function updateMail(UpdateMailSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        foreach ($data as $key => $value) {
            if ($key === 'mail_password' && $value !== null) {
                AppSetting::set($key, Crypt::encryptString($value));
            } else {
                AppSetting::set($key, $value);
            }
        }

        return response()->json(['message' => 'Mail settings updated.']);
    }

    public function testMail(): JsonResponse
    {
        $map = [
            'mail_mailer'       => 'mail.default',
            'mail_from_address' => 'mail.from.address',
            'mail_from_name'    => 'mail.from.name',
            'mail_host'         => 'mail.mailers.smtp.host',
            'mail_port'         => 'mail.mailers.smtp.port',
            'mail_username'     => 'mail.mailers.smtp.username',
            'mail_password'     => 'mail.mailers.smtp.password',
            'mail_encryption'   => 'mail.mailers.smtp.encryption',
        ];

        foreach ($map as $setting => $configKey) {
            $value = AppSetting::get($setting);
            if ($setting === 'mail_password' && $value) {
                try {
                    $value = \Illuminate\Support\Facades\Crypt::decryptString($value);
                } catch (\Exception) {
                    // Stored before encryption was added — use as-is
                }
            }
            if ($value !== null && $value !== '') {
                \Illuminate\Support\Facades\Config::set($configKey, $value);
            }
        }

        Mail::purge(config('mail.default'));

        try {
            $user = request()->user();
            Mail::raw('This is a test email from Cypress Dashboard.', function ($message) use ($user) {
                $message->to($user->email)->subject('Test Email — Cypress Dashboard');
            });

            return response()->json(['message' => 'Test email sent to '.$user->email]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send: '.$e->getMessage()], 500);
        }
    }

    public function sso(SsoConfigService $ssoConfig): JsonResponse
    {
        $providers = [];

        foreach (SsoConfigService::PROVIDERS as $key => $meta) {
            $providers[$key] = [
                'label'       => $meta['label'],
                'enabled'     => $ssoConfig->isProviderEnabled($key),
                'configured'  => $ssoConfig->isProviderConfigured($key),
                'client_id'   => $ssoConfig->getClientId($key) ? '••••••••' : null,
                'has_secret'  => (bool) $ssoConfig->getClientSecret($key),
                'redirect_uri' => $ssoConfig->getRedirectUri($key),
            ];
        }

        return response()->json(['providers' => $providers]);
    }

    // MARK: - Slack

    public function slack(): JsonResponse
    {
        return response()->json([
            'slack_notifications_enabled' => AppSetting::get('slack_notifications_enabled', '0') === '1',
            'slack_has_bot_token'         => (bool) AppSetting::get('slack_bot_token'),
            'slack_has_signing_secret'    => (bool) AppSetting::get('slack_signing_secret'),
        ]);
    }

    public function updateSlack(UpdateSlackSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('slack_notifications_enabled', $data)) {
            AppSetting::set('slack_notifications_enabled', $data['slack_notifications_enabled'] ? '1' : '0');
        }

        foreach (['slack_bot_token', 'slack_signing_secret'] as $key) {
            if (! empty($data[$key])) {
                AppSetting::set($key, Crypt::encryptString($data[$key]));
            }
        }

        return response()->json(['message' => 'Slack settings updated.']);
    }

    public function testSlack(SlackService $slack): JsonResponse
    {
        $result = $slack->testConnection();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    // MARK: - Storage / S3

    public function storage(): JsonResponse
    {
        $pendingCount = TestRun::where(function ($q) {
            $q->whereNull('storage_disk')->orWhere('storage_disk', '!=', 's3');
        })->whereNotNull('report_html_path')->count();

        return response()->json([
            's3_bucket'          => AppSetting::get('s3_bucket'),
            's3_region'          => AppSetting::get('s3_region'),
            's3_key'             => AppSetting::get('s3_key'),
            's3_has_secret'      => (bool) AppSetting::get('s3_secret'),
            's3_endpoint'        => AppSetting::get('s3_endpoint'),
            's3_use_path_style'  => AppSetting::get('s3_use_path_style') === '1',
            'is_configured'      => (bool) AppSetting::get('s3_bucket'),
            'migration_running'  => AppSetting::get('s3_migration_running') === '1',
            'pending_migration_count' => $pendingCount,
        ]);
    }

    public function updateStorage(Request $request): JsonResponse
    {
        $data = $request->validate([
            's3_bucket'         => 'nullable|string|max:255',
            's3_region'         => 'nullable|string|max:100',
            's3_key'            => 'nullable|string|max:255',
            's3_secret'         => 'nullable|string|max:512',
            's3_endpoint'       => 'nullable|url|max:255',
            's3_use_path_style' => 'boolean',
        ]);

        AppSetting::set('s3_bucket',         $data['s3_bucket'] ?? '');
        AppSetting::set('s3_region',         $data['s3_region'] ?? '');
        AppSetting::set('s3_key',            $data['s3_key'] ?? '');
        AppSetting::set('s3_endpoint',       $data['s3_endpoint'] ?? '');
        AppSetting::set('s3_use_path_style', ($data['s3_use_path_style'] ?? false) ? '1' : '0');

        if (!empty($data['s3_secret'])) {
            AppSetting::set('s3_secret', $data['s3_secret']);
        }

        return response()->json(['message' => 'Storage settings updated.']);
    }

    public function migrateStorage(): JsonResponse
    {
        if (!AppSetting::get('s3_bucket')) {
            return response()->json(['message' => 'S3 is not configured.'], 422);
        }

        if (AppSetting::get('s3_migration_running') === '1') {
            return response()->json(['message' => 'Migration already running.'], 422);
        }

        AppSetting::set('s3_migration_running', '1');
        MigrateArtifactsToS3Job::dispatch();

        return response()->json(['message' => 'Migration queued.']);
    }

    public function updateSso(UpdateSsoSettingsRequest $request, SsoConfigService $ssoConfig): JsonResponse
    {
        $data     = $request->validated();
        $provider = $data['provider'];

        AppSetting::set("sso_{$provider}_enabled", $data['enabled'] ? '1' : '0');

        if (! empty($data['client_id'])) {
            $ssoConfig->setCredential($provider, 'client_id', $data['client_id']);
        }

        if (! empty($data['client_secret'])) {
            $ssoConfig->setCredential($provider, 'client_secret', $data['client_secret']);
        }

        $ssoConfig->applyRuntimeConfig();

        return response()->json(['message' => "SSO settings for {$provider} updated."]);
    }
}
