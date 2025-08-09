<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KickHistory extends Model
{
    use HasFactory;

    protected $table = 'kick_history';

    protected $fillable = [
        'user_id',
        'kicked_by',
        'kicked_at',
        'reason',
    ];

    protected $casts = [
        'kicked_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────────

    public function vpnUser(): BelongsTo
    {
        return $this->belongsTo(VpnUser::class, 'user_id');
    }

    public function kickedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kicked_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAdmin($query, $adminId)
    {
        return $query->where('kicked_by', $adminId);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('kicked_at', '>=', now()->subDays($days));
    }
}