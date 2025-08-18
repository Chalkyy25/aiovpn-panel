#!/bin/bash
set -euo pipefail

###############################################################################
# AIOVPN: OpenVPN + WireGuard one‑shot deploy (panel‑integrated, hardened)
###############################################################################

# ── Required env ──────────────────────────────────────────────────────────────
: "${PANEL_URL:?set PANEL_URL, e.g. https://aiovpn.co.uk}"
: "${PANEL_TOKEN:?set PANEL_TOKEN (Bearer token)}"
: "${SERVER_ID:?set SERVER_ID (panel id for this server)}"

# ── Optional env with sensible defaults ───────────────────────────────────────
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
VPN_PORT="${VPN_PORT:-1194}"
VPN_PROTO="${VPN_PROTO:-udp}"        # udp|tcp
DNS1="${DNS1:-1.1.1.1}"
DNS2="${DNS2:-8.8.8.8}"
VPN_USER="${VPN_USER:-admin}"
VPN_PASS="${VPN_PASS:-$(openssl rand -base64 18)}"
WG_PORT="${WG_PORT:-51820}"

# Secrets we create should be 0600 by default
umask 077

# ── Logging ───────────────────────────────────────────────────────────────────
LOG_FILE="/root/vpn-deploy.log"
exec > >(tee -i "$LOG_FILE")
exec 2>&1

###############################################################################
# Helpers
###############################################################################
section()   { echo -e "\n=== $* ==="; }
ok()        { echo "✅ $*"; }
warn()      { echo "⚠️  $*"; }
fail()      { echo "❌ $*"; exit 1; }
require()   { command -v "$1" >/dev/null 2>&1 || fail "Missing command: $1"; }

panel() {  # panel <METHOD> <PATH> [--data '{"json":"yes"}'] [--file /path]
  local method="$1"; shift
  local path="$1"; shift
  local url="${PANEL_URL%/}${path}"
  local base=(-fsS --retry 3 --retry-connrefused --max-time 20 -X "$method" -H "Authorization: Bearer $PANEL_TOKEN")

  if [[ "${1-}" == "--file" ]]; then
    curl "${base[@]}" -F "file=@$2" "$url"
    return
  fi

  local data=""
  if [[ "${1-}" == "--data" ]]; then data="$2"; fi
  curl "${base[@]}" -H "Content-Type: application/json" -d "$data" "$url"
}

announce() {  # announce <status> <message>
  panel POST "/api/servers/$SERVER_ID/deploy/events" \
    --data "$(jq -nc --arg s "$1" --arg m "$2" '{status:$s, message:$m}')" || true
}

logchunk() {  # logchunk <line>
  panel POST "/api/servers/$SERVER_ID/deploy/logs" \
    --data "$(jq -nc --arg l "$1" '{line:$l}')" >/dev/null || true
}

on_error() {
  local rc=$?
  announce failed "Deployment failed (exit=$rc)"
  fail "Deployment failed (exit=$rc)"
}
trap on_error ERR

###############################################################################
# Start
###############################################################################
echo -e "\n=======================================\nSTART $(date)\n======================================="
announce running "Starting deployment"

section "System checks"
require apt-get
require systemctl
require iptables
require ip
require curl
require jq
require python3
require openvpn
require wg
require wg-quick

[[ $EUID -eq 0 ]] || fail "Run as root"
[[ -c /dev/net/tun ]] || fail "TUN device missing"

