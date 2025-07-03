#!/bin/bash

# Redirect all output to a log file
exec > >(tee -i /var/log/openvpn-deploy.log)
exec 2>&1

echo "======================================="
echo " SCRIPT RUN START: $(date)"
echo "======================================="
set -e
trap 'CODE=$?; echo "======================================="; echo "❌ Deployment failed with code: $CODE"; echo "EXIT_CODE:$CODE"; echo "======================================="; exit $CODE' ERR

set -x  # Debug: print each command

# ───────── Functions ───────── #

function pre_cleanup() {
  echo "======================================="
  echo "[PRE] Killing stale processes and cleaning up…"
  echo "======================================="
  sudo killall openvpn || true
  sudo killall debconf-communicate || true

  sudo rm -f /var/lib/dpkg/lock /var/lib/dpkg/lock-frontend
  sudo rm -f /var/cache/debconf/config.dat /var/cache/debconf/passwords.dat /var/cache/debconf/templates.dat

  sudo dpkg --configure -a

  MAX_WAIT=120
  WAITED=0
  while fuser /var/cache/debconf/config.dat >/dev/null 2>&1 || \
        fuser /var/cache/debconf/passwords.dat >/dev/null 2>&1 || \
        fuser /var/cache/debconf/templates.dat >/dev/null 2>&1; do
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
  echo "======================================="
  echo "[APT] Waiting for other apt processes to finish…"
  echo "======================================="
  MAX_WAIT=120
  WAITED=0
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
  echo "======================================="
  echo "[1/11] Updating and upgrading system…"
  echo "======================================="
  wait_for_apt
  sudo apt-get update -y
  wait_for_apt
  sudo apt-get upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
  wait_for_apt
}

function install_packages() {
  echo "======================================="
  echo "[2/11] Installing required packages…"
  echo "======================================="
  sudo debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v4 boolean true"
  sudo debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v6 boolean true"

  wait_for_apt
  sudo apt-get install -y openvpn easy-rsa vnstat iptables-persistent curl wget lsb-release ca-certificates \
    -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
  wait_for_apt

  if [ ! -f /etc/iptables/rules.v4 ]; then
    sudo iptables-save | sudo tee /etc/iptables/rules.v4
  fi
}

