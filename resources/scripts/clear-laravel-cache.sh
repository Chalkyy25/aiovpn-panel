#!/bin/bash

# Clear Laravel cache, config, view, route, and event caches
php artisan optimize:clear

# (Optional, but helps with blade/view cache issues)
php artisan view:clear
php artisan config:clear

# Reload PHP-FPM (uncomment and edit your PHP version as needed)
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx

echo "✅ All Laravel caches cleared!"