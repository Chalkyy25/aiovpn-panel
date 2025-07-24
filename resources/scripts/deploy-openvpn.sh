#!/bin/bash
set -euo pipefail

# Redirect all output to a log file
exec > >(tee -i /root/vpn-deploy.log)
exec 2>&1

echo -e "\n=======================================\n SCRIPT RUN START: $(date)\n======================================="

trap 'CODE=$?; echo -e "\n=======================================\n❌ Deployment failed with code: $CODE\nEXIT_CODE:$CODE\n======================================="; exit $CODE' ERR

DEBUG=false
[ "$DEBUG" = true ] && set -x

# Ensure running as root
if [ "$EUID" -ne 0 ]; then
  echo "❌ Please run as root or with sudo."
  exit 1
fi

# Check for TUN device
if ! [ -c /dev/net/tun ]; then
  echo "❌ /dev/net/tun is missing — TUN not available."
  exit 1
fi

# Check required commands
for cmd in apt-get systemctl iptables ip fuser curl tee; do
  if ! command -v $cmd &>/dev/null; then
    echo "❌ Required command '$cmd' not found. Aborting."
    exit 1
  fi
done

function pre_cleanup() {
  echo -e "\n[PRE] Killing stale processes and cleaning up…"
  killall openvpn || true
  killall debconf-communicate || true
  rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
  dpkg --configure -a
  local MAX_WAIT=120
  local WAITED=0
  while fuser /var/cache/debconf/*.dat >/dev/null 2>&1; do
    if [ $WAITED -ge $MAX_WAIT ]; then
      echo "❌ Timed out waiting for debconf locks to clear."
      exit 1
    fi
    echo "⏳ Waiting for debconf locks to clear..."
    sleep 3
    WAITED=$((WAITED+3))
  done
  echo "[PRE] Cleanup complete."
}

function wait_for_apt() {
  echo -e "\n[APT] Checking apt locks…"
  local MAX_WAIT=120
  local WAITED=0
  while fuser /var/lib/dpkg/lock >/dev/null 2>&1 || \
        fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || \
        fuser /var/cache/apt/archives/lock >/dev/null 2>&1; do
    if [ $WAITED -ge $MAX_WAIT ]; then
      echo "❌ Timed out waiting for apt locks to clear."
      exit 1
    fi
    echo "[APT] Another process is holding apt lock. Waiting 3s..."
    sleep 3
    WAITED=$((WAITED+3))
  done
}

function update_and_upgrade() {
  echo -e "\n[1/11] Updating and upgrading system…"
  wait_for_apt
  apt-get update -y
  wait_for_apt
  apt-get upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
  wait_for_apt
  echo "[1/11] Update and upgrade complete."
}

function install_packages() {
  echo -e "\n[2/11] Installing required packages…"
  debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v4 boolean true"
  debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v6 boolean true"
  wait_for_apt
  apt-get install -y software-properties-common
  wait_for_apt
  if ! apt-cache show wireguard >/dev/null 2>&1; then
    echo "[2/11] Adding WireGuard PPA…"
    add-apt-repository ppa:wireguard/wireguard -y
    wait_for_apt
  fi
  apt-get update
  wait_for_apt
  apt-get install -y openvpn easy-rsa wireguard wireguard-tools vnstat iptables-persistent curl wget lsb-release ca-certificates \
    -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
  wait_for_apt
  if command -v wg >/dev/null 2>&1; then
    echo "✅ WireGuard version: $(wg --version)"
  else
    echo "✅ WireGuard version: wg not found"
  fi
  [ ! -f /etc/iptables/rules.v4 ] && (iptables-save | tee /etc/iptables/rules.v4)
  echo "[2/11] Package installation complete."
}

function clean_openvpn_setup() {
  echo -e "\n[3/11] Cleaning existing OpenVPN setup…"
  systemctl stop openvpn@server || true
  killall openvpn || true
  mkdir -p /etc/openvpn/auth
  mv /etc/openvpn/auth/psw-file /tmp/psw-file.bak || true
  mv /etc/openvpn/auth/checkpsw.sh /tmp/checkpsw.sh.bak || true
  [ -d /etc/openvpn/pki ] && mv /etc/openvpn/pki /tmp/pki.bak || true
  rm -rf /etc/openvpn/*
  mkdir -p /etc/openvpn/auth
  mv /tmp/psw-file.bak /etc/openvpn/auth/psw-file || true
  mv /tmp/checkpsw.sh.bak /etc/openvpn/auth/checkpsw.sh || true
  mv /tmp/pki.bak /etc/openvpn/pki || true
  : > /etc/openvpn/ipp.txt
  echo "[3/11] Cleanup complete."
}

# Sets up Easy-RSA PKI for OpenVPN, initializes the PKI, generates CA and server certificates,
# copies required files to /etc/openvpn, and ensures all necessary keys are present.
function setup_easy_rsa() {
  echo -e "\n[4/11] Setting up Easy-RSA PKI…"
  local easyrsa_dir=/etc/openvpn/easy-rsa
  cp -a /usr/share/easy-rsa "$easyrsa_dir" 2>/dev/null || true
  cd "$easyrsa_dir"
  ./easyrsa init-pki
  EASYRSA_BATCH=1 EASYRSA_REQ_CN="OpenVPN-CA" ./easyrsa build-ca nopass
  ./easyrsa gen-dh

  # Generate ta.key directly in /etc/openvpn
  openvpn --genkey --secret /etc/openvpn/ta.key

  EASYRSA_BATCH=1 EASYRSA_REQ_CN="server" ./easyrsa gen-req server nopass
  echo yes | ./easyrsa sign-req server server

  # Only copy certs/keys, not ta.key (already in place)
  cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem /etc/openvpn/
  echo "[4/11] Easy-RSA setup complete."
}

function create_auth_files() {
  echo -e "\n[6/11] Creating authentication files…"
  if [ ! -f /etc/openvpn/auth/psw-file ]; then
    VPN_USER="${VPN_USER:-}"
    VPN_PASS="${VPN_PASS:-}"
    if [ -z "$VPN_USER" ] || [ -z "$VPN_PASS" ]; then
      echo "❌ VPN_USER and VPN_PASS environment variables must be set for non-interactive deployment."
      exit 1
    fi
    echo "$VPN_USER $VPN_PASS" > /etc/openvpn/auth/psw-file
    chmod 600 /etc/openvpn/auth/psw-file
    chown root:root /etc/openvpn/auth/psw-file
  fi

  tee /etc/openvpn/auth/checkpsw.sh > /dev/null <<'EOF'
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/etc/openvpn/auth/auth.log"
if [ ! -r "$PASSFILE" ]; then
  echo "$(date): ❌ psw-file not readable" >> "$LOG_FILE"
  exit 1
fi
CORRECT_PASSWORD=$(awk -v user="$1" -F' ' '$1 == user {print $2; exit}' "$PASSFILE")
if [ "$CORRECT_PASSWORD" = "$2" ]; then
  echo "$(date): ✅ Auth OK: $1" >> "$LOG_FILE"
  exit 0
else
  echo "$(date): ❌ Auth FAIL: $1" >> "$LOG_FILE"
  exit 1
fi
EOF

  chmod 755 /etc/openvpn/auth/checkpsw.sh
  chown -R root:root /etc/openvpn/auth
  touch /etc/openvpn/auth/auth.log
  chmod 640 /etc/openvpn/auth/auth.log
  echo "[6/11] Authentication files created."
}

function write_server_conf() {
  echo -e "\n[8/11] Writing improved server.conf…"
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
ifconfig-pool-persist /etc/openvpn/ipp.txt
keepalive 10 120
cipher AES-256-GCM
ncp-ciphers AES-256-GCM:AES-128-GCM
persist-key
persist-tun
sndbuf 0
rcvbuf 0
push "sndbuf 393216"
push "rcvbuf 393216"
status /etc/openvpn/openvpn-status.log
verb 3
mute-replay-warnings
explicit-exit-notify 1
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
script-security 3
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 8.8.8.8"
push "dhcp-option DNS 1.1.1.1"
# Compression is disabled for compatibility; uncomment the next line to enable lz4 compression if all clients support it.
#push "compress lz4"
EOF
  echo "[8/11] Improved server.conf written."
}

function enable_ip_forwarding() {
  echo -e "\n[9/11] Enabling IP forwarding…"
  sysctl -w net.ipv4.ip_forward=1
  if grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf; then
    :
  elif grep -q '^#net.ipv4.ip_forward=1' /etc/sysctl.conf; then
    sed -i 's/^#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf
  else
    echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  fi
  sysctl -p
  echo "[9/11] IP forwarding enabled."
}

function setup_nat() {
  echo -e "\n[10/11] Setting up NAT with iptables…"
  local PUB_IF
  PUB_IF=$(ip route show default | awk '/default/ {print $NF; exit}')

  # Clean up existing rules
  iptables -t nat -D POSTROUTING -s 10.8.0.0/24 -o $PUB_IF -j MASQUERADE 2>/dev/null || true
  iptables -t nat -D POSTROUTING -o $PUB_IF -j MASQUERADE 2>/dev/null || true

  # Add fresh rule
  iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o $PUB_IF -j MASQUERADE

  iptables-save > /etc/iptables/rules.v4
  echo "[10/11] NAT setup complete."
}


function setup_firewall() {
  echo -e "\n[FW] Setting up firewall rules…"
  iptables -I INPUT -p udp --dport 1194 -j ACCEPT
}

function restart_openvpn() {
  echo -e "\n[11/11] Enabling and restarting OpenVPN service…"
  systemctl enable openvpn@server
  systemctl restart openvpn@server
  sleep 2
  if ! systemctl is-active --quiet openvpn@server; then
    echo "❌ OpenVPN failed to restart. Dumping last 20 logs:"
    journalctl -u openvpn@server --no-pager | tail -n 20
    exit 1
  fi
  echo "✅ OpenVPN service is active and running."
  echo "[11/11] OpenVPN service restarted."
}

function setup_wireguard() {
  echo -e "\n[WG] Setting up WireGuard…"
  mkdir -p /etc/wireguard
  if [ ! -f /etc/wireguard/server_private_key ]; then
    umask 077
    wg genkey > /etc/wireguard/server_private_key
    wg pubkey < /etc/wireguard/server_private_key > /etc/wireguard/server_public_key
    echo "[WG] WireGuard server keys generated."
  else
    echo "[WG] Server keys already exist, skipping generation."
  fi

  local PRIVATE_KEY
  PRIVATE_KEY=$(cat /etc/wireguard/server_private_key)
  local PUBLIC_IP
  if [ -f /tmp/public_ip.cache ]; then
    PUBLIC_IP=$(cat /tmp/public_ip.cache)
  else
    PUBLIC_IP=$(curl -s https://api.ipify.org)
    echo "$PUBLIC_IP" > /tmp/public_ip.cache
  fi

  local WG_PORT=51820
  cat <<EOF > /etc/wireguard/wg0.conf
[Interface]
PrivateKey = $PRIVATE_KEY
Address = 10.66.66.1/24
ListenPort = $WG_PORT
SaveConfig = true

# Example client config (uncomment per client)
#[Peer]
#PublicKey = CLIENT_PUBLIC_KEY
EOF

  sysctl -w net.ipv4.ip_forward=1
  sysctl -w net.ipv6.conf.all.forwarding=1

  local PUB_IF
  PUB_IF=$(ip route | grep default | awk '{print $5}')

  if grep -q '^#net.ipv4.ip_forward=1' /etc/sysctl.conf; then
    sed -i 's/^#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf
  elif ! grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf; then
    echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  fi

  iptables -A FORWARD -i wg0 -j ACCEPT
  iptables -A FORWARD -o wg0 -j ACCEPT
  iptables -t nat -A POSTROUTING -o $PUB_IF -j MASQUERADE
  iptables-save > /etc/iptables/rules.v4

  systemctl enable wg-quick@wg0
  systemctl start wg-quick@wg0
  echo "[WG] WireGuard setup complete."
}

function deploy_ssh_key() {
  echo -e "\n[FINAL] Deploying panel SSH public key (optional)…"
  if [ -f /tmp/id_rsa.pub ]; then
    mkdir -p /root/.ssh
    cat /tmp/id_rsa.pub >> /root/.ssh/authorized_keys
    rm /tmp/id_rsa.pub
    echo "✅ Added panel SSH public key to authorized_keys"
  fi
  echo "[FINAL] SSH key deployment complete."
}

function check_openvpn_status() {
  echo -e "\n[FINAL] Checking OpenVPN service status…"
  if systemctl is-active --quiet openvpn@server; then
    echo "✅ OpenVPN service is running."
  else
    echo "❌ OpenVPN service failed to start."
    journalctl -u openvpn@server
    exit 1
  fi
  echo "[FINAL] OpenVPN status check complete."
}

function check_wireguard_status() {
  echo -e "\n[FINAL] Checking WireGuard service status…"
  if systemctl is-active --quiet wg-quick@wg0; then
    echo "✅ WireGuard service is running."
  else
    echo "❌ WireGuard service failed to start."
    journalctl -u wg-quick@wg0
    exit 1
  fi
  echo "[FINAL] WireGuard status check complete."
}

function deployment_summary() {
  echo -e "\n DEPLOYMENT SUMMARY"
  echo "OpenVPN service: $(systemctl is-active openvpn@server)"
  echo "WireGuard service: $(systemctl is-active wg-quick@wg0)"
  echo "IP forwarding: $(sysctl net.ipv4.ip_forward | awk '{print $3}')"
  echo "NAT rules:"
  iptables -t nat -L POSTROUTING -n -v
}

function cleanup_temp_files() {
  echo -e "\n[CLEANUP] Removing temporary files…"
  rm -f /tmp/psw-file.bak /tmp/checkpsw.sh.bak
  rm -rf /tmp/pki.bak
  rm -f /tmp/public_ip.cache /tmp/id_rsa.pub
  echo "[CLEANUP] Temp files cleaned."
}

# ───────── Main Script ───────── #

pre_cleanup
update_and_upgrade
install_packages
clean_openvpn_setup
setup_easy_rsa
create_auth_files
write_server_conf
enable_ip_forwarding
setup_nat
setup_firewall
restart_openvpn
setup_wireguard
check_openvpn_status
check_wireguard_status
deploy_ssh_key
deployment_summary
cleanup_temp_files

echo -e "\n✅ Deployment finished successfully.\n=== DEPLOYMENT END $(date) ==="
exit 0
