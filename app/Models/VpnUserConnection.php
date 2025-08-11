<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnUserConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'vpn_user_id',
        'vpn_server_id',
        'is_connected',
        'client_ip',
        'virtual_ip',
        'connected_at',
        'disconnected_at',
        'bytes_received',
        'bytes_sent',
    ];

    protected $casts = [
        'is_connected' => 'boolean',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'bytes_received' => 'integer',
        'bytes_sent' => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────────────────

    public function vpnUser(): BelongsTo
    {
        return $this->belongsTo(VpnUser::class);
    }

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    public function scopeDisconnected($query)
    {
        return $query->where('is_connected', false);
    }

    public function scopeForServer($query, $serverId)
    {
        return $query->where('vpn_server_id', $serverId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('vpn_user_id', $userId);
    }

    // ─── Helper Methods ─────────────────────────────────────────────

    /**
     * Update user's online status if they have no active connections.
     */
    public static function updateUserOnlineStatusIfNoActiveConnections(int $userId): void
    {
        $hasActiveConnections = static::where('vpn_user_id', $userId)
            ->where('is_connected', true)
            ->exists();

        if (!$hasActiveConnections) {
            VpnUser::where('id', $userId)->update([
                'is_online' => false,
                'last_seen_at' => now(),
            // latest known disconnect time for this user
            $lastDisc = self::where('vpn_user_id', $vpnUserId)->max('disconnected_at');
        
            \App\Models\VpnUser::where('id', $vpnUserId)->update([
                'is_online'    => false,
                'last_seen_at' => $lastDisc, // <-- key line
            ]);
        }
    }

    public function getConnectionDurationAttribute(): ?int
    {
        if (!$this->connected_at) {
            return null;
        }

        $endTime = $this->disconnected_at ?? now();
        return $this->connected_at->diffInSeconds($endTime);
    }

    public function getTotalBytesAttribute(): int
    {
        return $this->bytes_received + $this->bytes_sent;
    }

    public function getFormattedBytesAttribute(): string
    {
        $bytes = $this->getTotalBytesAttribute();

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
