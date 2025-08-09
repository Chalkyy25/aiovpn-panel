<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'connected_at',
        'disconnected_at',
        'kicked_at',
        'kicked_by',
        'is_active',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'kicked_at' => 'datetime',
        'is_active' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeKicked($query)
    {
        return $query->whereNotNull('kicked_at');
    }

    // ─── Helper Methods ─────────────────────────────────────────────

    public function isKicked(): bool
    {
        return $this->kicked_at !== null;
    }

    public function kick(User $kickedBy, ?string $reason = null): void
    {
        $this->update([
            'kicked_at' => now(),
            'kicked_by' => $kickedBy->id,
            'is_active' => false,
            'disconnected_at' => now(),
        ]);

        // Create kick history record
        KickHistory::create([
            'user_id' => $this->user_id,
            'kicked_by' => $kickedBy->id,
            'kicked_at' => now(),
            'reason' => $reason,
        ]);
    }
}