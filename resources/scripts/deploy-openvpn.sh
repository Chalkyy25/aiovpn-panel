#!/bin/bash
echo "SCRIPT RUN START: $(date)"
set -e
trap 'CODE=$?; echo "❌ Deployment failed with code: $CODE"; echo "EXIT_CODE:$CODE"; exit $CODE' ERR
set -x  # Debug: print each command

# 🛑 PRE-CLEANUP: Kill stale processes, remove locks, reconfigure dpkg
echo "[PRE] Killing stale processes and cleaning up…"
sudo killall openvpn || true
sudo killall debconf-communicate || true

# Remove known lock files
sudo rm -f /var/lib/dpkg/lock /var/lib/dpkg/lock-frontend
sudo rm -f /var/cache/debconf/config.dat /var/cache/debconf/passwords.dat /var/cache/debconf/templates.dat

# Reconfigure dpkg
sudo dpkg --configure -a

# Wait for any debconf locks to clear
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

echo "[PRE] Cleanup complete. Proceeding with deployment..."

export DEBIAN_FRONTEND=noninteractive
export EASYRSA_BATCH=1
export EASYRSA_REQ_CN="${EASYRSA_REQ_CN:-OpenVPN-CA}"

echo "=== DEPLOYMENT START $(date) ==="

# 🔧 Update and upgrade system
echo "[1/10] Updating and upgrading system…"
sudo apt-get update -y
sudo apt-get upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"

# 🔧 Install core packages with preseeding for iptables-persistent
echo "[2/10] Installing OpenVPN, Easy-RSA, vnStat, iptables-persistent with preseeding…"
sudo debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v4 boolean true"
sudo debconf-set-selections <<< "iptables-persistent iptables-persistent/autosave_v6 boolean true"

sudo apt-get install -y openvpn easy-rsa vnstat iptables-persistent curl wget lsb-release ca-certificates \
  -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"

# Ensure iptables-persistent rules are saved
if [ ! -f /etc/iptables/rules.v4 ]; then
  sudo iptables-save | sudo tee /etc/iptables/rules.v4
fi  

# 🔧 Clean existing OpenVPN setup
echo "[3/10] Cleaning existing OpenVPN setup…"
sudo systemctl stop openvpn@server || true
sudo killall openvpn || true
sudo rm -rf /etc/openvpn/*
sudo mkdir -p /etc/openvpn/auth
: > /etc/openvpn/ipp.txt

# 🔧 Setup Easy-RSA PKI
echo "[4/10] Setting up Easy-RSA PKI…"
EASYRSA_DIR=/etc/openvpn/easy-rsa
sudo cp -a /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
cd "$EASYRSA_DIR"
sudo ./easyrsa init-pki
sudo EASYRSA_BATCH=1 EASYRSA_REQ_CN="OpenVPN-CA" ./easyrsa build-ca nopass
sudo ./easyrsa gen-dh
sudo openvpn --genkey --secret ta.key
sudo EASYRSA_BATCH=1 EASYRSA_REQ_CN="server" ./easyrsa gen-req server nopass
echo yes | sudo ./easyrsa sign-req server server

# 🔧 Copy certs & keys to /etc/openvpn
echo "[5/10] Copying certs and keys…"
sudo cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/

# 🔧 Create psw-file for user authentication
echo "[6/10] Creating psw-file for user authentication…"
if [ ! -f /etc/openvpn/auth/psw-file ]; then
  echo "testuser testpass" | sudo tee /etc/openvpn/auth/psw-file
  sudo chmod 600 /etc/openvpn/auth/psw-file
fi

# 🔧 Create checkpsw.sh script
echo "[7/10] Creating checkpsw.sh script…"
sudo bash -c 'cat <<EOF > /etc/openvpn/auth/checkpsw.sh
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=\$(grep "^\$1 " "\$PASSFILE" | cut -d" " -f2-)
[ "\$2" = "\$CORRECT" ] && exit 0 || exit 1
EOF'

sudo chmod 755 /etc/openvpn/auth/checkpsw.sh
sudo chmod 755 /etc/openvpn/auth

# 🔧 Write server.conf
echo "[8/10] Writing server.conf…"
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

# 🔧 Enable IP forwarding
echo "[9/10] Enabling IP forwarding…"
sudo sysctl -w net.ipv4.ip_forward=1
sudo sed -i 's/#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf
sudo sysctl -p

# 🔧 Set up NAT (replace eth0 if needed)
echo "[10/10] Setting up NAT with iptables…"
sudo iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o eth0 -j MASQUERADE
sudo iptables-save | sudo tee /etc/iptables/rules.v4
sudo systemctl restart netfilter-persistent

# 🔧 Enable & restart OpenVPN
echo "[FINAL] Enabling and restarting OpenVPN service…"
sudo systemctl enable openvpn@server
sudo systemctl restart openvpn@server

# ✅ Deploy your panel SSH public key (optional step)
if [ -f /tmp/id_rsa.pub ]; then
  mkdir -p /root/.ssh
  cat /tmp/id_rsa.pub >> /root/.ssh/authorized_keys
  rm /tmp/id_rsa.pub
  echo "✅ Added panel SSH public key to authorized_keys"
fi

echo "✅ Deployment finished successfully."
echo "=== DEPLOYMENT END $(date) ==="

exit 0
