<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMgmtEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $serverId;
    public string $ts;
    public int $clients;
    public string $cnList;
    public string $raw;

    public function __construct(int $serverId, string $ts, int $clients, string $cnList, string $raw)
    {
        $this->serverId = $serverId;
        $this->ts = $ts;
        $this->clients = $clients;
        $this->cnList = $cnList;
        $this->raw = $raw;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("servers.$this->serverId");
    }

    public function broadcastAs(): string
    {
        return 'mgmt.update';
    }

    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'ts' => $this->ts,
            'clients' => $this->clients,
            'cn_list' => $this->cnList,
            'raw' => $this->raw,
        ];
    }
}
