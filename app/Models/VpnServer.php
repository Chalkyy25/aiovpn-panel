<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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
        'status', // <-- Add this line!
    ];

    /**
     * Append a line to the deployment log.
     */
    public function appendLog(string $line)
{
    Log::info("APPEND_LOG: " . $line);
    $existing = trim($this->deployment_log ?? '');
    $lines = $existing === '' ? [] : explode("\n", $existing);

    // Only add this line if it doesn't already exist in the log (prevents all duplicates)
    if (!in_array($line, $lines)) {
        $lines[] = $line;
        $this->update([
            'deployment_log' => implode("\n", $lines),
        ]);
    }
}

    /**
     * Relationship: many clients can be assigned to a VPN server.
     */
    public function clients()
    {
        return $this->belongsToMany(User::class, 'client_vpn_server');
    }

    // ---- Status accessors/mutators ----

    public function getDeploymentStatusAttribute($value)
    {
        return $value; // Return as-is, no formatting
    }

    public function setDeploymentStatusAttribute($value)
    {
        $this->attributes['deployment_status'] = strtolower($value);
    }

    // ---- Status helpers ----

    public function isDeployed()
    {
        return strtolower($this->attributes['deployment_status']) === 'deployed';
    }

    public function isPending()
    {
        return strtolower($this->attributes['deployment_status']) === 'pending';
    }

    public function isFailed()
    {
        return strtolower($this->attributes['deployment_status']) === 'failed';
    }

    public function isActive()
    {
        return strtolower($this->attributes['deployment_status']) === 'active';
    }

    public function isInactive()
    {
        return strtolower($this->attributes['deployment_status']) === 'inactive';
    }

    // ---- Status scopes ----

    public function scopeActive($query)
    {
        return $query->where('deployment_status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('deployment_status', 'inactive');
    }

    public function scopeDeployed($query)
    {
        return $query->where('deployment_status', 'deployed');
    }

    public function scopePending($query)
    {
        return $query->where('deployment_status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('deployment_status', 'failed');
    }

    // ---- Additional methods ----

    /**
     * Get the SSH command to connect to this server.
     */
    public function getSshCommand()
    {
        $sshUser = $this->ssh_user;
        $ip = $this->ip;
        $port = $this->ssh_port ?? 22;

        if ($this->ssh_type === 'key') {
            $keyPath = $this->ssh_key; // Path to private key
            return "ssh -i {$keyPath} -p {$port} {$sshUser}@{$ip}";
        } else {
            // Password auth (less secure, used for auto-deployment/testing)
            return "sshpass -p '{$this->ssh_password}' ssh -o StrictHostKeyChecking=no -p {$port} {$sshUser}@{$ip}";
        }
    }
}
