<?php

// Manual database update for VPN connections
// This bypasses the queue/Laravel issues and directly updates the database

echo "💾 Manually updating VPN connection database...\n\n";

// Get database credentials from .env file
$env = file_get_contents(__DIR__ . '/.env');
preg_match('/DB_HOST=(.+)/', $env, $host_match);
preg_match('/DB_PORT=(.+)/', $env, $port_match);
preg_match('/DB_DATABASE=(.+)/', $env, $db_match);
preg_match('/DB_USERNAME=(.+)/', $env, $user_match);
preg_match('/DB_PASSWORD=(.*)/', $env, $pass_match);

$host = trim($host_match[1] ?? '127.0.0.1');
$port = trim($port_match[1] ?? '3306');
$database = trim($db_match[1] ?? 'aiovpn');
$username = trim($user_match[1] ?? 'aiovpn');
$password = trim($pass_match[1] ?? '');

echo "🔌 Connecting to database: {$username}@{$host}:{$port}/{$database}\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Database connection successful!\n\n";
    
    // 1. Clear existing connections
    echo "🧹 Clearing old connection records...\n";
    $pdo->exec("UPDATE vpn_user_connections SET is_connected = 0, disconnected_at = NOW() WHERE is_connected = 1");
    
    // 2. Get current live data from Germany server
    echo "📡 Fetching live connection data from Germany server...\n";
    $cmd = "ssh root@5.22.212.177 \"echo 'status 3' | nc -w 3 127.0.0.1 7506\"";
    $output = shell_exec($cmd);
    
    if (str_contains($output, 'CLIENT_LIST')) {
        $lines = explode("\n", $output);
        $connections = [];
        
        foreach ($lines as $line) {
            if (str_starts_with($line, 'CLIENT_LIST') && !str_contains($line, 'Common Name')) {
                $parts = explode("\t", $line);
                if (count($parts) >= 8) {
                    $connections[] = [
                        'username' => trim($parts[1]),
                        'real_address' => trim($parts[2]),
                        'virtual_address' => trim($parts[3]),
                        'bytes_received' => intval($parts[5] ?? 0),
                        'bytes_sent' => intval($parts[6] ?? 0),
                        'connected_since' => trim($parts[7] ?? ''),
                    ];
                }
            }
        }
        
        echo "📊 Found " . count($connections) . " active connections:\n";
        
        // 3. Get server and user IDs
        $stmt = $pdo->prepare("SELECT id FROM vpn_servers WHERE ip_address = '5.22.212.177' LIMIT 1");
        $stmt->execute();
        $server_id = $stmt->fetchColumn();
        
        if (!$server_id) {
            echo "❌ Germany server not found in database\n";
            exit(1);
        }
        
        echo "🇩🇪 Germany server ID: {$server_id}\n\n";
        
        // 4. Insert/update each connection
        foreach ($connections as $conn) {
            echo "👤 Processing {$conn['username']}...\n";
            
            // Get user ID
            $stmt = $pdo->prepare("SELECT id FROM vpn_users WHERE username = ? LIMIT 1");
            $stmt->execute([$conn['username']]);
            $user_id = $stmt->fetchColumn();
            
            if ($user_id) {
                // Insert/update connection record
                $stmt = $pdo->prepare("
                    INSERT INTO vpn_user_connections 
                    (vpn_user_id, vpn_server_id, client_ip, virtual_ip, bytes_received, bytes_sent, is_connected, connected_at, updated_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                    client_ip = VALUES(client_ip),
                    virtual_ip = VALUES(virtual_ip),
                    bytes_received = VALUES(bytes_received),
                    bytes_sent = VALUES(bytes_sent),
                    is_connected = 1,
                    updated_at = NOW()
                ");
                
                $real_ip = explode(':', $conn['real_address'])[0]; // Remove port
                $stmt->execute([
                    $user_id,
                    $server_id,
                    $real_ip,
                    $conn['virtual_address'],
                    $conn['bytes_received'],
                    $conn['bytes_sent']
                ]);
                
                echo "  ✅ Updated: {$conn['username']} -> {$conn['virtual_address']} (from {$real_ip})\n";
            } else {
                echo "  ❌ User '{$conn['username']}' not found in database\n";
            }
        }
        
        echo "\n🎉 Database updated successfully!\n";
        echo "💻 Your VPN dashboard should now show the active connections.\n";
        
    } else {
        echo "❌ No connection data received from server\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n🎯 Manual update completed!\n";