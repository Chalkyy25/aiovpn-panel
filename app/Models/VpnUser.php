<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VpnUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'vpn_server_id',
        'username',
        'password',
        'client_id',
    ];

    // Relations
    public function vpnServer()
    {
        return $this->belongsTo(\App\Models\VpnServer::class, 'vpn_server_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    // 👇 Add this just before the final closing brace
    protected static function booted(): void
    {
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
