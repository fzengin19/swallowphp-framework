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
    | Pagination View
    |--------------------------------------------------------------------------
    |
    | Custom view for rendering pagination links. Set to null to use 
    | default Bootstrap-compatible HTML. Example: 'components.pagination'
    |
    */
    'pagination_view' => null,

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
    | Error Reporting Level
    |--------------------------------------------------------------------------
    |
    | Determine which PHP errors are reported. By default, report all errors
    | when in debug mode, and report all except deprecated and notice
    | errors when not in debug mode. You can set this specifically using
    | PHP constants like E_ALL, E_ERROR | E_WARNING, etc.
    | Use 0 to turn off reporting completely (not recommended unless handled).
    |
    */
    // 'error_reporting_level' => env('APP_DEBUG', false) ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_NOTICE),
    'error_reporting_level' => E_ALL, // Framework default, app should override based on env('APP_DEBUG')

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    // 'log_path' => env('LOG_PATH', config('app.storage_path') . '/logs/swallow.log'), // Default log file path
    // 'log_path' => null, // Default is null (disabled), app should define it in its config/app.php using env()
    'log_path' => null, // Framework default is disabled

    /*
    |--------------------------------------------------------------------------
    | HTML Minification
    |--------------------------------------------------------------------------
    |
    | Enable or disable automatic HTML minification for views.
    | Set APP_MINIFY_HTML=true in your .env file to enable.
    |
    */
    'minify_html' => env('APP_MINIFY_HTML', false), // Default is disabled

];
