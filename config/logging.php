<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified here should match one of the
    | channels defined in the "channels" array below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This channel is used to log warnings about deprecated features in PHP
    | or libraries so you can prepare for upcoming versions.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Laravel uses Monolog under the hood. Here you may configure your log
    | channels for different outputs: daily files, Slack, syslog, etc.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [

        // 👇 Default stack now writes to both laravel.log AND vpn.log
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => 14,
            'replace_placeholders' => true,
        ],

        // 👇 New VPN-specific channel (separate file, rotates daily)
        // 👇 VPN-specific channel (static single file)
        'vpn' => [
            'driver' => 'single',
            'path'   => storage_path('logs/vpn.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url'    => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji'    => ':boom:',
            'level'    => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver'  => 'monolog',
            'level'   => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://' . env('PAPERTRAIL_URL') . ':' . env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver'  => 'monolog',
            'level'   => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level'  => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level'  => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],
];