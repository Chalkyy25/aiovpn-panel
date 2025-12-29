<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMgmtEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public int $serverId;
    public string $ts;

    /** event-level protocol: OPENVPN | WIREGUARD | null */
    public ?string $protocol = null;

    /** rich rows */
    public array $users = [];

    public int $clients = 0;
    public string $cnList = '';
    public string $raw = '';

    /** wg-agent | ovpn-mgmt | sync-job | disconnect | unknown */
    public string $source = 'unknown';

    public function __construct(
        int $serverId,
        string $ts,
        array $users = [],
        ?string $cnList = null,
        ?string $raw = null,
        ?string $source = null,
        ?string $protocol = null,   // ✅ add
    ) {
        $this->serverId = $serverId;
        $this->ts       = $ts;

        $this->protocol = $protocol ? strtoupper($protocol) : null; // ✅ add

        // Normalize users + force protocol casing if present
        $this->users = array_values(array_map(function ($u) {
            if (!is_array($u)) return [];
            if (isset($u['protocol']) && is_string($u['protocol'])) {
                $u['protocol'] = strtoupper($u['protocol']);
            }
            return $u;
        }, $users));

        $this->clients = count($this->users);

        $this->cnList = $cnList ?? implode(',', array_values(array_filter(array_map(
            fn ($u) => is_array($u) ? ($u['username'] ?? null) : null,
            $this->users
        ))));

        $this->raw    = (string) ($raw ?? '');
        $this->source = $source ? (string) $source : 'unknown';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("servers.{$this->serverId}"),
            new PrivateChannel('servers.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mgmt.update';
    }

    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'ts'        => $this->ts,
            'protocol'  => $this->protocol, // ✅ add
            'clients'   => $this->clients,
            'cn_list'   => $this->cnList,
            'users'     => $this->users,
            'raw'       => $this->raw,
            'source'    => $this->source,
        ];
    }
}