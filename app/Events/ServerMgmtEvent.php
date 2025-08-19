<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ServerMgmtEvent implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $serverId,
        public string $ts,
        public int $clients,
        public string $cnList,   // comma-separated names
        public string $raw       // raw mgmt line (optional)
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("servers.{$this->serverId}");
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
            'clients'   => $this->clients,
            'cn_list'   => $this->cnList,
            // omit raw in production if you want less noise
            'raw'       => $this->raw,
        ];
    }

    // Optional: drop no‑change updates (quiet logs)
    public function broadcastWhen(): bool
    {
        // If you cache the last snapshot per server, compare here.
        // Return true to broadcast; false to skip.
        return true;
    }
}