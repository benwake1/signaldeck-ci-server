<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSO / OAuth Providers
    |--------------------------------------------------------------------------
    |
    | These values serve as fallbacks when the admin has not yet configured
    | SSO credentials via the Settings page. Once configured in the UI,
    | database values take precedence (applied at runtime by SsoConfigService).
    |
    */

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', '/admin/oauth/callback/google'),
    ],

    'github' => [
        'client_id'     => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect'      => env('GITHUB_REDIRECT_URI', '/admin/oauth/callback/github'),
    ],

];
