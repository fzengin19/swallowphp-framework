<?php

use SwallowPHP\Framework\Foundation\Env; // Bu use ifadesi artık gereksiz ama kalabilir.

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver (Framework Default)
    |--------------------------------------------------------------------------
    | The application's config/session.php should override this.
    */

    'driver' => 'file', // Default to file-based sessions

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime (Framework Default)
    |--------------------------------------------------------------------------
    */

    'lifetime' => 120, // Default lifetime in minutes

    /*
    |--------------------------------------------------------------------------
    | Expire On Close (Framework Default)
    |--------------------------------------------------------------------------
    */

    'expire_on_close' => false,

    /*
    |--------------------------------------------------------------------------
    | Session File Location (Framework Default)
    |--------------------------------------------------------------------------
    | Default is null. The actual path will be determined by SessionManager
    | based on the application's config (app.storage_path) or a fallback.
    | The application's config/session.php should define this using BASE_PATH.
    */

    'files' => null, // Default path is null, resolved later

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection (Placeholder)
    |--------------------------------------------------------------------------
    */

    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Session Database Table (Placeholder)
    |--------------------------------------------------------------------------
    */

    'table' => 'sessions',

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name (Framework Default)
    |--------------------------------------------------------------------------
    */

    'cookie' => 'swallow_session',

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path (Framework Default)
    |--------------------------------------------------------------------------
    */

    'path' => '/',

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain (Framework Default)
    |--------------------------------------------------------------------------
    */

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies (Framework Default)
    |--------------------------------------------------------------------------
    */

    'secure' => null, // Cookie manager will decide based on context

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only (Framework Default)
    |--------------------------------------------------------------------------
    */

    'http_only' => true,

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies (Framework Default)
    |--------------------------------------------------------------------------
    */

    'same_site' => 'Lax',

    /*
    |--------------------------------------------------------------------------
    | Session Garbage Collection Lottery (Framework Default)
    |--------------------------------------------------------------------------
    */
    'lottery' => [2, 100],

];