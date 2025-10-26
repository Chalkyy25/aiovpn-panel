<?php

// Test the updated VPN monitoring to check both TCP and UDP servers
echo "🔍 Testing VPN monitoring for TCP stealth server connections...\n\n";

// Simulate checking both management ports like the updated code
$server_ip = '5.22.212.177';
$mgmt_ports = [7505, 7506]; // UDP and TCP management ports

foreach ($mgmt_ports as $port) {
    echo "📡 Testing management interface on port {$port}:\n";
    
    $cmd = "ssh root@{$server_ip} \"echo 'status 3' | nc -w 3 127.0.0.1 {$port}\"";
    echo "Command: {$cmd}\n";
    
    $output = shell_exec($cmd);
    
    if (str_contains($output, 'CLIENT_LIST')) {
        echo "✅ SUCCESS: Found active connections on port {$port}\n";
        echo "Preview: " . substr($output, 0, 200) . "...\n\n";
        
        // Count connections
        $client_lines = substr_count($output, 'CLIENT_LIST') - 1; // Subtract header
        echo "👥 Active connections: {$client_lines}\n\n";
    } else {
        echo "❌ No CLIENT_LIST data found on port {$port}\n";
        echo "Output: " . substr($output, 0, 100) . "\n\n";
    }
}

echo "🎯 Test completed!\n";