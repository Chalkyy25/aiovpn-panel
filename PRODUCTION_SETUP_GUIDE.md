# VPN Dashboard - Production Setup Guide

## The Issue
You were trying to run monitoring from your **local Windows VS Code**, but:
- Local `.env` points to localhost (127.0.0.1)
- Local database is empty/different from production
- Reverb is running on the server, not locally

## Solution: Run Everything on the Server

### 1️⃣ On Your Server (via Termius/SSH)

```bash
# Connect to your server
ssh root@panel.aiovpn.co.uk

# Navigate to project
cd /var/www/aiovpn

# Check if monitoring is working
php artisan vpn:update-status -vvv

# Check logs
tail -f storage/logs/vpn.log
```

### 2️⃣ Set Up Supervisor (Auto-run services)

Create supervisor config:

```bash
sudo nano /etc/supervisor/conf.d/aiovpn.conf
```

Add this configuration:

```ini
[program:aiovpn-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/aiovpn/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/aiovpn/storage/logs/queue-worker.log
stopwaitsecs=3600

[program:aiovpn-reverb]
command=/usr/bin/php /var/www/aiovpn/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/aiovpn/storage/logs/reverb.log

[program:aiovpn-scheduler]
command=/bin/bash -c "while true; do php /var/www/aiovpn/artisan schedule:run --verbose --no-interaction; sleep 60; done"
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/aiovpn/storage/logs/scheduler.log
```

Then:

```bash
# Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all

# Check status
sudo supervisorctl status
```

### 3️⃣ Or Add to Crontab (Alternative to scheduler supervisor)

```bash
crontab -e
```

Add:
```
* * * * * cd /var/www/aiovpn && php artisan schedule:run >> /dev/null 2>&1
```

### 4️⃣ Verify It's Working

```bash
# Check if services are running
sudo supervisorctl status

# Check queue is processing
php artisan queue:work --once

# Check monitoring logs
tail -f storage/logs/vpn.log

# Check if dashboard shows data
curl https://panel.aiovpn.co.uk
```

## What About Local Development?

For **local development** on Windows, you have 2 options:

### Option A: Connect to Production (Read-Only)
Update your local `.env`:

```env
# Point to production server
DB_HOST=panel.aiovpn.co.uk  # or server IP
DB_PORT=3306
DB_DATABASE=aiovpn
DB_USERNAME=aiovpn_readonly  # create read-only user for safety
DB_PASSWORD=xxx

# Connect to production Reverb
REVERB_HOST=reverb.aiovpn.co.uk
REVERB_PORT=443
REVERB_SCHEME=https
```

⚠️ **Warning**: This connects to LIVE data. Be careful!

### Option B: Full Local Stack (Recommended for dev)
Run everything locally:

```powershell
# Start local Reverb
php artisan reverb:start

# Start queue worker
php artisan queue:work

# Start scheduler (in PowerShell)
while ($true) { php artisan schedule:run; Start-Sleep 60 }
```

But you'd need local VPN servers or mock data.

## Production Checklist

- [ ] Supervisor running queue workers
- [ ] Supervisor running Reverb
- [ ] Cron/supervisor running scheduler every minute
- [ ] Nginx configured for reverb.aiovpn.co.uk
- [ ] SSL certificate for reverb.aiovpn.co.uk
- [ ] Firewall allows port 8080 (or whatever Reverb uses)
- [ ] Laravel cache cleared: `php artisan config:cache`

## Testing Production

```bash
# SSH to server
ssh root@panel.aiovpn.co.uk

# Test monitoring
php artisan vpn:update-status

# Should see:
# Germany Frankfurt: mgmt responded on port 7506 (X clients)
```

## Current Status

✅ **Server (.env)**: Correctly configured with reverb.aiovpn.co.uk  
❌ **Local (.env)**: Points to localhost (won't work for production monitoring)  
✅ **Code fixes**: Applied to VpnDashboard.php and UpdateVpnStatus.php  

**Next Step**: Set up Supervisor on your server to auto-run everything!
