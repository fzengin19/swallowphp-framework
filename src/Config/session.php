<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    */
    // 'cookie' => env('SESSION_COOKIE', 'swallowphp_session'),
    'cookie' => 'swallowphp_session', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path
    |--------------------------------------------------------------------------
    */
    // 'path' => env('SESSION_PATH', '/'),
    'path' => '/', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain
    |--------------------------------------------------------------------------
    */
    // 'domain' => env('SESSION_DOMAIN', null),
    'domain' => null, // Framework default

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies
    |--------------------------------------------------------------------------
    */
    // 'secure' => env('SESSION_SECURE_COOKIE', null), // null will default based on APP_ENV in Cookie::set
    'secure' => null, // Framework default (Cookie::set will handle logic)

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only
    |--------------------------------------------------------------------------
    */
    // 'http_only' => env('SESSION_HTTP_ONLY', true),
    'http_only' => true, // Framework default

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies
    |--------------------------------------------------------------------------
    | Supported: "Lax", "Strict", "None"
    */
    // 'same_site' => env('SESSION_SAME_SITE', 'Lax'),
    'same_site' => 'Lax', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime (for drivers that use it, not directly for cookie expiry)
    |--------------------------------------------------------------------------
    */
    // 'lifetime' => 120, // In minutes

    /*
    |--------------------------------------------------------------------------
    | Expire On Close (for drivers that use it)
    |--------------------------------------------------------------------------
    */
    // 'expire_on_close' => false,

];