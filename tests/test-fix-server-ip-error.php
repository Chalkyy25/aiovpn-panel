<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load Laravel environment
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\VpnServer;
use App\Livewire\Pages\Admin\ServerShow;
use Illuminate\Support\Facades\Log;

echo "Testing server IP address error fix...\n\n";

// Create a test server with no IP address
$testServer = new VpnServer();
$testServer->name = "TestServer";
$testServer->id = 1;
$testServer->exists = true; // Make Laravel think this is a persisted model
// Intentionally not setting ip_address

// Create a test server with empty name and no IP address
$emptyNameServer = new VpnServer();
$emptyNameServer->name = "";
$emptyNameServer->id = 2;
$emptyNameServer->exists = true; // Make Laravel think this is a persisted model
// Intentionally not setting ip_address

// Create a test server with null name and no IP address
$nullNameServer = new VpnServer();
$nullNameServer->id = 3;
$nullNameServer->exists = true; // Make Laravel think this is a persisted model
// Intentionally not setting name and ip_address

// Mock the fresh() method to return the same object
VpnServer::macro('fresh', function () {
    return $this;
});

// Create a mock logger to capture log messages
$logMessages = [];
Log::shouldReceive('error')
    ->andReturnUsing(function ($message, $context) use (&$logMessages) {
        $logMessages[] = [
            'message' => $message,
            'context' => $context
        ];
        echo "Logged error: $message\n";
        echo "Context: " . json_encode($context) . "\n\n";
        return null;
    });

// Test with server that has name but no IP
echo "Test 1: Server with name but no IP address\n";
$component = new ServerShow();
$component->mount($testServer);

// Test with server that has empty name and no IP
echo "Test 2: Server with empty name and no IP address\n";
$component = new ServerShow();
$component->mount($emptyNameServer);

// Test with server that has null name and no IP
echo "Test 3: Server with null name and no IP address\n";
$component = new ServerShow();
$component->mount($nullNameServer);

// Verify results
echo "\nTest Results:\n";
echo "1. Number of log messages captured: " . count($logMessages) . "\n";

if (count($logMessages) >= 3) {
    echo "2. First log message contains server name: " .
         (strpos($logMessages[0]['message'], 'TestServer') !== false ? "✅ Yes" : "❌ No") . "\n";

    echo "3. Second log message uses 'unknown' for empty name: " .
         (strpos($logMessages[1]['message'], 'unknown') !== false ? "✅ Yes" : "❌ No") . "\n";

    echo "4. Third log message uses 'unknown' for null name: " .
         (strpos($logMessages[2]['message'], 'unknown') !== false ? "✅ Yes" : "❌ No") . "\n";

    echo "5. Context includes name field in all messages: " .
         (isset($logMessages[0]['context']['name']) &&
          isset($logMessages[1]['context']['name']) &&
          isset($logMessages[2]['context']['name']) ? "✅ Yes" : "❌ No") . "\n";
}

echo "\nTest completed.\n";
