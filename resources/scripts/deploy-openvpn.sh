#!/bin/bash

log_step() {
  echo "[${1}/9] ${2}..."
}

echo "SCRIPT RUN START: $(date)"
echo "=== DEPLOYMENT START $(date) ==="

# Ensure no apt/dpkg locks
echo "Checking for package manager locks..."
sleep 1
lsof /var/lib/dpkg/lock-frontend && killall apt apt-get dpkg

log_step 0 "Fixing any broken dpkg state"
dpkg --configure -a

log_step 1 "Updating packages"
apt update -y

log_step 2 "Installing dependencies"
apt install -y openvpn easy-rsa ca-certificates curl wget lsb-release vnstat

log_step 3 "Cleaning OpenVPN config and stopping any service"
systemctl stop openvpn@server || true
rm -rf /etc/openvpn/easy-rsa /etc/openvpn/server.conf
mkdir -p /etc/openvpn/easy-rsa
cp -r /usr/share/easy-rsa/* /etc/openvpn/easy-rsa
cd /etc/openvpn/easy-rsa
chmod +x *

log_step 4 "Generating PKI and certificates"
./easyrsa init-pki
echo | ./easyrsa build-ca nopass

log_step 5 "Generating server certificate and key"
EASYRSA_BATCH=1 ./easyrsa gen-req server nopass
echo | EASYRSA_BATCH=1 ./easyrsa sign-req server server

log_step 6 "Generating Diffie-Hellman parameters"
./easyrsa gen-dh

log_step 7 "Creating server.conf"
cat > /etc/openvpn/server.conf <<EOF
port 1194
proto udp
dev tun
ca /etc/openvpn/easy-rsa/pki/ca.crt
cert /etc/openvpn/easy-rsa/pki/issued/server.crt
key /etc/openvpn/easy-rsa/pki/private/server.key
dh /etc/openvpn/easy-rsa/pki/dh.pem
auth SHA256
topology subnet
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist ipp.txt
keepalive 10 120
persist-key
persist-tun
status openvpn-status.log
verb 3
EOF

log_step 8 "Enabling and starting OpenVPN service"
systemctl enable openvpn@server
systemctl start openvpn@server

log_step 9 "Done"
echo "✅ Deployment completed successfully!"
