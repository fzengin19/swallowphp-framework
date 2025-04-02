<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is used whenever another store is not explicitly
    | specified when executing a cache operation inside the application.
    | Supported: "file", "sqlite" // Add more like "redis", "memcached" later
    |
    */

    // 'default' => env('CACHE_DRIVER', 'file'),
    'default' => 'file', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Default Cache Time To Live (TTL)
    |--------------------------------------------------------------------------
    |
    | Default number of seconds to store items in the cache.
    | null means store forever.
    |
    */
    // 'ttl' => env('CACHE_TTL', 3600), // Default 1 hour
    'ttl' => 3600, // Framework default

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of cached data.
    |
    */

    'stores' => [

        'file' => [
            'driver' => 'file',
            // Path relative to storage_path defined in config/app.php
            // 'path' => env('CACHE_FILE_PATH', 'cache/data.json'),
            'path' => 'cache/data.json', // Framework default relative path
            // 'max_size' => (int) env('CACHE_FILE_MAX_SIZE_MB', 50) * 1024 * 1024, // Size in bytes
            'max_size' => 50 * 1024 * 1024, // Framework default size (50MB)
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            // Path relative to storage_path defined in config/app.php
            // 'path' => env('CACHE_SQLITE_PATH', 'cache/database.sqlite'),
            'path' => 'cache/database.sqlite', // Framework default relative path
            'table' => 'cache', // Optional: table name
        ],

        // Example for future Redis store
        // 'redis' => [
        //     'driver' => 'redis',
        //     'connection' => 'default', // Corresponds to database.php redis connections
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as APC or Memcached, there might
    | be other applications utilizing the same cache. So, we'll specify a
    | value to get prefixed to all our keys so we can avoid collisions.
    |
    */

    // 'prefix' => env('CACHE_PREFIX', 'swallowphp_cache_'),
    'prefix' => 'swallowphp_cache_', // Framework default prefix

];