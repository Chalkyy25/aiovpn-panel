<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',        // ✅ Make sure this is fillable too
        'created_by',  // ✅ Track who created the user
        'is_active',   // ✅ Allow setting active status
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // ✅ Relationship to show who created this user
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function vpnServers()
{
    return $this->belongsToMany(VpnServer::class, 'client_vpn_server'); // adjust pivot table name if needed
}
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isReseller()
    {
        return $this->role === 'reseller';
    }

    public function isClient()
    {
        return $this->role === 'client';
    }
    public function isActive()
    {
        return $this->is_active;
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }
    public function scopeRole($query, $role)
    {
        return $query->where('role', $role);
    }
    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }
}
