<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WireguardPeer extends Model
{
    protected $table = 'wireguard_peers';

    protected $fillable = [
        'vpn_server_id',
        'user_id',
        'public_key',
        'preshared_key',
        'private_key_encrypted',
        'ip_address',
        'allowed_ips',
        'dns',
        'revoked',
        'last_handshake_at',
        'transfer_rx_bytes',
        'transfer_tx_bytes',
    ];

    protected $casts = [
        'revoked'            => 'boolean',
        'last_handshake_at'  => 'datetime',
        'transfer_rx_bytes'  => 'integer',
        'transfer_tx_bytes'  => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class, 'vpn_server_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Convenience: access private key via accessors

    public function getPrivateKeyAttribute(): ?string
    {
        $enc = $this->private_key_encrypted;
        return $enc ? decrypt($enc) : null;
    }

    public function setPrivateKeyAttribute(?string $value): void
    {
        $this->attributes['private_key_encrypted'] = $value ? encrypt($value) : null;
    }
}