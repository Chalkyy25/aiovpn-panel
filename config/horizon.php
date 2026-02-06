<?php

use Illuminate\Support\Str;

return [

    // ── Horizon UI location ────────────────────────────────────────────────
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),

    // ── Redis connection Horizon uses (from config/database.php) ──────────
    'use' => env('HORIZON_CONNECTION', 'default'),

    // ── Redis key prefix ───────────────────────────────────────────────────
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'),

    // ── Dashboard guard ────────────────────────────────────────────────────
    'middleware' => ['web', 'auth'],

    // ── “queue feels slow” thresholds (seconds) ────────────────────────────
    'waits' => [
        'redis:default' => 30,
        'redis:wg'      => 60,
        'redis:ovpn'    => 60,
        'redis:low'     => 120,
    ],

    // ── Trim history (minutes) ─────────────────────────────────────────────
    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080, // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    // ── Metrics retention (hours) ──────────────────────────────────────────
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 48,
            'queue' => 48,
        ],
    ],

    // ── Master process behavior ────────────────────────────────────────────
    'fast_termination' => true,
    'memory_limit'     => 256, // MB (Horizon master guard; workers set below)

    // ── Pool defaults (each must have minProcesses >= 1) ───────────────────
    'defaults' => [

        // General/light app jobs
        'default-high' => [
            'connection'          => 'redis',
            'queue'               => ['default'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 1,
            'maxProcesses'        => 6,
            'balanceMaxShift'     => 1,
            'balanceCooldown'     => 3,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 256, // per-worker recycle cap
            'tries'               => 2,
            'timeout'             => 60,  // per-job
            'nice'                => 0,
        ],

        // WireGuard / SSH / network heavy
        'wg-io' => [
            'connection'          => 'redis',
            'queue'               => ['wg'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 1,
            'maxProcesses'        => 4,
            'balanceMaxShift'     => 1,
            'balanceCooldown'     => 5,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 256,
            'tries'               => 2,
            'timeout'             => 120,
            'nice'                => 5,
        ],

        // OpenVPN jobs
        'ovpn-io' => [
            'connection'          => 'redis',
            'queue'               => ['ovpn'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 1,
            'maxProcesses'        => 3,
            'balanceMaxShift'     => 1,
            'balanceCooldown'     => 5,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 256,
            'tries'               => 2,
            'timeout'             => 120,
            'nice'                => 5,
        ],

        // Best-effort / background
        'low' => [
            'connection'          => 'redis',
            'queue'               => ['low'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 1,
            'maxProcesses'        => 2,
            'balanceMaxShift'     => 1,
            'balanceCooldown'     => 10,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 256,
            'tries'               => 1,
            'timeout'             => 60,
            'nice'                => 10,
        ],
    ],

    // ── Environment-specific overrides only where it matters ───────────────
    'environments' => [

        // IMPORTANT: 2 cores / 4GB => keep total workers ~5
        'production' => [
            'default-high' => ['maxProcesses' => 2],
            'wg-io'        => ['maxProcesses' => 1],
            'ovpn-io'       => ['maxProcesses' => 1],
            'low'          => ['maxProcesses' => 1],
        ],

        'local' => [
            'default-high' => ['maxProcesses' => 2],
            'wg-io'        => ['maxProcesses' => 1],
            'ovpn-io'       => ['maxProcesses' => 1],
            'low'          => ['maxProcesses' => 1],
        ],
    ],

];
