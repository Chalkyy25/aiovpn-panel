#!/bin/bash

echo "SCRIPT RUN START: $(date)"
set -e
trap 'CODE=$?; echo "❌ Deployment failed with code: $CODE"; echo "EXIT_CODE:$CODE"; exit $CODE' ERR
set -x

export DEBIAN_FRONTEND=noninteractive
export EASYRSA_BATCH=1
export EASYRSA_REQ_CN="${EASYRSA_REQ_CN:-OpenVPN-CA}"

echo "=== DEPLOYMENT START $(date) ==="

MAX_WAIT=120
WAITED=0
while sudo fuser /var/lib/dpkg/lock >/dev/null 2>&1 || sudo fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
  if [ $WAITED -ge $MAX_WAIT ]; then
    echo "❌ Timed out waiting for package manager lock."
    exit 1
  fi
  echo "⏳ Waiting for other package managers to finish..."
  sleep 3
  WAITED=$((WAITED+3))
done

echo "[0/9] Checking for interrupted package operations..."
sudo dpkg --configure -a --force-confdef --force-confold

echo "[1/9] Updating packages..."
sudo apt-get update -y
sudo apt-get upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"

echo "[2/9] Installing dependencies..."
sudo apt-get install -y openvpn easy-rsa vnstat curl wget lsb-release ca-certificates -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"

echo "[3/9] Stopping OpenVPN and cleaning up..."
sudo systemctl stop openvpn@server || true
sudo rm -rf /etc/openvpn/*
sudo mkdir -p /etc/openvpn/auth
: > /etc/openvpn/ipp.txt

echo "[4/9] Generating certificates..."
EASYRSA_DIR=/etc/openvpn/easy-rsa
sudo cp -a /usr/share/easy-rsa "$EASYRSA_DIR" 2>/dev/null || true
cd "$EASYRSA_DIR"
sudo ./easyrsa init-pki
sudo EASYRSA_REQ_CN="OpenVPN-CA" ./easyrsa build-ca nopass
sudo ./easyrsa gen-dh
sudo openvpn --genkey --secret ta.key
sudo EASYRSA_REQ_CN="server" ./easyrsa gen-req server nopass
echo yes | sudo ./easyrsa sign-req server server

echo "[5/9] Copying keys..."
sudo cp -f pki/ca.crt pki/issued/server.crt pki/private/server.key pki/dh.pem ta.key /etc/openvpn/

echo "[6/9] Creating user/pass files..."
echo "testuser testpass" | sudo tee /etc/openvpn/auth/psw-file
sudo chmod 600 /etc/openvpn/auth/psw-file

sudo tee /etc/openvpn/auth/checkpsw.sh > /dev/null <<'EOF'
#!/bin/sh
PASSFILE="/etc/openvpn/auth/psw-file"
CORRECT=$(grep "^$1 " "$PASSFILE" | cut -d" " -f2-)
[ "$2" = "$CORRECT" ] && exit 0 || exit 1
EOF

sudo chmod 700 /etc/openvpn/auth/checkpsw.sh

echo "[7/9] Writing server.conf..."
sudo tee /etc/openvpn/server.conf > /dev/null <<'CONF'
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
CONF

echo "[8/9] Enabling OpenVPN..."
sudo systemctl enable openvpn@server
sudo systemctl restart openvpn@server

echo "[9/9] Enabling vnStat..."
sudo systemctl enable vnstat
sudo systemctl restart vnstat

echo "✅ Deployment finished successfully."
exit 0