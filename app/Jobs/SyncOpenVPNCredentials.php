<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOpenVPNCredentials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $vpnServer;

    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    public function handle(): void
    {
        $vpnServer = $this->vpnServer->fresh();

        if (!$vpnServer) {
             Log::error('SyncOpenVPNCredentials: $vpnServer is null. ID: ' . ($this->vpnServer->id ?? 'unknown'));
            return;
        }

        $users = $vpnServer->vpnUsers()->get();

        if (!$users) {
             Log::error('SyncOpenVPNCredentials: $users is null for vpnServer ID: ' . $vpnServer->id);
            $users = collect();
        }

        $lines = [];
        foreach ($users as $vpnUser) {
            $lines[] = "{$vpnUser->username} {$vpnUser->password}";
        }

        $fileContents = implode("\n", $lines) . "\n";
        $ip = $vpnServer->ip_address;
        $sshUser = 'root';
        $sshKey = storage_path('app/ssh_keys/id_rsa');

        $remoteFile = "/etc/openvpn/auth/psw-file";
        $tmpFile = storage_path("app/psw-file-{$vpnServer->id}.txt");
        file_put_contents($tmpFile, $fileContents);

        $cmd = "scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpFile} {$sshUser}@{$ip}:{$remoteFile}";
        exec($cmd, $output, $status);

        if ($status !== 0) {
            Log::error("Failed to sync VPN credentials for server {$ip}. Output: " . implode("\n", $output));
        } else {
            Log::info("✅ Synced " . count($lines) . " VPN credentials to {$ip}");
        }

        // Optionally, clean up temp file
        @unlink($tmpFile);
    }
}
