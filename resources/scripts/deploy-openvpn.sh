#!/bin/bash
set -euo pipefail

# Redirect logs to a file and console
exec > >(tee -i /root/vpn-deploy.log)
exec 2>&1

echo -e "\n=======================================\nSTARTING DEPLOYMENT: $(date)\n======================================="

trap 'ERROR_CODE=$?; echo -e "\n❌ Deployment failed with code: $ERROR_CODE\nEXIT_CODE:$ERROR_CODE\n"; exit $ERROR_CODE' ERR

DEBUG=false
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 12)}"
[ "$DEBUG" = true ] && set -x

# ─────────────────────────────────────────────
# [STEP 1] SYSTEM CHECKS
# ─────────────────────────────────────────────
echo ""
echo "=== [1/7] SYSTEM CHECKS ==="
if [ "$EUID" -ne 0 ]; then
  echo "❌ Please run as root or with sudo."
  exit 1
fi

if ! [ -c /dev/net/tun ]; then
  echo "❌ TUN device unavailable; ensure the server supports VPN."
  exit 1
fi

REQUIRED_COMMANDS=(apt-get systemctl iptables ip fuser curl)
for cmd in "${REQUIRED_COMMANDS[@]}"; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "❌ Required command '$cmd' is not installed. Exiting."
    exit 1
  fi
done

echo ""

# ─────────────────────────────────────────────
# [STEP 2] PRE-CLEANUP
# ─────────────────────────────────────────────
echo "=== [2/7] PRE-CLEANUP ==="
killall openvpn || true
killall debconf-communicate || true
rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
dpkg --configure -a
echo "[2] Cleanup complete."
echo ""

# ─────────────────────────────────────────────
# [STEP 3] SYSTEM UPDATE & DEPENDENCIES
# ─────────────────────────────────────────────
echo "=== [3/7] SYSTEM UPDATE & DEPENDENCIES ==="
apt-get update -y && apt-get upgrade -y
DEBIAN_FRONTEND=noninteractive apt-get install -y openvpn easy-rsa wireguard iptables-persistent
echo "[3] System updated and dependencies installed."
echo ""

# ─────────────────────────────────────────────
# [STEP 4] OPENVPN CONFIGURATION
# ─────────────────────────────────────────────
echo "=== [4/7] OPENVPN CONFIGURATION ==="

