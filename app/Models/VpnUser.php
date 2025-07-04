<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable; // For client login
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VpnUser extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'vpn_server_id',
        'username',
        'password',
        'client_id',
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
     * Model events to automate config generation and sync on create/save/delete
     */
    protected static function booted(): void
    {
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
}
