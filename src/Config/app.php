<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    // 'name' => env('APP_NAME', 'SwallowPHP'),
    'name' => 'SwallowPHP', // Framework default, override in app's config/app.php using env()

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */
    // 'env' => env('APP_ENV', 'production'),
    'env' => 'production', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    // 'debug' => (bool) env('APP_DEBUG', false),
    'debug' => false, // Framework default

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    // 'url' => env('APP_URL', 'http://localhost'),
    'url' => 'http://localhost', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Application Path (Subdirectory)
    |--------------------------------------------------------------------------
    */
    // 'path' => env('APP_PATH', ''),
    'path' => '', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    // 'timezone' => env('APP_TIMEZONE', 'Europe/Istanbul'),
    'timezone' => 'UTC', // Sensible framework default

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    */
    // 'locale' => env('APP_LOCALE', 'tr'),
    'locale' => 'en', // Sensible framework default

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */
    // 'key' => env('APP_KEY'),
    'key' => null, // MUST be set in the application's .env file and config/app.php

    'cipher' => 'AES-256-CBC', // Cipher used by Cookie encryption

    /*
    |--------------------------------------------------------------------------
    | Base Path for Storage
    |--------------------------------------------------------------------------
    */
    // 'storage_path' => env('STORAGE_PATH', dirname(__DIR__, 2) . '/storage'), // Assumes config is in src/Config
    'storage_path' => null, // Better to force app definition in app's config/app.php

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */
    // 'view_path' => env('VIEW_PATH', dirname(__DIR__, 2) . '/resources/views'),
    'view_path' => null, // Better to force app definition in app's config/app.php

    /*
    |--------------------------------------------------------------------------
    | Controller Namespace
    |--------------------------------------------------------------------------
    */
    // 'controller_namespace' => env('CONTROLLER_NAMESPACE', '\\App\\Controllers'),
    'controller_namespace' => null, // Should be defined in the application's config/app.php

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Time
    |--------------------------------------------------------------------------
    */
    // 'max_execution_time' => (int) env('MAX_EXECUTION_TIME', 30),
    'max_execution_time' => 30, // Default value

    /*
    |--------------------------------------------------------------------------
    | Force SSL
    |--------------------------------------------------------------------------
    */
    // 'ssl_redirect' => (bool) env('SSL_REDIRECT', false),
    'ssl_redirect' => false, // Default value

    /*
    |--------------------------------------------------------------------------
    | Gzip Compression
    |--------------------------------------------------------------------------
    */
    // 'gzip_compression' => (bool) env('GZIP_COMPRESSION', true),
    'gzip_compression' => true, // Default value

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    // 'log_path' => env('LOG_PATH', config('app.storage_path') . '/logs/swallow.log'), // Default log file path
    // 'log_path' => null, // Default is null (disabled), app should define it in its config/app.php using env()
    'log_path' => null, // Framework default is disabled

];