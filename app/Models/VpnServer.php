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

    /** Virtuals appended when casting to array/json */
    protected $appends = ['is_online', 'display_location', 'country_name'];

    /** Mass-assignable fields */
    protected $fillable = [
        'name',
        'ip_address',
        'protocol',        // 'openvpn' | 'wireguard'
        'ssh_port',
        'ssh_type',        // 'key' | 'password'
        'ssh_key',
        'ssh_password',
        'ssh_user',
        'port',            // OpenVPN port (udp/tcp)
        'mgmt_port',
        'transport',       // 'udp' | 'tcp' (null for WireGuard)
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

        // Location & metadata
        'location',
        'region',
        'country_code',
        'city',
        'tags',

        // WireGuard facts
        'wg_endpoint_host',
        'wg_public_key',
        'wg_port',
        'wg_subnet',
    ];

    /** Hide sensitive stuff by default from API responses */
    protected $hidden = [
        'ssh_password',
        'ssh_key',
        'deploy_key_id',
        'deployment_log',
        'status_log_path',
    ];

    /** Defaults for new rows */
    protected $attributes = [
        'protocol'  => 'openvpn',
        'transport' => 'udp',
    ];

    /** Type casts */
    protected $casts = [
        'last_sync_at'   => 'datetime',
        'is_online'      => 'boolean',
        'enable_ipv6'    => 'boolean',
        'enable_proxy'   => 'boolean',
        'enable_logging' => 'boolean',
        'wg_port'        => 'integer',
        'port'           => 'integer',
        'mgmt_port'      => 'integer',
        'protocol'       => 'string',
        'transport'      => 'string',
        'tags'           => 'array',
    ];

    /* ========= Route binding ========= */

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /* ========= Relationships ========= */

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_vpn_server');
    }

    public function vpnUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            VpnUser::class,
            'vpn_server_user',      // pivot table
            'vpn_server_id',        // this model's key on pivot
            'vpn_user_id'           // related model's key on pivot
        )->withTimestamps();
    }
    
    public function wireguardPeers()
{
    return $this->hasMany(\App\Models\WireguardPeer::class, 'vpn_server_id');
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

    /* ========= Query scopes ========= */

    public function scopeOpenVpn($q)   { return $q->where('protocol', 'openvpn'); }
    public function scopeWireGuard($q) { return $q->where('protocol', 'wireguard'); }
    public function scopeOnline($q)    { return $q->where('status', 'online'); } // adjust if you persist differently

    /* ========= Mutators / Helpers ========= */

    public function appendLog(string $line): void
    {
        Log::info("APPEND_LOG: " . $line);

        $existing = trim((string) $this->deployment_log);
        $lines = $existing === '' ? [] : explode("\n", $existing);

        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
            $this->forceFill(['deployment_log' => implode("\n", $lines)])->save();
        }
    }

    public function isWireGuard(): bool { return strtolower((string)$this->protocol) === 'wireguard'; }
    public function isOpenVPN(): bool   { return strtolower((string)$this->protocol) === 'openvpn'; }

    public function displayTransport(): ?string
    {
        return $this->isOpenVPN() ? ($this->transport ?: 'udp') : null;
    }

    public function displayPort(): int
    {
        if ($this->isWireGuard()) return (int) ($this->wg_port ?: 51820);
        // OpenVPN
        if (($this->displayTransport() ?? 'udp') === 'tcp') {
            return (int) ($this->port ?: 443);
        }
        return (int) ($this->port ?: 1194);
    }
    
        public function getCountryNameAttribute(): ?string
    {
        $code = strtoupper((string) $this->country_code);
        if ($code === '') {
            return null;
        }

        // Minimal ISO2 → country mapping.
        // Add only what you actually use; we don't need a full world DB.
        $map = [
            'DE' => 'Germany',
            'ES' => 'Spain',
            'GB' => 'United Kingdom',
            'UK' => 'United Kingdom',
            'FR' => 'France',
            'NL' => 'Netherlands',
            'US' => 'United States',
            'CA' => 'Canada',
        ];

        return $map[$code] ?? null;
    }

    public function getDisplayLocationAttribute(): string
    {
        $countryName = $this->country_name; // uses accessor above
        $city        = $this->city;
        $region      = $this->region;
        $location    = $this->location;

        // Best: City, Country
        if ($city && $countryName) {
            return "{$city}, {$countryName}";
        }

        // Fallback: City, CC
        if ($city && $this->country_code) {
            return "{$city}, " . strtoupper($this->country_code);
        }

        // Fallback: region + country
        if ($region && $countryName) {
            return "{$region}, {$countryName}";
        }

        // Legacy: plain location string
        if ($location) {
            return $location;
        }

        // Worst case: server name
        return (string) $this->name;
    }

    /* ========= WireGuard ========= */

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

    public function saveWireGuardFacts(array $facts): void
    {
        $this->fill(array_intersect_key($facts, array_flip([
            'wg_endpoint_host', 'wg_public_key', 'wg_port', 'wg_subnet',
        ])));

        if (isset($this->wg_port)) {
            $this->wg_port = (int) $this->wg_port ?: 51820;
        }
        if (isset($this->wg_subnet) && $this->wg_subnet === '') {
            $this->wg_subnet = null;
        }

        $this->save();
    }

    /* ========= API DTO ========= */

    public function toApiArray(): array
    {
        return array_filter([
            'id'        => (int) $this->id,
            'name'      => $this->name,
            'ip'        => $this->ip_address,
            'protocol'  => $this->protocol,
            'transport' => $this->displayTransport(),
            'port'      => $this->displayPort(),
            'wg'        => $this->isWireGuard() ? array_filter([
                'endpoint'   => $this->wgEndpoint(),
                'public_key' => $this->wg_public_key,
                'subnet'     => $this->wg_subnet,
            ]) : null,
        ], static function ($v) { return !is_null($v); });
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
            Log::error("❌ Failed to get online user count for {$this->name}: " . $e->getMessage());
        }

        return 0;
    }

    public function getSshCommand(): string
    {
        $ip = $this->ip_address;
        if (blank($ip)) {
            Log::error("❌ Cannot generate SSH command for {$this->name}: IP address missing");
            throw new InvalidArgumentException("Server IP address is required to generate SSH command");
        }

        $port = (int) ($this->ssh_port ?: 22);
        $user = (string) ($this->ssh_user ?: 'root');

        $tempSshDir = storage_path('app/temp_ssh');
        if (!is_dir($tempSshDir)) {
            @mkdir($tempSshDir, 0700, true);
        }
        $kh = escapeshellarg($tempSshDir . '/known_hosts');

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

        // 2) Legacy: password
        if ($this->ssh_type === 'password') {
            $pass = (string) $this->ssh_password;
            if ($pass === '') {
                throw new InvalidArgumentException('SSH password not set for password auth.');
            }
            return "sshpass -p " . escapeshellarg($pass) . " ssh {$baseOpts} {$dest}";
        }

        // 3) Legacy: key path
        $keyPath = (is_string($this->ssh_key) && (str_starts_with($this->ssh_key, '/') || str_contains($this->ssh_key, ':\\')))
            ? $this->ssh_key
            : storage_path('app/ssh_keys/' . ($this->ssh_key ?: 'id_rsa'));

        if (!is_file($keyPath)) {
            throw new InvalidArgumentException("SSH key not found at {$keyPath}");
        }
        @chmod($keyPath, 0600);

        return "ssh -i " . escapeshellarg($keyPath) . " {$baseOpts} {$dest}";
    }

    /* ========= Computed attributes ========= */

    public function getIsOnlineAttribute($value): bool
    {
        if (!is_null($this->attributes['is_online'] ?? null)) {
            return (bool) $this->attributes['is_online'];
        }

        return Cache::remember("server:{$this->id}:is_online", 60, function () {
            if (method_exists(VpnConfigBuilder::class, 'testOpenVpnConnectivity') && $this->isOpenVPN()) {
                $res = VpnConfigBuilder::testOpenVpnConnectivity($this);
                return ($res['server_reachable'] ?? false)
                    && (($res['openvpn_running'] ?? false) || ($res['port_open'] ?? false));
            }
            return $this->quickOnlineProbe();
        });
    }

    /** Minimal inline probe (OpenVPN or WireGuard) */
    private function quickOnlineProbe(): bool
    {
        $ip = $this->ip_address;
        if (!$ip) return false;

        try {
            // 1) SSH reachable?
            $ssh = $this->executeRemoteCommand($this, 'echo ok');
            if (($ssh['status'] ?? 1) !== 0) return false;

            if ($this->isWireGuard()) {
                // WireGuard service (common unit names) or port
                $svc = $this->executeRemoteCommand(
                    $this,
                    'systemctl is-active wg-quick@wg0 || systemctl is-active wg-quick@server || echo inactive'
                );
                $active = ($svc['status'] === 0)
                    && collect($svc['output'] ?? [])->contains(fn($l) => trim($l) === 'active');

                $port = $this->displayPort(); // 51820 default
                $cmd = sprintf(
                    "ss -ulnp 2>/dev/null | grep ':%d' || netstat -ulnp 2>/dev/null | grep ':%d' || true",
                    $port, $port
                );
                $portRes = $this->executeRemoteCommand($this, $cmd);
                $portOpen = ($portRes['status'] === 0) && !empty($portRes['output']);

                return $active || $portOpen;
            }

            // OpenVPN
            $svc = $this->executeRemoteCommand(
                $this,
                'systemctl is-active openvpn-server@server || systemctl is-active openvpn@server || systemctl is-active openvpn || echo inactive'
            );
            $active = ($svc['status'] === 0)
                && collect($svc['output'] ?? [])->contains(fn($l) => trim($l) === 'active');

            $transport = $this->displayTransport() ?? 'udp';
            $port      = $this->displayPort();
            $ssOpt     = $transport === 'tcp' ? '-tl' : '-ul';

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

        static::creating(function (self $m) use ($ensureKey) {
            $m->protocol  = $m->protocol ? strtolower($m->protocol) : 'openvpn';
            $m->transport = $m->transport ? strtolower($m->transport) : 'udp';
            if ($m->protocol === 'wireguard') $m->transport = null;
            $ensureKey($m);
        });

        static::updating(function (self $m) use ($ensureKey) {
            if ($m->protocol)  $m->protocol  = strtolower($m->protocol);
            if ($m->transport) $m->transport = strtolower($m->transport);

            if ($m->isDirty('protocol') && $m->protocol === 'wireguard') {
                $m->transport = null; // WG has no udp/tcp transport concept
            }
            $ensureKey($m);
        });
    }
}