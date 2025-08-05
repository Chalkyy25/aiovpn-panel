#!/bin/bash

# Clear Laravel cache, config, view, route, and event caches
php artisan optimize:clear

# (Optional, but helps with blade/view cache issues)
php artisan view:clear
php artisan config:clear

# Reload PHP-FPM (uncomment and edit your PHP version as needed)
sudo systemctl reload php8.2-fpm
# sudo systemctl reload php8.3-fpm

echo "✅ All Laravel caches cleared!"