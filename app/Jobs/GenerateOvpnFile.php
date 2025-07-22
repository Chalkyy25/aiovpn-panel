<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateOvpnFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
    }

    public function handle(): void
    {
        Log::info("🚀 [OVPN] Starting minimal config generation for user {$this->vpnUser->username}");

        foreach ($this->vpnUser->vpnServers as $server) {
            $ip = $server->ip_address;
            $username = $this->vpnUser->username;

            Log::info("🔧 [OVPN] Processing server: {$server->name} ({$ip})");

            // Required remote files
            $files = [
                'ca' => '/etc/openvpn/ca.crt',
                'ta' => '/etc/openvpn/ta.key',
            ];

            $fetched = [];
            foreach ($files as $label => $path) {
                $content = $this->fetchRemoteFile($ip, $path, strtoupper($label));
                if (!$content) {
                    Log::error("❌ [OVPN] Missing {$label} for {$username} on {$ip}, skipping server.");
                    continue 2;
                }
                $fetched[$label] = $content;
            }

            // Build the stripped-down OpenVPN config
            $config = <<<EOL
client
dev tun
proto udp
remote {$ip} 1194
resolv-retry infinite
nobind
persist-key
persist-tun
explicit-exit-notify
remote-cert-tls server
auth SHA256
cipher AES-256-CBC
tls-version-min 1.2
reneg-sec 0
auth-user-pass
verb 3

<ca>
{$fetched['ca']}
</ca>

<tls-auth>
{$fetched['ta']}
</tls-auth>
key-direction 1
EOL;

            // Save final .ovpn file
            $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
            $fileName = "public/ovpn_configs/{$safeServerName}_{$username}.ovpn";

            Storage::put($fileName, $config);
            Storage::setVisibility($fileName, 'public');

            Log::info("✅ [OVPN] Generated stripped config for {$username} on {$server->name}: storage/app/{$fileName}");
        }

        Log::info("🎉 [OVPN] Finished generating stripped configs for {$this->vpnUser->username}");
    }

    private function runSshCommand(string $ip, string $command): array
    {
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $sshUser = 'root';
        $fullCommand = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} '{$command}'";
        exec($fullCommand, $output, $status);
        return [$status, $output];
    }

    private function fetchRemoteFile(string $ip, string $remotePath, string $label): ?string
    {
        [$status, $output] = $this->runSshCommand($ip, "cat {$remotePath}");
        if ($status !== 0 || empty($output)) {
            Log::error("❌ [OVPN] Failed to fetch {$label} from {$ip} (status {$status})");
            return null;
        }
        return implode("\n", $output);
    }
}