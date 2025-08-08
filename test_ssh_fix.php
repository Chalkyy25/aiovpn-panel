<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 Testing SSH fixes...\n";
echo "========================\n\n";

// Test the ExecutesRemoteCommands trait directly
use App\Traits\ExecutesRemoteCommands;

class TestSSH {
    use ExecutesRemoteCommands;

    public function testCommand($ip, $command) {
        return $this->executeRemoteCommand($ip, $command);
    }
}

$tester = new TestSSH();

// Test with a simple command that should work
$testIp = '127.0.0.1'; // localhost for testing
$testCommand = 'echo "SSH test successful"';

echo "🧪 Testing SSH command execution...\n";
echo "IP: $testIp\n";
echo "Command: $testCommand\n\n";

$result = $tester->testCommand($testIp, $testCommand);

echo "📊 Result:\n";
echo "Status: " . $result['status'] . "\n";
echo "Output:\n";
foreach ($result['output'] as $line) {
    echo "  $line\n";
}

echo "\n✅ SSH test completed!\n";
echo "If status is 0 and output shows 'SSH test successful', the SSH configuration is working.\n";
echo "If there are permission errors, they should now be resolved with temp directory usage.\n";
