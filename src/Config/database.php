<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */
    // 'default' => env('DB_CONNECTION', 'mysql'),
    'default' => 'mysql', // Framework default

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            // Path relative to storage_path defined in config/app.php
            // Ensure the 'database' directory exists within storage_path
            // 'database' => env('DB_DATABASE', 'database/database.sqlite'),
            'database' => 'database/database.sqlite', // Framework default relative path
            'prefix' => '',
        ],

        'mysql' => [
            'driver' => 'mysql',
            // 'host' => env('DB_HOST', '127.0.0.1'),
            'host' => '127.0.0.1', // Framework default
            // 'port' => env('DB_PORT', '3306'),
            'port' => '3306', // Framework default
            // 'database' => env('DB_DATABASE', 'swallowphp'),
            'database' => 'swallowphp', // Framework default
            // 'username' => env('DB_USERNAME', 'root'),
            'username' => 'root', // Framework default
            // 'password' => env('DB_PASSWORD', ''),
            'password' => '', // Framework default
            // 'unix_socket' => env('DB_SOCKET', ''),
            'unix_socket' => '', // Framework default
            // 'charset' => env('DB_CHARSET', 'utf8mb4'),
            'charset' => 'utf8mb4', // Framework default
            // 'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'collation' => 'utf8mb4_unicode_ci', // Framework default
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ],
    ],
];