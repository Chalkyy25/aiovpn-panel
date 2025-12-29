<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MgmtSnapshotStore
{
    private function key(int $serverId): string
    {
        return "mgmt:snapshot:{$serverId}";
    }

    public function put(int $serverId, string $tsIso, array $users, int $ttlSeconds = 600): void
    {
        Cache::store('redis')->put($this->key($serverId), [
            'ts'    => $tsIso,
            'users' => $users,
        ], $ttlSeconds);
    }

    public function get(int $serverId): array
    {
        return Cache::store('redis')->get($this->key($serverId), [
            'ts'    => null,
            'users' => [],
        ]);
    }
}