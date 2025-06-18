# AIOVPN Panel Deployment Notes

This repo is auto-deployed to the live production server whenever a push is made to the `main` branch.

---

## 🚀 Live Deployment

- **Trigger**: GitHub Actions watches the `main` branch
- **Runs on**: Every commit push to `main`
- **Workflow**: `.github/workflows/deploy.yml`
- **Server Path**: `/var/www/aiovpn`

### What the workflow does:
1. SSH into the server (`root@${{ secrets.SERVER_HOST }}`)
2. Pulls latest changes from `main`
3. Runs:
   - `php artisan migrate --force`
   - `php artisan config:cache`
   - `php artisan route:cache`

---

## 🔐 Deployment Secrets

Set in GitHub under **Settings > Secrets and variables > Actions**:

- `SERVER_HOST` → your server IP
- `SSH_PRIVATE_KEY` → private key with access to `/var/www/aiovpn` as root

---

## 🛠 Tips

- Update the server manually:  
  ```bash
  cd /var/www/aiovpn
  git pull origin main
  php artisan migrate --force
  php artisan config:cache
  php artisan route:cache
