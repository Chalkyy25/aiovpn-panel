<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnConnection extends Model
{
    protected $table = 'vpn_connections';

    protected $fillable = [
        'vpn_server_id','vpn_user_id','protocol',
        'session_key','wg_public_key',
        'client_ip','virtual_ip','endpoint',
        'bytes_in','bytes_out',
        'connected_at','last_seen_at','disconnected_at',
        'is_active',
    ];

    protected $casts = [
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'is_active' => 'boolean',
        'connected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function vpnServer(): BelongsTo { return $this->belongsTo(VpnServer::class, 'vpn_server_id'); }
    public function vpnUser(): BelongsTo { return $this->belongsTo(VpnUser::class, 'vpn_user_id'); }
}