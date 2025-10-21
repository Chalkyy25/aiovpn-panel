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

    protected $description = 'Generate WireGuard keypairs + allocate addresses for users missing them';

    public function handle(): int
    {
        $dry    = (bool) $this->option('dry');
        $sync   = (bool) $this->option('sync');
        $subnet = (string) $this->option('subnet');

        // pick users
        $userOpt = $this->option('user');
        $q = VpnUser::query();
        if ($userOpt) {
            if (is_numeric($userOpt)) $q->where('id', $userOpt);
            else                      $q->where('username', $userOpt);
        }
        $users = $q->get();

        if ($users->isEmpty()) {
            $this->warn('No matching users.');
            return self::SUCCESS;
        }

        // cache used IPs once; store bare IP (no /32) in DB
        [$net, $maskBits] = $this->parseCidr($subnet); // e.g. [ "10.66.66.0", 24 ]
        $used = $this->collectUsedIps();

        $touched = [];
        $this->info("🔎 Ensuring WG materials for {$users->count()} user(s) in {$subnet}");

        DB::beginTransaction();
        try {
            foreach ($users as $u) {
                $needKeypair = blank($u->wireguard_public_key) || blank($u->wireguard_private_key);
                $needAddr    = blank($u->wireguard_address);

                if (!$needKeypair && !$needAddr) {
                    $this->line("⏭️  {$u->username}: OK (already has keys + address)");
                    continue;
                }

                $changed = false;

                if ($needKeypair) {
                    [$priv, $pub] = $this->generateKeypair();
                    $u->wireguard_private_key = $priv;
                    $u->wireguard_public_key  = $pub;
                    $changed = true;
                    $this->line("🔑 {$u->username}: generated WG keypair");
                }

                if ($needAddr) {
                    $next = $this->allocateNextIp($net, $maskBits, $used);
                    if (!$next) {
                        throw new \RuntimeException("No free IPs available in {$subnet}");
                    }
                    $u->wireguard_address = $next; // store as bare IP; your job adds /32
                    $used[$next] = true;
                    $changed = true;
                    $this->line("📬 {$u->username}: assigned {$next}");
                }

                if ($changed) {
                    if ($dry) {
                        $this->line("🧪 (dry) would save {$u->username}");
                    } else {
                        // make sure password exists so other flows don’t explode
                        if (blank($u->password)) {
                            // auto-generate a login password only if missing
                            $plain = bin2hex(random_bytes(6));
                            $u->plain_password = $plain; // mutator also sets hashed password
                        }
                        $u->save();
                        $touched[] = $u->username;
                    }
                }
            }

            $dry ? DB::rollBack() : DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Failed: '.$e->getMessage());
            Log::error('[wg:ensure-keys] error', ['error'=>$e->getMessage()]);
            return self::FAILURE;
        }

        $this->info('✅ Done. Updated: '.count($touched).'.');
        if (!empty($touched) && $sync && !$dry) {
            // only sync peers for users we touched
            $list = implode(',', $touched);
            $this->info("🚀 Syncing peers for updated users: {$list}");
            // call your existing command per user (avoids huge batches)
            foreach ($touched as $username) {
                // php artisan wg:sync-peers --user=<name>
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
        return [$ip, (int)$bits];
    }

    private function collectUsedIps(): array
    {
        // normalize: strip /xx if present
        $all = VpnUser::query()
            ->whereNotNull('wireguard_address')
            ->pluck('wireguard_address')
            ->map(fn($a) => preg_replace('/\/\d+$/', '', trim((string)$a)))
            ->filter()
            ->values();

        $map = [];
        foreach ($all as $ip) { $map[$ip] = true; }
        return $map;
    }

    private function allocateNextIp(string $network, int $maskBits, array &$used): ?string
    {
        // only supports /24 pools (like 10.66.66.0/24) which is your current setup
        if ($maskBits !== 24) {
            throw new \InvalidArgumentException('Allocator currently supports /24 only');
        }
        $parts = explode('.', $network);
        if (count($parts) !== 4) return null;

        // reserve .0 (network) and .255 (broadcast) and .1 (server)
        for ($host = 2; $host <= 254; $host++) {
            $ip = "{$parts[0]}.{$parts[1]}.{$parts[2]}.{$host}";
            if (!isset($used[$ip])) {
                return $ip;
            }
        }
        return null;
    }

    /**
     * Generate real WireGuard-compatible Curve25519 keys if possible.
     * Order:
     *   1) libsodium (sodium_crypto_scalarmult_base)
     *   2) system wg tools (wg genkey | wg pubkey)
     *   3) fallback (random) – last resort
     *
     * @return array [privateBase64, publicBase64]
     */
    private function generateKeypair(): array
    {
        // 1) libsodium (preferred)
        if (function_exists('sodium_crypto_scalarmult_base')) {
            $sk = random_bytes(32);
            // clamp for X25519
            $sk = $this->clamp25519($sk);
            $pk = sodium_crypto_scalarmult_base($sk);
            return [base64_encode($sk), base64_encode($pk)];
        }

        // 2) system wg tools
        try {
            $priv = trim(@shell_exec('wg genkey 2>/dev/null'));
            if ($priv) {
                $pub = trim($this->pipeTo('wg pubkey', $priv."\n"));
                if ($pub) return [$priv, $pub];
            }
        } catch (\Throwable) {
            // ignore
        }

        // 3) fallback (compatible length, but not true X25519 pub derivation)
        $sk = random_bytes(32);
        $pk = hash('sha256', $sk, true);
        return [base64_encode($sk), base64_encode($pk)];
    }

    private function clamp25519(string $sk): string
    {
        $bytes = str_split($sk);
        $bytes[0]  = chr(ord($bytes[0]) & 248);
        $bytes[31] = chr((ord($bytes[31]) & 127) | 64);
        return implode('', $bytes);
    }

    private function pipeTo(string $cmd, string $input): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) return '';
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        proc_close($proc);
        return trim($out ?: $err);
    }
}