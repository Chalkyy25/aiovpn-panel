#!/bin/bash
set -euo pipefail

# Redirect logs to a file and console
exec > >(tee -i /root/vpn-deploy.log)
exec 2>&1

echo -e "\n=======================================\n STARTING DEPLOYMENT: $(date)\n======================================="

# Handle any error during script execution
trap 'ERROR_CODE=$?; echo -e "\n❌ Deployment failed with code: $ERROR_CODE\n\nEXIT_CODE:$ERROR_CODE"; exit $ERROR_CODE' ERR

# Global Configuration
DEBUG=false
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 12)}"

# Debug mode: Enable extended logging
[ "$DEBUG" = true ] && set -x

# ────────────────────────────────────────────────────────────
# System checks: Ensure the script has required permissions
# ────────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
  echo "❌ Please run as root or with sudo."
  exit 1
fi

# TUN device availability
if ! [ -c /dev/net/tun ]; then
  echo "❌ TUN device unavailable; ensure the server supports VPN."
  exit 1
fi

# Check essential commands
REQUIRED_COMMANDS=(apt-get systemctl iptables ip fuser curl)
for cmd in "${REQUIRED_COMMANDS[@]}"; do
  if ! command -v "$cmd" &>/dev/null; then
    echo "❌ Required command '$cmd' is not installed. Exiting."
    exit 1
  fi
done

# ────────────────────────────────────────────────────────────
# 01: Pre-execution cleanup
# ────────────────────────────────────────────────────────────
function pre_cleanup() {
  echo "[1] Cleaning existing stale processes and locks…"
  killall openvpn || true
  killall debconf-communicate || true
  rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
  dpkg --configure -a
  echo "[1] Cleanup complete."
}

# ────────────────────────────────────────────────────────────
# 02: OS & Package management
# ────────────────────────────────────────────────────────────
function update_system() {
  echo "[2] Updating repositories and upgrading packages…"
  apt-get update -y && apt-get upgrade -y
  echo "[2] System update & upgrade completed."
}

function install_dependencies() {
  echo "[3] Installing VPN dependencies: OpenVPN, Easy-RSA, WireGuard…"
  DEBIAN_FRONTEND=noninteractive apt-get install -y openvpn easy-rsa wireguard iptables-persistent
  echo "[3] Dependencies installed successfully."
}

# ────────────────────────────────────────────────────────────
# 03: OpenVPN Configuration
# ────────────────────────────────────────────────────────────
function configure_openvpn() {
  echo "[4] Configuring OpenVPN server…"

  # Generate CA and server certificates
  mkdir -p /etc/openvpn/easy-rsa/keys
  cp -a /usr/share/easy-rsa/* /etc/openvpn/easy-rsa
  cd /etc/openvpn/easy-rsa
  ./easyrsa init-pki
  EASYRSA_BATCH=1 ./easyrsa build-ca nopass
  EASYRSA_BATCH=1 ./easyrsa gen-req server nopass
  EASYRSA_BATCH=1 ./easyrsa sign-req server server
  ./easyrsa gen-dh
  openvpn --genkey --secret /etc/openvpn/ta.key

  # Copy server configuration files
  cp pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem /etc/openvpn/
  echo "[4] OpenVPN certificates generated successfully."
}

function write_server_configuration() {
  echo "[5] Writing OpenVPN server configuration…"
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
  echo "[5] Server configuration written successfully."
}

function create_auth_script() {
  echo "[5.1] Creating authentication script…"

  # Create auth directory if it doesn't exist
  mkdir -p /etc/openvpn/auth
  chmod 700 /etc/openvpn/auth

  # Create initial password file with default credentials if not exists
  if [ ! -f /etc/openvpn/auth/psw-file ]; then
    echo "$VPN_USER $VPN_PASS" > /etc/openvpn/auth/psw-file
    chmod 600 /etc/openvpn/auth/psw-file
    echo "✅ Created initial password file with default credentials"
  fi

  # Create the authentication script
  cat <<'EOF' > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
###########################################################
# checkpsw.sh (C) 2004 Mathias Sundman <mathias@openvpn.se>
#
# This script will authenticate OpenVPN users against
# a plain text file. The passfile should simply contain
# one row per user with the username first followed by
# a space and then the password.

PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/var/log/openvpn-password.log"
TIME_STAMP=`date "+%Y-%m-%d %T"`

###########################################################

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

  # Set proper permissions for the authentication script
  chmod 755 /etc/openvpn/auth/checkpsw.sh
  echo "[5.1] Authentication script created successfully."
}

function enable_openvpn() {
  echo "[6] Restarting OpenVPN service…"
  systemctl enable openvpn@server
  systemctl restart openvpn@server
  if systemctl is-active --quiet openvpn@server; then
    echo "✅ OpenVPN service is active."
  else
    echo "❌ Cannot start OpenVPN service. Exiting."
    exit 1
  fi
}

# ────────────────────────────────────────────────────────────
# 04: WireGuard Configuration
# ────────────────────────────────────────────────────────────
function configure_wireguard() {
  echo "[7] Setting up WireGuard VPN server…"

  mkdir -p /etc/wireguard
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
  PRIVATE_KEY=$(cat /etc/wireguard/server_private_key)
  PUBLIC_IP=$(curl -s https://api.ipify.org)

  # Detect the primary network interface
  DEFAULT_IFACE=$(ip -4 route show default | grep -Po '(?<=dev )(\S+)' | head -1)
  if [ -z "$DEFAULT_IFACE" ]; then
    DEFAULT_IFACE="eth0"
    echo "⚠️ Could not detect primary network interface, using default: $DEFAULT_IFACE"
  else
    echo "✅ Detected primary network interface: $DEFAULT_IFACE"
  fi

  cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
PrivateKey = $PRIVATE_KEY
Address = 10.66.66.1/24
ListenPort = 51820
SaveConfig = true
EOF

  # Apply IP forwarding and NAT
  sysctl -w net.ipv4.ip_forward=1
  iptables -t nat -A POSTROUTING -o $DEFAULT_IFACE -j MASQUERADE

  systemctl enable wg-quick@wg0
  systemctl start wg-quick@wg0
  echo "[7] WireGuard configuration completed successfully."
}

# ────────────────────────────────────────────────────────────
# Main script flow execution
# ────────────────────────────────────────────────────────────
echo "🚀 Starting VPN deployment sequence…"
pre_cleanup
update_system
install_dependencies
configure_openvpn
write_server_configuration
create_auth_script
enable_openvpn
configure_wireguard

echo -e "\n✅ Deployment finished successfully on $(date)"
exit 0
