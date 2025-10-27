<?php

namespace App\Models;

use App\Models\DeployKey;
use App\Services\VpnConfigBuilder;
use App\Traits\ExecutesRemoteCommands;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class VpnServer extends Model
{
    use HasFactory, ExecutesRemoteCommands;

    /** Computed at runtime if not persisted */
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
        'status',
        'status_log_path',
        'deploy_key_id',

        // ---- WireGuard facts ----
        'wg_endpoint_host',
        'wg_public_key',
        'wg_port',
        'wg_subnet',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
        'is_online'    => 'boolean',
        'enable_ipv6'  => 'boolean',
        'enable_proxy' => 'boolean',
        'enable_logging' => 'boolean',
        'wg_port'      => 'integer',
    ];

    /* ========= Relationships ========= */

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

    /** DB-backed SSH key (preferred over legacy ssh_key/ssh_type) */
    public function deployKey(): BelongsTo
    {
        return $this->belongsTo(DeployKey::class, 'deploy_key_id');
    }

    /* ========= Deployment log helper ========= */

    public function appendLog(string $line): void
    {
        Log::info("APPEND_LOG: ".$line);

        $existing = trim((string) $this->deployment_log);
        $lines = $existing === '' ? [] : explode("\n", $existing);

        // avoid dup spam
        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
            $this->forceFill(['deployment_log' => implode("\n", $lines)])->save();
        }
    }

    /* ========= WireGuard helpers ========= */

    public function hasWireGuard(): bool
    {
        return !empty($this->wg_public_key) && !empty($this->wg_port);
    }

    public function wgEndpoint(): ?string
    {
        if (empty($this->wg_endpoint_host) || empty($this->wg_port)) {
            return null;
        }
        return "{$this->wg_endpoint_host}:{$this->wg_port}";
    }

    /**
     * Persist WG facts safely (used by your API controllers).
     */
    public function saveWireGuardFacts(array $facts): void
    {
        $this->fill(array_intersect_key($facts, array_flip([
            'wg_endpoint_host', 'wg_public_key', 'wg_port', 'wg_subnet',
        ])));

        // basic normalization
        if (isset($this->wg_port)) {
            $this->wg_port = (int) $this->wg_port ?: 51820;
        }
        if (isset($this->wg_subnet) && $this->wg_subnet === '') {
            $this->wg_subnet = null;
        }

        $this->save();
    }

    /* ========= Online metrics / probes ========= */

    public function getOnlineUserCount(): int
    {
        if (blank($this->ip_address)) {
            Log::warning("⚠️ Cannot get online user count for {$this->name}: IP is empty");
            return 0;
        }

        $statusPath = $this->status_log_path ?: '/run/openvpn/server.status';

        try {
            // v3 (TSV)
            $cmd = 'bash -lc ' . escapeshellarg(
                "awk -F '\t' '\$1==\"CLIENT_LIST\"{c++} END{print c+0}' " . escapeshellarg($statusPath)
            );
            $result = $this->executeRemoteCommand($this, $cmd);
            if (($result['status'] ?? 1) === 0 && isset($result['output'][0])) {
                return (int) trim((string) $result['output'][0]);
            }

            // v2 (CSV) fallback
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

    /**
     * Preferred by ExecutesRemoteCommands.
     * Precedence:
     *  1) DeployKey (server->deployKey or default active) → key auth
     *  2) Legacy ssh_type=password → sshpass
     *  3) Legacy ssh_type=key → ssh_key absolute or storage/app/ssh_keys/<file>
     */
    public function getSshCommand(): string
    {
        $ip = $this->ip_address;
        if (blank($ip)) {
            Log::error("❌ Cannot generate SSH command for {$this->name}: IP address missing");
            throw new InvalidArgumentException("Server IP address is required to generate SSH command");
        }

        $port = (int) ($this->ssh_port ?: 22);
        $user = (string) ($this->ssh_user ?: 'root');

        // Use a temp known_hosts file to avoid permission issues
        $tempSshDir = storage_path('app/temp_ssh');
        if (!is_dir($tempSshDir)) {
            @mkdir($tempSshDir, 0700, true);
        }
        $kh = escapeshellarg($tempSshDir.'/known_hosts');

        $baseOpts = implode(' ', [
            '-o StrictHostKeyChecking=no',
            "-o UserKnownHostsFile={$kh}",
            '-o ConnectTimeout=30',
            '-o ServerAliveInterval=15',
            '-o ServerAliveCountMax=4',
            '-p ' . $port,
        ]);
        $dest = escapeshellarg("{$user}@{$ip}");

        // 1) DeployKey (DB-first)
        $dk = $this->deployKey ?: DeployKey::active()->first();
        if ($dk) {
            $keyPath = $dk->privateAbsolutePath();
            if (!is_file($keyPath)) {
                throw new InvalidArgumentException("DeployKey file not found at {$keyPath} (name={$dk->name})");
            }
            @chmod($keyPath, 0600);
            return "ssh -i " . escapeshellarg($keyPath) . " {$baseOpts} {$dest}";
        }

        // 2) Legacy: password auth
        if ($this->ssh_type === 'password') {
            $pass = (string) $this->ssh_password;
            if ($pass === '') {
                throw new InvalidArgumentException('SSH password not set for password auth.');
            }
            return "sshpass -p " . escapeshellarg($pass) . " ssh {$baseOpts} {$dest}";
        }

        // 3) Legacy: key auth
        $keyPath = (is_string($this->ssh_key) && (str_starts_with($this->ssh_key, '/') || str_contains($this->ssh_key, ':\\')))
            ? $this->ssh_key
            : storage_path('app/ssh_keys/' . ($this->ssh_key ?: 'id_rsa'));

        if (!is_file($keyPath)) {
            throw new InvalidArgumentException("SSH key not found at {$keyPath}");
        }
        @chmod($keyPath, 0600);

        return "ssh -i " . escapeshellarg($keyPath) . " {$baseOpts} {$dest}";
    }

    /* ========= Virtuals ========= */

    public function getIsOnlineAttribute($value): bool
    {
        // Respect persisted is_online if you store it
        if (!is_null($this->attributes['is_online'] ?? null)) {
            return (bool) $this->attributes['is_online'];
        }

        return Cache::remember("server:{$this->id}:is_online", 60, function () {
            if (method_exists(VpnConfigBuilder::class, 'testOpenVpnConnectivity')) {
                $res = VpnConfigBuilder::testOpenVpnConnectivity($this);
                return ($res['server_reachable'] ?? false)
                    && (($res['openvpn_running'] ?? false) || ($res['port_open'] ?? false));
            }

            return $this->quickOnlineProbe();
        });
    }

    /**
     * Minimal inline probe if the service method isn't available.
     */
    private function quickOnlineProbe(): bool
    {
        $ip = $this->ip_address;
        if (!$ip) return false;

        try {
            // 1) SSH reachable?
            $ssh = $this->executeRemoteCommand($this, 'echo ok');
            if (($ssh['status'] ?? 1) !== 0) return false;

            // 2) OpenVPN active? Check modern service first, fallback to legacy
            $svc = $this->executeRemoteCommand(
                $this,
                'systemctl is-active openvpn-server@server || systemctl is-active openvpn@server || systemctl is-active openvpn || echo inactive'
            );
            $active = ($svc['status'] === 0)
                && collect($svc['output'] ?? [])->contains(fn($l) => trim($l) === 'active');

            // 3) Port open? Use configured proto/port
            $proto = strtolower((string) ($this->protocol ?: 'udp'));
            $port  = (int) ($this->port ?: 1194);
            $ssOpt = $proto === 'tcp' ? '-tl' : '-ul';

            $cmd = sprintf(
                "ss %snp 2>/dev/null | grep ':%d' || netstat %snp 2>/dev/null | grep ':%d' || true",
                $ssOpt, $port, $ssOpt, $port
            );
            $portRes = $this->executeRemoteCommand($this, $cmd);
            $portOpen = ($portRes['status'] === 0) && !empty($portRes['output']);

            return $active || $portOpen;
        } catch (\Throwable) {
            return false;
        }
    }

    /* ========= Boot hooks ========= */

    protected static function booted(): void
    {
        $ensureKey = function (self $vpnServer) {
            // Keep legacy default for old records
            if (($vpnServer->ssh_type === 'key' || is_null($vpnServer->ssh_type)) && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = 'id_rsa';
            }
        };

        static::creating($ensureKey);
        static::updating($ensureKey);
    }
}