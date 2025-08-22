<?php
/**
 * Script to test logo display issues
 * This will help identify the problems with the logo.svg file
 */

echo "=== LOGO ANALYSIS ===\n";

$logoPath = 'public/images/logo.svg';

if (!file_exists($logoPath)) {
    echo "❌ Logo file not found at: $logoPath\n";
    exit(1);
}

$fileSize = filesize($logoPath);
echo "📁 Logo file size: " . number_format($fileSize) . " bytes (" . round($fileSize / 1024 / 1024, 2) . " MB)\n";

if ($fileSize > 500000) { // 500KB
    echo "⚠️  WARNING: Logo file is very large (over 500KB)\n";
    echo "   Large SVG files can cause:\n";
    echo "   - Slow page loading\n";
    echo "   - Browser rendering issues\n";
    echo "   - Memory consumption problems\n";
}

// Read first few characters to check SVG structure
$handle = fopen($logoPath, 'r');
$firstChars = fread($handle, 200);
fclose($handle);

echo "\n📋 SVG file starts with:\n";
echo substr($firstChars, 0, 100) . "...\n";

// Check if it's a valid SVG
if (strpos($firstChars, '<svg') === false) {
    echo "❌ File doesn't appear to be a valid SVG\n";
} else {
    echo "✅ File appears to be a valid SVG\n";
}

echo "\n=== CSS ANALYSIS ===\n";

$cssPath = 'resources/css/xui.css';
if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);

    // Find xui-logo styles
    if (preg_match('/\.xui-logo\s*{([^}]+)}/s', $cssContent, $matches)) {
        echo "🎨 Current .xui-logo CSS:\n";
        echo ".xui-logo {\n" . trim($matches[1]) . "\n}\n";

        if (strpos($matches[1], 'height: 2.5rem') !== false) {
            echo "⚠️  Fixed height (2.5rem) may cause aspect ratio issues with custom logos\n";
        }
    }
} else {
    echo "❌ CSS file not found at: $cssPath\n";
}

echo "\n=== RECOMMENDATIONS ===\n";
echo "1. 🔧 Optimize the SVG file (reduce size)\n";
echo "2. 🎨 Update CSS to handle custom logos better\n";
echo "3. 📱 Ensure responsive behavior\n";
echo "4. 🖼️  Consider using object-fit: contain for better scaling\n";

echo "\n=== ANALYSIS COMPLETE ===\n";
