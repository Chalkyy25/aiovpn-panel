# AIO VPN Configuration Files

## 📱 Firestick/Android TV Configs
**Location:** `/firestick-stealth-configs/`

- **`Germany-TCP-Stealth.ovpn`** ✅ **ACTIVE** - Currently has testuser + PStammers connected
- **`Spain-TCP-Stealth.ovpn`** ✅ **READY** - TCP port 443 stealth mode
- **`UK-TCP-Stealth.ovpn`** ✅ **READY** - TCP port 443 stealth mode

**Features:**
- TCP port 443 (bypasses most firewalls)
- ARM-optimized for Firestick performance
- Unbound DNS (10.66.66.1)
- Server-specific tls-crypt keys
- Modern cipher suites (AES-256-GCM)

## 📲 AIO Smarters Mobile App Configs
**Location:** `/public/configs/` (Web accessible)

- Complete set of stealth configs for mobile app distribution
- All using TCP port 443 for maximum compatibility
- Server-specific certificates extracted via SSH
- Ready for direct download by users

## 🔧 Usage Instructions

### For Firestick/Android TV:
1. Use configs from `/firestick-stealth-configs/`
2. **Germany config is currently active** with live connections
3. Install via file manager or USB transfer

### For AIO Smarters App:
1. Users can download from: `https://your-domain/configs/`
2. Import directly into AIO Smarters mobile app
3. Automatic server selection available

## ⚠️ Important Notes

- **Germany server**: Active with 2 users (testuser, PStammers)
- **Database monitoring**: Now tracks both UDP (7505) and TCP (7506) ports
- **Traffic stats**: 195.95MB/70.65MB upload traffic visible
- **Firewall bypass**: All configs use TCP 443 for maximum compatibility

## 🎯 Monitoring

- **Live API**: `/public/api/live-vpn-status.php`
- **Dashboard monitoring**: Database persistence working
- **Manual update**: `php artisan vpn:update-connections`

All configs are production-ready and tested! 🚀