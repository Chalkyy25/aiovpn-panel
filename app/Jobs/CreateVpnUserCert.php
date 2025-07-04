<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CreateVpnUserCert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
    }

    public function handle(): void
    {
        $sshUser = 'root';
        $sshKey = storage_path('app/ssh_keys/id_rsa');

        foreach ($this->vpnUser->vpnServers as $server) {
            $ip = $server->ip_address;

            Log::info("🔧 Creating client cert for {$this->vpnUser->username} on {$server->name}");

            $output = [];
            exec("ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'cd /etc/openvpn/easy-rsa && ./easyrsa build-client-full {$this->vpnUser->username} nopass'", $output, $status);

            if ($status === 0) {
                Log::info("✅ Client cert created for {$this->vpnUser->username} on {$server->name}");
            } else {
                Log::error("❌ Failed to create client cert for {$this->vpnUser->username} on {$server->name}. Status: {$status}");
            }
        }
    }
}
