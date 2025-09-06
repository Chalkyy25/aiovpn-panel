<?php

// database/seeders/DeployKeySeeder.php
namespace Database\Seeders;

use App\Models\DeployKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class DeployKeySeeder extends Seeder
{
    public function run(): void
    {
        $dir = storage_path('app/ssh_keys');
        if (!File::isDirectory($dir)) File::makeDirectory($dir, 0700, true);
        $priv = $dir.'/id_ed25519';
        $pub  = $priv.'.pub';

        // Generate if missing
        if (!is_file($priv) || !is_file($pub)) {
            // Safe perms
            @chmod($dir, 0700);
            // Generate keypair (no passphrase)
            $cmd = sprintf('ssh-keygen -t ed25519 -N "" -f %s -C %s',
                escapeshellarg($priv),
                escapeshellarg('panel@'.gethostname())
            );
            exec($cmd, $o, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException("Failed to generate deploy keypair");
            }
        }

        @chmod($priv, 0600);
        $publicKey = trim(file_get_contents($pub));

        DeployKey::updateOrCreate(
            ['name' => 'default-ed25519'],
            [
                'private_path' => 'id_ed25519',  // filename only; kept on disk
                'public_key'   => $publicKey,    // stored in DB for easy distribution
                'is_active'    => true,
            ]
        );
    }
}
