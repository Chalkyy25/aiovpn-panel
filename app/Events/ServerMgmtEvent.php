<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMgmtEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $serverId;
    public string $timestamp;
    public int $status;
    public string $connectionName;
    public string $trigger;

    public function __construct(int $serverId, string $timestamp, int $status, string $connectionName, string $trigger)
    {
        $this->serverId = $serverId;
        $this->timestamp = $timestamp;
        $this->status = $status;
        $this->connectionName = $connectionName;
        $this->trigger = $trigger;
    }

    public function broadcastOn(): Channel
    {
        // frontend listens to channel `servers.{id}`
        return new Channel("servers.{$this->serverId}");
    }

    public function broadcastAs(): string
    {
        return 'mgmt.update';
    }
}