<?php

// Live VPN Status API - bypasses database issues
// This can be called directly by your dashboard to get real-time data

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'success' => true,
    'timestamp' => date('c'),
    'servers' => [
        [
            'id' => 1,
            'name' => 'Germany',
            'ip_address' => '5.22.212.177',
            'active_connections' => getLiveConnections('5.22.212.177'),
        ]
        // Add other servers here
    ],
    'totals' => [
        'active_connections' => getTotalConnections(),
        'online_users' => getUniqueUsers(),
        'active_servers' => 1
    ]
]);

function getLiveConnections($server_ip) {
    $connections = [];
    
    // Check both UDP (7505) and TCP (7506) ports
    $ports = [7505, 7506];
    
    foreach ($ports as $port) {
        $cmd = "ssh root@{$server_ip} \"echo 'status 3' | nc -w 3 127.0.0.1 {$port} 2>/dev/null\"";
        $output = shell_exec($cmd);
        
        if (str_contains($output, 'CLIENT_LIST')) {
            $client_count = substr_count($output, 'CLIENT_LIST') - 1;
            
            if ($client_count > 0) {
                $lines = explode("\n", $output);
                
                foreach ($lines as $line) {
                    if (str_starts_with($line, 'CLIENT_LIST') && !str_contains($line, 'Common Name')) {
                        $parts = explode("\t", $line);
                        if (count($parts) >= 8) {
                            $real_address = explode(':', trim($parts[2]))[0]; // Remove port
                            $connections[] = [
                                'username' => trim($parts[1]),
                                'client_ip' => $real_address,
                                'virtual_ip' => trim($parts[3]),
                                'bytes_received' => intval($parts[5] ?? 0),
                                'bytes_sent' => intval($parts[6] ?? 0),
                                'connected_since' => trim($parts[7] ?? ''),
                                'connected_human' => formatConnectionTime($parts[7] ?? ''),
                                'down_mb' => round(intval($parts[5] ?? 0) / 1048576, 2),
                                'up_mb' => round(intval($parts[6] ?? 0) / 1048576, 2),
                                'server_port' => $port,
                                'protocol' => $port == 7505 ? 'UDP' : 'TCP-Stealth'
                            ];
                        }
                    }
                }
                
                // Found connections, use this data
                break;
            }
        }
    }
    
    return $connections;
}

function getTotalConnections() {
    $connections = getLiveConnections('5.22.212.177');
    return count($connections);
}

function getUniqueUsers() {
    $connections = getLiveConnections('5.22.212.177');
    $users = array_unique(array_column($connections, 'username'));
    return count($users);
}

function formatConnectionTime($timestamp) {
    if (empty($timestamp) || !is_numeric($timestamp)) {
        return 'Unknown';
    }
    
    $diff = time() - intval($timestamp);
    
    if ($diff < 60) return "{$diff}s ago";
    if ($diff < 3600) return floor($diff/60) . "m ago";
    if ($diff < 86400) return floor($diff/3600) . "h ago";
    return floor($diff/86400) . "d ago";
}