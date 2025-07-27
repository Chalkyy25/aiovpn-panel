<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class VpnServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ip_address',
        'protocol',
        'ssh_port',
        'ssh_type',
        'ssh_key',
        'ssh_password',
        'ssh_user',
        'port',
        'transport',
        'dns',
        'enable_ipv6',
        'enable_logging',
        'enable_proxy',
        'header1',
        'header2',
        'deployment_status',
        'deployment_log',
        'status',
    ];

    // ─── Relationships ──────────────────────────────────────────────
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_vpn_server');
    }

    public function vpnUsers(): BelongsToMany
    {
        return $this->belongsToMany(VpnUser::class, 'vpn_server_user');
    }

    // ─── Deployment log helper ──────────────────────────────────────
    public function appendLog(string $line): void
    {
        Log::info("APPEND_LOG: " . $line);
        $existing = trim($this->deployment_log ?? '');
        $lines = $existing === '' ? [] : explode("\n", $existing);

        if (!in_array($line, $lines)) {
            $lines[] = $line;
            $this->update([
                'deployment_log' => implode("\n", $lines),
            ]);
        }
    }

    // ─── Accessors & Mutators ───────────────────────────────────────
    public function getDeploymentStatusAttribute($value): string
    {
        return $value;
    }

    public function setDeploymentStatusAttribute($value): void
    {
        $this->attributes['deployment_status'] = strtolower($value);
    }

    public function getOnlineUserCount(): int
    {
        $ssh = $this->getSshCommand();
        $statusPath = '/etc/openvpn/openvpn-status.log';

        $cmd = "$ssh 'cat $statusPath | grep -E \"^CLIENT_LIST\" | wc -l'";
        exec($cmd, $output, $code);

        if ($code === 0 && isset($output[0])) {
            return (int) trim($output[0]);
        }

        return 0;
    }


    // ─── Status Helpers ─────────────────────────────────────────────
    public function isDeployed(): bool
    {
        return $this->deployment_status === 'deployed';
    }

    public function isPending(): bool
    {
        return $this->deployment_status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->deployment_status === 'failed';
    }

    public function isActive(): bool
    {
        return $this->deployment_status === 'active';
    }

    public function isInactive(): bool
    {
        return $this->deployment_status === 'inactive';
    }

    // ─── Status Scopes ──────────────────────────────────────────────
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deployment_status', 'active');
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('deployment_status', 'inactive');
    }

    public function scopeDeployed(Builder $query): Builder
    {
        return $query->where('deployment_status', 'deployed');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('deployment_status', 'pending');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('deployment_status', 'failed');
    }

    // ─── SSH Command Generator ──────────────────────────────────────

    // ─── Boot Hooks ─────────────────────────────────────────────────
    protected static function booted(): void
    {
        static::creating(function (self $vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa_www';
            }
        });

        static::updating(function (self $vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa_www';
            }
        });
    }
}
