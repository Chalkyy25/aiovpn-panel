<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ServerMgmtEvent implements ShouldBroadcastNow
{
    public function __construct(
        public int    $serverId,
        public string $ts,
        public int    $clients,
        public string $cnList,
        public string $raw
    ) {}

    public function broadcastOn() { return new PrivateChannel("servers.{$this->serverId}"); }
    public function broadcastAs(): string { return 'mgmt.update'; }

    public function broadcastWith(): array {
        return [
            'server_id' => $this->serverId,
            'ts'        => $this->ts,
            'clients'   => $this->clients,
            'cn_list'   => $this->cnList,
            'raw'       => $this->raw,
        ];
    }
}
