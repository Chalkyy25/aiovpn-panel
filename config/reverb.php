<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    */
    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    | Reverb itself listens only on localhost:8080.
    | Nginx terminates TLS and proxies to it.
    */
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => (int) env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            // purely cosmetic; used in logs
            'hostname' => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),

            // Do NOT enable TLS here when you’re behind Nginx
            'options' => [
                'tls' => [],
            ],

            'max_request_size' => (int) env('REVERB_MAX_REQUEST_SIZE', 10000),

            // Single node for now — disable scaling
            'scaling' => [
                'enabled' => (bool) env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url'      => env('REDIS_URL'),
                    'host'     => env('REDIS_HOST', '127.0.0.1'),
                    'port'     => (int) env('REDIS_PORT', 6379),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => (int) env('REDIS_DB', 0),
                    'timeout'  => (int) env('REDIS_TIMEOUT', 60),
                ],
            ],

            'pulse_ingest_interval'     => (int) env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => (int) env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Applications (credentials)
    |--------------------------------------------------------------------------
    | Provider = "config": we list a single app your Echo will use.
    */
    'apps' => [
        'provider' => 'config',

        'apps' => [
            [
                'key'    => env('REVERB_APP_KEY', 'localkey'),
                'secret' => env('REVERB_APP_SECRET', 'localsecret'),
                'app_id' => env('REVERB_APP_ID', 'aiovpn'),

                // These options are for the *client* connection details Echo uses
                'options' => [
                    'host'    => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
                    'port'    => (int) env('REVERB_PORT', 443),
                    'scheme'  => env('REVERB_SCHEME', 'https'),
                    'useTLS'  => env('REVERB_SCHEME', 'https') === 'https',
                ],

                // tighten later to: ['https://panel.aiovpn.co.uk']
                'allowed_origins' => ['*'],

                'ping_interval'     => (int) env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout'  => (int) env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_message_size'  => (int) env('REVERB_APP_MAX_MESSAGE_SIZE', 10000),
            ],
        ],
    ],
];