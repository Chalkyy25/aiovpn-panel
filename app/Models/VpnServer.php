<?php

namespace App\Models;

use App\Traits\ExecutesRemoteCommands;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class VpnServer extends Model
{
    use HasFactory, ExecutesRemoteCommands;

    protected $appends = ['is_online'];

    protected $fillable = [
        'name',
        'ip_address',
        'protocol',
        'ssh_port',
        'ssh_type',
        'ssh_key',
        'ssh_password',
        'ssh_user',
        'port',
        'mgmt_port',
        'transport',
        'dns',
        'enable_ipv6',
        'enable_logging',
        'enable_proxy',
        'header1',
        'header2',
        'deployment_status',
        'deployment_log',
        'is_online' => 'boolean',
        'status',
        'status_log_path',   // <- reported by deploy script
    ];
    protected $casts = [
    'last_sync_at' => 'datetime',
];

    /* ─────────────── Relationships ─────────────── */

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_vpn_server');
    }

    public function vpnUsers(): BelongsToMany
    {
        return $this->belongsToMany(VpnUser::class, 'vpn_user_server', 'server_id', 'user_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class, 'vpn_server_id');
    }

    public function activeConnections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class, 'vpn_server_id')
            ->where('is_connected', true);
    }

    /* ─────────────── Deployment log helper ─────────────── */

    public function appendLog(string $line): void
    {
        Log::info("APPEND_LOG: ".$line);

        $existing = trim($this->deployment_log ?? '');
        $lines = $existing === '' ? [] : explode("\n", $existing);

        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
            $this->update([
                'deployment_log' => implode("\n", $lines),
            ]);
        }
    }

    /* ─────────────── Accessors / helpers ─────────────── */

    public function getOnlineUserCount(): int
    {
        if (empty($this->ip_address)) {
            Log::warning("⚠️ Cannot get online user count for {$this->name}: IP is empty");
            return 0;
        }

        // Default to the v3 path reported by the deploy script
        $statusPath = $this->status_log_path ?: '/run/openvpn/server.status';

        try {
            // Count CLIENT_LIST rows in status-version 3 (TSV)
            $cmd = 'bash -lc ' . escapeshellarg(
                "awk -F '\t' '\$1==\"CLIENT_LIST\"{c++} END{print c+0}' " . escapeshellarg($statusPath)
            );

            $result = $this->executeRemoteCommand($this, $cmd);

            if (($result['status'] ?? 1) === 0 && isset($result['output'][0])) {
                return (int) trim((string) $result['output'][0]);
            }

            // Fallback for older v2-style files (CSV)
            $cmdV2 = 'bash -lc ' . escapeshellarg(
                "awk -F ',' '\$1==\"CLIENT_LIST\"{c++} END{print c+0}' " . escapeshellarg($statusPath)
            );
            $result2 = $this->executeRemoteCommand($this, $cmdV2);

            if (($result2['status'] ?? 1) === 0 && isset($result2['output'][0])) {
                return (int) trim((string) $result2['output'][0]);
            }
        } catch (Exception $e) {
            Log::error("❌ Failed to get online user count for {$this->name}: ".$e->getMessage());
        }

        return 0;
    }

    public function getSshCommand(): string
    {
        $ip = $this->ip_address;

        if (empty($ip)) {
            Log::error("❌ Cannot generate SSH command for {$this->name}: IP address missing");
            throw new InvalidArgumentException("Server IP address is required to generate SSH command");
        }

        $port = $this->ssh_port ?? 22;
        $user = $this->ssh_user ?? 'root';

        // Use a temp known_hosts file to avoid permission issues
        $tempSshDir = storage_path('app/temp_ssh');
        if (!is_dir($tempSshDir)) {
            mkdir($tempSshDir, 0700, true);
        }

        if ($this->ssh_type === 'key') {
            // Accept absolute path or filename (resolved under storage/app/ssh_keys)
            if (str_starts_with((string)$this->ssh_key, '/') || str_contains((string)$this->ssh_key, ':\\')) {
                $keyPath = $this->ssh_key;
            } else {
                $keyPath = storage_path('app/ssh_keys/' . ($this->ssh_key ?: 'id_rsa'));
            }
            return "ssh -i {$keyPath} -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o UserKnownHostsFile={$tempSshDir}/known_hosts -p {$port} {$user}@{$ip}";
        }

        // Password auth
        return "sshpass -p '{$this->ssh_password}' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o UserKnownHostsFile={$tempSshDir}/known_hosts -p {$port} {$user}@{$ip}";
    }

    /* ─────────────── Virtuals ─────────────── */

    public function getIsOnlineAttribute(): bool
    {
        // Treat successfully deployed servers as "online" for now
        return $this->deployment_status === 'succeeded';
    }

    /* ─────────────── Boot hooks ─────────────── */

    protected static function booted(): void
    {
        $ensureKey = function (self $vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = 'id_rsa';
            }
        };

        static::creating($ensureKey);
        static::updating($ensureKey);
    }
}
