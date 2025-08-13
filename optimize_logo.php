<?php
/**
 * SVG Logo Optimization Script
 * Helps optimize the large logo.svg file for better web performance
 */

echo "=== SVG LOGO OPTIMIZATION ===\n";

$logoPath = 'public/images/logo.svg';
$backupPath = 'public/images/logo_backup.svg';

if (!file_exists($logoPath)) {
    echo "❌ Logo file not found at: $logoPath\n";
    exit(1);
}

$originalSize = filesize($logoPath);
echo "📁 Original file size: " . number_format($originalSize) . " bytes (" . round($originalSize / 1024 / 1024, 2) . " MB)\n";

// Create backup
if (!file_exists($backupPath)) {
    copy($logoPath, $backupPath);
    echo "✅ Backup created at: $backupPath\n";
}

// Read SVG content
$svgContent = file_get_contents($logoPath);

echo "\n=== OPTIMIZATION STEPS ===\n";

// Basic optimizations
$optimized = $svgContent;
$optimizations = [];

// Remove XML comments
$beforeComments = strlen($optimized);
$optimized = preg_replace('/<!--.*?-->/s', '', $optimized);
$afterComments = strlen($optimized);
if ($beforeComments > $afterComments) {
    $optimizations[] = "Removed XML comments: saved " . ($beforeComments - $afterComments) . " bytes";
}

// Remove excessive whitespace
$beforeWhitespace = strlen($optimized);
$optimized = preg_replace('/\s+/', ' ', $optimized);
$optimized = preg_replace('/>\s+</', '><', $optimized);
$afterWhitespace = strlen($optimized);
if ($beforeWhitespace > $afterWhitespace) {
    $optimizations[] = "Removed excess whitespace: saved " . ($beforeWhitespace - $afterWhitespace) . " bytes";
}

// Remove unnecessary attributes
$beforeAttrs = strlen($optimized);
$optimized = preg_replace('/\s+id="[^"]*"/', '', $optimized);
$optimized = preg_replace('/\s+class="[^"]*"/', '', $optimized);
$afterAttrs = strlen($optimized);
if ($beforeAttrs > $afterAttrs) {
    $optimizations[] = "Removed unnecessary attributes: saved " . ($beforeAttrs - $afterAttrs) . " bytes";
}

$newSize = strlen($optimized);
$savings = $originalSize - $newSize;

if ($savings > 0) {
    echo "🔧 Applied optimizations:\n";
    foreach ($optimizations as $opt) {
        echo "   - $opt\n";
    }

    echo "\n📊 Results:\n";
    echo "   Original: " . number_format($originalSize) . " bytes\n";
    echo "   Optimized: " . number_format($newSize) . " bytes\n";
    echo "   Saved: " . number_format($savings) . " bytes (" . round(($savings / $originalSize) * 100, 1) . "%)\n";

    // Write optimized version
    $optimizedPath = 'public/images/logo_optimized.svg';
    file_put_contents($optimizedPath, $optimized);
    echo "✅ Optimized version saved as: $optimizedPath\n";

    echo "\n=== NEXT STEPS ===\n";
    echo "1. Test the optimized logo in your browser\n";
    echo "2. If it looks good, replace the original:\n";
    echo "   copy public\\images\\logo_optimized.svg public\\images\\logo.svg\n";
    echo "3. Clear browser cache to see changes\n";

} else {
    echo "ℹ️  No basic optimizations could be applied.\n";
    echo "   The file may need manual optimization or conversion.\n";
}

echo "\n=== ADDITIONAL RECOMMENDATIONS ===\n";
echo "🎨 For further optimization:\n";
echo "   - Use online tools like SVGOMG (https://jakearchibald.github.io/svgomg/)\n";
echo "   - Consider converting complex graphics to simpler vector shapes\n";
echo "   - Remove embedded raster images if present\n";
echo "   - Simplify paths and reduce precision\n";

if ($originalSize > 100000) { // 100KB
    echo "\n⚠️  IMPORTANT: File is still very large for web use\n";
    echo "   Consider creating a simpler version of your logo\n";
    echo "   Ideal size for web logos: 10-50KB\n";
}

echo "\n=== OPTIMIZATION COMPLETE ===\n";