mkdir -p /etc/openvpn/easy-rsa/keys
cp -a /usr/share/easy-rsa/* /etc/openvpn/easy-rsa 2>/dev/null || true
cd /etc/openvpn/easy-rsa

export EASYRSA_BATCH=1

if [ ! -d "pki" ]; then
  echo "[4] No PKI found. Generating OpenVPN server PKI/certs…"
  ./easyrsa init-pki
  ./easyrsa build-ca nopass
  ./easyrsa gen-req server nopass
  ./easyrsa sign-req server server
  ./easyrsa gen-dh
  openvpn --genkey --secret /etc/openvpn/ta.key
  cp pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem /etc/openvpn/
  echo "[4] OpenVPN certificates generated."
else
  echo "[4] PKI exists. Skipping cert generation."
fi

cat <<EOF > /etc/openvpn/server.conf
port 1194
proto udp
dev tun
ca ca.crt
cert server.crt
key server.key
dh dh.pem
auth SHA256
tls-auth ta.key 0
topology subnet
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist ipp.txt
keepalive 10 120
persist-key
persist-tun
status /var/log/openvpn-status.log
verb 3
explicit-exit-notify 1
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
script-security 3

username-as-common-name
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 8.8.8.8"
push "dhcp-option DNS 1.1.1.1"
EOF

echo ""

# ─────────────────────────────────────────────
# [STEP 5] AUTH SCRIPTS
# ─────────────────────────────────────────────
echo "=== [5/7] OPENVPN AUTH ==="

mkdir -p /etc/openvpn/auth
chmod 700 /etc/openvpn/auth

if [ ! -f /etc/openvpn/auth/psw-file ]; then
  echo "$VPN_USER $VPN_PASS" > /etc/openvpn/auth/psw-file
  chmod 600 /etc/openvpn/auth/psw-file
  echo "✅ Created initial password file with default credentials"
fi

cat <<'EOF' > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/var/log/openvpn-password.log"
TIME_STAMP=$(date "+%Y-%m-%d %T")
if [ ! -r "${PASSFILE}" ]; then
  echo "${TIME_STAMP}: Could not open password file \"${PASSFILE}\" for reading." >> ${LOG_FILE}
  exit 1
fi
CREDENTIALS=$(cat $1 | tr -d '\r')
USERNAME=$(echo $CREDENTIALS | cut -d' ' -f1)
PASSWORD=$(echo $CREDENTIALS | cut -d' ' -f2-)
if [ -z "$USERNAME" ] || [ -z "$PASSWORD" ]; then
  echo "${TIME_STAMP}: Empty username or password from $USERNAME." >> ${LOG_FILE}
  exit 1
fi
CORRECT_PASSWORD=$(grep -E "^${USERNAME}[ \t]+" ${PASSFILE} | cut -d' ' -f2-)
if [ "$PASSWORD" = "$CORRECT_PASSWORD" ]; then
  echo "${TIME_STAMP}: Successful authentication: $USERNAME." >> ${LOG_FILE}
  exit 0
fi
echo "${TIME_STAMP}: Authentication failed: $USERNAME." >> ${LOG_FILE}
exit 1
EOF

chmod 755 /etc/openvpn/auth/checkpsw.sh
echo "[5] Authentication script created."
echo ""

# ─────────────────────────────────────────────
# [STEP 6] OPENVPN SERVICE ENABLE/RESTART
# ─────────────────────────────────────────────
echo "=== [6/7] OPENVPN SERVICE ==="
systemctl enable openvpn@server
systemctl restart openvpn@server
if systemctl is-active --quiet openvpn@server; then
  echo "✅ OpenVPN service is active."
else
  echo "❌ Cannot start OpenVPN service. Exiting."
  exit 1
fi

echo ""

# ─────────────────────────────────────────────
# [STEP 7] WIREGUARD CONFIGURATION
# ─────────────────────────────────────────────
echo "=== [7/7] WIREGUARD CONFIGURATION ==="

mkdir -p /etc/wireguard

if [ ! -f /etc/wireguard/server_private_key ]; then
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
  echo "✅ WireGuard keys generated."
else
  echo "✅ WireGuard keys already exist. Skipping generation."
fi

PRIVATE_KEY=$(cat /etc/wireguard/server_private_key)
DEFAULT_IFACE=$(ip -4 route show default | grep -Po '(?<=dev )(\S+)' | head -1)
DEFAULT_IFACE="${DEFAULT_IFACE:-eth0}"

cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
PrivateKey = $PRIVATE_KEY
Address = 10.66.66.1/24
ListenPort = 51820
SaveConfig = true
EOF

sysctl -w net.ipv4.ip_forward=1

iptables -t nat -C POSTROUTING -o "$DEFAULT_IFACE" -j MASQUERADE 2>/dev/null || \
iptables -t nat -A POSTROUTING -o "$DEFAULT_IFACE" -j MASQUERADE

systemctl enable wg-quick@wg0
systemctl restart wg-quick@wg0
echo "[7] WireGuard configuration completed."
echo ""

# ─────────────────────────────────────────────
# FINAL SUMMARY
# ─────────────────────────────────────────────
echo ""
echo "======== DEPLOYMENT SUMMARY ========"
echo "OpenVPN service: $(systemctl is-active openvpn@server)"
echo "WireGuard service: $(systemctl is-active wg-quick@wg0)"
echo "Default interface: $DEFAULT_IFACE"
echo "IP forwarding: $(sysctl -n net.ipv4.ip_forward)"
echo "NAT rules:"
iptables -t nat -S POSTROUTING | grep MASQUERADE || echo "No NAT MASQUERADE rules found."
echo ""
echo "✅ Deployment finished successfully on $(date)"
echo ""
exit 0