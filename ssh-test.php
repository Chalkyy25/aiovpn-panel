<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

$process = new Process([
    'ssh',
    '-i', 'C:/Users/oem/.ssh/id_rsa',
    '-o', 'StrictHostKeyChecking=no',
    'root@85.9.205.205',
    'uptime'
]);

$process->run();

// Output
echo "✅ Output:\n" . $process->getOutput();
echo "\n⚠️ Error Output:\n" . $process->getErrorOutput();
echo "\n🚪 Exit Code: " . $process->getExitCode();

if (!$process->isSuccessful()) {
    echo "\n❌ Something went wrong running the SSH command.\n";
}
