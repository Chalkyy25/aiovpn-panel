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
                $generated = Str::random(20);
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
                    Log::info("OpenVPN creds synced to {$server->name} ({$server->ip_address})");
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
     * 1) libsodium (sodium_crypto_scalarmult_base)
     * 2) `wg` tools if available
     */
    public static function generateWireGuardKeys(): array
    {
        // 1) libsodium preferred (no wg binary dependency)
        if (function_exists('sodium_crypto_scalarmult_base')) {
            try {
                $sk = random_bytes(32);

                // X25519 clamp
                $sk[0]  = chr(ord($sk[0]) & 248);
                $sk[31] = chr((ord($sk[31]) & 127) | 64);

                $pk = sodium_crypto_scalarmult_base($sk);

                return [
                    'private' => base64_encode($sk),
                    'public'  => base64_encode($pk),
                ];
            } catch (\Throwable $e) {
                Log::warning('WireGuard: sodium keygen failed, falling back to wg: '.$e->getMessage());
            }
        }

        // 2) system wg tools
        $priv = trim((string) @shell_exec('/usr/bin/wg genkey 2>/dev/null'));
        if ($priv !== '') {
            $pub = trim((string) self::pipeTo('/usr/bin/wg pubkey', $priv . "\n"));
            if ($pub !== '') {
                // wg already returns base64 keys
                return [
                    'private' => $priv,
                    'public'  => $pub,
                ];
            }
        }

        throw new \RuntimeException('No valid WireGuard keygen available (libsodium or wg)');
    }

    /**
     * Derive base64 public key from base64 (or raw) private key.
     */
    public static function wgPublicFromPrivate(string $private): ?string
    {
        try {
            $raw = base64_decode($private, true);
            if ($raw === false) {
                $raw = $private;
            }

            if (!is_string($raw) || strlen($raw) !== 32) {
                return null;
            }
            if (!function_exists('sodium_crypto_scalarmult_base')) {
                return null;
            }

            $pk = sodium_crypto_scalarmult_base($raw);
            return base64_encode($pk);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function pipeTo(string $cmd, string $input): string
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return '';
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        proc_close($proc);

        return trim($out !== '' ? $out : $err);
    }
}