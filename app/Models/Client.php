<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'username',
        'password',
        'vpn_server_id',
    ];

    public function vpnServer()
    {
        return $this->belongsTo(VpnServer::class);
    }
}
