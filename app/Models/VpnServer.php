<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function getOnlineUserCount(): int
    {
        $ssh = $this->getSshCommand(); // This must return something like: ssh -i /path/to/key root@ip
        $statusPath = '/etc/openvpn/openvpn-status.log';

        // Count client lines between "Common Name" and "ROUTING TABLE"
        $cmd = "$ssh \"awk '/Common Name/{flag=1;next}/ROUTING TABLE/{flag=0}flag' $statusPath | wc -l\"";

        exec($cmd, $output, $code);

        if ($code === 0 && isset($output[0])) {
            return (int) trim($output[0]);
        }

        return 0;
    }

    public function getSshCommand(): string
    {
        $ip = $this->ip_address;
        $port = $this->ssh_port ?? 22;
        $user = $this->ssh_user ?? 'root';

        if ($this->ssh_type === 'key') {
            $keyPath = storage_path("ssh/$this->ssh_key_path");

            // 💥 Add this line temporarily:
            \Log::info("SSH CMD: ssh -i $keyPath -o StrictHostKeyChecking=no -p $port $user@$ip");

            return "ssh -i $keyPath -o StrictHostKeyChecking=no -p $port $user@$ip";
        }

        return "sshpass -p '$this->ssh_password' ssh -o StrictHostKeyChecking=no -p $port $user@$ip";
    }




    // ─── Status Helpers ─────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->deployment_status === 'active';
    }

    // ─── Status Scopes ──────────────────────────────────────────────

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
