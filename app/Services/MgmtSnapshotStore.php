<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class MgmtSnapshotStore
{
    private function key(int $serverId): string
    {
        return "mgmt:snapshot:{$serverId}";
    }

    public function put(int $serverId, string $tsIso, array $users, int $ttlSeconds = 600): void
    {
        $users = $this->normalizeUsers($users);

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

    private function normalizeUsers(array $users): array
    {
        $out = [];

        foreach ($users as $u) {
            if (!is_array($u)) continue;

            // normalize protocol
            $proto = strtoupper((string)($u['protocol'] ?? 'OPENVPN'));
            $u['protocol'] = $proto;

            // normalize online flag
            $u['is_active'] = (bool) ($u['is_active'] ?? true);
            
            // add connected_human if missing
            if (empty($u['connected_human'])) {
                $u['connected_human'] = !empty($u['connected_at'])
                    ? Carbon::parse($u['connected_at'])->diffForHumans()
                    : '—';
            }

            $out[] = $u;
        }

        return $out;
    }
}