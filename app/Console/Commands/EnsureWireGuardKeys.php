<?php

namespace App\Console\Commands;

use App\Models\VpnUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnsureWireGuardKeys extends Command
{
    protected $signature = 'wg:ensure-keys
                            {--user= : Username or user ID to limit}
                            {--subnet=10.66.66.0/24 : WG subnet to allocate from}
                            {--dry : Show what would change, don\'t write}
                            {--sync : After writing, run wg:sync-peers for affected users}';

    protected $description = 'Ensure all targeted users have WireGuard keypairs and /32 addresses';

    public function handle(): int
    {
        $dry    = (bool) $this->option('dry');
        $sync   = (bool) $this->option('sync');
        $subnet = (string) $this->option('subnet');

        $userOpt = $this->option('user');

        $q = VpnUser::query();
        if ($userOpt) {
            if (is_numeric($userOpt)) {
                $q->where('id', $userOpt);
            } else {
                $q->where('username', $userOpt);
            }
        }

        $users = $q->get();
        if ($users->isEmpty()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        [$netLong, $maskBits] = $this->parseCidr($subnet);
        $used = $this->collectUsedIps(); // map of long(ip) => true

        $touched = [];
        $this->info("Ensuring WG keys+IP for {$users->count()} user(s) in {$subnet}");

        DB::beginTransaction();
        try {
            foreach ($users as $u) {
                $needKeypair = blank($u->wireguard_public_key) || blank($u->wireguard_private_key);
                $needAddr    = blank($u->wireguard_address);

                if (! $needKeypair && ! $needAddr) {
                    $this->line("OK  {$u->username}: already has keys + address ({$u->wireguard_address})");
                    continue;
                }

                $changed = false;

                if ($needKeypair) {
                    $keys = VpnUser::generateWireGuardKeys();
                    $u->wireguard_private_key = $keys['private'];
                    $u->wireguard_public_key  = $keys['public'];
                    $this->line("KEY {$u->username}: generated WG keypair");
                    $changed = true;
                }

                if ($needAddr) {
                    $next = $this->allocateNextIp($netLong, $maskBits, $used);
                    if (! $next) {
                        throw new \RuntimeException("No free IPs available in {$subnet}");
                    }
                    $u->wireguard_address = $next;
                    $used[$this->ipLongFromCidr($next)] = true;
                    $this->line("IP  {$u->username}: assigned {$next}");
                    $changed = true;
                }

                if ($changed) {
                    if ($dry) {
                        $this->line("DRY {$u->username}: would save changes");
                    } else {
                        if (blank($u->password)) {
                            $plain = bin2hex(random_bytes(6));
                            $u->plain_password = $plain; // mutator sets hashed password
                        }
                        $u->save();
                        $touched[] = $u->username;
                    }
                }
            }

            if ($dry) {
                DB::rollBack();
            } else {
                DB::commit();
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed: '.$e->getMessage());
            Log::error('[wg:ensure-keys] error', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->info('Done. Updated: '.count($touched));

        if (!empty($touched) && $sync && !$dry) {
            $this->info('Syncing peers for updated users: '.implode(',', $touched));
            foreach ($touched as $username) {
                \Artisan::call('wg:sync-peers', ['--user' => $username]);
                $this->line(trim(\Artisan::output()));
            }
        }

        return self::SUCCESS;
    }

    /* ---------- helpers ---------- */

    private function parseCidr(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new \InvalidArgumentException("Bad subnet: {$cidr}");
        }
        [$ip, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $long = ip2long($ip);
        if ($long === false || $bits < 0 || $bits > 32) {
            throw new \InvalidArgumentException("Bad subnet: {$cidr}");
        }

        return [$long, $bits];
    }

    private function collectUsedIps(): array
    {
        $all = VpnUser::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(function ($addr) {
                $addr = trim((string) $addr);
                if ($addr === '') return null;
                $ip = strtok($addr, '/');
                $long = ip2long($ip);
                return $long === false ? null : $long;
            })
            ->filter()
            ->values();

        $map = [];
        foreach ($all as $long) {
            $map[$long] = true;
        }
        return $map;
    }

    private function ipLongFromCidr(string $cidr): ?int
    {
        $ip = strtok($cidr, '/');
        $long = ip2long($ip);
        return $long === false ? null : $long;
    }

    private function allocateNextIp(int $netLong, int $maskBits, array &$used): ?string
    {
        // same semantics as CreateVpnUser: /32s in pool, reserve .0, .1, .broadcast
        $hostBits = 32 - $maskBits;
        if ($hostBits <= 0) {
            throw new \InvalidArgumentException("Subnet too small: /{$maskBits}");
        }

        $start = $netLong + 2; // skip network + .1 (server)
        $end   = $netLong + ((1 << $hostBits) - 2); // skip broadcast

        for ($ip = $start; $ip <= $end; $ip++) {
            if (!isset($used[$ip])) {
                return long2ip($ip).'/32';
            }
        }

        return null;
    }
}