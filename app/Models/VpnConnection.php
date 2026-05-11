<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VpnConnection extends Model
{
    // WireGuard needs longer to avoid false “offline”
    public const OPENVPN_STALE_SECONDS = 120;
    public const WIREGUARD_STALE_SECONDS = 180;

    protected $fillable = [
        'vpn_server_id',
        'vpn_user_id',
        'protocol',
        'session_key',
        'wg_public_key',
        'client_ip',
        'virtual_ip',
        'endpoint',
        'bytes_in',
        'bytes_out',
        'connected_at',
        'last_seen_at',
        'disconnected_at',
        'is_active',
    ];

    protected $casts = [
        'connected_at'    => 'datetime',
        'last_seen_at'    => 'datetime',
        'disconnected_at' => 'datetime',
        'is_active'       => 'boolean',
        'bytes_in'        => 'integer',
        'bytes_out'       => 'integer',
    ];

    public function vpnUser()
    {
        return $this->belongsTo(\App\Models\VpnUser::class, 'vpn_user_id');
    }

    public function vpnServer()
    {
        return $this->belongsTo(\App\Models\VpnServer::class, 'vpn_server_id');
    }

    /**
     * "Live" = has a fresh last_seen_at within the protocol-specific threshold.
     *
     * Protocol rules:
     *  - OPENVPN:   is_active = 1  AND  last_seen_at >= OPENVPN_STALE_SECONDS ago
     *  - WIREGUARD: last_seen_at >= WIREGUARD_STALE_SECONDS ago  (no is_active check)
     *
     * WireGuard has no true disconnect event; liveness is inferred purely from
     * last_seen_at freshness.  is_active can temporarily drift to 0 during
     * handshake gaps and must NOT gate WireGuard liveness.
     */
    public function scopeLive(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= now();

        $ovpnCutoff = $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS);
        $wgCutoff   = $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS);

        return $query
            ->whereNotNull('last_seen_at')
            ->where(function (Builder $q) use ($ovpnCutoff, $wgCutoff) {
                // OpenVPN: requires both the active flag and a fresh heartbeat.
                $q->where(function (Builder $q) use ($ovpnCutoff) {
                    $q->where('protocol', 'OPENVPN')
                        ->where('is_active', 1)
                        ->where('last_seen_at', '>=', $ovpnCutoff);
                // WireGuard: only freshness matters -- is_active is a compatibility
                // write-through and must not gate liveness.
                })->orWhere(function (Builder $q) use ($wgCutoff) {
                    $q->where('protocol', 'WIREGUARD')
                        ->where('last_seen_at', '>=', $wgCutoff);
                });
            });
    }


    /**
     * Whether this single instance is currently live.
     * Mirrors the scopeLive() logic so widgets can call this on a loaded model
     * instead of duplicating the stale-threshold logic inline.
     *
     * Protocol rules:
     *  - WIREGUARD: only last_seen_at freshness is checked. is_active can
     *               temporarily drift during handshake gaps and must NOT gate
     *               WireGuard liveness.
     *  - OPENVPN:   is_active = true  AND  last_seen_at is fresh.
     */
    public function isLive(?Carbon $now = null): bool
    {
        if (! $this->last_seen_at) {
            return false;
        }

        $now ??= now();

        return match (strtoupper((string) $this->protocol)) {
            // WireGuard liveness is inferred purely from last_seen_at freshness.
            'WIREGUARD' => $this->last_seen_at->greaterThanOrEqualTo(
                $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS)
            ),
            // OpenVPN requires both the flag and a fresh heartbeat.
            'OPENVPN' => $this->is_active && $this->last_seen_at->greaterThanOrEqualTo(
                $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS)
            ),
            // Unknown protocols fall back to the OpenVPN model as a safe default.
            default => $this->is_active && $this->last_seen_at->greaterThanOrEqualTo(
                $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS)
            ),
        };
    }

    /**
     * “Stale” = active in DB, but last_seen_at is older than the live cutoff.
     */
    public function scopeStale(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= now();

        $ovpnCutoff = $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS);
        $wgCutoff = $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS);

        return $query
            ->where('is_active', 1)
            ->whereNotNull('last_seen_at')
            ->where(function (Builder $q) use ($ovpnCutoff, $wgCutoff) {
                $q->where(function (Builder $q) use ($ovpnCutoff) {
                    $q->where('protocol', 'OPENVPN')
                        ->where('last_seen_at', '<', $ovpnCutoff);
                })->orWhere(function (Builder $q) use ($wgCutoff) {
                    $q->where('protocol', 'WIREGUARD')
                        ->where('last_seen_at', '<', $wgCutoff);
                });
            });
    }
}