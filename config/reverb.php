<?php

return [
  'default' => env('REVERB_SERVER', 'reverb'),

  'servers' => [
    'reverb' => [
      'host' => env('REVERB_SERVER_HOST', '127.0.0.1'), // bind locally
      'port' => (int) env('REVERB_SERVER_PORT', 8080),
      'path' => env('REVERB_SERVER_PATH', ''),
      'hostname' => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
      'options' => ['tls' => []], // TLS is on Nginx, not here
      'max_request_size' => (int) env('REVERB_MAX_REQUEST_SIZE', 10000),
      'scaling' => [
        'enabled' => (bool) env('REVERB_SCALING_ENABLED', false),
        'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
        'server' => [
          'url' => env('REDIS_URL'),
          'host' => env('REDIS_HOST', '127.0.0.1'),
          'port' => (int) env('REDIS_PORT', 6379),
          'username' => env('REDIS_USERNAME'),
          'password' => env('REDIS_PASSWORD'),
          'database' => (int) env('REDIS_DB', 0),
          'timeout' => (int) env('REDIS_TIMEOUT', 60),
        ],
      ],
      'pulse_ingest_interval' => (int) env('REVERB_PULSE_INGEST_INTERVAL', 15),
      'telescope_ingest_interval' => (int) env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
    ],
  ],

  'apps' => [
    'provider' => 'config',
    'apps' => [[
      'key' => env('REVERB_APP_KEY', 'localkey'),
      'secret' => env('REVERB_APP_SECRET', 'localsecret'),
      'app_id' => env('REVERB_APP_ID', '1'),
      'options' => [
        'host' => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
        'port' => (int) env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
      ],
      'allowed_origins' => ['*'],
      'ping_interval' => (int) env('REVERB_APP_PING_INTERVAL', 60),
      'activity_timeout' => (int) env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
      'max_message_size' => (int) env('REVERB_APP_MAX_MESSAGE_SIZE', 10000),
    ]],
  ],
];