function clean_openvpn_setup() {
  echo "======================================="
  echo "[3/11] Cleaning existing OpenVPN setup…"
  echo "======================================="
  sudo systemctl stop openvpn@server || true
  sudo killall openvpn || true

  # Preserve important files
  sudo mkdir -p /etc/openvpn/auth
  sudo mv /etc/openvpn/auth/psw-file /tmp/psw-file.bak || true
  sudo mv /etc/openvpn/auth/checkpsw.sh /tmp/checkpsw.sh.bak || true
  if [ -d /etc/openvpn/pki ]; then
    sudo mv /etc/openvpn/pki /tmp/pki.bak
  fi

  # Remove everything except preserved files
  sudo rm -rf /etc/openvpn/*
  sudo mkdir -p /etc/openvpn/auth

  # Restore preserved files
  sudo mv /tmp/psw-file.bak /etc/openvpn/auth/psw-file || true
  sudo mv /tmp/checkpsw.sh.bak /etc/openvpn/auth/checkpsw.sh || true
  sudo mv /tmp/pki.bak /etc/openvpn/pki || true

  : > /etc/openvpn/ipp.txt  # Clears the IP pool persistence file
}

function setup_easy_rsa() {
  echo "======================================="
  echo "[4/11] Setting up Easy-RSA PKI…"
  echo "======================================="
  EASYRSA_DIR=/etc/openvpn/easy-rsa
  sudo cp -a /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
  cd "$EASYRSA_DIR"
  sudo ./easyrsa init-pki
  sudo EASYRSA_BATCH=1 EASYRSA_REQ_CN="OpenVPN-CA" ./easyrsa build-ca nopass
  sudo ./easyrsa gen-dh
  sudo openvpn --genkey secret ta.key
  sudo EASYRSA_BATCH=1 EASYRSA_REQ_CN="server" ./easyrsa gen-req server nopass
  echo yes | sudo ./easyrsa sign-req server server

  sudo cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/
}

function create_auth_files() {
  echo "======================================="
  echo "[6/11] Creating authentication files…"
  echo "======================================="
  if [ ! -f /etc/openvpn/auth/psw-file ]; then
    echo "testuser testpass" | sudo tee /etc/openvpn/auth/psw-file
    sudo chmod 600 /etc/openvpn/auth/psw-file
  fi

  echo "[7/11] Creating checkpsw.sh script…"
  sudo bash -c 'cat <<EOF > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=\$(grep "^\$1 " "\$PASSFILE" | cut -d" " -f2-)
[ "\$2" = "\$CORRECT" ] && exit 0 || exit 1
EOF'

  sudo chmod 755 /etc/openvpn/auth/checkpsw.sh
  sudo chmod 755 /etc/openvpn/auth
}

function write_server_conf() {
  echo "======================================="
  echo "[8/11] Writing server.conf…"
  echo "======================================="
  sudo bash -c 'cat <<CONF > /etc/openvpn/server.conf
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
cipher AES-256-CBC
user nobody
group nogroup
persist-key
persist-tun
status /etc/openvpn/openvpn-status.log
verb 3
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-env
script-security 3
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 8.8.8.8"
push "dhcp-option DNS 1.1.1.1"
CONF'
}

function enable_ip_forwarding() {
  echo "======================================="
  echo "[9/11] Enabling IP forwarding…"
  echo "======================================="
  sudo sysctl -w net.ipv4.ip_forward=1
  sudo sed -i 's/#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf
  sudo sysctl -p
}

function setup_nat() {
  echo "======================================="
  echo "[10/11] Setting up NAT with iptables…"
  echo "======================================="
  PUB_IF=$(ip route | grep default | awk '{print $5}')
  sudo iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o $PUB_IF -j MASQUERADE
  sudo iptables-save | sudo tee /etc/iptables/rules.v4
}

function restart_openvpn() {
  echo "======================================="
  echo "[11/11] Enabling and restarting OpenVPN service…"
  echo "======================================="
  sudo systemctl enable openvpn@server
  sudo systemctl restart openvpn@server
}

function deploy_ssh_key() {
  echo "======================================="
  echo "✅ Deploy your panel SSH public key (optional step)"
  echo "======================================="
  if [ -f /tmp/id_rsa.pub ]; then
    mkdir -p /root/.ssh
    cat /tmp/id_rsa.pub >> /root/.ssh/authorized_keys
    rm /tmp/id_rsa.pub
    echo "✅ Added panel SSH public key to authorized_keys"
  fi
}

function check_openvpn_status() {
  echo "======================================="
  echo "[FINAL] Checking OpenVPN service status…"
  echo "======================================="
  if systemctl is-active --quiet openvpn@server; then
    echo "✅ OpenVPN service is running."
  else
    echo "❌ OpenVPN service failed to start."
    sudo journalctl -u openvpn@server
    exit 1
  fi
}

function deployment_summary() {
  echo "======================================="
  echo " DEPLOYMENT SUMMARY"
  echo "======================================="
  echo "OpenVPN service: $(systemctl is-active openvpn@server)"
  echo "IP forwarding: $(sysctl net.ipv4.ip_forward | awk '{print $3}')"
  echo "NAT rules: $(iptables -t nat -L POSTROUTING)"
}

function cleanup_temp_files() {
  echo "======================================="
  echo "[CLEANUP] Removing temporary files…"
  echo "======================================="
  sudo rm -rf /tmp/*
}

# ───────── Main Script ───────── #

pre_cleanup

# System preparation
update_and_upgrade
install_packages

# OpenVPN setup
clean_openvpn_setup
setup_easy_rsa
create_auth_files
write_server_conf

# Network configuration
enable_ip_forwarding
setup_nat

# Service and finalization
restart_openvpn
check_openvpn_status
deploy_ssh_key
deployment_summary
cleanup_temp_files

echo "======================================="
echo "✅ Deployment finished successfully."
echo "======================================="
echo "=== DEPLOYMENT END $(date) ==="

exit 0