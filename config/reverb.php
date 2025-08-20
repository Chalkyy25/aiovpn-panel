<?php

return [
    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
            'options' => [
                'tls' => [],
            ],
        ],
    ],

    'apps' => [
        [
            'app_id' => env('REVERB_APP_ID', '1'),
            'key'    => env('REVERB_APP_KEY', 'localkey'),
            'secret' => env('REVERB_APP_SECRET', 'localsecret'),
            'options' => [
                'host'   => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
                'port'   => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
        ],
    ],
];