<?php
require_once __DIR__ . '/vendor/autoload.php';

// Simple test to verify our VpnConfigBuilder changes
use App\Services\VpnConfigBuilder;

// Test the buildUnifiedConfig method signature
$reflection = new ReflectionClass(VpnConfigBuilder::class);
$method = $reflection->getMethod('buildUnifiedConfig');
$parameters = $method->getParameters();

echo "VpnConfigBuilder::buildUnifiedConfig parameters:\n";
foreach ($parameters as $param) {
    echo "- " . $param->getName() . " (" . $param->getType() . ")\n";
}

echo "\nConfig should now include:\n";
echo "- cipher AES-128-GCM\n";
echo "- <auth-user-pass> block with embedded credentials\n";
echo "- pull-filter ignore \"cipher\"\n";
echo "- pull-filter ignore \"auth\"\n";
echo "- No comp-lzo\n";