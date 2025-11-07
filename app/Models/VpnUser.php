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

    public function getAuthIdentifierName(): string { return 'username'; }
    public function getAuthPassword(): string { return $this->password; }

    protected function password(): Attribute
    {
        return Attribute::set(function ($value) {
            if (blank($value)) return $this->password;
            $v = (string) $value;
            return (strlen($v) === 60 && str_starts_with($v, '$2y$')) ? $v : Hash::make($v);
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

    // No remember token column
    public function setRememberToken($value): void {}
    public function getRememberToken(): ?string { return null; }
    public function getRememberTokenName(): string { return 'remember_token'; }

    /* ========= Relationships ========= */

    public function vpnServers(): BelongsToMany
    {
        return $this->belongsToMany(
            VpnServer::class,
            'vpn_server_user',      // pivot table
            'vpn_user_id',          // this model's key on pivot
            'vpn_server_id'         // related model's key on pivot
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
        return $this->hasMany(VpnUserConnection::class)->where('is_connected', true);
    }

    /* ========= Scopes & computed ========= */

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
            $coll = $this->relationLoaded('activeConnections') ? $this->activeConnections : $this->activeConnections()->get();
            $ts = $coll->min('connected_at');
            return $ts ? Carbon::parse($ts) : null;
        });
    }

    public function lastDisconnectedAt(): Attribute
    {
        return Attribute::get(function () {
            $coll = $this->relationLoaded('connections') ? $this->connections : $this->connections()->get();
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
        return ((int) $this->max_connections === 0) ? 'Unlimited' : (string) (int) $this->max_connections;
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
            // Username (avoid UNDEF / blanks)
            $u->username = trim((string) ($u->username ?? ''));
            if ($u->username === '' || strtoupper($u->username) === 'UNDEF') {
                $u->username = 'wg-' . Str::lower(Str::random(10));
            }

            // Always ensure a password exists
            if (blank($u->plain_password) && blank($u->password)) {
                $generated = Str::random(20);
                $u->plain_password = $generated;
                $u->password       = Hash::make($generated);
            } elseif (!blank($u->plain_password) && blank($u->password)) {
                $u->password = Hash::make($u->plain_password);
            }

            // Defaults
            $u->max_connections ??= 1;
            $u->is_active       ??= true;

            // WireGuard autogen
            if (config('services.wireguard.autogen', false)) {
                if (blank($u->wireguard_private_key)) {
                    $keys = self::generateWireGuardKeys();
                    $u->wireguard_private_key = $keys['private'];
                    $u->wireguard_public_key  = $keys['public'];
                } elseif (blank($u->wireguard_public_key)) {
                    // derive public if only private provided
                    $pub = self::wgPublicFromPrivate($u->wireguard_private_key);
                    if ($pub) $u->wireguard_public_key = $pub;
                }

                if (blank($u->wireguard_address)) {
                    do {
                        $last = random_int(2, 254);
                        $ip = "10.66.66.$last/32";
                    } while (self::where('wireguard_address', $ip)->exists());
                    $u->wireguard_address = $ip;
                }
            }
        });

        static::created(function (self $u) {
            if ($u->vpnServers()->exists()) {
                foreach ($u->vpnServers as $server) {
                    SyncOpenVPNCredentials::dispatch($server);
                    Log::info("🚀 OpenVPN creds synced to {$server->name} ({$server->ip_address})");
                }
            }
        });

        static::deleting(function (self $u) {
            Log::info("🗑️ Cleanup for VPN user: {$u->username}");
            $u->loadMissing('vpnServers');

            if (config('services.wireguard.autogen', false) && !blank($u->wireguard_public_key)) {
                foreach ($u->vpnServers as $server) {
                    RemoveWireGuardPeer::dispatch(clone $u, $server);
                }
                Log::info("🔧 WG peer removal queued for {$u->username}");
            }

            if ($u->vpnServers()->exists()) {
                RemoveOpenVPNUser::dispatch($u);
                Log::info("🔧 OpenVPN cleanup queued for {$u->username}");
            }
        });
    }

    /* ========= WireGuard helpers ========= */

    /**
     * Generate a valid X25519 keypair for WireGuard.
     * Prefers libsodium; falls back to `wg` tools if available.
     */
    public static function generateWireGuardKeys(): array
    {
        // Prefer libsodium (pure PHP, no shell)
        if (function_exists('sodium_crypto_box_keypair')) {
            try {
                $kp   = sodium_crypto_box_keypair();
                $priv = sodium_crypto_box_secretkey($kp);           // 32 bytes
                $pub  = sodium_crypto_scalarmult_base($priv);       // derive X25519 public key
                return [
                    'private' => base64_encode($priv),
                    'public'  => base64_encode($pub),
                ];
            } catch (\Throwable $e) {
                Log::warning('WireGuard: sodium generation failed, falling back to wg tools: '.$e->getMessage());
            }
        }

        // Fallback to system wg binaries
        $priv = trim((string) @shell_exec('wg genkey 2>/dev/null'));
        if ($priv !== '') {
            $pub = trim((string) @shell_exec('printf %s '.escapeshellarg($priv).' | wg pubkey 2>/dev/null'));
            if ($pub !== '') {
                // wg tools output base64 already
                return ['private' => $priv, 'public' => $pub];
            }
        }

        // Last resort: random, but still derive proper public via sodium if present
        Log::warning('WireGuard: no sodium/wg tools available; generating random private and attempting sodium derive.');
        $rawPriv = random_bytes(32);
        $public  = function_exists('sodium_crypto_scalarmult_base')
            ? base64_encode(sodium_crypto_scalarmult_base($rawPriv))
            : null;

        return [
            'private' => base64_encode($rawPriv),
            'public'  => $public ?? base64_encode($rawPriv), // placeholder only if we truly cannot derive
        ];
    }

    /**
     * Derive a base64 public key from a base64 (or raw) private key.
     */
    public static function wgPublicFromPrivate(string $private): ?string
    {
        try {
            $raw = base64_decode($private, true);
            if ($raw === false) {
                // might already be raw
                $raw = $private;
            }
            if (!is_string($raw) || strlen($raw) !== 32) return null;
            if (!function_exists('sodium_crypto_scalarmult_base')) return null;
            return base64_encode(sodium_crypto_scalarmult_base($raw));
        } catch (\Throwable) {
            return null;
        }
    }
}