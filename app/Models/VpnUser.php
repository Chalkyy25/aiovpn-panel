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
        'username',
	'plain_password',
        'password',
	'device_name',
        'client_id',
        'wireguard_private_key',
        'wireguard_public_key',
        'wireguard_address',
    ];

    protected $hidden = [
        'password',
        'wireguard_private_key', // ✅ Hide private key from outputs
    ];

    /**
     * ✅ Get password for authentication.
     */
    public function getAuthPassword()
    {
        return $this->password;
    }

    /**
     * ✅ Many-to-Many: user can belong to multiple VPN servers.
     */
public function vpnServers()
{
    return $this->belongsToMany(
        VpnServer::class,
        'vpn_server_user', // pivot table
        'vpn_user_id',     // this model's key on pivot
        'vpn_server_id'    // related model's key on pivot
    );
}
    /**
     * ✅ Relationship: linked client record.
     */
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * ✅ Boot events for auto WireGuard key generation and IP allocation.
     */
    protected static function booted(): void
    {
        static::creating(function ($vpnUser) {
            // 🔑 Generate WireGuard keys
            $keys = self::generateWireGuardKeys();
            $vpnUser->wireguard_private_key = $keys['private'];
            $vpnUser->wireguard_public_key = $keys['public'];

            // 🔢 Allocate unique WireGuard IP
            do {
                $lastOctet = rand(2, 254);
                $ip = "10.66.66.$lastOctet/32";
            } while (self::where('wireguard_address', $ip)->exists());
            $vpnUser->wireguard_address = $ip;

            // 🔠 Generate random username if not provided
            if (empty($vpnUser->username)) {
                $vpnUser->username = 'wg-' . Str::random(6);
            }
        });

        static::created(function ($vpnUser) {
            \App\Services\VpnConfigBuilder::generate($vpnUser);
        });
    }

    /**
     * ✅ Generate WireGuard private/public keypair.
     */
    public static function generateWireGuardKeys(): array
    {
        $private = trim(shell_exec('wg genkey'));
        $public = trim(shell_exec("echo '$private' | wg pubkey"));

        // ⚠️ Avoid logging private keys in production
        Log::info("🔑 WireGuard public key generated: {$public}");

        return [
            'private' => $private,
            'public' => $public,
        ];
    }
}
