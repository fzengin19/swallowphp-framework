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

    // Framework default - This should ideally be null or a very basic placeholder.
    // The actual model MUST be defined in the application's config/auth.php or .env file.
    'model' => null, // Do NOT call env() here during framework's internal config load.    

    /*
    |--------------------------------------------------------------------------
    | Login Throttling / Lockout
    |--------------------------------------------------------------------------
    */
    'max_attempts' => 5, // Default value
    'lockout_time' => 900, // Default value
    'remember_me_lifetime' => 43200, // Default value (30 days)

];
