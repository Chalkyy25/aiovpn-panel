#!/bin/bash
set -euo pipefail

# ===== Env =====
: "${PANEL_URL:?set PANEL_URL, e.g. https://aiovpn.co.uk}"
: "${PANEL_TOKEN:?set PANEL_TOKEN (Bearer token)}"
: "${SERVER_ID:?set SERVER_ID (panel id for this server)}"

PANEL_CALLBACKS="${PANEL_CALLBACKS:-1}"   # set to 0 to silence API posts until routes exist

MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
VPN_PORT="${VPN_PORT:-1194}"
VPN_PROTO="${VPN_PROTO:-udp}"             # must be udp|tcp
DNS1="${DNS1:-1.1.1.1}"
DNS2="${DNS2:-8.8.8.8}"
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 18)}"
WG_PORT="${WG_PORT:-51820}"

LOG_FILE="/root/vpn-deploy.log"
exec > >(tee -i "$LOG_FILE")
exec 2>&1
echo -e "\n=======================================\nSTART $(date)\n======================================="

# ===== Tiny JSON (no jq needed) =====
json_escape() { sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
json_kv() { printf '"%s":"%s"' "$1" "$(printf '%s' "$2" | json_escape)"; }

# ===== Panel helpers (tolerate missing routes) =====
panel() {
  local method="$1"; shift
  local path="$1"; shift
  local url="${PANEL_URL%/}${path}"
  if [[ "${1-}" == "--file" ]]; then
    [[ "$PANEL_CALLBACKS" = "1" ]] || return 0
    curl -fsS -X "$method" -H "Authorization: Bearer $PANEL_TOKEN" -F "file=@$2" "$url"
    return
  fi
  local data=""
  if [[ "${1-}" == "--data" ]]; then data="$2"; fi
  if [[ "$PANEL_CALLBACKS" = "1" ]]; then
    curl -fsS -X "$method" -H "Authorization: Bearer $PANEL_TOKEN" -H "Content-Type: application/json" -d "$data" "$url"
  else
    return 0
  fi
}

announce() {
  local status="$1"; shift; local msg="$*"
  panel POST "/api/servers/$SERVER_ID/deploy/events" \
    --data "{ $(json_kv status "$status"), $(json_kv message "$msg") }" || true
}
logchunk() {
  local line="$*"
  panel POST "/api/servers/$SERVER_ID/deploy/logs" \
    --data "{ $(json_kv line "$line") }" >/dev/null || true
}

trap 'rc=$?; announce "failed" "Deployment failed (exit=$rc)"; exit $rc' ERR
announce running "Starting deployment"

# ===== System checks =====
[[ $EUID -eq 0 ]] || { announce failed "Run as root"; exit 1; }
[[ -c /dev/net/tun ]] || { announce failed "TUN missing"; exit 1; }
for c in apt-get systemctl iptables ip curl; do
  command -v "$c" >/dev/null || { announce failed "Missing command: $c"; exit 1; }
done

# ===== Pre-clean =====
logchunk "Pre-clean"
killall openvpn 2>/dev/null || true
rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
dpkg --configure -a || true

# ===== Install deps =====
export DEBIAN_FRONTEND=noninteractive
logchunk "Installing packages"
apt-get update -y
apt-get install -y openvpn easy-rsa wireguard iproute2 iptables-persistent curl ca-certificates python3 jq || \
apt-get install -y openvpn easy-rsa wireguard iproute2 iptables-persistent curl ca-certificates python3

# ===== OpenVPN PKI/config =====
logchunk "OpenVPN PKI/config"
install -d -m 0755 /etc/openvpn/easy-rsa
cp -a /usr/share/easy-rsa/* /etc/openvpn/easy-rsa 2>/dev/null || true
cd /etc/openvpn/easy-rsa
export EASYRSA_BATCH=1
if [[ ! -d pki ]]; then
  ./easyrsa init-pki
  ./easyrsa build-ca nopass
  ./easyrsa gen-req server nopass
  ./easyrsa sign-req server server
  ./easyrsa gen-dh
  openvpn --genkey --secret /etc/openvpn/ta.key
  install -m 0644 pki/ca.crt /etc/openvpn/ca.crt
  install -m 0644 pki/issued/server.crt /etc/openvpn/server.crt
  install -m 0600 pki/private/server.key /etc/openvpn/server.key
  install -m 0644 pki/dh.pem /etc/openvpn/dh.pem
fi

# Auth dir + fetch panel auth file
install -d -m 0700 /etc/openvpn/auth
if panel GET "/api/servers/$SERVER_ID/authfile" >/tmp/panel-auth 2>/dev/null && [[ -s /tmp/panel-auth ]]; then
  install -m 0600 /tmp/panel-auth /etc/openvpn/auth/psw-file
else
  printf '%s %s\n' "$VPN_USER" "$VPN_PASS" >/etc/openvpn/auth/psw-file
  chmod 600 /etc/openvpn/auth/psw-file
fi
rm -f /tmp/panel-auth

cat >/etc/openvpn/auth/checkpsw.sh <<'SH'
#!/bin/sh
set -eu
PASSFILE="/etc/openvpn/auth/psw-file"
LOG_FILE="/var/log/openvpn-password.log"
TS="$(date '+%F %T')"
CRED_FILE="$1"
[ -r "$PASSFILE" ] || { echo "$TS: Cannot read $PASSFILE" >>"$LOG_FILE"; exit 1; }
[ -r "$CRED_FILE" ] || { echo "$TS: Cannot read $CRED_FILE" >>"$LOG_FILE"; exit 1; }
CREDENTIALS="$(tr -d '\r' < "$CRED_FILE" | tr '\n\t' '  ' | awk '{$1=$1;print}')"
USERNAME="$(printf '%s' "$CREDENTIALS" | cut -d' ' -f1)"
PASSWORD="$(printf '%s' "$CREDENTIALS" | cut -d' ' -f2-)"
[ -n "$USERNAME" ] && [ -n "$PASSWORD" ] || { echo "$TS: Empty user/pass" >>"$LOG_FILE"; exit 1; }
CORRECT_PASSWORD="$(awk -v u="$USERNAME" '$1==u { $1=""; sub(/^[ \t]+/,""); print; exit }' "$PASSFILE")"
if [ -n "$CORRECT_PASSWORD" ] && [ "$PASSWORD" = "$CORRECT_PASSWORD" ]; then
  echo "$TS: OK $USERNAME" >>"$LOG_FILE"; exit 0
fi
echo "$TS: FAIL $USERNAME" >>"$LOG_FILE"; exit 1
SH
chmod 0755 /etc/openvpn/auth/checkpsw.sh

cat >/etc/openvpn/server.conf <<CONF
port $VPN_PORT
proto $VPN_PROTO
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
status /run/openvpn/server.status 10
status-version 3
verb 3
management $MGMT_HOST $MGMT_PORT
script-security 3
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS $DNS1"
push "dhcp-option DNS $DNS2"
explicit-exit-notify 1
CONF

# ===== Firewall/NAT =====
logchunk "IP forwarding + NAT"
sysctl -w net.ipv4.ip_forward=1 >/dev/null
echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-vpn.conf
sysctl --system >/dev/null
DEF_IFACE="$(ip -4 route show default | awk '/default/ {print $5; exit}')" ; DEF_IFACE="${DEF_IFACE:-eth0}"
iptables -t nat -C POSTROUTING -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -o "$DEF_IFACE" -j MASQUERADE
iptables -C INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT
iptables -C INPUT -p udp --dport "$WG_PORT" -j ACCEPT 2>/dev/null || iptables -A INPUT -p udp --dport "$WG_PORT" -j ACCEPT
iptables-save >/etc/iptables/rules.v4 || true
netfilter-persistent save || true

# ===== WireGuard =====
logchunk "WireGuard setup"
install -d -m 0700 /etc/wireguard
if [[ ! -f /etc/wireguard/server_private_key ]]; then
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
fi
PRIV="$(cat /etc/wireguard/server_private_key)"
cat >/etc/wireguard/wg0.conf <<WG
[Interface]
PrivateKey = $PRIV
Address = 10.66.66.1/24
ListenPort = $WG_PORT
SaveConfig = true
WG
systemctl enable --now wg-quick@wg0

# ===== OpenVPN service =====
logchunk "Start OpenVPN"
systemctl enable openvpn@server
systemctl restart openvpn@server
systemctl is-active --quiet openvpn@server || { announce failed "OpenVPN failed to start"; exit 1; }

# ===== Facts + auth mirror (optional) =====
panel POST "/api/servers/$SERVER_ID/deploy/facts" \
  --data "{ $(json_kv iface "$DEF_IFACE"), $(json_kv proto "$VPN_PROTO"), \"mgmt_port\": $MGMT_PORT, \"vpn_port\": $VPN_PORT, \"ip_forward\": 1 }" >/dev/null || true
panel POST "/api/servers/$SERVER_ID/authfile" --file /etc/openvpn/auth/psw-file >/dev/null || true

# ===== Auth sync timer =====
logchunk "Install auth sync timer"
install -d -m 0755 /usr/local/lib/aiovpn
cat >/usr/local/lib/aiovpn/pull-auth.sh <<SYNC
#!/bin/bash
set -euo pipefail
[[ "${PANEL_CALLBACKS:-1}" = "1" ]] || exit 0
TMP=\$(mktemp)
curl -fsS -H "Authorization: Bearer $PANEL_TOKEN" "${PANEL_URL%/}/api/servers/$SERVER_ID/authfile" -o "\$TMP" || exit 0
[ -s "\$TMP" ] || exit 0
install -m 0600 "\$TMP" /etc/openvpn/auth/psw-file
rm -f "\$TMP"
/bin/systemctl reload openvpn@server || true
SYNC
chmod 0750 /usr/local/lib/aiovpn/pull-auth.sh

cat >/etc/systemd/system/aiovpn-auth-sync.service <<SVC
[Unit]
Description=AIOVPN pull authfile from panel
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/lib/aiovpn/pull-auth.sh
User=root
Group=root
ProtectSystem=full
ProtectHome=true
NoNewPrivileges=true
SVC

cat >/etc/systemd/system/aiovpn-auth-sync.timer <<TIM
[Unit]
Description=Run auth sync every minute
[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
Unit=aiovpn-auth-sync.service
[Install]
WantedBy=timers.target
TIM

systemctl daemon-reload
systemctl enable --now aiovpn-auth-sync.timer

announce succeeded "Deployment complete"
echo -e "\n✅ Done @ $(date)"