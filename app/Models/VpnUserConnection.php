<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VpnUserConnection extends Model
{
    use HasFactory;

    /**
     * This table is written by trusted backend logic (mgmt agent / controller).
     * Don't fight mass-assignment here.
     */
    protected $guarded = [];

    protected $casts = [
        'is_connected'     => 'boolean',
        'connected_at'     => 'datetime',
        'disconnected_at'  => 'datetime',
        'session_duration' => 'integer',
        'bytes_received'   => 'integer',
        'bytes_sent'       => 'integer',
        'client_id'        => 'integer',
        'mgmt_port'        => 'integer',
    ];

    /* ───────────────────────── Relationships ───────────────────────── */

    public function vpnUser(): BelongsTo
    {
        return $this->belongsTo(VpnUser::class);
    }

    public function vpnServer(): BelongsTo
    {
        return $this->belongsTo(VpnServer::class);
    }

    /* ───────────────────────── Scopes ───────────────────────── */

    public function scopeConnected($q)
    {
        return $q->where('is_connected', true);
    }

    public function scopeDisconnected($q)
    {
        return $q->where('is_connected', false);
    }

    public function scopeForServer($q, int $serverId)
    {
        return $q->where('vpn_server_id', $serverId);
    }

    public function scopeForUser($q, int $userId)
    {
        return $q->where('vpn_user_id', $userId);
    }

    /* ───────────────────────── Helpers ───────────────────────── */

    public function isWireguard(): bool
    {
        return strtoupper((string) $this->protocol) === 'WIREGUARD';
    }

    public function isOpenvpn(): bool
    {
        return strtoupper((string) $this->protocol) === 'OPENVPN';
    }

    /**
     * Always return a stable session key.
     * - WireGuard: wg:<public_key>
     * - OpenVPN:   ovpn:<mgmt_port>:<client_id>:<username>
     */
    public function computedSessionKey(): ?string
    {
        if ($this->isWireguard()) {
            return $this->public_key ? "wg:{$this->public_key}" : null;
        }

        if ($this->isOpenvpn()) {
            if (!$this->client_id) return null;
            $mp = $this->mgmt_port ?: 7505;

            // If you ever store username on the connection table, use it here.
            // For now, session_key is managed in controller, so this is fallback only.
            return $this->session_key ?: "ovpn:{$mp}:{$this->client_id}";
        }

        return $this->session_key ?: null;
    }

    /**
     * If user has no active connections anywhere, mark them offline and
     * set last_seen_at to most recent disconnected_at.
     */
    public static function updateUserOnlineStatusIfNoActiveConnections(int $userId): void
    {
        $hasActive = static::where('vpn_user_id', $userId)
            ->where('is_connected', true)
            ->exists();

        if ($hasActive) {
            VpnUser::whereKey($userId)->update(['is_online' => true]);
            return;
        }

        $lastDisc = static::where('vpn_user_id', $userId)->max('disconnected_at');

        VpnUser::whereKey($userId)->update([
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
        if (!empty($this->session_duration)) {
            return (int) $this->session_duration;
        }

        if (!$this->connected_at instanceof Carbon) {
            return null;
        }

        $end = $this->disconnected_at instanceof Carbon ? $this->disconnected_at : now();
        return $this->connected_at->diffInSeconds($end);
    }

    public function getSessionDurationFormattedAttribute(): string
    {
        $seconds = $this->connection_duration;
        if (!$seconds) return '-';

        $minutes = intdiv($seconds, 60);
        $hours   = intdiv($minutes, 60);
        $minutes = $minutes % 60;

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    public function getTotalBytesAttribute(): int
    {
        return (int) $this->bytes_received + (int) $this->bytes_sent;
    }

    public function getFormattedBytesAttribute(): string
    {
        $bytes = $this->total_bytes;

        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}