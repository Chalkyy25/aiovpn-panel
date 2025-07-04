#!/bin/bash

# Redirect all output to a log file
exec > >(tee -i /root/openvpn-deploy.log)
exec 2>&1

echo -e "\n=======================================\n SCRIPT RUN START: $(date)\n======================================="

set -e
trap 'CODE=$?; echo -e "\n=======================================\n❌ Deployment failed with code: $CODE\nEXIT_CODE:$CODE\n======================================="; exit $CODE' ERR

# Debug toggle
DEBUG=false
[ "$DEBUG" = true ] && set -x

# ───────── Functions ───────── #

function pre_cleanup() {
  echo -e "\n=======================================\n[PRE] Killing stale processes and cleaning up…\n======================================="
  
  sudo killall openvpn || true
  sudo killall debconf-communicate || true
  sudo rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
  sudo dpkg --configure -a

  local MAX_WAIT=120
  local WAITED=0
  while fuser /var/cache/debconf/*.dat >/dev/null 2>&1; do
    if [ $WAITED -ge $MAX_WAIT ]; then
      echo -e "\n❌ Timed out waiting for debconf locks to clear.\n"
      exit 1
    fi
    echo "⏳ Waiting for debconf locks to clear..."
    sleep 3
    WAITED=$((WAITED+3))
  done

  echo -e "[PRE] Cleanup complete.\n======================================="
}

function wait_for_apt() {
  echo -e "\n=======================================\n[APT] Checking apt locks…\n======================================="
  local MAX_WAIT=120
  local WAITED=0
  while fuser /var/lib/dpkg/lock >/dev/null 2>&1 || \
        fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || \
        fuser /var/cache/apt/archives/lock >/dev/null 2>&1; do
    if [ $WAITED -ge $MAX_WAIT ]; then
      echo -e "\n❌ Timed out waiting for apt locks to clear.\n"
      exit 1
    fi
    echo "[APT] Another process is holding apt lock. Waiting 3s..."
    sleep 3
    WAITED=$((WAITED+3))
  done
}

function update_and_upgrade() {
  echo -e "\n=======================================\n[1/11] Updating and upgrading system…\n======================================="

  wait_for_apt
  sudo apt-get update -y
  wait_for_apt
  sudo apt-get upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
  wait_for_apt

  echo -e "[1/11] Update and upgrade complete.\n======================================="
}

function install_packages() {
  echo -e "\n=======================================\n[2/11] Installing required packages…\n======================================="

  sudo debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v4 boolean true"
  sudo debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v6 boolean true"

  wait_for_apt
  sudo apt-get install -y openvpn easy-rsa vnstat iptables-persistent curl wget lsb-release ca-certificates \
    -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
  wait_for_apt

  [ ! -f /etc/iptables/rules.v4 ] && sudo iptables-save | sudo tee /etc/iptables/rules.v4

  echo -e "[2/11] Package installation complete.\n======================================="
}

function clean_openvpn_setup() {
  echo -e "\n=======================================\n[3/11] Cleaning existing OpenVPN setup…\n======================================="

  sudo systemctl stop openvpn@server || true
  sudo killall openvpn || true

  # Preserve important files
  sudo mkdir -p /etc/openvpn/auth
  sudo mv /etc/openvpn/auth/psw-file /tmp/psw-file.bak || true
  sudo mv /etc/openvpn/auth/checkpsw.sh /tmp/checkpsw.sh.bak || true
  [ -d /etc/openvpn/pki ] && sudo mv /etc/openvpn/pki /tmp/pki.bak || true

  sudo rm -rf /etc/openvpn/*
  sudo mkdir -p /etc/openvpn/auth

  # Restore preserved files
  sudo mv /tmp/psw-file.bak /etc/openvpn/auth/psw-file || true
  sudo mv /tmp/checkpsw.sh.bak /etc/openvpn/auth/checkpsw.sh || true
  sudo mv /tmp/pki.bak /etc/openvpn/pki || true

  : > /etc/openvpn/ipp.txt

  echo -e "[3/11] Cleanup complete.\n======================================="
}

function setup_easy_rsa() {
  echo -e "\n=======================================\n[4/11] Setting up Easy-RSA PKI…\n======================================="

  local EASYRSA_DIR=/etc/openvpn/easy-rsa
  sudo cp -a /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
  cd "$EASYRSA_DIR"
  sudo ./easyrsa init-pki
  sudo EASYRSA_BATCH=1 EASYRSA_REQ_CN="OpenVPN-CA" ./easyrsa build-ca nopass
  sudo ./easyrsa gen-dh
  sudo openvpn --genkey secret ta.key
  sudo EASYRSA_BATCH=1 EASYRSA_REQ_CN="server" ./easyrsa gen-req server nopass
  echo yes | sudo ./easyrsa sign-req server server

  sudo cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/

  echo -e "[4/11] Easy-RSA setup complete.\n======================================="
}

function create_auth_files() {
  echo -e "\n=======================================\n[6/11] Creating authentication files…\n======================================="

  [ ! -f /etc/openvpn/auth/psw-file ] && echo "testuser testpass" | sudo tee /etc/openvpn/auth/psw-file && sudo chmod 600 /etc/openvpn/auth/psw-file

  echo "[7/11] Creating checkpsw.sh script…"
  sudo bash -c 'cat <<EOF > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=\$(grep "^\$1 " "\$PASSFILE" | cut -d" " -f2-)
[ "\$2" = "\$CORRECT" ] && exit 0 || exit 1
EOF'
  sudo chmod 755 /etc/openvpn/auth/checkpsw.sh
  sudo chmod 755 /etc/openvpn/auth

  echo -e "[6/11] Authentication files created.\n======================================="
}

function write_server_conf() {
  echo -e "\n=======================================\n[8/11] Writing server.conf…\n======================================="

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

  echo -e "[8/11] server.conf written.\n======================================="
}

function enable_ip_forwarding() {
  echo -e "\n=======================================\n[9/11] Enabling IP forwarding…\n======================================="

  sudo sysctl -w net.ipv4.ip_forward=1
  sudo sed -i 's/#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf
  sudo sysctl -p

  echo -e "[9/11] IP forwarding enabled.\n======================================="
}

function setup_nat() {
  echo -e "\n=======================================\n[10/11] Setting up NAT with iptables…\n======================================="

  local PUB_IF
  PUB_IF=$(ip route | grep default | awk '{print $5}')
  sudo iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o $PUB_IF -j MASQUERADE
  sudo iptables-save | sudo tee /etc/iptables/rules.v4

  echo -e "[10/11] NAT setup complete.\n======================================="
}

function restart_openvpn() {
  echo -e "\n=======================================\n[11/11] Enabling and restarting OpenVPN service…\n======================================="

  sudo systemctl enable openvpn@server
  sudo systemctl restart openvpn@server

  echo -e "[11/11] OpenVPN service restarted.\n======================================="
}

function deploy_ssh_key() {
  echo -e "\n=======================================\n[FINAL] Deploying panel SSH public key (optional)…\n======================================="

  if [ -f /tmp/id_rsa.pub ]; then
    mkdir -p /root/.ssh
    cat /tmp/id_rsa.pub >> /root/.ssh/authorized_keys
    rm /tmp/id_rsa.pub
    echo "✅ Added panel SSH public key to authorized_keys"
  fi

  echo -e "[FINAL] SSH key deployment complete.\n======================================="
}

function check_openvpn_status() {
  echo -e "\n=======================================\n[FINAL] Checking OpenVPN service status…\n======================================="

  if systemctl is-active --quiet openvpn@server; then
    echo "✅ OpenVPN service is running."
  else
    echo "❌ OpenVPN service failed to start."
    sudo journalctl -u openvpn@server
    exit 1
  fi

  echo -e "[FINAL] OpenVPN status check complete.\n======================================="
}

function deployment_summary() {
  echo -e "\n=======================================\n DEPLOYMENT SUMMARY\n======================================="

  echo "OpenVPN service: $(systemctl is-active openvpn@server)"
  echo "IP forwarding: $(sysctl net.ipv4.ip_forward | awk '{print $3}')"
  echo "NAT rules:"
  iptables -t nat -L POSTROUTING | grep MASQUERADE

  echo -e "=======================================\n"
}

function cleanup_temp_files() {
  echo -e "\n=======================================\n[CLEANUP] Removing temporary files…\n======================================="
  sudo rm -rf /tmp/*
  echo "[CLEANUP] Temp files cleaned."
  echo "======================================="
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
restart_openvpn
check_openvpn_status
deploy_ssh_key
deployment_summary
cleanup_temp_files

echo -e "\n=======================================\n✅ Deployment finished successfully.\n=======================================\n=== DEPLOYMENT END $(date) ==="

exit 0
