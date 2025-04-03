<?php

use Psr\Log\LogLevel;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel (Framework Default)
    |--------------------------------------------------------------------------
    | This is the framework's default. The application's config/logging.php
    | should override this using Env::get('LOG_CHANNEL', 'file').
    */

    'default' => 'file', // Default to 'file'

    /*
    |--------------------------------------------------------------------------
    | Log Channels (Framework Defaults)
    |--------------------------------------------------------------------------
    | Defines basic channel structures. The application's config/logging.php
    | will override paths and levels using Env::get().
    */

    'channels' => [
        'file' => [
            'driver' => 'single',
            // Default relative path - Actual path resolution happens in App.php logger definition
            'path' => 'logs/swallow.log',
            'level' => LogLevel::DEBUG, // Default minimum level
        ],

        'stderr' => [
            'driver' => 'errorlog',
            'level' => LogLevel::DEBUG,
        ],

        // Slack and other drivers would be defined here as well if added to the framework core.
    ],

];