<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable; // ✅ Needed for ::dispatch()
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AddWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser;
    }

    public function handle(): void
    {
        $this->autoFillDeviceName();
        Log::info("🚀 [WireGuard] Starting peer setup for user {$this->vpnUser->username}");

        foreach ($this->vpnUser->vpnServers as $server) {
            Log::info("🔧 [WireGuard] Processing server: {$server->name} ($server->ip_address)");

            if (!$this->generateKeysOnServer($server)) {
                continue;
            }

            [$privateKey, $publicKey] = $this->fetchKeysFromServer($server);

            if (empty($privateKey) || empty($publicKey)) {
                Log::error("❌ [WireGuard] Fetched keys are empty on {$server->name} for {$this->vpnUser->username}");
                continue;
            }

            $this->saveKeysToDb($privateKey, $publicKey);
            $this->addPeerToInterface($server, $publicKey);
            $this->appendPeerConfig($server, $publicKey);
            $this->enableWireGuardMasquerade($server);
            $this->cleanupTempKeys($server);
            $this->generateClientConfig($server, $privateKey);
        }

        Log::info("🎉 [WireGuard] Finished peer setup for user {$this->vpnUser->username}");
    }

    private function autoFillDeviceName(): void
    {
        if (empty($this->vpnUser->device_name)) {
            $this->vpnUser->device_name = 'Unknown Device';
            $this->vpnUser->save();
        }
    }

    private function generateKeysOnServer($server): bool
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $commands = "
            umask 077 &&
            sudo mkdir -p /etc/wireguard/clients &&
            cd /etc/wireguard/clients &&
            sudo wg genkey | sudo tee {$this->vpnUser->username}_private > /dev/null &&
            sudo cat {$this->vpnUser->username}_private | sudo wg pubkey | sudo tee {$this->vpnUser->username}_public > /dev/null
        ";

        $sshGenerateCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$commands\"";
        $output = [];
        $returnVar = 0;
        exec($sshGenerateCmd, $output, $returnVar);

        if ($returnVar !== 0) {
            Log::error("❌ [WireGuard] Key generation failed on {$server->name}. Output: " . implode("\n", $output));
            return false;
        }

        return true;
    }

    private function fetchKeysFromServer($server): array
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $fetchPrivate = sprintf(
            'ssh -i %s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s@%s "sudo cat /etc/wireguard/clients/%s_private"',
            $keyPath, $port, $sshUser, $ip, $this->vpnUser->username
        );

        $fetchPublic = sprintf(
            'ssh -i %s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s@%s "sudo cat /etc/wireguard/clients/%s_public"',
            $keyPath, $port, $sshUser, $ip, $this->vpnUser->username
        );

        $privateKey = trim(shell_exec($fetchPrivate));
        $publicKey = trim(shell_exec($fetchPublic));

        Log::info("🔎 [WireGuard] Fetched private: $privateKey");
        Log::info("🔎 [WireGuard] Fetched public: $publicKey");

        return [$privateKey, $publicKey];
    }

    private function saveKeysToDb(string $privateKey, string $publicKey): void
    {
        $this->vpnUser->wireguard_private_key = $privateKey;
        $this->vpnUser->wireguard_public_key = $publicKey;
        $this->vpnUser->save();

        Log::info("🔐 [WireGuard] Keys saved to DB for {$this->vpnUser->username}");
    }

    private function addPeerToInterface($server, string $publicKey): void
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $addPeerCmd = sprintf(
            'sudo wg set wg0 peer %s allowed-ips %s',
            escapeshellarg($publicKey),
            escapeshellarg($this->vpnUser->wireguard_address)
        );

        $sshCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$addPeerCmd\"";
        shell_exec($sshCmd);

        Log::info("✅ [WireGuard] Peer added on {$server->name} for {$this->vpnUser->username}");
    }

    private function appendPeerConfig($server, string $publicKey): void
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $peerBlock = <<<EOL

# {$this->vpnUser->username}
[Peer]
PublicKey = {$publicKey}
AllowedIPs = {$this->vpnUser->wireguard_address}/32
EOL;

        $appendCmd = "echo \"$peerBlock\" | sudo tee -a /etc/wireguard/wg0.conf";
        $sshCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$appendCmd\"";
        shell_exec($sshCmd);

        Log::info("💾 [WireGuard] Peer block added to wg0.conf on {$server->name}");
    }

    private function cleanupTempKeys($server): void
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $cmd = "sudo rm -f /etc/wireguard/clients/{$this->vpnUser->username}_private /etc/wireguard/clients/{$this->vpnUser->username}_public";
        $sshCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$cmd\"";
        shell_exec($sshCmd);

        Log::info("🧹 [WireGuard] Temp key cleanup done on {$server->name}");
    }

    private function generateClientConfig($server, string $privateKey): void
    {
        $serverPublicKey = $this->getServerPublicKey($server);
        $fileName = "{$this->vpnUser->username}_wg.conf";

        $config = <<<EOL
[Interface]
PrivateKey = {$privateKey}
Address = {$this->vpnUser->wireguard_address}
DNS = 1.1.1.1

[Peer]
PublicKey = {$serverPublicKey}
Endpoint = {$server->ip_address}:51820
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
EOL;

        Storage::disk('local')->put("configs/{$fileName}", $config);
        Log::info("📄 [WireGuard] Client config saved: configs/{$fileName}");
    }

    private function getServerPublicKey($server): string
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $cmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"[ -f /etc/wireguard/server_public_key ] && sudo cat /etc/wireguard/server_public_key || sudo wg show wg0 public-key\"";

        return trim(shell_exec($cmd));
    }

    private function enableWireGuardMasquerade($server): void
    {
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

        $iptablesCmd = "sudo iptables -t nat -C POSTROUTING -s 10.66.66.0/24 -o eth0 -j MASQUERADE || sudo iptables -t nat -A POSTROUTING -s 10.66.66.0/24 -o eth0 -j MASQUERADE";
        $sshCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$iptablesCmd\"";
        shell_exec($sshCmd);

        // Save iptables rules
        $saveCmd = "sudo netfilter-persistent save";
        $sshSaveCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$saveCmd\"";
        shell_exec($sshSaveCmd);

        Log::info("🔐 [WireGuard] NAT rule ensured and saved on {$server->name}");
    }
}
