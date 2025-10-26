# VPN Configuration Files for IPTV Smarters

## ✅ Production Ready - All Servers Configured!

### Firestick/Android TV Optimized Configs
All configs extracted with **server-specific certificates** and optimized for IPTV streaming:

1. **`Germany-TCP-Stealth.ovpn`** ✅ **READY**
   - Location: Frankfurt, Germany
   - Server: 5.22.212.177:443
   - Status: Production-ready with correct keys

2. **`Spain-TCP-Stealth.ovpn`** ✅ **READY**
   - Location: Madrid, Spain
   - Server: 5.22.218.134:443
   - Status: Production-ready with correct keys

3. **`UK-TCP-Stealth.ovpn`** ✅ **READY**
   - Location: London, UK
   - Server: 83.136.254.231:443
   - Status: Production-ready with correct keys

## 📂 Files Location
- **Source Files**: `firestick-stealth-configs/` (version control)
- **Upload to WHMCS/Smarters**: `public/configs/` (ready to distribute)

## 🎯 Optimizations for Firestick/Android TV

Each config includes:
- ✅ **TCP 443 stealth mode** - Bypasses ISP throttling and blocks
- ✅ **ARM processor optimized** - Efficient for Firestick/Fire TV hardware
- ✅ **Streaming buffer sizes** - 393KB send/receive buffers for smooth playback
- ✅ **Fast reconnection** - 2-5 second retry intervals
- ✅ **DNS leak protection** - Forces Unbound DNS (10.66.66.1)
- ✅ **Modern ciphers** - AES-128-GCM, ChaCha20-Poly1305 (hardware accelerated)
- ✅ **Low latency** - TCP_NODELAY, fast-io enabled
- ✅ **MTU optimized** - 1450 MSS fix prevents fragmentation

## 📤 How to Upload to WHMCS/Smarters

### For WHMCS Integration:
1. Navigate to your WHMCS admin panel
2. Go to VPN product configuration
3. Upload the `.ovpn` files from `public/configs/`:
   - `Germany-TCP-Stealth.ovpn`
   - `Spain-TCP-Stealth.ovpn`
   - `UK-TCP-Stealth.ovpn`
4. Set each config as downloadable for customers

### For IPTV Smarters App:
Users can import configs directly:
1. Transfer .ovpn file to device (USB, cloud, or download)
2. Open "OpenVPN for Android" app
3. Import profile → Select .ovpn file
4. Enter VPN username/password
5. Connect and enjoy streaming!

## 🔑 Authentication
All configs use `auth-user-pass` - users will need:
- **Username**: Their VPN account username
- **Password**: Their VPN account password

Credentials are managed through your Laravel VPN panel.

## ✅ Verified Working
- ✅ Germany server actively serving connections (testuser, PStammers confirmed)
- ✅ All servers have unique CA certificates and tls-crypt keys
- ✅ TCP 443 stealth mode bypasses common VPN blocks
- ✅ Optimized for IPTV streaming on ARM devices

## 📋 Technical Details
- Protocol: TCP on port 443 (stealth mode)
- Encryption: tls-crypt with AES-128-GCM
- DNS: Private Unbound resolver (10.66.66.1)
- Optimized for: Firestick, Fire TV, Android TV, Android phones/tablets
