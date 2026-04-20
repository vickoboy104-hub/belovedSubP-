<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com'),
        'fixed_account_bvn' => env('FLUTTERWAVE_FIXED_ACCOUNT_BVN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ✅ GSUBZ API (VTU Provider)
    |--------------------------------------------------------------------------
    */
   'gsubz' => [
    'key'  => env('GSUBZ_API_KEY', ''),
    'base' => env('GSUBZ_BASE_URL', 'https://api.gsubz.com'),
    ],

    'alt' => [
        'key' => env('ALT_PROVIDER_API_KEY', ''),
        'base' => env('ALT_PROVIDER_BASE_URL', ''),
    ],

    'nin' => [
        'key' => env('NIN_API_KEY', ''),
        'base' => env('NIN_BASE_URL', 'https://confirmident.com.ng/api'),
        'print_endpoint' => env('NIN_PRINT_ENDPOINT', ''),
        'reports_endpoint' => env('NIN_REPORTS_ENDPOINT', ''),
        'validation_endpoint' => env('NIN_VALIDATION_ENDPOINT', ''),
    ],

    'bvn' => [
        'key' => env('BVN_API_KEY', ''),
        'base' => env('BVN_BASE_URL', 'https://confirmident.com.ng/api'),
        'verify_endpoint' => env('BVN_VERIFY_ENDPOINT', '/bvn_search'),
        'retrieve_phone_endpoint' => env('BVN_RETRIEVE_PHONE_ENDPOINT', ''),
        'retrieve_bms_endpoint' => env('BVN_RETRIEVE_BMS_ENDPOINT', ''),
        'print_endpoint' => env('BVN_PRINT_ENDPOINT', ''),
    ],


];
