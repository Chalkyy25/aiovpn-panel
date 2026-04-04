<?php

namespace App\Models;

use App\Jobs\RemoveOpenVPNUser;
use App\Jobs\RemoveWireGuardPeer;
use App\Jobs\ReconcileWireGuardServer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Services\WireGuardIpAllocator;
use App\Traits\ExecutesRemoteCommands;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class VpnUser extends Authenticatable
{
    use HasFactory;
    use ExecutesRemoteCommands;
    use HasApiTokens;

    public const GENERATED_PASSWORD_LENGTH = 5;

    protected $table = 'vpn_users';

    protected $fillable = [
        'client_id',
        'created_by',
        'username',
        'plain_password',
        'password',
        'device_name',
        'max_connections',
        'is_active',
        'is_trial',
        'expires_at',
        'is_online',
        'last_seen_at',
        'last_ip',
        'wireguard_private_key',
        'wireguard_public_key',
        'wireguard_address',
    ];

    protected $hidden = [
        'password',
        'plain_password',
        'wireguard_private_key',
    ];

    protected $casts = [
        'max_connections' => 'integer',
        'is_online'       => 'boolean',
        'is_active'       => 'boolean',
        'is_trial'        => 'boolean',
        'last_seen_at'    => 'datetime',
        'expires_at'      => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    /* =========================
     | Auth
     ========================= */

    public function getAuthIdentifierName(): string
    {
        return 'username';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password;
    }

    public function setRememberToken($value): void {}
    public function getRememberToken(): ?string { return null; }
    public function getRememberTokenName(): string { return 'remember_token'; }

    /* =========================
     | Relationships
     ========================= */

    public function vpnServers(): BelongsToMany
    {
        return $this->belongsToMany(
            VpnServer::class,
            'vpn_server_user',
            'vpn_user_id',
            'vpn_server_id'
        )->withTimestamps();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class);
    }

    public function sessionConnections(): HasMany
    {
        return $this->hasMany(VpnConnection::class, 'vpn_user_id');
    }

    public function liveSessionConnections(): HasMany
    {
        return $this->hasMany(VpnConnection::class, 'vpn_user_id')->live();
    }

    public function activeConnections(): HasMany
    {
        return $this->hasMany(VpnUserConnection::class)->where('is_connected', true);
    }

    /* =========================
     | Scopes
     ========================= */

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOnlineNow(Builder $q, ?Carbon $now = null): Builder
    {
        return $q->whereHas('sessionConnections', fn (Builder $sessions) => $sessions->live($now));
    }

    public function scopeTrials(Builder $q): Builder
    {
        return $q->where('is_trial', true);
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function scopeActiveTrials(Builder $q): Builder
    {
        return $q->where('is_trial', true)->where('expires_at', '>', now());
    }

    /* =========================
     | Computed helpers
     ========================= */

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function activeConnectionsCount(): Attribute
    {
        return Attribute::get(fn (): int =>
            $this->relationLoaded('activeConnections')
                ? $this->activeConnections->count()
                : (int) $this->activeConnections()->count()
        );
    }

    public function connectionLimitText(): Attribute
    {
        return Attribute::get(fn (): string =>
            ((int) $this->max_connections === 0) ? 'Unlimited' : (string) (int) $this->max_connections
        );
    }

    public function connectionSummary(): Attribute
    {
        return Attribute::get(fn (): string =>
            ((int) $this->max_connections === 0)
                ? ($this->activeConnectionsCount . '/∞')
                : ($this->activeConnectionsCount . '/' . (int) $this->max_connections)
        );
    }

    public function onlineSince(): Attribute
    {
        return Attribute::get(function (): ?CarbonInterface {
            $connections = $this->relationLoaded('activeConnections')
                ? $this->activeConnections
                : $this->activeConnections()->get();

            $ts = $connections->min('connected_at');

            return $ts ? $ts->copy() : null;
        });
    }

    public function lastDisconnectedAt(): Attribute
    {
        return Attribute::get(function (): ?CarbonInterface {
            $connections = $this->relationLoaded('connections')
                ? $this->connections
                : $this->connections()->get();

            $ts = $connections->where('is_connected', false)->max('disconnected_at');

            return $ts ? $ts->copy() : null;
        });
    }

    public function canConnect(): bool
    {
        $limit = (int) $this->max_connections;

        return $limit === 0
            ? true
            : ($this->activeConnectionsCount < $limit);
    }

    public function isOverDeviceLimit(): bool
    {
        $limit = (int) $this->max_connections;

        if ($limit === 0) {
            return false;
        }

        return (int) $this->activeConnections()->count() > $limit;
    }

    /* =========================
     | Attribute mutators
     ========================= */

    protected function password(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (blank($value)) {
                    return $this->attributes['password'] ?? null;
                }

                $v = (string) $value;

                if (Hash::isHashed($v)) {
                    return $v;
                }

                return Hash::make($v);
            }
        );
    }

    protected function plainPassword(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (! blank($value)) {
                    $this->attributes['password'] = Hash::make((string) $value);
                }

                return $value;
            }
        );
    }

    protected function wireguardAddress(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (blank($value)) {
                    return null;
                }

                $ip = preg_replace('/\/\d+$/', '', trim((string) $value));

                return $ip !== '' ? $ip . '/32' : null;
            }
        );
    }

    /* =========================
     | Device limit enforcement
     ========================= */

    public function enforceDeviceLimit(): int
    {
        $limit = (int) $this->max_connections;

        if ($limit === 0) {
            return 0;
        }

        $activeConnections = $this->activeConnections()
            ->with('vpnServer')
            ->orderBy('connected_at', 'asc')
            ->get();

        $current = $activeConnections->count();

        if ($current <= $limit) {
            return 0;
        }

        $toDisconnect = $current - $limit;
        $now = now();
        $disconnected = 0;

        foreach ($activeConnections->take($toDisconnect) as $conn) {
            $this->killVpnSession($conn);

            $conn->update([
                'is_connected'     => false,
                'disconnected_at'  => $now,
                'session_duration' => $conn->connected_at ? $now->diffInSeconds($conn->connected_at) : null,
            ]);

            $disconnected++;

            Log::channel('vpn')->info(sprintf(
                'DEVICE_LIMIT: auto-killed %s session=%s user=%s (%d>%d)',
                $conn->protocol,
                $conn->session_key,
                $this->username,
                $current,
                $limit
            ));
        }

        VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($this->id);

        return $disconnected;
    }

    private function killVpnSession(VpnUserConnection $conn): void
    {
        try {
            $server = $conn->vpnServer;
            if (! $server) {
                return;
            }

            if ($conn->protocol === 'WIREGUARD') {
                $publicKey = $conn->public_key;
                if (! $publicKey) {
                    return;
                }

                $interface = $server->wg_interface ?? 'wg0';

                $cmd = sprintf(
                    'wg set %s peer %s remove 2>/dev/null || true',
                    escapeshellarg($interface),
                    escapeshellarg($publicKey)
                );

                $this->executeRemoteCommand($server, $cmd, 5);
                return;
            }

            $mgmtPort = (int) ($conn->mgmt_port ?: 7505);
            $clientId = $conn->client_id;

            if ($clientId === null) {
                return;
            }

            $cmd = sprintf(
                'echo "kill %s" | nc 127.0.0.1 %d 2>/dev/null || true',
                escapeshellarg((string) $clientId),
                $mgmtPort
            );

            $this->executeRemoteCommand($server, $cmd, 5);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error(sprintf(
                'Failed to kill session=%s user=%s err=%s',
                (string) $conn->session_key,
                (string) $this->username,
                $e->getMessage()
            ));
        }
    }

    /* =========================
     | Server sync helper
     ========================= */

    public function syncVpnServers(array $serverIds, ?string $context = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $serverIds), fn ($id) => $id > 0));

        $changes = $this->vpnServers()->sync($ids);

        $ctx = $context ? " context={$context}" : '';

        if (! empty($changes['attached'])) {
            $servers = VpnServer::query()->whereIn('id', $changes['attached'])->get();

            foreach ($servers as $server) {
                SyncOpenVPNCredentials::dispatch((int) $server->id);

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

        if (! empty($changes['detached'])) {
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

    /* =========================
 | Model events
 ========================= */

protected static function booted(): void
{
    static::creating(function (self $u) {
        $u->username = trim((string) ($u->username ?? ''));

        if ($u->username === '' || strtoupper($u->username) === 'UNDEF') {
            $u->username = 'wg-' . Str::lower(Str::random(10));
        }

        $u->max_connections ??= 1;
        $u->is_active ??= true;

        if (blank($u->plain_password) && blank($u->password)) {
            $generated = Str::random(self::GENERATED_PASSWORD_LENGTH);
            $u->plain_password = $generated;
            $u->password = Hash::make($generated);
        } elseif (! blank($u->plain_password) && blank($u->password)) {
            $u->password = Hash::make((string) $u->plain_password);
        }

        if (config('services.wireguard.autogen', false)) {
            if (blank($u->wireguard_private_key) || blank($u->wireguard_public_key)) {
                $keys = self::generateWireGuardKeys();
                $u->wireguard_private_key = $keys['private'];
                $u->wireguard_public_key = $keys['public'];
            } elseif (blank($u->wireguard_public_key) && ! blank($u->wireguard_private_key)) {
                $pub = self::wgPublicFromPrivate($u->wireguard_private_key);
                if ($pub) {
                    $u->wireguard_public_key = $pub;
                }
            }

            if (blank($u->wireguard_address)) {
                $u->wireguard_address = WireGuardIpAllocator::next();
            }
        }
    });

    static::created(function (self $u) {
        $u->loadMissing('vpnServers');

        foreach ($u->vpnServers as $server) {
            SyncOpenVPNCredentials::dispatch((int) $server->id);

            Log::channel('vpn')->info(sprintf(
                'OpenVPN creds synced to server=%s ip=%s for user=%s',
                (string) $server->name,
                (string) $server->ip_address,
                (string) $u->username
            ));
        }
    });

    static::deleting(function (self $u) {
        $u->loadMissing('vpnServers');

        // capture linked server IDs before the row is gone
        $u->reconcileWireGuardServerIds = $u->vpnServers
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        Log::channel('vpn')->info("Cleanup queued for VPN user={$u->username}");

        if ($u->vpnServers->isNotEmpty()) {
            RemoveOpenVPNUser::dispatch($u);
        }
    });

    static::deleted(function (self $u) {
        if (! config('services.wireguard.autogen', false)) {
            return;
        }

        foreach ($u->reconcileWireGuardServerIds as $serverId) {
            $server = VpnServer::find($serverId);

            if (! $server) {
                continue;
            }

            Log::channel('vpn')->info("WG reconcile after delete user={$u->username} server={$server->name}");

            dispatch_sync(new ReconcileWireGuardServer($server));
        }
    });
}

    /* =========================
     | WireGuard helpers
     ========================= */

    public static function generateWireGuardKeys(): array
    {
        if (! function_exists('sodium_crypto_scalarmult_base')) {
            throw new \RuntimeException('WireGuard keygen: libsodium extension missing');
        }

        $sk = random_bytes(32);

        $sk[0]  = $sk[0]  & "\xF8";
        $sk[31] = ($sk[31] & "\x7F") | "\x40";

        $pk = sodium_crypto_scalarmult_base($sk);

        return [
            'private' => base64_encode($sk),
            'public'  => base64_encode($pk),
        ];
    }

    public static function wgPublicFromPrivate(string $private): ?string
    {
        if (! function_exists('sodium_crypto_scalarmult_base')) {
            return null;
        }

        $raw = base64_decode($private, true);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }

        $raw[0]  = $raw[0]  & "\xF8";
        $raw[31] = ($raw[31] & "\x7F") | "\x40";

        return base64_encode(sodium_crypto_scalarmult_base($raw));
    }
}