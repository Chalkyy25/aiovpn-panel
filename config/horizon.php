<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain & Path
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Horizon Uses
    |--------------------------------------------------------------------------
    | This should match a Redis connection name from config/database.php.
    | If you created a dedicated "horizon" connection, put 'horizon' here.
    */
    'use' => env('HORIZON_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Redis Key Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:default' => 30,
        'redis:wg'      => 60,
        'redis:ovpn'    => 60,
        'redis:low'     => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming (minutes)
    |--------------------------------------------------------------------------
    */
    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,  // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Retention (hours)
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 48,   // 2 days
            'queue' => 48,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Master Behavior
    |--------------------------------------------------------------------------
    */
    'fast_termination' => true,
    'memory_limit'     => 256,

    /*
    |--------------------------------------------------------------------------
    | Supervisor Pool Defaults
    |--------------------------------------------------------------------------
    | IMPORTANT: If `minProcesses` is present it must be >= 1.
    */
    'defaults' => [

        // Snappy light jobs
        'default-high' => [
            'connection'           => 'redis',
            'queue'                => ['default'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'minProcesses'         => 1,
            'maxProcesses'         => 6,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 3,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 2,
            'timeout'              => 60,
            'nice'                 => 0,
        ],

        // Network/SSH heavy (WireGuard)
        'wg-io' => [
            'connection'           => 'redis',
            'queue'                => ['wg'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'minProcesses'         => 1,  // was 1 (keep >=1)
            'maxProcesses'         => 4,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 5,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 2,
            'timeout'              => 120,
            'nice'                 => 5,
        ],

        // OpenVPN jobs
        'ovpn-io' => [
            'connection'           => 'redis',
            'queue'                => ['ovpn'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'minProcesses'         => 1,  // ← FIXED (cannot be 0)
            'maxProcesses'         => 3,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 5,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 2,
            'timeout'              => 120,
            'nice'                 => 5,
        ],

        // Best-effort slow/low priority
        'low' => [
            'connection'           => 'redis',
            'queue'                => ['low'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'minProcesses'         => 1,  // ← FIXED (cannot be 0)
            'maxProcesses'         => 2,
            'balanceMaxShift'      => 1,
            'balanceCooldown'      => 10,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 1,
            'timeout'              => 60,
            'nice'                 => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Overrides
    |--------------------------------------------------------------------------
    | Only override what differs; minProcesses remains >= 1 everywhere.
    */
    'environments' => [

        'production' => [
            'default-high' => [
                'maxProcesses' => 10,
            ],
            'wg-io' => [
                'maxProcesses' => 6,
            ],
            'ovpn-io' => [
                'maxProcesses' => 4,
            ],
            'low' => [
                'maxProcesses' => 3,
            ],
        ],

        'local' => [
            'default-high' => [
                'maxProcesses' => 2,
            ],
            'wg-io' => [
                'maxProcesses' => 1,
            ],
            'ovpn-io' => [
                'maxProcesses' => 1, // keep >= minProcesses
            ],
            'low' => [
                'maxProcesses' => 1, // keep >= minProcesses
            ],
        ],
    ],
];
