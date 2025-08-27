<?php

namespace App\Models;

use Carbon\Carbon;
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
        'session_duration',
        'bytes_received',
        'bytes_sent',
    ];

    protected $casts = [
        'is_connected'     => 'boolean',
        'connected_at'     => 'datetime',
        'disconnected_at'  => 'datetime',
        'session_duration' => 'integer',
        'bytes_received'   => 'integer',
        'bytes_sent'       => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function vpnUser(): BelongsTo
    {
        return $this->belongsTo(VpnUser::class);
    }

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    public function scopeDisconnected($query)
    {
        return $query->where('is_connected', false);
    }

    public function scopeForServer($query, int $serverId)
    {
        return $query->where('vpn_server_id', $serverId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('vpn_user_id', $userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * If user has no active connections anywhere, mark them offline and
     * set last_seen_at to the most recent disconnected_at we know about.
     */
    public static function updateUserOnlineStatusIfNoActiveConnections(int $userId): void
    {
        $hasActive = static::where('vpn_user_id', $userId)
            ->where('is_connected', true)
            ->exists();

        if ($hasActive) {
            VpnUser::where('id', $userId)->update(['is_online' => true]);
            return;
        }

        $lastDisc = static::where('vpn_user_id', $userId)->max('disconnected_at');

        VpnUser::where('id', $userId)->update([
            'is_online'    => false,
            'last_seen_at' => $lastDisc,
        ]);
    }

    /**
     * Duration (seconds). Uses stored session_duration if available,
     * otherwise calculates live duration for connected sessions.
     */
    public function getConnectionDurationAttribute(): ?int
    {
        if ($this->session_duration) {
            return $this->session_duration;
        }

        if (!$this->connected_at instanceof Carbon) {
            return null;
        }

        $end = $this->disconnected_at instanceof Carbon ? $this->disconnected_at : now();
        return $this->connected_at->diffInSeconds($end);
    }

    /**
     * Human-readable formatted session duration.
     */
    public function getSessionDurationFormattedAttribute(): string
    {
        $seconds = $this->connection_duration;

        if (!$seconds) {
            return '-';
        }

        $minutes = intdiv($seconds, 60);
        $hours   = intdiv($minutes, 60);
        $minutes = $minutes % 60;

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    /**
     * Total bytes transferred on this connection.
     */
    public function getTotalBytesAttribute(): int
    {
        return (int) $this->bytes_received + (int) $this->bytes_sent;
    }

    /**
     * Human readable total bytes.
     */
    public function getFormattedBytesAttribute(): string
    {
        $bytes = $this->total_bytes;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}