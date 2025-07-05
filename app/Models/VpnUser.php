<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * Get password for authentication.
     */
    public function getAuthPassword()
    {
        return $this->password;
    }

    /**
     * Relationships.
     */
    public function vpnServer()
    {
        return $this->belongsToMany(VpnServer::class, 'vpn_server_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * Booted: auto WireGuard key generation, config build, credential sync.
     */
    protected static function booted(): void
    {
        static::creating(function ($vpnUser) {
            // 🔑 Generate WireGuard keys and assign unique IP
            $keys = self::generateWireGuardKeys();
            $vpnUser->wireguard_private_key = $keys['private'];
            $vpnUser->wireguard_public_key = $keys['public'];
            $vpnUser->wireguard_address = '10.66.66.' . rand(2, 254) . '/32';

            // Generate random username if not set
            if (empty($vpnUser->username)) {
                $vpnUser->username = 'wg-' . Str::random(6);
            }
        });

        static::created(function ($vpnUser) {
            \App\Services\VpnConfigBuilder::generate($vpnUser);

            if ($vpnUser->vpnServer) {
                dispatch(new \App\Jobs\AddWireGuardPeer($vpnUser, $vpnUser->vpnServer));
            }
        });

        static::saved(function ($vpnUser) {
            if ($vpnUser->vpnServer) {
                \App\Jobs\SyncOpenVPNCredentials::dispatch($vpnUser->vpnServer);
            }
        });

        static::deleted(function ($vpnUser) {
            if ($vpnUser->vpnServer) {
                \App\Jobs\SyncOpenVPNCredentials::dispatch($vpnUser->vpnServer);
            }
        });
    }

    /**
     * Generate WireGuard private/public keypair.
     */
    public static function generateWireGuardKeys(): array
    {
        $private = trim(shell_exec('wg genkey'));
        $public = trim(shell_exec("echo '$private' | wg pubkey"));

        Log::info("🔑 WireGuard keys generated | Private: {$private}, Public: {$public}");

        return [
            'private' => $private,
            'public' => $public,
        ];
    }
}
