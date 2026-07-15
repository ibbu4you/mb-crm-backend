<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    | Allow the React SPA (and the Sanctum CSRF cookie route) to talk to the
    | API with credentials (cookies).
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('LANDING_URL'), // public "Get Featured" landing page
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ])),

    'allowed_origins_patterns' => [
        // Allow testing on a phone over the LAN (http://192.168.x.x:5173)
        '#^http://(192\.168|10|172\.(1[6-9]|2[0-9]|3[01]))\.[0-9.]+:5173$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
