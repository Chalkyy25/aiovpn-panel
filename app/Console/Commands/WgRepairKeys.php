<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VpnUser;
use App\Jobs\AddWireGuardPeer;

class WgRepairKeys extends Command
{
    protected $signature = 'wg:repair-keys {--push : Re-sync peers after fixing}';
    protected $description = 'Fix WireGuard public keys by deriving from stored private keys and optionally re-sync peers';

    public function handle(): int
    {
        $fixed = 0;

        VpnUser::query()
            ->whereNotNull('wireguard_private_key')
            ->chunkById(200, function ($users) use (&$fixed) {
                foreach ($users as $user) {
                    $derived = VpnUser::wgPublicFromPrivate($user->wireguard_private_key);

                    if (!$derived) {
                        $this->warn("⚠️ Skipped {$user->username}: could not derive");
                        continue;
                    }

                    if ($user->wireguard_public_key !== $derived) {
                        $this->info("🔧 Fixing {$user->username}");
                        $this->line("    Old: {$user->wireguard_public_key}");
                        $this->line("    New: {$derived}");

                        $user->wireguard_public_key = $derived;
                        $user->saveQuietly();

                        $fixed++;

                        if ($this->option('push')) {
                            AddWireGuardPeer::dispatch($user);
                        }
                    }
                }
            });

        $this->info("✅ Done. Fixed {$fixed} user(s).");
        return self::SUCCESS;
    }
}