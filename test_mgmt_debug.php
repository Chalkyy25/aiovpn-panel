<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\VpnServer;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

// Create a simple Laravel bootstrap
$app = new Application(__DIR__);
$app->singleton('config', function() {
    return new class {
        public function get($key, $default = null) {
            return match($key) {
                'app.env' => 'local',
                'database.default' => 'mysql',
                default => $default
            };
        }
    };
});

class MgmtDebugger {
    use ExecutesRemoteCommands;

    public function testMgmtCommands(VpnServer $server): void
    {
        echo "🔍 Testing Management Interface Commands for {$server->name}\n";
        echo "==================================================\n\n";

        $mgmtPort = (int)($server->mgmt_port ?? 7505);
        echo "Management port: {$mgmtPort}\n";
        echo "Server IP: {$server->ip_address}\n\n";

        // Test 1: The working command (as mentioned by user)
        echo "1. Testing working command (printf with sleep):\n";
        $workingCmd = 'bash -lc ' . escapeshellarg(
            '{ printf "status 3\n"; sleep 1; printf "quit\n"; } | nc -w 5 127.0.0.1 ' . $mgmtPort
        );
        echo "   Command: {$workingCmd}\n";
        $result1 = $this->executeRemoteCommand($server, $workingCmd);
        echo "   Exit code: " . ($result1['status'] ?? 'unknown') . "\n";
        echo "   Output length: " . strlen(implode("\n", $result1['output'] ?? [])) . " bytes\n";
        echo "   First 200 chars: " . substr(implode("\n", $result1['output'] ?? []), 0, 200) . "\n\n";

        // Test 2: Simple echo command (user says doesn't work)
        echo "2. Testing simple echo command:\n";
        $echoCmd = 'bash -lc ' . escapeshellarg(
            'echo -e "status\nquit\n" | nc -w 3 127.0.0.1 ' . $mgmtPort
        );
        echo "   Command: {$echoCmd}\n";
        $result2 = $this->executeRemoteCommand($server, $echoCmd);
        echo "   Exit code: " . ($result2['status'] ?? 'unknown') . "\n";
        echo "   Output length: " . strlen(implode("\n", $result2['output'] ?? [])) . " bytes\n";
        echo "   First 200 chars: " . substr(implode("\n", $result2['output'] ?? []), 0, 200) . "\n\n";

        // Test 3: Echo with status 3
        echo "3. Testing echo with status 3:\n";
        $echo3Cmd = 'bash -lc ' . escapeshellarg(
            'echo -e "status 3\nquit\n" | nc -w 3 127.0.0.1 ' . $mgmtPort
        );
        echo "   Command: {$echo3Cmd}\n";
        $result3 = $this->executeRemoteCommand($server, $echo3Cmd);
        echo "   Exit code: " . ($result3['status'] ?? 'unknown') . "\n";
        echo "   Output length: " . strlen(implode("\n", $result3['output'] ?? [])) . " bytes\n";
        echo "   First 200 chars: " . substr(implode("\n", $result3['output'] ?? []), 0, 200) . "\n\n";

        // Test 4: Check if management port is listening
        echo "4. Testing if management port is listening:\n";
        $portCheckCmd = 'bash -lc ' . escapeshellarg(
            'netstat -ln | grep :' . $mgmtPort . ' || ss -ln | grep :' . $mgmtPort
        );
        echo "   Command: {$portCheckCmd}\n";
        $result4 = $this->executeRemoteCommand($server, $portCheckCmd);
        echo "   Exit code: " . ($result4['status'] ?? 'unknown') . "\n";
        echo "   Output: " . implode("\n", $result4['output'] ?? []) . "\n\n";

        // Test 5: Check OpenVPN processes
        echo "5. Testing OpenVPN process status:\n";
        $processCmd = 'bash -lc ' . escapeshellarg(
            'ps aux | grep openvpn | grep -v grep'
        );
        echo "   Command: {$processCmd}\n";
        $result5 = $this->executeRemoteCommand($server, $processCmd);
        echo "   Exit code: " . ($result5['status'] ?? 'unknown') . "\n";
        echo "   Output: " . implode("\n", $result5['output'] ?? []) . "\n\n";

        // Test 6: Check status file paths
        echo "6. Testing status file candidates:\n";
        $candidates = [
            $server->status_log_path ?? null,
            '/run/openvpn/server.status',
            '/run/openvpn/openvpn.status',
            '/run/openvpn/server/server.status',
            '/var/log/openvpn-status.log',
        ];

        foreach ($candidates as $path) {
            if (!$path) continue;
            echo "   Checking: {$path}\n";
            $fileCmd = 'bash -lc ' . escapeshellarg(
                "ls -la {$path} 2>/dev/null || echo 'FILE NOT FOUND'"
            );
            $result6 = $this->executeRemoteCommand($server, $fileCmd);
            echo "     Result: " . implode(" ", $result6['output'] ?? []) . "\n";
        }

        echo "\n🎯 Summary:\n";
        echo "=========\n";
        echo "Working command success: " . (($result1['status'] ?? 1) === 0 ? 'YES' : 'NO') . "\n";
        echo "Simple echo success: " . (($result2['status'] ?? 1) === 0 ? 'YES' : 'NO') . "\n";
        echo "Echo status 3 success: " . (($result3['status'] ?? 1) === 0 ? 'YES' : 'NO') . "\n";
        echo "Management port listening: " . (($result4['status'] ?? 1) === 0 ? 'YES' : 'NO') . "\n";
        echo "OpenVPN running: " . (($result5['status'] ?? 1) === 0 && count($result5['output'] ?? []) > 0 ? 'YES' : 'NO') . "\n";
    }
}

// Test with first available server
try {
    $server = VpnServer::where('deployment_status', 'succeeded')->first();
    if ($server) {
        $debugger = new MgmtDebugger();
        $debugger->testMgmtCommands($server);
    } else {
        echo "❌ No succeeded VPN servers found to test\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "This might be expected if Laravel isn't fully bootstrapped\n";
    echo "Try running this script in a proper Laravel environment\n";
}
