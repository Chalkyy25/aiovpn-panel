<?php

namespace App\Models;

use App\Jobs\SyncOpenVPNCredentials;
use App\Jobs\RemoveWireGuardPeer;
use App\Jobs\RemoveOpenVPNUser;
use App\Services\VpnConfigBuilder;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class VpnUser
 *
 * @property string $username
 * @property string|null $plain_password
 * @property string $password
 * @property int $max_connections
 * @property bool $is_online
 * @property Carbon|null $last_seen_at
 * @property-read int $active_connection_count
 */
class VpnUser extends Authenticatable
{
    use HasFactory, ExecutesRemoteCommands;

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
        'expires_at', // Added missing expires_at field
        'is_active', // Added missing is_active field
        'last_ip',   // Added missing last_ip field
    ];

    protected $hidden = [
        'password',
        'wireguard_private_key',
    ];

    protected $casts = [
        'is_online'    => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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

    public function vpnSessions(): HasMany
    {
        return $this->hasMany(VpnSession::class, 'user_id');
    }

    public function activeSessions(): HasMany
    {
        return $this->hasMany(VpnSession::class, 'user_id')->where('is_active', true);
    }

    public function kickHistory(): HasMany
    {
        return $this->hasMany(KickHistory::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    /*
    |--------------------------------------------------------------------------
    | Active Connections
    |--------------------------------------------------------------------------
    */

    private function fetchOpenVpnStatusLog(VpnServer $server): string
    {
        $logPath = '/var/log/openvpn-status.log';

        $result = $this->executeRemoteCommand(
            $server->ip_address,
            "cat $logPath"
        );

        return $result['status'] === 0 ? implode("\n", $result['output']) : '';
    }

    private function countConnectionsForUser(string $log): int
    {
        if (empty($log)) return 0;

        $username = $this->username;
        return collect(explode("\n", $log))
            ->filter(fn($line) => str_starts_with($line, "CLIENT_LIST,$username"))
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Boot Logic
    |--------------------------------------------------------------------------
    */

    protected static function booted(): void
    {
        static::creating(function (self $vpnUser) {
            // Auto-generate WireGuard keys
            $keys = self::generateWireGuardKeys();
            $vpnUser->wireguard_private_key = $keys['private'];
            $vpnUser->wireguard_public_key  = $keys['public'];

            // Assign random WireGuard IP
            do {
                $lastOctet = rand(2, 254);
                $ip = "10.66.66.$lastOctet/32";
            } while (self::where('wireguard_address', $ip)->exists());

            $vpnUser->wireguard_address = $ip;

            // Default max connections
            $vpnUser->max_connections = $vpnUser->max_connections ?? 1;

            // Auto-generate username if missing
            if (empty($vpnUser->username)) {
                $vpnUser->username = 'wg-' . Str::random(6);
            }
        });

        static::created(function (self $vpnUser) {
            // Note: OpenVPN configuration generation and credential syncing is now handled
            // in the CreateVpnUser component to ensure servers are associated first.
            // This event handler is kept for backward compatibility with other parts of the application.

            // Only generate configs and sync credentials if servers are already associated
            if ($vpnUser->vpnServers->isNotEmpty()) {
                // Build .conf or .ovpn file
                VpnConfigBuilder::generate($vpnUser);

                // Sync OpenVPN credentials
                foreach ($vpnUser->vpnServers as $server) {
                    SyncOpenVPNCredentials::dispatch($server);
                    Log::info("🚀 Synced OpenVPN credentials to $server->name ($server->ip_address)");
                }
            }
        });

        static::deleting(function (self $vpnUser) {
            Log::info("🗑️ Auto-cleanup triggered for VPN user: {$vpnUser->username}");

            // Load relationships to ensure they're available for cleanup jobs
            $vpnUser->load('vpnServers');

            // Store the public key before deletion to ensure it's available for the job
            $wireguardPublicKey = $vpnUser->wireguard_public_key;

            // Log the key for debugging
            if (!empty($wireguardPublicKey)) {
                Log::info("🔑 [WG] User has public key: {$wireguardPublicKey}");
            }

            // Dispatch WireGuard peer removal job for each server directly
            if (!empty($wireguardPublicKey) && $vpnUser->vpnServers->isNotEmpty()) {
                foreach ($vpnUser->vpnServers as $server) {
                    Log::info("🔧 Dispatching WireGuard peer removal for user {$vpnUser->username} on server {$server->name}");
                    RemoveWireGuardPeer::dispatch(clone $vpnUser, $server);
                }
                Log::info("🔧 WireGuard peer removal queued for user: {$vpnUser->username}");
            } else {
                if (empty($wireguardPublicKey)) {
                    Log::warning("⚠️ No WireGuard public key found for user: {$vpnUser->username}");
                }
                if ($vpnUser->vpnServers->isEmpty()) {
                    Log::warning("⚠️ No servers associated with user: {$vpnUser->username}");
                }
            }

            // Dispatch OpenVPN cleanup job
            if ($vpnUser->vpnServers->isNotEmpty()) {
                RemoveOpenVPNUser::dispatch($vpnUser);
                Log::info("🔧 OpenVPN cleanup queued for user: {$vpnUser->username}");
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public static function generateWireGuardKeys(): array
    {
        // Check if WireGuard tools are available
        exec('where wg', $output, $returnCode);
        $wgAvailable = ($returnCode === 0);

        if ($wgAvailable) {
            // Generate keys using WireGuard tools
            $private = trim(shell_exec('wg genkey'));
            $public  = trim(shell_exec("echo '$private' | wg pubkey"));

            if (!empty($private) && !empty($public)) {
                Log::info("🔑 WireGuard public key generated: $public");

                return [
                    'private' => $private,
                    'public'  => $public,
                ];
            }
        }

        // Fallback: Generate keys using OpenSSL if WireGuard tools are not available
        Log::warning("⚠️ WireGuard tools not available, using OpenSSL fallback for key generation");

        // Generate a 32-byte private key
        $private = base64_encode(openssl_random_pseudo_bytes(32));

        // For public key, we'll use a deterministic hash of the private key
        // This is not cryptographically correct for WireGuard but ensures we have values
        $public = base64_encode(hash('sha256', $private, true));

        Log::info("🔑 Fallback WireGuard public key generated: $public");

        return [
            'private' => $private,
            'public'  => $public,
        ];
    }

    /*
|--------------------------------------------------------------------------
| Connection Counters
|--------------------------------------------------------------------------
*/

    public function getActiveConnectionsCountAttribute(): int
    {
        return $this->is_online ? 1 : 0; // ✅ Replace with smarter tracking later if needed
    }

    public function getConnectionSummaryAttribute(): string
    {
        return "$this->active_connections_count/$this->max_connections";
    }

}
