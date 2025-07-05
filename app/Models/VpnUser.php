<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable; // For client login
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;

class VpnUser extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'vpn_server_id',
        'username',
        'password',
        'client_id',
        'wireguard_private_key',
        'wireguard_public_key',
        'wireguard_address',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Use password for authentication
     */
    public function getAuthPassword()
    {
        return $this->password;
    }

    /**
     * Relations
     */
    public function vpnServers()
    {
        return $this->belongsToMany(VpnServer::class, 'vpn_user_server');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * Model events to automate config generation, WireGuard keys, and sync
     */
    protected static function booted(): void
    {
        static::creating(function ($vpnUser) {
            // 🔑 Generate WireGuard keys + address on create
            $keyPair = self::generateWireGuardKeys();
            $vpnUser->wireguard_private_key = $keyPair['private'];
            $vpnUser->wireguard_public_key = $keyPair['public'];
            $vpnUser->wireguard_address = '10.66.66.' . rand(2, 254) . '/32'; // example IP
        });

        static::created(function ($user) {
            \App\Services\VpnConfigBuilder::generate($user);
        });

        static::saved(function ($user) {
            if ($user->vpnServer) {
                \App\Jobs\SyncOpenVPNCredentials::dispatch($user->vpnServer);
            }
        });

        static::deleted(function ($user) {
            if ($user->vpnServer) {
                \App\Jobs\SyncOpenVPNCredentials::dispatch($user->vpnServer);
            }
        });
    }

    public static function generateWireGuardKeys(): array
    {
        $private = trim(shell_exec('wg genkey'));
        $public = trim(shell_exec("echo '{$private}' | wg pubkey"));

        Log::info("🔑 Generated WireGuard keys for user: private={$private}, public={$public}");

        return [
            'private' => $private,
            'public' => $public,
        ];
    }
}
