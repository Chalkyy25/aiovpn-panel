<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain & Path
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN', null),
    'path'   => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web', 'auth'], // 👈 protect dashboard in production

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    | Fire LongWaitDetected if jobs wait too long.
    */

    'waits' => [
        'redis:default' => 30, // lowered for faster alerts
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times (minutes)
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent'        => 60,     // 1h of recent jobs
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,  // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 48,   // 2 days of job snapshots
            'queue' => 48,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Behavior
    |--------------------------------------------------------------------------
    */

    'fast_termination' => true,   // don’t wait forever on deploys
    'memory_limit'     => 128,    // restart if >128MB

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue'      => ['default'],
            'balance'    => 'auto',   // dynamic scaling
            'autoScalingStrategy' => 'time',

            'maxProcesses' => 1,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 128,
            'tries'        => 2,
            'timeout'      => 90,
            'nice'         => 0,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-1' => [
                'maxProcesses'     => 10,  // up to 10 workers
                'balanceMaxShift'  => 1,   // add/remove 1 worker at a time
                'balanceCooldown'  => 3,   // every 3s Horizon re-checks load
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3, // dev machines don’t need 10
            ],
        ],
    ],
];