<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VpnConnection extends Model
{
    protected $fillable = [
        'vpn_server_id',
        'vpn_user_id',
        'protocol',
        'session_key',
        'wg_public_key',
        'client_ip',
        'virtual_ip',
        'endpoint',
        'bytes_in',
        'bytes_out',
        'connected_at',
        'last_seen_at',
        'disconnected_at',
        'is_active',
    ];

    protected $casts = [
        'connected_at'    => 'datetime',
        'last_seen_at'    => 'datetime',
        'disconnected_at' => 'datetime',
        'is_active'       => 'boolean',
        'bytes_in'        => 'integer',
        'bytes_out'       => 'integer',
    ];

    public function vpnUser()
    {
        return $this->belongsTo(\App\Models\VpnUser::class, 'vpn_user_id');
    }

    public function vpnServer()
    {
        return $this->belongsTo(\App\Models\VpnServer::class, 'vpn_server_id');
    }
}