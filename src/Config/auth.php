<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Model
    |--------------------------------------------------------------------------
    |
    | Specify the fully qualified class name of the model that represents
    | your application's users and extends the AuthenticatableModel.
    | This should be set in your .env file as AUTH_MODEL.
    | e.g., AUTH_MODEL=App\Models\User
    |
    */

    'model' => env('AUTH_MODEL'), // Rely solely on the environment variable

    /*
    |--------------------------------------------------------------------------
    | Login Throttling / Lockout
    |--------------------------------------------------------------------------
    */
    'max_attempts' => (int) env('AUTH_MAX_ATTEMPTS', 5), // Max attempts before lockout
    'lockout_time' => (int) env('AUTH_LOCKOUT_TIME', 900), // Lockout time in seconds (15 minutes)

];