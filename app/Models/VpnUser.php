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

class VpnUser extends Authenticatable
{
    use HasFactory, ExecutesRemoteCommands;

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

    /* ========= Auth (username + hashed password) ========= */

    public function getAuthIdentifierName(): string
    {
        return 'username';
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    // Hash on set; keep bcrypt as-is
    protected function password(): Attribute
    {
        return Attribute::set(function ($value) {
            if (blank($value)) {
                return $this->password;
            }
            return (is_string($value) && strlen($value) === 60 && str_starts_with($value, '$2y$'))
                ? $value
                : Hash::make($value);
        });
    }

    // Mirror plain_password -> password (hashed)
    protected function plainPassword(): Attribute
    {
        return Attribute::set(function ($value) {
            if (!blank($value)) {
                $this->attributes['password'] = Hash::make($value);
            }
            return $value;
        });
    }

    // This table has no remember_token column
    public function setRememberToken($value): void {}
    public function getRememberToken(): ?string { return null; }
    public function getRememberTokenName(): string { return 'remember_token'; }

    /* ========= Relationships ========= */

    public function vpnServers(): BelongsToMany
    {
        return $this->belongsToMany(VpnServer::class, 'vpn_user_server', 'user_id', 'server_id');
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
        if ((int) $this->max_connections === 0) {
            return true;
        }
        return $this->activeConnectionsCount < (int) $this->max_connections;
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
        static::creating(function (self $vpnUser) {
            $vpnUser->max_connections ??= 1;
            $vpnUser->is_active      ??= true;

            if (blank($vpnUser->username)) {
                $vpnUser->username = 'wg-' . Str::random(6);
            }

            // WG disabled for launch unless explicitly enabled
            if (config('services.wireguard.autogen', false)) {
                if (blank($vpnUser->wireguard_private_key) || blank($vpnUser->wireguard_public_key)) {
                    $keys = self::generateWireGuardKeys();
                    $vpnUser->wireguard_private_key = $keys['private'];
                    $vpnUser->wireguard_public_key  = $keys['public'];
                }

                if (blank($vpnUser->wireguard_address)) {
                    do {
                        $last = random_int(2, 254);
                        $ip = "10.66.66.$last/32";
                    } while (self::where('wireguard_address', $ip)->exists());
                    $vpnUser->wireguard_address = $ip;
                }
            }
        });

        static::created(function (self $vpnUser) {
            if ($vpnUser->vpnServers()->exists()) {
                foreach ($vpnUser->vpnServers as $server) {
                    SyncOpenVPNCredentials::dispatch($server);
                    Log::info("🚀 OpenVPN creds synced to {$server->name} ({$server->ip_address})");
                }
            }
        });

        static::deleting(function (self $vpnUser) {
            Log::info("🗑️ Cleanup for VPN user: {$vpnUser->username}");
            $vpnUser->loadMissing('vpnServers');

            if (config('services.wireguard.autogen', false) && !blank($vpnUser->wireguard_public_key)) {
                foreach ($vpnUser->vpnServers as $server) {
                    RemoveWireGuardPeer::dispatch(clone $vpnUser, $server);
                }
                Log::info("🔧 WG peer removal queued for {$vpnUser->username}");
            }

            if ($vpnUser->vpnServers()->exists()) {
                RemoveOpenVPNUser::dispatch($vpnUser);
                Log::info("🔧 OpenVPN cleanup queued for {$vpnUser->username}");
            }
        });
    }

    /* ========= Helpers ========= */

    public static function generateWireGuardKeys(): array
    {
        Log::warning('⚠️ WG tools disabled; using fallback key generation');
        $private = base64_encode(random_bytes(32));
        $public  = base64_encode(hash('sha256', $private, true));
        return ['private' => $private, 'public' => $public];
    }
}