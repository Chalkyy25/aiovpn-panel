<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class VpnConnection extends Model
{
    // WireGuard needs longer to avoid false “offline”
    public const OPENVPN_STALE_SECONDS = 120;
    public const WIREGUARD_STALE_SECONDS = 60;

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
     * “Live” = active + has last_seen_at + not stale (protocol-specific).
     */
    public function scopeLive(Builder $query, ?Carbon $now = null): Builder
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
                        ->where('last_seen_at', '>=', $ovpnCutoff);
                })->orWhere(function (Builder $q) use ($wgCutoff) {
                    $q->where('protocol', 'WIREGUARD')
                        ->where('last_seen_at', '>=', $wgCutoff);
                });
            });
    }


    /**
     * Whether this single instance is currently live.
     * Mirrors the live() scope so widgets can call this on a loaded model
     * instead of duplicating the stale-threshold logic inline.
     */
    public function isLive(?Carbon $now = null): bool
    {
        if (! $this->is_active || ! $this->last_seen_at) {
            return false;
        }

        $now ??= now();

        return match (strtoupper((string) $this->protocol)) {
            'WIREGUARD' => $this->last_seen_at->greaterThanOrEqualTo(
                $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS)
            ),
            'OPENVPN' => $this->last_seen_at->greaterThanOrEqualTo(
                $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS)
            ),
            // Unknown protocols fall back to the OpenVPN threshold as a safe default.
            default => $this->last_seen_at->greaterThanOrEqualTo(
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