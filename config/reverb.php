return [
  'default' => env('REVERB_SERVER', 'reverb'),

  'servers' => [
    'reverb' => [
      // Bind the Reverb server only on localhost; Nginx terminates TLS.
      'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
      'port' => env('REVERB_SERVER_PORT', 8080),
      'path' => env('REVERB_SERVER_PATH', ''),
      'hostname' => env('REVERB_HOST', 'reverb.aiovpn.co.uk'),
      'options' => [
        'tls' => [], // empty because Nginx does TLS
      ],
      'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10000),
      'scaling' => [
        'enabled' => env('REVERB_SCALING_ENABLED', false),
        'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
        'server' => [
          'host' => env('REDIS_HOST', '127.0.0.1'),
          'port' => env('REDIS_PORT', 6379),
          'password' => env('REDIS_PASSWORD'),
          'database' => env('REDIS_DB', 0),
          'timeout' => env('REDIS_TIMEOUT', 60),
        ],
      ],
      'pulse_ingest_interval' => 15,
      'telescope_ingest_interval' => 15,
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
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
      ],
      // lock this down if you want: 'https://panel.aiovpn.co.uk'
      'allowed_origins' => ['*'],
      'ping_interval' => 60,
      'activity_timeout' => 30,
      'max_message_size' => 10000,
    ]],
  ],
];