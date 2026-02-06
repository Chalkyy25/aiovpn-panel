<?php

namespace App\Models;

use App\Jobs\RemoveOpenVPNUser;
use App\Jobs\RemoveWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class VpnUser extends Authenticatable
{
    use HasFactory, ExecutesRemoteCommands, HasApiTokens;

    public const GENERATED_PASSWORD_LENGTH = 5;

    protected $table = 'vpn_users';

    protected $fillable = [
        'username',
        'plain_password',
        'password',
        'device_name',
        'client_id',
        'wireguard_private_key',
        'wireguard_public_key',
        'wireguard_address',
        'max_connections',
        'is_online',
        'last_seen_at',
        'expires_at',
        'is_active',
        'last_ip',
        'is_trial',
    ];

    protected $hidden = [
        'password',
        'plain_password',
        'wireguard_private_key',
    ];

    protected $casts = [
        'is_online'    => 'boolean',
        'is_active'    => 'boolean',
        'is_trial'     => 'boolean',
        'last_seen_at' => 'datetime',
        'expires_at'   => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    /* ========= Auth ========= */

    public function getAuthIdentifierName(): string
    {
        return 'username';
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    protected function password(): Attribute
    {
        return Attribute::set(function ($value) {
            if (blank($value)) {
                return $this->password;
            }

            $v = (string) $value;

            // keep if already a bcrypt hash
            if (strlen($v) === 60 && str_starts_with($v, '$2y$')) {
                return $v;
            }

            return Hash::make($v);
        });
    }

    protected function plainPassword(): Attribute
    {
        return Attribute::set(function ($value) {
            if (!blank($value)) {
                $this->attributes['password'] = Hash::make((string) $value);
            }
            return $value;
        });
    }

    // disable remember token
    public function setRememberToken($value): void {}
    public function getRememberToken(): ?string { return null; }
    public function getRememberTokenName(): string { return 'remember_token'; }

    /* ========= Relationships ========= */

    public function vpnServers(): BelongsToMany
    {
        return $this->belongsToMany(
            VpnServer::class,
            'vpn_server_user',    // pivot
            'vpn_user_id',
            'vpn_server_id'
        )->withTimestamps();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class);
    }

    public function activeConnections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class)
            ->where('is_connected', true);
    }

    /* ========= Scopes / computed ========= */

    public function scopeTrials($q)       { return $q->where('is_trial', true); }
    public function scopeActiveTrials($q) { return $q->where('is_trial', true)->where('expires_at', '>', now()); }
    public function scopeExpired($q)      { return $q->whereNotNull('expires_at')->where('expires_at', '<=', now()); }
    public function scopeActive($q)       { return $q->where('is_active', true); }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function onlineSince(): Attribute
    {
        return Attribute::get(function () {
            $coll = $this->relationLoaded('activeConnections')
                ? $this->activeConnections
                : $this->activeConnections()->get();

            $ts = $coll->min('connected_at');
            return $ts ? Carbon::parse($ts) : null;
        });
    }

    public function lastDisconnectedAt(): Attribute
    {
        return Attribute::get(function () {
            $coll = $this->relationLoaded('connections')
                ? $this->connections
                : $this->connections()->get();

            $ts = $coll->where('is_connected', false)->max('disconnected_at');
            return $ts ? Carbon::parse($ts) : null;
        });
    }

    public function activeConnectionsCount(): Attribute
    {
        return Attribute::get(fn () =>
            $this->relationLoaded('activeConnections')
                ? $this->activeConnections->count()
                : (int) $this->activeConnections()->count()
        );
    }

    /** 0 = unlimited devices */
    public function canConnect(): bool
    {
        return (int) $this->max_connections === 0
            ? true
            : $this->activeConnectionsCount < (int) $this->max_connections;
    }

    public function getConnectionLimitTextAttribute(): string
    {
        return ((int) $this->max_connections === 0)
            ? 'Unlimited'
            : (string) (int) $this->max_connections;
    }

    public function getConnectionSummaryAttribute(): string
    {
        return ((int) $this->max_connections === 0)
            ? $this->activeConnectionsCount . '/∞'
            : $this->activeConnectionsCount . '/' . (int) $this->max_connections;
    }

    /**
     * Check if this user is over their device limit.
     * Returns true if they have MORE active connections than allowed.
     */
    public function isOverDeviceLimit(): bool
    {
        if ((int) $this->max_connections === 0) {
            return false; // unlimited
        }

        $activeCount = $this->activeConnections()->count();
        return $activeCount > (int) $this->max_connections;
    }

    /**
     * Disconnect oldest connections if user exceeds device limit.
     * Typically called after new connection is established.
     * Actually kills sessions on VPN servers.
     * 
     * @return int Number of connections disconnected
     */
    public function enforceDeviceLimit(): int
    {
        if ((int) $this->max_connections === 0) {
            return 0; // unlimited, nothing to enforce
        }

        $activeConnections = $this->activeConnections()
            ->with('vpnServer')
            ->orderBy('connected_at', 'asc') // oldest first
            ->get();

        $maxAllowed = (int) $this->max_connections;
        $currentCount = $activeConnections->count();

        if ($currentCount <= $maxAllowed) {
            return 0; // within limit
        }

        $toDisconnect = $currentCount - $maxAllowed;
        $now = now();
        $disconnectedCount = 0;

        foreach ($activeConnections->take($toDisconnect) as $conn) {
            // Kill the actual session on VPN server
            $this->killVpnSession($conn);

            // Update database
            $conn->update([
                'is_connected'     => false,
                'disconnected_at'  => $now,
                'session_duration' => $conn->connected_at ? $now->diffInSeconds($conn->connected_at) : null,
            ]);

            $disconnectedCount++;

            Log::channel('vpn')->info(sprintf(
                'DEVICE_LIMIT: ✂️ Auto-killed %s session %s for user %s (exceeded %d/%d)',
                $conn->protocol,
                $conn->session_key,
                $this->username,
                $currentCount,
                $maxAllowed
            ));
        }

        // Update user's online status if needed
        VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($this->id);

        return $disconnectedCount;
    }

    /**
     * Kill a VPN session on the actual server (not just database).
     */
    private function killVpnSession(VpnUserConnection $conn): void
    {
        try {
            $server = $conn->vpnServer;
            if (!$server) {
                return;
            }

            if ($conn->protocol === 'WIREGUARD') {
                // Remove WireGuard peer
                $publicKey = $conn->public_key;
                if (!$publicKey) {
                    return;
                }

                $interface = $server->wg_interface ?? 'wg0';
                $command = sprintf(
                    'wg set %s peer %s remove 2>/dev/null || true',
                    escapeshellarg($interface),
                    escapeshellarg($publicKey)
                );

                $this->executeRemoteCommand($server, $command, 5);

            } else {
                // Kill OpenVPN client via management interface
                $mgmtPort = $conn->mgmt_port ?: 7505;
                $clientId = $conn->client_id;

                if ($clientId === null) {
                    return;
                }

                $command = sprintf(
                    'echo \"kill %s\" | nc 127.0.0.1 %d 2>/dev/null || true',
                    escapeshellarg((string)$clientId),
                    $mgmtPort
                );

                $this->executeRemoteCommand($server, $command, 5);
            }
        } catch (\Throwable $e) {
            Log::channel('vpn')->error(sprintf(
                'Failed to kill VPN session %s: %s',
                $conn->session_key,
                $e->getMessage()
            ));
        }
    }

    /**
     * Sync assigned servers and handle side-effects (jobs + logs) centrally.
     *
     * @return array{attached: array<int,int>, detached: array<int,int>, updated: array<int,int>}
     */
    public function syncVpnServers(array $serverIds, ?string $context = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $serverIds), fn ($id) => $id > 0));

        $changes = $this->vpnServers()->sync($ids);

        $ctx = $context ? " context={$context}" : '';

        if (!empty($changes['attached'])) {
            $attachedServers = VpnServer::query()
                ->whereIn('id', $changes['attached'])
                ->get();

            foreach ($attachedServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);

                Log::channel('vpn')->info(sprintf(
                    'VPN_USER_SERVERS: attached vpn_user_id=%d username=%s server_id=%d server=%s%s',
                    (int) $this->id,
                    (string) $this->username,
                    (int) $server->id,
                    (string) $server->name,
                    $ctx
                ));
            }
        }

        if (!empty($changes['detached'])) {
            Log::channel('vpn')->info(sprintf(
                'VPN_USER_SERVERS: detached vpn_user_id=%d username=%s server_ids=[%s]%s',
                (int) $this->id,
                (string) $this->username,
                implode(',', array_map('intval', $changes['detached'])),
                $ctx
            ));
        }

        if (empty($changes['attached']) && empty($changes['detached']) && empty($changes['updated'])) {
            Log::channel('vpn')->info(sprintf(
                'VPN_USER_SERVERS: sync no-op vpn_user_id=%d username=%s%s',
                (int) $this->id,
                (string) $this->username,
                $ctx
            ));
        }

        return $changes;
    }

    /* ========= Model events ========= */

    protected static function booted(): void
    {
        static::creating(function (self $u) {
            // username
            $u->username = trim((string) ($u->username ?? ''));
            if ($u->username === '' || strtoupper($u->username) === 'UNDEF') {
                $u->username = 'wg-' . Str::lower(Str::random(10));
            }

            // ensure password
            if (blank($u->plain_password) && blank($u->password)) {
                $generated = Str::random(self::GENERATED_PASSWORD_LENGTH);
                $u->plain_password = $generated;
                $u->password       = Hash::make($generated);
            } elseif (!blank($u->plain_password) && blank($u->password)) {
                $u->password = Hash::make($u->plain_password);
            }

            // defaults
            $u->max_connections ??= 1;
            $u->is_active       ??= true;

            // optional auto WG identity
            if (config('services.wireguard.autogen', false)) {
                // keys
                if (blank($u->wireguard_private_key) || blank($u->wireguard_public_key)) {
                    $keys = self::generateWireGuardKeys();
                    $u->wireguard_private_key = $keys['private'];
                    $u->wireguard_public_key  = $keys['public'];
                } elseif (blank($u->wireguard_public_key) && !blank($u->wireguard_private_key)) {
                    $pub = self::wgPublicFromPrivate($u->wireguard_private_key);
                    if ($pub) {
                        $u->wireguard_public_key = $pub;
                    }
                }

                // address (always store /32)
                if (blank($u->wireguard_address)) {
                    do {
                        $last = random_int(2, 254);
                        $ip   = "10.66.66.$last/32";
                    } while (self::where('wireguard_address', $ip)->exists());

                    $u->wireguard_address = $ip;
                }
            }
        });

        static::created(function (self $u) {
            if ($u->vpnServers()->exists()) {
                foreach ($u->vpnServers as $server) {
                    SyncOpenVPNCredentials::dispatch($server);
                    Log::channel('vpn')->info("OpenVPN creds synced to {$server->name} ({$server->ip_address})");
                }
            }
        });

        static::deleting(function (self $u) {
            Log::info("Cleanup for VPN user: {$u->username}");
            $u->loadMissing('vpnServers');

            if (config('services.wireguard.autogen', false) && !blank($u->wireguard_public_key)) {
                foreach ($u->vpnServers as $server) {
                    RemoveWireGuardPeer::dispatch(clone $u, $server);
                }
                Log::info("WG peer removal queued for {$u->username}");
            }

            if ($u->vpnServers()->exists()) {
                RemoveOpenVPNUser::dispatch($u);
                Log::info("OpenVPN cleanup queued for {$u->username}");
            }
        });
    }

        /* ========= WireGuard helpers ========= */

    /**
     * Generate a valid X25519 keypair for WireGuard.
     * Requires libsodium on the panel. No wg binary.
     */
    public static function generateWireGuardKeys(): array
    {
        if (!function_exists('sodium_crypto_scalarmult_base')) {
            throw new \RuntimeException('WireGuard keygen: libsodium extension missing');
        }

        // 32-byte private key, clamped for X25519
        $sk = random_bytes(32);
        $sk[0]  = $sk[0]  & "\xF8";
        $sk[31] = ($sk[31] & "\x7F") | "\x40";

        $pk = sodium_crypto_scalarmult_base($sk);

        return [
            'private' => base64_encode($sk),
            'public'  => base64_encode($pk),
        ];
    }

    /**
     * Derive base64 public key from base64 (or raw) private key.
     */
    public static function wgPublicFromPrivate(string $private): ?string
    {
        if (!function_exists('sodium_crypto_scalarmult_base')) {
            return null;
        }

        $raw = base64_decode($private, true);
        if ($raw === false) {
            $raw = $private;
        }

        if (!is_string($raw) || strlen($raw) !== 32) {
            return null;
        }

        // clamp
        $raw[0]  = $raw[0]  & "\xF8";
        $raw[31] = ($raw[31] & "\x7F") | "\x40";

        return base64_encode(sodium_crypto_scalarmult_base($raw));
    }

}