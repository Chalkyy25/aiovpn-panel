<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AddWireGuardPeer implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser;
    }

    public function handle(): void
    {
        Log::info("🚀 [WireGuard] Starting peer setup for user {$this->vpnUser->username}");

        foreach ($this->vpnUser->vpnServers as $server) {
            Log::info("🔧 [WireGuard] Processing server: {$server->name} ({$server->ip_address})");

            $ip = $server->ip_address;
            $port = $server->ssh_port ?? 22;
            $sshUser = $server->ssh_user;
            $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';

            // 🗝️ Generate keys on VPN server
            $commands = "
                umask 077 &&
                sudo mkdir -p /etc/wireguard/clients &&
                cd /etc/wireguard/clients &&
                sudo wg genkey | tee {$this->vpnUser->username}_private | sudo wg pubkey > {$this->vpnUser->username}_public
            ";

            $sshGenerateCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$commands\"";
            shell_exec($sshGenerateCmd);

            // 🔎 Fetch keys back to panel
            $fetchPrivate = sprintf(
                'ssh -i %s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s@%s "sudo cat /etc/wireguard/clients/%s_private"',
                $keyPath,
                $port,
                $sshUser,
                $ip,
                $this->vpnUser->username
            );

            $fetchPublic = sprintf(
                'ssh -i %s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s@%s "sudo cat /etc/wireguard/clients/%s_public"',
                $keyPath,
                $port,
                $sshUser,
                $ip,
                $this->vpnUser->username
            );

            $privateKey = trim(shell_exec($fetchPrivate));
            $publicKey = trim(shell_exec($fetchPublic));

            Log::info("🔎 [WireGuard] Private key fetched: $privateKey");
            Log::info("🔎 [WireGuard] Public key fetched: $publicKey");

            if (empty($privateKey) || empty($publicKey)) {
                Log::error("❌ [WireGuard] Failed to fetch keys on {$server->name} for {$this->vpnUser->username}");
                continue;
            }

            // 📝 Save keys to DB
            $this->vpnUser->wireguard_private_key = $privateKey;
            $this->vpnUser->wireguard_public_key = $publicKey;
            $this->vpnUser->save();

            Log::info("🔑 [WireGuard] Keys saved for {$this->vpnUser->username}");

	// ➕ Add peer live to WireGuard interface
	$addPeerCmd = sprintf(
	    "sudo wg set wg0 peer %s allowed-ips %s",
	    escapeshellarg($publicKey),
	    escapeshellarg($this->vpnUser->wireguard_address)
	);

	$sshAddPeerCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$addPeerCmd\"";
	shell_exec($sshAddPeerCmd);

	// ➕ Append peer config for persistence
	$appendPeerConfig = <<<EOL

	# {$this->vpnUser->username}
	[Peer]
	PublicKey = {$publicKey}
	AllowedIPs = {$this->vpnUser->wireguard_address}

	EOL;

	$escapedConfig = escapeshellarg($appendPeerConfig);

	$appendCmd = "echo $escapedConfig | sudo tee -a /etc/wireguard/wg0.conf";

	$sshAppendCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$appendCmd\"";
	shell_exec($sshAppendCmd);

	Log::info("💾 [WireGuard] Peer config appended to wg0.conf on {$server->name} for {$this->vpnUser->username}");

            $sshAddPeerCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"$addPeerCmd\"";
            shell_exec($sshAddPeerCmd);

            Log::info("✅ [WireGuard] Peer added on {$server->name} for {$this->vpnUser->username}");

            // 🧹 Clean up temp key files on server
            $cleanupCmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"sudo rm -f /etc/wireguard/clients/{$this->vpnUser->username}_private /etc/wireguard/clients/{$this->vpnUser->username}_public\"";
            shell_exec($cleanupCmd);

            Log::info("🧹 [WireGuard] Cleaned up temp keys on {$server->name}");

            // 📄 Generate client config file for download
            $serverPublicKey = $this->getServerPublicKey($server);
            Log::info("🔑 [WireGuard] Server public key for {$server->name}: {$serverPublicKey}");

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

            $fileName = "{$this->vpnUser->username}_wg.conf";
            Storage::disk('local')->put("configs/{$fileName}", $config);

            Log::info("📄 [WireGuard] Client config generated for {$this->vpnUser->username}");
        }

        Log::info("🎉 [WireGuard] Finished peer setup for user {$this->vpnUser->username}");
    }

    /**
     * Helper to fetch server public key.
     */
    private function getServerPublicKey($server): string
    {
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';
        $ip = $server->ip_address;
        $port = $server->ssh_port ?? 22;
        $sshUser = $server->ssh_user;

        $cmd = "ssh -i $keyPath -p $port -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null $sshUser@$ip \"sudo cat /etc/wireguard/server_public_key\"";
        return trim(shell_exec($cmd));
    }
}