section "Pre‑clean"
killall openvpn 2>/dev/null || true
rm -f /var/lib/dpkg/lock* /var/cache/debconf/*.dat
dpkg --configure -a || true
ok "Pre‑clean done"

section "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y \
  openvpn easy-rsa wireguard iproute2 iptables-persistent \
  curl ca-certificates jq python3 >/dev/null
ok "Dependencies installed"

###############################################################################
# OpenVPN
###############################################################################
section "OpenVPN PKI / config"

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

  install -m 0644 pki/ca.crt             /etc/openvpn/ca.crt
  install -m 0644 pki/issued/server.crt  /etc/openvpn/server.crt
  install -m 0600 pki/private/server.key /etc/openvpn/server.key
  install -m 0644 pki/dh.pem             /etc/openvpn/dh.pem
  ok "PKI generated"
else
  ok "PKI present (skipped)"
fi

# Auth directory + initial authfile
install -d -m 0700 /etc/openvpn/auth
if panel GET "/api/servers/$SERVER_ID/authfile" >/tmp/panel-auth 2>/dev/null && [[ -s /tmp/panel-auth ]]; then
  install -m 0600 /tmp/panel-auth /etc/openvpn/auth/psw-file
  ok "Pulled authfile from panel"
else
  printf '%s %s\n' "$VPN_USER" "$VPN_PASS" >/etc/openvpn/auth/psw-file
  chmod 600 /etc/openvpn/auth/psw-file
  warn "Seeded local authfile (no panel auth yet)"
fi
rm -f /tmp/panel-auth

# Auth script
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

# server.conf (with management + run-as nobody/nogroup)
cat >/etc/openvpn/server.conf <<CONF
port $VPN_PORT
proto $VPN_PROTO
dev tun

user nobody
group nogroup

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

# Logs/status
status /run/openvpn/server.status 10
status-version 3
verb 3

# Management (panel can query)
management $MGMT_HOST $MGMT_PORT

# Panel-auth
script-security 3
verify-client-cert none
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/checkpsw.sh via-file

# Routes / DNS
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS $DNS1"
push "dhcp-option DNS $DNS2"

explicit-exit-notify 1
CONF

ok "OpenVPN configured"

###############################################################################
# Firewall / NAT
###############################################################################
section "IP forwarding + NAT + ports"

echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-vpn.conf
sysctl --system >/dev/null

DEF_IFACE="$(ip -4 route show default | awk '/default/ {print $5; exit}')"
DEF_IFACE="${DEF_IFACE:-eth0}"

iptables -t nat -C POSTROUTING -o "$DEF_IFACE" -j MASQUERADE 2>/dev/null || \
iptables -t nat -A POSTROUTING -o "$DEF_IFACE" -j MASQUERADE

iptables -C INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT 2>/dev/null || \
iptables -A INPUT -p "$VPN_PROTO" --dport "$VPN_PORT" -j ACCEPT

iptables -C INPUT -p udp --dport "$WG_PORT" -j ACCEPT 2>/dev/null || \
iptables -A INPUT -p udp --dport "$WG_PORT" -j ACCEPT

iptables-save >/etc/iptables/rules.v4 || true
netfilter-persistent save || true
ok "Forwarding/NAT/ports ready"

###############################################################################
# WireGuard (minimal)
###############################################################################
section "WireGuard setup"

install -d -m 0700 /etc/wireguard
if [[ ! -f /etc/wireguard/server_private_key ]]; then
  umask 077 && wg genkey | tee /etc/wireguard/server_private_key | wg pubkey > /etc/wireguard/server_public_key
  ok "WG keys generated"
else
  ok "WG keys present"
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
ok "WireGuard running"

###############################################################################
# Start OpenVPN
###############################################################################
section "Start OpenVPN"

systemctl enable openvpn@server
systemctl restart openvpn@server
systemctl is-active --quiet openvpn@server || fail "OpenVPN failed to start"
ok "OpenVPN running"

###############################################################################
# Report facts + upload authfile
###############################################################################
section "Report to panel"

panel POST "/api/servers/$SERVER_ID/deploy/facts" \
  --data "$(jq -nc --arg iface "$DEF_IFACE" --arg proto "$VPN_PROTO" \
                  --argjson mgmt "$MGMT_PORT" --argjson vpn "$VPN_PORT" \
                  '{iface:$iface, mgmt_port:$mgmt, vpn_port:$vpn, proto:$proto, ip_forward:1}')" >/dev/null || true

panel POST "/api/servers/$SERVER_ID/authfile" --file /etc/openvpn/auth/psw-file >/dev/null || true
ok "Panel updated"

###############################################################################
# Install minute auth sync (systemd timer), hardened
###############################################################################
section "Install auth sync timer"

install -d -m 0755 /usr/local/lib/aiovpn
cat >/usr/local/lib/aiovpn/pull-auth.sh <<SYNC
#!/bin/bash
set -euo pipefail
TMP=\$(mktemp)
/usr/bin/curl -fsS --retry 3 --retry-connrefused --max-time 20 \
  -H "Authorization: Bearer $PANEL_TOKEN" "${PANEL_URL%/}/api/servers/$SERVER_ID/authfile" -o "\$TMP" || exit 0
[ -s "\$TMP" ] || exit 0
install -m 0600 "\$TMP" /etc/openvpn/auth/psw-file
rm -f "\$TMP"
/bin/systemctl reload openvpn@server 2>/dev/null || /bin/systemctl restart openvpn@server || true
SYNC
chmod 0750 /usr/local/lib/aiovpn/pull-auth.sh

cat >/etc/systemd/system/aiovpn-auth-sync.service <<'SVC'
[Unit]
Description=AIOVPN pull authfile from panel
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/lib/aiovpn/pull-auth.sh
User=root
Group=root
# Hardening
ProtectSystem=full
ProtectHome=true
NoNewPrivileges=true
PrivateTmp=true
ProtectKernelTunables=true
ProtectControlGroups=true
RestrictSUIDSGID=true
LockPersonality=true
MemoryDenyWriteExecute=true
RestrictNamespaces=true
SystemCallArchitectures=native
# Allow writing only where needed
ReadWritePaths=/etc/openvpn/auth
SVC

cat >/etc/systemd/system/aiovpn-auth-sync.timer <<'TIM'
[Unit]
Description=Run AIOVPN auth sync every minute

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
Unit=aiovpn-auth-sync.service

[Install]
WantedBy=timers.target
TIM

systemctl daemon-reload
systemctl enable --now aiovpn-auth-sync.timer
ok "Auth sync timer active"

###############################################################################
# Done
###############################################################################
announce succeeded "Deployment complete"
echo -e "\n✅ Done @ $(date)"