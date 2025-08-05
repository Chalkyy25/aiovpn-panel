<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load Laravel environment
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VpnServer;
use Illuminate\Support\Facades\Log;

echo "Testing server error message formatting directly...\n\n";

// Create test servers with different name scenarios
$servers = [
    'with_name' => (object)[
        'name' => 'TestServer',
        'id' => 1,
        'ip_address' => null
    ],
    'empty_name' => (object)[
        'name' => '',
        'id' => 2,
        'ip_address' => null
    ],
    'null_name' => (object)[
        'name' => null,
        'id' => 3,
        'ip_address' => null
    ]
];

// Test error message formatting directly
foreach ($servers as $type => $server) {
    // Get server name safely
    $serverName = $server->name;

    // Use a default name if server name is null or empty
    $displayName = $serverName ? $serverName : 'unknown';

    $message = "Server {$displayName} has no IP address!";
    $context = [
        'id' => $server->id ?? 'null',
        'ip_address' => $server->ip_address ?? 'null',
        'name' => $displayName,
    ];

    echo "Test case: $type\n";
    echo "Message: $message\n";
    echo "Context: " . json_encode($context) . "\n\n";
}

echo "Test completed.\n";
