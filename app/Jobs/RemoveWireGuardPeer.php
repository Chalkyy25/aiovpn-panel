<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Traits\ExecutesRemoteCommands;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RemoveWireGuardPeer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected VpnUser $vpnUser;
    protected ?VpnServer $server = null;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(VpnUser $vpnUser, ?VpnServer $server = null)
    {
        $this->vpnUser = $vpnUser;
        $this->server = $server;

        $this->onConnection('redis');
        $this->onQueue('wg');
    }

    public function handle(): void
    {
        $pub = trim((string) $this->vpnUser->wireguard_public_key);

        if ($pub === '') {
            Log::channel('vpn')->warning("WG REMOVE SKIP user={$this->vpnUser->username} reason=no_public_key");
            return;
        }

        $servers = $this->server
            ? collect([$this->server])
            : $this->vpnUser->vpnServers()->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning("WG REMOVE SKIP user={$this->vpnUser->username} reason=no_servers");
            return;
        }

        foreach ($servers as $server) {
            $this->removeFromServer($server, $pub);
        }
    }

    protected function removeFromServer(VpnServer $server, string $publicKey): void
    {
        Log::channel('vpn')->info("WG REMOVE TRY user={$this->vpnUser->username} server={$server->name}");

        $script = $this->buildRemoveScript($publicKey);

        $res = $this->executeRemoteCommand($server, $script);

        if (($res['status'] ?? 1) !== 0) {
            $out = trim(implode("\n", (array) ($res['output'] ?? [])));
            Log::channel('vpn')->error("WG REMOVE FAIL user={$this->vpnUser->username} server={$server->name} exit=" . ($res['status'] ?? 'unknown'));
            if ($out !== '') {
                Log::channel('vpn')->error("WG REMOVE OUTPUT {$server->name}: {$out}");
            }
            return;
        }

        $out = trim(implode("\n", (array) ($res['output'] ?? [])));
        Log::channel('vpn')->info("WG REMOVE SUCCESS user={$this->vpnUser->username} server={$server->name}" . ($out !== '' ? " {$out}" : ""));
    }

    protected function buildRemoveScript(string $publicKey): string
    {
        $PUB = escapeshellarg(trim($publicKey));

        return <<<BASH
set -euo pipefail
IFACE="wg0"
PUB={$PUB}

if ! command -v wg >/dev/null 2>&1; then
  echo "NO_WG"
  exit 2
fi

if ! wg show "\$IFACE" >/dev/null 2>&1; then
  echo "NO_IFACE"
  exit 3
fi

EXISTS=0
if wg show "\$IFACE" peers | grep -Fxq "\$PUB"; then
  EXISTS=1
fi

if [ "\$EXISTS" -eq 0 ]; then
  echo "NOT_FOUND"
  exit 0
fi

wg set "\$IFACE" peer "\$PUB" remove
wg-quick save "\$IFACE"

if wg show "\$IFACE" peers | grep -Fxq "\$PUB"; then
  echo "STILL_PRESENT"
  exit 4
fi

echo "REMOVED"
BASH;
    }
}