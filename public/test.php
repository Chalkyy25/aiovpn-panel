<?php
$dsn = "mysql:host=127.0.0.1;dbname=aiovpn;charset=utf8mb4";
$user = "aiovpn";
$pass = "secret";

try {
    $pdo = new PDO($dsn, $user, $pass);
    echo "✅ Connected as {$user}@127.0.0.1\n";
    $stmt = $pdo->query("SELECT NOW() as now");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "DB Time: " . $row['now'] . "\n";
} catch (PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage();
}
