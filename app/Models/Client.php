<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'username',
        'password',
        'vpn_server_id',
    ];

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }
}
