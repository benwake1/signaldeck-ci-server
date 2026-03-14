<?php

return [
    'name'            => env('BRAND_NAME'),
    'legal_name'            => env('COMPANY_LEGAL_NAME'),
    'primary_color'   => env('BRAND_PRIMARY_COLOR'),
    'logo_path'       => env('BRAND_LOGO_PATH'),
    'logo_dark_path'  => env('BRAND_LOGO_DARK_PATH'),
    'logo_height'     => env('BRAND_LOGO_HEIGHT', '2rem'),
    'favicon_path'    => env('BRAND_FAVICON_PATH'),
    'version'         => env('APP_VERSION', 'dev'),
];
