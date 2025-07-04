<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    // Relationships
    public function clients()
    {
        return $this->belongsToMany(User::class, 'client_vpn_server');
    }

	public function vpnUsers()
	{
	    return $this->belongsToMany(VpnUser::class, 'vpn_user_server');
	}

    // Deployment log helper
    public function appendLog(string $line)
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

    // Deployment status accessors/mutators
    public function getDeploymentStatusAttribute($value)
    {
        return $value;
    }

    public function setDeploymentStatusAttribute($value)
    {
        $this->attributes['deployment_status'] = strtolower($value);
    }

    // Status helpers
    public function isDeployed()   { return $this->deployment_status === 'deployed'; }
    public function isPending()    { return $this->deployment_status === 'pending'; }
    public function isFailed()     { return $this->deployment_status === 'failed'; }
    public function isActive()     { return $this->deployment_status === 'active'; }
    public function isInactive()   { return $this->deployment_status === 'inactive'; }

    // Status scopes
    public function scopeActive($query)    { return $query->where('deployment_status', 'active'); }
    public function scopeInactive($query)  { return $query->where('deployment_status', 'inactive'); }
    public function scopeDeployed($query)  { return $query->where('deployment_status', 'deployed'); }
    public function scopePending($query)   { return $query->where('deployment_status', 'pending'); }
    public function scopeFailed($query)    { return $query->where('deployment_status', 'failed'); }

    // SSH command helper
    public function getSshCommand()
    {
        $sshUser = $this->ssh_user;
        $ip = $this->ip_address;
        $port = $this->ssh_port ?? 22;

        if ($this->ssh_type === 'key') {
            $keyPath = $this->ssh_key;
            return "ssh -i {$keyPath} -p {$port} {$sshUser}@{$ip}";
        } else {
            return "sshpass -p '{$this->ssh_password}' ssh -o StrictHostKeyChecking=no -p {$port} {$sshUser}@{$ip}";
        }
    }

    protected static function booted()
    {
        static::creating(function ($vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa_www';
            }
        });

        static::updating(function ($vpnServer) {
            if ($vpnServer->ssh_type === 'key' && blank($vpnServer->ssh_key)) {
                $vpnServer->ssh_key = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa_www';
            }
        });
    }
}
